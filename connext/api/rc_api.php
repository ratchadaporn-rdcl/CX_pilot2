<?php
/**
 * CONNEXT — api/rc_api.php : endpoint JSON ของแผงจัดของเข้า gate (หน้า rc.php)
 *
 *   GET  ?a=line&b=<bufferId>   → รายละเอียดบรรทัด buffer + IC ที่เคยผูก + ประวัติการนำออก
 *   GET  ?a=lines&proj=&q=      → บรรทัดที่ยังค้างใน buffer ของไซต์นั้น (ใช้รีเฟรชหลังบันทึก)
 *   POST  a=push_batch          → นำหลายบรรทัดออกพร้อมกัน → ใบ IN "ไร้ประตู" ใบเดียว
 *                                 body: csrf, note, jobs=<JSON array>
 *
 * สิทธิ์: uiApiGuard() = CanReq หรือ ADM
 * PHP 7.4-compatible เท่านั้น
 */

require __DIR__ . '/../config.php';
require __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/ui.php';
require_once __DIR__ . '/../lib/po.php';

$user = uiApiGuard();
$a    = (string)($_REQUEST['a'] ?? '');

/** บรรทัด buffer → รูปแบบเดียวที่ฝั่งหน้าเว็บใช้ (ทั้งตารางและการ์ดในตะกร้า) */
function rcLineOut(array $r): array {
    return [
        'id'        => (int)$r['id'],
        'po_no'     => (string)$r['po_no'],
        'line_no'   => (int)$r['line_no'],
        'mat_code'  => (string)($r['mat_code'] ?? ''),
        'mat_name'  => (string)$r['mat_name'],
        'vendor'    => (string)($r['vendor_name'] ?? ''),
        'unit'      => (string)$r['unit_po_name'],
        'received'  => (float)$r['qty_received'],
        'consumed'  => (float)$r['qty_consumed'],
        'remain'    => (float)$r['qty_remain'],
        'line_kind' => (string)($r['line_kind'] ?? 'item'),
    ];
}

// ═══════════════════════════════════════════════════════════════════════
if ($a === 'lines') {
    $projId = (int)($_REQUEST['proj'] ?? ($user['projectId'] ?? 0));
    $q      = trim((string)($_REQUEST['q'] ?? ''));
    $rows   = poBufferLines($pdo, $projId, $q, true);

    $out = [];
    foreach ($rows as $r) {
        $one = rcLineOut($r);
        // IC ที่เคยผูกกับรหัสนี้ของผู้ขายรายนี้ (มติ 20) — ติดมากับแถวเลย
        // เพื่อให้ลากชิปเข้าช่องได้ทันทีโดยไม่ต้องยิงต่ออีกรอบ
        $one['suggest'] = icSuggestFor($pdo, poVendorKey($one['vendor']), $one['mat_code']);
        $out[] = $one;
    }
    uiApiOut(['ok' => true, 'error' => '', 'rows' => $out]);
}

// ═══════════════════════════════════════════════════════════════════════
if ($a === 'line') {
    $bufId = (int)($_REQUEST['b'] ?? 0);
    $buf   = poBufferGet($pdo, $bufId);
    if ($buf === null) { uiApiFail('ไม่พบบรรทัดใน buffer', 404); }

    $one = rcLineOut($buf);
    $one['description'] = (string)($buf['description'] ?? '');
    $one['proj_code']   = (string)($buf['proj_code'] ?? '');
    $one['suggest']     = icSuggestFor($pdo, poVendorKey($one['vendor']), $one['mat_code']);
    $one['history']     = poPushHistory($pdo, $bufId);
    uiApiOut(['ok' => true, 'error' => '', 'line' => $one]);
}

// ═══════════════════════════════════════════════════════════════════════
if ($a === 'push_batch') {
    uiApiRequirePost();

    $jobs = json_decode((string)($_POST['jobs'] ?? ''), true);
    if (!is_array($jobs) || empty($jobs)) {
        uiApiFail('ยังไม่ได้เลือกของสักบรรทัด — ลากบรรทัดจากตาราง buffer มาวางในตะกร้าก่อน');
    }

    $clean = [];
    foreach ($jobs as $j) {
        $allocs = [];
        foreach ((array)($j['allocs'] ?? []) as $al) {
            $allocs[] = [
                'ic_code' => (string)($al['ic_code'] ?? ''),
                'qty'     => (float)($al['qty'] ?? 0),
            ];
        }
        $clean[] = [
            'buffer_id'    => (int)($j['buffer_id'] ?? 0),
            'qty_consumed' => (float)($j['qty_consumed'] ?? 0),
            'allocs'       => $allocs,
        ];
    }

    // [2026-10-02 · GP-10] รูปใบส่งของ (data URI · JSON) — บังคับอย่างน้อย 1 รูป (ตรวจใน poPushBatch)
    $photos = json_decode((string)($_POST['photos'] ?? '[]'), true);
    $r = poPushBatch($pdo, $clean, (string)($_POST['note'] ?? ''), $user,
                     ['source' => 'supplier', 'photos' => is_array($photos) ? $photos : []]);
    if (!$r['ok']) { uiApiFail($r['error']); }

    uiApiOut([
        'ok'      => true,
        'error'   => '',
        'doc_no'  => $r['doc_no'],
        'n_lines' => $r['n_lines'],
    ]);
}

uiApiFail('ไม่รู้จัก action "' . $a . '"', 404);
