<?php
/**
 * CONNEXT — lib/stock.php : stock engine (mirror ตรรกะ Balance ของ GAS)
 *
 * โมเดลเดิม (Code.js): OnHand = In − Out (clamp ≥ 0), Pending = ยอด "จอง"
 * [2026-10-02 · GP-42] เลิกปัดเป็น 0 — OnHand = In − Out ติดลบได้ (แสดงสีแดงจนกว่าจะมีคนแก้ด้วยใบนับ SC / รับเข้า)
 *   เดิมยอดที่ตัดเกินค้างใน qty_out อยู่แล้ว (กินยอดรับเข้าครั้งถัดไป) แต่หน้าจอเห็น 0 · ยังจด error_logs ทุกครั้งที่ติดลบ
 * จากเอกสารสถานะอนุมัติแล้ว/รอยืม (PENDING_STATUSES — ใบรออนุมัติไม่นับตั้งแต่ 2026-10-06)
 *
 * ฟังก์ชัน GAS ที่ mirror:
 *   _ensureSiteMaterialsAndBalance_ / _ensureBalanceRows_ → ensureProjectMaterial()
 *   _applyStockMovement_ / _applyBalanceDeltas_           → applyBalanceDelta()
 *   recalcPendingBalance                                  → recalcPending()
 *   _deductStockForDoc_                                   → deductStockForDoc()
 *   _restoreStockForDoc_                                  → restoreStockForDoc()
 *   _isDocStockDeducted_                                  → isDocStockDeducted()
 *   handleGateClosed_ (+ scan-flow finalize ใน saveConfirmationData
 *   และ guard ของ reconcileGateFinalization)              → finalizeGateDoc()
 *
 * [Scenario 05 · 2026-09-28] ทุกการเคลื่อนไหวของยอดตอนปิดงาน/คืน/ยกเลิก ใช้ "จำนวนหยิบจริง"
 *   (document_items.qty_actual — บันทึกตอนถ่ายรูปยืนยัน) ถ้ามี ไม่งั้นจำนวนที่ขอ · ใบยืมคืนตามจำนวนที่ยืมจริง
 *   ยอดจอง (pending) ยังคิดจากจำนวนที่ขอ และปล่อยทั้งก้อนเมื่อใบปิดงาน
 * [Scenario 05 ③ · 2026-09-29] ขาคืนของใบยืมคืนยอด "เท่ากับจำนวนที่คืนจริง" (qty_returned) · ของที่ตีเป็นชำรุด/สูญหาย
 *   ไม่คืนเข้า G · คืนไม่ครบ → ใบคง Sent Return รอสายสโตร์ตี (lib/borrow.php)
 *
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/s05.php';      // s05EffQty()
require_once __DIR__ . '/borrow.php';   // borrowWrittenOffMap()

// =========================================================================
// helpers ภายใน
// =========================================================================

/** เขียน error_logs (mirror logError_ / Logger.log ตอน clamp ของ GAS) */
function _stockErrorLog(PDO $pdo, ?int $projectId, ?string $gateCode, string $message): void {
    try {
        $st = $pdo->prepare('INSERT INTO error_logs (project_id, gate_code, message) VALUES (?, ?, ?)');
        $st->execute([$projectId, $gateCode, $message]);
    } catch (Throwable $e) {
        error_log('_stockErrorLog failed: ' . $e->getMessage() . ' | ' . $message);
    }
}

/** ดึง GateCode จากเลขเอกสาร (mirror _gateFromDocId_: ตัด RT ก่อน แล้วหา G{n} ท้าย) */
function _stockGateCodeFromDocNo(string $docNo): string {
    $clean = preg_replace('/RT$/i', '', trim($docNo));
    if (preg_match('/G(\d+)$/i', $clean, $m)) {
        return 'G' . $m[1];
    }
    return '';
}

/** สถานะเป็น "ใบตาย" (cancelled/rejected/returned) — guard เดิมของ
 *  handleGateClosed_ / reconcileGateFinalization (เทียบแบบ substring, lowercase) */
function _stockIsDeadStatus(string $statusLower): bool {
    return strpos($statusLower, 'cancel') !== false
        || strpos($statusLower, 'reject') !== false
        || strpos($statusLower, 'return') !== false;
}

// =========================================================================
// ยอดคงเหลือรายประตู (stock_gate_balances)
//
// วัสดุตัวเดียวกันอยู่ได้หลายประตูในโครงการเดียวกัน — `stock_balances` เดิม
// ยังเป็น "ยอดรวมทั้งโครงการ" เหมือนเดิมทุกประการ (รายงาน/แดชบอร์ด/PDF อ่าน
// ตารางนั้นอยู่) ส่วนตารางใหม่เก็บรายละเอียดว่าแต่ละประตูมีเท่าไหร่
// ทุกความเคลื่อนไหวผ่าน applyBalanceDelta() จุดเดียว → สองตารางไม่มีทางหลุดกัน
// =========================================================================

/** ประตูแรกของโครงการ (เรียงตาม gate_code) — ใช้เมื่อเอกสารไม่ได้ระบุประตู */
function stockDefaultGateId(PDO $pdo, int $projectId): ?int {
    static $cache = [];
    if ($projectId <= 0) { return null; }
    if (array_key_exists($projectId, $cache)) { return $cache[$projectId]; }
    $st = $pdo->prepare(
        "SELECT id FROM gates WHERE project_id = ? AND status = 'active' ORDER BY gate_code LIMIT 1"
    );
    $st->execute([$projectId]);
    $id = $st->fetchColumn();
    $cache[$projectId] = ($id === false) ? null : (int)$id;
    return $cache[$projectId];
}

/** gate_code → gates.id ภายในโครงการเดียวกัน */
function stockGateIdByCode(PDO $pdo, int $projectId, string $gateCode): ?int {
    $gateCode = trim($gateCode);
    if ($projectId <= 0 || $gateCode === '') { return null; }
    $st = $pdo->prepare('SELECT id FROM gates WHERE gate_code = ? AND project_id = ?');
    $st->execute([$gateCode, $projectId]);
    $id = $st->fetchColumn();
    return ($id === false) ? null : (int)$id;
}

/**
 * ประตูที่ต้องเอาไป +/- ยอดของเอกสารใบนี้ ตามลำดับความน่าเชื่อถือ:
 *   1. documents.gate_id       — ผู้ใช้เลือกตอนกรอกฟอร์ม (เบิก/ยืม/รับเข้าคลัง)
 *   2. เลขเอกสารลงท้าย G{n}    — ใบเก่าที่มีประตูอยู่ในเลขแต่คอลัมน์ยังว่าง
 *   3. gate_logs.gate_id       — ใบ IN จาก rc.php ไม่ผูกประตูตอนออกใบ (มติ 23)
 *                                ประตูจริงรู้ตอนเอา QR ไปสแกน
 *   4. ประตูแรกของโครงการ      — กันยอดหล่นหายเมื่อไม่มีข้อมูลประตูเลย
 */
function stockResolveDocGateId(PDO $pdo, int $docId, int $projectId, string $docNo = ''): ?int {
    if ($docId <= 0 || $projectId <= 0) { return null; }

    $st = $pdo->prepare('SELECT gate_id, doc_no FROM documents WHERE id = ?');
    $st->execute([$docId]);
    $row = $st->fetch();
    if ($row && $row['gate_id'] !== null) { return (int)$row['gate_id']; }
    if ($docNo === '' && $row) { $docNo = (string)$row['doc_no']; }

    $code = _stockGateCodeFromDocNo($docNo);
    if ($code !== '') {
        $gid = stockGateIdByCode($pdo, $projectId, $code);
        if ($gid !== null) { return $gid; }
    }

    $gl = $pdo->prepare(
        'SELECT gate_id FROM gate_logs
          WHERE document_id = ? AND gate_id IS NOT NULL
          ORDER BY (leg = \'out\') DESC, id DESC LIMIT 1'
    );
    $gl->execute([$docId]);
    $gid = $gl->fetchColumn();
    if ($gid !== false && $gid !== null) { return (int)$gid; }

    return stockDefaultGateId($pdo, $projectId);
}

/**
 * ปรับยอดของ "ประตูเดียว" — กติกาเดียวกับ applyBalanceDelta ทุกข้อ
 * (clamp ที่ 0 + log ตอนติดลบ) เรียกจาก applyBalanceDelta เท่านั้น
 */
function applyGateBalanceDelta(PDO $pdo, int $projectId, int $materialId, int $gateId, float $dIn, float $dOut): void {
    if ($projectId <= 0 || $materialId <= 0 || $gateId <= 0) { return; }
    if ($dIn == 0.0 && $dOut == 0.0) { return; }

    $ins = $pdo->prepare(
        'INSERT IGNORE INTO stock_gate_balances (project_id, material_id, gate_id, qty_in, qty_out, on_hand, pending)
         VALUES (?, ?, ?, 0, 0, 0, 0)'
    );
    $ins->execute([$projectId, $materialId, $gateId]);

    $sel = $pdo->prepare(
        'SELECT qty_in, qty_out FROM stock_gate_balances
          WHERE project_id = ? AND material_id = ? AND gate_id = ? FOR UPDATE'
    );
    $sel->execute([$projectId, $materialId, $gateId]);
    $row = $sel->fetch();
    if (!$row) { return; }

    $newIn  = (float)$row['qty_in'] + $dIn;
    $newOut = (float)$row['qty_out'] + $dOut;
    if ($newIn < 0)  { $newIn = 0.0; }
    if ($newOut < 0) { $newOut = 0.0; }

    // [2026-10-02 · GP-42] ติดลบได้ (ไม่ปัดเป็น 0) — จด error_logs เมื่อการเคลื่อนไหวนี้ทำให้ติดลบ/ติดลบเพิ่ม
    $raw = round($newIn - $newOut, 3);
    if ($raw < 0 && ($dOut > 0 || $dIn < 0)) {
        _stockErrorLog($pdo, $projectId, null,
            'applyGateBalanceDelta: material_id=' . $materialId . ' gate_id=' . $gateId
            . ' on_hand went negative (' . $newIn . ' - ' . $newOut . ' = ' . $raw . ') — kept negative (GP-42)');
    }
    $onHand = $raw;

    $upd = $pdo->prepare(
        'UPDATE stock_gate_balances SET qty_in = ?, qty_out = ?, on_hand = ?
          WHERE project_id = ? AND material_id = ? AND gate_id = ?'
    );
    $upd->execute([$newIn, $newOut, $onHand, $projectId, $materialId, $gateId]);
}

// =========================================================================
// ยอด "พร้อมเบิก" รายประตู + ย้ายของข้ามประตู — [PHP port 2026-09-25 per-gate · มติ 51]
//
// กติกา: วัสดุตัวเดียวกันอยู่ได้หลายประตู (เช่น ปูนสกิม G01 10 ถุง · G03 50 ถุง)
// ผู้เบิกต้องเลือกประตูที่จะไปรับ และยอดคงเหลือ/ยอดจอง/การตัดสต๊อก คิดแยกรายประตู
// ทั้งฝั่งฟอร์ม (client) และฝั่ง server (ส่งใบ · อนุมัติ · ประตูสแกน)
// =========================================================================

/**
 * ยอดของ (โครงการ, วัสดุ) ที่ประตูหนึ่ง — ใช้ตรวจ "ของพอไหม" ตอนส่งใบ/อนุมัติ
 *   found = มีแถวของประตูนี้ · any = วัสดุนี้มีแถวรายประตูอยู่บ้าง (ประตูใดก็ได้)
 *   avail = on_hand − pending (ยอดจองของใบที่อนุมัติแล้วยังไม่ได้รับของ — ใบรออนุมัติไม่จอง · 2026-10-06)
 *   any = false → ข้อมูลยุคก่อนแยกรายประตู ผู้เรียกควร fallback ไปเทียบยอดรวม
 * @param bool $lock ล็อกแถว (FOR UPDATE) — เรียกภายในทรานแซกชันเท่านั้น
 */
function stockGateAvail(PDO $pdo, int $projectId, int $materialId, string $gateCode, bool $lock = false): array {
    $out = ['found' => false, 'any' => false, 'on_hand' => 0.0, 'pending' => 0.0, 'avail' => 0.0, 'gate_id' => null];
    $gateCode = strtoupper(trim($gateCode));
    if ($projectId <= 0 || $materialId <= 0) { return $out; }

    $anySt = $pdo->prepare('SELECT COUNT(*) FROM stock_gate_balances WHERE project_id = ? AND material_id = ?');
    $anySt->execute([$projectId, $materialId]);
    $out['any'] = (int)$anySt->fetchColumn() > 0;
    if ($gateCode === '') { return $out; }

    $st = $pdo->prepare(
        'SELECT gb.gate_id, gb.on_hand, gb.pending
           FROM stock_gate_balances gb
           JOIN gates g ON g.id = gb.gate_id
          WHERE gb.project_id = ? AND gb.material_id = ? AND g.gate_code = ?'
        . ($lock ? ' FOR UPDATE' : '')
    );
    $st->execute([$projectId, $materialId, $gateCode]);
    $row = $st->fetch();
    if (!$row) { return $out; }
    $out['found']   = true;
    $out['gate_id'] = (int)$row['gate_id'];
    $out['on_hand'] = (float)$row['on_hand'];
    $out['pending'] = (float)$row['pending'];
    $out['avail']   = (float)$row['on_hand'] - (float)$row['pending'];
    return $out;
}

/** เลขแบบ JS (5.0→'5' · 2.5→'2.5') สำหรับข้อความสต๊อกไม่พอ */
function stockNumStr($v): string {
    $f = (float)$v;
    if (abs($f - round($f)) < 0.0000001) { return (string)(int)round($f); }
    return rtrim(rtrim(number_format($f, 3, '.', ''), '0'), '.');
}

/**
 * ย้ายของ "ข้ามประตู" ในโครงการเดียวกัน — ยอดรวมโครงการไม่เปลี่ยน (ของไม่ได้เข้า/ออกไซต์)
 *   ประตูต้นทาง: qty_out += qty · ประตูปลายทาง: qty_in += qty (on_hand คิดใหม่ทั้งคู่)
 *   ย้ายได้ไม่เกิน on_hand − pending ของประตูต้นทาง (ของที่ถูกจองไว้ให้ใบที่รอรับ ห้ามย้ายหนี)
 *   ล็อกแถว stock_balances ก่อนเสมอ (กติกาเดียวกับ applyBalanceDelta/stockAllocateUnassigned)
 *   จดลง activity_log (entity stock_gate_balances · action gate_transfer)
 * @throws RuntimeException เมื่อข้อมูลไม่ถูกต้อง/ของไม่พอ — ไม่มีอะไรถูกเขียน
 * @return array ['mat_code','unit','qty','from'=>['gate_code','on_hand'],'to'=>['gate_code','on_hand']]
 */
function stockTransferBetweenGates(PDO $pdo, int $projectId, int $materialId, int $fromGateId, int $toGateId,
                                   float $qty, string $by = '', string $note = ''): array {
    $qty = round($qty, 3);
    if ($projectId <= 0 || $materialId <= 0) { throw new RuntimeException('ต้องระบุโครงการและวัสดุ'); }
    if ($qty <= 0)                            { throw new RuntimeException('จำนวนที่ย้ายต้องมากกว่า 0'); }
    if ($fromGateId <= 0 || $toGateId <= 0)   { throw new RuntimeException('ต้องเลือกทั้งประตูต้นทางและปลายทาง'); }
    if ($fromGateId === $toGateId)            { throw new RuntimeException('ประตูต้นทางกับปลายทางเป็นประตูเดียวกัน'); }

    $g = $pdo->prepare("SELECT id, gate_code FROM gates WHERE id = ? AND project_id = ? AND status = 'active'");
    $g->execute([$fromGateId, $projectId]);
    $from = $g->fetch();
    $g->execute([$toGateId, $projectId]);
    $to = $g->fetch();
    if (!$from) { throw new RuntimeException('ประตูต้นทางไม่ได้อยู่ในไซต์นี้ หรือถูกปิดใช้งาน'); }
    if (!$to)   { throw new RuntimeException('ประตูปลายทางไม่ได้อยู่ในไซต์นี้ หรือถูกปิดใช้งาน'); }

    $m = $pdo->prepare('SELECT mat_code, unit FROM materials WHERE id = ?');
    $m->execute([$materialId]);
    $mat = $m->fetch();
    if (!$mat) { throw new RuntimeException('ไม่พบวัสดุ'); }

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) { $pdo->beginTransaction(); }
    try {
        $pdo->prepare('SELECT on_hand FROM stock_balances WHERE project_id = ? AND material_id = ? FOR UPDATE')
            ->execute([$projectId, $materialId]);

        $sel = $pdo->prepare(
            'SELECT qty_in, qty_out, on_hand, pending FROM stock_gate_balances
              WHERE project_id = ? AND material_id = ? AND gate_id = ? FOR UPDATE'
        );
        $sel->execute([$projectId, $materialId, $fromGateId]);
        $src = $sel->fetch();
        $srcOn  = $src ? (float)$src['on_hand'] : 0.0;
        $srcPen = $src ? (float)$src['pending'] : 0.0;
        $avail  = round($srcOn - $srcPen, 3);
        if (!$src || $qty > $avail + 0.0005) {
            throw new RuntimeException(
                'ของที่ประตู ' . (string)$from['gate_code'] . ' ย้ายได้ ' . stockNumStr(max(0, $avail))
                . ' ' . (string)$mat['unit'] . ' (ในคลัง ' . stockNumStr($srcOn)
                . ($srcPen > 0 ? ' · จองไว้ ' . stockNumStr($srcPen) : '') . ') — ย้าย ' . stockNumStr($qty) . ' ไม่ได้'
            );
        }

        $newOut = (float)$src['qty_out'] + $qty;
        $newOn  = round((float)$src['qty_in'] - $newOut, 3);   // [2026-10-02 · GP-42] ไม่ปัดเป็น 0
        $pdo->prepare('UPDATE stock_gate_balances SET qty_out = ?, on_hand = ? WHERE project_id = ? AND material_id = ? AND gate_id = ?')
            ->execute([$newOut, $newOn, $projectId, $materialId, $fromGateId]);

        $pdo->prepare(
            'INSERT IGNORE INTO stock_gate_balances (project_id, material_id, gate_id, qty_in, qty_out, on_hand, pending)
             VALUES (?, ?, ?, 0, 0, 0, 0)'
        )->execute([$projectId, $materialId, $toGateId]);
        $sel->execute([$projectId, $materialId, $toGateId]);
        $dst = $sel->fetch();
        $dstIn = (float)$dst['qty_in'] + $qty;
        $dstOn = round($dstIn - (float)$dst['qty_out'], 3);   // [2026-10-02 · GP-42] ประตูปลายทางที่ติดลบอยู่ = ย้ายเข้าไปลบล้าง
        $pdo->prepare('UPDATE stock_gate_balances SET qty_in = ?, on_hand = ? WHERE project_id = ? AND material_id = ? AND gate_id = ?')
            ->execute([$dstIn, $dstOn, $projectId, $materialId, $toGateId]);

        try {
            $pdo->prepare(
                'INSERT INTO activity_log (entity_type, entity_id, user_name, action, old_value, new_value)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                'stock_gate_balances', (string)$projectId, $by !== '' ? $by : null, 'gate_transfer',
                json_encode(['mat_code' => (string)$mat['mat_code'],
                             'from' => [(string)$from['gate_code'] => $srcOn],
                             'to'   => [(string)$to['gate_code'] => (float)$dst['on_hand']]], JSON_UNESCAPED_UNICODE),
                json_encode(['material_id' => $materialId, 'mat_code' => (string)$mat['mat_code'], 'qty' => $qty,
                             'from' => [(string)$from['gate_code'] => $newOn],
                             'to'   => [(string)$to['gate_code'] => $dstOn],
                             'note' => $note !== '' ? $note : null], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) {
            error_log('stockTransferBetweenGates log failed: ' . $e->getMessage());
        }

        if ($ownTx) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return [
        'mat_code' => (string)$mat['mat_code'],
        'unit'     => (string)$mat['unit'],
        'qty'      => $qty,
        'from'     => ['gate_code' => (string)$from['gate_code'], 'on_hand' => $newOn],
        'to'       => ['gate_code' => (string)$to['gate_code'],   'on_hand' => $dstOn],
    ];
}

// =========================================================================
// ยอดที่ยังไม่ลงประตู — [PHP port 2026-09-23 gate-stock]
//
// GAS ไม่มียอดรายประตู: Balance.csv = ยอดรวมรายไซต์ · ประตูของวัสดุอยู่ที่ SiteMaterials.GateID
// ตอนนำเข้าได้ stock_balances ครบแต่ stock_gate_balances ว่าง → ฟอร์มเบิก/เบ็ดเตล็ด/ยืม (เลือกได้
// เฉพาะประตูที่มีของ) ขึ้น "ไม่มีของในประตูใดเลย" ทั้งที่ยอดรวมมีของ · ส่วนต่าง (ยอดรวม − ผลรวม
// รายประตู) จึงลงที่ประตูตั้งต้นของวัสดุ (project_materials.gate_id = GateID เดิมของ GAS)
// =========================================================================

/**
 * (โครงการ, วัสดุ) ที่ยอดรวมไม่เท่าผลรวมรายประตู
 * unalloc > 0 = ยังไม่ลงประตู · unalloc < 0 = รายประตูเกินยอดรวม (ผิดปกติ — ไม่แตะ ให้คนตรวจ)
 * target_gate_id = ประตูตั้งต้นถ้ายังใช้งานและอยู่ไซต์เดียวกัน ไม่งั้นประตูแรกของไซต์
 * (กติกาเดียวกับ applyBalanceDelta ตอนไม่รู้ประตู) · null = ไซต์ไม่มีประตูที่ใช้งาน
 */
function stockUnallocatedList(PDO $pdo, int $projectId = 0, int $materialId = 0): array {
    $w = ['b.on_hand <> COALESCE(gs.s, 0)'];
    $ar = [];
    if ($projectId > 0)  { $w[] = 'b.project_id = ?';  $ar[] = $projectId; }
    if ($materialId > 0) { $w[] = 'b.material_id = ?'; $ar[] = $materialId; }
    $st = $pdo->prepare(
        "SELECT b.project_id, p.code AS project_code, b.material_id, m.mat_code, m.code_type, m.name, m.unit,
                b.on_hand, COALESCE(gs.s, 0) AS gate_sum, b.on_hand - COALESCE(gs.s, 0) AS unalloc,
                dg.id AS default_gate_id
           FROM stock_balances b
           JOIN projects  p ON p.id = b.project_id
           JOIN materials m ON m.id = b.material_id
           LEFT JOIN (SELECT project_id, material_id, SUM(on_hand) AS s
                        FROM stock_gate_balances GROUP BY project_id, material_id) gs
                  ON gs.project_id = b.project_id AND gs.material_id = b.material_id
           LEFT JOIN project_materials pm ON pm.project_id = b.project_id AND pm.material_id = b.material_id
           LEFT JOIN gates dg ON dg.id = pm.gate_id AND dg.project_id = b.project_id AND dg.status = 'active'
          WHERE " . implode(' AND ', $w) . "
          ORDER BY p.code, m.code_type, m.mat_code"
    );
    $st->execute($ar);
    $rows = $st->fetchAll();

    $codes = [];
    foreach ($pdo->query('SELECT id, gate_code FROM gates') as $g) { $codes[(int)$g['id']] = (string)$g['gate_code']; }
    foreach ($rows as &$r) {
        $gid = $r['default_gate_id'] !== null ? (int)$r['default_gate_id'] : stockDefaultGateId($pdo, (int)$r['project_id']);
        $r['on_hand']           = (float)$r['on_hand'];
        $r['gate_sum']          = (float)$r['gate_sum'];
        $r['unalloc']           = round((float)$r['unalloc'], 3);
        $r['target_gate_id']    = $gid;
        $r['target_gate_code']  = $gid !== null ? ($codes[$gid] ?? '') : '';
        $r['target_is_default'] = $r['default_gate_id'] !== null;
    }
    unset($r);
    return $rows;
}

/**
 * ลงยอดที่ยังไม่อยู่ประตูใด — (โครงการ, วัสดุ) ละ 1 ประตู เป็น qty_in ของประตูนั้น
 * (แบบยอดยกมา เหมือน smMoveSplit) → ผลรวมรายประตู = ยอดรวมพอดี
 * ล็อกแถว stock_balances แล้วคิดส่วนต่างใหม่ก่อนลง — ทุกทางที่ขยับยอดรายประตู (applyBalanceDelta)
 * ล็อกแถวเดียวกันก่อนเสมอ กดซ้อนกันจึงไม่ลงซ้ำ · ไม่แตะ pending (ยอดจองคิดจากเอกสาร ไม่เกี่ยว)
 *
 * @param int      $projectId  0 = ทุกโครงการ
 * @param int      $materialId 0 = ทุกวัสดุ
 * @param int|null $gateId     บังคับประตู (ต้องใช้งานอยู่และอยู่ไซต์เดียวกัน) · null = ประตูตั้งต้น/ประตูแรก
 * @return array ['items' => n, 'qty' => รวม, 'rows' => [[project_code, mat_code, qty, gate_code]],
 *                'skipped' => [[project_code, mat_code, unalloc, เหตุผล]]]
 */
function stockAllocateUnassigned(PDO $pdo, int $projectId = 0, int $materialId = 0, ?int $gateId = null): array {
    $out = ['items' => 0, 'qty' => 0.0, 'rows' => [], 'skipped' => []];
    $list = stockUnallocatedList($pdo, $projectId, $materialId);
    if (!$list) { return $out; }

    $forced = null;
    if ($gateId !== null) {
        $g = $pdo->prepare("SELECT id, gate_code, project_id FROM gates WHERE id = ? AND status = 'active'");
        $g->execute([$gateId]);
        $forced = $g->fetch() ?: null;
    }

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) { $pdo->beginTransaction(); }
    try {
        $lock = $pdo->prepare('SELECT on_hand FROM stock_balances WHERE project_id = ? AND material_id = ? FOR UPDATE');
        $sum  = $pdo->prepare('SELECT COALESCE(SUM(on_hand), 0) FROM stock_gate_balances WHERE project_id = ? AND material_id = ?');
        $put  = $pdo->prepare(
            'INSERT INTO stock_gate_balances (project_id, material_id, gate_id, qty_in, qty_out, on_hand, pending)
             VALUES (?, ?, ?, ?, 0, ?, 0)
             ON DUPLICATE KEY UPDATE qty_in = qty_in + VALUES(qty_in), on_hand = on_hand + VALUES(on_hand)'
        );
        foreach ($list as $r) {
            $P = (int)$r['project_id'];
            $M = (int)$r['material_id'];
            $lock->execute([$P, $M]);
            $on = $lock->fetchColumn();
            if ($on === false) { continue; }
            $sum->execute([$P, $M]);
            $diff = round((float)$on - (float)$sum->fetchColumn(), 3);
            if ($diff == 0.0) { continue; }
            if ($diff < 0) {
                $out['skipped'][] = [$r['project_code'], $r['mat_code'], $diff, 'ยอดรายประตูเกินยอดรวม'];
                continue;
            }
            if ($gateId !== null) {
                if (!$forced || (int)$forced['project_id'] !== $P) {
                    $out['skipped'][] = [$r['project_code'], $r['mat_code'], $diff, 'ประตูที่เลือกไม่ได้อยู่ในไซต์นี้/ปิดใช้งาน'];
                    continue;
                }
                $gid   = (int)$forced['id'];
                $gcode = (string)$forced['gate_code'];
            } else {
                if ($r['target_gate_id'] === null) {
                    $out['skipped'][] = [$r['project_code'], $r['mat_code'], $diff, 'ไซต์นี้ไม่มีประตูที่ใช้งาน'];
                    continue;
                }
                $gid   = (int)$r['target_gate_id'];
                $gcode = (string)$r['target_gate_code'];
            }
            $put->execute([$P, $M, $gid, $diff, $diff]);
            $out['items']++;
            $out['qty'] += $diff;
            $out['rows'][] = [$r['project_code'], $r['mat_code'], $diff, $gcode];
        }
        if ($ownTx) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    $out['qty'] = round($out['qty'], 3);
    return $out;
}

// =========================================================================
// ensureProjectMaterial — mirror _ensureSiteMaterialsAndBalance_ + _ensureBalanceRows_
// =========================================================================

/**
 * ให้มีแถว project_materials + stock_balances ของ (project, matCode) เสมอ
 * คืน material_id หรือ null ถ้าวัสดุไม่มีใน master และไม่ให้สร้างอัตโนมัติ
 *
 * $gateCode ใช้ตั้ง gate_id เฉพาะตอน "สร้างแถวใหม่" (INSERT IGNORE) —
 * แถวเดิมไม่ถูกแก้ (พฤติกรรม GAS: เพิ่มเฉพาะแถวที่ยังไม่มี ไม่ทับของเก่า)
 *
 * ⚠ รับได้เฉพาะรหัส IC (มติ 38) — เหตุผลอยู่ในคอมเมนต์ก้อน "กติกา" ด้านล่าง
 * @throws RuntimeException เมื่อ matCode ไม่ใช่รหัส IC
 */
function ensureProjectMaterial(PDO $pdo, int $projectId, string $matCode, ?string $gateCode = null, bool $autoCreateMaterial = false): ?int {
    $matCode = trim($matCode);
    if ($matCode === '' || $projectId <= 0) {
        return null;
    }

    $sel = $pdo->prepare('SELECT id, code_type FROM materials WHERE mat_code = ?');
    $sel->execute([$matCode]);
    $row = $sel->fetch();

    // ── กติกา: รหัส Mango ห้ามถือยอด (มติ 33/34 · บังคับที่นี่ตามมติ 38) ────
    // หลังล้างข้อมูลเริ่มนับหนึ่ง ของทุกชิ้นต้องอยู่ใต้รหัส IC เท่านั้น เดิมกันไว้
    // ด้วย "ลบแถว project_materials ของ Mango ทิ้ง" ซึ่งกันได้แค่ดรอปดาวน์ฟอร์ม —
    // ฟังก์ชันนี้ยังสร้างแถวคืนให้ทันทีที่มีใบไหนส่ง MatCode เป็นรหัส Mango เข้ามา
    // (ยิง RPC ตรง / สคริปต์ / ข้อมูลเก่าค้างในคิว) แล้วยอดโครงการจะหลุดจากสาย PO เงียบ ๆ
    // โยน exception ทิ้งทั้งใบดีกว่าเขียนครึ่ง ๆ — ล้อมติ 30 "error ชัดเจน ไม่ fallback เงียบ"
    if ($row !== false && (string)$row['code_type'] !== 'ic') {
        throw new RuntimeException(
            'รหัส ' . $matCode . ' เป็นรหัส Mango — เข้าสต๊อกไม่ได้ '
            . 'ของทุกชิ้นต้องผ่านสาย PO → buffer → IcCode ก่อน (มติ 34)'
        );
    }

    $materialId = $row === false ? false : $row['id'];
    if ($materialId === false) {
        if (!$autoCreateMaterial) {
            return null;
        }
        // เดิม: GAS ไม่เคยเพิ่ม MaterialsMain เอง (Balance คีย์ด้วย MatCode ดิบ) แต่
        // schema ใหม่ต้องมีแถว master เพื่อ FK — จึงสร้าง stub ว่างให้
        //
        // ตั้งแต่มติ 38 ทางนี้ปิดตาย: `materials.code_type` มี default เป็น 'mango'
        // stub ที่สร้างจึงเป็นรหัส Mango ที่ถือยอดทันที — ผิดกติกาที่เพิ่งตรวจไปข้างบน
        // และจะเล็ดลอด guard ไปได้เพราะตอนตรวจยังไม่มีแถวให้เจอ
        // (ผู้เรียกจริงทั้งระบบส่ง false อยู่แล้ว — ทางนี้ไม่เคยถูกใช้)
        throw new RuntimeException(
            'รหัส ' . $matCode . ' ไม่มีในทะเบียนวัสดุ — สร้างอัตโนมัติไม่ได้แล้ว '
            . 'ของทุกชิ้นต้องมีรหัส IC ที่ออกจากสาย PO → buffer → IcCode ก่อน (มติ 34/38)'
        );
    }
    $materialId = (int)$materialId;

    // gate_id จาก gate_code ภายในโครงการเดียวกัน (ถ้าระบุ)
    $gateId = null;
    $gateCode = trim((string)$gateCode);
    if ($gateCode !== '') {
        $g = $pdo->prepare('SELECT id FROM gates WHERE gate_code = ? AND project_id = ?');
        $g->execute([$gateCode, $projectId]);
        $gid = $g->fetchColumn();
        if ($gid !== false) {
            $gateId = (int)$gid;
        }
    }

    $pm = $pdo->prepare('INSERT IGNORE INTO project_materials (project_id, material_id, gate_id) VALUES (?, ?, ?)');
    $pm->execute([$projectId, $materialId, $gateId]);

    $bal = $pdo->prepare('INSERT IGNORE INTO stock_balances (project_id, material_id, qty_in, qty_out, on_hand, pending) VALUES (?, ?, 0, 0, 0, 0)');
    $bal->execute([$projectId, $materialId]);

    // แถวยอดรายประตู — สร้างล่วงหน้าเมื่อรู้ประตูปลายทาง (ยอดเริ่มที่ 0)
    if ($gateId !== null) {
        $gb = $pdo->prepare(
            'INSERT IGNORE INTO stock_gate_balances (project_id, material_id, gate_id, qty_in, qty_out, on_hand, pending)
             VALUES (?, ?, ?, 0, 0, 0, 0)'
        );
        $gb->execute([$projectId, $materialId, $gateId]);
    }

    return $materialId;
}

// =========================================================================
// applyBalanceDelta — mirror _applyStockMovement_ / _applyBalanceDeltas_
// =========================================================================

/**
 * ปรับ qty_in/qty_out แล้วคิด on_hand = max(0, in − out) ใหม่
 * (GAS: 'in' → In += qty · 'out' → Out += qty · 'return' → Out = max(0, Out − qty))
 * clamp ติดลบที่ 0 + log ลง error_logs (พฤติกรรม _applyBalanceDeltas_ ตอนติดลบ)
 */
function applyBalanceDelta(PDO $pdo, int $projectId, int $materialId, float $dIn, float $dOut, ?int $gateId = null): void {
    if ($projectId <= 0 || $materialId <= 0) {
        return;
    }
    if ($dIn == 0.0 && $dOut == 0.0) {
        return;
    }

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) { $pdo->beginTransaction(); }
    try {
        // แถว Balance ต้องมีเสมอ — ถ้าหาย/ยังไม่เคยมี สร้างใหม่ (mirror _ensureBalanceRows_
        // ภายใน _applyBalanceDeltas_/_applyStockMovement_ — ยอดต้องไม่หายเงียบ)
        $ins = $pdo->prepare('INSERT IGNORE INTO stock_balances (project_id, material_id, qty_in, qty_out, on_hand, pending) VALUES (?, ?, 0, 0, 0, 0)');
        $ins->execute([$projectId, $materialId]);

        $sel = $pdo->prepare('SELECT qty_in, qty_out FROM stock_balances WHERE project_id = ? AND material_id = ? FOR UPDATE');
        $sel->execute([$projectId, $materialId]);
        $row = $sel->fetch();
        if (!$row) { // ไม่ควรเกิด — แต่กันไว้
            if ($ownTx) { $pdo->commit(); }
            return;
        }

        $newIn  = (float)$row['qty_in'] + $dIn;
        $newOut = (float)$row['qty_out'] + $dOut;
        // mirror 'return' ของ GAS: Out = max(0, Out − qty) — In เช่นกัน (เชิงป้องกัน)
        if ($newIn < 0)  { $newIn = 0.0; }
        if ($newOut < 0) { $newOut = 0.0; }

        // [2026-10-02 · GP-42] ติดลบ = ตัดมากกว่าที่มี — เก็บยอดติดลบตามจริง (เดิมปัดเป็น 0) + log ให้ตรวจย้อนหลังได้
        $raw = round($newIn - $newOut, 3);
        if ($raw < 0 && ($dOut > 0 || $dIn < 0)) {
            _stockErrorLog($pdo, $projectId, null,
                'applyBalanceDelta: material_id=' . $materialId . ' on_hand went negative ('
                . $newIn . ' - ' . $newOut . ' = ' . $raw . ') — kept negative (GP-42)');
        }
        $onHand = $raw;

        $upd = $pdo->prepare('UPDATE stock_balances SET qty_in = ?, qty_out = ?, on_hand = ? WHERE project_id = ? AND material_id = ?');
        $upd->execute([$newIn, $newOut, $onHand, $projectId, $materialId]);

        // ยอดรายประตู — ขยับด้วยจำนวนเดียวกันในทรานแซกชันเดียวกัน
        // ไม่รู้ประตู = ยอดรวมยังถูก แต่รายละเอียดรายประตูจะขาดไป จึงเลือก
        // ประตูแรกของโครงการแทนการทิ้ง (ให้ผลรวมรายประตู = ยอดรวมเสมอ)
        if ($gateId === null) { $gateId = stockDefaultGateId($pdo, $projectId); }
        if ($gateId !== null) {
            applyGateBalanceDelta($pdo, $projectId, $materialId, (int)$gateId, $dIn, $dOut);
        }

        if ($ownTx) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

// =========================================================================
// recalcPending — mirror recalcPendingBalance
// =========================================================================

/**
 * คำนวณ stock_balances.pending ใหม่ทั้งโครงการ
 * Pending = SUM(qty) ของรายการใน RD/OD/BD (ไม่รวม IN — พฤติกรรมเดิม) ที่
 * สถานะเอกสาร (lowercase + trim, เทียบแบบ substring เหมือน status.includes(p))
 * ตรงกับ PENDING_STATUSES: approved / sent borrow (= อนุมัติแล้ว ยังไม่ได้รับของ) · เฉพาะ qty > 0
 * แถวที่ไม่มียอดค้าง → pending = 0 (GAS เขียนทับทั้งคอลัมน์)
 *
 * [2026-10-06] ยอดจอง = เฉพาะใบที่อนุมัติแล้ว (ผู้ใช้สั่ง) — ใบ "รออนุมัติ" ไม่จองของอีกต่อไป
 *   (เดิมตาม GAS นับ awaiting approval / awaiting for approval ด้วย) · ใบรออนุมัติหลายใบจึงขอเกินของที่มีได้
 *   แล้วตัดสินกันตอนอนุมัติ: guard ใน lib/approval.php เทียบ on_hand − pending (ใบอื่นที่อนุมัติแล้ว)
 */
function recalcPending(PDO $pdo, int $projectId): void {
    if ($projectId <= 0) {
        return;
    }
    // [2026-10-06] ไม่นับ 'awaiting approval' / 'awaiting for approval' แล้ว — จองเมื่ออนุมัติเท่านั้น
    $pendingNames = ['approved', 'sent borrow'];

    $st = $pdo->prepare(
        "SELECT di.material_id, di.mat_code, di.qty, d.status,
                d.id AS doc_id, d.gate_id, d.doc_no
           FROM document_items di
           JOIN documents d ON d.id = di.document_id
          WHERE d.project_id = ? AND d.doc_type IN ('RD','OD','BD','TD','TG')"   /* TD = เบิกโอนย้ายข้ามไซต์ (2026-09-29) จองเหมือนใบเบิก · TG = ย้าย Gate (2026-10-02) จองที่ G ต้นทางจนเบิกออก */
    );
    $st->execute([$projectId]);

    $map  = []; // material_id → pending qty (ยอดรวมทั้งโครงการ — เหมือนเดิม)
    $gmap = []; // material_id → gate_id → pending qty (รายประตู)
    $gateOf = []; // doc_id → gate_id (กันยิงซ้ำต่อบรรทัด)
    while ($row = $st->fetch()) {
        $status = mb_strtolower(trim((string)$row['status']), 'UTF-8');
        $hit = false;
        foreach ($pendingNames as $p) {
            if (strpos($status, $p) !== false) { $hit = true; break; }
        }
        if (!$hit) {
            continue;
        }
        $qty = (float)$row['qty'];
        if ($qty <= 0) {
            continue;
        }
        $materialId = $row['material_id'];
        if ($materialId === null) {
            // แถว import เก่าที่ยังไม่แมตช์ master — resolve จาก mat_code
            // (GAS คีย์ pendingMap ด้วย MatCode ดิบ และสร้างแถว Balance ให้เสมอ)
            $materialId = ensureProjectMaterial($pdo, $projectId, (string)$row['mat_code'], null, true);
            if ($materialId === null) {
                continue;
            }
        }
        $materialId = (int)$materialId;
        $map[$materialId] = ($map[$materialId] ?? 0.0) + $qty;

        // ยอดจองรายประตู — ของที่รออนุมัติ/รอไปรับ ถูกกันไว้ที่ประตูของใบนั้น
        $docId = (int)$row['doc_id'];
        if (!array_key_exists($docId, $gateOf)) {
            $gateOf[$docId] = ($row['gate_id'] !== null)
                ? (int)$row['gate_id']
                : stockResolveDocGateId($pdo, $docId, $projectId, (string)$row['doc_no']);
        }
        $gid = $gateOf[$docId];
        if ($gid !== null) {
            if (!isset($gmap[$materialId])) { $gmap[$materialId] = []; }
            $gmap[$materialId][$gid] = ($gmap[$materialId][$gid] ?? 0.0) + $qty;
        }
    }

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) { $pdo->beginTransaction(); }
    try {
        // วัสดุที่มียอด Pending แต่แถว Balance หาย → สร้างก่อน (mirror _ensureBalanceRows_)
        if ($map) {
            $ins = $pdo->prepare('INSERT IGNORE INTO stock_balances (project_id, material_id, qty_in, qty_out, on_hand, pending) VALUES (?, ?, 0, 0, 0, 0)');
            foreach ($map as $mid => $q) {
                $ins->execute([$projectId, (int)$mid]);
            }
        }

        // เขียนทั้งคอลัมน์เหมือน GAS: แถวนอก map = 0
        $zero = $pdo->prepare('UPDATE stock_balances SET pending = 0 WHERE project_id = ? AND pending <> 0');
        $zero->execute([$projectId]);
        if ($map) {
            $upd = $pdo->prepare('UPDATE stock_balances SET pending = ? WHERE project_id = ? AND material_id = ?');
            foreach ($map as $mid => $q) {
                $upd->execute([$q, $projectId, (int)$mid]);
            }
        }

        // ── ยอดจองรายประตู — เขียนทับทั้งคอลัมน์แบบเดียวกับยอดรวม ──
        $gzero = $pdo->prepare('UPDATE stock_gate_balances SET pending = 0 WHERE project_id = ? AND pending <> 0');
        $gzero->execute([$projectId]);
        if ($gmap) {
            $gins = $pdo->prepare(
                'INSERT IGNORE INTO stock_gate_balances (project_id, material_id, gate_id, qty_in, qty_out, on_hand, pending)
                 VALUES (?, ?, ?, 0, 0, 0, 0)'
            );
            $gupd = $pdo->prepare(
                'UPDATE stock_gate_balances SET pending = ? WHERE project_id = ? AND material_id = ? AND gate_id = ?'
            );
            foreach ($gmap as $mid => $byGate) {
                foreach ($byGate as $gid => $q) {
                    $gins->execute([$projectId, (int)$mid, (int)$gid]);
                    $gupd->execute([$q, $projectId, (int)$mid, (int)$gid]);
                }
            }
        }
        if ($ownTx) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

// =========================================================================
// deductStockForDoc / restoreStockForDoc — mirror _deductStockForDoc_ / _restoreStockForDoc_
// =========================================================================

/**
 * ตัดสต๊อกทุกรายการของเอกสาร (idempotent ด้วยธง document_items.stock_deducted)
 * $leg: 'out' (RD/OD/BD เบิก/ยืมออก → Out += qty) หรือ 'in' (IN รับเข้า → In += qty)
 * ข้ามแถวที่ธงติดแล้ว / mat ว่าง / qty ≤ 0 (พฤติกรรม _deductStockForDoc_:
 * แถวที่ข้ามเพราะ mat/qty จะไม่ถูกติดธง)
 */
function deductStockForDoc(PDO $pdo, string $docNo, string $leg = 'out'): void {
    $docNo = trim($docNo);
    if ($docNo === '') {
        return;
    }
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) { $pdo->beginTransaction(); }
    try {
        $d = $pdo->prepare('SELECT id, project_id FROM documents WHERE doc_no = ? FOR UPDATE');
        $d->execute([$docNo]);
        $doc = $d->fetch();
        if (!$doc) {
            if ($ownTx) { $pdo->commit(); }
            return;
        }
        $projectId = (int)$doc['project_id'];
        // ประตูของใบนี้ — ยอดรายประตูต้องขยับที่ประตูเดียวกับที่ของเข้า/ออกจริง
        $gateId = stockResolveDocGateId($pdo, (int)$doc['id'], $projectId, $docNo);

        $it = $pdo->prepare('SELECT id, material_id, mat_code, qty, qty_actual, stock_deducted FROM document_items WHERE document_id = ? FOR UPDATE');
        $it->execute([(int)$doc['id']]);
        $items = $it->fetchAll();

        $flag   = $pdo->prepare('UPDATE document_items SET stock_deducted = 1 WHERE id = ?');
        $setMid = $pdo->prepare('UPDATE document_items SET material_id = ? WHERE id = ?');

        foreach ($items as $item) {
            if (isTrueFlag($item['stock_deducted'])) {
                continue; // ตัดไปแล้ว — กันตัดซ้ำ
            }
            $matCode = trim((string)$item['mat_code']);
            $qty     = s05EffQty($item);   // หยิบจริง ?? ที่ขอ (Scenario 05 ⑦)
            if ($matCode === '' || $qty <= 0) {
                continue;
            }
            $materialId = $item['material_id'];
            if ($materialId === null) {
                $materialId = ensureProjectMaterial($pdo, $projectId, $matCode, null, true);
                if ($materialId === null) {
                    continue;
                }
                $setMid->execute([$materialId, (int)$item['id']]);
            }
            $materialId = (int)$materialId;

            if ($leg === 'in') {
                applyBalanceDelta($pdo, $projectId, $materialId, $qty, 0.0, $gateId);
            } else {
                applyBalanceDelta($pdo, $projectId, $materialId, 0.0, $qty, $gateId);
            }
            $flag->execute([(int)$item['id']]);
        }

        if ($ownTx) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

/**
 * คืนสต๊อกที่เคยตัดไว้ (เส้นทาง cancel/reject) — เฉพาะแถวที่ธง stock_deducted=1
 * จึงไม่มีทางคืนของที่ไม่เคยถูกตัด (พฤติกรรม _restoreStockForDoc_)
 * ทิศทางย้อนตามชนิดเอกสาร: IN → In −= qty · อื่นๆ → Out −= qty
 * (GAS บวก OnHand ตรง ๆ — ในโมเดล In−Out เทียบเท่ากันในทุกกรณีที่เข้าถึงได้จริง)
 */
function restoreStockForDoc(PDO $pdo, string $docNo): void {
    $docNo = trim($docNo);
    if ($docNo === '') {
        return;
    }
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) { $pdo->beginTransaction(); }
    try {
        $d = $pdo->prepare('SELECT id, project_id, doc_type FROM documents WHERE doc_no = ? FOR UPDATE');
        $d->execute([$docNo]);
        $doc = $d->fetch();
        if (!$doc) {
            if ($ownTx) { $pdo->commit(); }
            return;
        }
        $projectId = (int)$doc['project_id'];
        $isInbound = ((string)$doc['doc_type'] === Doc::TYPE_IN);
        // ประตูของใบนี้ — ยอดรายประตูต้องขยับที่ประตูเดียวกับที่ของเข้า/ออกจริง
        $gateId    = stockResolveDocGateId($pdo, (int)$doc['id'], $projectId, $docNo);

        $it = $pdo->prepare('SELECT id, material_id, mat_code, qty, qty_actual, stock_deducted FROM document_items WHERE document_id = ? FOR UPDATE');
        $it->execute([(int)$doc['id']]);
        $items = $it->fetchAll();

        $unflag = $pdo->prepare('UPDATE document_items SET stock_deducted = 0 WHERE id = ?');
        $setMid = $pdo->prepare('UPDATE document_items SET material_id = ? WHERE id = ?');

        foreach ($items as $item) {
            if (!isTrueFlag($item['stock_deducted'])) {
                continue; // ไม่เคยตัด — ไม่คืน
            }
            $matCode = trim((string)$item['mat_code']);
            $qty     = s05EffQty($item);   // หยิบจริง ?? ที่ขอ (Scenario 05 ⑦)
            if ($matCode === '' || $qty <= 0) {
                continue; // mirror GAS: แถวไม่สมบูรณ์คงธงเดิมไว้
            }
            $materialId = $item['material_id'];
            if ($materialId === null) {
                $materialId = ensureProjectMaterial($pdo, $projectId, $matCode, null, true);
                if ($materialId === null) {
                    continue;
                }
                $setMid->execute([$materialId, (int)$item['id']]);
            }
            $materialId = (int)$materialId;

            if ($isInbound) {
                applyBalanceDelta($pdo, $projectId, $materialId, -$qty, 0.0, $gateId);
            } else {
                applyBalanceDelta($pdo, $projectId, $materialId, 0.0, -$qty, $gateId);
            }
            $unflag->execute([(int)$item['id']]);
        }

        if ($ownTx) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

/** มีแถวใดของเอกสารที่ธง stock_deducted=1 หรือไม่ (mirror _isDocStockDeducted_) */
function isDocStockDeducted(PDO $pdo, string $docNo): bool {
    $docNo = trim($docNo);
    if ($docNo === '') {
        return false;
    }
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM document_items di
           JOIN documents d ON d.id = di.document_id
          WHERE d.doc_no = ? AND di.stock_deducted = 1'
    );
    $st->execute([$docNo]);
    return (int)$st->fetchColumn() > 0;
}

// =========================================================================
// finalizeGateDoc — mirror handleGateClosed_ (+ scan-flow finalize + reconcile guards)
// =========================================================================

/**
 * ปิดงานเอกสารเมื่อถึงปลายทางที่ประตู (G01: Closed จาก Pi · scan-flow: Confirmed)
 *
 *   docNo ลงท้าย 'RT' → ขาคืนของ BD: คืนสต๊อก (Out −= จำนวนคืนจริง) เฉพาะรายการที่ธง stock_deducted=1 แล้วล้างธง ·
 *       คืนครบ → status 'Returned' + return_ts · คืนไม่ครบ → คง 'Sent Return' + return_ts (รอตีชำรุด/สูญหาย)
 *   RD/OD → 'Completed', Out += qty · BD → 'Borrowed', Out += qty ·
 *   IN → 'Completed', In += qty — ติดธง stock_deducted=1 ทุกแถวของเอกสาร
 *
 * idempotent: สถานะปลายทางแล้วไม่ตัดซ้ำ (แต่การันตีธง=1 เหมือน GAS) และ
 * ธงรายแถวกันซ้ำเพิ่มอีกชั้น · ข้ามใบ Cancelled/Rejected/Returned (guard ของ
 * handleGateClosed_/reconcileGateFinalization) · ปรับ gate_logs.status ให้ถึง
 * ปลายทางของประตูนั้นถ้า caller ยังไม่ได้ตั้ง · จบด้วย recalcPending
 */
function finalizeGateDoc(PDO $pdo, string $gateLogDocNo): void {
    $gateLogDocNo = trim($gateLogDocNo);
    if ($gateLogDocNo === '') {
        return;
    }
    // [2026-10-02 · ย้าย Gate TG] ขาเบิกออก (TG…Gxx) / ขานำเข้า (TG…GxxGyy) ปิดงานคนละแบบ — lib/gatemove.php
    if (strncasecmp($gateLogDocNo, 'TG', 2) === 0) {
        require_once __DIR__ . '/gatemove.php';
        if (gmIsMoveNo($gateLogDocNo)) { gmFinalizeGateDoc($pdo, $gateLogDocNo); return; }
    }
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) { $pdo->beginTransaction(); }
    try {
        // RT (ขาคืน) — GAS ใช้ /RT$/ (ตัวใหญ่เท่านั้น) ใน handleGateClosed_
        $isReturnLeg = str_ends_with($gateLogDocNo, 'RT');
        $lookupNo    = $isReturnLeg ? substr($gateLogDocNo, 0, -2) : $gateLogDocNo;

        $d = $pdo->prepare('SELECT id, doc_no, doc_type, project_id, status FROM documents WHERE doc_no = ? FOR UPDATE');
        $d->execute([$lookupNo]);
        $doc = $d->fetch();
        if (!$doc) {
            // mirror GAS: 'no rows matched DocID' — เงียบ
            if ($ownTx) { $pdo->commit(); }
            return;
        }

        $docId     = (int)$doc['id'];
        $docType   = (string)$doc['doc_type'];
        $projectId = (int)$doc['project_id'];
        $statusRaw = trim((string)$doc['status']);
        $statusL   = mb_strtolower($statusRaw, 'UTF-8');
        // ประตูของใบนี้ — ยอดรายประตูต้องขยับที่ประตูเดียวกับที่ของเข้า/ออกจริง
        $gateId    = stockResolveDocGateId($pdo, $docId, $projectId, (string)$doc['doc_no']);

        // ── 1) ขาคืน (RT) — เฉพาะเอกสาร BD (GAS ค้นเฉพาะ Borrow_Return) ──
        if ($isReturnLeg) {
            if ($docType !== Doc::TYPE_BD) {
                if ($ownTx) { $pdo->commit(); }
                return;
            }
            // ใบยกเลิก/ปฏิเสธ ห้าม finalize (guard ของ reconcileGateFinalization)
            if (strpos($statusL, 'cancel') !== false || strpos($statusL, 'reject') !== false) {
                if ($ownTx) { $pdo->commit(); }
                return;
            }
            // คืนแล้ว → idempotent: ไม่คืนซ้ำ (GAS ข้ามแถวที่มี 'returned')
            $alreadyReturned = strpos($statusL, 'returned') !== false;
            if (!$alreadyReturned) {
                // [2026-10-08] ทยอยคืนได้หลายรอบ (ผู้ใช้สั่ง) — แทนการคืนครั้งเดียวต่อใบ (Scenario 05 ③ ⑦ · 2026-09-29)
                //   หน้าถ่ายรูปยืนยันคืนจด "คืนรอบนี้" (qty_return_round · ≤ ยอดค้าง) → ปิดประตูแล้วคืนยอดเข้า G เดิมเท่านี้
                //   สะสมลง qty_returned (คืนรวม) + ล้าง qty_return_round ในทรานแซกชันเดียว (closeGate / reconcile ซ้ำ = ไม่คืนซ้ำ)
                //   ไม่เหลือค้าง → Returned + เวลาคืน · ยังค้าง → ใบกลับเป็น Borrowed (ทยอยคืนรอบถัดไป หรือสายสโตร์ตีชำรุด/สูญหาย)
                //   ไม่มีรายการไหนจดคืนรอบนี้ (รอบที่ปิดไปแล้ว / ไม่ได้ถ่ายรูปยืนยัน) = ไม่ทำอะไร
                $bis = [];
                foreach (borrowDocItems($pdo, $docId, true) as $bi) { $bis[$bi['id']] = $bi; }
                $it = $pdo->prepare('SELECT id, material_id, mat_code, qty_return_round FROM document_items WHERE document_id = ? FOR UPDATE');
                $it->execute([$docId]);
                $items = $it->fetchAll();
                $addRet  = $pdo->prepare('UPDATE document_items SET qty_returned = COALESCE(qty_returned, 0) + ?, qty_return_round = NULL WHERE id = ?');
                $applied = false;
                foreach ($items as $item) {
                    if ($item['qty_return_round'] === null || $item['qty_return_round'] === '') { continue; }
                    $applied = true;
                    $iid = (int)$item['id'];
                    $out = isset($bis[$iid]) ? (float)$bis[$iid]['outstanding'] : 0.0;   // ยอดค้างก่อนรอบนี้
                    $q   = min(max(0.0, (float)$item['qty_return_round']), $out);
                    if ($q > 0.0005) {
                        $matCode    = trim((string)$item['mat_code']);
                        $materialId = $item['material_id'];
                        if ($materialId === null && $matCode !== '') {
                            $materialId = ensureProjectMaterial($pdo, $projectId, $matCode, null, true);
                        }
                        if ($materialId !== null) {
                            // 'return' movement: Out −= qty (OnHand เพิ่มกลับ) ที่ G เดิมของใบ
                            applyBalanceDelta($pdo, $projectId, (int)$materialId, 0.0, -$q, $gateId);
                        }
                    }
                    $addRet->execute([round($q, 3), $iid]);
                }
                if ($applied) {
                    $remaining = borrowRemainingTotal(borrowDocItems($pdo, $docId));
                    if ($remaining <= 0.0005) {
                        $pdo->prepare('UPDATE documents SET status = ?, return_ts = NOW(), overdue_flag = 0 WHERE id = ?')
                            ->execute([Doc::ST_RETURNED, $docId]);
                        // คืนครบแล้ว = ไม่มีของอยู่นอกสต๊อก → ล้างธงทุกแถว (เหมือนเดิม)
                        $pdo->prepare('UPDATE document_items SET stock_deducted = 0 WHERE document_id = ?')->execute([$docId]);
                    } else {
                        // คืนบางส่วน — ใบกลับเป็นยืมอยู่ (ยังนับเกินกำหนดตามกำหนดคืนเดิม) · return_ts = เวลาคืนรอบล่าสุด
                        $pdo->prepare('UPDATE documents SET status = ?, return_ts = NOW() WHERE id = ?')
                            ->execute([Doc::ST_BORROWED, $docId]);
                    }
                }
            }
            _stockFinalizeGateLogStatus($pdo, $gateLogDocNo, $projectId);
            recalcPending($pdo, $projectId);
            if ($ownTx) { $pdo->commit(); }
            return;
        }

        // ── 1b) ใบนับสต๊อก (SC · 2026-09-29) — ปิดงานโดยไม่ตัด/ไม่เพิ่มสต๊อก ──
        //   ผลต่างระหว่างที่นับได้กับยอดในระบบ รอ ADM / R8+ อนุมัติปรับยอดที่หน้าตรวจสอบประจำวัน (lib/stockcount.php)
        if ($docType === 'SC') {
            if (!_stockIsDeadStatus($statusL) && !eqUser($statusRaw, Doc::ST_COMPLETED)) {
                $pdo->prepare('UPDATE documents SET status = ? WHERE id = ?')->execute([Doc::ST_COMPLETED, $docId]);
            }
            _stockFinalizeGateLogStatus($pdo, $gateLogDocNo, $projectId);
            if ($ownTx) { $pdo->commit(); }
            return;
        }

        // ── 2) ขาปกติ — สถานะปลายทาง + ทิศทางสต๊อกตามชนิดเอกสาร ──
        //   RD/OD/TD → Completed, Out += qty · BD → Borrowed, Out += qty · IN → Completed, In += qty
        $closedStatus = ($docType === Doc::TYPE_BD) ? Doc::ST_BORROWED : Doc::ST_COMPLETED;
        $isInbound    = ($docType === Doc::TYPE_IN);

        // ใบตาย (cancelled/rejected/returned เช่น Sent Return) — ห้าม finalize/ตัดสต๊อก
        if (_stockIsDeadStatus($statusL)) {
            if ($ownTx) { $pdo->commit(); }
            return;
        }

        if (eqUser($statusRaw, $closedStatus)) {
            // ปิดงานไปแล้ว — ไม่ตัดซ้ำ แต่การันตีธง stock_deducted=1 (mirror GAS)
            $pdo->prepare('UPDATE document_items SET stock_deducted = 1 WHERE document_id = ? AND stock_deducted = 0')->execute([$docId]);
        } else {
            $up = $pdo->prepare('UPDATE documents SET status = ? WHERE id = ?');
            $up->execute([$closedStatus, $docId]);

            $it = $pdo->prepare('SELECT id, material_id, mat_code, qty, qty_actual, stock_deducted FROM document_items WHERE document_id = ? FOR UPDATE');
            $it->execute([$docId]);
            $items  = $it->fetchAll();
            $setMid = $pdo->prepare('UPDATE document_items SET material_id = ? WHERE id = ?');

            foreach ($items as $item) {
                $wasDeducted = isTrueFlag($item['stock_deducted']);
                $matCode = trim((string)$item['mat_code']);
                $qty     = s05EffQty($item);   // หยิบจริง ?? ที่ขอ (Scenario 05 ⑦)
                if (!$wasDeducted && $matCode !== '' && $qty > 0) {
                    $materialId = $item['material_id'];
                    if ($materialId === null) {
                        $materialId = ensureProjectMaterial($pdo, $projectId, $matCode, null, true);
                        if ($materialId !== null) {
                            $setMid->execute([(int)$materialId, (int)$item['id']]);
                        }
                    }
                    if ($materialId !== null) {
                        if ($isInbound) {
                            applyBalanceDelta($pdo, $projectId, (int)$materialId, $qty, 0.0, $gateId);
                            // [2026-10-06] ใบ IN ไม่มี G ในใบ → วัสดุที่เพิ่งเข้าไซต์ยังไม่มีประตูตั้งต้น: ใช้ G ที่รับเข้าจริง
                            if ($gateId !== null) {
                                $pdo->prepare('UPDATE project_materials SET gate_id = ? WHERE project_id = ? AND material_id = ? AND gate_id IS NULL')
                                    ->execute([$gateId, $projectId, (int)$materialId]);
                            }
                        } else {
                            applyBalanceDelta($pdo, $projectId, (int)$materialId, 0.0, $qty, $gateId);
                        }
                    }
                }
            }
            // ติดธงทุกแถวของเอกสาร (GAS ตั้ง TRUE ทุกแถวที่แตะ ไม่ว่าจะมี delta หรือไม่)
            $pdo->prepare('UPDATE document_items SET stock_deducted = 1 WHERE document_id = ? AND stock_deducted = 0')->execute([$docId]);
        }

        _stockFinalizeGateLogStatus($pdo, $gateLogDocNo, $projectId);
        recalcPending($pdo, $projectId);
        if ($ownTx) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

/**
 * ตั้ง gate_logs.status ให้ถึงปลายทางของประตูนั้น ถ้า caller ยังไม่ได้ตั้ง
 * (GAS ไม่แตะ GateLogs ใน handleGateClosed_ — doPost/saveConfirmationData ตั้งก่อน
 *  เรียก; PHP รับหน้าที่นี้ตามสัญญา finalizeGateDoc "set status if caller hasn't")
 *   ประตู hardware_close (เดิม G01) → 'Closed' · ประตู scan-flow → 'Confirmed'
 *   ไม่ทราบประตู → flow เดิม/Closed (mirror gateUsesScanFlow_('') === false)
 *   แถวสถานะ 'Cancelled' ไม่แตะ (ประตูเลิกฟังใบนี้แล้ว)
 */
function _stockFinalizeGateLogStatus(PDO $pdo, string $gateLogDocNo, int $projectId): void {
    $sel = $pdo->prepare(
        'SELECT gl.id, gl.status, gl.gate_id, g.hardware_close
           FROM gate_logs gl
           LEFT JOIN gates g ON g.id = gl.gate_id
          WHERE gl.doc_no = ?'
    );
    $sel->execute([$gateLogDocNo]);
    $row = $sel->fetch();
    if (!$row) {
        return;
    }
    $cur = trim((string)$row['status']);
    if (eqUser($cur, Gate::ST_CANCELLED)) {
        return;
    }

    $hardware = null;
    if ($row['gate_id'] !== null && $row['hardware_close'] !== null) {
        $hardware = isTrueFlag($row['hardware_close']);
    } else {
        // fallback: อ่าน gate code จากเลขเอกสาร (mirror _gateFromDocId_)
        $gateCode = _stockGateCodeFromDocNo($gateLogDocNo);
        if ($gateCode !== '') {
            $g = $pdo->prepare('SELECT hardware_close FROM gates WHERE gate_code = ? AND project_id = ?');
            $g->execute([$gateCode, $projectId]);
            $hw = $g->fetchColumn();
            if ($hw !== false) {
                $hardware = isTrueFlag($hw);
            }
        }
    }
    if ($hardware === null) {
        $hardware = true; // ไม่ทราบประตู → flow เดิม (ปลอดภัยสุด — พฤติกรรม gateUsesScanFlow_)
    }

    $terminal = $hardware ? Gate::ST_CLOSED : Gate::ST_CONFIRMED;
    if ($cur !== $terminal) {
        $pdo->prepare('UPDATE gate_logs SET status = ? WHERE id = ?')->execute([$terminal, (int)$row['id']]);
    }
}
