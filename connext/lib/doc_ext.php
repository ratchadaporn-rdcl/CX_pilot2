<?php
/**
 * CONNEXT — lib/doc_ext.php : เอกสารชนิดเพิ่ม [PHP port 2026-09-29 · TD / SC] — ไม่มีใน GAS
 *
 *   TD = ใบเบิกโอนย้ายข้ามไซต์ — เบิกของออกจาก G ของไซต์ต้นทางเพื่อส่งไปไซต์ปลายทาง
 *        · ผู้จัดการโครงการ (PM) ของไซต์ต้นทางอนุมัติทุกใบ (ผู้ส่งเป็น PM ของไซต์ = อนุมัติทันที)
 *        · ผ่านประตูเหมือนใบเบิก: สแกน QR → แตะบัตร → ถ่ายรูปยืนยัน (หยิบจริง ≤ ที่ขอ) → ปิดประตู = ตัดสต๊อกที่ G ต้นทาง
 *        · ต้นทางตัดอย่างเดียว — ไซต์ปลายทางคีย์ใบรับเข้า (IN) เอง ระบบไม่ผูกสองฝั่ง
 *   SC = ใบนับสต๊อก — QR เข้า gate จากหน้า "ตรวจสอบประจำวัน" แท็บนับสต๊อก · รายการ = ทุกรายการที่มียอดใน G นั้น
 *        · เปิดประตูแยกจากใบอื่น · นับแบบไม่เห็นยอดในระบบ · บันทึกผลนับแล้วปิดประตู → ไม่ตัด/ไม่เพิ่มสต๊อก
 *        · ผลต่างรอ ADM หรือ R8 ขึ้นไปอนุมัติปรับยอด (อนุมัติ = ยอดที่ G ขยับเท่าผลต่าง) → ตาราง stock_adjustments
 *
 * สคีมา (สร้างเองครั้งแรก — docExtEnsureSchema ถูกเรียกจาก config.php · เครื่องหมาย settings/.docext-schema-v1-<db>):
 *   documents.doc_type ENUM + 'TD','SC' · documents.dest_project_id (ไซต์ปลายทางของ TD) · ตาราง stock_adjustments
 *
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/../helpers.php';

const DOC_TYPE_TD = 'TD';
const DOC_TYPE_SC = 'SC';

/** ป้ายชื่อชนิดเอกสารเพิ่ม (หน้าจอ / PDF / ประวัติ) */
function docExtTypeLabel(string $type): string {
    $t = strtoupper(trim($type));
    if ($t === DOC_TYPE_TD) { return 'เบิกโอนย้ายข้ามไซต์'; }
    if ($t === DOC_TYPE_SC) { return 'นับสต๊อก'; }
    return $t;
}

// =========================================================================
// สคีมา
// =========================================================================

/**
 * documents.doc_type ENUM + TD/SC (คงค่าเดิมทุกตัว) · documents.dest_project_id · ตาราง stock_adjustments
 * ทำงานจริงครั้งเดียวต่อฐานข้อมูล · DDL อยู่นอกทรานแซกชัน (config.php เรียกก่อนงานอื่น)
 */
function docExtEnsureSchema(PDO $pdo, string $dbName = ''): void {
    static $done = [];
    $key = $dbName !== '' ? $dbName : '_';
    if (isset($done[$key])) { return; }
    $done[$key] = true;

    $safe   = preg_replace('/[^A-Za-z0-9_]/', '_', $key);
    $marker = __DIR__ . '/../settings/.docext-schema-v1-' . $safe;
    if (is_file($marker)) { return; }
    if ($pdo->inTransaction()) { return; }

    try {
        $col = $pdo->query("SHOW COLUMNS FROM documents LIKE 'doc_type'")->fetch();
        $typeDef = $col ? (string)$col['Type'] : '';
        if (preg_match("/^enum\((.*)\)$/i", $typeDef, $m)) {
            preg_match_all("/'((?:[^']|'')*)'/", $m[1], $vv);
            $vals = $vv[1];
            $changed = false;
            foreach ([DOC_TYPE_TD, DOC_TYPE_SC] as $want) {
                if (!in_array($want, $vals, true)) { $vals[] = $want; $changed = true; }
            }
            if ($changed) {
                $list = implode(',', array_map(function ($v) { return "'" . str_replace("'", "''", $v) . "'"; }, $vals));
                $pdo->exec("ALTER TABLE documents MODIFY doc_type ENUM($list) NOT NULL");
            }
        }
        $cols = [];
        foreach ($pdo->query('SHOW COLUMNS FROM documents')->fetchAll() as $c) { $cols[(string)$c['Field']] = true; }
        if (!isset($cols['dest_project_id'])) {
            $pdo->exec("ALTER TABLE documents ADD COLUMN dest_project_id INT NULL DEFAULT NULL
                        COMMENT 'ไซต์ปลายทางของใบโอนย้าย (TD)' AFTER project_id, ADD KEY idx_doc_dest_project (dest_project_id)");
        }
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS stock_adjustments (
                id           INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                document_id  INT NOT NULL COMMENT 'ใบนับสต๊อก (SC)',
                item_id      INT NOT NULL,
                project_id   INT NOT NULL,
                gate_id      INT NULL,
                material_id  INT NULL,
                mat_code     VARCHAR(50) NOT NULL DEFAULT '',
                system_qty   DECIMAL(14,3) NOT NULL COMMENT 'ยอดในระบบตอนนับ',
                counted_qty  DECIMAL(14,3) NOT NULL COMMENT 'ที่นับได้',
                delta        DECIMAL(14,3) NOT NULL COMMENT 'นับได้ − ในระบบ (อนุมัติ = ยอดที่ G ขยับเท่านี้)',
                status       VARCHAR(10) NOT NULL COMMENT 'approved | rejected',
                note         VARCHAR(255) NULL,
                decided_by   VARCHAR(100) NOT NULL,
                decided_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_adj_item (item_id),
                KEY idx_adj_doc (document_id),
                KEY idx_adj_proj_time (project_id, decided_at),
                CONSTRAINT fk_adj_doc  FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE,
                CONSTRAINT fk_adj_item FOREIGN KEY (item_id) REFERENCES document_items (id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        @file_put_contents($marker, date('c') . "\n");
    } catch (Throwable $e) {
        error_log('docExtEnsureSchema: ' . $e->getMessage());
    }
}

// =========================================================================
// ผู้ใช้ / บทบาท (server-authoritative — อ่านจาก DB ไม่เชื่อ client)
// =========================================================================

/**
 * บทบาทของผู้ใช้ใน session → ['level','code','canReq','canDaily','projectId','username']
 * subcontractor → level 1 · ไม่พบ → level 99
 */
function docExtUserRole(PDO $pdo, ?array $user): array {
    $out = ['level' => 99, 'code' => '', 'canReq' => false, 'canDaily' => false,
            'projectId' => (int)($user['projectId'] ?? 0), 'username' => trim((string)($user['username'] ?? ''))];
    if (!$user) { return $out; }
    if (($user['accountType'] ?? '') === 'subcontractor') {
        $out['level'] = 1;
        $out['code'] = 'SUB';
        return $out;
    }
    try {
        $st = $pdo->prepare('SELECT r.level, r.role_code, r.can_req, r.can_daily_check, u.project_id
                               FROM users u JOIN roles r ON r.id = u.role_id WHERE u.username = ? LIMIT 1');
        $st->execute([$out['username']]);
        $r = $st->fetch();
        if ($r) {
            $out['level']    = (int)$r['level'];
            $out['code']     = strtoupper(trim((string)$r['role_code']));
            $out['canReq']   = isTrueFlag($r['can_req']);
            $out['canDaily'] = isTrueFlag($r['can_daily_check']);
        }
    } catch (Throwable $e) {
        error_log('docExtUserRole: ' . $e->getMessage());
    }
    return $out;
}

/** ผู้จัดการโครงการ (role PM) ที่ยังใช้งานของไซต์ → [{username, fullName}] */
function docExtProjectPms(PDO $pdo, int $projectId): array {
    $st = $pdo->prepare("SELECT u.username, u.full_name FROM users u JOIN roles r ON r.id = u.role_id
                          WHERE u.project_id = ? AND u.status = 'active' AND r.role_code = 'PM' ORDER BY u.full_name, u.username");
    $st->execute([$projectId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $fn = trim((string)$r['full_name']);
        $out[] = ['username' => (string)$r['username'], 'fullName' => $fn !== '' ? $fn : (string)$r['username']];
    }
    return $out;
}

/** ผู้ใช้เป็น PM (role PM · active) ของไซต์นี้หรือไม่ */
function docExtIsProjectPm(PDO $pdo, string $username, int $projectId): bool {
    if ($username === '' || $projectId <= 0) { return false; }
    $st = $pdo->prepare("SELECT 1 FROM users u JOIN roles r ON r.id = u.role_id
                          WHERE u.username = ? AND u.project_id = ? AND u.status = 'active' AND r.role_code = 'PM' LIMIT 1");
    $st->execute([$username, $projectId]);
    return $st->fetchColumn() !== false;
}

/** projects.code → ['id','code','name'] | null */
function docExtProjectByCode(PDO $pdo, string $code): ?array {
    $code = trim($code);
    if ($code === '') { return null; }
    $st = $pdo->prepare('SELECT id, code, name, status FROM projects WHERE code = ? LIMIT 1');
    $st->execute([$code]);
    $p = $st->fetch();
    return $p ? ['id' => (int)$p['id'], 'code' => (string)$p['code'], 'name' => (string)$p['name'], 'status' => (string)$p['status']] : null;
}

/** projects.id → ['id','code','name'] | null */
function docExtProjectById(PDO $pdo, int $id): ?array {
    if ($id <= 0) { return null; }
    $st = $pdo->prepare('SELECT id, code, name, status FROM projects WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $p = $st->fetch();
    return $p ? ['id' => (int)$p['id'], 'code' => (string)$p['code'], 'name' => (string)$p['name'], 'status' => (string)$p['status']] : null;
}
