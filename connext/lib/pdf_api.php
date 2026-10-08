<?php
/**
 * CONNEXT — lib/pdf_api.php : RPC สร้าง PDF 3 ตัว (พอร์ตจาก Code.js v1.10 · generateStatsReportPDF เอาออก 2026-10-02 พร้อมหน้า "สถิติ")
 *   generateDocReportPDF(docId, docType)
 *   generateBalancePDF(siteCode, username)
 *   generateDeductionPDF(payload) — งวดครึ่งเดือน/docNos/ราคาแช่/ลายเซ็นออนไลน์ ครบตาม GAS
 *
 * โครงผลลัพธ์เหมือน GAS เป๊ะ: {success:true, dataUri:'data:application/pdf;base64,…', fileName}
 * ผิดพลาด → {success:false, message} (client เช็ค res.success เอง)
 *
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/pdf_engine.php';
require_once __DIR__ . '/signatures.php';   // งวด/ราคาแช่/คำขอลายเซ็น ที่ใบหักเงินใช้ร่วมกับหน้า e-sign

// =========================================================================
// helpers ภายใน (ล้อ helper ของ GAS — query DB แทนชีต)
// =========================================================================

/** สิทธิ์ CanDailyCheck จาก session user (server-authoritative — mirror userCanDailyCheck_) */
function _pdfUserCanDailyCheck(PDO $pdo, ?array $user): bool {
    if (!$user || ($user['accountType'] ?? '') !== 'user') { return false; }
    $st = $pdo->prepare(
        'SELECT r.can_daily_check FROM users u JOIN roles r ON r.id = u.role_id
         WHERE u.username = ? LIMIT 1'
    );
    $st->execute([trim((string)$user['username'])]);
    $v = $st->fetchColumn();
    return $v !== false && isTrueFlag($v);
}

/** โครงการจาก code → แถว projects หรือ null */
function _pdfProjectByCode(PDO $pdo, string $code): ?array {
    $code = trim($code);
    if ($code === '') { return null; }
    $st = $pdo->prepare('SELECT id, code, name, site_ref FROM projects WHERE code = ? LIMIT 1');
    $st->execute([$code]);
    $row = $st->fetch();
    return $row ?: null;
}

/** map sub_code → SubName (แถวแรกชนะ — พฤติกรรม subMap ของ GAS) */
function _pdfSubNameByCode(PDO $pdo): array {
    $map = [];
    $st = $pdo->query('SELECT sub_code, name FROM subcontractors ORDER BY id');
    foreach ($st->fetchAll() as $r) {
        $k = fmtSubId($r['sub_code']);
        if ($k !== '' && !isset($map[$k])) { $map[$k] = (string)$r['name']; }
    }
    return $map;
}

/** resolve Receiver → ชื่อแสดงผล (mirror resolveRecv: 'DC:' คงเดิม, SubID → SubName, อื่น ๆ คงเดิม) */
function _pdfResolveReceiver(string $raw, array $subMap): string {
    $raw = trim($raw);
    if ($raw === '') { return ''; }
    if (strpos($raw, 'DC:') === 0) { return $raw; }
    $k = fmtSubId($raw);
    return isset($subMap[$k]) ? $subMap[$k] : $raw;
}

/** map username → FullName (mirror userMap) */
function _pdfUserFullNameMap(PDO $pdo): array {
    $map = [];
    $st = $pdo->query('SELECT username, full_name FROM users');
    foreach ($st->fetchAll() as $r) {
        $un = trim((string)$r['username']);
        if ($un !== '') { $map[$un] = trim((string)$r['full_name']); }
    }
    return $map;
}

// ---- ตัวช่วยสถานะ (mirror lc/isCancel/isReject/isPending/isSuccess/dispStatus) ----
function _pdfLc($s): string { return mb_strtolower(trim((string)$s), 'UTF-8'); }
function _pdfIsCancel($s): bool {
    $x = _pdfLc($s);
    return strpos($x, 'cancel') !== false || strpos($x, 'ยกเลิก') !== false;
}
function _pdfIsReject($s): bool {
    $x = _pdfLc($s);
    return strpos($x, 'reject') !== false || strpos($x, 'denied') !== false || strpos($x, 'ไม่อนุมัติ') !== false;
}
function _pdfIsPending($s): bool {
    $x = _pdfLc($s);
    return strpos($x, 'await') !== false || strpos($x, 'pending') !== false || strpos($x, 'รออนุมัติ') !== false;
}
function _pdfIsSuccess($s): bool {
    return !_pdfIsCancel($s) && !_pdfIsReject($s) && !_pdfIsPending($s);
}
function _pdfDispStatus($s): string {
    $x = _pdfLc($s);
    if (_pdfIsCancel($x)) { return 'ยกเลิก'; }
    if (_pdfIsReject($x)) { return 'ไม่อนุมัติ'; }
    if (strpos($x, 'revision') !== false) { return 'ตีกลับให้แก้'; }   // [2026-10-06]
    if (strpos($x, 'await') !== false || strpos($x, 'pending') !== false || strpos($x, 'รออนุมัติ') !== false) { return 'รออนุมัติ'; }
    if (strpos($x, 'sent') !== false && strpos($x, 'borrow') !== false) { return 'ส่งยืม'; }
    if (strpos($x, 'sent') !== false && strpos($x, 'return') !== false) { return 'ส่งคืน'; }
    if ($x === 'borrowed') { return 'ยืมอยู่'; }
    if ($x === 'returned') { return 'คืนแล้ว'; }
    return 'สำเร็จ';
}

/** dataUri response builder */
function _pdfOk(string $binary, string $fileName): array {
    return [
        'success'  => true,
        'dataUri'  => 'data:application/pdf;base64,' . base64_encode($binary),
        'fileName' => $fileName,
    ];
}

// =========================================================================
// generateDocReportPDF(docId, docType) — รายงานเอกสารรายใบ (หน้า History)
// =========================================================================
function rpc_generateDocReportPDF(PDO $pdo, ?array $user, array $args) {
    try {
        $docId   = trim((string)($args[0] ?? ''));
        $docType = strtoupper(trim((string)($args[1] ?? '')));
        if ($docId === '' || $docType === '') {
            return ['success' => false, 'message' => 'Missing docId or docType'];
        }
        $labels = [
            'RD' => 'ใบเบิกวัสดุหลัก',
            'OD' => 'ใบเบิกวัสดุเบ็ดเตล็ด',
            'BD' => 'ใบยืม-คืน อุปกรณ์',
            'IN' => 'ใบรับเข้าคลัง',
            'TD' => 'ใบเบิกโอนย้ายข้ามไซต์',   // 2026-09-29
            'TG' => 'ใบย้าย Gate (ภายในไซต์)',   // 2026-10-02
            'SC' => 'ใบนับสต๊อก',             // 2026-09-29
        ];
        if (!isset($labels[$docType])) {
            return ['success' => false, 'message' => 'Unknown docType: ' . $docType];
        }

        $st = $pdo->prepare(
            'SELECT d.*, p.code AS site_code, p.name AS p_name, p.site_ref
             FROM documents d JOIN projects p ON p.id = d.project_id
             WHERE d.doc_no = ? AND d.doc_type = ? LIMIT 1'
        );
        $st->execute([$docId, $docType]);
        $doc = $st->fetch();
        if (!$doc) {
            return ['success' => false, 'message' => 'ไม่พบเอกสาร ' . $docId];
        }

        $it = $pdo->prepare(
            'SELECT i.*, m.name AS master_name, m.unit AS master_unit
             FROM document_items i LEFT JOIN materials m ON m.mat_code = i.mat_code
             WHERE i.document_id = ? ORDER BY i.id'
        );
        $it->execute([(int)$doc['id']]);
        $itemRows = $it->fetchAll();

        // ── Receiver resolve (mirror: 'DC:' คงเดิม, SubID → SubName, ไม่แมตช์คืน raw) ──
        $recvRaw = trim((string)($doc['receiver_name'] ?? ''));
        $receiverName = $recvRaw !== '' ? $recvRaw : '-';
        if ($recvRaw !== '' && strpos($recvRaw, 'DC:') !== 0) {
            $subMap = _pdfSubNameByCode($pdo);
            $k = fmtSubId($recvRaw);
            $receiverName = isset($subMap[$k]) ? $subMap[$k] : $recvRaw;
        }

        // ── ผู้นำจ่าย: gate_logs.card_id → users lookup (mirror GAS) ──
        // Scenario 05 ⑥: ใบที่เพิ่มเข้ารอบด้วยบัตรคนอื่น → ผู้นำจ่ายของใบนั้น = เจ้าของบัตรที่ยืนยันใบนั้น (card ของแถวใบเอง)
        $cardName = function (string $cardId) use ($pdo): string {
            if ($cardId === '') { return '-'; }
            $us = $pdo->prepare('SELECT full_name, username FROM users WHERE card_id = ? LIMIT 1');
            $us->execute([$cardId]);
            $u = $us->fetch();
            $n = $u ? (trim((string)$u['full_name']) !== '' ? (string)$u['full_name'] : (string)$u['username']) : '';
            return $n !== '' ? $n : 'CardID: ' . $cardId;
        };
        $gl = $pdo->prepare(
            "SELECT gl.card_id, gl.picking_id, g.gate_code FROM gate_logs gl LEFT JOIN gates g ON g.id = gl.gate_id
             WHERE gl.doc_no = ? AND gl.card_id IS NOT NULL AND gl.card_id <> '' ORDER BY gl.id LIMIT 1"
        );
        $gl->execute([$docId]);
        $glRow = $gl->fetch() ?: [];
        $releaserName = $cardName(trim((string)($glRow['card_id'] ?? '')));
        $roundLabel = trim((string)($glRow['picking_id'] ?? '')) !== ''
            ? trim((string)$glRow['picking_id']) . (trim((string)($glRow['gate_code'] ?? '')) !== '' ? ' · ประตู ' . trim((string)$glRow['gate_code']) : '')
            : '';
        $returnerName = '-';
        if ($docType === 'BD') {
            $gl->execute([$docId . 'RT']);
            $rtRow = $gl->fetch() ?: [];
            $returnerName = $cardName(trim((string)($rtRow['card_id'] ?? '')));
        }

        // ── สถานะ → ไทย (mirror statusThai) ──
        $statusThai = [
            'awaiting approval'     => 'รออนุมัติ',
            'awaiting for approval' => 'รออนุมัติ',
            'awaiting revision'     => 'ตีกลับให้แก้',   // [2026-10-06]
            'pending'               => 'รออนุมัติ',
            'approved'              => 'อนุมัติแล้ว',
            'sent to gate'          => 'อนุมัติแล้ว',
            'rejected'              => 'ไม่อนุมัติ',
            'denied'                => 'ไม่อนุมัติ',
            'closed'                => 'นำจ่ายแล้ว',
            'sent borrow'           => 'ส่งยืม',
            'borrowed'              => 'ยืมอยู่',
            'sent return'           => 'ส่งคืน',
            'returned'              => 'คืนแล้ว',
            'completed'             => 'สำเร็จ',
            'opened'                => 'เปิดประตูแล้ว',
            'scanned'               => 'สแกนแล้ว',
            'confirmed'             => 'ยืนยันแล้ว',
        ];
        $rawStatus = (string)($doc['status'] ?? '');
        $lcStatus  = mb_strtolower($rawStatus, 'UTF-8');
        $statusDisplay = isset($statusThai[$lcStatus]) ? $statusThai[$lcStatus]
                       : ($rawStatus !== '' ? $rawStatus : '-');

        $fmtDate = function ($ts) {
            if (!$ts) { return '-'; }
            $dt = new DateTime((string)$ts);
            return $dt->format('d/m/Y H:i');
        };

        // ── usage/notice: header (สำเนาแถวแรก) → fallback รายการแรก ──
        $usage = trim((string)($doc['usage_area'] ?? ''));
        if ($usage === '' && $itemRows) { $usage = trim((string)($itemRows[0]['usage_area'] ?? '')); }
        $notice = trim((string)($doc['notice'] ?? ''));
        if ($notice === '' && $itemRows) { $notice = trim((string)($itemRows[0]['notice'] ?? '')); }

        $meta = [
            ['วันที่',        $fmtDate($doc['doc_ts'])],
            ['สถานะ',        $statusDisplay],
            ['ผู้ทำเบิก',      trim((string)$doc['requester_username']) !== '' ? (string)$doc['requester_username'] : '-'],
            ['ผู้รับ',         $receiverName],
            // GAS quirk: อ่านคอลัมน์ 'Approver' ซึ่งไม่มีใน OddsLogs (OD ใช้ 'Picker')
            // → รายงาน OD แสดง '-' เสมอ (mirror พฤติกรรมเดิม)
            ['ผู้อนุมัติ',      ($docType !== 'OD' && trim((string)($doc['approver_username'] ?? '')) !== '')
                                ? (string)$doc['approver_username'] : '-'],
            ['ผู้นำจ่าย',      $releaserName],
            ['พื้นที่ใช้งาน',   $usage !== '' ? $usage : '-'],
        ];
        if ($roundLabel !== '') { $meta[] = ['รอบเบิก', $roundLabel]; }
        if ($docType === 'BD' && $returnerName !== '-') { $meta[] = ['ผู้นำคืน', $returnerName]; }
        // [Scenario 05 ③ · 2026-09-29] ใบยืม: กำหนดวันคืน · คืนบางส่วน · รายการที่ตีเป็นชำรุด/สูญหาย
        $bdWo = [];
        if ($docType === 'BD') {
            require_once __DIR__ . '/borrow.php';
            $due = (string)($doc['due_date'] ?? '');
            $endDay = !empty($doc['return_ts']) ? substr((string)$doc['return_ts'], 0, 10) : null;
            $od = borrowOverdueDays($due, $endDay);
            $meta[] = ['กำหนดคืน', $due !== '' ? borrowThaiDate($due) . ($od > 0 ? ' (เกินกำหนด ' . $od . ' วัน)' : '') : '-'];
            foreach (borrowDocItems($pdo, (int)$doc['id']) as $bi) { $bdWo[$bi['id']] = $bi; }
            if (isTrueFlag($doc['writeoff_flag'] ?? 0)) {
                $meta[] = ['ชำรุด/สูญหาย', 'มีรายการที่สายสโตร์ตีเป็นชำรุด/สูญหาย — ดู "รายงานอุปกรณ์ชำรุด/สูญหาย" ของใบนี้'];
            }
        }
        if (strtolower(trim((string)($doc['origin_type'] ?? ''))) === 'bypass') {
            $meta[] = ['ช่องทาง', 'Bypass — คีย์ย้อนหลังจากแบบฟอร์มกระดาษ (ไม่ผ่านประตู)'];
        } else {
            // [2026-09-29] Bypass ประตู (หน้า Bypass · เลือกเลขเอกสาร): เปิด/ปิดรอบแทนตู้ — ถ่ายรูปยืนยันตามปกติ
            $bq = $pdo->prepare("SELECT action, user_name, created_at FROM activity_log
                                  WHERE entity_type = 'document' AND entity_id IN (?, ?) AND action IN ('gate_bypass_open', 'gate_bypass_close')
                                  ORDER BY id");
            $bq->execute([$docId, $docId . 'RT']);
            $bp = [];
            foreach ($bq->fetchAll() as $b) {
                $bp[] = ($b['action'] === 'gate_bypass_open' ? 'เปิด ' : 'ปิด ') . (string)$b['user_name'] . ' ' . substr((string)$b['created_at'], 0, 16);
            }
            if ($bp) { $meta[] = ['ช่องทาง', 'Bypass ประตู (ตู้/ประตูใช้ไม่ได้) — ' . implode(' · ', $bp)]; }
        }
        // [2026-09-29] TD: ไซต์ปลายทาง · SC: ผลการนับ + การปรับยอด (lib/stockcount.php)
        $scAdj = [];
        if ($docType === 'TG') {   // [2026-10-02] ใบย้าย Gate: เส้นทาง G → G · สถานะการย้าย · ขานำเข้า — lib/gatemove.php
            require_once __DIR__ . '/gatemove.php';
            $tgMeta = gmPdfMeta($pdo, $doc);
            $meta[1] = array_shift($tgMeta);
            $meta[5][0] = 'ผู้แตะบัตรเบิกออก';
            unset($meta[6]);   // พื้นที่ใช้งาน — ไม่ใช้กับใบย้าย Gate
            foreach ($tgMeta as $mv) { $meta[] = $mv; }
            $meta = array_values($meta);
        }
        if ($docType === 'TD') {
            $dp = null;
            if (!empty($doc['dest_project_id'])) {
                $dq = $pdo->prepare('SELECT code, name FROM projects WHERE id = ?');
                $dq->execute([(int)$doc['dest_project_id']]);
                $dp = $dq->fetch();
            }
            $meta[6] = ['ผู้รับที่ไซต์ปลายทาง', $usage !== '' ? $usage : '-'];   // TD เก็บผู้รับปลายทางในช่องพื้นที่ใช้งาน
            $meta[] = ['ไซต์ปลายทาง', $dp ? (string)$dp['code'] . ' · ' . (string)$dp['name'] : '-'];
            $meta[] = ['หมายเหตุการโอน', 'ต้นทางตัดสต๊อกเมื่อปิดประตู — ไซต์ปลายทางคีย์ใบรับเข้า (IN) เอง'];
        } elseif ($docType === 'SC') {
            $meta[0] = ['วันที่สร้างใบนับ', $fmtDate($doc['doc_ts'])];
            $meta[2] = ['ผู้สร้างใบนับ', trim((string)$doc['requester_username']) !== '' ? (string)$doc['requester_username'] : '-'];
            $meta[3] = ['ประตูที่นับ', trim((string)($glRow['gate_code'] ?? '')) !== '' ? (string)$glRow['gate_code']
                                       : (preg_match('/G\d+$/i', $docId, $gm) ? strtoupper($gm[0]) : '-')];
            $meta[4] = ['ผู้อนุมัติปรับยอด', '-'];
            $meta[5] = ['ผู้แตะบัตรเปิดประตู', $releaserName];
            unset($meta[6]);   // พื้นที่ใช้งาน — ไม่ใช้กับใบนับ
            foreach ($meta as $mi => $mv) { if ($mv[0] === 'รอบเบิก') { $meta[$mi][0] = 'รอบเปิดประตู'; } }
            $aq = $pdo->prepare('SELECT item_id, status, note, decided_by, decided_at FROM stock_adjustments WHERE document_id = ?');
            $aq->execute([(int)$doc['id']]);
            $by = [];
            foreach ($aq->fetchAll() as $a) {
                $scAdj[(int)$a['item_id']] = $a;
                $by[(string)$a['decided_by']] = true;
            }
            if ($by) { $meta[4] = ['ผู้อนุมัติปรับยอด', implode(', ', array_keys($by))]; }
            $meta = array_values($meta);
        }

        // [2026-10-02 · GP-10] ใบ IN: ผู้ออกใบ (บัญชีที่ล็อกอิน) + ที่มาของของ (มีใบส่งของ / ไม่มีใบส่งของ — อ้างอิง TD/BD หรือเหตุผล)
        if ($docType === 'IN') {
            require_once __DIR__ . '/inbound_ctl.php';
            $meta[2][0] = 'ผู้ออกใบรับเข้า';
            $inSrcTxt = inCtlSourceText($doc);
            if ($inSrcTxt === '') {
                $inRs = trim((string)($doc['rs_no'] ?? ''));
                $inSrcTxt = ($inRs !== '' ? 'เลขที่ RS/PO ' . $inRs . ' · ' : '') . 'ใบก่อน 2 ต.ค. 2026 (ไม่มีรูปใบส่งของ)';
            }
            $meta[] = ['ที่มาของของ', $inSrcTxt];
        }

        // ── รายการวัสดุ: จำนวนที่ขอ · หยิบจริง · ผลต่าง + เหตุผลของรายการที่ลด (Scenario 05 ⑦) ──
        $items = [];
        $n = 0;
        $itemPhotoCells = [];
        foreach ($itemRows as $r) {
            $n++;
            $name = trim((string)($r['mat_name'] ?? ''));
            if ($name === '') { $name = trim((string)($r['master_name'] ?? '')); }
            if ($name === '') { $name = '-'; }
            $unit = trim((string)($r['master_unit'] ?? ''));
            if ($unit === '') { $unit = trim((string)($r['unit'] ?? '')); }
            $hasActual = $r['qty_actual'] !== null && $r['qty_actual'] !== '';
            $diff = $hasActual ? round((float)$r['qty_actual'] - (float)$r['qty'], 3) : null;
            $reasonTxt = trim((string)($r['actual_reason'] ?? ''));
            if (isset($bdWo[(int)$r['id']])) {
                // ใบยืม: คืนจริง / ตีเป็นชำรุด-สูญหาย / ยังค้าง ต่อท้ายช่องหมายเหตุ
                $bi = $bdWo[(int)$r['id']];
                $extra = [];
                if ($bi['returned'] !== null) {
                    $extra[] = 'คืน ' . pdfQty($bi['returned']) . ($bi['returnReason'] !== '' ? ' (' . $bi['returnReason'] . ')' : '');
                }
                if ($bi['writtenOff'] > 0.0005) { $extra[] = 'ชำรุด/สูญหาย ' . pdfQty($bi['writtenOff']); }
                if ($bi['outstanding'] > 0.0005 && ($bi['returned'] !== null || $bi['writtenOff'] > 0.0005)) {
                    $extra[] = 'ค้าง ' . pdfQty($bi['outstanding']);
                }
                if ($extra) { $reasonTxt = trim($reasonTxt . ($reasonTxt !== '' ? ' · ' : '') . implode(' · ', $extra)); }
            }
            if ($docType === 'TG' && $r['qty_returned'] !== null && $r['qty_returned'] !== '') {   // [2026-10-02] ใบย้าย Gate: นำเข้าแล้ว
                $reasonTxt = trim($reasonTxt . ($reasonTxt !== '' ? ' · ' : '') . 'นำเข้า ' . pdfQty($r['qty_returned']));
            }
            if ($docType === 'SC') {
                // ใบนับ: qty = ยอดในระบบตอนนับ · qty_actual = นับได้ · หมายเหตุ = ผลการอนุมัติปรับยอด
                $a = $scAdj[(int)$r['id']] ?? null;
                if ($diff !== null && abs($diff) >= 0.0005) {
                    $reasonTxt = $a ? (((string)$a['status'] === 'approved' ? 'ปรับยอดแล้ว' : 'ไม่ปรับยอด')
                                       . (trim((string)$a['note']) !== '' ? ' (' . trim((string)$a['note']) . ')' : ''))
                                    : 'รออนุมัติปรับยอด';
                } elseif (!$hasActual) {
                    $reasonTxt = 'ยังไม่ได้นับ';
                }
            }
            $items[] = [
                'no'      => $n,
                'matCode' => (string)$r['mat_code'],
                'name'    => $name,
                'qty'     => pdfQty($r['qty']),
                'actual'  => $hasActual ? pdfQty($r['qty_actual']) : '-',
                'diff'    => $diff === null ? '-' : (abs($diff) < 0.0005 ? '0' : (($diff > 0 ? '+' : '−') . pdfQty(abs($diff)))),
                'diffCls' => $diff === null || abs($diff) < 0.0005 ? '' : ($diff > 0 ? 'up' : 'down'),
                'unit'    => $unit,
                'reason'  => $reasonTxt,
            ];
            // ใบคีย์จากแบบฟอร์มกระดาษ (bypass): รูปรายรายการ = รูปสินค้าที่เบิก (2026-09-29)
            $isPaper = strtolower(trim((string)($doc['origin_type'] ?? ''))) === 'bypass';
            foreach (['photo_url' => ($isPaper ? 'รูปสินค้าที่เบิก' : ($docType === 'TG' ? 'รูปยืนยันเบิกออก' : 'รูปยืนยัน')),
                      'photo_return_url' => ($docType === 'TG' ? 'รูปยืนยันนำเข้า' : 'รูปยืนยันคืน')] as $col => $lbl) {
                $raw = trim((string)($r[$col] ?? ''));
                if ($raw === '') { continue; }
                foreach (preg_split('/[,;\s]+/u', $raw) as $p) {
                    $p = trim($p);
                    if ($p !== '') { $itemPhotoCells[] = ['url' => $p, 'label' => $lbl . ' — ' . (string)$r['mat_code']]; }
                }
            }
        }

        // ── รูปยืนยัน: local uploads/ → ฝัง <img>, Drive URL เดิม → บรรทัดข้อความ ──
        $collect = function (?string $raw, string $label) {
            $out = [];
            $raw = trim((string)$raw);
            if ($raw === '') { return $out; }
            $pieces = preg_split('/[,;\s]+/u', $raw);
            foreach ($pieces as $p) {
                $p = trim($p);
                if ($p === '') { continue; }
                $out[] = ['url' => $p, 'label' => $label];
            }
            return $out;
        };
        // รูปรายรายการมาก่อน (ป้ายบอกรหัส IC) แล้วค่อยรูปรวมของใบ (ใบเก่า) — dedup ตาม URL ด้านล่าง
        $photos = $itemPhotoCells;
        if ($docType === 'BD') {
            $photos = array_merge(
                $photos,
                $collect($doc['photo_url'] ?? '', 'รูปยืนยันยืม'),
                $collect($doc['photo_return_url'] ?? '', 'รูปยืนยันคืน')
            );
        } else {
            $isPaperDoc = strtolower(trim((string)($doc['origin_type'] ?? ''))) === 'bypass';
            $photos = array_merge($photos, $collect($doc['photo_url'] ?? '', $isPaperDoc ? 'รูปแบบฟอร์มกระดาษ' : 'รูปยืนยัน'));
        }
        if ($docType === 'IN' && trim((string)($doc['in_photo_url'] ?? '')) !== '') {
            // [2026-10-02 · GP-10] รูปใบส่งของ / รูปของที่รับเข้า (ตอนออกใบ) — ขึ้นก่อนรูปยืนยันที่ประตู
            $photos = array_merge($collect((string)$doc['in_photo_url'], inCtlPhotoLabel((string)($doc['in_source'] ?? ''))), $photos);
        }
        // dedup ตาม URL (GAS dedup ตาม Drive file ID)
        $seen = [];
        $uniq = [];
        foreach ($photos as $p) {
            if (isset($seen[$p['url']])) { continue; }
            $seen[$p['url']] = true;
            $uniq[] = $p;
        }
        $root = _pdfRoot();
        $photoCells = [];
        foreach ($uniq as $p) {
            $url = $p['url'];
            $isLocal = (strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0
                        && strpos($url, 'uploads/') === 0);
            if ($isLocal && is_file($root . $url)) {
                $photoCells[] = ['label' => $p['label'], 'src' => $root . $url, 'note' => null];
            } elseif ($isLocal) {
                $photoCells[] = ['label' => $p['label'], 'src' => null,
                                 'note' => '(ไม่พบไฟล์รูป)' . "\n" . $url];
            } else {
                // Drive URL จากเอกสาร import เดิม — ไม่ fetch ระยะไกล แสดงเป็นข้อความ
                $photoCells[] = ['label' => $p['label'], 'src' => null,
                                 'note' => '(รูปจากระบบเดิม — เปิดดูได้ที่ลิงก์)' . "\n" . $url];
            }
        }
        // จัดเป็นแถวละ 2 รูป (mirror layout เดิม)
        $photoRows = [];
        for ($i = 0; $i < count($photoCells); $i += 2) {
            $row = [$photoCells[$i]];
            if (isset($photoCells[$i + 1])) { $row[] = $photoCells[$i + 1]; }
            $photoRows[] = $row;
        }

        $siteRef = trim((string)($doc['site_ref'] ?? ''));
        $siteHeader = (string)$doc['site_code'] . '  |  ' . (string)$doc['p_name']
                    . ($siteRef !== '' && $siteRef !== '-' ? '  |  SiteID: ' . $siteRef : '');

        $tplVars = [];
        if ($docType === 'SC') {
            $tplVars = ['itemsTitle' => 'ผลการนับสต๊อก (ยอดในระบบ ณ เวลาบันทึกผลนับ)', 'colQty' => 'ในระบบ', 'colActual' => 'นับได้'];
        }
        $html = pdfRenderTemplate('doc_report.php', $tplVars + [
            'siteHeader' => $siteHeader,
            'docLabel'   => $labels[$docType],
            'docId'      => $docId,
            'meta'       => $meta,
            'items'      => $items,
            'noticeText' => $notice !== '' ? $notice : '-',
            'photoRows'  => $photoRows,
            'footerText' => 'สร้างโดยระบบ CONNEXT — ' . date('d/m/Y H:i'),
        ]);
        $bin = renderPdf($html, 'a4', 'portrait');
        return _pdfOk($bin, $docId . '_Report.pdf');
    } catch (Throwable $err) {
        error_log('generateDocReportPDF error: ' . $err->getMessage());
        return ['success' => false, 'message' => $err->getMessage()];
    }
}

// =========================================================================
// generateBalancePDF(siteCode, username) — ยอดคงเหลือวัสดุ (template4)
// =========================================================================
function rpc_generateBalancePDF(PDO $pdo, ?array $user, array $args) {
    try {
        // GAS เช็ค username ว่าง → no_permission (ฝั่งนี้ยึด session)
        $callerName = $user ? trim((string)$user['username']) : '';
        if ($callerName === '') {
            return ['success' => false, 'message' => 'no_permission'];
        }
        $wantSite = trim((string)($args[0] ?? ''));

        // เฉพาะรหัส IC — ตรงกับตาราง Dashboard (มติ 34) · ยอดที่ยังค้างบนรหัส Mango ไม่อยู่ในรายงานนี้
        $sql = "SELECT p.code AS site, m.mat_code, m.name AS m_name, m.unit AS m_unit,
                       m.subgroup_name, b.qty_in, b.qty_out, b.on_hand, b.pending
                FROM stock_balances b
                JOIN materials m ON m.id = b.material_id
                JOIN projects  p ON p.id = b.project_id
                WHERE m.code_type = 'ic'";
        $params = [];
        if ($wantSite !== '') {
            $sql .= ' AND p.code = ?';
            $params[] = $wantSite;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $bRows = $st->fetchAll();

        if (!$bRows) {
            // mirror: ไม่มีชีต/ข้อมูลเลย → 'ไม่พบข้อมูล Balance', กรองแล้วว่าง → ระบุ Site
            $any = (int)$pdo->query('SELECT COUNT(*) FROM stock_balances')->fetchColumn();
            if ($any === 0) {
                return ['success' => false, 'message' => 'ไม่พบข้อมูล Balance'];
            }
            return ['success' => false,
                    'message' => 'ไม่พบรายการ Balance' . ($wantSite !== '' ? ' ของ Site ' . $wantSite : '')];
        }

        // ยอดรายประตู — คอลัมน์ "รายประตู" เช่น "G01 10 · G03 50" (มติ 51 — [PHP port 2026-09-25 per-gate])
        $gsql = "SELECT p.code AS site, m.mat_code, g.gate_code, gb.on_hand
                   FROM stock_gate_balances gb
                   JOIN materials m ON m.id = gb.material_id
                   JOIN projects  p ON p.id = gb.project_id
                   JOIN gates     g ON g.id = gb.gate_id
                  WHERE m.code_type = 'ic' AND g.status = 'active' AND gb.on_hand <> 0";
        $gparams = [];
        if ($wantSite !== '') { $gsql .= ' AND p.code = ?'; $gparams[] = $wantSite; }
        $gsql .= ' ORDER BY g.gate_code';
        $gst = $pdo->prepare($gsql);
        $gst->execute($gparams);
        $gatesOf = [];
        foreach ($gst->fetchAll() as $gr) {
            $k = (string)$gr['site'] . '|' . trim((string)$gr['mat_code']);
            $gatesOf[$k][] = (string)$gr['gate_code'] . ' ' . pdfBalNum($gr['on_hand']);
        }

        $rows = [];
        foreach ($bRows as $r) {
            $mc = trim((string)$r['mat_code']);
            if ($mc === '') { continue; }
            $rows[] = [
                'site'     => (string)$r['site'],
                'matCode'  => $mc,
                'gates'    => implode(' · ', $gatesOf[(string)$r['site'] . '|' . $mc] ?? []),
                'subgroup' => trim((string)($r['subgroup_name'] ?? '')),
                'name'     => trim((string)$r['m_name']) !== '' ? (string)$r['m_name'] : $mc,
                'unit'     => trim((string)($r['m_unit'] ?? '')),
                'inv'      => (float)$r['qty_in'],
                'pending'  => (float)$r['pending'],
                'out'      => (float)$r['qty_out'],
                'onhand'   => (float)$r['on_hand'],
            ];
        }
        if (!$rows) {
            return ['success' => false,
                    'message' => 'ไม่พบรายการ Balance' . ($wantSite !== '' ? ' ของ Site ' . $wantSite : '')];
        }

        $includeSite = ($wantSite === '');
        usort($rows, function ($a, $b) use ($includeSite) {
            if ($includeSite && $a['site'] !== $b['site']) {
                return $a['site'] < $b['site'] ? -1 : 1;
            }
            if ($a['matCode'] === $b['matCode']) { return 0; }
            return $a['matCode'] < $b['matCode'] ? -1 : 1;
        });

        $siteName = '';
        if ($wantSite !== '') {
            $proj = _pdfProjectByCode($pdo, $wantSite);
            if ($proj) { $siteName = (string)$proj['name']; }
        }

        $haveStock = 0;
        foreach ($rows as $r) { if ($r['onhand'] > 0) { $haveStock++; } }
        $stamp = date('d/m/Y H:i');
        $titleTxt = 'CONNEXT  —  ยอดคงเหลือวัสดุ (Balance)'
                  . ($wantSite !== ''
                        ? '   ·   Site: ' . $wantSite . ($siteName !== '' ? ' · ' . $siteName : '')
                        : '   ·   ทุก Site')
                  . '   ·   ' . count($rows) . ' รายการ (มีของ ' . $haveStock . ')   ·   ณ ' . $stamp . ' น.';

        $html = pdfRenderTemplate('balance.php', [
            'titleTxt'    => $titleTxt,
            'includeSite' => $includeSite,
            'rows'        => $rows,
        ]);
        $bin = renderPdf($html, 'a4', 'landscape', ['pageNumbers' => true]);
        $fileName = 'Balance_' . ($wantSite !== '' ? $wantSite : 'AllSites') . '_' . date('Ymd_Hi') . '.pdf';
        return _pdfOk($bin, $fileName);
    } catch (Throwable $err) {
        error_log('generateBalancePDF error: ' . $err->getMessage());
        return ['success' => false, 'message' => $err->getMessage()];
    }
}

// =========================================================================
// generateDeductionPDF(payload) — ตารางหักเงินผู้รับเหมาชุด (1 ชุด = 1 หน้า)
// ล้อ GAS v1.10 ครบ: งวดครึ่งเดือน (half) · โหมดออกซ้ำตามใบ (docNos) · ราคาแช่
// (deduction_doc_rates ก่อน Rate Card สด) · หัว "ประจำงวด" · ผู้สรุปเอกสาร = ผู้ออกใบ
// (summarizerFromIssuer) · ฝังภาพลายเซ็นออนไลน์ + รับทราบโดยปริยาย
// payload = { siteCode, ym:'yyyy-MM', subName|subNames[], half, days[], docNos[],
//             showSummarizer, summarizerName, summarizerDept, summarizerFromIssuer,
//             inspectorPos, approverPos, projectName, username, confirmOverlap }
// =========================================================================

/** หัว "ประจำงวด/ประจำเดือน" ของใบ (mirror _docPeriodHead_) */
function _pdfDocPeriodHead(string $ym, string $daysLabel): array {
    $lb = trim($daysLabel);
    $y = (int)substr($ym, 0, 4);
    $m = (int)substr($ym, 5, 2);
    if ($y && $m && mb_strpos($lb, 'งวด', 0, 'UTF-8') === 0) {
        $yy   = str_pad((string)((($y + 543) % 100)), 2, '0', STR_PAD_LEFT);
        $isH2 = strpos($lb, '16') !== false;
        $last = (int)date('t', mktime(0, 0, 0, $m, 1, $y));
        $range = $isH2 ? ('16–' . $last) : '1–15';
        return ['label' => 'ประจำงวด',
                'text'  => 'งวดที่ ' . ($isH2 ? 2 : 1) . ' · ' . $range . ' ' . pdfThaiMonthAbbr($m) . ' ' . $yy];
    }
    $monthLabel = thaiMonthName($m) . ' ' . ($y + 543);
    if ($lb === '' || $lb === 'ทั้งเดือน') { return ['label' => 'ประจำเดือน', 'text' => $monthLabel]; }
    return ['label' => 'ประจำเดือน', 'text' => $monthLabel . ' (วันที่ ' . $lb . ')'];
}

/** ลายเซ็นที่ผูกไว้กับชุดของโครงการ → lower(ชื่อชุด) => data URL (mirror _subSignaturesBySubId_) */
function _pdfSubBoundSigs(PDO $pdo, int $projectId): array {
    $out = [];
    $st = $pdo->prepare(
        'SELECT s.name, s.signature_path FROM subcontractors s
         JOIN sub_projects sp ON sp.sub_id = s.id AND sp.project_id = ? ORDER BY s.id'
    );
    $st->execute([$projectId]);
    foreach ($st->fetchAll() as $r) {
        $nm  = trim((string)$r['name']);
        $rel = trim((string)($r['signature_path'] ?? ''));
        if ($nm === '' || $rel === '') { continue; }
        $k = mb_strtolower($nm, 'UTF-8');
        if (!isset($out[$k])) {
            $d = _sigReadDataUrl($rel);
            if ($d !== '') { $out[$k] = $d; }
        }
    }
    return $out;
}

/** ลายเซ็นที่ตั้งไว้ของผู้ใช้ → data URL ('' = ไม่มี) (mirror _userSignatureOf_) */
function _pdfUserSigByUsername(PDO $pdo, string $username): string {
    $username = trim($username);
    if ($username === '') { return ''; }
    $st = $pdo->prepare('SELECT signature_path FROM users WHERE username = ? LIMIT 1');
    $st->execute([$username]);
    $rel = trim((string)$st->fetchColumn());
    return $rel !== '' ? _sigReadDataUrl($rel) : '';
}

/**
 * รวบรวมข้อมูล + จองเลข + ประกอบตัวแปร template ของตารางหักเงิน
 * คืน ['ok'=>true,'vars'=>...,'fileName'=>...,'nDocs'=>N] หรือ ['ok'=>false,'resp'=>ก้อนตอบ client]
 * (แยกจาก wrapper เพื่อให้โหมด "พิมพ์รวม" ของหักคจช. เรนเดอร์เป็น fragment ต่อท้ายได้ — ล้อ hostSS)
 */
function _pdfBuildDeductionDoc(PDO $pdo, ?array $user, array $payload): array {
    $fail = function ($m) { return ['ok' => false, 'resp' => ['success' => false, 'message' => $m]]; };

    // สิทธิ์: server-authoritative จาก session (mirror userCanDailyCheck_)
    if (!_pdfUserCanDailyCheck($pdo, $user)) { return $fail('no_permission'); }
    $username = trim((string)$user['username']);

    $ym = trim((string)($payload['ym'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) { return $fail('bad_month'); }

    // ชุดที่เลือก: subNames[] หรือ subName — ว่าง = ทุกชุดที่มีหักเงินในเดือน
    $wantList = [];
    if (isset($payload['subNames']) && is_array($payload['subNames'])) {
        foreach ($payload['subNames'] as $s) {
            $s = trim((string)$s);
            if ($s !== '') { $wantList[] = $s; }
        }
    } elseif (!empty($payload['subName'])) {
        $wantList[] = trim((string)$payload['subName']);
    }
    $wantSet = null;
    if ($wantList) {
        $wantSet = [];
        foreach ($wantList as $s) { $wantSet[$s] = true; }
    }

    $wantSite = trim((string)($payload['siteCode'] ?? ''));

    // วันที่ subset (yyyy-MM-dd) — ว่าง = ทั้งเดือน (ใบเก่าแบบรายวัน — หน้าเว็บปัจจุบันส่งเป็นงวด)
    $daySet = null;
    if (isset($payload['days']) && is_array($payload['days']) && count($payload['days'])) {
        $daySet = [];
        foreach ($payload['days'] as $d) {
            $s = trim((string)$d);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) { $daySet[$s] = true; }
        }
        if (!count($daySet)) { $daySet = null; }
    }

    // ── งวดครึ่งเดือน (half = 1 | 2) — เลขที่เอกสารผูกกับงวด ออกซ้ำได้เลขเดิมเสมอ (mirror GAS) ──
    $yNum = (int)substr($ym, 0, 4);
    $mNum = (int)substr($ym, 5, 2);
    $half = (int)($payload['half'] ?? 0);
    $halfLabel = '';
    if ($half === 1 || $half === 2) {
        $halfLabel = _sigHalfLabel($half);
        $daySet    = _sigDaySetFromLabel($ym, $halfLabel);
    }
    $monthLabel = thaiMonthName($mNum) . ' ' . ($yNum + 543);

    // ── โหมดออกเอกสาร: A ปกติ (subNames + half/days) · B รวมตามใบ (docNos — เลขเดิม ไม่จองใหม่) ──
    $docNosMode = isset($payload['docNos']) && is_array($payload['docNos']) && count($payload['docNos']) > 0;

    $resolvedSite = $wantSite;
    $groups   = [];
    $subList  = [];
    $docsToRender = null;   // [{subName, docNo, days, issuedBy, items[]}] — ลิสต์เรนเดอร์จริงทั้งสองโหมด

    if ($docNosMode) {
        // ── โหมด B: โหลด meta ของแต่ละใบจากทะเบียน แล้วกรองรายการตามป้ายงวดของใบนั้น ──
        $bDocs = [];
        $wantSetAll = [];
        foreach ($payload['docNos'] as $dn) {
            $doc = _sigDeductionDoc($pdo, trim((string)$dn));
            if (!$doc) { continue; }
            if (trim((string)$doc['ym']) !== $ym) { continue; }                    // ต้องเป็นเดือนเดียวกัน
            $dSite = trim((string)$doc['project_code']);
            if ($wantSite !== '' && $dSite !== '' && $dSite !== $wantSite) { continue; }
            if ($resolvedSite === '' && $dSite !== '') { $resolvedSite = $dSite; }
            $wantSetAll[trim((string)$doc['sub_name'])] = true;
            $bDocs[] = $doc;
        }
        if (!$bDocs) { return $fail('ไม่พบเอกสารที่เลือกในทะเบียน'); }
        // สแกน log ครั้งเดียวทั้งเดือน (ทุกชุดที่เกี่ยว) แล้วค่อยกรองรายใบ
        $colAll = _sigCollectGroups($pdo, $resolvedSite, $ym, null, $wantSetAll);
        if ($resolvedSite === '') { $resolvedSite = $colAll['resolvedSite']; }
        $docsToRender = [];
        foreach ($bDocs as $doc) {
            $sub   = trim((string)$doc['sub_name']);
            $label = trim((string)$doc['days_label']);
            $dSet  = _sigDaySetFromLabel($ym, $label);
            $items = [];
            foreach (($colAll['groups'][$sub] ?? []) as $it) {
                if ($dSet !== null && !isset($dSet[$it['dayKey']])) { continue; }
                $items[] = $it;
            }
            usort($items, function ($a, $b) { return $a['ms'] <=> $b['ms']; });
            if ($items) {
                $docsToRender[] = ['subName' => $sub, 'docNo' => trim((string)$doc['doc_no']),
                                   'days' => $label, 'issuedBy' => trim((string)($doc['issued_by'] ?? '')),
                                   'items' => $items];
            }
        }
        if (!$docsToRender) { return $fail('ใบที่เลือกไม่มีรายการหักเงินในระบบแล้ว'); }
    } else {
        // ── โหมด A: เก็บรายการหักเงินในเดือน จาก RD + OD จัดกลุ่มตามชุด (Receiver) ──
        $col = _sigCollectGroups($pdo, $wantSite, $ym, $daySet, $wantSet);
        $groups = $col['groups'];
        $resolvedSite = $col['resolvedSite'];

        if ($wantList) {
            $subList = [];
            foreach ($wantList as $s) {
                if (!empty($groups[$s])) { $subList[] = $s; }
            }
        } else {
            $subList = array_keys($groups);
            if (class_exists('Collator')) {
                $colr = new Collator('th_TH');
                usort($subList, function ($a, $b) use ($colr) { return $colr->compare($a, $b); });
            } else {
                sort($subList, SORT_STRING);
            }
        }
        if (!$subList) {
            return $fail('ไม่พบรายการหักเงินของชุดที่เลือก ในเดือน ' . $monthLabel);
        }
        foreach ($subList as $sn) {
            usort($groups[$sn], function ($a, $b) { return $a['ms'] <=> $b['ms']; });
        }
    }

    // ── ชื่อ Site + project id ──
    $siteName = '-';
    $projectId = null;
    if ($resolvedSite !== '') {
        $proj = _pdfProjectByCode($pdo, $resolvedSite);
        if ($proj) {
            $siteName = trim((string)$proj['name']) !== '' ? (string)$proj['name'] : '-';
            $projectId = (int)$proj['id'];
        }
    }
    if ($projectId === null) {
        return $fail('ไม่พบโครงการ ' . ($resolvedSite !== '' ? $resolvedSite : '(ไม่ระบุ)'));
    }

    // ── Mango vendor ต่อชุด (ทะเบียนกลาง — รายโครงการ) ──
    $mangoBySubName = [];
    $mq = $pdo->prepare(
        'SELECT s.name, sp.mango_vendor_code, sp.mango_vendor_name
           FROM subcontractors s
           LEFT JOIN sub_projects sp ON sp.sub_id = s.id AND sp.project_id = ?
          ORDER BY s.id'
    );
    $mq->execute([$projectId]);
    foreach ($mq->fetchAll() as $r) {
        $nm = trim((string)$r['name']);
        if ($nm === '') { continue; }
        $mangoBySubName[mb_strtolower($nm, 'UTF-8')] = [
            'code' => trim((string)($r['mango_vendor_code'] ?? '')),
            'name' => trim((string)($r['mango_vendor_name'] ?? '')),
        ];
    }

    // ── ตัวเลือกลายเซ็น/หัวเอกสาร ──
    $showSummarizer = !(isset($payload['showSummarizer']) && $payload['showSummarizer'] === false);
    $summarizerName = $showSummarizer
        ? (string)((isset($payload['summarizerName']) && trim((string)$payload['summarizerName']) !== '')
            ? $payload['summarizerName'] : ($username !== '' ? $username : '-'))
        : '';
    $summarizerDept = $showSummarizer ? trim((string)($payload['summarizerDept'] ?? '')) : '';
    $inspectorPos = trim((string)($payload['inspectorPos'] ?? '')) !== '' ? (string)$payload['inspectorPos'] : 'PE / SSE';
    $approverPos  = trim((string)($payload['approverPos'] ?? ''))  !== '' ? (string)$payload['approverPos']  : 'PM';
    $projectName  = trim((string)($payload['projectName'] ?? '')) !== ''
                  ? (string)$payload['projectName']
                  : ($resolvedSite !== '' ? $resolvedSite : '-');
    $ymCompact = str_replace('-', '', $ym);
    $now = nowBkk();
    $issueDate = $now->format('d/m/') . ((int)$now->format('Y') + 543);
    $genStamp = 'สร้างเมื่อ ' . $now->format('d/m/Y H:i') . ' น.' . ($username !== '' ? ' โดย ' . $username : '');

    // ── ป้ายวัน = ตัวระบุ "ใบ" (งวด → ป้ายคงที่; รายวัน → เลขวันเรียง; ไม่เลือก → ทั้งเดือน) ──
    if ($halfLabel !== '') {
        $daysLabel = $halfLabel;
        $selDayNums = null;
        if ($daySet !== null) {
            $selDayNums = [];
            foreach (array_keys($daySet) as $k) { $selDayNums[] = (int)substr($k, 8, 2); }
        }
    } elseif ($daySet !== null) {
        $keys = array_keys($daySet);
        sort($keys, SORT_STRING);
        $dayNums = [];
        foreach ($keys as $k) { $dayNums[] = (int)substr($k, 8, 2); }
        $daysLabel = implode(',', $dayNums);
        $selDayNums = $dayNums;
    } else {
        $daysLabel = 'ทั้งเดือน';
        $selDayNums = null;
    }

    // ── เลขที่เอกสาร (โหมด A): idempotent ผ่านทะเบียน deduction_docs (transaction + FOR UPDATE) ──
    $docNoList = [];
    if (!$docNosMode) {
        $createdDocs = [];   // ใบใหม่รอบนี้ → แช่ราคาหลัง commit
        $pdo->beginTransaction();
        try {
            $dd = $pdo->prepare(
                'SELECT doc_no, running_no, sub_name, days_label
                 FROM deduction_docs WHERE project_id = ? AND ym = ? ORDER BY id FOR UPDATE'
            );
            $dd->execute([$projectId, $ym]);
            $existing = [];   // "sub|label" → DocNo (เจอครั้งแรกชนะ)
            $bySub    = [];   // sub → [{label, days, docNo}] (ไว้ตรวจวันซ้อน)
            $maxNo    = 0;
            foreach ($dd->fetchAll() as $r) {
                $n = (int)$r['running_no'];
                if ($n > $maxNo) { $maxNo = $n; }
                $sub   = trim((string)$r['sub_name']);
                $label = trim((string)$r['days_label']);
                $docNo = trim((string)$r['doc_no']);
                $k = $sub . '|' . $label;
                if (!isset($existing[$k])) { $existing[$k] = $docNo; }
                if ($label === 'ทั้งเดือน' || $label === '') {
                    $days = 'all';
                } elseif (mb_strpos($label, 'งวด', 0, 'UTF-8') === 0) {
                    $set = _sigDaySetFromLabel($ym, $label);
                    $days = [];
                    foreach (array_keys((array)$set) as $x) { $days[] = (int)substr($x, 8, 2); }
                } else {
                    $days = [];
                    foreach (explode(',', $label) as $x) {
                        $x = trim($x);
                        if ($x !== '' && is_numeric($x)) { $days[] = (int)$x; }
                    }
                }
                if (!isset($bySub[$sub])) { $bySub[$sub] = []; }
                $bySub[$sub][] = ['label' => $label, 'days' => $days, 'docNo' => $docNo];
            }

            // ── ตรวจ "วันซ้อน" ก่อนจองเลข (ปัจจุบันหน้าเว็บส่ง confirmOverlap=true เสมอ — คงไว้ตาม GAS) ──
            if (empty($payload['confirmOverlap'])) {
                $overlaps = [];
                foreach ($subList as $subName) {
                    if (isset($existing[$subName . '|' . $daysLabel])) { continue; } // ออกซ้ำใบเดิม
                    if (empty($bySub[$subName])) { continue; }
                    foreach ($bySub[$subName] as $ex) {
                        if ($ex['label'] === $daysLabel) { continue; }
                        $inter = '';
                        if ($selDayNums === null || $ex['days'] === 'all') {
                            if ($selDayNums === null) {
                                $inter = ($ex['days'] === 'all') ? 'ทั้งเดือน' : implode(',', $ex['days']);
                            } else {
                                $sorted = $selDayNums;
                                sort($sorted, SORT_NUMERIC);
                                $inter = implode(',', $sorted);
                            }
                        } else {
                            $setEx = [];
                            foreach ($ex['days'] as $d) { $setEx[$d] = true; }
                            $hit = [];
                            foreach ($selDayNums as $d) {
                                if (isset($setEx[$d])) { $hit[] = $d; }
                            }
                            sort($hit, SORT_NUMERIC);
                            $inter = $hit ? implode(',', $hit) : '';
                        }
                        if ($inter !== '') {
                            $overlaps[] = ['subName' => $subName, 'days' => $inter, 'docNo' => $ex['docNo']];
                        }
                    }
                }
                if ($overlaps) {
                    $pdo->rollBack(); // ยังไม่จองเลข/ไม่สร้าง PDF — ให้หน้าเว็บถามยืนยันก่อน
                    return ['ok' => false, 'resp' => ['success' => false, 'overlap' => true, 'overlaps' => $overlaps]];
                }
            }

            $nextNo = $maxNo;
            $ins = $pdo->prepare(
                'INSERT INTO deduction_docs
                   (doc_no, project_id, ym, running_no, sub_id, sub_name, days_label, days_json,
                    item_count, issued_by, issued_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $subIdStmt = $pdo->prepare(
                'SELECT s.id FROM subcontractors s
                   JOIN sub_projects sp ON sp.sub_id = s.id
                  WHERE s.name = ? AND sp.project_id = ? ORDER BY s.id LIMIT 1'
            );
            // days_json เฉพาะป้ายรายวัน (งวด/ทั้งเดือน = NULL ตาม issueDeductionDocs)
            $daysJson = null;
            if ($halfLabel === '' && $selDayNums !== null) {
                $sortedDays = $selDayNums;
                sort($sortedDays, SORT_NUMERIC);
                $daysJson = json_encode($sortedDays);
            }
            foreach ($subList as $subName) {
                $k = $subName . '|' . $daysLabel;
                if (isset($existing[$k])) {
                    $docNoList[] = $existing[$k];              // เคยออกใบนี้แล้ว → เลขเดิม
                } else {
                    $nextNo += 1;
                    $docNo = 'SUB-' . ($resolvedSite !== '' ? $resolvedSite : 'NA') . '-' . $ymCompact . '-'
                           . ($nextNo < 10 ? '0' . $nextNo : (string)$nextNo);
                    $docNoList[] = $docNo;
                    $existing[$k] = $docNo;                     // กันชุดเดียวกันถูกเลือกซ้ำในรอบเดียว
                    $subIdStmt->execute([$subName, $projectId]);
                    $subId = $subIdStmt->fetchColumn();
                    $ins->execute([
                        $docNo, $projectId, $ym, $nextNo,
                        $subId !== false ? (int)$subId : null,
                        $subName, $daysLabel, $daysJson,
                        count($groups[$subName]), $username, $now->format('Y-m-d H:i:s'),
                    ]);
                    $createdDocs[] = ['docNo' => $docNo, 'subName' => $subName, 'items' => $groups[$subName]];
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }

        // แช่ราคาใบใหม่หลังปล่อยล็อกทะเบียน (mirror _freezeDocRates_ — ล็อกครั้งแรกชนะเสมอ)
        if ($createdDocs) {
            _sigFreezeDocRates($pdo, $createdDocs, _sigRateMap($pdo, $projectId), $projectId, $ym, $username);
        }

        $docsToRender = [];
        foreach ($subList as $i => $s) {
            $docsToRender[] = [
                'subName' => $s,
                'docNo'   => $docNoList[$i] ?? ('SUB-' . ($resolvedSite !== '' ? $resolvedSite : 'NA') . '-'
                             . $ymCompact . '-' . (($i + 1) < 10 ? '0' . ($i + 1) : (string)($i + 1))),
                'days'    => $daysLabel,
                'issuedBy' => '',
                'items'   => $groups[$s],
            ];
        }
    } else {
        foreach ($docsToRender as $d) { $docNoList[] = $d['docNo']; }
    }

    // ── ราคา: ที่แช่ไว้กับใบก่อน → Rate Card สดเป็น fallback (mirror _docPriceOf_) ──
    $frozenByDoc = _sigFrozenRates($pdo, $docNoList);
    $rateMap     = _sigRateMap($pdo, $projectId);

    // ── ผู้สรุปเอกสาร = ผู้ออกใบ (โหมด docNos และ summarizerFromIssuer) ──
    $summarizerFromIssuer = $docNosMode || (($payload['summarizerFromIssuer'] ?? false) === true);
    $issuerInfo = [];   // username(lower) → {name, dept, img}
    if ($summarizerFromIssuer) {
        foreach ($docsToRender as &$d) {
            if ($d['issuedBy'] === '') {
                $reg = _sigDeductionDoc($pdo, $d['docNo']);
                $d['issuedBy'] = $reg ? trim((string)($reg['issued_by'] ?? '')) : '';
            }
            if ($d['issuedBy'] === '') { $d['issuedBy'] = $username; }
            $key = mb_strtolower($d['issuedBy'], 'UTF-8');
            if ($d['issuedBy'] !== '' && !isset($issuerInfo[$key])) {
                $uq = $pdo->prepare(
                    'SELECT u.full_name, r.role_code, u.signature_path
                     FROM users u JOIN roles r ON r.id = u.role_id WHERE u.username = ? LIMIT 1'
                );
                $uq->execute([$d['issuedBy']]);
                $u = $uq->fetch();
                $issuerInfo[$key] = [
                    'name' => ($u && trim((string)$u['full_name']) !== '') ? (string)$u['full_name'] : $d['issuedBy'],
                    'dept' => $u ? (string)$u['role_code'] : '',
                    'img'  => ($u && trim((string)($u['signature_path'] ?? '')) !== '')
                              ? _sigReadDataUrl((string)$u['signature_path']) : '',
                ];
            }
        }
        unset($d);
    }
    $summarizerImg = $showSummarizer ? _pdfUserSigByUsername($pdo, $username) : '';
    $boundSigs = _pdfSubBoundSigs($pdo, $projectId);

    // ── ชื่อผู้เบิก = ผู้อนุมัติ (FullName) ──
    $userMap = _pdfUserFullNameMap($pdo);

    // ── สร้างข้อมูลหน้า (1 ชุด = 1 หน้า, 14 คอลัมน์) ──
    $pages = [];
    foreach ($docsToRender as $docEntry) {
        $subName = $docEntry['subName'];
        $docNo   = $docEntry['docNo'];
        $mango = $mangoBySubName[mb_strtolower($subName, 'UTF-8')] ?? ['code' => '', 'name' => ''];
        $docRates = $frozenByDoc[$docNo] ?? [];

        $rows = [];
        $grandTotal = 0.0;
        foreach ($docEntry['items'] as $idx => $it) {
            $nm = (string)$it['name'];
            if (mb_strlen($nm, 'UTF-8') > 160) {
                $nm = mb_substr($nm, 0, 158, 'UTF-8') . '…'; // กันชื่อยาวผิดปกติ (mirror)
            }
            $mc = (string)$it['matCode'];
            $price = null;
            if ($mc !== '') {
                if (array_key_exists($mc, $docRates))    { $price = $docRates[$mc]; }
                elseif (array_key_exists($mc, $rateMap)) { $price = $rateMap[$mc]; }
            }
            $qtyNum = is_numeric($it['qty']) ? (float)$it['qty'] : null;
            $amt = ($price !== null && $qtyNum !== null) ? $price * $qtyNum : null;
            if ($amt !== null) { $grandTotal += $amt; }
            $apRaw = trim((string)($it['approver'] ?? ''));
            $picker = $apRaw !== ''
                ? ((isset($userMap[$apRaw]) && $userMap[$apRaw] !== '') ? $userMap[$apRaw] : $apRaw) : '';
            $dt = new DateTime('@' . (int)($it['ms'] / 1000));
            $dt->setTimezone(new DateTimeZone('Asia/Bangkok'));
            $rows[] = [
                'no'       => $idx + 1,
                'dateTh'   => pdfThaiShortBE2($dt),
                'docId'    => (string)$it['docId'],
                'matCode'  => $mc,
                'subgroup' => (string)$it['subgroup'],
                'name'     => $nm,
                'unit'     => (string)$it['unit'],
                'qty'      => pdfQty($it['qty']),
                'price'    => $price !== null ? pdfMoney($price) : '',
                'amt'      => $amt !== null ? pdfMoney($amt) : '',
                'picker'   => $picker,
                'receiver' => $subName,
            ];
        }

        // ── ลายเซ็น 4 ช่อง (mirror renderSignatures) ──
        $NAME_DOTS = '( .................................................. )';
        $con = _sigFind($pdo, $docNo, 'contractor');
        $ins = _sigFind($pdo, $docNo, 'inspector');
        $apv = _sigFind($pdo, $docNo, 'approver');

        // ผู้สรุปเอกสาร: โหมด issuer → ชื่อ/ตำแหน่ง/ลายเซ็นของผู้ออกใบนั้น; ปกติ → ตาม modal
        if ($summarizerFromIssuer && $docEntry['issuedBy'] !== ''
            && isset($issuerInfo[mb_strtolower($docEntry['issuedBy'], 'UTF-8')])) {
            $inf = $issuerInfo[mb_strtolower($docEntry['issuedBy'], 'UTF-8')];
            $sumBlock = ['role' => 'ผู้สรุปเอกสาร',
                         'name' => $inf['name'] !== '' ? ('( ' . $inf['name'] . ' )') : $NAME_DOTS,
                         'pos'  => $inf['dept'], 'img' => $inf['img']];
        } else {
            $sumBlock = ['role' => 'ผู้สรุปเอกสาร',
                         'name' => $showSummarizer ? ('( ' . $summarizerName . ' )') : $NAME_DOTS,
                         'pos'  => $summarizerDept, 'img' => $summarizerImg];
        }

        $conBlock = ['role' => 'ผู้รับเหมารับทราบ', 'name' => $NAME_DOTS, 'pos' => 'ผู้รับเหมา', 'img' => ''];
        if ($con && $con['status'] === 'signed') {
            $sn = trim((string)($con['signer_name'] ?? ''));
            $conBlock['name'] = '( ' . ($sn !== '' ? $sn : $subName) . ' )';
            $conBlock['img']  = _sigReadDataUrl((string)($con['signature_path'] ?? ''));
        } elseif ($con && $con['status'] === 'auto') {
            // เกินกำหนดโดยไม่ตอบกลับ — ชุดที่ผูกลายเซ็นไว้ ใช้ลายเซ็นนั้นแทน (กำกับว่าปริยาย)
            $dl = !empty($con['signed_at']) ? new DateTime((string)$con['signed_at']) : nowBkk();
            $bound = $boundSigs[mb_strtolower($subName, 'UTF-8')] ?? '';
            if ($bound !== '') {
                $conBlock['name'] = '( ' . $subName . ' )';
                $conBlock['pos']  = 'รับทราบโดยปริยาย · ครบกำหนด ' . pdfThaiShortBE2($dl);
                $conBlock['img']  = $bound;
            } else {
                $conBlock['name'] = '( รับทราบโดยปริยาย )';
                $conBlock['pos']  = 'ครบกำหนด ' . pdfThaiShortBE2($dl);
            }
        }

        $insBlock = ['role' => 'ผู้ตรวจสอบ', 'name' => $NAME_DOTS, 'pos' => 'ตำแหน่ง: ' . $inspectorPos, 'img' => ''];
        if ($ins && $ins['status'] === 'signed') {
            $insBlock['name'] = '( ' . trim((string)$ins['signer_name']) . ' )';
            $insBlock['pos']  = 'ตำแหน่ง: ' . (trim((string)($ins['signer_pos'] ?? '')) !== '' ? (string)$ins['signer_pos'] : $inspectorPos);
            $insBlock['img']  = _sigReadDataUrl((string)($ins['signature_path'] ?? ''));
        }
        $apvBlock = ['role' => 'ผู้อนุมัติ', 'name' => $NAME_DOTS, 'pos' => 'ตำแหน่ง: ' . $approverPos, 'img' => ''];
        if ($apv && $apv['status'] === 'signed') {
            $apvBlock['name'] = '( ' . trim((string)$apv['signer_name']) . ' )';
            $apvBlock['pos']  = 'ตำแหน่ง: ' . (trim((string)($apv['signer_pos'] ?? '')) !== '' ? (string)$apv['signer_pos'] : $approverPos);
            $apvBlock['img']  = _sigReadDataUrl((string)($apv['signature_path'] ?? ''));
        }

        $pages[] = [
            'subName'  => $subName,
            'docNo'    => $docNo,
            'mango'    => $mango,
            'period'   => _pdfDocPeriodHead($ym, (string)$docEntry['days']),
            'rows'     => $rows,
            'totalStr' => $grandTotal > 0 ? pdfMoney($grandTotal) : '',
            'signs'    => [$sumBlock, $conBlock, $insBlock, $apvBlock],
        ];
    }

    // ── ชื่อไฟล์: ระบุงวดเมื่อทุกใบเป็นงวดเดียวกัน (mirror GAS) ──
    $uniqDayLabels = [];
    foreach ($docsToRender as $d) { $uniqDayLabels[(string)$d['days']] = true; }
    $labelKeys = array_keys($uniqDayLabels);
    $fileHalfTxt = '';
    if (count($labelKeys) === 1 && mb_strpos($labelKeys[0], 'งวด', 0, 'UTF-8') === 0) {
        $fileHalfTxt = (strpos($labelKeys[0], '16') !== false) ? '_งวด2(16-สิ้นเดือน)' : '_งวด1(1-15)';
    }
    $fileName = count($docsToRender) === 1
        ? ('ตารางหักเงิน_' . $docsToRender[0]['subName'] . $fileHalfTxt . '_' . $monthLabel . '.pdf')
        : ('ตารางหักเงิน' . $fileHalfTxt . '_' . $monthLabel . '_' . count($docsToRender)
           . ($docNosMode ? 'ใบ' : 'ชุด') . '.pdf');

    return [
        'ok'       => true,
        'vars'     => [
            'pages'        => $pages,
            'projectName'  => $projectName,
            'resolvedSite' => $resolvedSite !== '' ? $resolvedSite : '-',
            'siteName'     => $siteName,
            'issueDate'    => $issueDate,
            'genStamp'     => $genStamp,
        ],
        'fileName' => $fileName,
        'nDocs'    => count($docsToRender),
    ];
}

function rpc_generateDeductionPDF(PDO $pdo, ?array $user, array $args) {
    try {
        $payload = isset($args[0]) && is_array($args[0]) ? $args[0] : [];
        $b = _pdfBuildDeductionDoc($pdo, $user, $payload);
        if (empty($b['ok'])) { return $b['resp']; }
        $html = pdfRenderTemplate('deduction.php', $b['vars']);
        $bin  = renderPdf($html, 'a4', 'landscape');
        return _pdfOk($bin, $b['fileName']);
    } catch (Throwable $err) {
        error_log('generateDeductionPDF error: ' . $err->getMessage());
        return ['success' => false, 'message' => $err->getMessage()];
    }
}
