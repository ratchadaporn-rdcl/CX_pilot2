<?php
/**
 * CONNEXT — lib/stockcount.php : SC ใบนับสต๊อก — QR เข้า gate จากหน้า "ตรวจสอบประจำวัน" [PHP port 2026-09-29] — ไม่มีใน GAS
 *
 *   ทางเดิน (ตามที่ผู้ใช้เลือก 2026-09-29):
 *     1) ผู้มีสิทธิ์ตรวจสอบประจำวัน (roles.can_daily_check) สร้างใบนับของ G หนึ่ง → รายการ = ทุกรายการที่มียอดใน G นั้น
 *        (ใบนับที่ยังไม่จบของ G เดียวกันมีได้ใบเดียว — สร้างซ้ำ = ได้ใบเดิม)
 *     2) QR → สแกนที่ตู้ G นั้น + แตะบัตร → ประตูเปิด (รอบนับต้องแยกจากใบอื่น — api/gate.php)
 *     3) นับแบบไม่เห็นยอดในระบบ (blind) → กรอกผลนับครบทุกรายการ (+ ของที่พบเพิ่มใน G) → บันทึก = Confirmed
 *        ยอดในระบบ = on_hand ของ G ณ เวลาบันทึก · ปิดประตู = ปิดงาน (ไม่ตัด/ไม่เพิ่มสต๊อก — lib/stock.php)
 *     4) ผลต่าง (นับได้ − ในระบบ) รอ ADM หรือ R8 ขึ้นไปอนุมัติปรับยอด (หน้าอนุมัติ / แท็บนับสต๊อก)
 *        อนุมัติ = ยอดที่ G ขยับเท่าผลต่าง (applyBalanceDelta) · ไม่อนุมัติต้องใส่เหตุผล → ตาราง stock_adjustments
 *   KPI: Stock Accuracy 30 วัน = รายการที่นับตรงยอด ÷ รายการที่นับทั้งหมด (ใบที่ปิดงานแล้ว)
 *
 * เก็บใน documents/document_items เดิม: doc_type 'SC' · qty = ยอดในระบบ · qty_actual = นับได้ · status Approved → Completed
 *
 * RPC:
 *   getStockCountData()                 ประตูของไซต์ + ใบนับที่ค้าง/ล่าสุด + KPI + สิทธิ์
 *   createStockCount(gateCode)          สร้าง (หรือคืนใบเดิมที่ยังไม่จบ) → ข้อมูล QR
 *   getStockCountSheet(docNo)           รายการนับ (ยังไม่บันทึก = ไม่ส่งยอดในระบบ)
 *   saveStockCount(payload)             {docNo, items:[{itemId, counted}], extra:[{matCode, counted}], note}
 *   getStockAdjustQueue()               ผลต่างที่รออนุมัติของไซต์ (หน้าอนุมัติ)
 *   decideStockAdjust(payload)          {docNo, decisions:[{itemId, action:'approve'|'reject', note}]}
 *
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/s05.php';
require_once __DIR__ . '/docnum.php';
require_once __DIR__ . '/doc_ext.php';

const SC_EPS = 0.0005;
const SC_MAX_EXTRA = 50;

// =========================================================================
// helpers
// =========================================================================

/** สิทธิ์ของผู้ใช้ใน session → ['count' => นับได้, 'decide' => อนุมัติปรับยอดได้, 'role' => docExtUserRole] */
function _scPerms(PDO $pdo, ?array $user): array {
    $r = docExtUserRole($pdo, $user);
    $isUser = $user && ($user['accountType'] ?? '') === 'user';
    return [
        'count'  => $isUser && $r['canDaily'],
        'decide' => $isUser && ($r['code'] === 'ADM' || ($r['level'] >= 8 && $r['level'] < 99)),
        'role'   => $r,
    ];
}

/** ใบนับ (หัว) ตามเลข + ประตู + สถานะที่ประตู */
function _scDoc(PDO $pdo, string $docNo, bool $lock = false): ?array {
    $st = $pdo->prepare(
        "SELECT d.id, d.doc_no, d.project_id, d.gate_id, d.status, d.doc_ts, d.requester_username, d.notice,
                g.gate_code, g.name AS gate_name, g.hardware_close,
                gl.id AS gl_id, gl.status AS gate_status, gl.picking_id, gl.card_id, gl.scanned_at
           FROM documents d
           LEFT JOIN gates g ON g.id = d.gate_id
           LEFT JOIN gate_logs gl ON gl.doc_no = d.doc_no
          WHERE d.doc_no = ? AND d.doc_type = 'SC'" . ($lock ? ' FOR UPDATE' : '')
    );
    $st->execute([trim($docNo)]);
    $d = $st->fetch();
    return $d ?: null;
}

/** ขั้นของใบนับ: awaiting (รอสแกน) · counting (ประตูเปิด รอกรอกผล) · counted (บันทึกแล้ว รอปิดประตู) · done · cancelled */
function _scStage(array $d): string {
    $s = mb_strtolower(trim((string)$d['status']), 'UTF-8');
    if (strpos($s, 'cancel') !== false || strpos($s, 'reject') !== false) { return 'cancelled'; }
    if (strpos($s, 'complete') !== false) { return 'done'; }
    $g = strtolower(trim((string)($d['gate_status'] ?? '')));
    if ($g === 'opened' || $g === 'scanned') { return 'counting'; }
    if ($g === 'confirmed' || $g === 'closed') { return 'counted'; }
    if ($g === 'cancelled') { return 'cancelled'; }
    return 'awaiting';
}

function _scStageThai(string $stage): string {
    $m = [
        'awaiting'  => 'รอสแกน QR ที่ประตู',
        'counting'  => 'ประตูเปิดแล้ว — รอกรอกผลนับ',
        'counted'   => 'บันทึกผลนับแล้ว — รอปิดประตู',
        'done'      => 'ปิดงานแล้ว',
        'cancelled' => 'ยกเลิกแล้ว',
    ];
    return $m[$stage] ?? $stage;
}

/** ผู้ใช้เป็นคนบันทึกผลนับของใบนี้ และไม่ใช่ PM ของไซต์ → ปรับยอดเองไม่ได้ (GP-16 · 2026-10-02) */
function _scIsOwnCount(PDO $pdo, ?array $user, string $docNo, int $projectId): bool {
    if (!$user) { return false; }
    $me = trim((string)($user['username'] ?? ''));
    $info = _scCountedInfo($pdo, [$docNo]);
    $by = (string)($info[$docNo]['by'] ?? '');
    if ($me === '' || $by === '' || !eqUser($by, $me)) { return false; }
    return !docExtIsProjectPm($pdo, $me, $projectId);
}

/** ผู้บันทึกผลนับ + เวลา (activity_log 'sc_count') */
function _scCountedInfo(PDO $pdo, array $docNos): array {
    $out = [];
    if (!$docNos) { return $out; }
    $ph = implode(',', array_fill(0, count($docNos), '?'));
    $st = $pdo->prepare("SELECT entity_id, user_name, created_at FROM activity_log
                          WHERE entity_type = 'document' AND action = 'sc_count' AND entity_id IN ($ph) ORDER BY id");
    $st->execute(array_values($docNos));
    foreach ($st->fetchAll() as $r) {
        $out[(string)$r['entity_id']] = ['by' => (string)$r['user_name'], 'at' => substr((string)$r['created_at'], 0, 16)];
    }
    return $out;
}

/** สรุปผลของใบ: รายการ · นับแล้ว · ตรง · ต่าง · รออนุมัติ · ปรับแล้ว · ไม่ปรับ */
function _scSummaries(PDO $pdo, array $docIds): array {
    $out = [];
    if (!$docIds) { return $out; }
    $ph = implode(',', array_fill(0, count($docIds), '?'));
    $st = $pdo->prepare(
        "SELECT di.document_id, di.id, di.qty, di.qty_actual, a.status AS adj_status
           FROM document_items di
           LEFT JOIN stock_adjustments a ON a.item_id = di.id
          WHERE di.document_id IN ($ph)"
    );
    $st->execute(array_values($docIds));
    foreach ($st->fetchAll() as $r) {
        $k = (int)$r['document_id'];
        if (!isset($out[$k])) {
            $out[$k] = ['items' => 0, 'counted' => 0, 'match' => 0, 'diff' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0,
                        'plus' => 0.0, 'minus' => 0.0];
        }
        $out[$k]['items']++;
        if ($r['qty_actual'] === null) { continue; }
        $out[$k]['counted']++;
        $delta = round((float)$r['qty_actual'] - (float)$r['qty'], 3);
        if (abs($delta) < SC_EPS) { $out[$k]['match']++; continue; }
        $out[$k]['diff']++;
        if ($delta > 0) { $out[$k]['plus'] += $delta; } else { $out[$k]['minus'] += -$delta; }
        $as = (string)($r['adj_status'] ?? '');
        if ($as === 'approved') { $out[$k]['approved']++; }
        elseif ($as === 'rejected') { $out[$k]['rejected']++; }
        else { $out[$k]['pending']++; }
    }
    foreach ($out as $k => $v) {
        $out[$k]['accuracy'] = $v['counted'] > 0 ? round($v['match'] * 100 / $v['counted'], 1) : null;
        $out[$k]['plus']  = round($v['plus'], 3);
        $out[$k]['minus'] = round($v['minus'], 3);
    }
    return $out;
}

/**
 * ใบอื่นของประตูนี้ที่ของขยับไปแล้วแต่ยอดในระบบยังไม่ขยับ (ใบยังไม่ปิดงาน)
 *   open  = แตะบัตรแล้ว (Opened/Scanned) รอบกำลังหยิบ/ค้างกลางคัน → ห้ามเริ่มนับ (ไม่รู้ว่าหยิบไปเท่าไร)
 *   stuck = ยืนยันแล้ว (Confirmed) แต่ใบยังไม่ปิดงาน (ประตูมีตู้ที่ไม่ได้รับ closeGate) → นับได้ แต่ผลต่างของรหัสในใบเหล่านี้
 *           อาจมาจากใบค้าง — แจ้งผู้นับ/ผู้อนุมัติพร้อมยอดต่อรหัส (stuckQty: material_id → + ของเข้า / − ของออก)
 *   ใบที่ปิดงานแล้วแต่แถวประตูค้าง Confirmed (ข้อมูลยุคเก่า) ไม่นับ · ขาคืน (…RT) ไม่นับ
 */
function _scGatePendingDocs(PDO $pdo, int $projectId, int $gateId, string $exceptDocNo = ''): array {
    $st = $pdo->prepare(
        "SELECT gl.doc_no, gl.status, d.id AS doc_id, d.doc_type, d.status AS doc_status
           FROM gate_logs gl JOIN documents d ON d.id = gl.document_id
          WHERE gl.project_id = ? AND gl.gate_id = ? AND gl.leg = 'out' AND gl.doc_no <> ?
            AND gl.status IN ('Opened','Scanned','Confirmed')
          ORDER BY gl.id"
    );
    $st->execute([$projectId, $gateId, $exceptDocNo]);
    $out = ['open' => [], 'stuck' => [], 'stuckQty' => [], 'stuckByMat' => []];
    $its = $pdo->prepare('SELECT material_id, qty, qty_actual FROM document_items WHERE document_id = ? AND stock_deducted = 0 AND material_id IS NOT NULL');
    foreach ($st->fetchAll() as $r) {
        $type = (string)$r['doc_type'];
        if ($type === DOC_TYPE_SC) { continue; }
        $s = mb_strtolower(trim((string)$r['doc_status']), 'UTF-8');
        $live = $type === Doc::TYPE_IN ? ($s === 'sent inbound')
              : (strpos($s, 'approved') !== false || strpos($s, 'sent borrow') !== false);
        if (!$live) { continue; }
        $g = strtolower(trim((string)$r['status']));
        $label = (string)$r['doc_no'];
        if ($g === 'opened' || $g === 'scanned') { $out['open'][] = $label . ' (' . (string)$r['status'] . ')'; continue; }
        $out['stuck'][] = $label;
        $its->execute([(int)$r['doc_id']]);
        foreach ($its->fetchAll() as $i) {
            $mid = (int)$i['material_id'];
            $q = s05EffQty($i);
            $signed = $type === Doc::TYPE_IN ? $q : -$q;
            $out['stuckQty'][$mid] = round(($out['stuckQty'][$mid] ?? 0.0) + $signed, 3);
            $out['stuckByMat'][$mid][] = $label;
        }
    }
    return $out;
}

/** ข้อความเตือนใบค้างของประตู (ว่าง = ไม่มี) */
function _scStuckWarning(array $pend): string {
    if (!$pend['stuck']) { return ''; }
    return 'ประตูนี้มีใบที่ยืนยันหยิบ/รับแล้วแต่ระบบยังไม่ปิดงาน (ยอดยังไม่ขยับ): ' . implode(', ', array_slice($pend['stuck'], 0, 8))
         . (count($pend['stuck']) > 8 ? ' …' : '') . ' — ผลต่างของรหัสในใบเหล่านี้อาจมาจากใบค้าง ให้ตรวจก่อนอนุมัติปรับยอด';
}

/** KPI 30 วันของไซต์ */
function _scKpi(PDO $pdo, int $projectId): array {
    $st = $pdo->prepare(
        "SELECT COUNT(*) AS n,
                SUM(CASE WHEN ABS(di.qty_actual - di.qty) < 0.0005 THEN 1 ELSE 0 END) AS m
           FROM document_items di JOIN documents d ON d.id = di.document_id
          WHERE d.project_id = ? AND d.doc_type = 'SC' AND d.status = 'Completed'
            AND di.qty_actual IS NOT NULL AND d.doc_ts >= NOW() - INTERVAL 30 DAY"
    );
    $st->execute([$projectId]);
    $r = $st->fetch() ?: ['n' => 0, 'm' => 0];
    $n = (int)$r['n'];
    $m = (int)$r['m'];
    $pq = $pdo->prepare(
        "SELECT COUNT(*) FROM document_items di JOIN documents d ON d.id = di.document_id
           LEFT JOIN stock_adjustments a ON a.item_id = di.id
          WHERE d.project_id = ? AND d.doc_type = 'SC' AND d.status NOT IN ('Cancelled','Rejected')
            AND di.qty_actual IS NOT NULL AND ABS(di.qty_actual - di.qty) >= 0.0005 AND a.id IS NULL"
    );
    $pq->execute([$projectId]);
    $dc = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE project_id = ? AND doc_type = 'SC' AND status = 'Completed' AND doc_ts >= NOW() - INTERVAL 30 DAY");
    $dc->execute([$projectId]);
    return [
        'accuracy30'    => $n > 0 ? round($m * 100 / $n, 1) : null,
        'counted30'     => $n,
        'matched30'     => $m,
        'docs30'        => (int)$dc->fetchColumn(),
        'pendingAdjust' => (int)$pq->fetchColumn(),
    ];
}

// =========================================================================
// getStockCountData()
// =========================================================================
function rpc_getStockCountData(PDO $pdo, ?array $user, array $args) {
    try {
        if (!$user) { return ['success' => false, 'message' => 'session expired']; }
        $perm = _scPerms($pdo, $user);
        $projectId = (int)($user['projectId'] ?? 0);
        if (!$perm['count'] && !$perm['decide']) {
            return ['success' => false, 'message' => 'no_permission'];
        }

        // ประตูของไซต์ + จำนวนรายการที่มียอด
        $gs = $pdo->prepare(
            "SELECT g.id, g.gate_code, g.name, g.hardware_close,
                    (SELECT COUNT(*) FROM stock_gate_balances gb JOIN materials m ON m.id = gb.material_id
                      WHERE gb.gate_id = g.id AND gb.project_id = g.project_id AND gb.on_hand <> 0) AS item_count
               FROM gates g WHERE g.project_id = ? AND g.status = 'active' ORDER BY g.gate_code"
        );
        $gs->execute([$projectId]);
        $gates = [];
        foreach ($gs->fetchAll() as $g) {
            $gates[(int)$g['id']] = [
                'code' => strtoupper(trim((string)$g['gate_code'])), 'name' => (string)($g['name'] ?? ''),
                'itemCount' => (int)$g['item_count'], 'scanFlow' => !isTrueFlag($g['hardware_close']),
                'openDoc' => null, 'lastDoc' => null, 'lastAt' => null, 'lastAccuracy' => null,
            ];
        }

        // ใบนับ 60 วันล่าสุด + ใบที่ยังไม่จบ
        $ds = $pdo->prepare(
            "SELECT d.id, d.doc_no, d.project_id, d.gate_id, d.status, d.doc_ts, d.requester_username, d.notice,
                    g.gate_code, gl.status AS gate_status, gl.picking_id
               FROM documents d
               LEFT JOIN gates g ON g.id = d.gate_id
               LEFT JOIN gate_logs gl ON gl.doc_no = d.doc_no
              WHERE d.project_id = ? AND d.doc_type = 'SC'
                AND (d.doc_ts >= NOW() - INTERVAL 60 DAY OR d.status = 'Approved')
              ORDER BY d.doc_ts DESC, d.id DESC LIMIT 120"
        );
        $ds->execute([$projectId]);
        $rows = $ds->fetchAll();
        $sum = _scSummaries($pdo, array_map(function ($r) { return (int)$r['id']; }, $rows));
        $cnt = _scCountedInfo($pdo, array_map(function ($r) { return (string)$r['doc_no']; }, $rows));

        $docs = [];
        foreach ($rows as $r) {
            $stage = _scStage($r);
            $s = $sum[(int)$r['id']] ?? ['items' => 0, 'counted' => 0, 'match' => 0, 'diff' => 0, 'pending' => 0,
                                         'approved' => 0, 'rejected' => 0, 'accuracy' => null, 'plus' => 0, 'minus' => 0];
            $one = [
                'docId'      => (string)$r['doc_no'],
                'gate'       => strtoupper(trim((string)($r['gate_code'] ?? ''))),
                'stage'      => $stage,
                'stageThai'  => _scStageThai($stage),
                'createdAt'  => substr((string)$r['doc_ts'], 0, 16),
                'createdBy'  => (string)$r['requester_username'],
                'countedBy'  => $cnt[(string)$r['doc_no']]['by'] ?? '',
                'countedAt'  => $cnt[(string)$r['doc_no']]['at'] ?? '',
                'pickingId'  => (string)($r['picking_id'] ?? ''),
                'summary'    => $s,
                'canCancel'  => $stage === 'awaiting' && $perm['count']
                                && eqUser((string)$r['requester_username'], (string)($user['username'] ?? '')),
            ];
            $docs[] = $one;
            $gid = $r['gate_id'] !== null ? (int)$r['gate_id'] : 0;
            if (isset($gates[$gid])) {
                if (in_array($stage, ['awaiting', 'counting', 'counted'], true) && $gates[$gid]['openDoc'] === null) {
                    $gates[$gid]['openDoc'] = $one['docId'];
                    $gates[$gid]['openStage'] = $stage;
                }
                if ($stage === 'done' && $gates[$gid]['lastDoc'] === null) {
                    $gates[$gid]['lastDoc'] = $one['docId'];
                    $gates[$gid]['lastAt'] = $one['countedAt'] !== '' ? $one['countedAt'] : $one['createdAt'];
                    $gates[$gid]['lastAccuracy'] = $s['accuracy'];
                }
            }
        }

        return [
            'success'   => true,
            'siteCode'  => (string)($user['siteCode'] ?? ''),
            'canCount'  => $perm['count'],
            'canDecide' => $perm['decide'],
            'gates'     => array_values($gates),
            'docs'      => $docs,
            'kpi'       => _scKpi($pdo, $projectId),
        ];
    } catch (Throwable $e) {
        error_log('getStockCountData: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// =========================================================================
// createStockCount(gateCode)
// =========================================================================
function rpc_createStockCount(PDO $pdo, ?array $user, array $args) {
    try {
        if (!$user) { return ['success' => false, 'message' => 'session expired']; }
        $perm = _scPerms($pdo, $user);
        if (!$perm['count']) {
            return ['success' => false, 'message' => 'ต้องมีสิทธิ์ตรวจสอบประจำวันจึงสร้างใบนับสต๊อกได้'];
        }
        $projectId = (int)($user['projectId'] ?? 0);
        $gateCode  = strtoupper(trim((string)($args[0] ?? '')));
        $me = trim((string)($user['username'] ?? ''));
        if ($gateCode === '') { return ['success' => false, 'message' => 'เลือกประตูที่จะนับ']; }

        $gq = $pdo->prepare("SELECT id, gate_code, name FROM gates WHERE project_id = ? AND gate_code = ? AND status = 'active'");
        $gq->execute([$projectId, $gateCode]);
        $gate = $gq->fetch();
        if (!$gate) { return ['success' => false, 'message' => 'ไม่พบประตู ' . $gateCode . ' ของไซต์นี้'];}
        $gateId = (int)$gate['id'];

        $pdo->beginTransaction();
        try {
            // ใบนับที่ยังไม่จบของประตูนี้ → คืนใบเดิม (ล็อกแถวประตูกันกดพร้อมกันสองเครื่อง)
            $pdo->prepare('SELECT id FROM gates WHERE id = ? FOR UPDATE')->execute([$gateId]);
            $ex = $pdo->prepare(
                "SELECT d.doc_no FROM documents d LEFT JOIN gate_logs gl ON gl.doc_no = d.doc_no
                  WHERE d.project_id = ? AND d.doc_type = 'SC' AND d.gate_id = ? AND d.status = 'Approved'
                    AND (gl.status IS NULL OR gl.status IN ('Awaiting','Opened','Scanned','Confirmed'))
                  ORDER BY d.id DESC LIMIT 1"
            );
            $ex->execute([$projectId, $gateId]);
            $exist = $ex->fetchColumn();
            if ($exist !== false) {
                $pdo->commit();
                $d = _scDoc($pdo, (string)$exist);
                return ['success' => true, 'reused' => true, 'docId' => (string)$exist, 'gate' => $gateCode,
                        'stage' => _scStage($d), 'itemCount' => (int)$pdo->query('SELECT COUNT(*) FROM document_items WHERE document_id = ' . (int)$d['id'])->fetchColumn(),
                        'message' => 'ประตู ' . $gateCode . ' มีใบนับที่ยังไม่จบอยู่แล้ว (' . $exist . ') — ใช้ใบเดิม'];
            }

            $pend = _scGatePendingDocs($pdo, $projectId, $gateId);
            if ($pend['open']) {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'ประตู ' . $gateCode . ' ยังมีใบที่แตะบัตรแล้วแต่ยังไม่ถ่ายรูปยืนยัน: ' . implode(', ', $pend['open'])
                        . "\nไม่รู้ว่าหยิบของไปเท่าไร ยอดในระบบยังไม่ขยับ — ให้ถ่ายรูปยืนยันใบเหล่านี้ (ปิดงาน) ก่อนแล้วค่อยนับ"];
            }

            $it = $pdo->prepare(
                "SELECT gb.material_id, gb.on_hand, m.mat_code, m.name, m.unit
                   FROM stock_gate_balances gb JOIN materials m ON m.id = gb.material_id
                  WHERE gb.project_id = ? AND gb.gate_id = ? AND gb.on_hand <> 0
                  ORDER BY m.name, m.mat_code"
            );
            $it->execute([$projectId, $gateId]);
            $lines = $it->fetchAll();
            if (!$lines) {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'ประตู ' . $gateCode . ' ไม่มีรายการที่มียอดในระบบ — ไม่มีอะไรต้องนับ'];
            }

            $dateKey = docDateKey();
            $n = _docCounterNext($pdo, DOC_TYPE_SC, $dateKey);
            $docNo = DOC_TYPE_SC . $dateKey . _docRunningStr($n) . $gateCode;
            $pdo->prepare(
                'INSERT INTO documents (doc_no, doc_type, project_id, requester_username, receiver_name, receiver_sub_id,
                                        gate_id, usage_area, notice, approver_username, status, rs_no, doc_ts)
                 VALUES (?, ?, ?, ?, ?, NULL, ?, ?, NULL, ?, ?, NULL, ?)'
            )->execute([$docNo, DOC_TYPE_SC, $projectId, $me, 'นับสต๊อก ' . $gateCode, $gateId, 'นับสต๊อก ' . $gateCode,
                        $me, Doc::ST_APPROVED, nowBkk()->format('Y-m-d H:i:s')]);
            $docId = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare(
                "INSERT INTO document_items (document_id, material_id, mat_code, mat_name, unit, qty, usage_area, notice, rs_no, charge_money, stock_deducted)
                 VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, NULL, 0, 0)"
            );
            foreach ($lines as $l) {
                $ins->execute([$docId, (int)$l['material_id'], (string)$l['mat_code'], (string)$l['name'], (string)$l['unit'], (float)$l['on_hand']]);
            }
            $pdo->prepare("INSERT IGNORE INTO gate_logs (project_id, doc_no, document_id, leg, gate_id, status) VALUES (?, ?, ?, 'out', ?, ?)")
                ->execute([$projectId, $docNo, $docId, $gateId, Gate::ST_AWAITING]);
            s05ActivityLog($pdo, 'document', $docNo, $me, 'sc_create', null,
                json_encode(['gate' => $gateCode, 'items' => count($lines)], JSON_UNESCAPED_UNICODE));
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return ['success' => true, 'reused' => false, 'docId' => $docNo, 'gate' => $gateCode, 'stage' => 'awaiting',
                'itemCount' => count($lines), 'warning' => _scStuckWarning($pend),
                'message' => 'สร้างใบนับ ' . $docNo . ' (' . count($lines) . ' รายการ) — สแกน QR ที่ตู้ประตู ' . $gateCode . ' แล้วแตะบัตรเพื่อเปิดประตู'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('createStockCount: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Server error: ' . $e->getMessage()];
    }
}

// =========================================================================
// getStockCountSheet(docNo)
// =========================================================================
function rpc_getStockCountSheet(PDO $pdo, ?array $user, array $args) {
    try {
        if (!$user) { return ['success' => false, 'message' => 'session expired']; }
        $perm = _scPerms($pdo, $user);
        if (!$perm['count'] && !$perm['decide']) { return ['success' => false, 'message' => 'no_permission']; }
        $d = _scDoc($pdo, (string)($args[0] ?? ''));
        if (!$d) { return ['success' => false, 'message' => 'ไม่พบใบนับ']; }
        $isR0 = (string)($user['roleLevel'] ?? '') === 'R0';
        if (!$isR0 && (int)$d['project_id'] !== (int)($user['projectId'] ?? 0)) {
            return ['success' => false, 'message' => 'ใบนี้ไม่ได้อยู่ในไซต์ของคุณ'];
        }
        $stage = _scStage($d);
        // นับแบบไม่เห็นยอด: ยังไม่บันทึกผล → ไม่ส่งยอดในระบบ/ผลต่าง
        $blind = in_array($stage, ['awaiting', 'counting'], true);

        $st = $pdo->prepare(
            "SELECT di.id, di.material_id, di.mat_code, COALESCE(NULLIF(m.name, ''), di.mat_name) AS name,
                    COALESCE(NULLIF(m.unit, ''), di.unit) AS unit, m.subgroup_name, di.qty, di.qty_actual,
                    a.status AS adj_status, a.note AS adj_note, a.decided_by, a.decided_at
               FROM document_items di
               LEFT JOIN materials m ON m.id = di.material_id
               LEFT JOIN stock_adjustments a ON a.item_id = di.id
              WHERE di.document_id = ? ORDER BY di.id"
        );
        $st->execute([(int)$d['id']]);
        $pend = (!$blind && $d['gate_id'] !== null && $stage !== 'done')
              ? _scGatePendingDocs($pdo, (int)$d['project_id'], (int)$d['gate_id'], (string)$d['doc_no'])
              : ['open' => [], 'stuck' => [], 'stuckQty' => [], 'stuckByMat' => []];
        $items = [];
        foreach ($st->fetchAll() as $r) {
            $one = [
                'itemId'   => (int)$r['id'],
                'matCode'  => (string)$r['mat_code'],
                'name'     => (string)$r['name'],
                'unit'     => (string)($r['unit'] ?? ''),
                'subgroup' => (string)($r['subgroup_name'] ?? ''),
            ];
            if (!$blind) {
                $sys = (float)$r['qty'];
                $cnt = $r['qty_actual'] !== null ? (float)$r['qty_actual'] : null;
                $delta = $cnt === null ? null : round($cnt - $sys, 3);
                $one += [
                    'system'  => $sys,
                    'counted' => $cnt,
                    'diff'    => $delta,
                    'adj'     => $r['adj_status'] !== null ? (string)$r['adj_status'] : ($delta !== null && abs($delta) >= SC_EPS ? 'pending' : ''),
                    'adjNote' => (string)($r['adj_note'] ?? ''),
                    'adjBy'   => (string)($r['decided_by'] ?? ''),
                    'adjAt'   => $r['decided_at'] !== null ? substr((string)$r['decided_at'], 0, 16) : '',
                ];
                $mid = $r['material_id'] !== null ? (int)$r['material_id'] : 0;
                if ($mid > 0 && isset($pend['stuckQty'][$mid])) {
                    $one['stuckQty']  = $pend['stuckQty'][$mid];
                    $one['stuckDocs'] = $pend['stuckByMat'][$mid];
                }
            }
            $items[] = $one;
        }
        $cnt = _scCountedInfo($pdo, [(string)$d['doc_no']]);
        return [
            'success' => true,
            'doc' => [
                'docId'     => (string)$d['doc_no'],
                'gate'      => strtoupper(trim((string)($d['gate_code'] ?? ''))),
                'gateName'  => (string)($d['gate_name'] ?? ''),
                'stage'     => $stage,
                'stageThai' => _scStageThai($stage),
                'createdAt' => substr((string)$d['doc_ts'], 0, 16),
                'createdBy' => (string)$d['requester_username'],
                'countedBy' => $cnt[(string)$d['doc_no']]['by'] ?? '',
                'countedAt' => $cnt[(string)$d['doc_no']]['at'] ?? '',
                'pickingId' => (string)($d['picking_id'] ?? ''),
                'note'      => (string)($d['notice'] ?? ''),
                'scanFlow'  => $d['hardware_close'] !== null ? !isTrueFlag($d['hardware_close']) : true,
            ],
            'blind'     => $blind,
            'warning'   => _scStuckWarning($pend),
            'canCount'  => $perm['count'] && $stage === 'counting',
            // [2026-10-02 · GP-16] คนนับปรับยอดผลนับของตัวเองไม่ได้ (ยกเว้น PM) — ปุ่มอนุมัติไม่ขึ้น + บอกเหตุ
            'canDecide' => $perm['decide'] && in_array($stage, ['counted', 'done'], true)
                           && !_scIsOwnCount($pdo, $user, (string)$d['doc_no'], (int)$d['project_id']),
            'ownCount'  => $perm['decide'] && _scIsOwnCount($pdo, $user, (string)$d['doc_no'], (int)$d['project_id']),
            'items'     => $items,
        ];
    } catch (Throwable $e) {
        error_log('getStockCountSheet: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// =========================================================================
// saveStockCount(payload)
// =========================================================================
function rpc_saveStockCount(PDO $pdo, ?array $user, array $args) {
    try {
        if (!$user) { return ['success' => false, 'message' => 'session expired']; }
        $perm = _scPerms($pdo, $user);
        if (!$perm['count']) { return ['success' => false, 'message' => 'ต้องมีสิทธิ์ตรวจสอบประจำวันจึงบันทึกผลนับได้']; }
        $p = (isset($args[0]) && is_array($args[0])) ? $args[0] : [];
        $docNo = trim((string)($p['docNo'] ?? ''));
        $me = trim((string)($user['username'] ?? ''));
        $note = trim(mb_substr((string)($p['note'] ?? ''), 0, 500, 'UTF-8'));

        $d = _scDoc($pdo, $docNo);
        if (!$d) { return ['success' => false, 'message' => 'ไม่พบใบนับ ' . $docNo]; }
        if ((int)$d['project_id'] !== (int)($user['projectId'] ?? 0)) {
            return ['success' => false, 'message' => 'ใบนี้ไม่ได้อยู่ในไซต์ของคุณ'];
        }
        $stage = _scStage($d);
        if ($stage === 'awaiting') {
            return ['success' => false, 'message' => 'ใบนับ ' . $docNo . ' ยังไม่ถูกแตะบัตรที่ประตู — สแกน QR ที่ตู้แล้วแตะบัตรเพื่อเปิดประตูก่อน'];
        }
        if ($stage !== 'counting') {
            return ['success' => false, 'message' => 'ใบนับ ' . $docNo . ' ' . _scStageThai($stage) . ' — บันทึกซ้ำไม่ได้'];
        }
        $projectId = (int)$d['project_id'];
        $gateId = $d['gate_id'] !== null ? (int)$d['gate_id'] : null;
        if ($gateId === null) { return ['success' => false, 'message' => 'ใบนับไม่มีประตู']; }
        $gateCode = strtoupper(trim((string)$d['gate_code']));

        // ---- ผลนับที่ส่งมา ----
        $counts = [];
        foreach ((isset($p['items']) && is_array($p['items'])) ? $p['items'] : [] as $x) {
            if (!is_array($x)) { continue; }
            $iid = (int)($x['itemId'] ?? 0);
            $v = $x['counted'] ?? null;
            if ($iid <= 0) { continue; }
            if ($v === null || trim((string)$v) === '') { $counts[$iid] = null; continue; }
            if (!is_numeric($v) || (float)$v < 0) { return ['success' => false, 'message' => 'ผลนับต้องเป็นตัวเลขตั้งแต่ 0 ขึ้นไป']; }
            $counts[$iid] = round((float)$v, 3);
        }
        $extras = [];
        $rawExtra = (isset($p['extra']) && is_array($p['extra'])) ? $p['extra'] : [];
        if (count($rawExtra) > SC_MAX_EXTRA) { return ['success' => false, 'message' => 'ของที่พบเพิ่มเกิน ' . SC_MAX_EXTRA . ' รายการ']; }
        foreach ($rawExtra as $x) {
            if (!is_array($x)) { continue; }
            $mc = trim((string)($x['matCode'] ?? ''));
            $v = $x['counted'] ?? null;
            if ($mc === '') { continue; }
            if (!is_numeric($v) || (float)$v < 0) { return ['success' => false, 'message' => $mc . ': ผลนับต้องเป็นตัวเลขตั้งแต่ 0 ขึ้นไป']; }
            $extras[strtoupper($mc)] = ['matCode' => $mc, 'counted' => round((float)$v, 3)];
        }

        $pdo->beginTransaction();
        try {
            $dl = _scDoc($pdo, $docNo, true);
            if (!$dl || _scStage($dl) !== 'counting') {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'ใบนับ ' . $docNo . ' ถูกบันทึกไปแล้ว'];
            }
            $pdo->prepare('SELECT id FROM gate_logs WHERE id = ? FOR UPDATE')->execute([(int)$dl['gl_id']]);

            $it = $pdo->prepare('SELECT id, material_id, mat_code FROM document_items WHERE document_id = ? ORDER BY id FOR UPDATE');
            $it->execute([(int)$d['id']]);
            $rows = $it->fetchAll();
            $missing = [];
            $have = [];
            foreach ($rows as $r) {
                $have[strtoupper(trim((string)$r['mat_code']))] = (int)$r['id'];
                if (!array_key_exists((int)$r['id'], $counts) || $counts[(int)$r['id']] === null) {
                    $missing[] = (string)$r['mat_code'];
                }
            }
            if ($missing) {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'ยังไม่ได้กรอกผลนับ ' . count($missing) . ' รายการ: '
                        . implode(', ', array_slice($missing, 0, 8)) . (count($missing) > 8 ? ' …' : '')
                        . "\nของที่ไม่พบให้กรอก 0", 'missing' => $missing];
            }

            // ของที่พบเพิ่ม: ต้องเป็นรหัส IC ในทะเบียน · ซ้ำกับรายการเดิม = รวมเข้ารายการเดิม
            $extraRows = [];
            if ($extras) {
                $ph = implode(',', array_fill(0, count($extras), '?'));
                $mq = $pdo->prepare("SELECT id, mat_code, name, unit, code_type FROM materials WHERE mat_code IN ($ph)");
                $mq->execute(array_column($extras, 'matCode'));
                $found = [];
                foreach ($mq->fetchAll() as $m) { $found[strtoupper(trim((string)$m['mat_code']))] = $m; }
                $bad = [];
                $dup = [];
                foreach ($extras as $k => $x) {
                    if (!isset($found[$k]) || (string)$found[$k]['code_type'] !== 'ic') { $bad[] = $x['matCode']; continue; }
                    if (isset($have[$k])) { $dup[] = $x['matCode']; continue; }
                    $extraRows[] = ['m' => $found[$k], 'counted' => $x['counted']];
                }
                if ($bad) {
                    $pdo->rollBack();
                    return ['success' => false, 'message' => 'รหัสที่พบเพิ่มต้องเป็นรหัส IC ในทะเบียน: ' . implode(', ', $bad)];
                }
                if ($dup) {
                    $pdo->rollBack();
                    return ['success' => false, 'message' => 'รหัส ' . implode(', ', $dup) . ' อยู่ในรายการนับแล้ว — กรอกยอดรวมที่บรรทัดเดิม แล้วลบออกจากของที่พบเพิ่ม'];
                }
            }

            // ยอดในระบบ ณ เวลาบันทึก (on_hand ของ G นี้)
            $bal = $pdo->prepare('SELECT on_hand FROM stock_gate_balances WHERE project_id = ? AND material_id = ? AND gate_id = ? FOR UPDATE');
            $upd = $pdo->prepare('UPDATE document_items SET qty = ?, qty_actual = ? WHERE id = ?');
            $matOf = [];
            foreach ($rows as $r) { $matOf[(int)$r['id']] = $r['material_id'] !== null ? (int)$r['material_id'] : 0; }
            $diffCount = 0;
            $total = 0;
            foreach ($counts as $iid => $cnt) {
                if (!isset($matOf[$iid])) { continue; }
                $sys = 0.0;
                if ($matOf[$iid] > 0) {
                    $bal->execute([$projectId, $matOf[$iid], $gateId]);
                    $v = $bal->fetchColumn();
                    $sys = $v === false ? 0.0 : (float)$v;
                }
                $upd->execute([$sys, $cnt, $iid]);
                $total++;
                if (abs($cnt - $sys) >= SC_EPS) { $diffCount++; }
            }
            if ($extraRows) {
                $ins = $pdo->prepare(
                    "INSERT INTO document_items (document_id, material_id, mat_code, mat_name, unit, qty, qty_actual, usage_area, notice, rs_no, charge_money, stock_deducted)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, NULL, 0, 0)"
                );
                foreach ($extraRows as $x) {
                    $bal->execute([$projectId, (int)$x['m']['id'], $gateId]);
                    $v = $bal->fetchColumn();
                    $sys = $v === false ? 0.0 : (float)$v;
                    $ins->execute([(int)$d['id'], (int)$x['m']['id'], (string)$x['m']['mat_code'], (string)$x['m']['name'],
                                   (string)$x['m']['unit'], $sys, $x['counted'], 'พบเพิ่มระหว่างนับ']);
                    $total++;
                    if (abs($x['counted'] - $sys) >= SC_EPS) { $diffCount++; }
                }
            }
            if ($note !== '') {
                $pdo->prepare('UPDATE documents SET notice = ? WHERE id = ?')->execute(['[Count]: ' . $note, (int)$d['id']]);
            }
            $pdo->prepare('UPDATE gate_logs SET status = ? WHERE doc_no = ?')->execute([Gate::ST_CONFIRMED, $docNo]);
            s05ActivityLog($pdo, 'document', $docNo, $me, 'sc_count', null, json_encode([
                'gate' => $gateCode, 'items' => $total, 'diff' => $diffCount, 'extra' => count($extraRows),
                'picking' => (string)($d['picking_id'] ?? ''),
            ], JSON_UNESCAPED_UNICODE));
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }

        // ปิดงาน: ประตูไม่มีตู้ (scan-flow) → ปิดทันที · รอบที่ตู้ปิดไปแล้ว → ปิดทันที + error_logs · อื่น ๆ รอ closeGate
        $scanFlow = $d['hardware_close'] !== null ? !isTrueFlag($d['hardware_close']) : true;
        $pickingId = trim((string)($d['picking_id'] ?? ''));
        $late = false;
        if (!$scanFlow && $pickingId !== '') {
            $cq = $pdo->prepare("SELECT COUNT(*) FROM gate_logs WHERE picking_id = ? AND status = 'Closed'");
            $cq->execute([$pickingId]);
            $late = (int)$cq->fetchColumn() > 0;
        }
        $finalized = false;
        if ($scanFlow || $late) {
            try {
                if ($late) {
                    $pdo->prepare('UPDATE gate_logs SET status = ? WHERE doc_no = ?')->execute([Gate::ST_CLOSED, $docNo]);
                    _stockErrorLog($pdo, $projectId, $gateCode, 'Late count: ' . $docNo . ' บันทึกผลนับหลังตู้ปิดรอบ PickingID=' . $pickingId . ' แล้ว — ปิดงานทันที | by=' . $me);
                }
                finalizeGateDoc($pdo, $docNo);
                $finalized = true;
            } catch (Throwable $e) {
                error_log('saveStockCount finalize ' . $docNo . ': ' . $e->getMessage());
            }
        }
        $sum = _scSummaries($pdo, [(int)$d['id']])[(int)$d['id']] ?? null;
        $pend = _scGatePendingDocs($pdo, $projectId, (int)$gateId, $docNo);
        $warn = _scStuckWarning($pend);
        if ($pend['open']) {
            $warn = trim('ประตูนี้มีใบที่แตะบัตรแล้วยังไม่ยืนยัน (' . implode(', ', $pend['open']) . ') ' . $warn);
        }
        return [
            'success'   => true,
            'docId'     => $docNo,
            'finalized' => $finalized,
            'scanFlow'  => $scanFlow,
            'summary'   => $sum,
            'warning'   => $warn,
            'message'   => 'บันทึกผลนับ ' . $docNo . ' แล้ว — ' . ($sum ? $sum['counted'] . ' รายการ · ตรงยอด ' . $sum['match'] . ' · ต่าง ' . $sum['diff'] : '')
                         . ($finalized ? '' : ' · ปิดประตูให้สนิทเพื่อจบรอบ'),
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('saveStockCount: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Server error: ' . $e->getMessage()];
    }
}

// =========================================================================
// getStockAdjustQueue() — ผลต่างรออนุมัติของไซต์ (หน้าอนุมัติ)
// =========================================================================
function rpc_getStockAdjustQueue(PDO $pdo, ?array $user, array $args) {
    try {
        if (!$user) { return ['success' => false, 'message' => 'session expired']; }
        $perm = _scPerms($pdo, $user);
        if (!$perm['decide']) { return ['success' => true, 'canDecide' => false, 'docs' => []]; }
        $projectId = (int)($user['projectId'] ?? 0);
        $st = $pdo->prepare(
            "SELECT d.id, d.doc_no, d.status, d.doc_ts, d.requester_username, d.gate_id, g.gate_code,
                    di.id AS item_id, di.material_id, di.mat_code, COALESCE(NULLIF(m.name, ''), di.mat_name) AS name,
                    COALESCE(NULLIF(m.unit, ''), di.unit) AS unit, di.qty, di.qty_actual, gl.status AS gate_status
               FROM documents d
               JOIN document_items di ON di.document_id = d.id
               LEFT JOIN materials m ON m.id = di.material_id
               LEFT JOIN gates g ON g.id = d.gate_id
               LEFT JOIN gate_logs gl ON gl.doc_no = d.doc_no
               LEFT JOIN stock_adjustments a ON a.item_id = di.id
              WHERE d.project_id = ? AND d.doc_type = 'SC' AND d.status NOT IN ('Cancelled','Rejected')
                AND gl.status IN ('Confirmed','Closed')
                AND di.qty_actual IS NOT NULL AND ABS(di.qty_actual - di.qty) >= 0.0005 AND a.id IS NULL
              ORDER BY d.doc_ts, d.id, di.id"
        );
        $st->execute([$projectId]);
        $docs = [];
        $pendByGate = [];
        foreach ($st->fetchAll() as $r) {
            $k = (string)$r['doc_no'];
            $gid = $r['gate_id'] !== null ? (int)$r['gate_id'] : 0;
            if (!isset($pendByGate[$gid])) {
                $pendByGate[$gid] = $gid > 0 ? _scGatePendingDocs($pdo, $projectId, $gid, $k)
                                             : ['open' => [], 'stuck' => [], 'stuckQty' => [], 'stuckByMat' => []];
            }
            $pend = $pendByGate[$gid];
            if (!isset($docs[$k])) {
                $docs[$k] = ['docId' => $k, 'gate' => strtoupper(trim((string)($r['gate_code'] ?? ''))),
                             'createdAt' => substr((string)$r['doc_ts'], 0, 16), 'createdBy' => (string)$r['requester_username'],
                             'warning' => _scStuckWarning($pend), 'items' => []];
            }
            $one = [
                'itemId' => (int)$r['item_id'], 'matCode' => (string)$r['mat_code'], 'name' => (string)$r['name'],
                'unit' => (string)($r['unit'] ?? ''), 'system' => (float)$r['qty'], 'counted' => (float)$r['qty_actual'],
                'diff' => round((float)$r['qty_actual'] - (float)$r['qty'], 3),
            ];
            $mid = $r['material_id'] !== null ? (int)$r['material_id'] : 0;
            if ($mid > 0 && isset($pend['stuckQty'][$mid])) {
                $one['stuckQty']  = $pend['stuckQty'][$mid];
                $one['stuckDocs'] = $pend['stuckByMat'][$mid];
            }
            $docs[$k]['items'][] = $one;
        }
        $cnt = _scCountedInfo($pdo, array_keys($docs));
        foreach ($docs as $k => $v) {
            $docs[$k]['countedBy'] = $cnt[$k]['by'] ?? '';
            $docs[$k]['countedAt'] = $cnt[$k]['at'] ?? '';
            // [2026-10-02 · GP-16] ผลนับของตัวเอง — อนุมัติเองไม่ได้ (ยกเว้น PM)
            $docs[$k]['ownCount'] = _scIsOwnCount($pdo, $user, (string)$k, $projectId);
        }
        return ['success' => true, 'canDecide' => true, 'docs' => array_values($docs)];
    } catch (Throwable $e) {
        error_log('getStockAdjustQueue: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// =========================================================================
// decideStockAdjust(payload)
// =========================================================================
function rpc_decideStockAdjust(PDO $pdo, ?array $user, array $args) {
    try {
        if (!$user) { return ['success' => false, 'message' => 'session expired']; }
        $perm = _scPerms($pdo, $user);
        if (!$perm['decide']) {
            return ['success' => false, 'message' => 'อนุมัติปรับยอดสต๊อกได้เฉพาะ ADM หรือ R8 ขึ้นไป'];
        }
        $p = (isset($args[0]) && is_array($args[0])) ? $args[0] : [];
        $docNo = trim((string)($p['docNo'] ?? ''));
        $me = trim((string)($user['username'] ?? ''));
        $dec = [];
        foreach ((isset($p['decisions']) && is_array($p['decisions'])) ? $p['decisions'] : [] as $x) {
            if (!is_array($x)) { continue; }
            $iid = (int)($x['itemId'] ?? 0);
            $act = strtolower(trim((string)($x['action'] ?? '')));
            if ($iid <= 0 || ($act !== 'approve' && $act !== 'reject')) { continue; }
            $n = trim(mb_substr((string)($x['note'] ?? ''), 0, 255, 'UTF-8'));
            if ($act === 'reject' && $n === '') {
                return ['success' => false, 'message' => 'ไม่อนุมัติปรับยอดต้องใส่เหตุผลทุกรายการ'];
            }
            $dec[$iid] = ['action' => $act, 'note' => $n];
        }
        if (!$dec) { return ['success' => false, 'message' => 'ไม่มีรายการที่เลือก']; }

        $d = _scDoc($pdo, $docNo);
        if (!$d) { return ['success' => false, 'message' => 'ไม่พบใบนับ ' . $docNo]; }
        if ((int)$d['project_id'] !== (int)($user['projectId'] ?? 0)) {
            return ['success' => false, 'message' => 'ใบนี้ไม่ได้อยู่ในไซต์ของคุณ'];
        }
        $stage = _scStage($d);
        if (!in_array($stage, ['counted', 'done'], true)) {
            return ['success' => false, 'message' => 'ใบนับ ' . $docNo . ' ' . _scStageThai($stage) . ' — ยังอนุมัติปรับยอดไม่ได้'];
        }
        // [2026-10-02 · GP-16] คนนับปรับยอดผลนับของตัวเองไม่ได้ (ยกเว้น PM ของไซต์)
        if (_scIsOwnCount($pdo, $user, $docNo, (int)$d['project_id'])) {
            return ['success' => false, 'message' => 'อนุมัติปรับยอดผลนับของตัวเองไม่ได้ — ให้ ADM หรือ R8 ขึ้นไปคนอื่นอนุมัติ (ยกเว้น PM ของไซต์)'];
        }
        $projectId = (int)$d['project_id'];
        $gateId = $d['gate_id'] !== null ? (int)$d['gate_id'] : null;

        $applied = 0; $rejected = 0; $skipped = [];
        $pdo->beginTransaction();
        try {
            $it = $pdo->prepare(
                "SELECT di.id, di.material_id, di.mat_code, di.qty, di.qty_actual, a.id AS adj_id
                   FROM document_items di LEFT JOIN stock_adjustments a ON a.item_id = di.id
                  WHERE di.document_id = ? FOR UPDATE"
            );
            $it->execute([(int)$d['id']]);
            $ins = $pdo->prepare(
                'INSERT INTO stock_adjustments (document_id, item_id, project_id, gate_id, material_id, mat_code, system_qty, counted_qty, delta, status, note, decided_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($it->fetchAll() as $r) {
                $iid = (int)$r['id'];
                if (!isset($dec[$iid])) { continue; }
                if ($r['adj_id'] !== null) { $skipped[] = (string)$r['mat_code'] . ' (ตัดสินไปแล้ว)'; continue; }
                if ($r['qty_actual'] === null) { $skipped[] = (string)$r['mat_code'] . ' (ยังไม่ได้นับ)'; continue; }
                $delta = round((float)$r['qty_actual'] - (float)$r['qty'], 3);
                if (abs($delta) < SC_EPS) { $skipped[] = (string)$r['mat_code'] . ' (ไม่มีผลต่าง)'; continue; }
                $act = $dec[$iid]['action'];
                if ($act === 'approve') {
                    $mid = $r['material_id'] !== null ? (int)$r['material_id'] : 0;
                    if ($mid <= 0) { $skipped[] = (string)$r['mat_code'] . ' (ไม่มีรหัสในทะเบียน)'; continue; }
                    // นับได้มากกว่า = รับเพิ่ม (In) · น้อยกว่า = ตัดออก (Out) — ขยับทั้งยอดรวมไซต์และยอดของ G นี้
                    applyBalanceDelta($pdo, $projectId, $mid, $delta > 0 ? $delta : 0.0, $delta < 0 ? -$delta : 0.0, $gateId);
                    $applied++;
                } else {
                    $rejected++;
                }
                $ins->execute([(int)$d['id'], $iid, $projectId, $gateId, $r['material_id'] !== null ? (int)$r['material_id'] : null,
                               (string)$r['mat_code'], (float)$r['qty'], (float)$r['qty_actual'], $delta,
                               $act === 'approve' ? 'approved' : 'rejected', $dec[$iid]['note'] !== '' ? $dec[$iid]['note'] : null, $me]);
                s05ActivityLog($pdo, 'document', $docNo, $me, $act === 'approve' ? 'sc_adjust' : 'sc_adjust_reject',
                    null, json_encode(['itemId' => $iid, 'matCode' => (string)$r['mat_code'], 'system' => (float)$r['qty'],
                                       'counted' => (float)$r['qty_actual'], 'delta' => $delta, 'note' => $dec[$iid]['note']], JSON_UNESCAPED_UNICODE));
            }
            if ($applied > 0) { recalcPending($pdo, $projectId); }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return [
            'success'  => true,
            'applied'  => $applied,
            'rejected' => $rejected,
            'skipped'  => $skipped,
            'message'  => 'ปรับยอด ' . $applied . ' รายการ · ไม่ปรับ ' . $rejected . ' รายการ'
                        . ($skipped ? ' · ข้าม ' . count($skipped) . ' (' . implode(', ', array_slice($skipped, 0, 5)) . ')' : ''),
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('decideStockAdjust: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Server error: ' . $e->getMessage()];
    }
}
