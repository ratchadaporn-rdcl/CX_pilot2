<?php
/**
 * CONNEXT — lib/history_stats.php
 * ประวัติเอกสาร + แดชบอร์ดหักเงิน (port ตรงจาก GAS Code.js) · getRequisitionStats เอาออก 2026-10-02 พร้อมหน้า "สถิติ"
 *
 *   rpc_getRequisitionHistory  ← GAS getRequisitionHistory(siteCode, username, roleLevel)
 *   rpc_getChargeDashboard     ← GAS getChargeDashboard(siteCode, fromMs, toMs, username)
 *
 * PHP 7.4 เท่านั้น · ตัวตน (role/site/ชื่อ) เป็น server-authoritative จาก session+DB
 * — args จาก client รับตามตำแหน่งเดิมเพื่อคง signature แต่ไม่ใช้ยืนยันสิทธิ์
 *
 * [Scenario 05 · 2026-09-28] จำนวนทุกจุดใช้ "หยิบจริง" (qty_actual) ถ้ามี — ประวัติแสดง "(x8 · ขอ 10)" เมื่อต่างกัน
 * · ใบ bypass (คีย์ย้อนหลังจากแบบฟอร์มกระดาษ) ติดป้าย "(bypass)" ท้ายชื่อชนิดใบ
 */

require_once __DIR__ . '/s05.php';
require_once __DIR__ . '/history_report.php';   // hrPayerMap / hrPhotoRefs (2026-09-30)

// =========================================================================
// Private helpers (prefix hs_ กันชนกับไฟล์ lib อื่น)
// =========================================================================

/** ระดับ role ปัจจุบันของผู้เรียก (ล้อ getUserRoleLevel_: user→Roles.level, sub→1, ไม่พบ→99) */
function hs_roleNum(PDO $pdo, ?array $user): int {
    if (!$user) return 99;
    if (($user['accountType'] ?? '') === 'subcontractor') return 1;
    $stmt = $pdo->prepare(
        "SELECT r.level FROM users u JOIN roles r ON r.id = u.role_id
         WHERE u.username = ? LIMIT 1"
    );
    $stmt->execute([trim((string)($user['username'] ?? ''))]);
    $lvl = $stmt->fetchColumn();
    return ($lvl === false) ? 99 : (int)$lvl;
}

/** สิทธิ์ตรวจสอบประจำวัน (ล้อ userCanDailyCheck_: เฉพาะ Users → Roles.CanDailyCheck) */
function hs_canDailyCheck(PDO $pdo, ?array $user): bool {
    if (!$user) return false;
    if (($user['accountType'] ?? '') !== 'user') return false;
    $stmt = $pdo->prepare(
        "SELECT r.can_daily_check FROM users u JOIN roles r ON r.id = u.role_id
         WHERE u.username = ? LIMIT 1"
    );
    $stmt->execute([trim((string)($user['username'] ?? ''))]);
    $v = $stmt->fetchColumn();
    return ($v !== false) && ((int)$v === 1);
}

/**
 * site ที่ใช้กรองจริง: R0 ใช้ค่าจาก args ('' = ทุกไซต์ — พฤติกรรม getEffectiveSiteCode
 * ฝั่ง client), role อื่นบังคับใช้ site จาก session เสมอ (server-authoritative)
 */
function hs_effectiveSite(?array $user, $argSite, int $roleNum): string {
    if ($roleNum === 0) return trim((string)$argSite);
    return trim((string)($user['siteCode'] ?? ''));
}

/** ชื่อที่เอกสารบันทึกไว้: SUB = SubName (fullName), user ปกติ = username */
function hs_effectiveName(?array $user): string {
    if (!$user) return '';
    if (($user['accountType'] ?? '') === 'subcontractor') {
        return trim((string)($user['fullName'] ?? ''));
    }
    return trim((string)($user['username'] ?? ''));
}

/** projects.code → id (ไม่พบ = null → ผลลัพธ์ว่างเหมือน GAS กรอง site ไม่แมตช์) */
function hs_projectIdByCode(PDO $pdo, string $code): ?int {
    $stmt = $pdo->prepare("SELECT id FROM projects WHERE code = ? LIMIT 1");
    $stmt->execute([$code]);
    $id = $stmt->fetchColumn();
    return ($id === false) ? null : (int)$id;
}

/** DATETIME (เวลาไทยใน DB) → epoch ms (client ทำ arithmetic กับค่านี้) */
function hs_msFromDb(?string $dt): int {
    if ($dt === null || $dt === '') return 0;
    try {
        $d = new DateTime($dt); // default tz = Asia/Bangkok (config.php)
        return $d->getTimestamp() * 1000;
    } catch (Throwable $e) {
        return 0;
    }
}

/** epoch ms (จาก client) → 'Y-m-d H:i:s' เวลาไทย สำหรับเทียบกับ doc_ts */
function hs_msToDbDate($ms): string {
    $sec = (int)floor(((float)$ms) / 1000);
    $d = new DateTime('@' . $sec);
    $d->setTimezone(new DateTimeZone('Asia/Bangkok'));
    return $d->format('Y-m-d H:i:s');
}

/** 'DD/MM/YYYY HH:MM' — ล้อ fmtDate ใน getRequisitionHistory ของ GAS */
function hs_fmtDateHist(?string $dt): string {
    if ($dt === null || $dt === '') return '';
    try {
        $d = new DateTime($dt);
        return $d->format('d/m/Y H:i');
    } catch (Throwable $e) {
        return (string)$dt;
    }
}

/** จำนวนแบบชีต: 5.000→'5', 2.500→'2.5' (GAS ต่อสตริงเลขจาก cell ตรง ๆ) */
function hs_qtyStr($v): string {
    if ($v === null || $v === '') return '';
    $f = (float)$v;
    if ($f == floor($f) && abs($f) < 1e15) return (string)(int)$f;
    return rtrim(rtrim(number_format($f, 3, '.', ''), '0'), '.');
}

/** ตัวเลข: integral → int (ให้ JSON ออกเป็น 3 ไม่ใช่ 3.0 เหมือน GAS) */
function hs_num($f) {
    $f = (float)$f;
    if ($f == floor($f) && abs($f) < 1e15) return (int)$f;
    return $f;
}

/** ช่วงเวลา: from = Number(fromMs)||0 · to = Number(toMs)||now+1วัน (พฤติกรรม GAS) */
function hs_rangeMs($fromArg, $toArg): array {
    $from = is_numeric($fromArg) ? (float)$fromArg : 0.0;
    $to   = is_numeric($toArg) ? (float)$toArg : 0.0;
    if ($from == 0.0) $from = 0.0;
    if ($to == 0.0) $to = (time() + 86400) * 1000.0;
    return [$from, $to];
}

// =========================================================================
// getRequisitionHistory(siteCode, username, roleLevel)
// รวม RD/OD/BD/IN — R0 หรือ ≥R6 เห็นทั้งไซต์, R1–R5 เห็นเฉพาะของตัวเอง
// คืน array ของ {docId, timestamp(ms), dateStr, type, typeName, detail, status[, rs]}
// เรียง timestamp ล่าสุดก่อน — error คืน [] (พฤติกรรม GAS)
// [2026-09-30] + mats [[รหัส IC, ชื่อ]] · payer (ผู้นำจ่าย — lib/history_report.php hrPayerMap) · photos / photoLinks
//   (จำนวนรูปในเครื่อง / ลิงก์รูประบบเดิม) ให้หน้าประวัติกรอง IC / ผู้นำจ่าย แล้ว Export รายงานพร้อมรูป (api/history_report.php)
// =========================================================================
function rpc_getRequisitionHistory(PDO $pdo, ?array $user, array $args) {
    try {
        // GAS: roleNum จาก arg roleLevel — ที่นี่ re-derive ฝั่ง server จาก session+DB
        $roleNum    = hs_roleNum($pdo, $user);
        $filterSelf = ($roleNum !== 0 && $roleNum <= 5);
        $callerUser = hs_effectiveName($user);
        $site       = hs_effectiveSite($user, isset($args[0]) ? $args[0] : '', $roleNum);

        $where  = [];
        $params = [];
        if ($site !== '') {
            $projectId = hs_projectIdByCode($pdo, $site);
            if ($projectId === null) return []; // ไม่มีไซต์นี้ → ไม่มีแถวแมตช์
            $where[]  = 'd.project_id = ?';
            $params[] = $projectId;
        }
        if ($filterSelf) {
            // GAS เทียบสตริงตรง (trim); ที่นี่ collation utf8mb4_unicode_ci = case-insensitive
            $where[]  = 'd.requester_username = ?';
            $params[] = $callerUser;
        }
        // [2026-09-29] ใบนับสต๊อก (SC) ไม่ใช่ใบเบิก — ไม่ขึ้นประวัติ (ดูที่หน้าตรวจสอบประจำวัน → นับสต๊อก)
        $where[] = "d.doc_type <> 'SC'";
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $sql = "SELECT d.doc_no, d.doc_type, d.status, d.doc_ts, d.return_ts, d.origin_type, d.writeoff_flag,
                       d.photo_url AS doc_photo, d.photo_return_url AS doc_photo_ret,
                       di.mat_code, di.qty, di.qty_actual, di.rs_no, di.photo_url AS item_photo, di.photo_return_url AS item_photo_ret,
                       m.name AS master_name
                FROM documents d
                JOIN document_items di ON di.document_id = d.id
                LEFT JOIN materials m ON m.mat_code = di.mat_code
                $whereSql
                ORDER BY FIELD(d.doc_type,'RD','OD','BD','IN'), d.id, di.id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $typeNames = [
            'RD' => 'เบิกวัสดุหลัก',
            'OD' => 'เบิกเบ็ดเตล็ด',
            'BD' => 'ยืมอุปกรณ์',
            'IN' => 'รับเข้าคลัง',
            'TD' => 'เบิกโอนย้ายข้ามไซต์',   // 2026-09-29
            'TG' => 'ย้าย Gate (ภายในไซต์)',   // 2026-10-02
        ];

        $grouped = []; // doc_no → row group (insertion order = ลำดับสแกนชีตเดิม)
        while (($r = $stmt->fetch()) !== false) {
            $docNo = (string)$r['doc_no'];
            if (!isset($grouped[$docNo])) {
                $type = (string)$r['doc_type'];
                // BD: ใช้เวลา return ถ้ามี ไม่งั้นเวลา borrow (พฤติกรรม GAS)
                $ts = ($type === 'BD' && !empty($r['return_ts'])) ? $r['return_ts'] : $r['doc_ts'];
                $grouped[$docNo] = [
                    'type'   => $type,
                    'ts'     => $ts,
                    'status' => ($r['status'] === null) ? '' : (string)$r['status'],
                    'rs'     => '',
                    'items'  => [],
                    'bypass' => strtolower(trim((string)($r['origin_type'] ?? ''))) === 'bypass',
                    // Scenario 05 ③ (2026-09-29): ใบยืมที่มีรายการตีเป็นชำรุด/สูญหาย
                    'writeoff' => isTrueFlag($r['writeoff_flag'] ?? 0),
                    // 2026-09-30: รหัส IC ในใบ + รูป (รายการ + ทั้งใบ · ไม่นับซ้ำ)
                    'origin' => (string)($r['origin_type'] ?? ''),
                    'mats'   => [],
                    'photo'  => [],
                ];
                foreach (hrPhotoRefs(($r['doc_photo'] ?? '') . ' ' . ($r['doc_photo_ret'] ?? '')) as $ref) {
                    $grouped[$docNo]['photo'][$ref] = true;
                }
            }
            foreach (hrPhotoRefs(($r['item_photo'] ?? '') . ' ' . ($r['item_photo_ret'] ?? '')) as $ref) {
                $grouped[$docNo]['photo'][$ref] = true;
            }
            $mcKey = strtoupper(trim((string)$r['mat_code']));
            if ($mcKey !== '' && !isset($grouped[$docNo]['mats'][$mcKey])) {
                $grouped[$docNo]['mats'][$mcKey] = trim((string)($r['master_name'] ?? ''));
            }
            // ชื่อวัสดุ: MaterialsMain.Name ถ้ามี (ไม่ว่าง) ไม่งั้น MatCode — เหมือน matMap ของ GAS
            $name = ($r['master_name'] !== null && trim((string)$r['master_name']) !== '')
                ? (string)$r['master_name'] : (string)$r['mat_code'];
            // [Scenario 05 ⑦] จำนวนหยิบจริง · แสดงจำนวนที่ขอเมื่อต่างกัน เช่น "ปูน (x8 · ขอ 10)"
            $effQty = s05EffQty($r);
            $grouped[$docNo]['items'][] = $name . ' (x' . hs_qtyStr($effQty)
                . (abs($effQty - (float)$r['qty']) > 0.0005 ? ' · ขอ ' . hs_qtyStr($r['qty']) : '') . ')';
            // IN: RS = ค่าแรกที่ไม่ว่างในกลุ่ม
            if ($grouped[$docNo]['type'] === 'IN' && $grouped[$docNo]['rs'] === '' && $r['rs_no'] !== null) {
                $grouped[$docNo]['rs'] = trim((string)$r['rs_no']);
            }
        }

        $results = [];
        $ord = 0;
        $origins = [];
        foreach ($grouped as $docNo => $g) { $origins[(string)$docNo] = $g['origin']; }
        $payers = $origins ? hrPayerMap($pdo, $origins) : [];   // ผู้นำจ่าย (เจ้าของบัตรที่แตะเปิดใบ) — 2026-09-30
        foreach ($grouped as $docNo => $g) {
            $mats = [];
            foreach ($g['mats'] as $code => $nm) { $mats[] = [(string)$code, (string)$nm]; }
            $local = 0;
            foreach (array_keys($g['photo']) as $ref) { if (hrIsLocalPhoto((string)$ref)) { $local++; } }
            $row = [
                'docId'     => (string)$docNo,
                'timestamp' => hs_msFromDb($g['ts']),
                'dateStr'   => hs_fmtDateHist($g['ts']),
                'type'      => $g['type'],
                'typeName'  => (isset($typeNames[$g['type']]) ? $typeNames[$g['type']] : $g['type'])
                             . ($g['bypass'] ? ' (bypass)' : '')
                             . ($g['writeoff'] ? ' (มีรายการชำรุด/สูญหาย)' : ''),
                'detail'    => implode(', ', $g['items']),
                'status'    => $g['status'],
                'mats'      => $mats,
                'payer'     => (string)($payers[(string)$docNo] ?? ''),
                'photos'    => $local,
                'photoLinks'=> count($g['photo']) - $local,
            ];
            if ($g['bypass']) {
                $row['bypass'] = true;   // คีย์ย้อนหลังจากแบบฟอร์มกระดาษ (Scenario 05 ⑩)
            }
            if ($g['writeoff']) {
                $row['writeoff'] = true; // ใบยืมที่มีรายการตีเป็นชำรุด/สูญหาย (Scenario 05 ③)
            }
            if ($g['type'] === 'IN') {
                $row['rs'] = $g['rs'];
            }
            $row['_o'] = $ord++;
            $results[] = $row;
        }

        // เรียง timestamp มาก→น้อย (เสถียร: เสมอกันคงลำดับเดิมเหมือน JS sort)
        usort($results, function ($a, $b) {
            if ($a['timestamp'] != $b['timestamp']) {
                return ($b['timestamp'] < $a['timestamp']) ? -1 : 1;
            }
            return $a['_o'] - $b['_o'];
        });
        foreach ($results as $i => $r) { unset($results[$i]['_o']); }

        return array_values($results);
    } catch (Throwable $e) {
        error_log('getRequisitionHistory: ' . $e->getMessage());
        return [];
    }
}

// =========================================================================
// getChargeDashboard(siteCode, fromMs, toMs, username)
// สรุปหักเงิน RD+OD (ทุกสถานะในช่วงเวลา — ตาม GAS ที่ไม่กรอง Status) ต่อรายการ
// สิทธิ์เดียวกับหน้าตรวจสอบประจำวัน (CanDailyCheck) — เช็คจาก session ไม่ใช่ arg
// =========================================================================
function rpc_getChargeDashboard(PDO $pdo, ?array $user, array $args) {
    try {
        if (!hs_canDailyCheck($pdo, $user)) {
            return ['success' => false, 'message' => 'no_permission'];
        }
        $roleNum = hs_roleNum($pdo, $user);
        $site    = hs_effectiveSite($user, isset($args[0]) ? $args[0] : '', $roleNum);
        list($from, $to) = hs_rangeMs($args[1] ?? null, $args[2] ?? null);

        $rows = [];
        $skipQuery = false;
        $where  = ["d.doc_type IN ('RD','OD')", 'd.doc_ts >= ?', 'd.doc_ts <= ?'];
        $params = [hs_msToDbDate($from), hs_msToDbDate($to)];
        if ($site !== '') {
            $projectId = hs_projectIdByCode($pdo, $site);
            if ($projectId === null) { $skipQuery = true; }
            else { $where[] = 'd.project_id = ?'; $params[] = $projectId; }
        }
        if (!$skipQuery) {
            $sql = "SELECT d.doc_type, d.doc_ts, d.receiver_name AS recv,
                           " . s05EffQtySql('di') . " AS qty, di.mat_code, di.charge_money,
                           m.name AS mname, m.subgroup_name AS sgrp
                    FROM documents d
                    JOIN document_items di ON di.document_id = d.id
                    LEFT JOIN materials m ON m.mat_code = di.mat_code
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY FIELD(d.doc_type,'RD','OD'), d.id, di.id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
        }

        $bySubName = []; $bySubgroup = []; $byMaterial = [];
        $byType = [
            'RD' => ['items' => 0, 'charged' => 0, 'qty' => 0.0],
            'OD' => ['items' => 0, 'charged' => 0, 'qty' => 0.0],
        ];
        $series = []; // day → ['charged'=>n,'notCharged'=>n]
        $totalItems = 0; $chargedItems = 0; $chargedQty = 0.0; $totalQty = 0.0;

        // ล้อ bump() ของ getChargeDashboard: key ว่าง → '(ไม่ระบุ)'
        $bump = function (array &$map, string $key, float $qty, bool $charge): void {
            if ($key === '') $key = '(ไม่ระบุ)';
            if (!isset($map[$key])) $map[$key] = ['items' => 0, 'charged' => 0, 'qty' => 0.0, 'chargedQty' => 0.0];
            $map[$key]['items'] += 1;
            $map[$key]['qty']   += $qty;
            if ($charge) { $map[$key]['charged'] += 1; $map[$key]['chargedQty'] += $qty; }
        };

        foreach ($rows as $r) {
            $type    = (string)$r['doc_type'];
            $matCode = trim((string)$r['mat_code']);
            $qty     = (float)$r['qty'];
            $recv    = trim((string)($r['recv'] ?? ''));
            $charge  = ((int)$r['charge_money'] === 1);
            $sub = ($r['sgrp'] !== null) ? trim((string)$r['sgrp']) : '';
            if ($sub === '') $sub = '(ไม่ระบุหมวด)';

            $bump($bySubName, $recv, $qty, $charge);
            $bump($bySubgroup, $sub, $qty, $charge);
            if ($matCode !== '') {
                if (!isset($byMaterial[$matCode])) {
                    $mn = ($r['mname'] !== null) ? trim((string)$r['mname']) : '';
                    $byMaterial[$matCode] = ['items' => 0, 'charged' => 0, 'qty' => 0.0,
                                             'name' => ($mn !== '') ? $mn : $matCode];
                }
                $byMaterial[$matCode]['items'] += 1;
                $byMaterial[$matCode]['qty']   += $qty;
                if ($charge) $byMaterial[$matCode]['charged'] += 1;
            }
            $byType[$type]['items'] += 1;
            $byType[$type]['qty']   += $qty;
            if ($charge) $byType[$type]['charged'] += 1;

            $totalItems += 1; $totalQty += $qty;
            if ($charge) { $chargedItems += 1; $chargedQty += $qty; }

            $dk = substr((string)$r['doc_ts'], 0, 10);
            if (!isset($series[$dk])) $series[$dk] = ['charged' => 0, 'notCharged' => 0];
            if ($charge) $series[$dk]['charged'] += 1; else $series[$dk]['notCharged'] += 1;
        }

        // topList: charged desc → items desc → ลำดับพบก่อน
        $topList = function (array $map, int $n): array {
            $out = []; $i = 0;
            foreach ($map as $k => $v) {
                $out[] = ['name' => (string)$k, 'items' => $v['items'], 'charged' => $v['charged'],
                          'qty' => hs_num($v['qty']),
                          'chargedQty' => hs_num(isset($v['chargedQty']) ? $v['chargedQty'] : 0),
                          '_o' => $i++];
            }
            usort($out, function ($a, $b) {
                if ($a['charged'] != $b['charged']) return $b['charged'] - $a['charged'];
                if ($a['items'] != $b['items'])     return $b['items'] - $a['items'];
                return $a['_o'] - $b['_o'];
            });
            $out = array_slice($out, 0, $n);
            foreach ($out as $j => $r) { unset($out[$j]['_o']); }
            return array_values($out);
        };

        $topMaterials = []; $i = 0;
        foreach ($byMaterial as $k => $v) {
            $topMaterials[] = ['matCode' => (string)$k, 'name' => $v['name'], 'items' => $v['items'],
                               'charged' => $v['charged'], 'qty' => hs_num($v['qty']), '_o' => $i++];
        }
        usort($topMaterials, function ($a, $b) {
            if ($a['charged'] != $b['charged']) return $b['charged'] - $a['charged'];
            if ($a['items'] != $b['items'])     return $b['items'] - $a['items'];
            return $a['_o'] - $b['_o'];
        });
        $topMaterials = array_slice($topMaterials, 0, 15);
        foreach ($topMaterials as $j => $r) { unset($topMaterials[$j]['_o']); }

        $days = array_keys($series);
        sort($days, SORT_STRING);
        $ts = ['labels' => array_values($days), 'charged' => [], 'notCharged' => []];
        foreach ($days as $d) {
            $ts['charged'][]    = $series[$d]['charged'];
            $ts['notCharged'][] = $series[$d]['notCharged'];
        }

        foreach (['RD', 'OD'] as $t) { $byType[$t]['qty'] = hs_num($byType[$t]['qty']); }

        return [
            'success'      => true,
            'summary'      => [
                'totalItems'      => $totalItems,
                'chargedItems'    => $chargedItems,
                'notChargedItems' => $totalItems - $chargedItems,
                'chargedQty'      => hs_num($chargedQty),
                'totalQty'        => hs_num($totalQty),
                'byType'          => $byType,
            ],
            'bySubName'    => $topList($bySubName, 15),
            'bySubgroup'   => $topList($bySubgroup, 15),
            'topMaterials' => array_values($topMaterials),
            'timeSeries'   => $ts,
        ];
    } catch (Throwable $e) {
        error_log('getChargeDashboard: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
