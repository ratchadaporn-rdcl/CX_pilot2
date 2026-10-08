<?php
/**
 * CONNEXT — lib/inventory_insights.php : Dashboard คลัง — วัสดุยอดนิยม + Min-Max stock (Safety stock)
 * [PHP port 2026-09-24 · มติ 49] — ไม่มีใน GAS
 *
 * RPC (ลงทะเบียนใน registry.php):
 *   getPopularMaterials(siteCode, days, type)   วัสดุที่ถูกเบิกบ่อยสุด — นับ "จำนวนใบ" ไม่รวมใบยกเลิก/ไม่อนุมัติ
 *   getMinMaxData(siteCode[, params])           ทุกรหัส IC ของไซต์: คงเหลือ · พร้อมเบิก · ใช้เฉลี่ย/วัน · Min/Max ที่ตั้ง
 *                                               · Min/Max แนะนำ · สถานะ · ควรสั่งเติม (params = ลองค่าใหม่โดยไม่บันทึก)
 *   getMinMaxSettings(siteCode)                 เฉพาะค่าที่ตั้งไว้ (siteCode ว่าง = ทุกไซต์ที่ดูได้) — ให้ตาราง Dashboard ใช้
 *   saveMinMax({siteCode, items:[{matCode,min,max}]})          ตั้ง/แก้/ล้าง รายวัสดุ (ว่างทั้งคู่ = ล้าง)
 *   saveMinMaxParams({siteCode, leadTimeDays, cycleDays, serviceLevel, lookbackDays})
 *
 * มติ 49 — Min-Max stock ต่อ (ไซต์ × รหัส IC) กรอกแบบเดียวกับ "ตั้งราคาหักเงิน"
 *   - Min = จุดสั่งซื้อ (ถึงหรือต่ำกว่านี้ต้องสั่งเติม) · Max = ระดับที่เติมขึ้นไปถึง · ควรสั่ง = Max − พร้อมเบิก
 *   - พร้อมเบิก = คงเหลือ − จองแล้ว (Pending) · เกิน Max เทียบกับคงเหลือจริง
 *   - ค่าแนะนำจากการใช้จริง: บรรทัด RD + OD ที่ไม่ยกเลิก ตามวันที่ออกใบ (BD ยืม-คืน ไม่นับ)
 *       ใช้เฉลี่ย/วัน และ σ คิดต่อ "วันที่ไซต์มีเอกสารในระบบ" ไม่ใช่วันปฏิทิน — ช่วงที่ยังไม่ได้ใช้ระบบ
 *       (เช่น ส.ค.–ก.ย. 2026 ระหว่างย้ายจาก GAS) จะไม่ถ่วงค่าเฉลี่ยลง
 *       Safety = Z × σ × √LeadTime · Min = ใช้เฉลี่ย × LeadTime + Safety · Max = Min + ใช้เฉลี่ย × รอบสั่งซื้อ (ปัดขึ้น)
 *       ต้องมีการใช้ ≥ 2 วันในช่วงที่ดู จึงแนะนำ
 *   - เก็บในตาราง stock_minmax / stock_minmax_params — สร้างเองตอนบันทึกครั้งแรก (อ่านอย่างเดียวไม่แตะ schema)
 *   - สิทธิ์แก้: ADM (R0) ทุกไซต์ · สายคลัง (roles.can_req) เฉพาะไซต์ตัวเอง · ดู: ตามสิทธิ์ดู Dashboard
 *   - ข้อจำกัด: re-code IC ที่หน้าแก้ IC (ic_edit) ไม่ย้ายค่า Min-Max ตามไป — รหัสใหม่ต้องตั้งใหม่ (มีค่าแนะนำให้)
 *
 * PHP 7.4-compatible เท่านั้น
 */

const II_DEFAULT_PARAMS = ['leadTimeDays' => 7.0, 'cycleDays' => 14.0, 'serviceLevel' => 95.0, 'lookbackDays' => 180];
const II_SERVICE_Z      = ['90' => 1.2816, '95' => 1.6449, '98' => 2.0537, '99' => 2.3263];
const II_MIN_USE_DAYS   = 2;

// =========================================================================
// ตัวช่วย
// =========================================================================

/** ตาราง Min-Max มีหรือยัง (อ่านอย่างเดียวห้ามสร้าง) */
function _iiTablesExist(PDO $pdo): bool {
    static $ok = null;
    if ($ok === true) return true;
    $st = $pdo->query("SHOW TABLES LIKE 'stock_minmax'");
    $ok = (bool)$st->fetchColumn();
    return $ok;
}

/** สร้างตารางตอนบันทึกครั้งแรก — DDL ทำ implicit commit จึงเรียกก่อนเปิดทรานแซกชันเท่านั้น */
function _iiEnsureTables(PDO $pdo): void {
    if (_iiTablesExist($pdo)) return;
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stock_minmax (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            material_id INT NOT NULL,
            min_qty DECIMAL(14,3) NULL COMMENT 'จุดสั่งซื้อ — พร้อมเบิกถึง/ต่ำกว่านี้ต้องสั่งเติม',
            max_qty DECIMAL(14,3) NULL COMMENT 'เติมขึ้นไปถึง',
            updated_by VARCHAR(100) NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_minmax (project_id, material_id),
            KEY idx_minmax_material (material_id),
            CONSTRAINT fk_minmax_project  FOREIGN KEY (project_id)  REFERENCES projects(id)  ON DELETE CASCADE,
            CONSTRAINT fk_minmax_material FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE CASCADE
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
           COMMENT='Min-Max stock ต่อไซต์ × รหัส IC (มติ 49)'"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stock_minmax_params (
            project_id INT NOT NULL PRIMARY KEY,
            lead_time_days DECIMAL(6,1) NOT NULL DEFAULT 7.0,
            cycle_days DECIMAL(6,1) NOT NULL DEFAULT 14.0,
            service_level DECIMAL(5,2) NOT NULL DEFAULT 95.00,
            lookback_days INT NOT NULL DEFAULT 180,
            updated_by VARCHAR(100) NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_minmax_params_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
           COMMENT='ค่าตั้งต้นการคำนวณ Min-Max แนะนำ ต่อไซต์ (มติ 49)'"
    );
}

/** ระดับ role สด ๆ จาก DB (session อาจค้างค่าเก่า) → ['level'=>int, 'canReq'=>bool] */
function _iiRole(PDO $pdo, ?array $user): array {
    $out = ['level' => 99, 'canReq' => false];
    if (!$user || ($user['accountType'] ?? '') !== 'user') return $out;
    $st = $pdo->prepare('SELECT r.level, r.can_req FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? LIMIT 1');
    $st->execute([(int)($user['accountId'] ?? 0)]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) { $out['level'] = (int)$r['level']; $out['canReq'] = (int)$r['can_req'] === 1; }
    return $out;
}

/**
 * ไซต์ที่ใช้จริง — ADM / ระดับ ≥ 8 เลือกได้ (เหมือนตัวกรองไซต์ของ Dashboard) · คนอื่นล็อกไซต์ตัวเอง
 * $needOne = true → ว่างแล้วเลือกไซต์ตัวเอง หรือไซต์ที่มีรายการคงเหลือมากสุด
 * @return array ['id'=>?int, 'code'=>string, 'name'=>string]
 */
function _iiSite(PDO $pdo, ?array $user, $argSite, bool $needOne): array {
    $lv = (string)($user['roleLevel'] ?? '');
    $num = preg_match('/\d+/', $lv, $m) ? (int)$m[0] : 99;
    $canPick = ($lv === 'R0') || $num >= 8;
    $code = $canPick ? trim((string)$argSite) : trim((string)($user['siteCode'] ?? ''));
    if ($code === '' && $needOne) {
        $code = trim((string)($user['siteCode'] ?? ''));
        if ($code === '') {
            $code = (string)$pdo->query(
                "SELECT p.code FROM stock_balances b JOIN projects p ON p.id = b.project_id
                  GROUP BY p.id, p.code ORDER BY COUNT(*) DESC LIMIT 1"
            )->fetchColumn();
        }
    }
    if ($code === '') return ['id' => null, 'code' => '', 'name' => ''];
    $st = $pdo->prepare('SELECT id, code, name FROM projects WHERE code = ? LIMIT 1');
    $st->execute([$code]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    return $p ? ['id' => (int)$p['id'], 'code' => (string)$p['code'], 'name' => (string)$p['name']]
              : ['id' => null, 'code' => $code, 'name' => ''];
}

function _iiCanEdit(PDO $pdo, ?array $user, string $siteCode): bool {
    if ($siteCode === '') return false;
    $r = _iiRole($pdo, $user);
    if ($r['level'] === 0) return true;
    return $r['canReq'] && strcasecmp(trim((string)($user['siteCode'] ?? '')), $siteCode) === 0;
}

/** ค่าตั้งต้นของไซต์ (+ ทับด้วย $override ถ้ามี) — ตรวจช่วงค่าให้อยู่ในกรอบเสมอ */
function _iiParams(PDO $pdo, ?int $projectId, $override = null): array {
    $p = II_DEFAULT_PARAMS;
    $p['saved'] = false;
    if ($projectId && _iiTablesExist($pdo)) {
        $st = $pdo->prepare('SELECT lead_time_days, cycle_days, service_level, lookback_days FROM stock_minmax_params WHERE project_id = ?');
        $st->execute([$projectId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $p = ['leadTimeDays' => (float)$r['lead_time_days'], 'cycleDays' => (float)$r['cycle_days'],
                  'serviceLevel' => (float)$r['service_level'], 'lookbackDays' => (int)$r['lookback_days'], 'saved' => true];
        }
    }
    if (is_array($override)) {
        foreach (['leadTimeDays', 'cycleDays', 'serviceLevel', 'lookbackDays'] as $k) {
            if (isset($override[$k]) && is_numeric($override[$k])) { $p[$k] = (float)$override[$k]; }
        }
    }
    $p['leadTimeDays'] = max(1.0, min(180.0, round((float)$p['leadTimeDays'], 1)));
    $p['cycleDays']    = max(1.0, min(180.0, round((float)$p['cycleDays'], 1)));
    $p['lookbackDays'] = (int)max(14, min(730, (int)$p['lookbackDays']));
    $sl = (string)(int)round((float)$p['serviceLevel']);
    if (!isset(II_SERVICE_Z[$sl])) { $sl = '95'; }
    $p['serviceLevel'] = (float)$sl;
    $p['z'] = II_SERVICE_Z[$sl];
    return $p;
}

/** เงื่อนไข "ใบที่นับ" — ไม่รวมยกเลิก/ไม่อนุมัติ */
function _iiLiveDocSql(string $alias = 'd'): string {
    return "LOWER({$alias}.status) NOT LIKE '%cancel%' AND LOWER({$alias}.status) NOT LIKE '%reject%'";
}

/** ค่าจาก client: '' / null = ไม่ตั้ง · ตัวเลข ≥ 0 · อื่น ๆ = false (ผิดรูป) */
function _iiQty($v) {
    if ($v === null) return null;
    if (is_string($v)) { $v = trim(str_replace(',', '', $v)); if ($v === '') return null; }
    if (!is_numeric($v)) return false;
    $f = round((float)$v, 3);
    return $f < 0 ? false : $f;
}

function _iiLog(PDO $pdo, ?array $user, string $action, string $entityId, $data): void {
    try {
        $js = json_encode($data, JSON_UNESCAPED_UNICODE);
        if (strlen($js) > 60000) { $js = mb_strcut($js, 0, 60000, 'UTF-8'); }
        $pdo->prepare('INSERT INTO activity_log (entity_type, entity_id, user_name, action, old_value, new_value) VALUES (?,?,?,?,?,?)')
            ->execute(['stock_minmax', $entityId, (string)($user['username'] ?? ''), $action, null, $js]);
    } catch (Throwable $e) {
        error_log('_iiLog: ' . $e->getMessage());
    }
}

// =========================================================================
// getPopularMaterials(siteCode, days, type)
// =========================================================================
function rpc_getPopularMaterials(PDO $pdo, ?array $user, array $args) {
    try {
        $site = _iiSite($pdo, $user, $args[0] ?? '', false);
        $days = (int)($args[1] ?? 90);
        if ($days < 0) $days = 0;
        $type = strtoupper(trim((string)($args[2] ?? '')));
        $types = in_array($type, ['RD', 'OD', 'BD'], true) ? [$type] : ['RD', 'OD'];
        if ($site['code'] !== '' && $site['id'] === null) {
            return ['success' => true, 'siteCode' => $site['code'], 'days' => $days, 'type' => $type, 'totalDocs' => 0, 'items' => []];
        }

        $w = ["m.code_type = 'ic'", _iiLiveDocSql(),
              'd.doc_type IN (' . implode(',', array_fill(0, count($types), '?')) . ')'];
        $ar = $types;
        if ($site['id'] !== null) { $w[] = 'd.project_id = ?'; $ar[] = $site['id']; }
        if ($days > 0) { $w[] = 'd.doc_ts >= CURDATE() - INTERVAL ? DAY'; $ar[] = $days - 1; }
        $where = implode(' AND ', $w);

        $st = $pdo->prepare(
            "SELECT m.mat_code, m.name, m.unit, m.subgroup_name,
                    COUNT(DISTINCT d.id) AS docs,
                    COUNT(DISTINCT CASE WHEN d.doc_type = 'RD' THEN d.id END) AS rd,
                    COUNT(DISTINCT CASE WHEN d.doc_type = 'OD' THEN d.id END) AS od,
                    COUNT(DISTINCT CASE WHEN d.doc_type = 'BD' THEN d.id END) AS bd,
                    SUM(COALESCE(di.qty_actual, di.qty)) AS qty, MAX(d.doc_ts) AS last_ts
               FROM document_items di
               JOIN documents d ON d.id = di.document_id
               JOIN materials m ON m.id = di.material_id
              WHERE $where
              GROUP BY m.id, m.mat_code, m.name, m.unit, m.subgroup_name
              ORDER BY docs DESC, qty DESC, m.mat_code
              LIMIT 20"
        );
        $st->execute($ar);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $items[] = [
                'matCode' => (string)$r['mat_code'], 'name' => (string)$r['name'], 'unit' => (string)$r['unit'],
                'subgroup' => (string)($r['subgroup_name'] ?? ''),
                'docs' => (int)$r['docs'], 'rd' => (int)$r['rd'], 'od' => (int)$r['od'], 'bd' => (int)$r['bd'],
                'qty' => round((float)$r['qty'], 3), 'lastDate' => substr((string)$r['last_ts'], 0, 10),
            ];
        }
        $st = $pdo->prepare(
            "SELECT COUNT(DISTINCT d.id) AS docs, COUNT(DISTINCT di.material_id) AS mats, MIN(d.doc_ts) AS f, MAX(d.doc_ts) AS t
               FROM document_items di JOIN documents d ON d.id = di.document_id JOIN materials m ON m.id = di.material_id
              WHERE $where"
        );
        $st->execute($ar);
        $tot = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'success' => true, 'siteCode' => $site['code'], 'siteName' => $site['name'], 'days' => $days, 'type' => $type,
            'totalDocs' => (int)($tot['docs'] ?? 0), 'totalMaterials' => (int)($tot['mats'] ?? 0),
            'firstDate' => substr((string)($tot['f'] ?? ''), 0, 10), 'lastDate' => substr((string)($tot['t'] ?? ''), 0, 10),
            'items' => $items,
        ];
    } catch (Throwable $e) {
        error_log('getPopularMaterials: ' . $e->getMessage());
        return ['success' => false, 'message' => 'โหลดวัสดุยอดนิยมไม่สำเร็จ'];
    }
}

// =========================================================================
// getMinMaxData(siteCode[, paramsOverride])
// =========================================================================
function rpc_getMinMaxData(PDO $pdo, ?array $user, array $args) {
    try {
        $site = _iiSite($pdo, $user, $args[0] ?? '', true);
        if ($site['id'] === null) return ['success' => false, 'message' => 'ไม่พบไซต์ ' . $site['code']];
        $P = $site['id'];
        $params = _iiParams($pdo, $P, $args[1] ?? null);
        $LT = $params['leadTimeDays'];
        $CY = $params['cycleDays'];
        $Z  = $params['z'];
        $back = $params['lookbackDays'];

        // วันที่ไซต์มีเอกสารในระบบ (ทุกชนิด) ในช่วงที่ดู = ตัวหารค่าเฉลี่ย
        $st = $pdo->prepare(
            "SELECT COUNT(DISTINCT DATE(d.doc_ts)) AS n, MIN(DATE(d.doc_ts)) AS f, MAX(DATE(d.doc_ts)) AS t
               FROM documents d
              WHERE d.project_id = ? AND d.doc_ts >= CURDATE() - INTERVAL ? DAY AND " . _iiLiveDocSql()
        );
        $st->execute([$P, $back - 1]);
        $act = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $N = (int)($act['n'] ?? 0);

        // การใช้รายวัน (RD + OD) ต่อวัสดุ
        $use = [];   // material_id => ['s'=>Σq, 'ss'=>Σq², 'days'=>n, 'lines'=>n]
        $st = $pdo->prepare(
            "SELECT di.material_id, DATE(d.doc_ts) AS dd, SUM(COALESCE(di.qty_actual, di.qty)) AS q, COUNT(*) AS n
               FROM document_items di JOIN documents d ON d.id = di.document_id
              WHERE d.project_id = ? AND d.doc_type IN ('RD','OD') AND di.material_id IS NOT NULL
                AND COALESCE(di.qty_actual, di.qty) > 0
                AND d.doc_ts >= CURDATE() - INTERVAL ? DAY AND " . _iiLiveDocSql() . "
              GROUP BY di.material_id, DATE(d.doc_ts)"
        );
        $st->execute([$P, $back - 1]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int)$r['material_id'];
            $q = (float)$r['q'];
            if (!isset($use[$id])) $use[$id] = ['s' => 0.0, 'ss' => 0.0, 'days' => 0, 'lines' => 0];
            $use[$id]['s'] += $q;
            $use[$id]['ss'] += $q * $q;
            $use[$id]['days']++;
            $use[$id]['lines'] += (int)$r['n'];
        }

        // ค่าที่ตั้งไว้
        $set = [];
        if (_iiTablesExist($pdo)) {
            $st = $pdo->prepare('SELECT material_id, min_qty, max_qty, updated_by, updated_at FROM stock_minmax WHERE project_id = ?');
            $st->execute([$P]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $set[(int)$r['material_id']] = $r; }
        }

        // รายการ = รหัส IC ที่มียอด/เคยเข้าไซต์นี้ ∪ ที่ตั้งค่าไว้ ∪ ที่มีการใช้
        $bal = [];
        $st = $pdo->prepare(
            "SELECT b.material_id, b.on_hand, b.pending FROM stock_balances b JOIN materials m ON m.id = b.material_id
              WHERE b.project_id = ? AND m.code_type = 'ic'"
        );
        $st->execute([$P]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $bal[(int)$r['material_id']] = $r; }
        $ids = array_values(array_unique(array_merge(array_keys($bal), array_keys($set), array_keys($use))));

        $mats = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st = $pdo->prepare("SELECT id, mat_code, name, unit, subgroup_name, cat_id, char_id FROM materials WHERE code_type = 'ic' AND id IN ($ph)");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $mats[(int)$r['id']] = $r; }
        }

        $items = [];
        $sum = ['total' => 0, 'set' => 0, 'unset' => 0, 'low' => 0, 'over' => 0, 'ok' => 0, 'suggested' => 0,
                'sugLow' => 0];
        foreach ($mats as $id => $m) {
            $onHand  = isset($bal[$id]) ? (float)$bal[$id]['on_hand'] : 0.0;
            $pending = isset($bal[$id]) ? (float)$bal[$id]['pending'] : 0.0;
            $avail   = round($onHand - $pending, 3);

            $u = $use[$id] ?? null;
            $adu = null; $sd = null; $sugMin = null; $sugMax = null; $useDays = 0; $lines = 0; $totalQty = 0.0;
            if ($u && $N > 0) {
                $useDays = $u['days']; $lines = $u['lines']; $totalQty = $u['s'];
                $adu = $u['s'] / $N;
                $var = $u['ss'] / $N - $adu * $adu;
                $sd = sqrt(max(0.0, $var));
                if ($useDays >= II_MIN_USE_DAYS) {
                    $safety = $Z * $sd * sqrt($LT);
                    $sugMin = (int)ceil($adu * $LT + $safety - 1e-9);
                    $sugMax = (int)max($sugMin + 1, ceil($adu * $LT + $safety + $adu * $CY - 1e-9));
                }
            }

            $min = null; $max = null; $by = ''; $at = '';
            if (isset($set[$id])) {
                $min = $set[$id]['min_qty'] === null ? null : (float)$set[$id]['min_qty'];
                $max = $set[$id]['max_qty'] === null ? null : (float)$set[$id]['max_qty'];
                $by = (string)($set[$id]['updated_by'] ?? '');
                $at = substr((string)($set[$id]['updated_at'] ?? ''), 0, 16);
            }
            $isSet = ($min !== null || $max !== null);

            $status = 'unset';
            if ($isSet) {
                // Min = 0 คือ "ไม่ต้องเก็บสต๊อก" — หมดพอดีไม่นับว่าต่ำกว่า Min (ติดลบ = จองเกินของ ยังนับ)
                if ($min !== null && ($min > 0 ? $avail <= $min : $avail < 0)) $status = 'low';
                elseif ($max !== null && $onHand > $max)  $status = 'over';
                else                                      $status = 'ok';
            }
            // ควรสั่งเติม — เติมถึง Max (ไม่ได้ตั้ง Max ใช้ Min + ใช้เฉลี่ย × รอบสั่งซื้อ)
            $orderQty = 0;
            if ($status === 'low') {
                $target = $max !== null ? $max : ($min + ($adu !== null ? $adu * $CY : 0));
                $orderQty = (int)max(0, ceil($target - $avail - 1e-9));
            }
            // ถ้าตั้งตามคำแนะนำ (ยังไม่ได้ตั้ง) จะเป็นอย่างไร — ให้หน้าจอชี้ว่าควรรีบตั้งตัวไหน
            $sugStatus = null;
            if (!$isSet && $sugMin !== null) {
                $sugStatus = $avail <= $sugMin ? 'low' : ($onHand > $sugMax ? 'over' : 'ok');
            }

            $sum['total']++;
            if ($isSet) { $sum['set']++; $sum[$status]++; } else { $sum['unset']++; }
            if ($sugMin !== null) { $sum['suggested']++; }
            if ($sugStatus === 'low') { $sum['sugLow']++; }

            $items[] = [
                'matCode' => (string)$m['mat_code'], 'name' => (string)$m['name'], 'unit' => (string)$m['unit'],
                'subgroup' => (string)($m['subgroup_name'] ?? ''), 'catId' => (string)($m['cat_id'] ?? ''),
                'onHand' => $onHand, 'pending' => $pending, 'available' => $avail,
                'adu' => $adu === null ? null : round($adu, 3), 'sd' => $sd === null ? null : round($sd, 3),
                'useDays' => $useDays, 'lines' => $lines, 'usedQty' => round($totalQty, 3),
                'sugMin' => $sugMin, 'sugMax' => $sugMax,
                'min' => $min, 'max' => $max, 'status' => $status, 'sugStatus' => $sugStatus, 'orderQty' => $orderQty,
                'daysCover' => ($adu !== null && $adu > 0) ? round(max(0.0, $avail) / $adu, 1) : null,
                'updatedBy' => $by, 'updatedAt' => $at,
            ];
        }
        usort($items, function ($a, $b) {
            $c = strcmp($a['subgroup'], $b['subgroup']);
            return $c !== 0 ? $c : strcmp($a['name'], $b['name']);
        });

        $sites = [];
        $lv = (string)($user['roleLevel'] ?? '');
        if ($lv === 'R0') {
            foreach ($pdo->query("SELECT code, name FROM projects WHERE status = 'active' ORDER BY code") as $r) {
                $sites[] = ['code' => (string)$r['code'], 'name' => (string)$r['name']];
            }
        }
        return [
            'success' => true, 'siteCode' => $site['code'], 'siteName' => $site['name'], 'sites' => $sites,
            'canEdit' => _iiCanEdit($pdo, $user, $site['code']),
            'params' => $params,
            'window' => ['activeDays' => $N, 'from' => (string)($act['f'] ?? ''), 'to' => (string)($act['t'] ?? '')],
            'summary' => $sum, 'items' => $items,
        ];
    } catch (Throwable $e) {
        error_log('getMinMaxData: ' . $e->getMessage());
        return ['success' => false, 'message' => 'โหลดข้อมูล Min-Max ไม่สำเร็จ'];
    }
}

// =========================================================================
// getMinMaxSettings(siteCode) — ค่าที่ตั้งไว้อย่างเดียว (ตาราง/ตัวเลขบน Dashboard ใช้)
// =========================================================================
function rpc_getMinMaxSettings(PDO $pdo, ?array $user, array $args) {
    try {
        $site = _iiSite($pdo, $user, $args[0] ?? '', false);
        if (!_iiTablesExist($pdo)) return ['success' => true, 'items' => []];
        $sql = "SELECT p.code AS site, m.mat_code, s.min_qty, s.max_qty
                  FROM stock_minmax s JOIN projects p ON p.id = s.project_id JOIN materials m ON m.id = s.material_id";
        $ar = [];
        if ($site['code'] !== '') { $sql .= ' WHERE s.project_id = ?'; $ar[] = (int)$site['id']; }
        $st = $pdo->prepare($sql);
        $st->execute($ar);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $items[] = ['siteCode' => (string)$r['site'], 'matCode' => (string)$r['mat_code'],
                        'min' => $r['min_qty'] === null ? null : (float)$r['min_qty'],
                        'max' => $r['max_qty'] === null ? null : (float)$r['max_qty']];
        }
        return ['success' => true, 'items' => $items];
    } catch (Throwable $e) {
        error_log('getMinMaxSettings: ' . $e->getMessage());
        return ['success' => false, 'items' => []];
    }
}

// =========================================================================
// saveMinMax({siteCode, items:[{matCode, min, max}]})
// =========================================================================
function rpc_saveMinMax(PDO $pdo, ?array $user, array $args) {
    try {
        $p = is_array($args[0] ?? null) ? $args[0] : [];
        $want = trim((string)($p['siteCode'] ?? ''));
        $site = _iiSite($pdo, $user, $want, false);
        if ($site['id'] === null) return ['success' => false, 'message' => 'ไม่ระบุไซต์'];
        // ขอไซต์อื่นแต่ถูกล็อกเป็นไซต์ตัวเอง = ไม่มีสิทธิ์ (เขียนต้องไม่เปลี่ยนไซต์เงียบ ๆ)
        if (($want !== '' && strcasecmp($want, $site['code']) !== 0) || !_iiCanEdit($pdo, $user, $site['code'])) {
            return ['success' => false, 'message' => 'ไม่มีสิทธิ์ตั้ง Min-Max ของไซต์ ' . ($want !== '' ? $want : $site['code']) . ' (ADM หรือสายคลังของไซต์นั้น)'];
        }
        $incoming = (isset($p['items']) && is_array($p['items'])) ? $p['items'] : [];
        if (!$incoming) return ['success' => true, 'saved' => 0, 'cleared' => 0];
        if (count($incoming) > 2000) return ['success' => false, 'message' => 'ส่งมาครั้งละไม่เกิน 2,000 รายการ'];

        // ตรวจทั้งชุดก่อนเขียน — ผิดตัวเดียวไม่บันทึกเลย
        $rows = []; $errors = [];
        foreach ($incoming as $it) {
            if (!is_array($it)) continue;
            $code = trim((string)($it['matCode'] ?? ''));
            if ($code === '') continue;
            $min = _iiQty($it['min'] ?? null);
            $max = _iiQty($it['max'] ?? null);
            if ($min === false || $max === false) { $errors[] = $code . ': ต้องเป็นตัวเลขไม่ติดลบ'; continue; }
            if ($min !== null && $max !== null && $max < $min) { $errors[] = $code . ': Max ต้องไม่น้อยกว่า Min'; continue; }
            $rows[$code] = [$min, $max];
        }
        if ($errors) {
            return ['success' => false, 'message' => "ตรวจพบค่าที่ใช้ไม่ได้ " . count($errors) . " รายการ:\n" . implode("\n", array_slice($errors, 0, 10))];
        }
        if (!$rows) return ['success' => true, 'saved' => 0, 'cleared' => 0];

        $ids = [];
        foreach (array_chunk(array_keys($rows), 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st = $pdo->prepare("SELECT id, mat_code FROM materials WHERE code_type = 'ic' AND mat_code IN ($ph)");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $ids[(string)$r['mat_code']] = (int)$r['id']; }
        }
        $missing = array_diff(array_keys($rows), array_keys($ids));
        if ($missing) {
            return ['success' => false, 'message' => 'ไม่พบรหัส IC: ' . implode(', ', array_slice($missing, 0, 10))];
        }

        _iiEnsureTables($pdo);   // ก่อนเปิดทรานแซกชัน (DDL commit เอง)
        $by = (string)($user['username'] ?? '');
        $up = $pdo->prepare(
            'INSERT INTO stock_minmax (project_id, material_id, min_qty, max_qty, updated_by) VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE min_qty = VALUES(min_qty), max_qty = VALUES(max_qty), updated_by = VALUES(updated_by)'
        );
        $del = $pdo->prepare('DELETE FROM stock_minmax WHERE project_id = ? AND material_id = ?');
        $saved = 0; $cleared = 0; $logRows = [];
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            foreach ($rows as $code => $mm) {
                list($min, $max) = $mm;
                if ($min === null && $max === null) {
                    $del->execute([$site['id'], $ids[$code]]);
                    $cleared += $del->rowCount();
                } else {
                    $up->execute([$site['id'], $ids[$code], $min, $max, $by]);
                    $saved++;
                }
                $logRows[] = [$code, $min, $max];
            }
            if ($ownTx) $pdo->commit();
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        _iiLog($pdo, $user, 'save', $site['code'], ['saved' => $saved, 'cleared' => $cleared, 'rows' => array_slice($logRows, 0, 1500)]);
        return ['success' => true, 'saved' => $saved, 'cleared' => $cleared];
    } catch (Throwable $e) {
        error_log('saveMinMax: ' . $e->getMessage());
        return ['success' => false, 'message' => 'บันทึก Min-Max ไม่สำเร็จ: ' . $e->getMessage()];
    }
}

// =========================================================================
// saveMinMaxParams({siteCode, leadTimeDays, cycleDays, serviceLevel, lookbackDays})
// =========================================================================
function rpc_saveMinMaxParams(PDO $pdo, ?array $user, array $args) {
    try {
        $p = is_array($args[0] ?? null) ? $args[0] : [];
        $want = trim((string)($p['siteCode'] ?? ''));
        $site = _iiSite($pdo, $user, $want, false);
        if ($site['id'] === null) return ['success' => false, 'message' => 'ไม่ระบุไซต์'];
        if (($want !== '' && strcasecmp($want, $site['code']) !== 0) || !_iiCanEdit($pdo, $user, $site['code'])) {
            return ['success' => false, 'message' => 'ไม่มีสิทธิ์แก้ค่าตั้งต้นของไซต์ ' . ($want !== '' ? $want : $site['code'])];
        }
        $v = _iiParams($pdo, null, $p);   // ตรวจ/บีบช่วงค่าแบบเดียวกับตอนคำนวณ
        _iiEnsureTables($pdo);
        $pdo->prepare(
            'INSERT INTO stock_minmax_params (project_id, lead_time_days, cycle_days, service_level, lookback_days, updated_by)
             VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE lead_time_days = VALUES(lead_time_days), cycle_days = VALUES(cycle_days),
                service_level = VALUES(service_level), lookback_days = VALUES(lookback_days), updated_by = VALUES(updated_by)'
        )->execute([$site['id'], $v['leadTimeDays'], $v['cycleDays'], $v['serviceLevel'], $v['lookbackDays'], (string)($user['username'] ?? '')]);
        _iiLog($pdo, $user, 'params', $site['code'], $v);
        return ['success' => true, 'params' => $v];
    } catch (Throwable $e) {
        error_log('saveMinMaxParams: ' . $e->getMessage());
        return ['success' => false, 'message' => 'บันทึกค่าตั้งต้นไม่สำเร็จ'];
    }
}
