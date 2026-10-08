<?php
/**
 * CONNEXT — lib/borrow.php : ใบยืม (BD) ตามเอกสาร "05 CONNEXT Scenario การทำงานของระบบ" ③ ⑦ ⑬ (ฉบับแก้ 2026-09-29)
 * [PHP port 2026-09-29 · Scenario 05 borrow-return] — ไม่มีใน GAS
 *
 *   ① กำหนดวันคืน: บังคับทุกใบยืม · เลือกได้ตั้งแต่วันนี้เป็นต้นไป → documents.due_date (ตรวจใน lib/documents.php)
 *   ② งานตรวจเกินกำหนดรายวัน (หลังเที่ยงคืน): ใบ Borrowed / Sent Return ที่เลยกำหนด → ธง overdue_flag +
 *      error_logs "Borrow overdue: …" วันละครั้งต่อใบ จนกว่าจะคืนครบหรือถูกตีเป็นชำรุด/สูญหาย
 *      borrowDailyTick() ถูกเรียกจาก config.php — คำขอแรกของวันเป็นคนรัน · db/borrow_overdue_job.php สำหรับ Task Scheduler
 *   ③ ขาคืน (…RT) คืนได้น้อยกว่าที่ยืม (⑦): document_items.qty_returned / return_reason — ส่วนที่ไม่ได้คืนค้างเป็น
 *      "รอตีชำรุด/สูญหาย" ใบยังไม่ Returned จนกว่าสายสโตร์จะปิด (คืนยอดเข้า G ทำใน finalizeGateDoc — lib/stock.php)
 *   [2026-10-08] ทยอยคืนได้หลายรอบ: หน้าถ่ายรูปยืนยันคืนจด "คืนรอบนี้" (document_items.qty_return_round) → ปิดประตูแล้ว
 *      คืนยอดเข้า G เดิมเท่านั้น สะสมลง qty_returned (คืนรวม) · ยังค้าง → ใบกลับเป็น Borrowed → ผู้ยืม/สายสโตร์แจ้งคืนรอบใหม่
 *      (แถวประตู …RT เดิม ตั้งกลับเป็น Awaiting · QR เลขเดิม · G เดิม) หรือสายสโตร์ตีชำรุด/สูญหาย · เดิม: ขาคืนครั้งเดียวต่อใบ
 *   ④ สายสโตร์ "ตีเป็นชำรุด/สูญหาย" (ตั้งแต่ใบเป็น Borrowed หรือหลังขาคืนที่คืนไม่ครบ) → borrow_writeoffs ·
 *      ไม่คืนยอดเข้า G (ของออกจากสต๊อกถาวร) · ทุกรายการปิดครบ → Returned + ป้าย "มีรายการชำรุด/สูญหาย" (writeoff_flag) ·
 *      รายงานอุปกรณ์ชำรุด/สูญหาย (PDF ต่อใบ · มูลค่าอ้างอิงจาก rate card หรือที่สโตร์กรอกตอนตี) ·
 *      แจ้งผู้เบิก ผู้อนุมัติของใบ และผู้จัดการโครงการ (user_notices = แจ้งเตือนในแอป) · ระบบไม่สร้างรายการหักเงินเอง
 *
 * จำนวนต่อรายการ: ยืมจริง = หยิบจริงขาออก ?? ที่ขอ (s05EffQty) · คืน = qty_returned · ตี = Σ borrow_writeoffs.qty
 *                 ค้างคืน = ยืมจริง − คืน − ตี
 * สถานะใบยืมสำหรับหน้าจอ (borrowDocState): borrowed · return_pending (แจ้งคืนแล้ว รอสแกน) · return_open (แตะบัตรคืนแล้ว
 *   ยังไม่ปิดประตู) · writeoff_pending (ขาคืนจบแล้วแต่คืนไม่ครบ) · closed (Returned)
 *
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/s05.php';

/** กำหนดวันคืนไกลสุดจากวันนี้ (กันพิมพ์ปีผิด) */
const BORROW_MAX_DUE_DAYS = 366;
/** ใกล้ครบกำหนด = ภายในกี่วัน (Dashboard) */
const BORROW_DUE_SOON_DAYS = 2;

// =========================================================================
// สคีมา — สร้างเองครั้งแรก (config.php เรียกก่อนงานอื่นเสมอ · DDL ต้องอยู่นอกทรานแซกชัน)
// =========================================================================

/**
 * documents.due_date / overdue_flag / writeoff_flag · document_items.qty_returned / return_reason ·
 * ตาราง borrow_writeoffs · user_notices — ทำงานจริงครั้งเดียวต่อฐานข้อมูล (เครื่องหมาย settings/.borrow-schema-v1-<db>)
 */
/**
 * [2026-10-08] ทยอยคืน — document_items.qty_return_round (คืนรอบนี้ · จดตอนถ่ายรูปยืนยันคืน · ล้างตอนปิดประตู)
 * ทำงานจริงครั้งเดียวต่อฐานข้อมูล (เครื่องหมาย settings/.borrow-schema-v2-<db>)
 */
function borrowEnsureRoundSchema(PDO $pdo, string $dbName = ''): void {
    static $done = [];
    $key = $dbName !== '' ? $dbName : '_';
    if (isset($done[$key])) { return; }
    $done[$key] = true;
    $safe   = preg_replace('/[^A-Za-z0-9_]/', '_', $key);
    $marker = __DIR__ . '/../settings/.borrow-schema-v2-' . $safe;
    if (is_file($marker)) { return; }
    if ($pdo->inTransaction()) { return; }
    try {
        $has = false;
        foreach ($pdo->query('SHOW COLUMNS FROM document_items')->fetchAll() as $c) {
            if ((string)$c['Field'] === 'qty_return_round') { $has = true; break; }
        }
        if (!$has) {
            $pdo->exec("ALTER TABLE document_items ADD COLUMN qty_return_round DECIMAL(14,3) NULL DEFAULT NULL
                        COMMENT 'ขาคืนใบยืม: คืนรอบนี้ (ถ่ายรูปยืนยันแล้ว ยังไม่ปิดประตู) — ปิดประตูแล้วสะสมลง qty_returned' AFTER qty_returned");
        }
        @file_put_contents($marker, date('c') . "\n");
    } catch (Throwable $e) {
        error_log('borrowEnsureRoundSchema: ' . $e->getMessage());
    }
}

function borrowEnsureSchema(PDO $pdo, string $dbName = ''): void {
    borrowEnsureRoundSchema($pdo, $dbName);   // [2026-10-08] ทยอยคืน — คอลัมน์ qty_return_round
    static $done = [];
    $key = $dbName !== '' ? $dbName : '_';
    if (isset($done[$key])) { return; }
    $done[$key] = true;

    $safe   = preg_replace('/[^A-Za-z0-9_]/', '_', $key);
    $marker = __DIR__ . '/../settings/.borrow-schema-v1-' . $safe;
    if (is_file($marker)) { return; }
    if ($pdo->inTransaction()) { return; }

    try {
        $cols = function (string $table) use ($pdo): array {
            $out = [];
            foreach ($pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll() as $c) { $out[(string)$c['Field']] = true; }
            return $out;
        };
        $dc = $cols('documents');
        if (!isset($dc['due_date'])) {
            $pdo->exec("ALTER TABLE documents ADD COLUMN due_date DATE NULL DEFAULT NULL
                        COMMENT 'กำหนดวันคืน (ใบยืม BD · Scenario 05 ③)' AFTER return_ts");
        }
        if (!isset($dc['overdue_flag'])) {
            $pdo->exec("ALTER TABLE documents ADD COLUMN overdue_flag TINYINT(1) NOT NULL DEFAULT 0
                        COMMENT 'ธงเกินกำหนดคืน (งานตรวจรายวัน)' AFTER due_date");
        }
        if (!isset($dc['writeoff_flag'])) {
            $pdo->exec("ALTER TABLE documents ADD COLUMN writeoff_flag TINYINT(1) NOT NULL DEFAULT 0
                        COMMENT 'มีรายการตีเป็นชำรุด/สูญหาย' AFTER overdue_flag");
        }
        $ic = $cols('document_items');
        if (!isset($ic['qty_returned'])) {
            $pdo->exec("ALTER TABLE document_items ADD COLUMN qty_returned DECIMAL(14,3) NULL DEFAULT NULL
                        COMMENT 'จำนวนคืนจริง (ขาคืน RT · Scenario 05 ⑦) · NULL = ยังไม่คืน' AFTER photo_return_url");
        }
        if (!isset($ic['return_reason'])) {
            $pdo->exec("ALTER TABLE document_items ADD COLUMN return_reason VARCHAR(255) NULL DEFAULT NULL
                        COMMENT 'เหตุผลเมื่อคืนน้อยกว่าที่ยืม' AFTER qty_returned");
        }
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS borrow_writeoffs (
                id           INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                document_id  INT NOT NULL,
                item_id      INT NOT NULL,
                project_id   INT NOT NULL,
                mat_code     VARCHAR(50) NOT NULL DEFAULT '',
                qty          DECIMAL(14,3) NOT NULL,
                kind         VARCHAR(10) NOT NULL COMMENT 'damaged | lost',
                reason       VARCHAR(255) NOT NULL,
                photo_url    TEXT NULL,
                ref_price    DECIMAL(12,4) NULL COMMENT 'มูลค่าอ้างอิงต่อหน่วย ณ วันที่ตี',
                ref_source   VARCHAR(10) NULL COMMENT 'ratecard | manual',
                stage        VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'borrowed | return_pending | writeoff_pending',
                created_by   VARCHAR(100) NOT NULL,
                created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_bw_doc (document_id),
                KEY idx_bw_proj_time (project_id, created_at),
                CONSTRAINT fk_bw_doc  FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE,
                CONSTRAINT fk_bw_item FOREIGN KEY (item_id) REFERENCES document_items (id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS user_notices (
                id          INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                project_id  INT NULL,
                username    VARCHAR(150) NOT NULL,
                kind        VARCHAR(30) NOT NULL,
                title       VARCHAR(200) NOT NULL,
                body        TEXT NULL,
                ref_doc     VARCHAR(35) NULL,
                created_by  VARCHAR(100) NULL,
                created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                read_at     DATETIME NULL,
                KEY idx_un_user (username, read_at),
                KEY idx_un_doc (ref_doc)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        @file_put_contents($marker, date('c') . "\n");
    } catch (Throwable $e) {
        error_log('borrowEnsureSchema: ' . $e->getMessage());
    }
}

// =========================================================================
// วันที่
// =========================================================================

function borrowToday(): string { return date('Y-m-d'); }

/** 'Y-m-d' (หรือ 'd/m/Y' ค.ศ./พ.ศ.) → 'Y-m-d' · ไม่ถูกต้อง → null */
function borrowParseDate($raw): ?string {
    $s = trim((string)$raw);
    if ($s === '') { return null; }
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) {
        $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3];
    } elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $s, $m)) {
        $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
        if ($y > 2400) { $y -= 543; }
    } else {
        return null;
    }
    if (!checkdate($mo, $d, $y)) { return null; }
    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
}

/** '2026-10-05' → '5 ต.ค. 2569' */
function borrowThaiDate(?string $ymd): string {
    $d = borrowParseDate((string)$ymd);
    if ($d === null) { return '-'; }
    $mon = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    list($y, $m, $dd) = array_map('intval', explode('-', $d));
    return $dd . ' ' . $mon[$m] . ' ' . ($y + 543);
}

/**
 * ตรวจกำหนดวันคืนของใบยืมใหม่ → [Y-m-d|null, ข้อความ error|null]
 * บังคับ · ตั้งแต่วันนี้ (เวลาไทย) · ไม่เกิน 1 ปี
 */
function borrowCheckDueDate($raw, ?string $today = null): array {
    $today = $today ?: borrowToday();
    if (trim((string)$raw) === '') {
        return [null, 'กรุณาระบุกำหนดวันคืน (บังคับทุกใบยืม)'];
    }
    $d = borrowParseDate($raw);
    if ($d === null) {
        return [null, 'กำหนดวันคืนไม่ถูกต้อง: ' . trim((string)$raw)];
    }
    if ($d < $today) {
        return [null, 'กำหนดวันคืน (' . borrowThaiDate($d) . ') ต้องเป็นวันนี้หรือหลังจากนี้'];
    }
    $max = (new DateTime($today))->modify('+' . BORROW_MAX_DUE_DAYS . ' days')->format('Y-m-d');
    if ($d > $max) {
        return [null, 'กำหนดวันคืน (' . borrowThaiDate($d) . ') ไกลเกินไป — ไม่เกิน 1 ปีจากวันนี้'];
    }
    return [$d, null];
}

/** จำนวนวันที่เลยกำหนด (วันถัดจากกำหนด = 1) · ยังไม่เลย / ไม่มีกำหนด = 0 */
function borrowOverdueDays(?string $due, ?string $today = null): int {
    $d = borrowParseDate((string)$due);
    if ($d === null) { return 0; }
    $today = $today ?: borrowToday();
    if ($d >= $today) { return 0; }
    return (int)(new DateTime($d))->diff(new DateTime($today))->days;
}

// =========================================================================
// ผู้ใช้ / สิทธิ์ / ชื่อ
// =========================================================================

/** ระดับ role จาก session ('R4' → 4 · subcontractor → 1 · ไม่รู้ → 99) */
function borrowRoleNum(?array $user): int {
    if (!$user) { return 99; }
    if (($user['accountType'] ?? '') === 'subcontractor') { return 1; }
    return preg_match('/\d+/', (string)($user['roleLevel'] ?? ''), $m) ? (int)$m[0] : 99;
}

/** เห็นรายการค้างคืนทั้งไซต์: R0 · R4 · R6 · R8 ขึ้นไป · สายสโตร์ (ชุดเดียวกับ getUnreturnedItems เดิม) */
function borrowSeesSite(PDO $pdo, ?array $user): bool {
    $n = borrowRoleNum($user);
    return $n === 0 || $n === 4 || $n === 6 || ($n >= 8 && $n < 99) || s05UserIsStore($pdo, $user);
}

/** ชื่อที่ใช้แทนตัวผู้ใช้ในเอกสาร (SUB = ชื่อชุด · อื่น = username) — ตรงกับ _docsEffectiveUsername */
function borrowEffectiveName(?array $user): string {
    if (!$user) { return ''; }
    if (($user['accountType'] ?? '') === 'subcontractor') {
        $n = trim((string)($user['fullName'] ?? ''));
        return $n !== '' ? $n : trim((string)($user['username'] ?? ''));
    }
    return trim((string)($user['username'] ?? ''));
}

/** username → ชื่อเต็ม (คีย์ตัวเล็ก) */
function borrowFullNames(PDO $pdo, array $usernames): array {
    $keys = [];
    foreach ($usernames as $u) {
        $u = trim((string)$u);
        if ($u !== '') { $keys[$u] = true; }
    }
    if (!$keys) { return []; }
    $list = array_keys($keys);
    $ph = implode(',', array_fill(0, count($list), '?'));
    $st = $pdo->prepare("SELECT username, full_name FROM users WHERE username IN ($ph)");
    $st->execute($list);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $n = trim((string)$r['full_name']);
        $out[mb_strtolower(trim((string)$r['username']), 'UTF-8')] = $n !== '' ? $n : (string)$r['username'];
    }
    return $out;
}

/** ชื่อชุดผู้รับ (SubID → ชื่อ · 'DC: …' คงเดิม) */
function borrowReceiverLabel(PDO $pdo, $raw): string {
    static $subs = null;
    $s = trim((string)$raw);
    if ($s === '') { return '-'; }
    if (strpos($s, 'DC:') === 0) { return $s; }
    if ($subs === null) {
        $subs = [];
        foreach ($pdo->query('SELECT sub_code, name FROM subcontractors ORDER BY id')->fetchAll() as $r) {
            $subs[fmtSubId((string)$r['sub_code'])] = trim((string)$r['name']);
        }
    }
    $k = fmtSubId($s);
    return (isset($subs[$k]) && $subs[$k] !== '') ? $subs[$k] : $s;
}

/** ผู้จัดการโครงการ (role PM) ที่ยังใช้งานของไซต์ */
function borrowProjectManagers(PDO $pdo, int $projectId): array {
    $st = $pdo->prepare("SELECT u.username FROM users u JOIN roles r ON r.id = u.role_id
                          WHERE u.project_id = ? AND u.status = 'active' AND r.role_code = 'PM' ORDER BY u.id");
    $st->execute([$projectId]);
    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
}

// =========================================================================
// จำนวนต่อรายการ + สถานะใบ
// =========================================================================

/** ยอดที่ตีเป็นชำรุด/สูญหายแล้ว → [documentId][itemId] = qty */
function borrowWrittenOffMap(PDO $pdo, array $docIds): array {
    $ids = [];
    foreach ($docIds as $id) { if ((int)$id > 0) { $ids[(int)$id] = true; } }
    if (!$ids) { return []; }
    $keys = array_keys($ids);
    try {
        $ph = implode(',', array_fill(0, count($keys), '?'));
        $st = $pdo->prepare("SELECT document_id, item_id, SUM(qty) AS q FROM borrow_writeoffs
                              WHERE document_id IN ($ph) GROUP BY document_id, item_id");
        $st->execute($keys);
        $out = [];
        foreach ($st->fetchAll() as $r) { $out[(int)$r['document_id']][(int)$r['item_id']] = (float)$r['q']; }
        return $out;
    } catch (Throwable $e) {
        return [];   // ตารางยังไม่เกิด (ยังไม่มีใครตี)
    }
}

/**
 * รายการของใบยืม + จำนวน → [{id, materialId, matCode, matName, unit, requested, borrowed, returned|null,
 *                            writtenOff, outstanding, returnReason, stockDeducted}]
 * $lock = ล็อกแถว document_items (เรียกในทรานแซกชัน)
 */
function borrowDocItems(PDO $pdo, int $docId, bool $lock = false): array {
    $st = $pdo->prepare('SELECT id, material_id, mat_code, mat_name, unit, qty, qty_actual, qty_returned, return_reason, stock_deducted,
                                qty_return_round
                           FROM document_items WHERE document_id = ? ORDER BY id' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$docId]);
    $rows = $st->fetchAll();
    if (!$rows) { return []; }
    $codes = [];
    foreach ($rows as $r) { $c = trim((string)$r['mat_code']); if ($c !== '') { $codes[$c] = true; } }
    $master = [];
    if ($codes) {
        $list = array_keys($codes);
        $ph = implode(',', array_fill(0, count($list), '?'));
        $ms = $pdo->prepare("SELECT id, mat_code, name, unit FROM materials WHERE mat_code IN ($ph)");
        $ms->execute($list);
        foreach ($ms->fetchAll() as $m) { $master[trim((string)$m['mat_code'])] = $m; }
    }
    $wo = borrowWrittenOffMap($pdo, [$docId]);
    $out = [];
    foreach ($rows as $r) {
        $id   = (int)$r['id'];
        $code = trim((string)$r['mat_code']);
        $m    = $master[$code] ?? null;
        $name = trim((string)($m['name'] ?? ''));
        if ($name === '') { $name = trim((string)$r['mat_name']); }
        $unit = trim((string)($m['unit'] ?? ''));
        if ($unit === '') { $unit = trim((string)$r['unit']); }
        $borrowed = s05EffQty($r);
        $returned = ($r['qty_returned'] === null || $r['qty_returned'] === '') ? null : (float)$r['qty_returned'];
        $w = (float)($wo[$docId][$id] ?? 0.0);
        $out[] = [
            'id'            => $id,
            'materialId'    => $r['material_id'] !== null ? (int)$r['material_id'] : ($m ? (int)$m['id'] : null),
            'matCode'       => $code,
            'matName'       => $name !== '' ? $name : $code,
            'unit'          => $unit,
            'requested'     => (float)$r['qty'],
            'borrowed'      => $borrowed,
            'returned'      => $returned,
            'writtenOff'    => $w,
            'outstanding'   => max(0.0, round($borrowed - (float)($returned ?? 0) - $w, 3)),
            'returnReason'  => trim((string)($r['return_reason'] ?? '')),
            'returnRound'   => ($r['qty_return_round'] ?? null) === null ? null : (float)$r['qty_return_round'],   // [2026-10-08] คืนรอบนี้ (ยังไม่ปิดประตู)
            'stockDeducted' => isTrueFlag($r['stock_deducted']),
        ];
    }
    return $out;
}

/** ยอดค้างรวมของใบ (ยืมจริง − คืน − ตี) */
function borrowRemainingTotal(array $items): float {
    $t = 0.0;
    foreach ($items as $it) { $t += (float)$it['outstanding']; }
    return round($t, 3);
}

/**
 * สถานะใบยืมสำหรับหน้าจอ/กติกา
 *   borrowed · return_pending (แจ้งคืนแล้ว รอสแกน QR คืน) · return_open (แตะบัตรคืนแล้ว ยังไม่ปิดประตู) ·
 *   writeoff_pending (ขาคืนจบแล้ว — return_ts มีค่า — แต่ยังมีของค้าง) · closed (Returned) · other
 */
function borrowDocState(string $status, $returnTs, $rtStatus): string {
    $s = mb_strtolower(trim($status), 'UTF-8');
    if (strpos($s, 'returned') !== false) { return 'closed'; }
    if ($s === 'borrowed') { return 'borrowed'; }
    if (strpos($s, 'sent return') !== false) {
        if ($returnTs !== null && trim((string)$returnTs) !== '') { return 'writeoff_pending'; }
        $g = strtolower(trim((string)$rtStatus));
        if ($g === 'opened' || $g === 'scanned' || $g === 'confirmed' || $g === 'closed') { return 'return_open'; }
        return 'return_pending';   // Awaiting หรือยังไม่มีแถว RT (ข้อมูลเก่า)
    }
    return 'other';
}

/** ราคา rate card ของไซต์ → [material_id => ราคา/หน่วย] (เฉพาะที่ตั้งราคาแล้ว) */
function borrowRatePrices(PDO $pdo, int $projectId, array $materialIds): array {
    $ids = [];
    foreach ($materialIds as $id) { if ((int)$id > 0) { $ids[(int)$id] = true; } }
    if (!$ids || $projectId <= 0) { return []; }
    $keys = array_keys($ids);
    $ph = implode(',', array_fill(0, count($keys), '?'));
    $st = $pdo->prepare("SELECT material_id, unit_price FROM rate_cards
                          WHERE project_id = ? AND material_id IN ($ph) AND unit_price IS NOT NULL");
    $st->execute(array_merge([$projectId], $keys));
    $out = [];
    foreach ($st->fetchAll() as $r) { $out[(int)$r['material_id']] = (float)$r['unit_price']; }
    return $out;
}

// =========================================================================
// งานตรวจเกินกำหนดรายวัน (③ ขั้น 5)
// =========================================================================

/**
 * ใบยืม Borrowed / Sent Return ที่เลยกำหนดคืนแล้ว → ธง overdue_flag + error_logs "Borrow overdue: <ใบ> | …"
 * วันละครั้งต่อใบ (มีแถวของวันนี้แล้ว = ไม่ลงซ้ำ — รันซ้ำได้ปลอดภัย) · ล้างธงของใบที่คืนครบ/ปิดแล้ว
 * @return array ['date','overdue','logged','flagged','cleared']
 */
function borrowOverdueRun(PDO $pdo, ?string $today = null): array {
    $today = $today ?: borrowToday();
    $out = ['date' => $today, 'overdue' => 0, 'logged' => 0, 'flagged' => 0, 'cleared' => 0];
    $st = $pdo->prepare(
        "SELECT d.id, d.doc_no, d.project_id, d.requester_username, d.receiver_name, d.due_date, d.overdue_flag, g.gate_code
           FROM documents d LEFT JOIN gates g ON g.id = d.gate_id
          WHERE d.doc_type = 'BD' AND d.due_date IS NOT NULL AND d.due_date < ?
            AND LOWER(TRIM(d.status)) IN ('borrowed', 'sent return')
          ORDER BY d.project_id, d.due_date, d.id"
    );
    $st->execute([$today]);
    $rows = $st->fetchAll();
    if ($rows) {
        $names = borrowFullNames($pdo, array_column($rows, 'requester_username'));
        $seen  = $pdo->prepare('SELECT 1 FROM error_logs WHERE message LIKE ? AND created_at >= ? LIMIT 1');
        $ins   = $pdo->prepare('INSERT INTO error_logs (project_id, gate_code, message) VALUES (?, ?, ?)');
        $flag  = $pdo->prepare('UPDATE documents SET overdue_flag = 1 WHERE id = ?');
        foreach ($rows as $r) {
            $left = [];
            foreach (borrowDocItems($pdo, (int)$r['id']) as $it) {
                if ($it['outstanding'] > 0.0005) { $left[] = $it['matCode'] . ' x' . s05Num($it['outstanding']); }
            }
            if (!$left) { continue; }   // ไม่มีของค้าง (ใบควรเป็น Returned แล้ว)
            $out['overdue']++;
            $docNo = trim((string)$r['doc_no']);
            $seen->execute(['Borrow overdue: ' . likeEscape($docNo) . ' |%', $today . ' 00:00:00']);
            if ($seen->fetchColumn() === false) {
                $u  = trim((string)$r['requester_username']);
                $fn = $names[mb_strtolower($u, 'UTF-8')] ?? '';
                $msg = 'Borrow overdue: ' . $docNo
                     . ' | borrower=' . $u . ($fn !== '' && $fn !== $u ? ' (' . $fn . ')' : '')
                     . ' | sub=' . borrowReceiverLabel($pdo, $r['receiver_name'])
                     . ' | due=' . (string)$r['due_date']
                     . ' | days=' . borrowOverdueDays((string)$r['due_date'], $today)
                     . ' | items=' . implode(', ', array_slice($left, 0, 6)) . (count($left) > 6 ? ' …' : '');
                $ins->execute([(int)$r['project_id'], ($r['gate_code'] ?? '') !== '' ? $r['gate_code'] : null, $msg]);
                $out['logged']++;
            }
            if (!isTrueFlag($r['overdue_flag'])) {
                $flag->execute([(int)$r['id']]);
                $out['flagged']++;
            }
        }
    }
    $clr = $pdo->prepare(
        "UPDATE documents SET overdue_flag = 0
          WHERE doc_type = 'BD' AND overdue_flag = 1
            AND (due_date IS NULL OR due_date >= ? OR LOWER(TRIM(status)) NOT IN ('borrowed', 'sent return'))"
    );
    $clr->execute([$today]);
    $out['cleared'] = $clr->rowCount();
    return $out;
}

/**
 * รันงานตรวจเกินกำหนดครั้งแรกของวัน — config.php เรียกทุกคำขอ (อ่านไฟล์เล็กไฟล์เดียว)
 * settings/.borrow-daily-<db> เก็บวันที่รันล่าสุด · flock กันสองคำขอรันพร้อมกัน · พังไม่กระทบคำขอหลัก
 */
function borrowDailyTick(PDO $pdo, string $dbName = ''): void {
    static $ran = false;
    if ($ran) { return; }
    $ran = true;
    if ($pdo->inTransaction()) { return; }
    $safe   = preg_replace('/[^A-Za-z0-9_]/', '_', $dbName !== '' ? $dbName : '_');
    $marker = __DIR__ . '/../settings/.borrow-daily-' . $safe;
    $today  = borrowToday();
    $last   = @file_get_contents($marker);
    if ($last !== false && trim($last) === $today) { return; }
    $fh = @fopen($marker, 'c+');
    if (!$fh) { return; }
    try {
        if (!flock($fh, LOCK_EX | LOCK_NB)) { return; }   // อีกคำขอกำลังรันอยู่
        rewind($fh);
        if (trim((string)stream_get_contents($fh)) !== $today) {
            try {
                borrowOverdueRun($pdo, $today);
            } catch (Throwable $e) {
                error_log('borrowDailyTick: ' . $e->getMessage());   // จดว่ารันแล้ว — ไม่ลองซ้ำทุกคำขอ
            }
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $today . "\n");
            fflush($fh);
        }
        flock($fh, LOCK_UN);
    } finally {
        fclose($fh);
    }
}

/**
 * ใบยืมเกินกำหนดที่ค้างอยู่ ต่อผู้ยืม — หน้าการอนุมัติของใบยืมใบถัดไป (③ ขั้น 5)
 * @return array ["<project_id>|<username ตัวเล็ก>" => [{docNo, dueDate, days}]]
 */
function borrowOverdueByRequester(PDO $pdo, ?int $projectId = null, ?string $today = null): array {
    $today = $today ?: borrowToday();
    $sql = "SELECT doc_no, project_id, requester_username, due_date FROM documents
             WHERE doc_type = 'BD' AND due_date IS NOT NULL AND due_date < ?
               AND LOWER(TRIM(status)) IN ('borrowed', 'sent return')";
    $ar = [$today];
    if ($projectId) { $sql .= ' AND project_id = ?'; $ar[] = $projectId; }
    $st = $pdo->prepare($sql . ' ORDER BY due_date, id');
    $st->execute($ar);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $k = (int)$r['project_id'] . '|' . mb_strtolower(trim((string)$r['requester_username']), 'UTF-8');
        $out[$k][] = ['docNo' => (string)$r['doc_no'], 'dueDate' => (string)$r['due_date'],
                      'days' => borrowOverdueDays((string)$r['due_date'], $today)];
    }
    return $out;
}

// =========================================================================
// แจ้งเตือนในแอป (user_notices)
// =========================================================================

/** ส่งแจ้งเตือนถึงผู้ใช้ (ไม่ซ้ำคน) → จำนวนที่ส่ง */
function borrowNotify(PDO $pdo, ?int $projectId, array $usernames, string $kind, string $title, string $body, string $refDoc, string $by): int {
    $ins = $pdo->prepare('INSERT INTO user_notices (project_id, username, kind, title, body, ref_doc, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $seen = [];
    $n = 0;
    foreach ($usernames as $u) {
        $u = trim((string)$u);
        if ($u === '') { continue; }
        $k = mb_strtolower($u, 'UTF-8');
        if (isset($seen[$k])) { continue; }
        $seen[$k] = true;
        $ins->execute([$projectId, $u, $kind, mb_substr($title, 0, 200, 'UTF-8'), $body, $refDoc !== '' ? $refDoc : null, $by]);
        $n++;
    }
    return $n;
}

/** getMyNotices() — แจ้งเตือนที่ยังไม่รับทราบของผู้ใช้ (ใหม่ → เก่า สูงสุด 20) */
function rpc_getMyNotices(PDO $pdo, ?array $user, array $args) {
    try {
        $me = borrowEffectiveName($user);
        if ($me === '') { return ['success' => true, 'data' => [], 'unread' => 0]; }
        $st = $pdo->prepare('SELECT id, kind, title, body, ref_doc, created_by, created_at FROM user_notices
                              WHERE username = ? AND read_at IS NULL ORDER BY id DESC LIMIT 20');
        $st->execute([$me]);
        $rows = [];
        foreach ($st->fetchAll() as $r) {
            $rows[] = ['id' => (int)$r['id'], 'kind' => (string)$r['kind'], 'title' => (string)$r['title'],
                       'body' => (string)($r['body'] ?? ''), 'refDoc' => (string)($r['ref_doc'] ?? ''),
                       'by' => (string)($r['created_by'] ?? ''), 'at' => substr((string)$r['created_at'], 0, 16)];
        }
        $c = $pdo->prepare('SELECT COUNT(*) FROM user_notices WHERE username = ? AND read_at IS NULL');
        $c->execute([$me]);
        return ['success' => true, 'data' => $rows, 'unread' => (int)$c->fetchColumn()];
    } catch (Throwable $e) {
        error_log('getMyNotices: ' . $e->getMessage());
        return ['success' => false, 'data' => [], 'unread' => 0];
    }
}

/** ackNotices(ids | 'all') — รับทราบแจ้งเตือนของตัวเอง */
function rpc_ackNotices(PDO $pdo, ?array $user, array $args) {
    try {
        $me = borrowEffectiveName($user);
        if ($me === '') { return ['success' => false]; }
        $arg = $args[0] ?? [];
        if ($arg === 'all') {
            $st = $pdo->prepare('UPDATE user_notices SET read_at = NOW() WHERE username = ? AND read_at IS NULL');
            $st->execute([$me]);
            return ['success' => true, 'updated' => $st->rowCount()];
        }
        $ids = [];
        foreach ((is_array($arg) ? $arg : [$arg]) as $id) { if ((int)$id > 0) { $ids[(int)$id] = true; } }
        if (!$ids) { return ['success' => true, 'updated' => 0]; }
        $keys = array_keys($ids);
        $ph = implode(',', array_fill(0, count($keys), '?'));
        $st = $pdo->prepare("UPDATE user_notices SET read_at = NOW() WHERE username = ? AND read_at IS NULL AND id IN ($ph)");
        $st->execute(array_merge([$me], $keys));
        return ['success' => true, 'updated' => $st->rowCount()];
    } catch (Throwable $e) {
        error_log('ackNotices: ' . $e->getMessage());
        return ['success' => false];
    }
}

// =========================================================================
// ตีเป็นชำรุด/สูญหาย (③ ขั้น 6)
// =========================================================================

/** โหลดหัวใบยืม + ตรวจไซต์ → [doc|null, error|null] */
function _borrowLoadDoc(PDO $pdo, ?array $user, string $docNo, bool $lock = false): array {
    $docNo = trim(preg_replace('/RT$/', '', $docNo));
    if ($docNo === '') { return [null, 'ไม่ระบุเลขใบยืม']; }
    $st = $pdo->prepare(
        'SELECT d.id, d.doc_no, d.doc_type, d.project_id, d.status, d.doc_ts, d.return_ts, d.due_date, d.overdue_flag,
                d.writeoff_flag, d.requester_username, d.approver_username, d.receiver_name, d.gate_id
           FROM documents d WHERE d.doc_no = ?' . ($lock ? ' FOR UPDATE' : '')
    );
    $st->execute([$docNo]);
    $d = $st->fetch();
    if (!$d || (string)$d['doc_type'] !== Doc::TYPE_BD) { return [null, 'ไม่พบใบยืม ' . $docNo]; }
    if (borrowRoleNum($user) !== 0 && (int)$d['project_id'] !== (int)($user['projectId'] ?? 0)) {
        return [null, 'ใบ ' . $docNo . ' ไม่ได้อยู่ในไซต์ของคุณ'];
    }
    return [$d, null];
}

/** สถานะแถวประตูขาคืน (…RT) ของใบ → [id|null, status|''] */
function _borrowRtRow(PDO $pdo, string $docNo, bool $lock = false): array {
    $st = $pdo->prepare('SELECT id, status FROM gate_logs WHERE doc_no = ? LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$docNo . 'RT']);
    $r = $st->fetch();
    return $r ? [(int)$r['id'], trim((string)$r['status'])] : [null, ''];
}

/** ข้อความเมื่อยังตีเป็นชำรุด/สูญหายไม่ได้ ('' = ได้) */
function _borrowWriteoffBlock(string $state, string $status): string {
    if ($state === 'borrowed' || $state === 'return_pending' || $state === 'writeoff_pending') { return ''; }
    if ($state === 'return_open') {
        return 'ใบนี้กำลังคืนที่ประตู (แตะบัตรแล้ว) — ให้ถ่ายรูปยืนยันคืนและปิดประตูก่อน แล้วค่อยตีรายการที่คืนไม่ครบ';
    }
    if ($state === 'closed') { return 'ใบนี้ปิดแล้ว (Returned)'; }
    return 'ตีเป็นชำรุด/สูญหายได้เฉพาะใบที่ยืมออกไปแล้ว (Borrowed) หรือคืนแล้วแต่ไม่ครบ — สถานะใบ: ' . $status;
}

/**
 * getBorrowWriteoffInfo(docNo) — ข้อมูลหน้าต่าง "ตีเป็นชำรุด/สูญหาย": รายการ + ยอดค้าง + ราคา rate card + ประวัติการตี
 */
function rpc_getBorrowWriteoffInfo(PDO $pdo, ?array $user, array $args) {
    try {
        list($d, $err) = _borrowLoadDoc($pdo, $user, (string)($args[0] ?? ''));
        if ($err !== null) { return ['success' => false, 'message' => $err]; }
        list(, $rtStatus) = _borrowRtRow($pdo, (string)$d['doc_no']);
        $state = borrowDocState((string)$d['status'], $d['return_ts'], $rtStatus);
        $items = borrowDocItems($pdo, (int)$d['id']);
        $prices = borrowRatePrices($pdo, (int)$d['project_id'], array_column($items, 'materialId'));
        $outItems = [];
        foreach ($items as $it) {
            $mid = (int)($it['materialId'] ?? 0);
            $outItems[] = [
                'itemId'      => $it['id'],
                'matCode'     => $it['matCode'],
                'matName'     => $it['matName'],
                'unit'        => $it['unit'],
                'borrowed'    => $it['borrowed'],
                'returned'    => $it['returned'],
                'writtenOff'  => $it['writtenOff'],
                'outstanding' => $it['outstanding'],
                'returnReason'=> $it['returnReason'],
                'refPrice'    => $mid > 0 && isset($prices[$mid]) ? $prices[$mid] : null,
            ];
        }
        $names = borrowFullNames($pdo, [(string)$d['requester_username'], (string)$d['approver_username']]);
        $isStore = s05UserIsStore($pdo, $user);
        $block = _borrowWriteoffBlock($state, (string)$d['status']);
        return [
            'success' => true,
            'doc' => [
                'docNo'        => (string)$d['doc_no'],
                'status'       => (string)$d['status'],
                'state'        => $state,
                'rtStatus'     => $rtStatus,
                'borrower'     => (string)$d['requester_username'],
                'borrowerName' => $names[mb_strtolower(trim((string)$d['requester_username']), 'UTF-8')] ?? (string)$d['requester_username'],
                'sub'          => borrowReceiverLabel($pdo, $d['receiver_name']),
                'dueDate'      => (string)($d['due_date'] ?? ''),
                'overdueDays'  => borrowOverdueDays((string)($d['due_date'] ?? '')),
                'hasWriteoff'  => isTrueFlag($d['writeoff_flag']),
            ],
            'items'       => $outItems,
            'remaining'   => borrowRemainingTotal($items),
            'canWriteoff' => $isStore && $block === '',
            'blocked'     => !$isStore ? 'ตีเป็นชำรุด/สูญหายได้เฉพาะสายสโตร์' : $block,
            'history'     => _borrowWriteoffRows($pdo, (int)$d['id']),
        ];
    } catch (Throwable $e) {
        error_log('getBorrowWriteoffInfo: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/** แถวการตีของใบ (เก่า → ใหม่) */
function _borrowWriteoffRows(PDO $pdo, int $docId): array {
    try {
        $st = $pdo->prepare('SELECT w.*, di.mat_name, m.name AS master_name, m.unit AS master_unit, di.unit AS item_unit
                               FROM borrow_writeoffs w
                               JOIN document_items di ON di.id = w.item_id
                               LEFT JOIN materials m ON m.mat_code = w.mat_code
                              WHERE w.document_id = ? ORDER BY w.id');
        $st->execute([$docId]);
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $name = trim((string)($r['master_name'] ?? ''));
        if ($name === '') { $name = trim((string)$r['mat_name']); }
        $unit = trim((string)($r['master_unit'] ?? ''));
        if ($unit === '') { $unit = trim((string)$r['item_unit']); }
        $price = $r['ref_price'] !== null ? (float)$r['ref_price'] : null;
        $out[] = [
            'id'        => (int)$r['id'],
            'itemId'    => (int)$r['item_id'],
            'matCode'   => (string)$r['mat_code'],
            'matName'   => $name !== '' ? $name : (string)$r['mat_code'],
            'unit'      => $unit,
            'qty'       => (float)$r['qty'],
            'kind'      => (string)$r['kind'],
            'reason'    => (string)$r['reason'],
            'photos'    => trim((string)($r['photo_url'] ?? '')),
            'refPrice'  => $price,
            'refSource' => (string)($r['ref_source'] ?? ''),
            'value'     => $price !== null ? round($price * (float)$r['qty'], 2) : null,
            'stage'     => (string)$r['stage'],
            'by'        => (string)$r['created_by'],
            'at'        => substr((string)$r['created_at'], 0, 16),
        ];
    }
    return $out;
}

/**
 * writeOffBorrowItems({docNo, reason, items:[{itemId, qty, kind:'damaged'|'lost', price?}], photos:[dataUri ≤ 3]})
 *   สายสโตร์เท่านั้น · ใบ Borrowed / แจ้งคืนแล้วรอสแกน / คืนแล้วแต่ไม่ครบ · จำนวน ≤ ยอดค้าง · เหตุผลบังคับ · รูปไม่บังคับ
 *   ไม่คืนยอดเข้า G · ทุกรายการปิดครบ → Returned (+ ยกเลิก QR ขาคืนที่ยังรอสแกน) · แจ้งผู้เบิก/ผู้อนุมัติ/PM ·
 *   activity_log · ไม่สร้างรายการหักเงิน
 */
function rpc_writeOffBorrowItems(PDO $pdo, ?array $user, array $args) {
    require_once __DIR__ . '/photos.php';
    $p      = (isset($args[0]) && is_array($args[0])) ? $args[0] : [];
    $docNo  = trim(preg_replace('/RT$/', '', (string)($p['docNo'] ?? '')));
    $reason = trim(mb_substr(trim((string)($p['reason'] ?? '')), 0, 255, 'UTF-8'));
    $lines  = (isset($p['items']) && is_array($p['items'])) ? $p['items'] : [];
    $photos = (isset($p['photos']) && is_array($p['photos'])) ? array_slice($p['photos'], 0, 3) : [];
    $actor  = borrowEffectiveName($user);
    $eps    = 0.0005;
    $root   = rtrim(str_replace('\\', '/', defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__) . '/'), '/') . '/';
    $saved  = [];
    $cleanup = function () use (&$saved, $root) {
        foreach ($saved as $rel) { @unlink($root . $rel); }
        $saved = [];
    };
    try {
        if (!s05UserIsStore($pdo, $user)) {
            return ['success' => false, 'message' => 'ตีเป็นชำรุด/สูญหายได้เฉพาะสายสโตร์'];
        }
        if ($docNo === '') { return ['success' => false, 'message' => 'ไม่ระบุเลขใบยืม']; }
        if (mb_strlen($reason, 'UTF-8') < 3) {
            return ['success' => false, 'message' => 'ใส่เหตุผลที่ตีเป็นชำรุด/สูญหาย (อย่างน้อย 3 ตัวอักษร)'];
        }
        // ข้อมูลรายรายการ (ตรวจรูปแบบก่อน — ตรวจยอดค้างจริงในทรานแซกชัน)
        $want = [];
        foreach ($lines as $x) {
            if (!is_array($x)) { continue; }
            $iid = (int)($x['itemId'] ?? 0);
            $raw = $x['qty'] ?? '';
            if ($iid <= 0 || $raw === '' || $raw === null) { continue; }
            if (!is_numeric($raw)) { return ['success' => false, 'message' => 'จำนวนที่ตีต้องเป็นตัวเลข']; }
            $q = round((float)$raw, 3);
            if ($q <= $eps) { continue; }
            $kind = strtolower(trim((string)($x['kind'] ?? '')));
            if ($kind !== 'damaged' && $kind !== 'lost') {
                return ['success' => false, 'message' => 'เลือกประเภท "ชำรุด" หรือ "สูญหาย" ของทุกรายการที่ตี'];
            }
            $price = null;
            if (isset($x['price']) && $x['price'] !== '' && $x['price'] !== null) {
                if (!is_numeric($x['price']) || (float)$x['price'] < 0) {
                    return ['success' => false, 'message' => 'มูลค่าอ้างอิงต้องเป็นตัวเลขตั้งแต่ 0 ขึ้นไป'];
                }
                $price = round((float)$x['price'], 4);
            }
            if (isset($want[$iid])) { $want[$iid]['qty'] += $q; continue; }
            $want[$iid] = ['qty' => $q, 'kind' => $kind, 'price' => $price];
        }
        if (!$want) {
            return ['success' => false, 'message' => 'เลือกรายการและจำนวนที่จะตีเป็นชำรุด/สูญหายอย่างน้อย 1 รายการ'];
        }
        list($d0, $err) = _borrowLoadDoc($pdo, $user, $docNo);
        if ($err !== null) { return ['success' => false, 'message' => $err]; }
        // [2026-10-02 · GP-16] สายสโตร์ตีชำรุด/สูญหายใบยืมของตัวเองไม่ได้ — ให้สายสโตร์คนอื่นทำ
        if (trim((string)($d0['requester_username'] ?? '')) !== '' && eqUser((string)$d0['requester_username'], $actor)) {
            return ['success' => false, 'message' => 'ตีชำรุด/สูญหายใบยืมของตัวเองไม่ได้ — ให้สายสโตร์คนอื่นเป็นผู้ตี'];
        }

        // รูป (ไฟล์ — นอกทรานแซกชัน)
        $n = 0;
        foreach ($photos as $b64) {
            if (!is_string($b64) || $b64 === '') { continue; }
            $rel = savePhotoDataUri($b64, 'WO_' . $docNo . '_' . (++$n));
            if ($rel === null) {
                $cleanup();
                return ['success' => false, 'message' => 'ไฟล์รูปใช้ไม่ได้ (รองรับ JPG / PNG / WEBP) — ถ่ายใหม่อีกครั้ง'];
            }
            $saved[] = $rel;
        }
        $photoStr = implode(', ', $saved);

        $pdo->beginTransaction();
        try {
            list($d, $err) = _borrowLoadDoc($pdo, $user, $docNo, true);
            if ($err !== null) { throw new RpcBorrowError($err); }
            $docId = (int)$d['id'];
            $projectId = (int)$d['project_id'];
            list($rtId, $rtStatus) = _borrowRtRow($pdo, $docNo, true);
            $state = borrowDocState((string)$d['status'], $d['return_ts'], $rtStatus);
            $block = _borrowWriteoffBlock($state, (string)$d['status']);
            if ($block !== '') { throw new RpcBorrowError($block); }

            $items = [];
            foreach (borrowDocItems($pdo, $docId, true) as $it) { $items[$it['id']] = $it; }
            $errors = [];
            foreach ($want as $iid => $w) {
                if (!isset($items[$iid])) { $errors[] = 'รายการ #' . $iid . ' ไม่อยู่ในใบ ' . $docNo; continue; }
                $it = $items[$iid];
                if ($w['qty'] > $it['outstanding'] + $eps) {
                    $errors[] = $it['matCode'] . ': ตีได้ไม่เกินยอดค้าง ' . s05Num($it['outstanding'])
                              . ($it['unit'] !== '' ? ' ' . $it['unit'] : '') . ' (ขอตี ' . s05Num($w['qty']) . ')';
                }
            }
            if ($errors) { throw new RpcBorrowError("ตีไม่ได้ — แก้รายการต่อไปนี้:\n• " . implode("\n• ", $errors)); }

            $prices = borrowRatePrices($pdo, $projectId, array_map(function ($iid) use ($items) { return $items[$iid]['materialId']; }, array_keys($want)));
            $ins = $pdo->prepare(
                'INSERT INTO borrow_writeoffs (document_id, item_id, project_id, mat_code, qty, kind, reason, photo_url, ref_price, ref_source, stage, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $logItems = [];
            $total = 0.0;
            $noPrice = 0;
            foreach ($want as $iid => $w) {
                $it  = $items[$iid];
                $mid = (int)($it['materialId'] ?? 0);
                if ($mid > 0 && isset($prices[$mid])) { $price = $prices[$mid]; $src = 'ratecard'; }
                elseif ($w['price'] !== null)         { $price = $w['price'];   $src = 'manual'; }
                else                                  { $price = null;          $src = null; }
                $ins->execute([$docId, $iid, $projectId, $it['matCode'], $w['qty'], $w['kind'], $reason,
                               $photoStr !== '' ? $photoStr : null, $price, $src, $state, $actor]);
                if ($price !== null) { $total += $price * $w['qty']; } else { $noPrice++; }
                $logItems[] = ['itemId' => $iid, 'matCode' => $it['matCode'], 'qty' => $w['qty'], 'kind' => $w['kind'],
                               'price' => $price, 'source' => $src];
            }
            $pdo->prepare('UPDATE documents SET writeoff_flag = 1 WHERE id = ?')->execute([$docId]);

            // ยอดค้างหลังตี → ปิดใบเมื่อไม่เหลือ (ของออกจากสต๊อกถาวร — ไม่คืนยอดเข้า G)
            $after = borrowDocItems($pdo, $docId);
            $remaining = borrowRemainingTotal($after);
            $closed = false;
            if ($remaining <= $eps) {
                $pdo->prepare('UPDATE documents SET status = ?, return_ts = COALESCE(return_ts, NOW()), overdue_flag = 0 WHERE id = ?')
                    ->execute([Doc::ST_RETURNED, $docId]);
                if ($rtId !== null && strcasecmp($rtStatus, Gate::ST_AWAITING) === 0) {
                    // QR ขาคืนที่ยังรอสแกน — ไม่มีอะไรต้องคืนแล้ว ประตูเลิกรับ
                    $pdo->prepare('UPDATE gate_logs SET status = ? WHERE id = ?')->execute([Gate::ST_CANCELLED, $rtId]);
                }
                $closed = true;
            }
            s05ActivityLog($pdo, 'document', $docNo, $actor, 'borrow_writeoff', (string)$d['status'],
                json_encode(['stage' => $state, 'items' => $logItems, 'reason' => $reason, 'photos' => count($saved),
                             'remaining' => $remaining, 'closed' => $closed], JSON_UNESCAPED_UNICODE));
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $cleanup();
            if ($e instanceof RpcBorrowError) { return ['success' => false, 'message' => $e->getMessage()]; }
            throw $e;
        }

        // แจ้งผู้เบิก ผู้อนุมัติของใบ และผู้จัดการโครงการ (หลังคอมมิต — แจ้งไม่สำเร็จไม่ย้อนการตี)
        $notified = 0;
        try {
            $kindTh = ['damaged' => 'ชำรุด', 'lost' => 'สูญหาย'];
            $parts = [];
            foreach ($logItems as $li) {
                $parts[] = $li['matCode'] . ' ' . s05Num($li['qty']) . ' (' . $kindTh[$li['kind']] . ')';
            }
            $body = 'รายการ: ' . implode(', ', $parts) . "\n"
                  . 'เหตุผล: ' . $reason . "\n"
                  . 'มูลค่าอ้างอิงรวม: ' . number_format($total, 2) . ' บาท' . ($noPrice ? ' (ยังไม่ตั้งราคา ' . $noPrice . ' รายการ)' : '') . "\n"
                  . ($closed ? 'ใบยืมปิดแล้ว (Returned · มีรายการชำรุด/สูญหาย)' : 'ยังมีของค้างคืน ' . s05Num($remaining)) . "\n"
                  . 'ระบบไม่หักเงินอัตโนมัติ — ต้องตัดสินใจว่าจะหักผู้เบิก หักผู้รับเหมา หรือลงงบโครงการ (ดูรายงาน PDF)';
            $to = [(string)$d0['requester_username'], (string)$d0['approver_username']];
            foreach (borrowProjectManagers($pdo, (int)$d0['project_id']) as $pm) { $to[] = $pm; }
            $to = array_values(array_filter($to, function ($u) use ($actor) { return trim($u) !== '' && !eqUser($u, $actor); }));
            $notified = borrowNotify($pdo, (int)$d0['project_id'], $to, 'borrow_writeoff',
                'อุปกรณ์ยืม ' . $docNo . ' ถูกตีเป็นชำรุด/สูญหาย', $body, $docNo, $actor);
        } catch (Throwable $e) {
            error_log('writeOffBorrowItems notify: ' . $e->getMessage());
        }

        return [
            'success'    => true,
            'docNo'      => $docNo,
            'closed'     => $closed,
            'docStatus'  => $closed ? Doc::ST_RETURNED : (string)$d['status'],
            'remaining'  => $remaining,
            'totalValue' => round($total, 2),
            'noPrice'    => $noPrice,
            'notified'   => $notified,
            'message'    => $closed ? 'ปิดใบยืมแล้ว (Returned · มีรายการชำรุด/สูญหาย)' : 'บันทึกแล้ว — ยังมีของค้างคืน ' . s05Num($remaining),
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $cleanup();
        error_log('writeOffBorrowItems: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Server error: ' . $e->getMessage()];
    }
}

if (!class_exists('RpcBorrowError')) {
    /** ข้อผิดพลาดที่ตั้งใจส่งข้อความถึงผู้ใช้ภายในทรานแซกชันของ writeOffBorrowItems */
    class RpcBorrowError extends RuntimeException {}
}

// =========================================================================
// รายงานอุปกรณ์ชำรุด/สูญหาย (PDF ต่อใบ)
// =========================================================================

/**
 * generateBorrowLossPDF(docNo) — ผู้เบิก · ชุด · รายการ · จำนวน · มูลค่าอ้างอิง · เหตุผล · รูป · ผู้ตี
 * ดูได้: สายสโตร์ · ระดับ ≥ 8 · R0 · ผู้ยืม · ผู้อนุมัติของใบ · ผู้ที่ได้รับแจ้งเตือนของใบนี้
 */
function rpc_generateBorrowLossPDF(PDO $pdo, ?array $user, array $args) {
    try {
        list($d, $err) = _borrowLoadDoc($pdo, $user, (string)($args[0] ?? ''));
        if ($err !== null) { return ['success' => false, 'message' => $err]; }
        $docNo = (string)$d['doc_no'];
        $me = borrowEffectiveName($user);
        $lvl = borrowRoleNum($user);
        $allowed = $lvl === 0 || ($lvl >= 8 && $lvl < 99) || s05UserIsStore($pdo, $user)
                || eqUser($me, (string)$d['requester_username']) || eqUser($me, (string)$d['approver_username']);
        if (!$allowed) {
            $c = $pdo->prepare('SELECT COUNT(*) FROM user_notices WHERE username = ? AND ref_doc = ?');
            $c->execute([$me, $docNo]);
            $allowed = (int)$c->fetchColumn() > 0;
        }
        if (!$allowed) { return ['success' => false, 'message' => 'ไม่มีสิทธิ์ดูรายงานของใบนี้']; }

        $rows = _borrowWriteoffRows($pdo, (int)$d['id']);
        if (!$rows) { return ['success' => false, 'message' => 'ใบ ' . $docNo . ' ยังไม่มีรายการที่ตีเป็นชำรุด/สูญหาย']; }
        $items = borrowDocItems($pdo, (int)$d['id']);

        require_once __DIR__ . '/pdf_engine.php';
        $pj = $pdo->prepare('SELECT code, name, site_ref FROM projects WHERE id = ?');
        $pj->execute([(int)$d['project_id']]);
        $p = $pj->fetch() ?: ['code' => '', 'name' => '', 'site_ref' => ''];
        $gt = $pdo->prepare('SELECT gate_code, name FROM gates WHERE id = ?');
        $gt->execute([(int)($d['gate_id'] ?? 0)]);
        $g = $gt->fetch() ?: ['gate_code' => '', 'name' => ''];
        $pms   = borrowProjectManagers($pdo, (int)$d['project_id']);
        $names = borrowFullNames($pdo, array_merge([(string)$d['requester_username'], (string)$d['approver_username']], array_column($rows, 'by'), $pms));
        $nm = function ($u) use ($names) {
            $u = trim((string)$u);
            if ($u === '') { return '-'; }
            $f = $names[mb_strtolower($u, 'UTF-8')] ?? '';
            return ($f !== '' && $f !== $u) ? $f . ' (' . $u . ')' : $u;
        };
        $fmtTs = function ($ts) {
            if (!$ts) { return '-'; }
            try { $x = new DateTime((string)$ts); } catch (Throwable $e) { return (string)$ts; }
            return borrowThaiDate($x->format('Y-m-d')) . ' ' . $x->format('H:i');
        };
        $pmList = array_map($nm, $pms);

        $statusTh = ['borrowed' => 'ยืมอยู่', 'sent return' => 'แจ้งคืนแล้ว', 'returned' => 'คืนแล้ว / ปิดแล้ว'];
        $sl = mb_strtolower(trim((string)$d['status']), 'UTF-8');
        $due = (string)($d['due_date'] ?? '');
        $overdue = borrowOverdueDays($due, $d['return_ts'] ? substr((string)$d['return_ts'], 0, 10) : null);
        $meta = [
            ['ผู้เบิก (ผู้ยืม)', $nm($d['requester_username'])],
            ['ชุด / ผู้รับ', borrowReceiverLabel($pdo, $d['receiver_name'])],
            ['ผู้อนุมัติ', $nm($d['approver_username'])],
            ['ประตู (G)', trim((string)$g['gate_code']) !== '' ? $g['gate_code'] . (trim((string)$g['name']) !== '' && $g['name'] !== $g['gate_code'] ? ' — ' . $g['name'] : '') : '-'],
            ['วันที่ยืม', $fmtTs($d['doc_ts'])],
            ['กำหนดคืน', $due !== '' ? borrowThaiDate($due) . ($overdue > 0 ? ' (เกินกำหนด ' . $overdue . ' วัน)' : '') : '- (ใบก่อนมีกำหนดคืน)'],
            ['วันที่คืน / ปิดใบ', $fmtTs($d['return_ts'])],
            ['สถานะใบ', ($statusTh[$sl] ?? (string)$d['status']) . (isTrueFlag($d['writeoff_flag']) ? ' · มีรายการชำรุด/สูญหาย' : '')],
            ['ผู้จัดการโครงการ', $pmList ? implode(', ', $pmList) : '-'],
        ];

        // สรุปรายรายการ
        $kindSum = [];
        foreach ($rows as $r) {
            $kindSum[$r['itemId']][$r['kind']] = ($kindSum[$r['itemId']][$r['kind']] ?? 0.0) + $r['qty'];
        }
        $sumRows = [];
        $n = 0;
        foreach ($items as $it) {
            $dm = (float)($kindSum[$it['id']]['damaged'] ?? 0);
            $ls = (float)($kindSum[$it['id']]['lost'] ?? 0);
            $n++;
            $sumRows[] = [
                'no' => $n, 'matCode' => $it['matCode'], 'name' => $it['matName'], 'unit' => $it['unit'],
                'borrowed' => pdfQty($it['borrowed']),
                'returned' => $it['returned'] === null ? '-' : pdfQty($it['returned']),
                'damaged'  => $dm > 0 ? pdfQty($dm) : '-',
                'lost'     => $ls > 0 ? pdfQty($ls) : '-',
                'left'     => $it['outstanding'] > 0.0005 ? pdfQty($it['outstanding']) : '-',
                'reason'   => $it['returnReason'],
            ];
        }
        $srcTh = ['ratecard' => 'rate card', 'manual' => 'สโตร์กรอก'];
        $kindTh = ['damaged' => 'ชำรุด', 'lost' => 'สูญหาย'];
        $logRows = [];
        $total = 0.0;
        $noPrice = 0;
        $photoCells = [];
        $root = _pdfRoot();
        $seenPhoto = [];
        foreach ($rows as $r) {
            if ($r['value'] !== null) { $total += $r['value']; } else { $noPrice++; }
            $logRows[] = [
                'at' => $fmtTs($r['at']), 'matCode' => $r['matCode'], 'name' => $r['matName'],
                'qty' => pdfQty($r['qty']) . ($r['unit'] !== '' ? ' ' . $r['unit'] : ''), 'kind' => $kindTh[$r['kind']] ?? $r['kind'],
                'price' => $r['refPrice'] !== null ? pdfMoney($r['refPrice']) : 'ยังไม่ตั้งราคา',
                'src' => $srcTh[$r['refSource']] ?? '', 'value' => $r['value'] !== null ? pdfMoney($r['value']) : '-',
                'reason' => $r['reason'], 'by' => $nm($r['by']),
            ];
            foreach (preg_split('/[,;\s]+/u', $r['photos']) as $ph) {
                $ph = trim($ph);
                if ($ph === '' || isset($seenPhoto[$ph])) { continue; }
                $seenPhoto[$ph] = true;
                $isLocal = strpos($ph, 'uploads/') === 0;
                $photoCells[] = ['label' => 'รูปประกอบการตี — ' . $fmtTs($r['at']),
                                 'src' => ($isLocal && is_file($root . $ph)) ? $root . $ph : null,
                                 'note' => ($isLocal && is_file($root . $ph)) ? null : '(ไม่พบไฟล์รูป) ' . $ph];
            }
        }
        $photoRows = [];
        for ($i = 0; $i < count($photoCells); $i += 2) {
            $photoRows[] = array_slice($photoCells, $i, 2);
        }
        $siteRef = trim((string)($p['site_ref'] ?? ''));
        $html = pdfRenderTemplate('borrow_loss.php', [
            'siteHeader' => (string)$p['code'] . '  |  ' . (string)$p['name'] . ($siteRef !== '' && $siteRef !== '-' ? '  |  SiteID: ' . $siteRef : ''),
            'docNo'      => $docNo,
            'meta'       => $meta,
            'sumRows'    => $sumRows,
            'logRows'    => $logRows,
            'totalText'  => pdfMoney($total) . ' บาท' . ($noPrice ? ' (ไม่รวม ' . $noPrice . ' รายการที่ยังไม่ตั้งราคา)' : ''),
            'photoRows'  => $photoRows,
            'footerText' => 'สร้างโดยระบบ CONNEXT — ' . date('d/m/Y H:i') . ' · ' . ($me !== '' ? $me : '-'),
        ]);
        $bin = renderPdf($html, 'a4', 'portrait', ['pageNumbers' => true]);
        return ['success' => true, 'dataUri' => 'data:application/pdf;base64,' . base64_encode($bin),
                'fileName' => $docNo . '_ชำรุด-สูญหาย.pdf'];
    } catch (Throwable $e) {
        error_log('generateBorrowLossPDF: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// =========================================================================
// Dashboard: อุปกรณ์ยืมค้างคืน (เกินกำหนด · ใกล้ครบกำหนด · รอตีชำรุด/สูญหาย)
// =========================================================================

/** getBorrowAlerts(siteCode) — R0 / R4 / R6 / R8+ / สายสโตร์ (ชุดเดียวกับที่เห็นรายการค้างคืนทั้งไซต์) */
/**
 * ใบยืมเกินกำหนดของผู้ใช้เอง (ผู้ส่งใบยืม) — ฟอร์มสร้างใบยืมขึ้นคำเตือน delay (GP-13 · 2026-10-02)
 * เตือนอย่างเดียว ไม่บล็อก · ผู้ยืม = ชื่อผู้ส่งใบ (requester) เหมือนหน้าอนุมัติ
 */
function rpc_getMyBorrowOverdue(PDO $pdo, ?array $user, array $args) {
    try {
        if (!$user) { return ['success' => false, 'message' => 'session expired']; }
        $pid = (int)($user['projectId'] ?? 0);
        $me  = mb_strtolower(borrowEffectiveName($user), 'UTF-8');
        $map = borrowOverdueByRequester($pdo, $pid > 0 ? $pid : null);
        $docs = [];
        foreach ($map as $k => $list) {
            $parts = explode('|', $k, 2);
            if (($parts[1] ?? '') === $me && ($pid <= 0 || (int)$parts[0] === $pid)) { $docs = array_merge($docs, $list); }
        }
        usort($docs, function ($a, $b) { return $b['days'] - $a['days']; });
        return ['success' => true, 'count' => count($docs), 'maxDays' => $docs ? (int)$docs[0]['days'] : 0,
                'docs' => array_slice($docs, 0, 10)];
    } catch (Throwable $e) {
        error_log('getMyBorrowOverdue: ' . $e->getMessage());
        return ['success' => false, 'message' => 'โหลดข้อมูลใบยืมเกินกำหนดไม่สำเร็จ'];
    }
}

function rpc_getBorrowAlerts(PDO $pdo, ?array $user, array $args) {
    try {
        require_once __DIR__ . '/inventory_insights.php';   // _iiSite
        if (!borrowSeesSite($pdo, $user)) { return ['success' => false, 'message' => 'no_permission']; }
        $site = _iiSite($pdo, $user, $args[0] ?? '', true);
        if ($site['id'] === null) { return ['success' => false, 'message' => 'ไม่พบไซต์ ' . $site['code']]; }
        $today = borrowToday();
        $st = $pdo->prepare(
            "SELECT d.id, d.doc_no, d.status, d.return_ts, d.due_date, d.requester_username, d.receiver_name, g.gate_code, gl.status AS rt_status
               FROM documents d
               LEFT JOIN gates g ON g.id = d.gate_id
               LEFT JOIN gate_logs gl ON gl.doc_no = CONCAT(d.doc_no, 'RT')
              WHERE d.project_id = ? AND d.doc_type = 'BD' AND LOWER(TRIM(d.status)) IN ('borrowed', 'sent return')
              ORDER BY d.due_date IS NULL, d.due_date, d.id"
        );
        $st->execute([$site['id']]);
        $docs = $st->fetchAll();
        $names = borrowFullNames($pdo, array_column($docs, 'requester_username'));
        $soonLimit = (new DateTime($today))->modify('+' . BORROW_DUE_SOON_DAYS . ' days')->format('Y-m-d');
        $out = ['success' => true, 'siteCode' => $site['code'], 'today' => $today, 'overdue' => [], 'dueSoon' => [],
                'writeoffPending' => [], 'noDueDate' => 0, 'open' => 0];
        foreach ($docs as $d) {
            $items = borrowDocItems($pdo, (int)$d['id']);
            $left = [];
            foreach ($items as $it) {
                if ($it['outstanding'] > 0.0005) { $left[] = $it['matName'] . ' x' . s05Num($it['outstanding']); }
            }
            if (!$left) { continue; }
            $out['open']++;
            $state = borrowDocState((string)$d['status'], $d['return_ts'], $d['rt_status']);
            $due = (string)($d['due_date'] ?? '');
            $u = trim((string)$d['requester_username']);
            $row = [
                'docNo' => (string)$d['doc_no'], 'state' => $state, 'gate' => (string)($d['gate_code'] ?? ''),
                'borrower' => $u, 'borrowerName' => $names[mb_strtolower($u, 'UTF-8')] ?? $u,
                'sub' => borrowReceiverLabel($pdo, $d['receiver_name']), 'dueDate' => $due,
                'days' => borrowOverdueDays($due, $today), 'items' => $left,
            ];
            // [2026-10-08] คืนไม่ครบ = ข้อมูลเก่า (ขาคืนจบแล้ว) หรือใบที่ทยอยคืนไปบางส่วน — รอทยอยคืนต่อ / ตีชำรุด
            $partial = false;
            foreach ($items as $it) { if ((float)($it['returned'] ?? 0) > 0.0005 && $it['outstanding'] > 0.0005) { $partial = true; break; } }
            if ($state === 'writeoff_pending' || ($state === 'borrowed' && $partial)) { $row['partial'] = true; $out['writeoffPending'][] = $row; }
            if ($due === '') { $out['noDueDate']++; continue; }
            if ($row['days'] > 0) { $out['overdue'][] = $row; }
            elseif ($due <= $soonLimit) { $out['dueSoon'][] = $row; }
        }
        usort($out['overdue'], function ($a, $b) { return $b['days'] - $a['days']; });
        // [2026-10-02 · GP-13] ผู้ยืมที่มีของเกินกำหนด (สรุปรายคน) — Dashboard สายสโตร์ · เตือนอย่างเดียว ไม่บล็อกการยืม
        $byB = [];
        foreach ($out['overdue'] as $r) {
            $k = mb_strtolower($r['borrower'], 'UTF-8');
            if (!isset($byB[$k])) {
                $byB[$k] = ['borrower' => $r['borrower'], 'borrowerName' => $r['borrowerName'], 'docs' => 0, 'maxDays' => 0, 'docNos' => []];
            }
            $byB[$k]['docs']++;
            $byB[$k]['maxDays'] = max($byB[$k]['maxDays'], (int)$r['days']);
            if (count($byB[$k]['docNos']) < 5) { $byB[$k]['docNos'][] = $r['docNo']; }
        }
        $out['overdueBorrowers'] = array_values($byB);
        usort($out['overdueBorrowers'], function ($a, $b) {
            return ($b['docs'] - $a['docs']) ?: ($b['maxDays'] - $a['maxDays']);
        });
        return $out;
    } catch (Throwable $e) {
        error_log('getBorrowAlerts: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
