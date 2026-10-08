<?php
/**
 * CONNEXT — lib/pick_alerts.php : การ์ดเตือนบน Dashboard ตามเอกสาร 05 Scenario (2026-09-28)
 *
 * RPC getPickAlerts(siteCode) →
 *   shortfalls  รายการที่ "หยิบจริงน้อยกว่าที่ขอ" 30 วันล่าสุด แยก ประตู × รหัส IC (⑦)
 *               เหตุผล "ของไม่พอ" (เลือกจากรายการตั้งแต่ 2 ต.ค. · ใบเก่าเดาจากคำ) = สัญญาณว่ายอดในระบบเพี้ยน → ให้สโตร์ตรวจนับที่ G นั้น
 *   overCap     รอบเบิกที่เวลารวมเกินเพดาน (ขอเวลาเพิ่มด้วยบัตรสายสโตร์) 14 วันล่าสุด (⑧)
 *               จาก gate_round_events (logRoundEvent / closeGate) + error_logs ที่ตู้ส่งเป็นข้อความ
 *   alarm4      [2026-09-30] ประตูที่ ALARM 4 ยังค้าง (LATCHED) — ตู้หยุดรับงานจนสายสโตร์แตะบัตร + กดยืนยันปิดสัญญาณที่ตู้
 *               = แถว "Person detected … LATCHED" ล่าสุดของประตูที่ยังไม่มีแถว "ALARM 4 killed …" ตามมา (7 วัน)
 *   zeroPick    [2026-09-30] ใบที่หยิบจริง 0 ทุกรายการ (ยกเลิกใบทางอ้อมหลังแตะบัตร · GP-05) 30 วัน พร้อมเหตุผล —
 *               ตั้งแต่ 2 ต.ค. shortfalls นับตามเหตุผล (สแกนผิด/ไม่ต้องการแล้ว ไม่นับ) จึงไม่ตัดใบเหล่านี้ออกจาก shortfalls แล้ว
 *
 * สิทธิ์ดู: ADM (R0) และระดับ ≥ 8 ทุกไซต์ · สายสโตร์ (can_req) เฉพาะไซต์ตัวเอง — ชุดเดียวกับ Error log
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/inventory_insights.php';   // _iiSite / _iiRole
require_once __DIR__ . '/s05.php';

function rpc_getPickAlerts(PDO $pdo, ?array $user, array $args) {
    try {
        $site = _iiSite($pdo, $user, $args[0] ?? '', true);
        if ($site['id'] === null) {
            return ['success' => false, 'message' => 'ไม่พบไซต์ ' . $site['code']];
        }
        $role = _iiRole($pdo, $user);
        $canView = $role['level'] === 0 || $role['level'] >= 8
                || ($role['canReq'] && strcasecmp(trim((string)($user['siteCode'] ?? '')), $site['code']) === 0);
        if (!$canView) {
            return ['success' => false, 'message' => 'no_permission'];
        }
        $P = (int)$site['id'];
        $settings = s05GateSettings($pdo, $P);

        // ---- 1) หยิบจริงน้อยกว่าที่ขอ (30 วัน) ----
        $st = $pdo->prepare(
            "SELECT d.doc_no, d.doc_type, d.doc_ts, d.requester_username, g.gate_code, d.gate_id,
                    di.material_id, di.mat_code, COALESCE(NULLIF(m.name, ''), di.mat_name) AS name, m.unit,
                    di.qty, di.qty_actual, di.actual_reason
               FROM document_items di
               JOIN documents d ON d.id = di.document_id
               LEFT JOIN gates g ON g.id = d.gate_id
               LEFT JOIN materials m ON m.mat_code = di.mat_code
              WHERE d.project_id = ? AND d.doc_type IN ('RD','OD','BD','TD')   /* + TD เบิกโอนย้ายข้ามไซต์ (2026-09-29) */
                AND di.qty_actual IS NOT NULL AND di.qty_actual < di.qty
                AND d.doc_ts >= CURDATE() - INTERVAL 29 DAY
                /* [2026-10-02 · GP-05] นับ ของไม่พอ จากเหตุผลที่เลือก (s05ReasonIsShortage) — ใบที่หยิบจริง 0 ทุกรายการ
                   ไม่ถูกตัดออกแล้ว: สแกนผิด ไม่นับเองตามเหตุผล · ของไม่พอ ทั้งใบคือของไม่พอจริง (ยังขึ้นหมวด zeroPick ด้วย) */
              ORDER BY d.doc_ts DESC, d.id DESC
              LIMIT 400"
        );
        $st->execute([$P]);
        $groups = [];
        foreach ($st->fetchAll() as $r) {
            $gate = strtoupper(trim((string)($r['gate_code'] ?? '')));
            $mc   = trim((string)$r['mat_code']);
            $reason = trim((string)($r['actual_reason'] ?? ''));
            // [2026-10-02 · GP-05] แยกกลุ่ม "ของไม่พอ" ออกจากเหตุผลอื่น — ยอดที่ลดเพราะสแกนผิด/ไม่ต้องการแล้ว ไม่ไปรวมในยอดขาด
            $isShort = $reason !== '' && s05ReasonIsShortage($reason);
            $k    = $gate . '|' . $mc . '|' . ($isShort ? 'S' : 'O');
            if (!isset($groups[$k])) {
                $groups[$k] = [
                    'gate' => $gate, 'gateId' => $r['gate_id'] !== null ? (int)$r['gate_id'] : null,
                    'materialId' => $r['material_id'] !== null ? (int)$r['material_id'] : null,
                    'matCode' => $mc, 'name' => (string)$r['name'], 'unit' => (string)($r['unit'] ?? ''),
                    'lines' => 0, 'shortQty' => 0.0, 'shortage' => $isShort, 'lastAt' => (string)$r['doc_ts'],
                    'docs' => [], 'reasons' => [],
                ];
            }
            $g = &$groups[$k];
            $g['lines']++;
            $g['shortQty'] += (float)$r['qty'] - (float)$r['qty_actual'];
            if (count($g['docs']) < 6) {
                $g['docs'][] = ['docId' => (string)$r['doc_no'], 'at' => substr((string)$r['doc_ts'], 0, 16),
                                'req' => (float)$r['qty'], 'actual' => (float)$r['qty_actual'],
                                'reason' => $reason, 'by' => (string)$r['requester_username']];
            }
            if ($reason !== '' && !in_array($reason, $g['reasons'], true) && count($g['reasons']) < 4) {
                $g['reasons'][] = $reason;
            }
            unset($g);
        }
        // คงเหลือปัจจุบันที่ประตูนั้น (ประกอบการตรวจนับ)
        $onHandSel = $pdo->prepare('SELECT on_hand FROM stock_gate_balances WHERE project_id = ? AND material_id = ? AND gate_id = ?');
        $short = [];
        foreach ($groups as $g) {
            $onHand = null;
            if ($g['materialId'] && $g['gateId']) {
                $onHandSel->execute([$P, $g['materialId'], $g['gateId']]);
                $v = $onHandSel->fetchColumn();
                if ($v !== false) { $onHand = (float)$v; }
            }
            $short[] = [
                'gate' => $g['gate'], 'matCode' => $g['matCode'], 'name' => $g['name'], 'unit' => $g['unit'],
                'lines' => $g['lines'], 'shortQty' => round($g['shortQty'], 3), 'shortage' => $g['shortage'],
                'lastAt' => substr($g['lastAt'], 0, 16), 'onHand' => $onHand, 'docs' => $g['docs'], 'reasons' => $g['reasons'],
            ];
        }
        usort($short, function ($a, $b) {
            if ($a['shortage'] !== $b['shortage']) return $a['shortage'] ? -1 : 1;
            return strcmp($b['lastAt'], $a['lastAt']);
        });

        // ---- 2) รอบเกินเพดานเวลา (14 วัน) ----
        $over = [];
        $st = $pdo->prepare(
            "SELECT picking_id, gate_code, event, total_min, cardholder, card_id, item_count, created_at
               FROM gate_round_events
              WHERE project_id = ? AND created_at >= CURDATE() - INTERVAL 13 DAY
                AND (over_cap = 1 OR (event = 'round_end' AND total_min > ?))
              ORDER BY created_at DESC LIMIT 200"
        );
        $st->execute([$P, (float)$settings['pickCapMin']]);
        foreach ($st->fetchAll() as $r) {
            $pk = trim((string)($r['picking_id'] ?? ''));
            $key = $pk !== '' ? $pk : ('#' . count($over));
            if (!isset($over[$key])) {
                $over[$key] = ['pickingId' => $pk, 'gate' => (string)($r['gate_code'] ?? ''), 'at' => substr((string)$r['created_at'], 0, 16),
                               'totalMin' => null, 'items' => null, 'cardholders' => [], 'source' => 'events'];
            }
            if ($r['total_min'] !== null) {
                $over[$key]['totalMin'] = max((float)$over[$key]['totalMin'], (float)$r['total_min']);
            }
            if ($r['item_count'] !== null && $over[$key]['items'] === null) { $over[$key]['items'] = (int)$r['item_count']; }
            $h = trim((string)($r['cardholder'] ?? ''));
            if ($h !== '' && !in_array($h, $over[$key]['cardholders'], true)) { $over[$key]['cardholders'][] = $h; }
        }
        // ข้อความจากตู้ที่ส่งทาง logError (ถ้าตู้ยังไม่ใช้ logRoundEvent)
        $st = $pdo->prepare(
            "SELECT gate_code, message, created_at FROM error_logs
              WHERE project_id = ? AND created_at >= CURDATE() - INTERVAL 13 DAY
                AND (message LIKE '%OVER-CAP%' OR message LIKE '%OVER CAP%' OR message LIKE '%past the cap%' OR message LIKE '%เกินเพดาน%')
              ORDER BY created_at DESC LIMIT 200"
        );
        $st->execute([$P]);
        foreach ($st->fetchAll() as $r) {
            $msg = (string)$r['message'];
            $pk = preg_match('/PickingID=([A-Za-z0-9_-]+)/i', $msg, $m) ? $m[1] : '';
            $key = $pk !== '' ? $pk : ('#e' . count($over));
            if (!isset($over[$key])) {
                $over[$key] = ['pickingId' => $pk, 'gate' => (string)($r['gate_code'] ?? ''), 'at' => substr((string)$r['created_at'], 0, 16),
                               'totalMin' => null, 'items' => null, 'cardholders' => [], 'source' => 'error_logs'];
            }
            if (preg_match('/total=([\d.]+)m/i', $msg, $m)) {
                $over[$key]['totalMin'] = max((float)$over[$key]['totalMin'], (float)$m[1]);
            } elseif (preg_match('/total=(\d+):(\d{2})\b/', $msg, $m)) {
                // [2026-10-08] ตู้ส่ง "… total=12:00 OVER CAP (store card)" (นาที:วินาที)
                $over[$key]['totalMin'] = max((float)$over[$key]['totalMin'], round((int)$m[1] + (int)$m[2] / 60, 2));
            }
            if (preg_match('/cardholder=([^|]+)/i', $msg, $m)) {
                $h = trim($m[1]);
                if ($h !== '' && !in_array($h, $over[$key]['cardholders'], true)) { $over[$key]['cardholders'][] = $h; }
            }
        }

        // ---- 3) ALARM 4 ค้าง (2026-09-30) — ตู้ส่ง "… LATCHED …" ตอนเกิด และ "ALARM 4 killed …" เมื่อสายสโตร์ปิดสัญญาณ ----
        $latched = [];
        $st = $pdo->prepare(
            "SELECT gate_code, message, created_at FROM error_logs
              WHERE project_id = ? AND created_at >= NOW() - INTERVAL 7 DAY
                AND ((message LIKE 'Person detected inside the zone%' AND message LIKE '%LATCHED%')
                     OR message LIKE 'ALARM 4 killed%')
              ORDER BY created_at, id"
        );
        $st->execute([$P]);
        foreach ($st->fetchAll() as $r) {
            $g = strtoupper(trim((string)($r['gate_code'] ?? '')));
            if (stripos((string)$r['message'], 'ALARM 4 killed') === 0) { unset($latched[$g]); continue; }
            if (!isset($latched[$g])) {
                $latched[$g] = ['gate' => $g, 'since' => substr((string)$r['created_at'], 0, 16), 'events' => 0];
            }
            $latched[$g]['events']++;
        }

        // ---- 4) ALARM: ใบที่หยิบจริง 0 ทุกรายการ = ยกเลิกใบทางอ้อมหลังแตะบัตร (30 วัน · 2026-09-30 · GP-05) ----
        //      อ่านจากข้อมูลใบโดยตรง (รวมใบที่ยืนยันก่อนมี error_logs "Zero pick:") · ผู้บันทึก = activity_log gate_confirm
        $zero = [];
        $st = $pdo->prepare(
            "SELECT d.id, d.doc_no, d.doc_type, d.doc_ts, g.gate_code, COUNT(*) AS n_items,
                    GROUP_CONCAT(DISTINCT NULLIF(TRIM(di.actual_reason), '') ORDER BY di.id SEPARATOR ' / ') AS reasons,
                    (SELECT gl.card_id FROM gate_logs gl WHERE gl.doc_no = d.doc_no AND gl.card_id IS NOT NULL AND gl.card_id <> '' LIMIT 1) AS card_id,
                    (SELECT al.user_name FROM activity_log al WHERE al.entity_type = 'document' AND al.entity_id = d.doc_no
                        AND al.action = 'gate_confirm' ORDER BY al.id DESC LIMIT 1) AS confirmed_by
               FROM documents d
               JOIN document_items di ON di.document_id = d.id
               LEFT JOIN gates g ON g.id = d.gate_id
              WHERE d.project_id = ? AND d.doc_type IN ('RD','OD','BD','TD')
                AND d.doc_ts >= CURDATE() - INTERVAL 29 DAY
              GROUP BY d.id
             HAVING SUM(di.qty_actual IS NULL) = 0 AND SUM(di.qty_actual > 0.0005) = 0 AND SUM(di.qty) > 0.0005
              ORDER BY d.doc_ts DESC, d.id DESC
              LIMIT 100"
        );
        $st->execute([$P]);
        $zrows = $st->fetchAll();
        $holders = s05CardHolders($pdo, array_map(function ($r) { return (string)$r['card_id']; }, $zrows));
        foreach ($zrows as $r) {
            $cid = trim((string)$r['card_id']);
            $zero[] = [
                'docId' => (string)$r['doc_no'], 'type' => (string)$r['doc_type'],
                'gate' => strtoupper(trim((string)($r['gate_code'] ?? ''))), 'at' => substr((string)$r['doc_ts'], 0, 16),
                'items' => (int)$r['n_items'], 'reasons' => (string)($r['reasons'] ?? ''),
                'by' => (string)($r['confirmed_by'] ?? ''), 'holder' => $cid !== '' ? ($holders[$cid] ?? $cid) : '',
            ];
        }

        return [
            'success' => true, 'siteCode' => $site['code'], 'siteName' => $site['name'],
            'capMin' => (int)$settings['pickCapMin'],
            'shortfalls' => array_slice($short, 0, 60),
            'shortageCount' => count(array_filter($short, function ($s) { return $s['shortage']; })),
            'overCap' => array_values($over),
            'alarm4' => array_values($latched),
            'zeroPick' => $zero,
        ];
    } catch (Throwable $e) {
        error_log('getPickAlerts: ' . $e->getMessage());
        return ['success' => false, 'message' => 'โหลดการแจ้งเตือนไม่สำเร็จ'];
    }
}
