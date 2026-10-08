<?php
/**
 * CONNEXT — lib/transfer.php : TD ใบเบิกโอนย้ายข้ามไซต์ [PHP port 2026-09-29] — ไม่มีใน GAS
 *
 *   เบิกของออกจาก G ของไซต์ต้นทาง (ไซต์ใน session) เพื่อส่งไปไซต์ปลายทาง
 *   กติกา (ตามที่ผู้ใช้เลือก 2026-09-29):
 *     · ผู้อนุมัติ = ผู้จัดการโครงการ (role PM) ของไซต์ต้นทางเท่านั้น — ผู้ส่งเป็น PM ของไซต์ = อนุมัติทันที
 *     · ต้นทางตัดอย่างเดียว — ไซต์ปลายทางคีย์ใบรับเข้า (IN) เอง ระบบไม่ผูกสองฝั่ง
 *       (หน้าจอฝั่งปลายทางเห็น "ของที่ไซต์อื่นโอนมา" เป็นข้อมูลอ้างอิงเท่านั้น)
 *   ทางเดิน: ส่งใบ → (รอ PM อนุมัติ) → QR → แตะบัตรที่ G ต้นทาง → ถ่ายรูปยืนยัน (หยิบจริง ≤ ที่ขอ)
 *            → ปิดประตู = ตัดสต๊อกที่ G ต้นทาง (finalizeGateDoc เหมือนใบเบิก) · ใบรอ/อนุมัติแล้ว = จองสต๊อก (recalcPending)
 *   เลขเอกสาร: TD + DDMMYY + เลขรัน + Gxx (แตกใบตามประตู — เลขรันเดียวกันทั้งตะกร้า)
 *
 * RPC:
 *   getTransferFormData()                     ไซต์ปลายทางที่เลือกได้ · PM ของไซต์ต้นทาง · ยอดพร้อมเบิกรายประตู · ใบของไซต์
 *   processTransferSubmission(payload)        {destSite, items:[{MatCode,Qty,GateID}], contact, note, approver}
 *   getTransferList()                         ใบ TD ของไซต์ (ส่งออก) + ใบที่ไซต์อื่นโอนมาหาไซต์นี้ (อ้างอิง)
 *
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/s05.php';         // s05ActivityLog
require_once __DIR__ . '/docnum.php';
require_once __DIR__ . '/documents.php';   // _docsMaterialsMap · _docsIcOnlyError · _docsGateStockGuard · _docsInsert*
require_once __DIR__ . '/doc_ext.php';

const TD_MAX_LINES = 60;

/** ผู้ใช้ส่งใบโอนย้ายได้ไหม — บัญชีพนักงาน (ไม่ใช่ผู้รับเหมา) ที่ผูกไซต์ */
function _tdCanCreate(?array $user): bool {
    return $user && ($user['accountType'] ?? '') === 'user' && (int)($user['projectId'] ?? 0) > 0
        && trim((string)($user['username'] ?? '')) !== '';
}

/** สถานะ → ข้อความไทยสั้น (หน้ารายการใบโอน) */
function _tdStatusThai(string $status, string $gateStatus): string {
    $s = mb_strtolower(trim($status), 'UTF-8');
    $g = strtolower(trim($gateStatus));
    if (strpos($s, 'cancel') !== false) { return 'ยกเลิกแล้ว'; }
    if (strpos($s, 'reject') !== false) { return 'ไม่อนุมัติ'; }
    if (strpos($s, 'complete') !== false) { return 'ตัดสต๊อกต้นทางแล้ว (ส่งของแล้ว)'; }
    if (strpos($s, 'awaiting') !== false) { return 'รอ PM อนุมัติ'; }
    if ($g === 'opened' || $g === 'scanned') { return 'แตะบัตรแล้ว — รอถ่ายรูปยืนยัน'; }
    if ($g === 'confirmed') { return 'ยืนยันแล้ว — รอปิดประตู'; }
    if (strpos($s, 'approved') !== false) { return 'อนุมัติแล้ว — รอสแกน QR ที่ประตู'; }
    return $status !== '' ? $status : '-';
}

// =========================================================================
// getTransferFormData()
// =========================================================================
function rpc_getTransferFormData(PDO $pdo, ?array $user, array $args) {
    try {
        if (!$user) { return ['success' => false, 'message' => 'session expired']; }
        $projectId = (int)($user['projectId'] ?? 0);
        $src = docExtProjectById($pdo, $projectId);
        if (!$src) { return ['success' => false, 'message' => 'ไม่พบไซต์ของบัญชีนี้']; }
        $me = trim((string)($user['username'] ?? ''));

        $sites = [];
        $st = $pdo->prepare("SELECT code, name FROM projects WHERE status = 'active' AND id <> ? ORDER BY code");
        $st->execute([$projectId]);
        foreach ($st->fetchAll() as $r) {
            $sites[] = ['code' => (string)$r['code'], 'name' => (string)$r['name']];
        }

        // ยอดพร้อมเบิกรายประตูของไซต์ต้นทาง (on_hand − pending > 0 · รหัส IC · ประตู active)
        // ทุกชนิด (CSB/NAR/BRB/WMS) — WMS ปกติ "ดูยอดอย่างเดียว" แต่ผู้ใช้ให้โอนข้ามไซต์ผ่าน TD ได้ (2026-09-29)
        $gs = $pdo->prepare(
            "SELECT m.mat_code, m.name, m.unit, m.char_id, g.gate_code, g.name AS gate_name, gb.on_hand, gb.pending
               FROM stock_gate_balances gb
               JOIN materials m ON m.id = gb.material_id
               JOIN gates g ON g.id = gb.gate_id
              WHERE gb.project_id = ? AND g.status = 'active' AND m.code_type = 'ic' AND gb.on_hand > 0
              ORDER BY m.name, m.mat_code, g.gate_code"
        );
        $gs->execute([$projectId]);
        $stock = [];
        $gates = [];
        foreach ($gs->fetchAll() as $r) {
            $avail = round((float)$r['on_hand'] - (float)$r['pending'], 3);
            $gc = strtoupper(trim((string)$r['gate_code']));
            $gates[$gc] = trim((string)($r['gate_name'] ?? ''));
            $stock[] = [
                'matCode' => trim((string)$r['mat_code']),
                'name'    => trim((string)$r['name']) !== '' ? (string)$r['name'] : (string)$r['mat_code'],
                'unit'    => trim((string)$r['unit']),
                'char'    => strtoupper(trim((string)($r['char_id'] ?? ''))),
                'gate'    => $gc,
                'onHand'  => (float)$r['on_hand'],
                'pending' => (float)$r['pending'],
                'avail'   => max(0.0, $avail),
            ];
        }
        ksort($gates);
        $gateList = [];
        foreach ($gates as $c => $n) { $gateList[] = ['code' => $c, 'name' => $n]; }

        $pms = docExtProjectPms($pdo, $projectId);
        return [
            'success'   => true,
            'site'      => ['code' => $src['code'], 'name' => $src['name']],
            'sites'     => $sites,
            'pms'       => $pms,
            'isPm'      => docExtIsProjectPm($pdo, $me, $projectId),
            'canCreate' => _tdCanCreate($user),
            'gates'     => $gateList,
            'stock'     => $stock,
            'maxLines'  => TD_MAX_LINES,
        ];
    } catch (Throwable $e) {
        error_log('getTransferFormData: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// =========================================================================
// processTransferSubmission(payload)
// =========================================================================
function rpc_processTransferSubmission(PDO $pdo, ?array $user, array $args) {
    try {
        if (!$user) { return ['success' => false, 'message' => 'session expired']; }
        if (!_tdCanCreate($user)) {
            return ['success' => false, 'message' => 'บัญชีนี้ส่งใบโอนย้ายข้ามไซต์ไม่ได้ (เฉพาะบัญชีพนักงานที่ผูกไซต์)'];
        }
        $p = (isset($args[0]) && is_array($args[0])) ? $args[0] : [];
        $projectId = (int)$user['projectId'];
        $src = docExtProjectById($pdo, $projectId);
        if (!$src) { return ['success' => false, 'message' => 'ไม่พบไซต์ต้นทาง']; }
        $me = trim((string)$user['username']);

        // ---- ไซต์ปลายทาง ----
        $dest = docExtProjectByCode($pdo, (string)($p['destSite'] ?? ''));
        if (!$dest) { return ['success' => false, 'message' => 'เลือกไซต์ปลายทาง']; }
        if ($dest['id'] === $projectId) { return ['success' => false, 'message' => 'ไซต์ปลายทางต้องไม่ใช่ไซต์เดียวกับต้นทาง']; }
        if (strtolower($dest['status']) !== 'active') { return ['success' => false, 'message' => 'ไซต์ปลายทาง ' . $dest['code'] . ' ปิดใช้งานแล้ว']; }

        // ---- รายการ ----
        $raw = (isset($p['items']) && is_array($p['items'])) ? array_values($p['items']) : [];
        if (!$raw) { return ['success' => false, 'message' => 'ไม่มีรายการที่จะโอน']; }
        if (count($raw) > TD_MAX_LINES) { return ['success' => false, 'message' => 'รายการเกิน ' . TD_MAX_LINES . ' บรรทัด — แยกเป็นหลายใบ']; }
        $items = [];
        $merge = [];   // GATE|MAT → index (รวมบรรทัดซ้ำ)
        foreach ($raw as $it) {
            if (!is_array($it)) { continue; }
            $mc  = trim((string)($it['MatCode'] ?? $it['matCode'] ?? ''));
            $g   = strtoupper(trim((string)($it['GateID'] ?? $it['gate'] ?? '')));
            $qv  = $it['Qty'] ?? $it['qty'] ?? null;
            if ($mc === '') { return ['success' => false, 'message' => 'มีบรรทัดที่ไม่ได้เลือกวัสดุ']; }
            if ($g === '' || !preg_match('/^G\d+$/', $g)) { return ['success' => false, 'message' => $mc . ': เลือกประตูที่จะเบิกของออก']; }
            if (!is_numeric($qv) || (float)$qv <= 0) { return ['success' => false, 'message' => $mc . ': จำนวนต้องมากกว่า 0']; }
            $q = round((float)$qv, 3);
            $k = $g . '|' . $mc;
            if (isset($merge[$k])) {
                $items[$merge[$k]]['Qty'] = round($items[$merge[$k]]['Qty'] + $q, 3);
                continue;
            }
            $merge[$k] = count($items);
            $items[] = ['MatCode' => $mc, 'Qty' => $q, 'GateID' => $g, 'SiteCode' => $src['code']];
        }
        if (!$items) { return ['success' => false, 'message' => 'ไม่มีรายการที่จะโอน']; }

        // รหัส IC เท่านั้น (มติ 34)
        $matMap = _docsMaterialsMap($pdo, array_column($items, 'MatCode'));
        $icErr = _docsIcOnlyError($pdo, $items, $matMap);
        if ($icErr !== null) { return ['success' => false, 'message' => $icErr]; }

        $contact = trim(mb_substr((string)($p['contact'] ?? ''), 0, 150, 'UTF-8'));
        $note    = trim(mb_substr((string)($p['note'] ?? ''), 0, 1000, 'UTF-8'));
        if ($contact === '') { return ['success' => false, 'message' => 'ระบุผู้รับของที่ไซต์ปลายทาง']; }

        // ---- ผู้อนุมัติ: PM ของไซต์ต้นทาง ----
        $isPm = docExtIsProjectPm($pdo, $me, $projectId);
        $approver = '';
        if (!$isPm) {
            $pms = docExtProjectPms($pdo, $projectId);
            if (!$pms) {
                return ['success' => false, 'message' => 'ไซต์ ' . $src['code'] . ' ยังไม่มีผู้จัดการโครงการ (PM) ในระบบ — ใบโอนย้ายต้องให้ PM ของไซต์ต้นทางอนุมัติ ติดต่อผู้ดูแลระบบ'];
            }
            $want = trim((string)($p['approver'] ?? ''));
            foreach ($pms as $pm) {
                if ($want !== '' && eqUser($pm['username'], $want)) { $approver = $pm['username']; break; }
            }
            if ($approver === '') {
                if (count($pms) === 1) {
                    $approver = $pms[0]['username'];
                } else {
                    return ['success' => false, 'message' => 'เลือก PM ของไซต์ ' . $src['code'] . ' ที่จะอนุมัติใบนี้'];
                }
            }
        }
        $status = $isPm ? Doc::ST_APPROVED : Doc::ST_AWAITING;
        if ($isPm) { $approver = $me; }

        list($gateMap, $siteMap) = _docsGateSiteMaps($pdo, $projectId, $src['code']);
        $now     = nowBkk()->format('Y-m-d H:i:s');
        $dateKey = docDateKey();

        $created = [];
        $pdo->beginTransaction();
        try {
            // ของที่ประตูต้นทางพอไหม (on_hand − pending) — ล็อกแถวยอดจนคอมมิต
            $gateErr = _docsGateStockGuard($pdo, $items, DOC_TYPE_TD, $gateMap, $siteMap, $matMap, $projectId);
            if ($gateErr !== null) {
                $pdo->rollBack();
                return ['success' => false, 'message' => $gateErr];
            }

            $n    = _docCounterNext($pdo, DOC_TYPE_TD, $dateKey);
            $base = DOC_TYPE_TD . $dateKey . _docRunningStr($n);

            $byGate = [];
            foreach ($items as $it) { $byGate[$it['GateID']][] = $it; }
            ksort($byGate);

            $receiver = 'โอนไป ' . $dest['code'];
            $setDest  = $pdo->prepare('UPDATE documents SET dest_project_id = ? WHERE id = ?');
            foreach ($byGate as $g => $gItems) {
                $docNo  = $base . $g;
                $gateId = _docsGateIdByCode($pdo, $projectId, $g);
                if ($gateId === null) {
                    throw new RuntimeException('ไม่พบประตู ' . $g . ' ของไซต์ ' . $src['code']);
                }
                $docId = _docsInsertDocument($pdo, [
                    'doc_no'          => $docNo,
                    'doc_type'        => DOC_TYPE_TD,
                    'project_id'      => $projectId,
                    'requester'       => $me,
                    'receiver_name'   => $receiver,
                    'receiver_sub_id' => null,
                    'gate_id'         => $gateId,
                    'usage_area'      => $contact,
                    'notice'          => $note,
                    'approver'        => $approver,
                    'status'          => $status,
                    'rs_no'           => null,
                    'doc_ts'          => $now,
                ]);
                $setDest->execute([$dest['id'], $docId]);
                foreach ($gItems as $it) {
                    $mi = $matMap[$it['MatCode']];
                    _docsInsertItem($pdo, $docId, [
                        'material_id' => $mi['id'],
                        'mat_code'    => $it['MatCode'],
                        'mat_name'    => $mi['name'],
                        'unit'        => $mi['unit'],
                        'qty'         => $it['Qty'],
                        'usage_area'  => $contact,
                        'notice'      => $note,
                        'rs_no'       => null,
                        'charge'      => 0,
                    ]);
                }
                if ($isPm) {
                    _docsEnsureGateLogAwaiting($pdo, $projectId, $docNo, $docId, $gateId);
                }
                $created[] = $docNo;
                s05ActivityLog($pdo, 'document', $docNo, $me, 'create_td', null, json_encode([
                    'dest' => $dest['code'], 'gate' => $g, 'lines' => count($gItems), 'status' => $status, 'approver' => $approver,
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
            'approved' => $isPm,
            'approver' => $approver,
            'message'  => $isPm
                ? 'ออกใบโอนย้าย ' . implode(', ', $created) . ' แล้ว (คุณเป็น PM ของไซต์ — อนุมัติทันที) ไปที่หน้า QR เพื่อสแกนเบิกของที่ประตู'
                : 'ส่งใบโอนย้าย ' . implode(', ', $created) . ' แล้ว — รอ PM อนุมัติ',
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('processTransferSubmission: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Server error: ' . $e->getMessage()];
    }
}

// =========================================================================
// getTransferList()
// =========================================================================
function rpc_getTransferList(PDO $pdo, ?array $user, array $args) {
    try {
        if (!$user) { return ['success' => false, 'message' => 'session expired']; }
        $projectId = (int)($user['projectId'] ?? 0);
        $me = trim((string)($user['username'] ?? ''));

        $sql = "SELECT d.id, d.doc_no, d.status, d.doc_ts, d.requester_username, d.approver_username, d.approved_by,
                       d.usage_area, d.notice, d.project_id, d.dest_project_id,
                       sp.code AS src_code, sp.name AS src_name, dp.code AS dest_code, dp.name AS dest_name,
                       g.gate_code, gl.status AS gate_status, gl.picking_id
                  FROM documents d
                  JOIN projects sp ON sp.id = d.project_id
                  LEFT JOIN projects dp ON dp.id = d.dest_project_id
                  LEFT JOIN gates g ON g.id = d.gate_id
                  LEFT JOIN gate_logs gl ON gl.doc_no = d.doc_no
                 WHERE d.doc_type = 'TD' AND (d.project_id = ? OR d.dest_project_id = ?)
                   AND d.doc_ts >= NOW() - INTERVAL 120 DAY
                 ORDER BY d.doc_ts DESC, d.id DESC
                 LIMIT 200";
        $st = $pdo->prepare($sql);
        $st->execute([$projectId, $projectId]);
        $docs = $st->fetchAll();

        $itemsByDoc = [];
        if ($docs) {
            $ids = array_map(function ($d) { return (int)$d['id']; }, $docs);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $it = $pdo->prepare("SELECT di.document_id, di.mat_code, COALESCE(NULLIF(m.name, ''), di.mat_name) AS name,
                                        COALESCE(NULLIF(m.unit, ''), di.unit) AS unit, di.qty, di.qty_actual
                                   FROM document_items di LEFT JOIN materials m ON m.id = di.material_id
                                  WHERE di.document_id IN ($ph) ORDER BY di.id");
            $it->execute($ids);
            foreach ($it->fetchAll() as $r) {
                $itemsByDoc[(int)$r['document_id']][] = [
                    'matCode'   => (string)$r['mat_code'],
                    'name'      => (string)$r['name'],
                    'unit'      => (string)($r['unit'] ?? ''),
                    'qty'       => (float)$r['qty'],
                    'qtyActual' => $r['qty_actual'] !== null ? (float)$r['qty_actual'] : null,
                ];
            }
        }

        $outgoing = [];
        $incoming = [];
        foreach ($docs as $d) {
            $gst = trim((string)($d['gate_status'] ?? ''));
            $statusL = mb_strtolower(trim((string)$d['status']), 'UTF-8');
            $isOut = (int)$d['project_id'] === $projectId;
            $row = [
                'docId'      => (string)$d['doc_no'],
                'status'     => (string)$d['status'],
                'statusThai' => _tdStatusThai((string)$d['status'], $gst),
                'gateStatus' => $gst,
                'gate'       => strtoupper(trim((string)($d['gate_code'] ?? ''))),
                'dateStr'    => substr((string)$d['doc_ts'], 0, 16),
                'reqName'    => (string)$d['requester_username'],
                'approver'   => (string)($d['approver_username'] ?? ''),
                'contact'    => (string)($d['usage_area'] ?? ''),
                'note'       => (string)($d['notice'] ?? ''),
                'srcSite'    => (string)$d['src_code'],
                'srcName'    => (string)$d['src_name'],
                'destSite'   => (string)($d['dest_code'] ?? ''),
                'destName'   => (string)($d['dest_name'] ?? ''),
                'items'      => $itemsByDoc[(int)$d['id']] ?? [],
                'done'       => strpos($statusL, 'complete') !== false,
            ];
            if ($isOut) {
                $g = strtolower($gst);
                $row['canCancel'] = eqUser((string)$d['requester_username'], $me)
                    && (strpos($statusL, 'awaiting') !== false || strpos($statusL, 'approved') !== false)
                    && !in_array($g, ['opened', 'scanned', 'confirmed', 'closed'], true);
                $outgoing[] = $row;
            } else {
                // ฝั่งปลายทาง: อ้างอิงเท่านั้น — ของมาถึงแล้วให้คีย์ใบรับเข้า (IN) เอง
                if (strpos($statusL, 'cancel') !== false || strpos($statusL, 'reject') !== false) { continue; }
                $incoming[] = $row;
            }
        }
        return ['success' => true, 'outgoing' => $outgoing, 'incoming' => $incoming];
    } catch (Throwable $e) {
        error_log('getTransferList: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
