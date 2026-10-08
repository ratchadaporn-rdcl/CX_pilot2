<?php
/**
 * CONNEXT — lib/setup_master.php : จัดการรหัสวัสดุ — ผูก Mango → LLP → IC + ย้ายยอดสต๊อก
 * (หน้า setup_master.php · api/setup_master_api.php · db/import_llp_master.php)
 *
 * ── ทำไมต้องมี (มติ 40-42) ─────────────────────────────────────────────────
 * มติ 34 บอกว่าของทุกชิ้นต้องอยู่ใต้รหัส IC และรหัส Mango ห้ามถือยอด — แต่ข้อมูลตั้งต้น
 * ที่นำเข้าจากชีตเดิม (Balance / SiteMaterials / RateCard / log เอกสาร) คีย์ด้วยรหัส Mango
 * ทั้งหมด จอนี้คือที่เดียวที่ ADM ใช้ผูกแล้วสั่ง "ย้ายยอด" ทีเดียว ไม่ต้องไล่แก้ใน phpMyAdmin
 *
 * ── การผูกเป็น 2 ขั้น (มติ 42) ──────────────────────────────────────────────
 *   ขั้น 1  Mango → LLP (ตัวสินค้า 8 หลัก)  — มาจากไฟล์ "สร้าง LLP.xlsx" ของฝ่ายจัดซื้อ
 *           นำเข้าทีเดียวหมื่นแถวด้วย db/import_llp_master.php แล้วแก้รายตัวในจอ
 *   ขั้น 2  LLP → IC (20 หลัก = llp + ขนาด + ยี่ห้อ + หน่วย + คุณสมบัติ) — "เติมรหัสตามหลัง"
 *
 * ── กติกาจำนวน (มติ 45 — แก้มติ 42 ที่เคยให้ 1 Mango ผูกหลาย LLP) ────────────────
 *   1 LLP มีได้หลาย Mango · 1 Mango มี LLP ได้ **ตัวเดียว** · 1 LLP ออกได้หลาย IC ·
 *   1 Mango มีได้หลาย IC แต่ทุกตัวต้องอยู่ใต้ LLP ของมัน (ของเดิมรหัสเดียวคลุมหลายสเปก เช่น
 *   ดอกสว่านเจาะปูน 12 มม. → MRO06029 → …120000026000 ปกติ + …120000026001 แบบยาว)
 *   → ผูก LLP ซ้ำ = "เปลี่ยนตัวสินค้า" (smMapLlp) · ผูก IC ที่อยู่ใต้ LLP อื่น = ปฏิเสธ (smAttachIc)
 *
 *   mango_ic_map = 1 แถวต่อ (Mango × IC) ใต้ LLP เดียวกันของ Mango นั้น · ic_code = '' แปลว่ายังอยู่ขั้น 1
 *   is_primary = แถวหลัก: รับบรรทัดเอกสารเดิม/ราคาที่ล็อก และได้ยอดทั้งก้อนโดยปริยาย
 *
 * ── ย้ายยอด ────────────────────────────────────────────────────────────────
 *   · ผูกไป IC เดียว → ยกยอดทั้งก้อน (in/out/คงเหลือตามเดิม)
 *   · ผูกหลาย IC     → ผู้ดูแลระบุยอดแยกต่อ IC (ผลรวมต้องเท่ายอดคงเหลือ) ลงเป็นยอดตั้งต้น
 *   · ยังไม่มี IC (ขั้น 1) → ย้ายไม่ได้ ต้องออกรหัส IC ก่อน
 *   ย้ายแล้วแตะ: project_materials · stock_balances · stock_gate_balances · rate_cards ·
 *   document_items + deduction_doc_rates (→ IC หลัก) แล้ว recalcPending ทั้งหมดในทรานแซกชันเดียว
 *
 * CatID/CharID ไม่ตั้งที่นี่ — เป็นของ LLP (มติ 28-29) จอนี้แค่โชว์/เตือนเมื่อยังไม่ตั้ง
 * ADM (R0) เท่านั้น — งานแตะ master ตามมติ 16 · PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/ic.php';
require_once __DIR__ . '/mango.php';
require_once __DIR__ . '/stock.php';     // recalcPending()

/** vendor_key ที่ใช้จำการผูกจากจอนี้ลง ic_suggest_map — คนละคีย์กับผู้ขายจริง (poVendorKey) */
const SM_SUGGEST_VENDOR = '*setup_master*';

/** ผลรวมยอดแยกต่างจากยอดคงเหลือได้ไม่เกินเท่านี้ (DECIMAL(14,3)) */
const SM_QTY_EPS = 0.0005;

// ═══════════════════════════════════════════════════════════════════════════
// โครงสร้างตาราง — เพิ่มทีหลัง (มติ 40-42) จอตรวจให้เองว่ามีหรือยัง
// ═══════════════════════════════════════════════════════════════════════════

/** ตาราง mango_ic_map พร้อมคอลัมน์ llp_code แล้วหรือยัง */
function smSchemaReady(PDO $pdo): bool {
    if (!$pdo->query("SHOW TABLES LIKE 'mango_ic_map'")->fetch()) { return false; }
    return (bool)$pdo->query("SHOW COLUMNS FROM mango_ic_map LIKE 'llp_code'")->fetch();
}

/**
 * สร้าง/อัปเกรดตาราง mango_ic_map — รันซ้ำได้
 * รองรับ 2 รุ่นก่อนหน้า: คอลัมน์ materials.ic_code (1:1) และตารางที่ยังไม่มี llp_code
 * ไม่ผูก FK ไป materials/ic_items/llp_products โดยตั้งใจ — ทั้งระบบบังคับกติกาที่ชั้น PHP
 * (ic_suggest_map / push_allocs ก็ไม่มี FK เหมือนกัน)
 * @return string[] คำสั่งที่รันไป (ว่าง = ครบอยู่แล้ว)
 */
function smSchemaUpgrade(PDO $pdo): array {
    $done = [];
    $hasTable = (bool)$pdo->query("SHOW TABLES LIKE 'mango_ic_map'")->fetch();
    if (!$hasTable) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS mango_ic_map (
  id         INT NOT NULL AUTO_INCREMENT,
  mat_code   VARCHAR(50) NOT NULL,
  llp_code   CHAR(8)     NOT NULL DEFAULT '',
  ic_code    CHAR(20)    NOT NULL DEFAULT '',
  is_primary TINYINT(1)  NOT NULL DEFAULT 0,
  mapped_by  INT NULL,
  mapped_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mim (mat_code, llp_code, ic_code),
  KEY idx_mim_llp (llp_code),
  KEY idx_mim_ic (ic_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $done[] = 'CREATE TABLE mango_ic_map';
    } elseif (!$pdo->query("SHOW COLUMNS FROM mango_ic_map LIKE 'llp_code'")->fetch()) {
        // รุ่นแรก: มีแค่ (mat_code, ic_code) — เติม llp_code จาก 8 ตัวแรกของ IC
        $pdo->exec("ALTER TABLE mango_ic_map ADD COLUMN llp_code CHAR(8) NOT NULL DEFAULT '' AFTER mat_code");
        $pdo->exec("UPDATE mango_ic_map SET llp_code = LEFT(ic_code, 8) WHERE ic_code <> ''");
        $pdo->exec("ALTER TABLE mango_ic_map DROP INDEX uq_mim, ADD UNIQUE KEY uq_mim (mat_code, llp_code, ic_code)");
        $pdo->exec("ALTER TABLE mango_ic_map ADD KEY idx_mim_llp (llp_code)");
        $done[] = 'ALTER mango_ic_map ADD llp_code';
    }

    // รุ่นก่อนหน้าเก็บการผูกไว้ที่คอลัมน์ของ materials — ย้ายเข้าตารางแล้วถอดคอลัมน์
    $has = function (string $col) use ($pdo): bool {
        return (bool)$pdo->query('SHOW COLUMNS FROM materials LIKE ' . $pdo->quote($col))->fetch();
    };
    if ($has('ic_code')) {
        $pdo->exec("INSERT IGNORE INTO mango_ic_map (mat_code, llp_code, ic_code, is_primary, mapped_by, mapped_at)
                    SELECT mat_code, LEFT(ic_code, 8), ic_code, 1, ic_mapped_by, COALESCE(ic_mapped_at, NOW())
                      FROM materials WHERE code_type = 'mango' AND ic_code IS NOT NULL");
        $pdo->exec('ALTER TABLE materials DROP COLUMN ic_code');
        $done[] = 'copy materials.ic_code → mango_ic_map + DROP COLUMN';
    }
    foreach (['ic_mapped_by', 'ic_mapped_at'] as $c) {
        if ($has($c)) { $pdo->exec("ALTER TABLE materials DROP COLUMN $c"); $done[] = "ALTER TABLE materials DROP COLUMN $c"; }
    }
    return $done;
}

// ═══════════════════════════════════════════════════════════════════════════
// อ่านข้อมูล
// ═══════════════════════════════════════════════════════════════════════════

/** บันทึกลง activity_log — ล้มเหลวห้ามลากงานหลักล้ม (คู่กับ mangoLog/admLog) */
function smLog(PDO $pdo, ?array $user, $id, string $act, $old, $new): void {
    mangoLog($pdo, $user, $id, $act, $old, $new);
}

/**
 * เงื่อนไขกรองของจอ — ใช้ร่วมกันระหว่างตัวอ่านแถวกับตัวนับ
 *   q       รหัส Mango / ชื่อ / กลุ่มย่อย / รหัส LLP / รหัส IC
 *   state   'no_llp' ยังไม่ผูก LLP · 'llp_only' ผูก LLP แล้วแต่ยังไม่มี IC ·
 *           'has_ic' มี IC แล้ว · 'multi_ic' มีมากกว่า 1 IC ·
 *           'multi_llp' ผูกไว้หลาย LLP (ผิดมติ 45 — ข้อมูลรุ่นก่อน ต้องเลือกให้เหลือตัวเดียว)
 *   scope   'stock' เฉพาะที่ถือยอด/อยู่ในโครงการ · 'doc' เคยอยู่ในเอกสาร · อื่น = ทุกรหัส
 *   l1      กรองตามกลุ่มใหญ่ของ LLP ที่ผูก
 *   project ถ้าระบุ — ยอด/สถานะดูเฉพาะโครงการนั้น
 */
function smWhere(array $f): array {
    $w    = ["m.code_type = 'mango'"];
    $args = [];
    $proj = (int)($f['project'] ?? 0);

    if (!empty($f['q'])) {
        $like = '%' . likeEscape((string)$f['q']) . '%';
        $w[]  = '(m.mat_code LIKE ? OR m.name LIKE ? OR m.subgroup_name LIKE ?
                  OR EXISTS (SELECT 1 FROM mango_ic_map x WHERE x.mat_code = m.mat_code
                              AND (x.llp_code LIKE ? OR x.ic_code LIKE ?)))';
        array_push($args, $like, $like, $like, $like, $like);
    }
    $nLlp = '(SELECT COUNT(DISTINCT x.llp_code) FROM mango_ic_map x WHERE x.mat_code = m.mat_code)';
    $nIc  = "(SELECT COUNT(*) FROM mango_ic_map x WHERE x.mat_code = m.mat_code AND x.ic_code <> '')";
    $st   = (string)($f['state'] ?? '');
    if ($st === 'no_llp')   { $w[] = $nLlp . ' = 0'; }
    if ($st === 'llp_only') { $w[] = $nLlp . ' > 0 AND ' . $nIc . ' = 0'; }
    if ($st === 'has_ic')   { $w[] = $nIc . ' > 0'; }
    if ($st === 'multi_ic') { $w[] = $nIc . ' > 1'; }
    if ($st === 'multi_llp') { $w[] = $nLlp . ' > 1'; }

    if (!empty($f['l1'])) {
        $w[]    = 'EXISTS (SELECT 1 FROM mango_ic_map x WHERE x.mat_code = m.mat_code AND LEFT(x.llp_code, 3) = ?)';
        $args[] = (string)$f['l1'];
    }

    $pw = $proj > 0 ? ' AND x.project_id = ' . $proj : '';
    if (($f['scope'] ?? '') === 'stock') {
        $w[] = '(EXISTS (SELECT 1 FROM stock_balances x WHERE x.material_id = m.id' . $pw . ')
              OR EXISTS (SELECT 1 FROM project_materials x WHERE x.material_id = m.id' . $pw . '))';
    } elseif (($f['scope'] ?? '') === 'doc') {
        $w[] = 'EXISTS (SELECT 1 FROM document_items x JOIN documents d ON d.id = x.document_id
                         WHERE x.material_id = m.id' . ($proj > 0 ? ' AND d.project_id = ' . $proj : '') . ')';
    }
    return [implode(' AND ', $w), $args];
}

/** แถวทะเบียน Mango + ยอดที่ยังค้างบน id ของ Mango · แต่ละแถวมี 'llps' = LLP ที่ผูก (พร้อม IC ใต้มัน) */
function smRows(PDO $pdo, array $f = []): array {
    list($where, $args) = smWhere($f);
    $proj = (int)($f['project'] ?? 0);
    $pw   = $proj > 0 ? ' WHERE project_id = ' . $proj : '';

    $sql = 'SELECT m.id, m.mat_code, m.name, m.unit, m.subgroup_name,
                   COALESCE(sb.on_hand, 0) AS on_hand, COALESCE(sb.pending, 0) AS pending,
                   COALESCE(sb.n, 0) AS n_bal, COALESCE(pm.n, 0) AS n_pm
              FROM materials m
              LEFT JOIN (SELECT material_id, SUM(on_hand) on_hand, SUM(pending) pending, COUNT(*) n
                           FROM stock_balances' . $pw . ' GROUP BY material_id) sb ON sb.material_id = m.id
              LEFT JOIN (SELECT material_id, COUNT(*) n
                           FROM project_materials' . $pw . ' GROUP BY material_id) pm ON pm.material_id = m.id
             WHERE ' . $where . '
             ORDER BY (sb.material_id IS NULL AND pm.material_id IS NULL), m.mat_code';
    if (!empty($f['limit'])) {
        $sql .= ' LIMIT ' . (int)$f['limit'] . ' OFFSET ' . (int)($f['offset'] ?? 0);
    }
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();

    $lists = smMapListMany($pdo, array_column($rows, 'mat_code'));
    foreach ($rows as &$r) {
        $r['llps'] = $lists[(string)$r['mat_code']] ?? [];
        $r['n_ic'] = 0;
        foreach ($r['llps'] as $l) { $r['n_ic'] += count($l['ics']); }
    }
    unset($r);
    return $rows;
}

function smCount(PDO $pdo, array $f = []): int {
    list($where, $args) = smWhere($f);
    $st = $pdo->prepare('SELECT COUNT(*) FROM materials m WHERE ' . $where);
    $st->execute($args);
    return (int)$st->fetchColumn();
}

/**
 * การผูกของ Mango หลายตัวในครั้งเดียว → [mat_code => [ LLP block, ... ]]
 * LLP block: llp_code, llp_name, l1_code/l1_name, l2_code/l2_name, cat_id, char_id, is_set,
 *            missing (ไม่มีใน llp_products), is_primary (LLP ที่มีแถวหลัก), ics[]
 * ic:        ic_code, ic_name, unit_name, is_active, is_primary, ic_material_id, missing
 */
function smMapListMany(PDO $pdo, array $matCodes): array {
    $matCodes = array_values(array_unique(array_filter(array_map('strval', $matCodes))));
    if (!$matCodes) { return []; }
    $ph = implode(',', array_fill(0, count($matCodes), '?'));
    $st = $pdo->prepare(
        "SELECT x.mat_code, x.llp_code, x.ic_code, x.is_primary, x.mapped_at,
                p.llp_name, p.cat_id, p.char_id, p.is_active AS llp_active,
                g.l1_name, c.l2_name,
                i.ic_name, i.is_active AS ic_active, u.unit_name,
                mi.id AS ic_material_id
           FROM mango_ic_map x
           LEFT JOIN llp_products p  ON p.llp_code = x.llp_code
           LEFT JOIN l1_groups g     ON g.l1_code  = p.l1_code
           LEFT JOIN l2_categories c ON c.l1_code  = p.l1_code AND c.l2_code = p.l2_code
           LEFT JOIN ic_items i      ON i.ic_code  = x.ic_code AND x.ic_code <> ''
           LEFT JOIN units u         ON u.unit_code = i.unit_code
           LEFT JOIN materials mi    ON mi.mat_code = x.ic_code AND mi.code_type = 'ic'
          WHERE x.mat_code IN ($ph)
          ORDER BY x.mat_code, x.is_primary DESC, x.llp_code, x.ic_code"
    );
    $st->execute($matCodes);

    $out = [];
    foreach ($st->fetchAll() as $r) {
        $mat = (string)$r['mat_code'];
        $llp = (string)$r['llp_code'];
        if (!isset($out[$mat][$llp])) {
            $cat = icNormalizeCat($r['cat_id']);
            $chr = icNormalizeChar($r['char_id']);
            $out[$mat][$llp] = [
                'llp_code'   => $llp,
                'llp_name'   => (string)($r['llp_name'] ?? ''),
                'l1_code'    => substr($llp, 0, 3),
                'l2_code'    => substr($llp, 3, 2),
                'l1_name'    => (string)($r['l1_name'] ?? ''),
                'l2_name'    => (string)($r['l2_name'] ?? ''),
                'cat_id'     => $cat,
                'char_id'    => $chr,
                'is_set'     => $cat !== null && $chr !== null,
                'is_active'  => $r['llp_active'] !== null ? (int)$r['llp_active'] : 0,
                'missing'    => $r['llp_name'] === null,
                'is_primary' => false,
                'ics'        => [],
            ];
        }
        if ((string)$r['ic_code'] !== '') {
            $out[$mat][$llp]['ics'][] = [
                'llp_code'       => $llp,
                'ic_code'        => (string)$r['ic_code'],
                'ic_name'        => (string)($r['ic_name'] ?? ''),
                'unit_name'      => (string)($r['unit_name'] ?? ''),
                'is_active'      => $r['ic_active'] !== null ? (int)$r['ic_active'] : 0,
                'is_primary'     => (int)$r['is_primary'] === 1,
                'ic_material_id' => $r['ic_material_id'] !== null ? (int)$r['ic_material_id'] : 0,
                'missing'        => $r['ic_name'] === null,
            ];
        }
        if ((int)$r['is_primary'] === 1) { $out[$mat][$llp]['is_primary'] = true; }
    }
    foreach ($out as $mat => $blocks) { $out[$mat] = array_values($blocks); }
    return $out;
}

/** การผูกของ Mango ตัวเดียว */
function smMapList(PDO $pdo, string $matCode): array {
    $code = mangoNormalizeCode($matCode);
    $all  = smMapListMany($pdo, [$code]);
    return $all[$code] ?? [];
}

/** IC ทุกตัวของ Mango (แบน — ใช้ตอนย้ายยอด) */
function smIcsOf(array $llpBlocks): array {
    $out = [];
    foreach ($llpBlocks as $b) { foreach ($b['ics'] as $ic) { $out[] = $ic; } }
    return $out;
}

/** ตัวเลขสรุปบนหัวจอ */
function smStats(PDO $pdo, int $projectId = 0): array {
    $pw = $projectId > 0 ? ' AND x.project_id = ' . $projectId : '';
    $r = $pdo->query(
        "SELECT COUNT(*) AS total,
                SUM(h.n_llp > 0) AS with_llp,
                SUM(h.n_ic > 0)  AS with_ic,
                SUM(h.n_ic > 1)  AS multi_ic,
                SUM(h.n_llp > 1) AS multi_llp,
                SUM(h.has) AS with_stock,
                SUM(h.has AND h.n_llp > 0) AS stock_llp,
                SUM(h.has AND h.n_ic > 0)  AS stock_ic
           FROM materials m
           JOIN (SELECT m2.id,
                        (EXISTS (SELECT 1 FROM stock_balances x WHERE x.material_id = m2.id$pw)
                      OR EXISTS (SELECT 1 FROM project_materials x WHERE x.material_id = m2.id$pw)) AS has,
                        (SELECT COUNT(DISTINCT x.llp_code) FROM mango_ic_map x WHERE x.mat_code = m2.mat_code) AS n_llp,
                        (SELECT COUNT(*) FROM mango_ic_map x WHERE x.mat_code = m2.mat_code AND x.ic_code <> '') AS n_ic
                   FROM materials m2 WHERE m2.code_type = 'mango') h ON h.id = m.id
          WHERE m.code_type = 'mango'"
    )->fetch();

    $pw2 = $projectId > 0 ? ' AND b.project_id = ' . $projectId : '';
    $bal = $pdo->query(
        "SELECT COUNT(*) AS rows_on_mango, COALESCE(SUM(b.on_hand), 0) AS on_hand_on_mango
           FROM stock_balances b JOIN materials m ON m.id = b.material_id
          WHERE m.code_type = 'mango'$pw2"
    )->fetch();

    return [
        'total'            => (int)$r['total'],
        'with_llp'         => (int)$r['with_llp'],
        'with_ic'          => (int)$r['with_ic'],
        'multi_ic'         => (int)$r['multi_ic'],
        'multi_llp'        => (int)$r['multi_llp'],      // ผิดมติ 45 — ควรเป็น 0 เสมอ
        'no_llp'           => (int)$r['total'] - (int)$r['with_llp'],
        'with_stock'       => (int)$r['with_stock'],
        'stock_llp'        => (int)$r['stock_llp'],
        'stock_ic'         => (int)$r['stock_ic'],
        'stock_no_llp'     => (int)$r['with_stock'] - (int)$r['stock_llp'],
        'rows_on_mango'    => (int)$bal['rows_on_mango'],
        'on_hand_on_mango' => (float)$bal['on_hand_on_mango'],
        'llp_total'        => (int)$pdo->query('SELECT COUNT(*) FROM llp_products')->fetchColumn(),
        'llp_unset'        => (int)$pdo->query('SELECT COUNT(*) FROM llp_products WHERE cat_id IS NULL OR char_id IS NULL')->fetchColumn(),
        'ic_total'         => (int)$pdo->query('SELECT COUNT(*) FROM ic_items')->fetchColumn(),
        'l1_total'         => (int)$pdo->query('SELECT COUNT(*) FROM l1_groups')->fetchColumn(),
        'l2_total'         => (int)$pdo->query('SELECT COUNT(*) FROM l2_categories')->fetchColumn(),
    ];
}

/** ค้นตัวสินค้า (LLP) สำหรับตัวเลือกในจอ — คืนพร้อมจำนวน IC ที่ออกแล้วและจำนวน Mango ที่ผูกอยู่ */
function smLlpSearch(PDO $pdo, string $q, string $l1 = '', int $limit = 40): array {
    $w = ['p.is_active = 1']; $a = [];
    if (trim($q) !== '') {
        $like = '%' . likeEscape(trim($q)) . '%';
        $w[]  = '(p.llp_name LIKE ? OR p.llp_code LIKE ?)';
        array_push($a, $like, $like);
    }
    if ($l1 !== '') { $w[] = 'p.l1_code = ?'; $a[] = $l1; }
    $st = $pdo->prepare(
        'SELECT p.llp_code, p.llp_name, p.cat_id, p.char_id, g.l1_name, c.l2_name,
                (SELECT COUNT(*) FROM ic_items i WHERE i.llp_code = p.llp_code) AS n_ic,
                (SELECT COUNT(DISTINCT x.mat_code) FROM mango_ic_map x WHERE x.llp_code = p.llp_code) AS n_mango
           FROM llp_products p
           JOIN l1_groups g     ON g.l1_code = p.l1_code
           JOIN l2_categories c ON c.l1_code = p.l1_code AND c.l2_code = p.l2_code
          WHERE ' . implode(' AND ', $w) . '
          ORDER BY (p.llp_name LIKE ?) DESC, p.llp_code
          LIMIT ' . (int)$limit
    );
    $a[] = (trim($q) !== '' ? likeEscape(trim($q)) : '') . '%';
    $st->execute($a);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['cat_id'] = icNormalizeCat($r['cat_id']);
        $r['char_id'] = icNormalizeChar($r['char_id']);
        $r['is_set'] = $r['cat_id'] !== null && $r['char_id'] !== null;
    }
    unset($r);
    return $rows;
}

/** LLP หนึ่งตัวพร้อมชื่อชั้น — คืน null ถ้าไม่มี */
function smLlpInfo(PDO $pdo, string $llp): ?array {
    $st = $pdo->prepare(
        'SELECT p.llp_code, p.llp_name, p.l1_code, p.l2_code, p.cat_id, p.char_id, p.is_active,
                g.l1_name, c.l2_name
           FROM llp_products p
           JOIN l1_groups g     ON g.l1_code = p.l1_code
           JOIN l2_categories c ON c.l1_code = p.l1_code AND c.l2_code = p.l2_code
          WHERE p.llp_code = ?'
    );
    $st->execute([strtoupper(trim($llp))]);
    $r = $st->fetch();
    if (!$r) { return null; }
    $r['cat_id']  = icNormalizeCat($r['cat_id']);
    $r['char_id'] = icNormalizeChar($r['char_id']);
    $r['is_set']  = $r['cat_id'] !== null && $r['char_id'] !== null;
    return $r;
}

/** ข้อมูล IC หนึ่งตัวพร้อม LLP/CatID/CharID — คืน null ถ้าไม่มี */
function smIcInfo(PDO $pdo, string $ic): ?array {
    $st = $pdo->prepare(
        'SELECT i.ic_code, i.ic_name, i.is_active, i.llp_code, u.unit_name,
                p.llp_name, p.cat_id, p.char_id, g.l1_name, c.l2_name
           FROM ic_items i
           JOIN units u         ON u.unit_code = i.unit_code
           JOIN llp_products p  ON p.llp_code  = i.llp_code
           JOIN l1_groups g     ON g.l1_code   = i.l1_code
           JOIN l2_categories c ON c.l1_code   = i.l1_code AND c.l2_code = i.l2_code
          WHERE i.ic_code = ?'
    );
    $st->execute([strtoupper(trim($ic))]);
    $r = $st->fetch();
    if (!$r) { return null; }
    $r['cat_id']  = icNormalizeCat($r['cat_id']);
    $r['char_id'] = icNormalizeChar($r['char_id']);
    $r['is_set']  = $r['cat_id'] !== null && $r['char_id'] !== null;
    return $r;
}

/** หน่วยของ Mango กับหน่วยเก็บของ IC ถือว่าตรงกันไหม (เทียบหลังตัดช่องว่าง/ตัวพิมพ์) */
function smUnitSame(string $mangoUnit, string $icUnit): bool {
    $n = function (string $s): string {
        return mb_strtolower(preg_replace('/\s+/u', '', trim($s)), 'UTF-8');
    };
    $a = $n($mangoUnit);
    $b = $n($icUnit);
    return $a === '' || $b === '' || $a === $b;
}

// ═══════════════════════════════════════════════════════════════════════════
// ขั้น 1 — ผูก Mango → LLP
// ═══════════════════════════════════════════════════════════════════════════

/** จำการผูกทั้งชุดของ Mango ลง ic_suggest_map (เฉพาะแถวที่มี IC แล้ว) */
function smRememberSuggest(PDO $pdo, string $matCode): void {
    try {
        $pdo->prepare('DELETE FROM ic_suggest_map WHERE vendor_key = ? AND mat_code = ?')
            ->execute([SM_SUGGEST_VENDOR, $matCode]);
        $st = $pdo->prepare("SELECT ic_code FROM mango_ic_map WHERE mat_code = ? AND ic_code <> '' ORDER BY is_primary DESC, id");
        $st->execute([$matCode]);
        $ins = $pdo->prepare(
            'INSERT INTO ic_suggest_map (vendor_key, mat_code, ic_code) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE last_used = CURRENT_TIMESTAMP'
        );
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $ic) { $ins->execute([SM_SUGGEST_VENDOR, $matCode, (string)$ic]); }
    } catch (Throwable $e) {
        error_log('smRememberSuggest: ' . $e->getMessage());   // ความจำเสริม พลาดห้ามล้มงานหลัก
    }
}

/** ไม่มีแถวหลักเหลือ → ให้แถวแรกเป็นหลัก (เรียกหลังลบ/แก้) */
function smFixPrimary(PDO $pdo, string $matCode): void {
    $st = $pdo->prepare('SELECT id FROM mango_ic_map WHERE mat_code = ? ORDER BY (ic_code <> "") DESC, is_primary DESC, id LIMIT 1');
    $st->execute([$matCode]);
    $first = $st->fetchColumn();
    if ($first !== false) {
        $pdo->prepare('UPDATE mango_ic_map SET is_primary = (id = ?) WHERE mat_code = ?')->execute([(int)$first, $matCode]);
    }
}

/**
 * ผูก/เปลี่ยนตัวสินค้า (LLP) ของ Mango — ขั้น 1 · 1 Mango มี LLP ได้ตัวเดียว (มติ 45)
 *   ยังไม่ผูก       → ผูกเป็นแถวขั้น 1
 *   ผูกตัวเดียวกัน   → ไม่ทำอะไร ไม่ error
 *   ผูกตัวอื่นอยู่    → เปลี่ยน: แถวของ LLP เดิมถูกลบทั้งหมด (รวม IC ใต้มัน)
 *                     ถ้ามี IC จะหลุดไปด้วย ต้องส่ง $replace = true (ยืนยันแล้ว) ไม่งั้นปฏิเสธพร้อมบอกว่าจะหลุดอะไร
 *                     ยอดที่ย้ายไป IC เดิมแล้วไม่ถูกแตะ — ยังอยู่ที่ IC นั้น
 * @return array ['ok','error','changed','llp'=>info|null,'mat'=>แถว materials,'llps'=>รายการหลังผูก,
 *                'old'=>LLP เดิมที่ถูกแทน[], 'dropped'=>IC ที่หลุด[], 'need_confirm'=>bool]
 */
function smMapLlp(PDO $pdo, string $matCode, string $llpCode, ?array $user = null, bool $replace = false): array {
    $fail = function (string $m, array $extra = []) {
        return array_merge(['ok' => false, 'error' => $m, 'changed' => false, 'llp' => null, 'mat' => null, 'llps' => [],
                            'old' => [], 'dropped' => [], 'need_confirm' => false], $extra);
    };
    $code = mangoNormalizeCode($matCode);
    $mat  = mangoGet($pdo, $code);
    if ($mat === null) { return $fail('ไม่พบรหัส Mango ' . $code . ' ในทะเบียน (หรือรหัสนี้เป็น IC)'); }

    $llp  = strtoupper(trim($llpCode));
    if (strlen($llp) !== 8) { return $fail('รหัสตัวสินค้า (LLP) ต้องยาว 8 ตัวอักษร (ได้ "' . $llp . '")'); }
    $info = smLlpInfo($pdo, $llp);
    if ($info === null)                { return $fail('ไม่พบตัวสินค้า (LLP) รหัส ' . $llp); }
    if ((int)$info['is_active'] !== 1) { return $fail('ตัวสินค้า ' . $llp . ' ถูกปิดใช้งานแล้ว'); }

    $uid   = $user !== null ? ((int)($user['accountId'] ?? 0) ?: null) : null;
    $old   = [];
    $drop  = [];
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) { $pdo->beginTransaction(); }
    try {
        $st = $pdo->prepare('SELECT llp_code, ic_code FROM mango_ic_map WHERE mat_code = ? ORDER BY id FOR UPDATE');
        $st->execute([$code]);
        $cur = $st->fetchAll();
        foreach ($cur as $r) {
            if ((string)$r['llp_code'] === $llp) { continue; }
            $old[(string)$r['llp_code']] = true;
            if ((string)$r['ic_code'] !== '') { $drop[] = (string)$r['ic_code']; }
        }
        $old = array_keys($old);
        $has = false;                                        // มีแถวของ LLP นี้อยู่แล้ว (เก็บ IC ใต้มันไว้)
        foreach ($cur as $r) { if ((string)$r['llp_code'] === $llp) { $has = true; break; } }

        if ($drop && !$replace) {
            if ($ownTx) { $pdo->rollBack(); }
            return $fail($code . ' ผูกตัวสินค้า ' . implode(', ', $old) . ' อยู่ และมี IC ' . count($drop) . ' ตัว ('
                       . implode(', ', $drop) . ') — 1 Mango มีตัวสินค้าได้ตัวเดียว ถ้าเปลี่ยนเป็น ' . $llp
                       . ' IC เหล่านี้จะถูกถอดออก (ยอดที่ย้ายไปแล้วยังอยู่ที่ IC เดิม) — ต้องยืนยันการเปลี่ยนก่อน',
                         ['need_confirm' => true, 'old' => $old, 'dropped' => $drop, 'llp' => $info, 'mat' => $mat]);
        }

        $changed = false;
        if ($old) {
            $pdo->prepare('DELETE FROM mango_ic_map WHERE mat_code = ? AND llp_code <> ?')->execute([$code, $llp]);
            $changed = true;
        }
        if (!$has) {
            $pdo->prepare("INSERT INTO mango_ic_map (mat_code, llp_code, ic_code, is_primary, mapped_by) VALUES (?,?,'',1,?)")
                ->execute([$code, $llp, $uid]);
            $changed = true;
        }
        if ($changed) {
            smFixPrimary($pdo, $code);
            if ($drop) { smRememberSuggest($pdo, $code); }
            smLog($pdo, $user, (int)$mat['id'], $old ? 'llp_change' : 'llp_map',
                ['mat_code' => $code, 'llp_code' => $old ? implode(',', $old) : '', 'dropped_ic' => $drop],
                ['mat_code' => $code, 'llp_code' => $llp, 'llp_name' => (string)$info['llp_name']]);
        }
        if ($ownTx) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('smMapLlp ' . $code . ': ' . $e->getMessage());
        return $fail('บันทึกการผูกไม่สำเร็จ: ' . $e->getMessage());
    }
    return ['ok' => true, 'error' => '', 'changed' => $changed, 'llp' => $info, 'mat' => $mat, 'llps' => smMapList($pdo, $code),
            'old' => $old, 'dropped' => $drop, 'need_confirm' => false];
}

/** LLP ที่ Mango ผูกอยู่ (ปกติ 0 หรือ 1 ตัว — มากกว่านั้นคือข้อมูลรุ่นก่อนมติ 45) */
function smLlpsOf(PDO $pdo, string $matCode, bool $lock = false): array {
    $st = $pdo->prepare('SELECT llp_code FROM mango_ic_map WHERE mat_code = ? ORDER BY id' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([mangoNormalizeCode($matCode)]);
    return array_values(array_unique(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN))));
}

/** ถอด LLP (พร้อม IC ใต้มัน) ออกจาก Mango — ไม่แตะยอดที่ย้ายไปแล้ว */
function smUnmapLlp(PDO $pdo, string $matCode, string $llpCode, ?array $user = null): array {
    $code = mangoNormalizeCode($matCode);
    $llp  = strtoupper(trim($llpCode));
    $mat  = mangoGet($pdo, $code);
    if ($mat === null) { return ['ok' => false, 'error' => 'ไม่พบรหัส Mango ' . $code, 'changed' => false, 'llps' => []]; }

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) { $pdo->beginTransaction(); }
    try {
        if ($llp === '') {
            $st = $pdo->prepare('DELETE FROM mango_ic_map WHERE mat_code = ?');
            $st->execute([$code]);
        } else {
            $st = $pdo->prepare('DELETE FROM mango_ic_map WHERE mat_code = ? AND llp_code = ?');
            $st->execute([$code, $llp]);
        }
        $changed = $st->rowCount() > 0;
        if ($changed) {
            smFixPrimary($pdo, $code);
            smRememberSuggest($pdo, $code);
            smLog($pdo, $user, (int)$mat['id'], 'llp_unmap', ['mat_code' => $code, 'llp_code' => $llp === '' ? '*' : $llp], null);
        }
        if ($ownTx) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
        return ['ok' => false, 'error' => 'ถอดการผูกไม่สำเร็จ: ' . $e->getMessage(), 'changed' => false, 'llps' => []];
    }
    return ['ok' => true, 'error' => '', 'changed' => $changed, 'llps' => smMapList($pdo, $code)];
}

// ═══════════════════════════════════════════════════════════════════════════
// ขั้น 2 — ผูก IC ที่ออกแล้วเข้ากับ Mango (ใต้ LLP ของมัน)
// ═══════════════════════════════════════════════════════════════════════════

/** ตรวจว่า IC ตัวนี้ผูกได้ไหม — คืน ['ok','error','ic'=>info] */
function smCheckIc(PDO $pdo, string $ic): array {
    $ic = strtoupper(trim($ic));
    if (strlen($ic) !== 20) {
        return ['ok' => false, 'error' => 'รหัส IC ต้องยาว 20 ตัวอักษร (ได้ "' . $ic . '")', 'ic' => null];
    }
    $info = smIcInfo($pdo, $ic);
    if ($info === null)                { return ['ok' => false, 'error' => 'ไม่พบรหัส IC ' . $ic . ' ในทะเบียน', 'ic' => null]; }
    if ((int)$info['is_active'] !== 1) { return ['ok' => false, 'error' => 'รหัส IC ' . $ic . ' ถูกปิดใช้งานแล้ว', 'ic' => $info]; }
    if (!$info['is_set']) {
        return ['ok' => false, 'ic' => $info,
                'error' => 'ตัวสินค้า ' . $info['llp_code'] . ' (' . $info['llp_name'] . ') ยังไม่ตั้งหมวดอนุมัติ/ลักษณะวัสดุ '
                         . '— ตั้งที่หน้า "ตั้งค่าตัวสินค้า (LLP)" ก่อน ไม่งั้นผูกไปก็ไม่โผล่ในฟอร์มเบิก'];
    }
    return ['ok' => true, 'error' => '', 'ic' => $info];
}

/**
 * ผูก IC เข้ากับ Mango — LLP ของ IC ถูกผูกให้อัตโนมัติถ้ายังไม่ได้ผูก
 * แถวขั้น 1 ของ LLP เดียวกัน (ic_code='') จะถูกอัปเกรดเป็นแถวนี้แทนการเพิ่มแถวใหม่
 * ผูกเพิ่มได้หลาย IC แต่ต้องอยู่ใต้ LLP เดียวกับที่ Mango ผูกอยู่ (มติ 45) — IC ใต้ LLP อื่น = ปฏิเสธ
 * @return array ['ok','error','changed','ic'=>info,'mat'=>แถว materials,'llps'=>รายการหลังผูก]
 */
function smAttachIc(PDO $pdo, string $matCode, string $icCode, ?array $user = null, bool $asPrimary = false): array {
    $fail = function (string $m, $ic = null) {
        return ['ok' => false, 'error' => $m, 'changed' => false, 'ic' => $ic, 'mat' => null, 'llps' => []];
    };
    $code = mangoNormalizeCode($matCode);
    $mat  = mangoGet($pdo, $code);
    if ($mat === null) { return $fail('ไม่พบรหัส Mango ' . $code . ' ในทะเบียน (หรือรหัสนี้เป็น IC)'); }

    $chk = smCheckIc($pdo, $icCode);
    if (!$chk['ok']) { return $fail($chk['error'], $chk['ic']); }
    $ic  = $chk['ic'];
    $llp = (string)$ic['llp_code'];

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) { $pdo->beginTransaction(); }
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM mango_ic_map WHERE mat_code = ? FOR UPDATE');
        $st->execute([$code]);
        $nBefore = (int)$st->fetchColumn();

        // 1 Mango = 1 LLP (มติ 45) — ผูก LLP อื่นไว้แล้วต้องเปลี่ยนตัวสินค้าก่อน ไม่ผูกข้ามให้
        $curLlps = smLlpsOf($pdo, $code);
        if ($curLlps && !in_array($llp, $curLlps, true)) {
            if ($ownTx) { $pdo->rollBack(); }
            return $fail('IC ' . (string)$ic['ic_code'] . ' อยู่ใต้ตัวสินค้า ' . $llp . ' (' . (string)$ic['llp_name'] . ') แต่ '
                       . $code . ' ผูกตัวสินค้า ' . implode(', ', $curLlps) . ' อยู่ — 1 Mango มีตัวสินค้าได้ตัวเดียว '
                       . '(ถ้าจะใช้ IC นี้ ให้เปลี่ยนตัวสินค้าของ ' . $code . ' เป็น ' . $llp . ' ก่อน)', $ic);
        }

        // มีแถวขั้น 1 ของ LLP นี้อยู่ → อัปเกรดแถวนั้น
        $st = $pdo->prepare("SELECT id, is_primary FROM mango_ic_map WHERE mat_code = ? AND llp_code = ? AND ic_code = ''");
        $st->execute([$code, $llp]);
        $stage1 = $st->fetch();

        if ($stage1) {
            $up = $pdo->prepare('UPDATE mango_ic_map SET ic_code = ?, mapped_by = ?, mapped_at = NOW() WHERE id = ?');
            $up->execute([(string)$ic['ic_code'], $user !== null ? ((int)($user['accountId'] ?? 0) ?: null) : null, (int)$stage1['id']]);
            $changed = true;
        } else {
            $ins = $pdo->prepare('INSERT IGNORE INTO mango_ic_map (mat_code, llp_code, ic_code, is_primary, mapped_by) VALUES (?,?,?,?,?)');
            $ins->execute([$code, $llp, (string)$ic['ic_code'], $nBefore === 0 ? 1 : 0,
                           $user !== null ? ((int)($user['accountId'] ?? 0) ?: null) : null]);
            $changed = $ins->rowCount() > 0;
        }

        if ($asPrimary) {
            $pdo->prepare('UPDATE mango_ic_map SET is_primary = (ic_code = ?) WHERE mat_code = ?')
                ->execute([(string)$ic['ic_code'], $code]);
        }
        if ($changed) {
            smFixPrimary($pdo, $code);
            smRememberSuggest($pdo, $code);
            smLog($pdo, $user, (int)$mat['id'], 'ic_map',
                ['mat_code' => $code, 'n_before' => $nBefore],
                ['mat_code' => $code, 'llp_code' => $llp, 'ic_code' => (string)$ic['ic_code'], 'ic_name' => (string)$ic['ic_name']]);
        }
        if ($ownTx) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('smAttachIc ' . $code . ': ' . $e->getMessage());
        return $fail('บันทึกการผูกไม่สำเร็จ: ' . $e->getMessage(), $ic);
    }
    return ['ok' => true, 'error' => '', 'changed' => $changed, 'ic' => $ic, 'mat' => $mat, 'llps' => smMapList($pdo, $code)];
}

/**
 * ถอด IC ออก แต่คง LLP ไว้ (กลับไปขั้น 1) — ถ้า LLP นั้นมีแถวขั้น 1 อยู่แล้วให้ลบแถวนี้ทิ้ง
 */
function smDetachIc(PDO $pdo, string $matCode, string $icCode, ?array $user = null): array {
    $code = mangoNormalizeCode($matCode);
    $ic   = strtoupper(trim($icCode));
    $mat  = mangoGet($pdo, $code);
    if ($mat === null) { return ['ok' => false, 'error' => 'ไม่พบรหัส Mango ' . $code, 'changed' => false, 'llps' => []]; }

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) { $pdo->beginTransaction(); }
    try {
        $st = $pdo->prepare('SELECT id, llp_code FROM mango_ic_map WHERE mat_code = ? AND ic_code = ? FOR UPDATE');
        $st->execute([$code, $ic]);
        $row = $st->fetch();
        $changed = false;
        if ($row) {
            $ex = $pdo->prepare("SELECT COUNT(*) FROM mango_ic_map WHERE mat_code = ? AND llp_code = ? AND ic_code = ''");
            $ex->execute([$code, (string)$row['llp_code']]);
            if ((int)$ex->fetchColumn() > 0) {
                $pdo->prepare('DELETE FROM mango_ic_map WHERE id = ?')->execute([(int)$row['id']]);
            } else {
                $pdo->prepare("UPDATE mango_ic_map SET ic_code = '' WHERE id = ?")->execute([(int)$row['id']]);
            }
            $changed = true;
            smFixPrimary($pdo, $code);
            smRememberSuggest($pdo, $code);
            smLog($pdo, $user, (int)$mat['id'], 'ic_unmap', ['mat_code' => $code, 'ic_code' => $ic], null);
        }
        if ($ownTx) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
        return ['ok' => false, 'error' => 'ถอด IC ไม่สำเร็จ: ' . $e->getMessage(), 'changed' => false, 'llps' => []];
    }
    return ['ok' => true, 'error' => '', 'changed' => $changed, 'llps' => smMapList($pdo, $code)];
}

/** ตั้ง IC ตัวหนึ่งเป็นหลักของ Mango (ต้องผูกไว้แล้ว) */
function smSetPrimary(PDO $pdo, string $matCode, string $icCode, ?array $user = null): array {
    $code = mangoNormalizeCode($matCode);
    $ic   = strtoupper(trim($icCode));
    $st = $pdo->prepare('SELECT COUNT(*) FROM mango_ic_map WHERE mat_code = ? AND ic_code = ?');
    $st->execute([$code, $ic]);
    if ((int)$st->fetchColumn() === 0) {
        return ['ok' => false, 'error' => $ic . ' ไม่ได้ผูกกับ ' . $code, 'changed' => false, 'llps' => []];
    }
    $up = $pdo->prepare('UPDATE mango_ic_map SET is_primary = (ic_code = ?) WHERE mat_code = ?');
    $up->execute([$ic, $code]);
    smRememberSuggest($pdo, $code);
    $mat = mangoGet($pdo, $code);
    smLog($pdo, $user, $mat !== null ? (int)$mat['id'] : $code, 'ic_primary', null, ['mat_code' => $code, 'ic_code' => $ic]);
    return ['ok' => true, 'error' => '', 'changed' => $up->rowCount() > 0, 'llps' => smMapList($pdo, $code)];
}

// ═══════════════════════════════════════════════════════════════════════════
// นำเข้าเป็นชุดจาก Excel (จอ) — 1 แถวต่อ (Mango × LLP [× IC])
// นำเข้าแบบ "เพิ่ม" เท่านั้น — ไม่ถอดการผูกที่ไม่อยู่ในไฟล์ (ถอดทีละตัวที่หน้าจอ)
// 1 Mango มี LLP ได้ตัวเดียว (มติ 45) — หลายแถวของ Mango เดียวกันได้ (หลาย IC) แต่ต้อง LLP เดียวกัน
// และไม่เปลี่ยน LLP ที่ผูกไว้แล้วผ่านไฟล์ (เปลี่ยนที่จอข้อ 3 — ต้องเห็นว่า IC ไหนจะหลุด)
// ไฟล์ตั้งต้นทั้งก้อนจากฝ่ายจัดซื้อใช้ db/import_llp_master.php แทน (เร็วกว่ามาก)
// ═══════════════════════════════════════════════════════════════════════════

/** หัวคอลัมน์ของไฟล์ที่ให้โหลดไปกรอก */
function smXlsxHead(): array {
    return ['รหัส Mango', 'ชื่อวัสดุ', 'หน่วย', 'คงเหลือรวม', 'รหัส LLP', 'ชื่อตัวสินค้า (LLP)', 'รหัส IC (ถ้ามี)', 'ชื่อ IC'];
}

/**
 * อ่านไฟล์ → แผนการนำเข้า (ยังไม่เขียน DB) — เรียกทั้งตอน preview และ commit กัน DB ขยับ
 * new_llp = คู่ (Mango, LLP) ที่ยังไม่ผูก · new_ic = IC ที่จะผูกเพิ่ม · same = มีอยู่แล้ว
 * multi = Mango ที่จะมีหลาย IC หลังนำเข้า (ตอนย้ายยอดต้องกรอกยอดแยก)
 */
function smBuildPlan(PDO $pdo, string $path): array {
    require_once __DIR__ . '/../includes/xlsx_lite.php';
    $sheets = xlsxReadSheets($path);

    $norm = function ($s) { return mb_strtolower(preg_replace('/\s+/u', '', (string)$s), 'UTF-8'); };
    $rows = null; $map = [];
    foreach ($sheets as $sheetRows) {
        if (!$sheetRows) { continue; }
        $idx = ['mat' => null, 'llp' => null, 'ic' => null];
        foreach ($sheetRows[0] as $i => $h) {
            $h = $norm($h);
            if ($idx['mat'] === null && strpos($h, 'mango') !== false) { $idx['mat'] = $i; }
            if ($idx['llp'] === null && strpos($h, 'llp') !== false && strpos($h, 'ชื่อ') === false) { $idx['llp'] = $i; }
            if ($idx['ic'] === null && strpos($h, 'ic') !== false && strpos($h, 'llp') === false
                && strpos($h, 'ชื่อ') === false) { $idx['ic'] = $i; }
        }
        if ($idx['mat'] !== null && ($idx['llp'] !== null || $idx['ic'] !== null)) { $rows = $sheetRows; $map = $idx; break; }
    }
    if ($rows === null) {
        throw new RuntimeException('ไม่พบตารางที่มีคอลัมน์ "รหัส Mango" และ "รหัส LLP" ในไฟล์ — '
            . 'ให้ดาวน์โหลดไฟล์ตั้งต้นจากหน้านี้แล้วกรอกทับ');
    }

    // สถานะปัจจุบัน — โหลดทีเดียวแทนยิงทีละแถว
    $cur = [];
    foreach ($pdo->query("SELECT id, mat_code, name, unit FROM materials WHERE code_type = 'mango'")->fetchAll() as $r) {
        $cur[(string)$r['mat_code']] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'unit' => (string)$r['unit'],
                                        'llp' => [], 'ic' => []];
    }
    foreach ($pdo->query('SELECT mat_code, llp_code, ic_code FROM mango_ic_map')->fetchAll() as $r) {
        $mc = (string)$r['mat_code'];
        if (!isset($cur[$mc])) { continue; }
        $cur[$mc]['llp'][(string)$r['llp_code']] = true;
        if ((string)$r['ic_code'] !== '') { $cur[$mc]['ic'][(string)$r['ic_code']] = true; }
    }

    $plan = ['new_llp' => [], 'new_ic' => [], 'same' => 0, 'blank' => 0, 'errors' => [], 'seen' => 0, 'multi' => 0];
    $seenPair = []; $llpMem = []; $icMem = []; $fromFile = []; $touched = [];

    foreach ($rows as $i => $row) {
        if ($i === 0) { continue; }
        $get = function ($k) use ($row, $map) {
            return $map[$k] === null ? '' : strtoupper(trim((string)($row[$map[$k]] ?? '')));
        };
        $mat  = mangoNormalizeCode($map['mat'] === null ? '' : (string)($row[$map['mat']] ?? ''));
        $llp  = preg_replace('/\s+/u', '', $get('llp'));
        $ic   = preg_replace('/\s+/u', '', $get('ic'));
        $line = $i + 1;

        if ($mat === '' && $llp === '' && $ic === '') { continue; }
        $plan['seen']++;

        if ($mat === '')            { $plan['errors'][] = ['line' => $line, 'mat' => '', 'msg' => 'ไม่มีรหัส Mango']; continue; }
        if (!isset($cur[$mat]))     { $plan['errors'][] = ['line' => $line, 'mat' => $mat, 'msg' => 'ไม่รู้จักรหัสนี้ในทะเบียน Mango']; continue; }
        if ($llp === '' && $ic === '') { $plan['blank']++; continue; }

        // IC ระบุมา → ใช้ LLP ของ IC เป็นหลัก
        if ($ic !== '') {
            if (!isset($icMem[$ic])) { $icMem[$ic] = smCheckIc($pdo, $ic); }
            if (!$icMem[$ic]['ok']) { $plan['errors'][] = ['line' => $line, 'mat' => $mat, 'msg' => $icMem[$ic]['error']]; continue; }
            $icInfo = $icMem[$ic]['ic'];
            if ($llp !== '' && $llp !== (string)$icInfo['llp_code']) {
                $plan['errors'][] = ['line' => $line, 'mat' => $mat,
                                     'msg' => 'IC ' . $ic . ' อยู่ใต้ ' . $icInfo['llp_code'] . ' ไม่ใช่ ' . $llp];
                continue;
            }
            $llp = (string)$icInfo['llp_code'];
        }

        if (!isset($llpMem[$llp])) { $llpMem[$llp] = smLlpInfo($pdo, $llp); }
        if ($llpMem[$llp] === null) { $plan['errors'][] = ['line' => $line, 'mat' => $mat, 'msg' => 'ไม่พบตัวสินค้า (LLP) ' . $llp]; continue; }

        $key = $mat . '|' . $llp . '|' . $ic;
        if (isset($seenPair[$key])) {
            $plan['errors'][] = ['line' => $line, 'mat' => $mat, 'msg' => 'ซ้ำกับบรรทัด ' . $seenPair[$key]];
            continue;
        }
        $seenPair[$key] = $line;
        $c = $cur[$mat];

        $hasLlp = isset($c['llp'][$llp]);
        $hasIc  = $ic !== '' && isset($c['ic'][$ic]);
        if (($ic === '' && $hasLlp) || ($ic !== '' && $hasIc)) { $plan['same']++; continue; }

        // 1 Mango = 1 LLP (มติ 45)
        if (!$hasLlp && $c['llp']) {
            $other = implode(', ', array_keys($c['llp']));
            $plan['errors'][] = ['line' => $line, 'mat' => $mat, 'msg' => isset($fromFile[$mat])
                ? 'ไฟล์ให้ตัวสินค้า 2 ตัวกับรหัสเดียวกัน (บรรทัด ' . $fromFile[$mat][1] . ' ให้ ' . $fromFile[$mat][0] . ') — 1 Mango มีตัวสินค้าได้ตัวเดียว'
                : 'ผูกตัวสินค้า ' . $other . ' อยู่แล้ว — 1 Mango มีตัวสินค้าได้ตัวเดียว (ถ้าจะเปลี่ยน ให้เปลี่ยนที่ข้อ 3)'];
            continue;
        }

        if (!$hasLlp) {
            $fromFile[$mat] = [$llp, $line];
            $plan['new_llp'][] = ['line' => $line, 'id' => $c['id'], 'mat' => $mat, 'name' => $c['name'],
                                  'llp' => $llp, 'llp_name' => (string)$llpMem[$llp]['llp_name'],
                                  'is_set' => (bool)$llpMem[$llp]['is_set'], 'n_before' => count($c['llp'])];
            $cur[$mat]['llp'][$llp] = true;
        }
        if ($ic !== '') {
            $plan['new_ic'][] = ['line' => $line, 'id' => $c['id'], 'mat' => $mat, 'name' => $c['name'],
                                 'llp' => $llp, 'ic' => $ic, 'ic_name' => (string)$icMem[$ic]['ic']['ic_name'],
                                 'unit' => (string)$icMem[$ic]['ic']['unit_name'],
                                 'unit_warn' => !smUnitSame($c['unit'], (string)$icMem[$ic]['ic']['unit_name'])];
            $cur[$mat]['ic'][$ic] = true;
            $touched[$mat] = true;
        }
    }
    foreach (array_keys($touched) as $mat) { if (count($cur[$mat]['ic']) > 1) { $plan['multi']++; } }
    return $plan;
}

/** เขียนแผนลง DB ในทรานแซกชันเดียว — คืน ['llp'=>n, 'ic'=>n] */
function smCommitPlan(PDO $pdo, array $plan, ?array $user = null): array {
    $n = ['llp' => 0, 'ic' => 0];
    if (!$plan['new_llp'] && !$plan['new_ic']) { return $n; }

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) { $pdo->beginTransaction(); }
    try {
        foreach ($plan['new_llp'] as $t) {
            $r = smMapLlp($pdo, $t['mat'], $t['llp'], $user);
            if (!$r['ok']) { throw new RuntimeException('บรรทัด ' . $t['line'] . ': ' . $r['error']); }
            if ($r['changed']) { $n['llp']++; }
        }
        foreach ($plan['new_ic'] as $t) {
            $r = smAttachIc($pdo, $t['mat'], $t['ic'], $user);
            if (!$r['ok']) { throw new RuntimeException('บรรทัด ' . $t['line'] . ': ' . $r['error']); }
            if ($r['changed']) { $n['ic']++; }
        }
        if ($ownTx) { $pdo->commit(); }
        return $n;
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// ย้ายยอดสต๊อกจาก Mango → IC
// ═══════════════════════════════════════════════════════════════════════════

/**
 * แผนการย้าย — 1 แถว = (โครงการ × รหัส Mango) ที่ยังมีอะไรคีย์ด้วย id ของ Mango อยู่
 * $projectId = 0 คือทุกโครงการ · $matCode ระบุ = เฉพาะรหัสนั้น
 * rows     = ย้ายได้ (มี IC แล้ว) · 'split' = มีหลาย IC ต้องระบุยอดแยก
 * blockers = ถือยอดอยู่แต่ยังไม่มี IC — 'reason' บอกว่าติดขั้นไหน
 */
function smMigratePlan(PDO $pdo, int $projectId = 0, string $matCode = ''): array {
    $pw   = $projectId > 0 ? ' AND d.project_id = ' . $projectId : '';
    $pwx  = $projectId > 0 ? ' AND x.project_id = ' . $projectId : '';
    $args = [];
    $mw   = '';
    if ($matCode !== '') { $mw = ' AND m.mat_code = ?'; $args[] = mangoNormalizeCode($matCode); }

    $sql = "SELECT s.project_id, s.material_id, pr.code AS project_code,
                   m.mat_code, m.name, m.unit,
                   COALESCE(b.qty_in, 0) qty_in, COALESCE(b.qty_out, 0) qty_out,
                   COALESCE(b.on_hand, 0) on_hand, COALESCE(b.pending, 0) pending,
                   (b.material_id IS NOT NULL) AS has_bal,
                   (pm.material_id IS NOT NULL) AS has_pm,
                   (SELECT COUNT(*) FROM stock_gate_balances x WHERE x.project_id = s.project_id AND x.material_id = s.material_id) AS n_gate,
                   (rc.material_id IS NOT NULL) AS has_rate,
                   (SELECT COUNT(*) FROM document_items x JOIN documents d ON d.id = x.document_id
                     WHERE d.project_id = s.project_id
                       AND (x.material_id = s.material_id OR (x.material_id IS NULL AND x.mat_code = m.mat_code))) AS n_doc,
                   (SELECT COUNT(*) FROM deduction_doc_rates x WHERE x.project_id = s.project_id AND x.mat_code = m.mat_code) AS n_ddr
              FROM (
                    SELECT project_id, material_id FROM stock_balances x WHERE 1=1$pwx
                    UNION SELECT project_id, material_id FROM project_materials x WHERE 1=1$pwx
                    UNION SELECT project_id, material_id FROM stock_gate_balances x WHERE 1=1$pwx
                    UNION SELECT project_id, material_id FROM rate_cards x WHERE 1=1$pwx
                    UNION SELECT d.project_id, x.material_id FROM document_items x JOIN documents d ON d.id = x.document_id
                     WHERE x.material_id IS NOT NULL$pw
                   ) s
              JOIN materials m  ON m.id = s.material_id AND m.code_type = 'mango'$mw
              JOIN projects pr  ON pr.id = s.project_id
              LEFT JOIN stock_balances b     ON b.project_id = s.project_id AND b.material_id = s.material_id
              LEFT JOIN project_materials pm ON pm.project_id = s.project_id AND pm.material_id = s.material_id
              LEFT JOIN rate_cards rc        ON rc.project_id = s.project_id AND rc.material_id = s.material_id
             ORDER BY pr.code, m.mat_code";
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();

    $lists = smMapListMany($pdo, array_column($rows, 'mat_code'));

    // IC ตัวไหนมีแถวยอด/วัสดุในโครงการอยู่แล้ว (= ต้องบวกรวม) — ถามทีเดียว
    $icIds = []; $projIds = [];
    foreach ($rows as $r) {
        $projIds[(int)$r['project_id']] = true;
        foreach (smIcsOf($lists[(string)$r['mat_code']] ?? []) as $ic) {
            if ($ic['ic_material_id'] > 0) { $icIds[$ic['ic_material_id']] = true; }
        }
    }
    $existing = [];
    if ($icIds && $projIds) {
        $ph1 = implode(',', array_map('intval', array_keys($projIds)));
        $ph2 = implode(',', array_map('intval', array_keys($icIds)));
        $q = "SELECT project_id, material_id FROM stock_balances WHERE project_id IN ($ph1) AND material_id IN ($ph2)
              UNION SELECT project_id, material_id FROM project_materials WHERE project_id IN ($ph1) AND material_id IN ($ph2)";
        foreach ($pdo->query($q)->fetchAll() as $e) { $existing[(int)$e['project_id'] . '|' . (int)$e['material_id']] = true; }
    }

    $out = ['rows' => [], 'blockers' => [], 'projects' => [],
            'sum' => ['on_hand' => 0.0, 'rows' => 0, 'split' => 0, 'merge' => 0, 'unit_warn' => 0, 'doc' => 0]];
    $seenIc = [];
    foreach ($rows as $r) {
        $P = (int)$r['project_id'];
        $out['projects'][$P] = (string)$r['project_code'];
        $blocks = $lists[(string)$r['mat_code']] ?? [];
        $row = [
            'project_id'   => $P,
            'project_code' => (string)$r['project_code'],
            'material_id'  => (int)$r['material_id'],
            'mat_code'     => (string)$r['mat_code'],
            'name'         => (string)$r['name'],
            'unit'         => (string)$r['unit'],
            'qty_in'   => (float)$r['qty_in'],  'qty_out' => (float)$r['qty_out'],
            'on_hand'  => (float)$r['on_hand'], 'pending' => (float)$r['pending'],
            'has_bal'  => (int)$r['has_bal'] === 1, 'has_pm' => (int)$r['has_pm'] === 1,
            'n_gate'   => (int)$r['n_gate'],  'has_rate' => (int)$r['has_rate'] === 1,
            'n_doc'    => (int)$r['n_doc'],   'n_ddr'    => (int)$r['n_ddr'],
            'llps'     => $blocks,
            'ics'      => [], 'split' => false, 'merge' => false, 'unit_warn' => false, 'reason' => '',
        ];
        $ics = array_values(array_filter(smIcsOf($blocks), function ($ic) { return !$ic['missing']; }));
        if (!$ics) {
            $row['reason'] = $blocks ? 'ผูก LLP แล้วแต่ยังไม่ได้ออกรหัส IC' : 'ยังไม่ผูกตัวสินค้า (LLP)';
            $out['blockers'][] = $row;
            continue;
        }

        foreach ($ics as $ic) {
            $key = $P . '|' . $ic['ic_code'];
            $ic['merge']     = isset($existing[$P . '|' . $ic['ic_material_id']]) || isset($seenIc[$key]);
            $ic['unit_warn'] = !smUnitSame($row['unit'], $ic['unit_name']);
            $seenIc[$key] = true;
            if ($ic['merge'])     { $row['merge'] = true; }
            if ($ic['unit_warn']) { $row['unit_warn'] = true; }
            $row['ics'][] = $ic;
        }
        $row['split'] = count($row['ics']) > 1;

        $out['rows'][] = $row;
        $out['sum']['rows']++;
        $out['sum']['on_hand'] += $row['on_hand'];
        $out['sum']['doc']     += $row['n_doc'];
        if ($row['split'])     { $out['sum']['split']++; }
        if ($row['merge'])     { $out['sum']['merge']++; }
        if ($row['unit_warn']) { $out['sum']['unit_warn']++; }
    }
    return $out;
}

/**
 * ตรวจยอดแยกที่ผู้ดูแลกรอกมาสำหรับแถวหลาย IC — คืน [ic_code => qty] หรือโยน RuntimeException
 * $posted = $splits[project_id][mat_code] = [ic_code => 'ตัวเลข']
 */
function smSplitFor(array $row, array $splits): array {
    $p = $splits[$row['project_id']][$row['mat_code']] ?? null;
    $label = $row['project_code'] . ' · ' . $row['mat_code'];
    if (!is_array($p)) {
        throw new RuntimeException($label . ' ผูกไว้ ' . count($row['ics']) . ' IC — ต้องระบุยอดแยกต่อ IC ก่อนย้าย (ข้อ 4)');
    }
    $out = []; $sum = 0.0;
    foreach ($row['ics'] as $ic) {
        $raw = trim((string)($p[$ic['ic_code']] ?? ''));
        if ($raw === '') { $raw = '0'; }
        $raw = str_replace(',', '', $raw);
        if (!is_numeric($raw) || (float)$raw < 0) {
            throw new RuntimeException($label . ' → ' . $ic['ic_code'] . ' ยอดแยก "' . $raw . '" ไม่ใช่ตัวเลขที่ใช้ได้');
        }
        $out[$ic['ic_code']] = round((float)$raw, 3);
        $sum += $out[$ic['ic_code']];
    }
    if (abs($sum - $row['on_hand']) > SM_QTY_EPS) {
        throw new RuntimeException($label . ' ยอดแยกรวม ' . number_format($sum, 3) . ' ไม่เท่ายอดคงเหลือ ' . number_format($row['on_hand'], 3));
    }
    return $out;
}

/**
 * ย้ายจริง — ทรานแซกชันเดียวทั้งชุด · แถวไหนย้ายไม่ได้โยน exception ให้ rollback ทั้งหมด (มติ 30)
 * @param array  $splits  ยอดแยกของแถวหลาย IC — [project_id][mat_code][ic_code] => qty
 * @param string $matCode ระบุ = ย้ายเฉพาะรหัสนั้น (ปุ่มรายตัว)
 * @return array ['rows','split','bal','gate','pm','rate','doc','ddr','projects']
 */
function smMigrate(PDO $pdo, int $projectId, ?array $user = null, array $splits = [], string $matCode = ''): array {
    $plan = smMigratePlan($pdo, $projectId, $matCode);
    $n = ['rows' => 0, 'split' => 0, 'bal' => 0, 'gate' => 0, 'pm' => 0, 'rate' => 0, 'doc' => 0, 'ddr' => 0, 'projects' => 0];
    if (!$plan['rows']) { return $n; }

    // ตรวจยอดแยกให้ครบก่อนแตะ DB — เจอปัญหาจะได้บอกทั้งชุดโดยไม่เริ่มทรานแซกชัน
    $shares = [];
    foreach ($plan['rows'] as $i => $r) {
        if ($r['split']) { $shares[$i] = smSplitFor($r, $splits); }
    }

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) { $pdo->beginTransaction(); }
    try {
        $touched = [];
        foreach ($plan['rows'] as $i => $r) {
            $P   = $r['project_id'];
            $mid = $r['material_id'];
            $by  = (string)($user['username'] ?? '');

            // แถวคู่ใน materials ของทุก IC (มติ 3) — ปกติมีตั้งแต่ตอนออกรหัส
            foreach ($r['ics'] as $k => $ic) {
                if ($ic['ic_material_id'] <= 0) {
                    $iid = icEnsureMaterial($pdo, $ic['ic_code']);
                    if ($iid <= 0) { throw new RuntimeException('สร้างแถววัสดุคู่ของ IC ' . $ic['ic_code'] . ' ไม่ได้'); }
                    $r['ics'][$k]['ic_material_id'] = $iid;
                }
            }
            $primary = $r['ics'][0];
            foreach ($r['ics'] as $ic) { if ($ic['is_primary']) { $primary = $ic; break; } }

            if (!$r['split']) {
                $c = smMoveWhole($pdo, $P, $mid, $primary['ic_material_id'], $by);
            } else {
                $c = smMoveSplit($pdo, $P, $mid, $r, $shares[$i], $by);
                $n['split']++;
            }
            foreach (['bal', 'gate', 'pm', 'rate'] as $k) { $n[$k] += $c[$k]; }

            // บรรทัดเอกสาร — เปลี่ยนทั้ง material_id และ mat_code เป็น IC หลัก (ชื่อ/หน่วยที่บันทึกไว้คงเดิม)
            $u = $pdo->prepare(
                'UPDATE document_items di JOIN documents d ON d.id = di.document_id
                    SET di.material_id = ?, di.mat_code = ?
                  WHERE d.project_id = ?
                    AND (di.material_id = ? OR (di.material_id IS NULL AND di.mat_code = ?))'
            );
            $u->execute([$primary['ic_material_id'], $primary['ic_code'], $P, $mid, $r['mat_code']]);
            $nDoc = $u->rowCount();
            $n['doc'] += $nDoc;

            // ราคาที่ล็อกไว้ในใบหักเงิน — คีย์ (doc_no, mat_code) ชนกันได้ถ้าใบเดียวมีทั้งสองรหัส
            $u = $pdo->prepare('UPDATE IGNORE deduction_doc_rates SET mat_code = ? WHERE project_id = ? AND mat_code = ?');
            $u->execute([$primary['ic_code'], $P, $r['mat_code']]);
            $n['ddr'] += $u->rowCount();
            $pdo->prepare('DELETE FROM deduction_doc_rates WHERE project_id = ? AND mat_code = ?')->execute([$P, $r['mat_code']]);

            smLog($pdo, $user, $mid, 'ic_migrate',
                ['project' => $r['project_code'], 'mat_code' => $r['mat_code'], 'on_hand' => $r['on_hand'], 'pending' => $r['pending']],
                ['project' => $r['project_code'], 'primary' => $primary['ic_code'],
                 'split' => $r['split'] ? $shares[$i] : null, 'doc_lines' => $nDoc]);
            $touched[$P] = true;
            $n['rows']++;
        }

        // ยอดจอง (pending) คิดใหม่จากเอกสารที่ย้ายรหัสแล้ว — ค่าที่บวกรวมมาเป็นแค่ค่าชั่วคราว
        foreach (array_keys($touched) as $P) {
            recalcPending($pdo, (int)$P);
            $n['projects']++;
        }

        if ($ownTx) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return $n;
}

/**
 * IC เดียว — ยกทั้งก้อน (in/out/on_hand/pending ตามเดิม) · มีแถว IC อยู่แล้วให้บวกรวมแล้วลบของ Mango
 * @return array ['bal','gate','pm','rate']
 */
function smMoveWhole(PDO $pdo, int $P, int $mid, int $iid, string $by): array {
    $c = ['bal' => 0, 'gate' => 0, 'pm' => 0, 'rate' => 0];

    // 1) ยอดรวม
    $st = $pdo->prepare('SELECT id FROM stock_balances WHERE project_id = ? AND material_id = ? FOR UPDATE');
    $st->execute([$P, $iid]);
    if ($st->fetchColumn() !== false) {
        $pdo->prepare(
            'UPDATE stock_balances t JOIN stock_balances s
                 ON s.project_id = t.project_id AND s.material_id = ?
                SET t.qty_in = t.qty_in + s.qty_in, t.qty_out = t.qty_out + s.qty_out,
                    t.on_hand = t.on_hand + s.on_hand, t.pending = t.pending + s.pending
              WHERE t.project_id = ? AND t.material_id = ?'
        )->execute([$mid, $P, $iid]);
        $d = $pdo->prepare('DELETE FROM stock_balances WHERE project_id = ? AND material_id = ?');
        $d->execute([$P, $mid]);
        $c['bal'] += $d->rowCount();
    } else {
        $u = $pdo->prepare('UPDATE stock_balances SET material_id = ? WHERE project_id = ? AND material_id = ?');
        $u->execute([$iid, $P, $mid]);
        $c['bal'] += $u->rowCount();
    }

    // 2) ยอดรายประตู — ทีละประตู
    $st = $pdo->prepare('SELECT gate_id FROM stock_gate_balances WHERE project_id = ? AND material_id = ?');
    $st->execute([$P, $mid]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $gid) {
        $gid = (int)$gid;
        $ex  = $pdo->prepare('SELECT id FROM stock_gate_balances WHERE project_id = ? AND material_id = ? AND gate_id = ? FOR UPDATE');
        $ex->execute([$P, $iid, $gid]);
        if ($ex->fetchColumn() !== false) {
            $pdo->prepare(
                'UPDATE stock_gate_balances t JOIN stock_gate_balances s
                     ON s.project_id = t.project_id AND s.gate_id = t.gate_id AND s.material_id = ?
                    SET t.qty_in = t.qty_in + s.qty_in, t.qty_out = t.qty_out + s.qty_out,
                        t.on_hand = t.on_hand + s.on_hand, t.pending = t.pending + s.pending
                  WHERE t.project_id = ? AND t.material_id = ? AND t.gate_id = ?'
            )->execute([$mid, $P, $iid, $gid]);
            $pdo->prepare('DELETE FROM stock_gate_balances WHERE project_id = ? AND material_id = ? AND gate_id = ?')
                ->execute([$P, $mid, $gid]);
        } else {
            $pdo->prepare('UPDATE stock_gate_balances SET material_id = ? WHERE project_id = ? AND material_id = ? AND gate_id = ?')
                ->execute([$iid, $P, $mid, $gid]);
        }
        $c['gate']++;
    }

    // 3) วัสดุในโครงการ + ประตูจ่าย — ของ IC ยังไม่มีประตูให้รับประตูของ Mango มา
    $st = $pdo->prepare('SELECT id, gate_id FROM project_materials WHERE project_id = ? AND material_id = ? FOR UPDATE');
    $st->execute([$P, $mid]);
    $mgPm = $st->fetch();
    if ($mgPm) {
        $st->execute([$P, $iid]);
        $icPm = $st->fetch();
        if ($icPm) {
            if ($icPm['gate_id'] === null && $mgPm['gate_id'] !== null) {
                $pdo->prepare('UPDATE project_materials SET gate_id = ? WHERE id = ?')->execute([(int)$mgPm['gate_id'], (int)$icPm['id']]);
            }
            $pdo->prepare('DELETE FROM project_materials WHERE id = ?')->execute([(int)$mgPm['id']]);
        } else {
            $pdo->prepare('UPDATE project_materials SET material_id = ? WHERE id = ?')->execute([$iid, (int)$mgPm['id']]);
        }
        $c['pm']++;
    }

    // 4) ราคาหักเงิน — IC มีราคาอยู่แล้วให้ของ IC ชนะ (เว้นแต่ยังว่าง)
    $st = $pdo->prepare('SELECT id, unit_price FROM rate_cards WHERE project_id = ? AND material_id = ? FOR UPDATE');
    $st->execute([$P, $mid]);
    $mgRc = $st->fetch();
    if ($mgRc) {
        $st->execute([$P, $iid]);
        $icRc = $st->fetch();
        if ($icRc) {
            if ($icRc['unit_price'] === null && $mgRc['unit_price'] !== null) {
                $pdo->prepare('UPDATE rate_cards SET unit_price = ?, updated_by = ? WHERE id = ?')
                    ->execute([$mgRc['unit_price'], $by, (int)$icRc['id']]);
            }
            $pdo->prepare('DELETE FROM rate_cards WHERE id = ?')->execute([(int)$mgRc['id']]);
        } else {
            $pdo->prepare('UPDATE rate_cards SET material_id = ? WHERE id = ?')->execute([$iid, (int)$mgRc['id']]);
        }
        $c['rate']++;
    }
    return $c;
}

/**
 * หลาย IC — แตกยอดคงเหลือของ Mango ตามยอดแยกที่กรอก ลงเป็น "ยอดตั้งต้น" ของแต่ละ IC
 * (qty_in = ยอดแยก · qty_out = 0 · on_hand = ยอดแยก) · ประวัติ in/out ของ Mango ไม่ถูกลากไป
 * IC ทุกตัวได้แถว project_materials (ประตูจ่ายของ Mango) + rate card สำเนา — แม้ยอดแยกเป็น 0
 * ยอดรายประตูแตกตามสัดส่วนยอดแยก (เศษปัดให้ตัวสุดท้าย) · ยอดรวมเป็น 0 → ทั้งหมดไปที่ IC หลัก
 */
function smMoveSplit(PDO $pdo, int $P, int $mid, array $row, array $shares, string $by): array {
    $c = ['bal' => 0, 'gate' => 0, 'pm' => 0, 'rate' => 0];
    $ics   = $row['ics'];
    $total = 0.0;
    foreach ($shares as $q) { $total += $q; }

    $upBal = $pdo->prepare('UPDATE stock_balances SET qty_in = qty_in + ?, on_hand = on_hand + ? WHERE project_id = ? AND material_id = ?');
    $inBal = $pdo->prepare('INSERT INTO stock_balances (project_id, material_id, qty_in, qty_out, on_hand, pending) VALUES (?,?,?,0,?,0)');

    // 1) ยอดรวม
    $st = $pdo->prepare('SELECT id FROM stock_balances WHERE project_id = ? AND material_id = ? FOR UPDATE');
    $st->execute([$P, $mid]);
    $hadBal = $st->fetchColumn() !== false;
    foreach ($ics as $ic) {
        $q = $shares[$ic['ic_code']] ?? 0.0;
        $upBal->execute([$q, $q, $P, $ic['ic_material_id']]);
        if ($upBal->rowCount() === 0) {
            $st->execute([$P, $ic['ic_material_id']]);
            if ($st->fetchColumn() === false) { $inBal->execute([$P, $ic['ic_material_id'], $q, $q]); }
        }
    }
    if ($hadBal) {
        $pdo->prepare('DELETE FROM stock_balances WHERE project_id = ? AND material_id = ?')->execute([$P, $mid]);
        $c['bal'] = 1;
    }

    // 2) ยอดรายประตู — แตกตามสัดส่วน
    $st = $pdo->prepare('SELECT gate_id, on_hand FROM stock_gate_balances WHERE project_id = ? AND material_id = ?');
    $st->execute([$P, $mid]);
    $gates = $st->fetchAll();
    $upG = $pdo->prepare('UPDATE stock_gate_balances SET qty_in = qty_in + ?, on_hand = on_hand + ? WHERE project_id = ? AND material_id = ? AND gate_id = ?');
    $inG = $pdo->prepare('INSERT INTO stock_gate_balances (project_id, material_id, gate_id, qty_in, qty_out, on_hand, pending) VALUES (?,?,?,?,0,?,0)');
    foreach ($gates as $g) {
        $gid  = (int)$g['gate_id'];
        $gOn  = (float)$g['on_hand'];
        $left = $gOn;
        $last = count($ics) - 1;
        foreach ($ics as $k => $ic) {
            if ($total > 0) {
                $part = $k === $last ? $left : round($gOn * (($shares[$ic['ic_code']] ?? 0.0) / $total), 3);
            } else {
                $part = $ic['is_primary'] ? $gOn : 0.0;     // ยอดแยกรวมเป็น 0 — เอาของประตูไปไว้ที่ IC หลัก
            }
            $left -= $part;
            $upG->execute([$part, $part, $P, $ic['ic_material_id'], $gid]);
            if ($upG->rowCount() === 0) {
                $ex = $pdo->prepare('SELECT id FROM stock_gate_balances WHERE project_id = ? AND material_id = ? AND gate_id = ?');
                $ex->execute([$P, $ic['ic_material_id'], $gid]);
                if ($ex->fetchColumn() === false) { $inG->execute([$P, $ic['ic_material_id'], $gid, $part, $part]); }
            }
        }
        $pdo->prepare('DELETE FROM stock_gate_balances WHERE project_id = ? AND material_id = ? AND gate_id = ?')->execute([$P, $mid, $gid]);
        $c['gate']++;
    }

    // 3) วัสดุในโครงการ — IC ทุกตัวได้แถว (ประตูจ่ายของ Mango ถ้า IC ยังไม่มี)
    $st = $pdo->prepare('SELECT id, gate_id FROM project_materials WHERE project_id = ? AND material_id = ? FOR UPDATE');
    $st->execute([$P, $mid]);
    $mgPm = $st->fetch();
    $gate = $mgPm && $mgPm['gate_id'] !== null ? (int)$mgPm['gate_id'] : null;
    $inPm = $pdo->prepare('INSERT IGNORE INTO project_materials (project_id, material_id, gate_id) VALUES (?,?,?)');
    foreach ($ics as $ic) {
        $inPm->execute([$P, $ic['ic_material_id'], $gate]);
        if ($inPm->rowCount() === 0 && $gate !== null) {
            $pdo->prepare('UPDATE project_materials SET gate_id = ? WHERE project_id = ? AND material_id = ? AND gate_id IS NULL')
                ->execute([$gate, $P, $ic['ic_material_id']]);
        }
    }
    if ($mgPm) {
        $pdo->prepare('DELETE FROM project_materials WHERE id = ?')->execute([(int)$mgPm['id']]);
        $c['pm'] = 1;
    }

    // 4) ราคาหักเงิน — สำเนาให้ IC ทุกตัวที่ยังไม่มี (IC ที่มีราคาแล้วชนะ)
    $st = $pdo->prepare('SELECT id, unit_price FROM rate_cards WHERE project_id = ? AND material_id = ? FOR UPDATE');
    $st->execute([$P, $mid]);
    $mgRc = $st->fetch();
    if ($mgRc) {
        $inRc = $pdo->prepare('INSERT INTO rate_cards (project_id, material_id, unit_price, updated_by) VALUES (?,?,?,?)');
        foreach ($ics as $ic) {
            $st->execute([$P, $ic['ic_material_id']]);
            $icRc = $st->fetch();
            if (!$icRc) {
                $inRc->execute([$P, $ic['ic_material_id'], $mgRc['unit_price'], $by]);
            } elseif ($icRc['unit_price'] === null && $mgRc['unit_price'] !== null) {
                $pdo->prepare('UPDATE rate_cards SET unit_price = ?, updated_by = ? WHERE id = ?')->execute([$mgRc['unit_price'], $by, (int)$icRc['id']]);
            }
        }
        $pdo->prepare('DELETE FROM rate_cards WHERE id = ?')->execute([(int)$mgRc['id']]);
        $c['rate'] = 1;
    }
    return $c;
}
