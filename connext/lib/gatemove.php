<?php
/**
 * CONNEXT — lib/gatemove.php : TG ใบย้าย Gate (ย้ายของระหว่าง G ภายในไซต์) [2026-10-02] — ไม่มีใน GAS
 *
 *   อยู่ในฟังก์ชัน "โอนย้าย" คู่กับ TD (โอนย้ายข้ามไซต์) — หน้าเบิก-จ่าย → แท็บโอนย้าย → เลือก "ภายใน site (ย้าย Gate)"
 *   กติกา (ผู้ใช้เลือก 2026-10-02):
 *     · ออกใบได้เฉพาะสายสโตร์ (roles.can_req · ระดับ ≥ 1 = AST/ST1/ST2/SST — ADM ไม่นับ) และบัตรที่แตะเปิดตู้ทั้ง 2 ขา
 *       ต้องเป็นบัตรสายสโตร์ของไซต์ · ถ่ายรูปยืนยันทั้ง 2 ขาโดยสายสโตร์
 *     · อนุมัติตามหมวดของ IC — เกณฑ์เดียวกับใบยืม: ในตะกร้ามี C01 → R6 ขึ้นไป · มีแต่ C02 → R4 ขึ้นไป ·
 *       NAR ล้วน (ลักษณะหรือหมวด NAR) → อนุมัติทันที · ทั้งตะกร้าใช้เกณฑ์สูงสุด (กติกา ⑥)
 *     · QR 2 ขา: ขาเบิกออก = เลขใบ (TG + DDMMYY + เลขรัน + G ต้นทาง) สแกนที่ G ต้นทาง ·
 *       ขานำเข้า = เลขใบ + G ปลายทาง (เช่น TG02102601G01G03) สแกนที่ G ปลายทาง — ลงท้ายด้วย G ปลายทาง
 *       ตู้จึงตรวจประตูได้ด้วยกติกาเดิม (ไม่ต้องแก้โปรแกรมตู้)
 *     · สต๊อก: ปิดขาเบิกออก = ตัด G ต้นทางตามหยิบจริง (ของ "ระหว่างย้าย" ไม่อยู่ใน G ใด ยอดรวมไซต์ลดชั่วคราว) →
 *       ปิดขานำเข้า = เพิ่ม G ปลายทาง (ยอดรวมไซต์กลับเท่าเดิม — ฝั่งยอดรวมขยับแบบ "คืน" Out −= qty
 *       เหมือนขาคืนของใบยืม ยอดสะสม qty_in/qty_out ของไซต์จึงไม่บวมจากการย้ายภายใน)
 *     · นำเข้าต้องเท่ากับที่เบิกออก (หยิบจริงของขาเบิกออก) — แก้จำนวนตอนนำเข้าไม่ได้
 *     · ใบรออนุมัติ/อนุมัติแล้ว = จองยอดที่ G ต้นทาง (recalcPending) · ยกเลิกได้จนกว่าจะแตะบัตรขาเบิกออก
 *     · หยิบจริงขาเบิกออก 0 ทุกรายการ = ไม่มีของย้าย → ใบจบ (Completed) ไม่มีขานำเข้า
 *   สถานะใบ: Awaiting approval → Approved → (ปิดขาเบิกออก) In Transit → (ปิดขานำเข้า) Completed
 *   document_items: qty = ที่ขอ · qty_actual = หยิบจริงขาเบิกออก · qty_returned = นำเข้าแล้ว ·
 *     stock_deducted = 1 ระหว่างย้าย (ตัดต้นทางแล้ว ยังไม่เข้าปลายทาง) แล้วล้างเป็น 0 เมื่อนำเข้า (ล้อขาคืนใบยืม) ·
 *     photo_url = รูปขาเบิกออก · photo_return_url = รูปขานำเข้า
 *   gate_logs: ขาเบิกออก leg 'out' (เลขใบ · G ต้นทาง) · ขานำเข้า leg 'in' (เลขใบ+G ปลายทาง · G ปลายทาง) สร้างเมื่อปิดขาเบิกออก
 *
 * RPC (lib/registry.php): getGateMoveFormData · processGateMoveSubmission · getGateMoveList
 * hook จากไฟล์อื่น: finalizeGateDoc (lib/stock.php) · gateAcceptDocs/gateDocIsLive/reconcile (api/gate.php) ·
 *   หน้า QR / ถ่ายรูปยืนยัน (lib/gate_api.php) · อนุมัติ (lib/approval.php) · Bypass ประตู (lib/bypass.php) · PDF / ประวัติ
 *
 * สคีมา (สร้างเองครั้งแรก — gmEnsureSchema เรียกจาก config.php · เครื่องหมาย settings/.gatemove-schema-v1-<db>):
 *   documents.doc_type ENUM + 'TG' · documents.dest_gate_id · gate_logs.leg ENUM + 'in'
 *
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/s05.php';
require_once __DIR__ . '/docnum.php';
require_once __DIR__ . '/documents.php';   // _docsMaterialsMap · _docsIcOnlyError · _docsGateStockGuard · _docsInsert*
require_once __DIR__ . '/doc_ext.php';     // docExtUserRole · docExtProjectById

const DOC_TYPE_TG      = 'TG';
const GM_ST_IN_TRANSIT = 'In Transit';
const GM_MAX_LINES     = 60;

// =========================================================================
// สคีมา
// =========================================================================

/** แปลง ENUM ของคอลัมน์ → เติมค่าที่ยังไม่มี (คงค่าเดิมทุกตัว) */
function _gmEnumAdd(PDO $pdo, string $table, string $column, array $want, string $tail): void {
    $col = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'")->fetch();
    $def = $col ? (string)$col['Type'] : '';
    if (!preg_match("/^enum\((.*)\)$/i", $def, $m)) { return; }
    preg_match_all("/'((?:[^']|'')*)'/", $m[1], $vv);
    $vals = $vv[1];
    $changed = false;
    foreach ($want as $w) {
        if (!in_array($w, $vals, true)) { $vals[] = $w; $changed = true; }
    }
    if (!$changed) { return; }
    $list = implode(',', array_map(function ($v) { return "'" . str_replace("'", "''", $v) . "'"; }, $vals));
    $pdo->exec("ALTER TABLE `$table` MODIFY `$column` ENUM($list) $tail");
}

/**
 * documents.doc_type + 'TG' · documents.dest_gate_id · gate_logs.leg + 'in'
 * ทำงานจริงครั้งเดียวต่อฐานข้อมูล · DDL อยู่นอกทรานแซกชัน (config.php เรียกก่อนงานอื่น)
 */
function gmEnsureSchema(PDO $pdo, string $dbName = ''): void {
    static $done = [];
    $key = $dbName !== '' ? $dbName : '_';
    if (isset($done[$key])) { return; }
    $done[$key] = true;

    $safe   = preg_replace('/[^A-Za-z0-9_]/', '_', $key);
    $marker = __DIR__ . '/../settings/.gatemove-schema-v1-' . $safe;
    if (is_file($marker)) { return; }
    if ($pdo->inTransaction()) { return; }

    try {
        _gmEnumAdd($pdo, 'documents', 'doc_type', [DOC_TYPE_TG], 'NOT NULL');
        _gmEnumAdd($pdo, 'gate_logs', 'leg', ['in'], "NOT NULL DEFAULT 'out'");
        $cols = [];
        foreach ($pdo->query('SHOW COLUMNS FROM documents')->fetchAll() as $c) { $cols[(string)$c['Field']] = true; }
        if (!isset($cols['dest_gate_id'])) {
            $pdo->exec("ALTER TABLE documents ADD COLUMN dest_gate_id INT NULL DEFAULT NULL
                        COMMENT 'ประตูปลายทางของใบย้าย Gate (TG)' AFTER gate_id, ADD KEY idx_doc_dest_gate (dest_gate_id)");
        }
        @file_put_contents($marker, date('c') . "\n");
    } catch (Throwable $e) {
        error_log('gmEnsureSchema: ' . $e->getMessage());
    }
}

// =========================================================================
// เลขเอกสาร 2 ขา
// =========================================================================

/** เลขขานำเข้า = เลขใบ + G ปลายทาง (TG02102601G01 + G03 → TG02102601G01G03) */
function gmInLegNo(string $docNo, string $destGate): string {
    return strtoupper(trim($docNo)) . strtoupper(trim($destGate));
}

/**
 * เลขที่สแกน/แถว gate_logs เป็นของใบย้าย Gate ไหม
 * @return array|null ['base' => เลขใบ, 'leg' => 'out'|'in', 'dest' => G ปลายทาง (ขานำเข้า) | '']
 */
function gmParseNo(string $no): ?array {
    $no = strtoupper(trim($no));
    if (preg_match('/^(TG\d{7,}G\d+)(G\d+)$/', $no, $m)) {
        return ['base' => $m[1], 'leg' => 'in', 'dest' => $m[2]];
    }
    if (preg_match('/^TG\d{7,}G\d+$/', $no)) {
        return ['base' => $no, 'leg' => 'out', 'dest' => ''];
    }
    return null;
}

/** เป็นเลขของใบย้าย Gate (ขาใดก็ได้) — ใช้คัดกรองเร็วก่อนเรียกฟังก์ชันอื่นในไฟล์นี้ */
function gmIsMoveNo(string $no): bool {
    return gmParseNo($no) !== null;
}

// =========================================================================
// หมวดวัสดุ → เกณฑ์อนุมัติ (เกณฑ์เดียวกับใบยืม)
// =========================================================================

/** NAR = ลักษณะ (char_id) หรือหมวด (cat_id) NAR (นิยามเดียวกับ _docsNarRuleError) · C01 · อื่น = C02 */
function gmItemClass(string $char, string $cat): string {
    $c = strtoupper(trim($char));
    $k = strtoupper(trim($cat));
    if ($c === 'NAR' || $k === 'NAR') { return 'NAR'; }
    return $k === 'C01' ? 'C01' : 'C02';
}

/** ระดับผู้อนุมัติที่ต้องการของทั้งตะกร้า: มี C01 → 6 · มี C02 → 4 · NAR ล้วน → 0 (อนุมัติทันที) */
function gmRequiredLevel(array $classes): int {
    $lvl = 0;
    foreach ($classes as $c) {
        if ($c === 'C01') { return 6; }
        if ($c === 'C02') { $lvl = 4; }
    }
    return $lvl;
}

/** ระดับผู้อนุมัติที่ต้องการจากรายการจริงของใบ (อ่านหมวดจาก master ณ ตอนตรวจ) */
function gmDocRequiredLevel(PDO $pdo, int $documentId): int {
    $st = $pdo->prepare(
        'SELECT m.char_id, m.cat_id
           FROM document_items di
           LEFT JOIN materials m ON m.id = di.material_id OR (di.material_id IS NULL AND m.mat_code = di.mat_code)
          WHERE di.document_id = ?'
    );
    $st->execute([$documentId]);
    $classes = [];
    foreach ($st->fetchAll() as $r) {
        $classes[] = gmItemClass((string)($r['char_id'] ?? ''), (string)($r['cat_id'] ?? ''));
    }
    return gmRequiredLevel($classes);
}

/** ข้อความเกณฑ์อนุมัติ */
function gmRequiredText(int $lvl): string {
    if ($lvl >= 6) { return 'ต้องผู้อนุมัติ R6 ขึ้นไป (มีวัสดุ C01)'; }
    if ($lvl >= 4) { return 'ต้องผู้อนุมัติ R4 ขึ้นไป (วัสดุ C02)'; }
    return 'NAR ล้วน — อนุมัติทันที';
}

// =========================================================================
// สายสโตร์ · บัตร · ผู้อนุมัติ
// =========================================================================

/** ผู้ใช้ใน session เป็นสายสโตร์ (บัญชีพนักงาน · roles.can_req · ระดับ ≥ 1 — นิยามเดียวกับบัตรสายสโตร์ของตู้) */
function gmIsStoreUser(PDO $pdo, ?array $user): bool {
    if (!$user || ($user['accountType'] ?? '') !== 'user') { return false; }
    if ((int)($user['projectId'] ?? 0) <= 0 || trim((string)($user['username'] ?? '')) === '') { return false; }
    $r = docExtUserRole($pdo, $user);
    return $r['canReq'] && $r['level'] >= 1 && $r['level'] < 99;
}

/**
 * บัตรที่แตะเป็นบัตรสายสโตร์ของไซต์นี้ไหม (ล้อ getCardList: ผู้ใช้ไม่ inactive · roles.can_req · ระดับ ≥ 1)
 * @return array ['ok' => bool, 'name' => ชื่อเจ้าของบัตร | '']
 */
function gmStoreCard(PDO $pdo, string $cardId, int $projectId): array {
    $cardId = trim($cardId);
    if ($cardId === '') { return ['ok' => false, 'name' => '']; }
    $st = $pdo->prepare(
        "SELECT u.full_name, u.username, u.status, u.project_id, r.can_req, r.level
           FROM users u LEFT JOIN roles r ON r.id = u.role_id
          WHERE u.card_id = ?"
    );
    $st->execute([$cardId]);
    $name = '';
    foreach ($st->fetchAll() as $u) {
        $nm = trim((string)$u['full_name']) !== '' ? trim((string)$u['full_name']) : (string)$u['username'];
        if ($name === '') { $name = $nm; }
        if (mb_strtolower(trim((string)$u['status']), 'UTF-8') === 'inactive') { continue; }
        if ($projectId > 0 && (int)$u['project_id'] !== $projectId) { continue; }
        if (isTrueFlag($u['can_req'] ?? 0) && (int)($u['level'] ?? 0) >= 1) {
            return ['ok' => true, 'name' => $nm];
        }
    }
    return ['ok' => false, 'name' => $name];
}

/** ผู้อนุมัติที่เลือกได้ของไซต์ (ระดับ 4–98 · ใช้งานอยู่ · ไม่ใช่ตัวเอง) → [{username, fullName, level, role}] เรียงระดับแล้วชื่อ */
function gmApprovers(PDO $pdo, int $projectId, string $exclude): array {
    $st = $pdo->prepare(
        "SELECT u.username, u.full_name, r.level, r.role_code
           FROM users u JOIN roles r ON r.id = u.role_id
          WHERE u.project_id = ? AND u.status <> 'inactive' AND r.level >= 4 AND r.level < 99 AND u.username <> ?
          ORDER BY r.level, u.full_name, u.username"
    );
    $st->execute([$projectId, $exclude]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $u = trim((string)$r['username']);
        if ($u === '') { continue; }
        $fn = trim((string)$r['full_name']);
        $out[] = ['username' => $u, 'fullName' => $fn !== '' ? $fn : $u, 'level' => (int)$r['level'], 'role' => (string)$r['role_code']];
    }
    return $out;
}

/** gates ของไซต์ (ใช้งานอยู่) → [code => ['id','code','name']] */
function gmSiteGates(PDO $pdo, int $projectId): array {
    $st = $pdo->prepare("SELECT id, gate_code, name FROM gates WHERE project_id = ? AND status = 'active' ORDER BY gate_code");
    $st->execute([$projectId]);
    $out = [];
    foreach ($st->fetchAll() as $g) {
        $c = strtoupper(trim((string)$g['gate_code']));
        $out[$c] = ['id' => (int)$g['id'], 'code' => $c, 'name' => trim((string)$g['name'])];
    }
    return $out;
}

/** gates.id → gate_code ('' ถ้าไม่พบ) */
function gmGateCode(PDO $pdo, ?int $gateId): string {
    if (!$gateId) { return ''; }
    $st = $pdo->prepare('SELECT gate_code FROM gates WHERE id = ?');
    $st->execute([(int)$gateId]);
    $v = $st->fetchColumn();
    return $v === false ? '' : strtoupper(trim((string)$v));
}

// =========================================================================
// สถานะ (ข้อความไทย)
// =========================================================================

/** ข้อความสถานะของใบย้าย Gate จากสถานะใบ + สถานะประตูของ 2 ขา */
function gmStatusThai(string $status, string $outGl, string $inGl, string $src, string $dest, bool $nothingMoved = false): string {
    $s  = mb_strtolower(trim($status), 'UTF-8');
    $o  = strtolower(trim($outGl));
    $i  = strtolower(trim($inGl));
    if (strpos($s, 'cancel') !== false) { return 'ยกเลิกแล้ว'; }
    if (strpos($s, 'reject') !== false) { return 'ไม่อนุมัติ'; }
    if (strpos($s, 'complete') !== false) {
        return $nothingMoved ? 'จบ — เบิกออก 0 ทุกรายการ (ไม่มีของย้าย)' : 'ย้ายเสร็จแล้ว — นำเข้า ' . $dest . ' แล้ว';
    }
    if (strpos($s, 'awaiting') !== false) { return 'รออนุมัติ'; }
    if (strpos($s, 'in transit') !== false) {
        if ($i === 'opened' || $i === 'scanned') { return 'แตะบัตรนำเข้า ' . $dest . ' แล้ว — รอถ่ายรูปยืนยัน'; }
        if ($i === 'confirmed') { return 'ยืนยันนำเข้าแล้ว — รอปิดประตู ' . $dest; }
        return 'ระหว่างย้าย — รอสแกน QR นำเข้าที่ ' . $dest;
    }
    if ($o === 'opened' || $o === 'scanned') { return 'แตะบัตรเบิกออก ' . $src . ' แล้ว — รอถ่ายรูปยืนยัน'; }
    if ($o === 'confirmed') { return 'ยืนยันเบิกออกแล้ว — รอปิดประตู ' . $src; }
    if (strpos($s, 'approved') !== false) { return 'อนุมัติแล้ว — รอสแกน QR เบิกออกที่ ' . $src; }
    return $status !== '' ? $status : '-';
}

// =========================================================================
// ปิดงานที่ประตู (เรียกจาก finalizeGateDoc ใน lib/stock.php)
// =========================================================================

/**
 * ปิดงานขาของใบย้าย Gate ตามแถว gate_logs ที่ถึงปลายทาง (idempotent — เรียกซ้ำจาก closeGate/reconcile ได้)
 *   ขาเบิกออก: ตัด G ต้นทางตามหยิบจริง (ธง stock_deducted = 1) → In Transit + สร้างแถวขานำเข้า (Awaiting ที่ G ปลายทาง)
 *              หยิบจริง 0 ทุกรายการ → Completed (ไม่มีขานำเข้า)
 *   ขานำเข้า: เพิ่ม G ปลายทาง = จำนวนที่เบิกออก (เฉพาะแถวธง 1 แล้วล้างเป็น 0 · จด qty_returned) → Completed + return_ts
 */
function gmFinalizeGateDoc(PDO $pdo, string $glDocNo): void {
    $p = gmParseNo($glDocNo);
    if (!$p) { return; }
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) { $pdo->beginTransaction(); }
    try {
        $d = $pdo->prepare('SELECT id, doc_no, doc_type, project_id, gate_id, dest_gate_id, status FROM documents WHERE doc_no = ? FOR UPDATE');
        $d->execute([$p['base']]);
        $doc = $d->fetch();
        if (!$doc || (string)$doc['doc_type'] !== DOC_TYPE_TG) {
            if ($ownTx) { $pdo->commit(); }
            return;
        }
        $statusL = mb_strtolower(trim((string)$doc['status']), 'UTF-8');
        if (strpos($statusL, 'cancel') !== false || strpos($statusL, 'reject') !== false) {
            if ($ownTx) { $pdo->commit(); }
            return;
        }
        $projectId = (int)$doc['project_id'];
        $docId     = (int)$doc['id'];
        $srcGateId = $doc['gate_id'] !== null ? (int)$doc['gate_id'] : stockResolveDocGateId($pdo, $docId, $projectId, (string)$doc['doc_no']);
        $glUpd = $glDocNo;

        if ($p['leg'] === 'out') {
            _gmFinalizeOut($pdo, $doc, $statusL, $projectId, $docId, $srcGateId);
        } else {
            $destGateId = $doc['dest_gate_id'] !== null ? (int)$doc['dest_gate_id'] : null;
            if ($destGateId === null) {
                $g = $pdo->prepare("SELECT gate_id FROM gate_logs WHERE document_id = ? AND leg = 'in' LIMIT 1");
                $g->execute([$docId]);
                $v = $g->fetchColumn();
                $destGateId = ($v === false || $v === null) ? null : (int)$v;
            }
            _gmFinalizeIn($pdo, $doc, $statusL, $projectId, $docId, $srcGateId, $destGateId);
        }
        _stockFinalizeGateLogStatus($pdo, $glUpd, $projectId);
        recalcPending($pdo, $projectId);
        if ($ownTx) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

/** ขาเบิกออก — ภายในทรานแซกชันของ gmFinalizeGateDoc */
function _gmFinalizeOut(PDO $pdo, array $doc, string $statusL, int $projectId, int $docId, ?int $srcGateId): void {
    $done = strpos($statusL, 'in transit') !== false || strpos($statusL, 'complete') !== false;
    if (!$done) {
        $it = $pdo->prepare('SELECT id, material_id, mat_code, qty, qty_actual, stock_deducted FROM document_items WHERE document_id = ? FOR UPDATE');
        $it->execute([$docId]);
        $setMid = $pdo->prepare('UPDATE document_items SET material_id = ? WHERE id = ?');
        $flag   = $pdo->prepare('UPDATE document_items SET stock_deducted = 1 WHERE id = ?');
        $sent = 0.0;
        foreach ($it->fetchAll() as $item) {
            $mc  = trim((string)$item['mat_code']);
            $eff = s05EffQty($item);   // หยิบจริง ?? ที่ขอ
            if ($mc === '' || $eff <= 0) { continue; }
            if (!isTrueFlag($item['stock_deducted'])) {
                $mid = $item['material_id'];
                if ($mid === null) {
                    $mid = ensureProjectMaterial($pdo, $projectId, $mc, null, true);
                    if ($mid === null) { continue; }
                    $setMid->execute([(int)$mid, (int)$item['id']]);
                }
                applyBalanceDelta($pdo, $projectId, (int)$mid, 0.0, $eff, $srcGateId);   // G ต้นทาง Out += หยิบจริง
                $flag->execute([(int)$item['id']]);
            }
            $sent += $eff;
        }
        if ($sent <= 0.0005) {
            $pdo->prepare('UPDATE documents SET status = ? WHERE id = ?')->execute([Doc::ST_COMPLETED, $docId]);
            s05ActivityLog($pdo, 'document', (string)$doc['doc_no'], null, 'tg_nothing_moved', null,
                json_encode(['note' => 'หยิบจริงขาเบิกออก 0 ทุกรายการ — ไม่มีขานำเข้า'], JSON_UNESCAPED_UNICODE));
            return;
        }
        $pdo->prepare('UPDATE documents SET status = ? WHERE id = ?')->execute([GM_ST_IN_TRANSIT, $docId]);
        s05ActivityLog($pdo, 'document', (string)$doc['doc_no'], null, 'tg_out_done', null,
            json_encode(['sent' => round($sent, 3), 'src' => gmGateCode($pdo, $srcGateId)], JSON_UNESCAPED_UNICODE));
        $statusL = mb_strtolower(GM_ST_IN_TRANSIT, 'UTF-8');
    }
    if (strpos($statusL, 'in transit') !== false) {
        _gmEnsureInLeg($pdo, $doc, $projectId, $docId);
    }
}

/** แถว gate_logs ขานำเข้า (Awaiting ที่ G ปลายทาง) — idempotent ด้วย unique doc_no */
function _gmEnsureInLeg(PDO $pdo, array $doc, int $projectId, int $docId): void {
    $destGateId = $doc['dest_gate_id'] !== null ? (int)$doc['dest_gate_id'] : null;
    $dest = gmGateCode($pdo, $destGateId);
    if ($dest === '') {
        _stockErrorLog($pdo, $projectId, null, 'Gate move ' . $doc['doc_no'] . ': ไม่พบประตูปลายทาง — สร้าง QR นำเข้าไม่ได้');
        return;
    }
    $pdo->prepare(
        "INSERT IGNORE INTO gate_logs (project_id, doc_no, document_id, leg, gate_id, status)
         VALUES (?, ?, ?, 'in', ?, ?)"
    )->execute([$projectId, gmInLegNo((string)$doc['doc_no'], $dest), $docId, $destGateId, Gate::ST_AWAITING]);
}

/** ขานำเข้า — ภายในทรานแซกชันของ gmFinalizeGateDoc */
function _gmFinalizeIn(PDO $pdo, array $doc, string $statusL, int $projectId, int $docId, ?int $srcGateId, ?int $destGateId): void {
    if (strpos($statusL, 'in transit') === false) { return; }   // Completed แล้ว (หรือยังไม่เบิกออก) — ไม่ทำซ้ำ
    if ($destGateId === null || $srcGateId === null) {
        _stockErrorLog($pdo, $projectId, null, 'Gate move ' . $doc['doc_no'] . ': ไม่พบประตูต้นทาง/ปลายทาง — ยังไม่นำเข้า');
        return;
    }
    $it = $pdo->prepare('SELECT id, material_id, mat_code, qty, qty_actual, qty_returned, stock_deducted FROM document_items WHERE document_id = ? FOR UPDATE');
    $it->execute([$docId]);
    $setIn = $pdo->prepare('UPDATE document_items SET stock_deducted = 0, qty_returned = ? WHERE id = ?');
    $moved = 0.0;
    foreach ($it->fetchAll() as $item) {
        if (!isTrueFlag($item['stock_deducted'])) { continue; }   // ไม่ได้เบิกออก / นำเข้าไปแล้ว
        $mc  = trim((string)$item['mat_code']);
        $eff = s05EffQty($item);                                 // นำเข้า = เท่าที่เบิกออก (กติกา)
        $mid = $item['material_id'];
        if ($mc !== '' && $eff > 0 && $mid !== null) {
            // ยอดรวมไซต์: แบบ "คืน" (Out −= qty) ผ่าน G ต้นทาง แล้วย้ายรายประตู ต้นทาง → ปลายทาง
            // ผลสุทธิ: ยอดรวมไซต์กลับเท่าก่อนย้าย (qty_in/qty_out สะสมไม่บวม) · G ต้นทาง Out += qty · G ปลายทาง In += qty
            applyBalanceDelta($pdo, $projectId, (int)$mid, 0.0, -$eff, $srcGateId);
            applyGateBalanceDelta($pdo, $projectId, (int)$mid, $srcGateId, 0.0, $eff);
            applyGateBalanceDelta($pdo, $projectId, (int)$mid, $destGateId, $eff, 0.0);
            $moved += $eff;
        }
        $setIn->execute([round(max(0.0, $eff), 3), (int)$item['id']]);
    }
    $pdo->prepare('UPDATE documents SET status = ?, return_ts = COALESCE(return_ts, NOW()) WHERE id = ?')
        ->execute([Doc::ST_COMPLETED, $docId]);
    s05ActivityLog($pdo, 'document', (string)$doc['doc_no'], null, 'tg_in_done', null,
        json_encode(['moved' => round($moved, 3), 'src' => gmGateCode($pdo, $srcGateId), 'dest' => gmGateCode($pdo, $destGateId)], JSON_UNESCAPED_UNICODE));
}

/** reconcile (api/gate.php): แถวประตูของใบย้าย Gate ที่ถึงปลายทางแล้วแต่ใบยังไม่ไปขั้นถัดไป */
function gmNeedsFinalize(PDO $pdo, string $glDocNo): bool {
    $p = gmParseNo($glDocNo);
    if (!$p) { return false; }
    $st = $pdo->prepare('SELECT status FROM documents WHERE doc_no = ? AND doc_type = ?');
    $st->execute([$p['base'], DOC_TYPE_TG]);
    $s = $st->fetchColumn();
    if ($s === false) { return false; }
    $s = mb_strtolower(trim((string)$s), 'UTF-8');
    if (strpos($s, 'cancel') !== false || strpos($s, 'reject') !== false || strpos($s, 'complete') !== false) { return false; }
    if ($p['leg'] === 'out') {
        if (strpos($s, 'in transit') === false) { return true; }
        // เบิกออกแล้ว — ต้องมีแถวขานำเข้า
        $q = $pdo->prepare("SELECT COUNT(*) FROM gate_logs gl JOIN documents d ON d.id = gl.document_id WHERE d.doc_no = ? AND gl.leg = 'in'");
        $q->execute([$p['base']]);
        return (int)$q->fetchColumn() === 0;
    }
    return strpos($s, 'in transit') !== false;
}

// =========================================================================
// ประตู (api/gate.php → gateAcceptDocs)
// =========================================================================

/**
 * ตรวจใบย้าย Gate ตอนรับเข้ารอบ — null = รับได้ · ข้อความ = ข้าม
 *   ขาเบิกออก ต้อง Approved · ขานำเข้า (leg in) ต้อง In Transit · บัตรที่แตะต้องเป็นบัตรสายสโตร์ของไซต์
 */
function gmGateAcceptError(PDO $pdo, array $row, string $docStatus, string $cardId): ?string {
    $docNo = trim((string)($row['doc_no'] ?? ''));
    $p = gmParseNo($docNo);
    $isIn = (string)($row['leg'] ?? '') === 'in' || ($p && $p['leg'] === 'in');
    $s = mb_strtolower(trim($docStatus), 'UTF-8');
    if ($isIn) {
        if (strpos($s, 'in transit') === false) {
            return strpos($s, 'complete') !== false ? 'นำเข้าไปแล้ว' : 'ยังไม่ได้เบิกออกจากประตูต้นทาง (สถานะใบ ' . ($docStatus !== '' ? $docStatus : '-') . ')';
        }
    } elseif (strpos($s, 'approved') === false) {
        return 'สถานะใบ ' . ($docStatus !== '' ? $docStatus : '-');
    }
    if (trim($cardId) === '') {
        return 'ใบย้าย Gate ต้องแตะบัตรสายสโตร์';
    }
    $c = gmStoreCard($pdo, $cardId, (int)($row['project_id'] ?? 0));
    if (!$c['ok']) {
        return 'ใบย้าย Gate ทำได้เฉพาะสายสโตร์ — บัตรที่แตะ' . ($c['name'] !== '' ? ' (' . $c['name'] . ')' : '') . ' ไม่ใช่บัตรสายสโตร์ของไซต์นี้';
    }
    return null;
}

// =========================================================================
// หน้า QR / หน้าถ่ายรูปยืนยัน (lib/gate_api.php)
// =========================================================================

/** การ์ด QR ขานำเข้า (รอสแกนที่ G ปลายทาง) ของไซต์ — โครงเดียวกับ getApprovedDocuments + isImport */
function gmQrInLegCards(PDO $pdo, int $projectId): array {
    $st = $pdo->prepare(
        "SELECT gl.doc_no AS leg_no, d.id, d.doc_no, d.status, d.doc_ts, d.requester_username, p.code AS site_code,
                sg.gate_code AS src_code, dg.gate_code AS dest_code
           FROM gate_logs gl
           JOIN documents d ON d.id = gl.document_id
           JOIN projects p ON p.id = d.project_id
           LEFT JOIN gates sg ON sg.id = d.gate_id
           LEFT JOIN gates dg ON dg.id = gl.gate_id
          WHERE gl.project_id = ? AND gl.leg = 'in' AND d.doc_type = 'TG' AND LOWER(TRIM(gl.status)) = 'awaiting'
          ORDER BY d.id"
    );
    $st->execute([$projectId]);
    $out = [];
    $it = $pdo->prepare(
        "SELECT di.mat_code, di.mat_name, di.qty, di.qty_actual, m.name AS master_name, m.unit AS master_unit, di.unit
           FROM document_items di LEFT JOIN materials m ON m.mat_code = di.mat_code
          WHERE di.document_id = ? AND di.stock_deducted = 1 ORDER BY di.id"
    );
    foreach ($st->fetchAll() as $r) {
        if (strpos(mb_strtolower(trim((string)$r['status']), 'UTF-8'), 'in transit') === false) { continue; }
        $it->execute([(int)$r['id']]);
        $items = [];
        foreach ($it->fetchAll() as $row) {
            $q = s05EffQty($row);
            if ($q <= 0.0005) { continue; }
            $items[] = [
                'matCode' => (string)$row['mat_code'],
                'matName' => $row['master_name'] !== null ? (string)$row['master_name'] : (string)$row['mat_name'],
                'qty'     => $q,
                'unit'    => $row['master_unit'] !== null ? (string)$row['master_unit'] : (string)$row['unit'],
            ];
        }
        if (!$items) { continue; }
        $src  = strtoupper(trim((string)($r['src_code'] ?? '')));
        $dest = strtoupper(trim((string)($r['dest_code'] ?? '')));
        $ts = (string)$r['doc_ts'];
        $out[] = [
            'docId'    => (string)$r['leg_no'],
            'type'     => DOC_TYPE_TG,
            'isImport' => true,
            'baseDoc'  => (string)$r['doc_no'],
            'srcGate'  => $src,
            'destGate' => $dest,
            'dateStr'  => $ts !== '' ? date('d/m/Y H:i', strtotime($ts)) : '',
            'receiver' => 'นำเข้า ' . $dest . ($src !== '' ? ' (จาก ' . $src . ')' : ''),
            'reqName'  => (string)$r['requester_username'],
            'siteCode' => (string)$r['site_code'],
            'items'    => $items,
        ];
    }
    return $out;
}

/** แถว gate_logs ขานำเข้าของใบ (เลข + สถานะ + ประตู) | null */
function gmInLegRow(PDO $pdo, int $documentId): ?array {
    $st = $pdo->prepare(
        "SELECT gl.doc_no, gl.status, gl.picking_id, gl.card_id, gl.scanned_at, g.gate_code
           FROM gate_logs gl LEFT JOIN gates g ON g.id = gl.gate_id
          WHERE gl.document_id = ? AND gl.leg = 'in' LIMIT 1"
    );
    $st->execute([$documentId]);
    $r = $st->fetch();
    return $r ?: null;
}

// =========================================================================
// RPC: getGateMoveFormData()
// =========================================================================
function rpc_getGateMoveFormData(PDO $pdo, ?array $user, array $args) {
    try {
        if (!$user) { return ['success' => false, 'message' => 'session expired']; }
        $projectId = (int)($user['projectId'] ?? 0);
        $site = docExtProjectById($pdo, $projectId);
        if (!$site) { return ['success' => false, 'message' => 'ไม่พบไซต์ของบัญชีนี้']; }
        $me = trim((string)($user['username'] ?? ''));

        $gates = [];
        foreach (gmSiteGates($pdo, $projectId) as $g) { $gates[] = ['code' => $g['code'], 'name' => $g['name']]; }

        // ยอดพร้อมย้ายรายประตู (on_hand − pending > 0 ที่ใดที่หนึ่ง · รหัส IC · ประตู active) — ทุกลักษณะรวม WMS
        $gs = $pdo->prepare(
            "SELECT m.mat_code, m.name, m.unit, m.char_id, m.cat_id, g.gate_code, gb.on_hand, gb.pending
               FROM stock_gate_balances gb
               JOIN materials m ON m.id = gb.material_id
               JOIN gates g ON g.id = gb.gate_id
              WHERE gb.project_id = ? AND g.status = 'active' AND m.code_type = 'ic' AND gb.on_hand > 0
              ORDER BY m.name, m.mat_code, g.gate_code"
        );
        $gs->execute([$projectId]);
        $stock = [];
        foreach ($gs->fetchAll() as $r) {
            $stock[] = [
                'matCode' => trim((string)$r['mat_code']),
                'name'    => trim((string)$r['name']) !== '' ? (string)$r['name'] : (string)$r['mat_code'],
                'unit'    => trim((string)$r['unit']),
                'char'    => strtoupper(trim((string)($r['char_id'] ?? ''))),
                'cls'     => gmItemClass((string)($r['char_id'] ?? ''), (string)($r['cat_id'] ?? '')),
                'gate'    => strtoupper(trim((string)$r['gate_code'])),
                'onHand'  => (float)$r['on_hand'],
                'pending' => (float)$r['pending'],
                'avail'   => max(0.0, round((float)$r['on_hand'] - (float)$r['pending'], 3)),
            ];
        }
        return [
            'success'   => true,
            'site'      => ['code' => $site['code'], 'name' => $site['name']],
            'canCreate' => gmIsStoreUser($pdo, $user),
            'gates'     => $gates,
            'stock'     => $stock,
            'approvers' => gmApprovers($pdo, $projectId, $me),
            'maxLines'  => GM_MAX_LINES,
        ];
    } catch (Throwable $e) {
        error_log('getGateMoveFormData: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// =========================================================================
// RPC: processGateMoveSubmission(payload)
//   payload = {destGate, items:[{MatCode, Qty, GateID}], note, approver}
// =========================================================================
function rpc_processGateMoveSubmission(PDO $pdo, ?array $user, array $args) {
    try {
        if (!$user) { return ['success' => false, 'message' => 'session expired']; }
        if (!gmIsStoreUser($pdo, $user)) {
            return ['success' => false, 'message' => 'ใบย้าย Gate ออกได้เฉพาะเจ้าหน้าที่สโตร์ (AST / ST1 / ST2 / SST)'];
        }
        $p = (isset($args[0]) && is_array($args[0])) ? $args[0] : [];
        $projectId = (int)$user['projectId'];
        $site = docExtProjectById($pdo, $projectId);
        if (!$site) { return ['success' => false, 'message' => 'ไม่พบไซต์ของบัญชีนี้']; }
        $me = trim((string)$user['username']);
        $gates = gmSiteGates($pdo, $projectId);

        // ---- ประตูปลายทาง ----
        $dest = strtoupper(trim((string)($p['destGate'] ?? '')));
        if ($dest === '') { return ['success' => false, 'message' => 'เลือกประตูปลายทาง (G ที่จะนำของเข้า)']; }
        if (!isset($gates[$dest])) { return ['success' => false, 'message' => 'ประตูปลายทาง ' . $dest . ' ไม่อยู่ในไซต์นี้หรือปิดใช้งาน']; }

        // ---- รายการ ----
        $raw = (isset($p['items']) && is_array($p['items'])) ? array_values($p['items']) : [];
        if (!$raw) { return ['success' => false, 'message' => 'ไม่มีรายการที่จะย้าย']; }
        if (count($raw) > GM_MAX_LINES) { return ['success' => false, 'message' => 'รายการเกิน ' . GM_MAX_LINES . ' บรรทัด — แยกเป็นหลายใบ']; }
        $items = [];
        $merge = [];
        foreach ($raw as $it) {
            if (!is_array($it)) { continue; }
            $mc = trim((string)($it['MatCode'] ?? $it['matCode'] ?? ''));
            $g  = strtoupper(trim((string)($it['GateID'] ?? $it['gate'] ?? '')));
            $qv = $it['Qty'] ?? $it['qty'] ?? null;
            if ($mc === '') { return ['success' => false, 'message' => 'มีบรรทัดที่ไม่ได้เลือกวัสดุ']; }
            if ($g === '' || !preg_match('/^G\d+$/', $g)) { return ['success' => false, 'message' => $mc . ': เลือกประตูต้นทางที่จะเบิกของออก']; }
            if (!isset($gates[$g])) { return ['success' => false, 'message' => $mc . ': ประตู ' . $g . ' ไม่อยู่ในไซต์นี้หรือปิดใช้งาน']; }
            if ($g === $dest) { return ['success' => false, 'message' => $mc . ': ประตูต้นทาง (' . $g . ') ต้องไม่ใช่ประตูปลายทาง']; }
            if (!is_numeric($qv) || (float)$qv <= 0) { return ['success' => false, 'message' => $mc . ': จำนวนต้องมากกว่า 0']; }
            $q = round((float)$qv, 3);
            $k = $g . '|' . $mc;
            if (isset($merge[$k])) { $items[$merge[$k]]['Qty'] = round($items[$merge[$k]]['Qty'] + $q, 3); continue; }
            $merge[$k] = count($items);
            $items[] = ['MatCode' => $mc, 'Qty' => $q, 'GateID' => $g, 'SiteCode' => $site['code']];
        }
        if (!$items) { return ['success' => false, 'message' => 'ไม่มีรายการที่จะย้าย']; }

        $matMap = _docsMaterialsMap($pdo, array_column($items, 'MatCode'));
        $icErr = _docsIcOnlyError($pdo, $items, $matMap);
        if ($icErr !== null) { return ['success' => false, 'message' => $icErr]; }

        // ---- เกณฑ์อนุมัติตามหมวด (ทั้งตะกร้า) ----
        $classes = [];
        foreach ($items as $it) { $classes[] = gmItemClass($matMap[$it['MatCode']]['char'], $matMap[$it['MatCode']]['cat']); }
        $reqLvl = gmRequiredLevel($classes);
        $approver = $me;
        $approverName = '';
        $status = Doc::ST_APPROVED;
        if ($reqLvl > 0) {
            $cands = array_values(array_filter(gmApprovers($pdo, $projectId, $me), function ($a) use ($reqLvl) { return $a['level'] >= $reqLvl; }));
            if (!$cands) {
                return ['success' => false, 'message' => 'ไซต์ ' . $site['code'] . ' ยังไม่มีผู้อนุมัติระดับ R' . $reqLvl . ' ขึ้นไปในระบบ — ติดต่อผู้ดูแลระบบ'];
            }
            $want = trim((string)($p['approver'] ?? ''));
            $approver = '';
            foreach ($cands as $c) {
                if ($want !== '' && eqUser($c['username'], $want)) { $approver = $c['username']; $approverName = $c['fullName']; break; }
            }
            if ($approver === '') {
                if ($want !== '') {
                    return ['success' => false, 'message' => 'ผู้อนุมัติที่เลือกไม่ถึงเกณฑ์ — ' . gmRequiredText($reqLvl)];
                }
                if (count($cands) === 1) { $approver = $cands[0]['username']; $approverName = $cands[0]['fullName']; }
                else { return ['success' => false, 'message' => 'เลือกผู้อนุมัติ (' . gmRequiredText($reqLvl) . ')']; }
            }
            $status = Doc::ST_AWAITING;
        }

        $note = trim(mb_substr((string)($p['note'] ?? ''), 0, 1000, 'UTF-8'));
        list($gateMap, $siteMap) = _docsGateSiteMaps($pdo, $projectId, $site['code']);
        $now = nowBkk()->format('Y-m-d H:i:s');
        $dateKey = docDateKey();

        $created = [];
        $pdo->beginTransaction();
        try {
            // ของที่ประตูต้นทางพอไหม (on_hand − pending) — ตัวตรวจเดียวกับใบเบิก/TD (ส่งชนิด TD = ตรวจ G ต้นทางของรายการ)
            $gateErr = _docsGateStockGuard($pdo, $items, 'TD', $gateMap, $siteMap, $matMap, $projectId);
            if ($gateErr !== null) {
                $pdo->rollBack();
                return ['success' => false, 'message' => $gateErr];
            }
            $n    = _docCounterNext($pdo, DOC_TYPE_TG, $dateKey);
            $base = DOC_TYPE_TG . $dateKey . _docRunningStr($n);

            $byGate = [];
            foreach ($items as $it) { $byGate[$it['GateID']][] = $it; }
            ksort($byGate);
            $setDest = $pdo->prepare('UPDATE documents SET dest_gate_id = ?, approved_by = ? WHERE id = ?');
            foreach ($byGate as $g => $gItems) {
                $docNo = $base . $g;
                $docId = _docsInsertDocument($pdo, [
                    'doc_no'          => $docNo,
                    'doc_type'        => DOC_TYPE_TG,
                    'project_id'      => $projectId,
                    'requester'       => $me,
                    'receiver_name'   => 'ย้ายไป ' . $dest,
                    'receiver_sub_id' => null,
                    'gate_id'         => $gates[$g]['id'],
                    'usage_area'      => '',
                    'notice'          => $note,
                    'approver'        => $approver,
                    'status'          => $status,
                    'rs_no'           => null,
                    'doc_ts'          => $now,
                ]);
                $setDest->execute([$gates[$dest]['id'], $reqLvl === 0 ? $me : null, $docId]);
                foreach ($gItems as $it) {
                    $mi = $matMap[$it['MatCode']];
                    _docsInsertItem($pdo, $docId, [
                        'material_id' => $mi['id'], 'mat_code' => $it['MatCode'], 'mat_name' => $mi['name'], 'unit' => $mi['unit'],
                        'qty' => $it['Qty'], 'usage_area' => '', 'notice' => $note, 'rs_no' => null, 'charge' => 0,
                    ]);
                }
                if ($status === Doc::ST_APPROVED) {
                    _docsEnsureGateLogAwaiting($pdo, $projectId, $docNo, $docId, $gates[$g]['id']);
                }
                $created[] = $docNo;
                s05ActivityLog($pdo, 'document', $docNo, $me, 'create_tg', null, json_encode([
                    'src' => $g, 'dest' => $dest, 'lines' => count($gItems), 'status' => $status,
                    'approver' => $approver, 'requiredLevel' => $reqLvl,
                ], JSON_UNESCAPED_UNICODE));
            }
            recalcPending($pdo, $projectId);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return [
            'success'  => true,
            'docs'     => $created,
            'status'   => $status,
            'approved' => $status === Doc::ST_APPROVED,
            'approver' => $approver,
            'requiredLevel' => $reqLvl,
            'message'  => $status === Doc::ST_APPROVED
                ? 'ออกใบย้าย Gate ' . implode(', ', $created) . ' แล้ว (NAR ล้วน — อนุมัติทันที) ไปที่หน้า QR เพื่อสแกนเบิกออกที่ประตูต้นทาง'
                : 'ส่งใบย้าย Gate ' . implode(', ', $created) . ' แล้ว — รอ ' . ($approverName !== '' ? $approverName : $approver)
                  . ' อนุมัติ (' . gmRequiredText($reqLvl) . ')',
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('processGateMoveSubmission: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Server error: ' . $e->getMessage()];
    }
}

// =========================================================================
// RPC: getGateMoveList() — ใบย้าย Gate ของไซต์ (120 วัน)
// =========================================================================
function rpc_getGateMoveList(PDO $pdo, ?array $user, array $args) {
    try {
        if (!$user) { return ['success' => false, 'message' => 'session expired']; }
        $projectId = (int)($user['projectId'] ?? 0);
        $me = trim((string)($user['username'] ?? ''));
        $st = $pdo->prepare(
            "SELECT d.id, d.doc_no, d.status, d.doc_ts, d.return_ts, d.requester_username, d.approver_username, d.notice,
                    sg.gate_code AS src_code, dg.gate_code AS dest_code,
                    glo.status AS out_status, glo.picking_id AS out_pk,
                    gli.doc_no AS in_no, gli.status AS in_status
               FROM documents d
               LEFT JOIN gates sg ON sg.id = d.gate_id
               LEFT JOIN gates dg ON dg.id = d.dest_gate_id
               LEFT JOIN gate_logs glo ON glo.doc_no = d.doc_no
               LEFT JOIN gate_logs gli ON gli.document_id = d.id AND gli.leg = 'in'
              WHERE d.doc_type = 'TG' AND d.project_id = ? AND d.doc_ts >= NOW() - INTERVAL 120 DAY
              ORDER BY d.doc_ts DESC, d.id DESC
              LIMIT 200"
        );
        $st->execute([$projectId]);
        $docs = $st->fetchAll();
        $itemsByDoc = [];
        if ($docs) {
            $ids = array_map(function ($d) { return (int)$d['id']; }, $docs);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $it = $pdo->prepare("SELECT di.document_id, di.mat_code, COALESCE(NULLIF(m.name, ''), di.mat_name) AS name,
                                        COALESCE(NULLIF(m.unit, ''), di.unit) AS unit, m.char_id, m.cat_id,
                                        di.qty, di.qty_actual, di.qty_returned, di.stock_deducted
                                   FROM document_items di LEFT JOIN materials m ON m.id = di.material_id
                                  WHERE di.document_id IN ($ph) ORDER BY di.id");
            $it->execute($ids);
            foreach ($it->fetchAll() as $r) {
                $itemsByDoc[(int)$r['document_id']][] = [
                    'matCode'   => (string)$r['mat_code'],
                    'name'      => (string)$r['name'],
                    'unit'      => (string)($r['unit'] ?? ''),
                    'cls'       => gmItemClass((string)($r['char_id'] ?? ''), (string)($r['cat_id'] ?? '')),
                    'qty'       => (float)$r['qty'],
                    'qtyOut'    => $r['qty_actual'] !== null ? (float)$r['qty_actual'] : null,
                    'qtyIn'     => $r['qty_returned'] !== null ? (float)$r['qty_returned'] : null,
                ];
            }
        }
        $rows = [];
        foreach ($docs as $d) {
            $statusL = mb_strtolower(trim((string)$d['status']), 'UTF-8');
            $src  = strtoupper(trim((string)($d['src_code'] ?? '')));
            $dest = strtoupper(trim((string)($d['dest_code'] ?? '')));
            $outS = trim((string)($d['out_status'] ?? ''));
            $inS  = trim((string)($d['in_status'] ?? ''));
            $items = $itemsByDoc[(int)$d['id']] ?? [];
            $nothing = strpos($statusL, 'complete') !== false && $inS === '';
            // QR ที่ใช้ได้ตอนนี้: ขาเบิกออกรอสแกน → เลขใบ · ขานำเข้ารอสแกน → เลขขานำเข้า
            $qrNo = ''; $qrLeg = '';
            if (strpos($statusL, 'approved') !== false && strcasecmp($outS, Gate::ST_AWAITING) === 0) { $qrNo = (string)$d['doc_no']; $qrLeg = 'out'; }
            if (strpos($statusL, 'in transit') !== false && strcasecmp($inS, Gate::ST_AWAITING) === 0) { $qrNo = (string)$d['in_no']; $qrLeg = 'in'; }
            $o = strtolower($outS);
            $rows[] = [
                'docId'      => (string)$d['doc_no'],
                'inLegNo'    => (string)($d['in_no'] ?? ($dest !== '' ? gmInLegNo((string)$d['doc_no'], $dest) : '')),
                'status'     => (string)$d['status'],
                'statusThai' => gmStatusThai((string)$d['status'], $outS, $inS, $src, $dest, $nothing),
                'outGate'    => $outS,
                'inGate'     => $inS,
                'srcGate'    => $src,
                'destGate'   => $dest,
                'dateStr'    => substr((string)$d['doc_ts'], 0, 16),
                'inDate'     => $d['return_ts'] !== null ? substr((string)$d['return_ts'], 0, 16) : '',
                'reqName'    => (string)$d['requester_username'],
                'approver'   => (string)($d['approver_username'] ?? ''),
                'note'       => (string)($d['notice'] ?? ''),
                'items'      => $items,
                'qrNo'       => $qrNo,
                'qrLeg'      => $qrLeg,
                'done'       => strpos($statusL, 'complete') !== false,
                'transit'    => strpos($statusL, 'in transit') !== false,
                'canCancel'  => eqUser((string)$d['requester_username'], $me)
                    && (strpos($statusL, 'awaiting') !== false || strpos($statusL, 'approved') !== false)
                    && !in_array($o, ['opened', 'scanned', 'confirmed', 'closed'], true),
            ];
        }
        return ['success' => true, 'docs' => $rows];
    } catch (Throwable $e) {
        error_log('getGateMoveList: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// =========================================================================
// PDF (lib/pdf_api.php)
// =========================================================================

/**
 * แถวหัวข้อของ PDF ใบย้าย Gate — แถวแรก = ['สถานะ', ข้อความไทย] (แทนแถวสถานะเดิมของรายงาน) ·
 * ต่อด้วย เส้นทาง · QR นำเข้า · เวลานำเข้า · ผู้แตะบัตรนำเข้า · หมายเหตุการย้าย
 */
function gmPdfMeta(PDO $pdo, array $doc): array {
    $src  = gmGateCode($pdo, isset($doc['gate_id']) && $doc['gate_id'] !== null ? (int)$doc['gate_id'] : null);
    $dest = gmGateCode($pdo, isset($doc['dest_gate_id']) && $doc['dest_gate_id'] !== null ? (int)$doc['dest_gate_id'] : null);
    $in = gmInLegRow($pdo, (int)$doc['id']);
    $outSt = '';
    $o = $pdo->prepare('SELECT status FROM gate_logs WHERE doc_no = ? LIMIT 1');
    $o->execute([(string)$doc['doc_no']]);
    $ov = $o->fetchColumn();
    if ($ov !== false) { $outSt = (string)$ov; }
    $meta = [];
    $meta[] = ['สถานะ', gmStatusThai((string)$doc['status'], $outSt, $in ? (string)$in['status'] : '', $src, $dest,
                    stripos((string)$doc['status'], 'complete') !== false && !$in)];
    $meta[] = ['ย้ายจาก → ไป', ($src !== '' ? $src : '-') . ' → ' . ($dest !== '' ? $dest : '-') . ' (ภายในไซต์)'];
    if ($in) {
        $holder = trim((string)($in['card_id'] ?? '')) !== '' ? (s05CardHolders($pdo, [(string)$in['card_id']])[(string)$in['card_id']] ?? '') : '';
        $meta[] = ['QR นำเข้า', (string)$in['doc_no'] . (trim((string)($in['picking_id'] ?? '')) !== '' ? ' · รอบ ' . $in['picking_id'] : '')];
        if (!empty($doc['return_ts'])) { $meta[] = ['นำเข้าเมื่อ', substr((string)$doc['return_ts'], 0, 16)]; }
        if ($holder !== '') { $meta[] = ['ผู้แตะบัตรนำเข้า', $holder]; }
    }
    $meta[] = ['หมายเหตุการย้าย', 'ปิดประตูต้นทาง = ตัดยอด G ต้นทาง · ปิดประตูปลายทาง = เพิ่มยอด G ปลายทาง (นำเข้าเท่าที่เบิกออก)'];
    return $meta;
}
