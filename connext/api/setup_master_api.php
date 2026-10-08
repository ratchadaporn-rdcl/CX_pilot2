<?php
/**
 * CONNEXT — api/setup_master_api.php : endpoint JSON ของหน้าจัดการรหัสวัสดุ (setup_master.php)
 *
 * คู่กับ api/ic_api.php (ไล่บันได / ออกรหัส IC) — ไฟล์นี้ทำเรื่องการผูกอย่างเดียว
 * การผูกเป็น 2 ขั้น (มติ 42): Mango → LLP แล้วค่อย LLP → IC · 1 Mango มี LLP ตัวเดียว หลาย IC ได้ (มติ 45)
 *   GET  ?a=info&mat=            → Mango หนึ่งตัว + LLP/IC ที่ผูกอยู่ (ใช้เปิด modal)
 *   GET  ?a=llp&q=&l1=           → ค้นตัวสินค้า (LLP) สำหรับเลือกในขั้น 1
 *   POST  a=map_llp   mat= llp= [replace=1] → ผูก/เปลี่ยนตัวสินค้า · มี IC ใต้ตัวเดิม = ต้องส่ง replace=1 (IC หลุด)
 *   POST  a=unmap_llp mat= llp=  → ถอดตัวสินค้า (พร้อม IC ใต้มัน) · llp ว่าง = ถอดทั้งหมด
 *   POST  a=attach_ic mat= ic=   → ผูก IC (LLP ของมันถูกผูกให้อัตโนมัติ · IC ใต้ LLP อื่น = ปฏิเสธ)
 *   POST  a=detach_ic mat= ic=   → ถอด IC กลับไปขั้น 1
 *   POST  a=primary   mat= ic=   → ตั้งเป็น IC หลัก (รับบรรทัดเอกสารเดิม/ราคาที่ล็อก)
 *
 * ทุก action คืน llps = โครงสร้างการผูกหลังทำ (LLP แต่ละตัวมี ics ใต้มัน)
 * สิทธิ์: ADM (R0) เท่านั้น — งานแตะ master ตามมติ 16 · PHP 7.4-compatible เท่านั้น
 */

require __DIR__ . '/../config.php';
require __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/ui.php';
require_once __DIR__ . '/../lib/setup_master.php';

$user = uiApiGuard();
if (!uiIsAdmin($user)) {
    uiApiFail('หน้าจัดการรหัสวัสดุสงวนไว้สำหรับผู้ดูแลระบบ (ADM) — มติ 16', 403);
}
if (!smSchemaReady($pdo)) {
    uiApiFail('ยังไม่ได้อัปเดตโครงสร้างฐานข้อมูล — เปิดหน้าจัดการรหัสวัสดุแล้วกดปุ่มอัปเดตก่อน', 409);
}

$a   = (string)($_REQUEST['a'] ?? '');
$mat = mangoNormalizeCode((string)($_REQUEST['mat'] ?? ''));
$ic  = strtoupper(trim((string)($_REQUEST['ic'] ?? '')));
$llp = strtoupper(preg_replace('/\s+/u', '', (string)($_REQUEST['llp'] ?? '')));

// ── ค้นตัวสินค้า (ไม่ต้องมีรหัส Mango) ─────────────────────────────────────
if ($a === 'llp') {
    $rows = smLlpSearch($pdo, (string)($_REQUEST['q'] ?? ''), strtoupper(trim((string)($_REQUEST['l1'] ?? ''))), 40);
    $catL = icCatLabels();
    $chrL = icCharLabels();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'llp_code'  => (string)$r['llp_code'],
            'llp_name'  => (string)$r['llp_name'],
            'l1_name'   => (string)$r['l1_name'],
            'l2_name'   => (string)$r['l2_name'],
            'cat_id'    => (string)($r['cat_id'] ?? ''),
            'char_id'   => (string)($r['char_id'] ?? ''),
            'cat_label' => $r['cat_id'] !== null ? ($catL[$r['cat_id']] ?? '') : '',
            'is_set'    => (bool)$r['is_set'],
            'n_ic'      => (int)$r['n_ic'],
            'n_mango'   => (int)$r['n_mango'],
        ];
    }
    uiApiOut(['ok' => true, 'error' => '', 'rows' => $out]);
}

$mango = mangoGet($pdo, $mat);
if ($mango === null) { uiApiFail('ไม่พบรหัส Mango ' . $mat, 404); }
$matPayload = ['mat_code' => (string)$mango['mat_code'], 'name' => (string)$mango['name'],
               'unit' => (string)$mango['unit'], 'subgroup' => (string)($mango['subgroup_name'] ?? '')];

/** โครงการผูกสำหรับฝั่งหน้าเว็บ — LLP แต่ละตัวพร้อม IC ใต้มัน + ธงหน่วยต่างจาก Mango */
$pack = function (array $blocks) use ($mango): array {
    $catL = icCatLabels();
    $chrL = icCharLabels();
    $out  = [];
    foreach ($blocks as $b) {
        $ics = [];
        foreach ($b['ics'] as $x) {
            $ics[] = [
                'ic_code'    => $x['ic_code'],
                'ic_name'    => $x['ic_name'],
                'unit'       => $x['unit_name'],
                'is_primary' => $x['is_primary'],
                'is_active'  => (int)$x['is_active'] === 1,
                'missing'    => $x['missing'],
                'unit_warn'  => !smUnitSame((string)$mango['unit'], $x['unit_name']),
            ];
        }
        $out[] = [
            'llp_code'   => $b['llp_code'],
            'llp_name'   => $b['llp_name'],
            'path'       => trim($b['l1_name'] . ' › ' . $b['l2_name'], ' ›'),
            'cat_id'     => (string)($b['cat_id'] ?? ''),
            'char_id'    => (string)($b['char_id'] ?? ''),
            'cat_label'  => $b['cat_id']  !== null ? ($catL[$b['cat_id']]   ?? '') : '',
            'char_label' => $b['char_id'] !== null ? ($chrL[$b['char_id']] ?? '') : '',
            'is_set'     => (bool)$b['is_set'],
            'missing'    => (bool)$b['missing'],
            'ics'        => $ics,
        ];
    }
    return $out;
};

if ($a === 'info') {
    uiApiOut(['ok' => true, 'error' => '', 'mat' => $matPayload, 'llps' => $pack(smMapList($pdo, $mat))]);
}

if ($a === 'map_llp') {
    uiApiRequirePost();
    $r = smMapLlp($pdo, $mat, $llp, $user, (string)($_POST['replace'] ?? '') === '1');
    if (!$r['ok']) { uiApiFail($r['error']); }
    uiApiOut(['ok' => true, 'error' => '', 'changed' => !empty($r['changed']), 'mat' => $matPayload,
              'llp_code' => (string)$r['llp']['llp_code'], 'llp_name' => (string)$r['llp']['llp_name'],
              'is_set' => (bool)$r['llp']['is_set'], 'old' => $r['old'], 'dropped' => $r['dropped'],
              'llps' => $pack($r['llps'])]);
}

if ($a === 'unmap_llp') {
    uiApiRequirePost();
    $r = smUnmapLlp($pdo, $mat, $llp, $user);
    if (!$r['ok']) { uiApiFail($r['error']); }
    uiApiOut(['ok' => true, 'error' => '', 'changed' => !empty($r['changed']), 'mat' => $matPayload, 'llps' => $pack($r['llps'])]);
}

if ($a === 'attach_ic') {
    uiApiRequirePost();
    $r = smAttachIc($pdo, $mat, $ic, $user, (string)($_POST['primary'] ?? '') === '1');
    if (!$r['ok']) { uiApiFail($r['error']); }
    uiApiOut(['ok' => true, 'error' => '', 'changed' => !empty($r['changed']), 'mat' => $matPayload,
              'ic_code' => (string)$r['ic']['ic_code'], 'ic_name' => (string)$r['ic']['ic_name'],
              'unit' => (string)$r['ic']['unit_name'],
              'unit_warn' => !smUnitSame((string)$mango['unit'], (string)$r['ic']['unit_name']),
              'llps' => $pack($r['llps'])]);
}

if ($a === 'detach_ic') {
    uiApiRequirePost();
    $r = smDetachIc($pdo, $mat, $ic, $user);
    if (!$r['ok']) { uiApiFail($r['error']); }
    uiApiOut(['ok' => true, 'error' => '', 'changed' => !empty($r['changed']), 'mat' => $matPayload, 'llps' => $pack($r['llps'])]);
}

if ($a === 'primary') {
    uiApiRequirePost();
    $r = smSetPrimary($pdo, $mat, $ic, $user);
    if (!$r['ok']) { uiApiFail($r['error']); }
    uiApiOut(['ok' => true, 'error' => '', 'changed' => !empty($r['changed']), 'mat' => $matPayload, 'llps' => $pack($r['llps'])]);
}

uiApiFail('ไม่รู้จัก action "' . $a . '"', 404);
