<?php
/**
 * CONNEXT — api/history_report.php : Export รายงานการเบิกจ่ายหลายใบพร้อมรูป (หน้า "ประวัติเอกสาร" · 2026-09-30)
 *
 * POST JSON {site, docNos:[...], ic, payer, from, to, type, status, q} + header X-CSRF-Token (เหมือน api/rpc.php)
 *   สำเร็จ → application/pdf (ไฟล์ตรง ไม่ผ่าน base64 — รายงานหลายใบพร้อมรูปใหญ่ได้หลาย MB)
 *   ไม่สำเร็จ → JSON {ok:false, error, code?} (session หมด = 401 code:auth · CSRF = 403)
 * ตัวสร้างรายงาน + สิทธิ์: lib/history_report.php (historyReportBuild — สิทธิ์เดียวกับหน้าประวัติ)
 */
require __DIR__ . '/../config.php';
require __DIR__ . '/../auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonOut(['ok' => false, 'error' => 'Method not allowed'], 405);
}
verifyCsrfOrFail();
$user = requireLogin();
session_write_close();   // รายงานใหญ่ใช้เวลาหลายวินาที — ไม่ล็อก session ของหน้าอื่นระหว่างรอ

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) { jsonFail('Bad request'); }

@set_time_limit(300);
require_once __DIR__ . '/../lib/history_report.php';
try {
    $r = historyReportBuild($pdo, $user, $body);
} catch (Throwable $e) {
    error_log('history_report: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    jsonFail('สร้างรายงานไม่สำเร็จ — ' . $e->getMessage());
}
if (empty($r['ok'])) {
    jsonFail((string)($r['message'] ?? 'สร้างรายงานไม่สำเร็จ'));
}
$name = (string)$r['fileName'];
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Content-Length: ' . strlen($r['pdf']));
header('Cache-Control: no-store');
header('X-Report-Docs: ' . (int)$r['docs']);
header('X-Report-Photos: ' . (int)$r['photos']);
header('X-Report-Skipped: ' . (int)$r['skipped']);
echo $r['pdf'];
