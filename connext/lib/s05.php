<?php
/**
 * CONNEXT — lib/s05.php : ส่วนกลางของงานตามเอกสาร "05 CONNEXT Scenario การทำงานของระบบ"
 * [PHP port 2026-09-28 · Scenario 05]
 *
 * สคีมาที่เพิ่ม (สร้างเองครั้งแรกที่มีคำขอเข้ามา — s05EnsureSchema ถูกเรียกจาก config.php):
 *   document_items.qty_actual        จำนวนหยิบจริง (NULL = ยังไม่ถ่ายรูปยืนยัน → ใช้จำนวนที่ขอ) ⑦
 *   document_items.actual_reason     เหตุผลเมื่อหยิบน้อยกว่าที่ขอ ⑦
 *   document_items.photo_url         รูปยืนยันรายรายการ (รหัส IC) ขาออก/รับเข้า — กติกาข้อ 21
 *   document_items.photo_return_url  รูปยืนยันคืนรายรายการ (ใบยืมขาคืน RT) ③
 *   gate_settings                    นาทีต่อรายการ · เพดาน · นาทีต่อการเลื่อน ต่อไซต์ ⑧ (ตู้ดึงพร้อมรายชื่อบัตร)
 *   gate_round_events                หมดเวลาหยิบของ · ขอเวลาเพิ่ม · เลื่อนเวลาปิดประตู · สรุปรอบ (จากตู้) ⑧ ⑨
 *
 * กติกาจำนวน: ทุกที่ที่ "ของเข้า/ออกสต๊อก" หรือ "รายงานของที่จ่ายไปแล้ว" ใช้ s05EffQty / s05EffQtySql
 * (หยิบจริง ถ้ายืนยันแล้ว ไม่งั้นจำนวนที่ขอ) · ยอดจอง (pending) ยังคิดจากจำนวนที่ขอเสมอ
 *
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/../helpers.php';

/** ค่าตั้งต้นเวลาหยิบของ (⑧): 3 นาที × รายการ · เพดาน 120 นาที · ขอเวลาเพิ่ม/เลื่อนครั้งละ 5 นาที */
const S05_DEFAULT_PICK_MIN_PER_ITEM = 3.0;
const S05_DEFAULT_PICK_CAP_MIN      = 120;
const S05_DEFAULT_EXTEND_MIN        = 5;
// [2026-10-08] บัตรสายสโตร์ขอเวลาเกินเพดานได้ไหม (ต่อไซต์ · ใช้ทั้ง "ขอเวลาเพิ่ม" และ "เลื่อนเวลา" ALARM 3)
//   ปิด (ค่าตั้งต้น) = เพดานตายตัวทุกบัตร · เปิด = สายสโตร์เกินได้ ทุกครั้งขึ้นเตือน OVER CAP บน Dashboard
const S05_DEFAULT_STORE_OVER_CAP    = false;
/**
 * หยิบจริงเกินจำนวนที่ขอได้กี่ชิ้นต่อรายการ ⑦ — เอกสาร 05 ฉบับแก้ 2026-09-29: "ห้ามเพิ่มเกินจำนวนที่ขอ
 * (ต้องการมากกว่าที่ขอ ให้ออกใบเบิกใหม่)" → 0 (เดิม +3 และไม่เกินยอดพร้อมเบิกของ G)
 */
const S05_MAX_OVER_PICK             = 0.0;

// =========================================================================
// สคีมา — สร้างเองครั้งแรก (DDL ต้องอยู่นอกทรานแซกชัน: config.php เรียกก่อนงานอื่นเสมอ)
// =========================================================================

/**
 * เพิ่มคอลัมน์/ตารางของ Scenario 05 ถ้ายังไม่มี — ทำงานจริงครั้งเดียวต่อฐานข้อมูล
 * (ไฟล์ settings/.s05-schema-v1-<db> เป็นเครื่องหมายว่าทำแล้ว ลบไฟล์ = ตรวจใหม่ ไม่มีผลข้างเคียง)
 */
function s05EnsureSchema(PDO $pdo, string $dbName = ''): void {
    static $done = [];
    $key = $dbName !== '' ? $dbName : '_';
    if (isset($done[$key])) { return; }
    $done[$key] = true;

    $safe   = preg_replace('/[^A-Za-z0-9_]/', '_', $key);
    $marker = __DIR__ . '/../settings/.s05-schema-v1-' . $safe;
    if (is_file($marker)) { return; }
    if ($pdo->inTransaction()) { return; }   // DDL จะคอมมิตทรานแซกชันของผู้เรียก — รอคำขอถัดไป

    try {
        $cols = [];
        foreach ($pdo->query('SHOW COLUMNS FROM document_items')->fetchAll() as $c) {
            $cols[(string)$c['Field']] = true;
        }
        if (!isset($cols['qty_actual'])) {
            $pdo->exec("ALTER TABLE document_items ADD COLUMN qty_actual DECIMAL(14,3) NULL DEFAULT NULL
                        COMMENT 'จำนวนหยิบจริง (Scenario 05 ⑦) · NULL = ใช้จำนวนที่ขอ' AFTER qty");
        }
        if (!isset($cols['actual_reason'])) {
            $pdo->exec("ALTER TABLE document_items ADD COLUMN actual_reason VARCHAR(255) NULL DEFAULT NULL
                        COMMENT 'เหตุผลเมื่อหยิบน้อยกว่าที่ขอ' AFTER qty_actual");
        }
        if (!isset($cols['photo_url'])) {
            $pdo->exec("ALTER TABLE document_items ADD COLUMN photo_url TEXT NULL DEFAULT NULL
                        COMMENT 'รูปยืนยันรายรายการ (กติกาข้อ 21)' AFTER actual_reason");
        }
        if (!isset($cols['photo_return_url'])) {
            $pdo->exec("ALTER TABLE document_items ADD COLUMN photo_return_url TEXT NULL DEFAULT NULL
                        COMMENT 'รูปยืนยันคืนรายรายการ (ใบยืมขาคืน)' AFTER photo_url");
        }
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS gate_settings (
                project_id        INT NOT NULL PRIMARY KEY,
                pick_min_per_item DECIMAL(5,2) NOT NULL DEFAULT 3.00,
                pick_cap_min      INT NOT NULL DEFAULT 120,
                extend_min        INT NOT NULL DEFAULT 5,
                store_over_cap    TINYINT(1) NOT NULL DEFAULT 0,
                updated_by        VARCHAR(100) NULL,
                updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_gs_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS gate_round_events (
                id          INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                project_id  INT NULL,
                gate_code   VARCHAR(20) NULL,
                picking_id  VARCHAR(20) NULL,
                event       VARCHAR(30) NOT NULL,
                item_count  INT NULL,
                seq         INT NULL,
                card_id     VARCHAR(50) NULL,
                cardholder  VARCHAR(150) NULL,
                total_min   DECIMAL(8,2) NULL,
                over_cap    TINYINT(1) NOT NULL DEFAULT 0,
                detail      TEXT NULL,
                created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_gre_proj_time (project_id, created_at),
                KEY idx_gre_picking (picking_id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        @file_put_contents($marker, date('c') . "\n");
    } catch (Throwable $e) {
        error_log('s05EnsureSchema: ' . $e->getMessage());
    }
}

// =========================================================================
// จำนวนที่มีผล (หยิบจริง ?? ขอ)
// =========================================================================

/** SQL ของจำนวนที่มีผลของแถว document_items (alias ตามคิวรีของผู้เรียก) */
function s05EffQtySql(string $alias = 'di'): string {
    return 'COALESCE(' . $alias . '.qty_actual, ' . $alias . '.qty)';
}

/** จำนวนที่มีผลจากแถวที่ดึงมาแล้ว (ต้องมีคีย์ qty · qty_actual ถ้ามี) */
function s05EffQty(array $row): float {
    if (array_key_exists('qty_actual', $row) && $row['qty_actual'] !== null && $row['qty_actual'] !== '') {
        return (float)$row['qty_actual'];
    }
    return (float)($row['qty'] ?? 0);
}

/** เลขแบบอ่านง่าย (5.000 → 5 · 2.500 → 2.5) */
function s05Num($v): string {
    $f = (float)$v;
    if (abs($f - round($f)) < 0.0000001) { return (string)(int)round($f); }
    return rtrim(rtrim(number_format($f, 3, '.', ''), '0'), '.');
}

// =========================================================================
// ค่าตั้งเวลาหยิบของต่อไซต์ (⑧)
// =========================================================================

/** ค่าตั้งเวลาของโครงการ — ไม่มีแถว/ตารางยังไม่เกิด = ค่าตั้งต้น */
function s05GateSettings(PDO $pdo, int $projectId): array {
    $out = [
        'pickMinPerItem' => S05_DEFAULT_PICK_MIN_PER_ITEM,
        'pickCapMin'     => S05_DEFAULT_PICK_CAP_MIN,
        'extendMin'      => S05_DEFAULT_EXTEND_MIN,
        'storeOverCap'   => S05_DEFAULT_STORE_OVER_CAP,
        'custom'         => false,
    ];
    if ($projectId <= 0) { return $out; }
    try {
        // SELECT * — คอลัมน์ store_over_cap เกิดตอนบันทึก/เปิดหน้าตั้งค่าครั้งแรก (s05EnsureStoreOverCapCol)
        $st = $pdo->prepare('SELECT * FROM gate_settings WHERE project_id = ?');
        $st->execute([$projectId]);
        $r = $st->fetch();
        if ($r) {
            $out['pickMinPerItem'] = (float)$r['pick_min_per_item'];
            $out['pickCapMin']     = (int)$r['pick_cap_min'];
            $out['extendMin']      = (int)$r['extend_min'];
            if (array_key_exists('store_over_cap', $r)) { $out['storeOverCap'] = (int)$r['store_over_cap'] === 1; }
            $out['custom']         = true;
        }
    } catch (Throwable $e) {
        error_log('s05GateSettings: ' . $e->getMessage());
    }
    return $out;
}

/** ค่าตั้งเวลาที่ส่งให้ตู้ (getCardList / getGateSettings / คำตอบเรื่องรอบ) — นาที · storeOverCap = บัตรสายสโตร์เกินเพดานได้ */
function s05GateTimingOut(array $settings): array {
    return ['pickMinPerItem' => $settings['pickMinPerItem'], 'pickCapMin' => $settings['pickCapMin'],
            'extendMin' => $settings['extendMin'], 'storeOverCap' => (bool)($settings['storeOverCap'] ?? S05_DEFAULT_STORE_OVER_CAP)];
}

/** [2026-10-08] เพิ่มคอลัมน์ store_over_cap ให้ตารางเดิม (ครั้งเดียว · ห้ามเรียกในทรานแซกชัน — DDL คอมมิตให้) */
function s05EnsureStoreOverCapCol(PDO $pdo): void {
    static $done = false;
    if ($done || $pdo->inTransaction()) { return; }
    try {
        $has = $pdo->query("SHOW COLUMNS FROM gate_settings LIKE 'store_over_cap'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE gate_settings ADD COLUMN store_over_cap TINYINT(1) NOT NULL DEFAULT 0
                        COMMENT 'บัตรสายสโตร์ขอเวลาเกินเพดานได้ (1) / เพดานตายตัว (0)' AFTER extend_min");
        }
        $done = true;
    } catch (Throwable $e) {
        error_log('s05EnsureStoreOverCapCol: ' . $e->getMessage());
    }
}

/** ตรวจค่าตั้งเวลาที่ผู้ใช้กรอก → [ค่า, error|null] */
function s05ValidateGateSettings($perItem, $cap, $extend, $storeOverCap = null): array {
    $p = is_numeric($perItem) ? (float)$perItem : -1;
    $c = is_numeric($cap) ? (int)$cap : -1;
    $x = is_numeric($extend) ? (int)$extend : -1;
    if ($p < 0.5 || $p > 30)   { return [null, 'นาทีต่อรายการต้องอยู่ระหว่าง 0.5 – 30']; }
    if ($c < 5 || $c > 600)    { return [null, 'เพดานเวลาต้องอยู่ระหว่าง 5 – 600 นาที']; }
    if ($x < 1 || $x > 60)     { return [null, 'นาทีต่อการเลื่อนต้องอยู่ระหว่าง 1 – 60']; }
    if ($p > $c)               { return [null, 'นาทีต่อรายการต้องไม่มากกว่าเพดาน']; }
    return [['pickMinPerItem' => round($p, 2), 'pickCapMin' => $c, 'extendMin' => $x,
             'storeOverCap' => $storeOverCap === null ? S05_DEFAULT_STORE_OVER_CAP : isTrueFlag($storeOverCap)], null];
}

/** บันทึกค่าตั้งเวลา (upsert) + activity_log */
function s05SaveGateSettings(PDO $pdo, int $projectId, array $vals, string $byUser): void {
    s05EnsureStoreOverCapCol($pdo);
    $old = s05GateSettings($pdo, $projectId);
    $st = $pdo->prepare(
        'INSERT INTO gate_settings (project_id, pick_min_per_item, pick_cap_min, extend_min, store_over_cap, updated_by)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE pick_min_per_item = VALUES(pick_min_per_item), pick_cap_min = VALUES(pick_cap_min),
                                 extend_min = VALUES(extend_min), store_over_cap = VALUES(store_over_cap),
                                 updated_by = VALUES(updated_by)'
    );
    $st->execute([$projectId, $vals['pickMinPerItem'], $vals['pickCapMin'], $vals['extendMin'],
                  !empty($vals['storeOverCap']) ? 1 : 0, $byUser]);
    s05ActivityLog($pdo, 'gate_settings', (string)$projectId, $byUser, 'update',
        json_encode($old, JSON_UNESCAPED_UNICODE), json_encode($vals, JSON_UNESCAPED_UNICODE));
}

// =========================================================================
// รอบเบิก (picking) — ใบในรอบ · จำนวนรหัส IC ที่ไม่ซ้ำ · เจ้าของบัตร
// =========================================================================

/** card_id → ชื่อเจ้าของบัตร (full_name หรือ username) */
function s05CardHolders(PDO $pdo, array $cardIds): array {
    $ids = [];
    foreach ($cardIds as $c) {
        $c = trim((string)$c);
        if ($c !== '') { $ids[$c] = true; }
    }
    if (!$ids) { return []; }
    $keys = array_keys($ids);
    $ph = implode(',', array_fill(0, count($keys), '?'));
    $st = $pdo->prepare("SELECT card_id, full_name, username FROM users WHERE card_id IN ($ph) ORDER BY (status = 'active') DESC, id");
    $st->execute($keys);
    $out = [];
    foreach ($st->fetchAll() as $u) {
        $k = trim((string)$u['card_id']);
        if ($k === '' || isset($out[$k])) { continue; }
        $n = trim((string)$u['full_name']);
        $out[$k] = $n !== '' ? $n : (string)$u['username'];
    }
    // collation ci: บัตรในตารางอาจเก็บตัวเล็ก/ใหญ่ต่างจากที่ส่งมา — เติมคีย์ตามที่ขอ
    foreach ($keys as $k) {
        if (!isset($out[$k])) {
            foreach ($out as $kk => $nn) {
                if (strcasecmp($kk, $k) === 0) { $out[$k] = $nn; break; }
            }
        }
    }
    return $out;
}

/**
 * แถว gate_logs ของรอบ (เรียงตามลำดับที่เข้ารอบ) — กรองประตูเมื่อระบุ
 * @return array [{id, docNo, status, cardId, gateCode, documentId, leg, docType}]
 */
function s05RoundRows(PDO $pdo, string $pickingId, string $gateCode = '', bool $lock = false): array {
    $pickingId = trim($pickingId);
    if ($pickingId === '') { return []; }
    $st = $pdo->prepare(
        'SELECT gl.id, gl.doc_no, gl.status, gl.card_id, gl.document_id, gl.leg, gl.scanned_at,
                g.gate_code, d.doc_type
           FROM gate_logs gl
           LEFT JOIN gates g ON g.id = gl.gate_id
           LEFT JOIN documents d ON d.id = gl.document_id
          WHERE gl.picking_id = ?
          ORDER BY gl.scanned_at, gl.id' . ($lock ? ' FOR UPDATE' : '')
    );
    $st->execute([$pickingId]);
    $out = [];
    $gateCode = strtoupper(trim($gateCode));
    foreach ($st->fetchAll() as $r) {
        $g = strtoupper(trim((string)($r['gate_code'] ?? '')));
        if ($gateCode !== '' && $g !== '' && $g !== $gateCode) { continue; }
        $out[] = [
            'id'         => (int)$r['id'],
            'docNo'      => trim((string)$r['doc_no']),
            'status'     => trim((string)$r['status']),
            'cardId'     => trim((string)($r['card_id'] ?? '')),
            'gateCode'   => $g,
            'documentId' => $r['document_id'] !== null ? (int)$r['document_id'] : null,
            'leg'        => (string)$r['leg'],
            'docType'    => (string)($r['doc_type'] ?? ''),
            'scannedAt'  => (string)($r['scanned_at'] ?? ''),
        ];
    }
    return $out;
}

/** document id ของแถว gate_logs (ขาคืน RT ชี้ใบยืมฐาน · แถวเก่าที่ไม่มี document_id หาจากเลขใบ) */
function s05RowDocumentIds(PDO $pdo, array $rows): array {
    $ids = [];
    $sel = null;
    foreach ($rows as $r) {
        if (!empty($r['documentId'])) { $ids[] = (int)$r['documentId']; continue; }
        $no = preg_replace('/RT$/', '', (string)$r['docNo']);
        if ($sel === null) { $sel = $pdo->prepare('SELECT id FROM documents WHERE doc_no = ?'); }
        $sel->execute([$no]);
        $id = $sel->fetchColumn();
        if ($id !== false) { $ids[] = (int)$id; }
    }
    return array_values(array_unique($ids));
}

/** จำนวนรหัส IC ที่ไม่ซ้ำกันของรอบ (รหัสเดียวกันหลายบรรทัด/หลายใบนับ 1) — ตู้ใช้คิดเวลาหยิบของ ⑧ */
function s05RoundItemCount(PDO $pdo, array $rows): int {
    $docIds = s05RowDocumentIds($pdo, $rows);
    if (!$docIds) { return 0; }
    $ph = implode(',', array_fill(0, count($docIds), '?'));
    $st = $pdo->prepare("SELECT COUNT(DISTINCT UPPER(TRIM(mat_code))) FROM document_items
                          WHERE document_id IN ($ph) AND TRIM(mat_code) <> ''");
    $st->execute($docIds);
    return (int)$st->fetchColumn();
}

/** สรุปสถานะรายใบของรอบ สำหรับคำตอบถึงตู้/หน้าเว็บ */
function s05RoundDocsOut(PDO $pdo, array $rows): array {
    $holders = s05CardHolders($pdo, array_map(function ($r) { return $r['cardId']; }, $rows));
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'docId'      => $r['docNo'],
            'status'     => $r['status'],
            'type'       => $r['docType'] !== '' ? $r['docType'] : substr($r['docNo'], 0, 2),
            'isReturn'   => (bool)preg_match('/RT$/', $r['docNo']),
            'cardId'     => $r['cardId'],
            'cardholder' => $r['cardId'] !== '' ? ($holders[$r['cardId']] ?? '') : '',
            'confirmed'  => in_array(strtolower($r['status']), ['confirmed', 'closed'], true),
        ];
    }
    return $out;
}

// =========================================================================
// อื่น ๆ
// =========================================================================

/** activity_log กลาง — ห้ามทำให้ธุรกรรมหลักล้ม */
function s05ActivityLog(PDO $pdo, string $entityType, string $entityId, ?string $userName, string $action, ?string $old, ?string $new): void {
    try {
        $st = $pdo->prepare(
            'INSERT INTO activity_log (entity_type, entity_id, user_name, action, old_value, new_value) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$entityType, $entityId, $userName, $action, $old, $new]);
    } catch (Throwable $e) {
        error_log('s05ActivityLog: ' . $e->getMessage());
    }
}

/** ผู้ใช้เป็นสายสโตร์ (roles.can_req) หรือชื่อบทบาทมีคำว่า store — เห็น/ทำแทนใบของทุกคนในไซต์ */
function s05UserIsStore(PDO $pdo, ?array $user): bool {
    if (!$user) { return false; }
    if (!empty($user['canReq'])) { return true; }
    if (strpos(mb_strtolower((string)($user['role'] ?? ''), 'UTF-8'), 'store') !== false) { return true; }
    if (($user['accountType'] ?? '') !== 'user') { return false; }
    try {
        $st = $pdo->prepare('SELECT r.can_req FROM users u JOIN roles r ON r.id = u.role_id WHERE u.username = ? LIMIT 1');
        $st->execute([trim((string)($user['username'] ?? ''))]);
        $v = $st->fetchColumn();
        return $v !== false && isTrueFlag($v);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * เหตุผลที่หยิบน้อยกว่าที่ขอ (ขาออก) — เลือกจากรายการเท่านั้น (ผลพิจารณา GP-05 · 2 ต.ค. 2026)
 * "สแกนผิด" = ใบที่สแกนผิดหลังแตะบัตร ลดหยิบจริงเป็น 0 แทนการคืนด้วยใบ IN · ขาคืนของใบยืมยังพิมพ์เหตุผลเองได้
 */
const S05_PICK_REASONS = ['ของไม่พอ', 'สแกนผิด', 'ไม่ต้องการแล้ว'];

/**
 * เหตุผลที่นับเป็น "ของไม่พอ" บน Dashboard (สโตร์ตรวจนับที่ G นั้น ⑦) — นับเฉพาะเหตุผล "ของไม่พอ" (GP-05)
 * เหตุผลพิมพ์อิสระก่อน 2 ต.ค. 2026: เดาจากคำ (ไม่นับ "หมดอายุ" / "ถุงขาด" ที่เป็นความหมายอื่น)
 */
function s05ReasonIsShortage(string $reason): bool {
    $r = trim($reason);
    if ($r === 'ของไม่พอ') { return true; }
    if (in_array($r, S05_PICK_REASONS, true)) { return false; }
    return (bool)preg_match('/ไม่พอ|ของหมด|หมดสต|ไม่มีของ|ไม่ครบ|short/iu', $r);
}
