<?php
/**
 * CONNEXT — api/rpc.php : dispatcher กลางของทุกฟังก์ชันที่ client เรียก
 * (คู่กับ js/gas-shim.js — ชื่อฟังก์ชันตรงกับ GAS เดิม 1:1)
 *
 * โครง: POST {fn, args:[...]} → {ok:true, result:...}
 * ความปลอดภัย: CSRF ทุกคำขอ · ฟังก์ชันนอก PUBLIC_FNS ต้องมี session ·
 * ตัวตน (username/site/role) ฝั่ง server ใช้จาก session เสมอ — args จาก client
 * ใช้เป็น filter/ข้อมูลประกอบเท่านั้น (ล้อพฤติกรรม GAS ที่ re-derive role เอง)
 */
require __DIR__ . '/../config.php';
require __DIR__ . '/../auth.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonOut(['ok' => false, 'error' => 'Method not allowed'], 405);
}

verifyCsrfOrFail();

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) { jsonFail('Bad request'); }
$fn   = (string)($body['fn'] ?? '');
$args = $body['args'] ?? [];
if (!is_array($args)) { $args = []; }

// ฟังก์ชันที่เรียกได้โดยยังไม่ login
// getSignPageData / submitContractorSignature = หน้า sign.php ของผู้รับเหมา —
// ตัวตนมาจาก token 40 hex ในลิงก์ (ตรวจในฟังก์ชันเอง) · ยังผ่าน CSRF ปกติ
// ทั้งคู่แตะได้เฉพาะใบที่ token นั้นชี้ ไม่มีทางไล่อ่านใบอื่นหรือไซต์อื่น
$PUBLIC_FNS = ['checkLogin', 'getAppBuild', 'getSignPageData', 'submitContractorSignature'];

$registry = require __DIR__ . '/../lib/registry.php';

if (!isset($registry[$fn])) {
    jsonFail('Unknown function: ' . $fn);
}

$user = null;
if (!in_array($fn, $PUBLIC_FNS, true)) {
    $user = requireLogin(); // 401 + code:auth ถ้า session หมด (shim จัดการต่อ)
}

list($file, $callable) = $registry[$fn];
require_once __DIR__ . '/../lib/' . $file;

try {
    $result = call_user_func($callable, $pdo, $user, $args);
    jsonOut(['ok' => true, 'result' => $result]);
} catch (RpcUserError $e) {
    // ข้อผิดพลาดที่ตั้งใจส่งข้อความถึงผู้ใช้ (เช่น สต๊อกไม่พอ) — โครงเดิมของ GAS
    // ส่วนใหญ่คืน {success:false, message} ใน result ปกติ; ตัวนี้สำรองไว้สำหรับ throw
    jsonOut(['ok' => true, 'result' => ['success' => false, 'message' => $e->getMessage()]]);
} catch (Throwable $e) {
    error_log('rpc ' . $fn . ': ' . $e->getMessage());
    jsonFail('Server error');
}
