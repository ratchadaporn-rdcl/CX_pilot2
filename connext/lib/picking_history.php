<?php
/**
 * CONNEXT — lib/picking_history.php : หน้า "ประวัติเอกสาร" มุมมอง "ราย Picking list" (2026-10-08)
 * ไม่มีใน GAS
 *
 *   rpc_getPickingHistory(siteCode, days) → { success, canSeeAlarms, rounds: [...] }
 *
 * 1 รอบ = 1 PickingID (PK{DDMMYY}{NN} · เลขรันรวมทุกไซต์ — lib/docnum.php nextPickingId) ที่ตู้ประตูเปิดให้หยิบ
 * ที่มาของข้อมูล (อ่านอย่างเดียว · ไม่แก้ schema)
 *   - gate_logs.picking_id      ใบที่อยู่ในรอบ + บัตรที่แตะ + เวลาสแกน + สถานะที่ประตู
 *   - gate_round_events         round_end (เวลาที่ใช้ · ขอเวลาเพิ่ม · เกินเพดาน · closedDocs) · bypass_open/close
 *   - activity_log              gate_confirm / zero_pick (ใครถ่ายรูปยืนยัน · picking) · gate_add_docs (ใบที่เพิ่มเข้ารอบ + บัตร)
 *     → ขาคืนแบบทยอยคืน (2026-10-08) เปิดรอบใหม่บนแถว RT เดิม ทำให้ picking_id ใน gate_logs ถูกเขียนทับ
 *       รอบก่อน ๆ จึงหาได้จาก closedDocs / activity_log เท่านั้น
 *   - error_logs "PickingID=…"  ALARM ของรอบ (เฉพาะคนที่ดู Error log ได้ — ข้อความมีรหัสบัตร/ชื่อผู้ถือบัตร)
 *
 * สิทธิ์: เหมือน getRequisitionHistory — R0 / ≥R6 เห็นทั้งไซต์ · R1–R5 เห็นเฉพาะรอบที่มีใบของตัวเอง (และเห็นเฉพาะใบของตัวเอง)
 *   ยกเว้นสายคลัง (roles.can_req) เห็นทุกรอบของไซต์ตัวเอง · ALARM ของรอบเห็นตามสิทธิ์ Error log (_seCanView)
 * PHP 7.4 เท่านั้น
 */

require_once __DIR__ . '/history_stats.php';   // hs_roleNum / hs_effectiveSite / hs_effectiveName / hs_msFromDb / hs_qtyStr
require_once __DIR__ . '/site_errors.php';     // _seCanView / _seClassify / _seCategories

if (!defined('PH_MAX_ROUNDS')) { define('PH_MAX_ROUNDS', 1500); }

/** เลข PickingID ที่ใช้ได้ (ข้อมูลเก่าจากชีตมีค่าที่ encoding เสีย) */
function _phValidPk(string $pk): bool {
    return (bool)preg_match('/^PK[0-9]{6,12}$/', $pk);
}

/** JSON ใน activity_log / gate_round_events → array (พังได้ = []) */
function _phJson($s): array {
    if ($s === null || $s === '') { return []; }
    $j = json_decode((string)$s, true);
    return is_array($j) ? $j : [];
}

function _phPlaceholders(array $a): string {
    return implode(',', array_fill(0, count($a), '?'));
}

// =========================================================================
// getPickingHistory(siteCode, days)
// =========================================================================
function rpc_getPickingHistory(PDO $pdo, ?array $user, array $args) {
    try {
        $roleNum    = hs_roleNum($pdo, $user);
        $filterSelf = ($roleNum !== 0 && $roleNum <= 5);
        // สายคลัง (roles.can_req) เป็นคนยืนยันรูปทุกรอบที่ตู้ → เห็นทุกรอบของไซต์ตัวเอง (ประวัติเอกสารยังเห็นเฉพาะใบตัวเองตามเดิม)
        if ($filterSelf && _iiRole($pdo, $user)['canReq']) { $filterSelf = false; }
        $callerUser = hs_effectiveName($user);
        $site       = hs_effectiveSite($user, $args[0] ?? '', $roleNum);
        $days       = max(0, (int)($args[1] ?? 0));

        $projectId = null;
        if ($site !== '') {
            $projectId = hs_projectIdByCode($pdo, $site);
            if ($projectId === null) { return ['success' => true, 'canSeeAlarms' => false, 'rounds' => []]; }
        }
        $since = $days > 0 ? date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days')) : null;

        /** @var array<string,array> $R  pk → รอบ */
        $R = [];
        $touch = function (string $pk) use (&$R): void {
            if (!isset($R[$pk])) {
                $R[$pk] = ['pk' => $pk, 'projectId' => null, 'gate' => '', 'start' => null, 'end' => null,
                           'roundEnd' => null, 'bypass' => false, 'bypassBy' => '', 'docs' => [], 'cards' => [],
                           'confirmers' => [], 'glStatus' => [], 'alarms' => []];
            }
        };
        $minTs = function (?string $a, ?string $b): ?string {
            if ($a === null || $a === '') return ($b === null || $b === '') ? null : $b;
            if ($b === null || $b === '') return $a;
            return strcmp($a, $b) <= 0 ? $a : $b;
        };
        $addDoc = function (string $pk, string $docNo) use (&$R): void {
            if ($docNo !== '' && !isset($R[$pk]['docs'][$docNo])) { $R[$pk]['docs'][$docNo] = ['glStatus' => '', 'card' => '', 'scannedAt' => null]; }
        };

        // ---- 1) gate_logs (picking_id ปัจจุบันของแต่ละแถว) ----
        $w = ["gl.picking_id IS NOT NULL", "gl.picking_id <> ''"];
        $p = [];
        if ($projectId !== null) { $w[] = 'gl.project_id = ?'; $p[] = $projectId; }
        if ($since !== null)     { $w[] = 'COALESCE(gl.scanned_at, gl.updated_at) >= ?'; $p[] = $since; }
        $st = $pdo->prepare("SELECT gl.doc_no, gl.leg, gl.picking_id, gl.card_id, gl.scanned_at, gl.status, gl.updated_at,
                                    gl.project_id, g.gate_code
                               FROM gate_logs gl LEFT JOIN gates g ON g.id = gl.gate_id
                              WHERE " . implode(' AND ', $w) . " ORDER BY gl.id");
        $st->execute($p);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pk = trim((string)$r['picking_id']);
            if (!_phValidPk($pk)) { continue; }
            $touch($pk);
            $doc = (string)$r['doc_no'];
            $addDoc($pk, $doc);
            $R[$pk]['docs'][$doc]['glStatus']  = (string)$r['status'];
            $R[$pk]['docs'][$doc]['card']      = trim((string)$r['card_id']);
            $R[$pk]['docs'][$doc]['scannedAt'] = $r['scanned_at'];
            $R[$pk]['glStatus'][] = (string)$r['status'];
            if ($R[$pk]['projectId'] === null) { $R[$pk]['projectId'] = (int)$r['project_id']; }
            if ($R[$pk]['gate'] === '' && $r['gate_code'] !== null) { $R[$pk]['gate'] = (string)$r['gate_code']; }
            if (trim((string)$r['card_id']) !== '') { $R[$pk]['cards'][trim((string)$r['card_id'])] = true; }
            $R[$pk]['start'] = $minTs($R[$pk]['start'], $r['scanned_at']);
            // แถวที่ปิดแล้ว (ยังไม่มี round_end) — ใช้ updated_at เป็นเวลาปิดชั่วคราว ถ้าห่างจากเวลาสแกนไม่เกิน 1 วัน
            // (ข้อมูลนำเข้าจากชีตมี updated_at = วันนำเข้า ไม่ใช่เวลาปิดจริง)
            if ((string)$r['status'] === 'Closed' && $r['scanned_at'] && $r['updated_at']
                && strtotime((string)$r['updated_at']) - strtotime((string)$r['scanned_at']) <= 86400) {
                $e = (string)$r['updated_at'];
                if ($R[$pk]['end'] === null || strcmp($e, $R[$pk]['end']) > 0) { $R[$pk]['end'] = $e; }
            }
        }

        // ---- 2) gate_round_events ----
        $w = ["event IN ('round_end','bypass_open','bypass_close')", "picking_id IS NOT NULL", "picking_id <> ''"];
        $p = [];
        if ($projectId !== null) { $w[] = 'project_id = ?'; $p[] = $projectId; }
        if ($since !== null)     { $w[] = 'created_at >= ?'; $p[] = $since; }
        $st = $pdo->prepare("SELECT project_id, gate_code, picking_id, event, item_count, seq, total_min, over_cap, detail, created_at
                               FROM gate_round_events WHERE " . implode(' AND ', $w) . " ORDER BY id");
        $st->execute($p);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pk = trim((string)$r['picking_id']);
            if (!_phValidPk($pk)) { continue; }
            $touch($pk);
            $d = _phJson($r['detail']);
            if ($R[$pk]['projectId'] === null && $r['project_id'] !== null) { $R[$pk]['projectId'] = (int)$r['project_id']; }
            if ($R[$pk]['gate'] === '' && $r['gate_code'] !== null) { $R[$pk]['gate'] = (string)$r['gate_code']; }
            $docs = [];
            if (isset($d['closedDocs']) && is_array($d['closedDocs'])) { $docs = $d['closedDocs']; }
            elseif (isset($d['docs']) && is_array($d['docs']))        { $docs = $d['docs']; }
            foreach ($docs as $dn) { $addDoc($pk, trim((string)$dn)); }
            $ev = (string)$r['event'];
            if ($ev === 'bypass_open') {
                $R[$pk]['bypass'] = true;
                $R[$pk]['start'] = $minTs($R[$pk]['start'], (string)$r['created_at']);
            } elseif ($ev === 'bypass_close') {
                $R[$pk]['bypass'] = true;
                $R[$pk]['end'] = (string)$r['created_at'];
            } else {   // round_end
                $used = isset($d['usedSec']) ? (int)$d['usedSec'] : null;
                $R[$pk]['roundEnd'] = [
                    'usedSec'      => $used,
                    'overrunSec'   => isset($d['overrunSec']) ? (int)$d['overrunSec'] : 0,
                    'pickExtends'  => isset($d['pickExtends']) ? (int)$d['pickExtends'] : (int)$r['seq'],
                    'closeExtends' => isset($d['closeExtends']) ? (int)$d['closeExtends'] : 0,
                    'totalMin'     => $r['total_min'] !== null ? (float)$r['total_min'] : null,
                    'overCap'      => (int)$r['over_cap'] === 1,
                    'itemCount'    => $r['item_count'] !== null ? (int)$r['item_count'] : null,
                ];
                $R[$pk]['end'] = (string)$r['created_at'];
                if ($used !== null && $used >= 0) {
                    $R[$pk]['start'] = $minTs($R[$pk]['start'], date('Y-m-d H:i:s', strtotime((string)$r['created_at']) - $used));
                }
            }
        }

        // ---- 3) activity_log: gate_add_docs (entity = PK) ----
        $pks = array_keys($R);
        foreach (array_chunk($pks, 400) as $chunk) {
            $st = $pdo->prepare("SELECT entity_id, user_name, new_value, created_at FROM activity_log
                                  WHERE entity_type = 'picking' AND action = 'gate_add_docs' AND entity_id IN (" . _phPlaceholders($chunk) . ")
                                  ORDER BY id");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $pk = (string)$r['entity_id'];
                $d = _phJson($r['new_value']);
                foreach ((array)($d['docs'] ?? []) as $dn) { $addDoc($pk, trim((string)$dn)); }
                if (!empty($d['card'])) { $R[$pk]['cards'][strtoupper(trim((string)$d['card']))] = true; }
                if ($R[$pk]['gate'] === '' && !empty($d['gate'])) { $R[$pk]['gate'] = (string)$d['gate']; }
            }
        }

        // ---- 4) เอกสาร: gate_logs (หา document_id ของแถว RT / TG ขาเข้า) + documents ----
        $allDocNos = [];
        foreach ($R as $pk => $x) { foreach (array_keys($x['docs']) as $dn) { $allDocNos[$dn] = true; } }
        $glMap = [];   // doc_no (ที่ประตู) → [document_id, leg, gate_code, project_id]
        foreach (array_chunk(array_keys($allDocNos), 400) as $chunk) {
            $st = $pdo->prepare("SELECT gl.doc_no, gl.document_id, gl.leg, gl.project_id, g.gate_code FROM gate_logs gl
                                   LEFT JOIN gates g ON g.id = gl.gate_id WHERE gl.doc_no IN (" . _phPlaceholders($chunk) . ")");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $glMap[(string)$r['doc_no']] = $r; }
        }
        $docIds = [];      // document_id → true
        $docNoToId = [];   // doc_no ที่ประตู → document_id
        $byDocNo = [];     // documents.doc_no ที่ต้องหาเอง
        foreach (array_keys($allDocNos) as $dn) {
            if (isset($glMap[$dn]) && $glMap[$dn]['document_id'] !== null) {
                $docNoToId[$dn] = (int)$glMap[$dn]['document_id'];
                $docIds[(int)$glMap[$dn]['document_id']] = true;
            } else {
                $byDocNo[$dn] = true;
                if (preg_match('/^(.+)RT$/', $dn, $m)) { $byDocNo[$m[1]] = true; }
            }
        }
        $docRows = [];   // document_id → row
        $docNoRow = [];  // documents.doc_no → document_id
        if ($byDocNo) {
            foreach (array_chunk(array_keys($byDocNo), 400) as $chunk) {
                $st = $pdo->prepare("SELECT id, doc_no FROM documents WHERE doc_no IN (" . _phPlaceholders($chunk) . ")");
                $st->execute($chunk);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $docNoRow[(string)$r['doc_no']] = (int)$r['id']; $docIds[(int)$r['id']] = true; }
            }
            foreach (array_keys($allDocNos) as $dn) {
                if (isset($docNoToId[$dn])) continue;
                if (isset($docNoRow[$dn])) { $docNoToId[$dn] = $docNoRow[$dn]; }
                elseif (preg_match('/^(.+)RT$/', $dn, $m) && isset($docNoRow[$m[1]])) { $docNoToId[$dn] = $docNoRow[$m[1]]; }
            }
        }
        $items = [];   // document_id → [items]
        foreach (array_chunk(array_keys($docIds), 400) as $chunk) {
            $st = $pdo->prepare("SELECT id, doc_no, doc_type, status, project_id, requester_username, receiver_name, origin_type
                                   FROM documents WHERE id IN (" . _phPlaceholders($chunk) . ")");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $docRows[(int)$r['id']] = $r; }
            $st = $pdo->prepare("SELECT di.document_id, di.mat_code, di.mat_name, di.unit, di.qty, di.qty_actual, di.actual_reason,
                                        di.qty_returned, di.return_reason, m.name AS master_name, m.unit AS master_unit
                                   FROM document_items di LEFT JOIN materials m ON m.mat_code = di.mat_code
                                  WHERE di.document_id IN (" . _phPlaceholders($chunk) . ") ORDER BY di.id");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $items[(int)$r['document_id']][] = $r; }
        }

        // ---- 5) activity_log: gate_confirm / zero_pick (ใครยืนยัน · รอบไหน) ----
        $confirm = [];   // pk|doc → ['by'=>, 'at'=>, 'zero'=>bool, 'reasons'=>[]]
        foreach (array_chunk(array_keys($allDocNos), 400) as $chunk) {
            $st = $pdo->prepare("SELECT entity_id, user_name, action, new_value, created_at FROM activity_log
                                  WHERE entity_type = 'document' AND action IN ('gate_confirm','zero_pick')
                                    AND entity_id IN (" . _phPlaceholders($chunk) . ") ORDER BY id");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $d = _phJson($r['new_value']);
                $pk = trim((string)($d['picking'] ?? ''));
                $dn = (string)$r['entity_id'];
                if ($pk === '' || !isset($R[$pk])) { continue; }
                $addDoc($pk, $dn);
                $k = $pk . '|' . $dn;
                if (!isset($confirm[$k])) { $confirm[$k] = ['by' => '', 'at' => '', 'zero' => false, 'reasons' => []]; }
                if ($r['action'] === 'gate_confirm') {
                    $confirm[$k]['by'] = trim((string)$r['user_name']);
                    $confirm[$k]['at'] = (string)$r['created_at'];
                    if ($confirm[$k]['by'] !== '') { $R[$pk]['confirmers'][$confirm[$k]['by']] = true; }
                } else {
                    $confirm[$k]['zero'] = true;
                    $confirm[$k]['reasons'] = array_values(array_filter(array_map('strval', (array)($d['reasons'] ?? []))));
                }
            }
        }

        // ---- 6) ALARM ของรอบ (error_logs) ----
        $siteCodes = [];
        foreach ($pdo->query('SELECT id, code FROM projects')->fetchAll(PDO::FETCH_ASSOC) as $r) { $siteCodes[(int)$r['id']] = (string)$r['code']; }
        $canSeeAlarms = $projectId !== null ? _seCanView($pdo, $user, $site) : ($roleNum === 0);
        $cats = _seCategories();
        if ($canSeeAlarms && $R) {
            $w = ["message LIKE '%PickingID=PK%'"];
            $p = [];
            if ($projectId !== null) { $w[] = 'project_id = ?'; $p[] = $projectId; }
            if ($since !== null)     { $w[] = 'created_at >= ?'; $p[] = $since; }
            $st = $pdo->prepare('SELECT gate_code, message, created_at FROM error_logs WHERE ' . implode(' AND ', $w) . ' ORDER BY id LIMIT 5000');
            $st->execute($p);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $msg = (string)$r['message'];
                if (!preg_match('/PickingID=(PK[0-9]+)/', $msg, $m) || !isset($R[$m[1]])) { continue; }
                list($cat) = _seClassify($msg);
                $label = $cats[$cat][0] ?? $cat;
                if ($cat === 'pick_time') { $label = preg_match('/Pick time extended/i', $msg) ? 'ขอเวลาเพิ่ม' : 'หมดเวลาหยิบของ'; }
                $R[$m[1]]['alarms'][] = ['at' => (string)$r['created_at'], 'gate' => (string)$r['gate_code'], 'cat' => $cat,
                                         'label' => $label, 'message' => mb_substr($msg, 0, 400, 'UTF-8')];
            }
        }

        // ---- 7) ผู้ถือบัตร ----
        $allCards = [];
        foreach ($R as $x) { foreach (array_keys($x['cards']) as $c) { $allCards[$c] = true; } }
        $holders = $allCards ? s05CardHolders($pdo, array_keys($allCards)) : [];

        // ---- 8) ประกอบผลลัพธ์ ----
        $out = [];
        foreach ($R as $pk => $x) {
            if ($projectId !== null && $x['projectId'] !== null && $x['projectId'] !== $projectId) { continue; }
            $docsOut = [];
            $hidden = 0;
            $types = [];
            $qtyReq = 0.0; $qtyAct = 0.0; $nItems = 0;
            foreach ($x['docs'] as $dn => $gl) {
                $id = $docNoToId[$dn] ?? null;
                $doc = $id !== null ? ($docRows[$id] ?? null) : null;
                if ($x['projectId'] === null && $doc) { $x['projectId'] = (int)$doc['project_id']; }
                if ($filterSelf && (!$doc || strcasecmp(trim((string)$doc['requester_username']), $callerUser) !== 0)) { $hidden++; continue; }
                $leg = isset($glMap[$dn]) ? (string)$glMap[$dn]['leg'] : (preg_match('/RT$/', $dn) ? 'return' : 'out');
                $type = $doc ? (string)$doc['doc_type'] : (preg_match('/^([A-Z]{2})/', $dn, $mm) ? $mm[1] : '');
                $types[$type . ($leg === 'return' ? 'RT' : '')] = true;
                $its = [];
                foreach (($id !== null ? ($items[$id] ?? []) : []) as $it) {
                    $nm = trim((string)($it['master_name'] ?? '')) !== '' ? (string)$it['master_name']
                        : (trim((string)$it['mat_name']) !== '' ? (string)$it['mat_name'] : (string)$it['mat_code']);
                    $q = (float)$it['qty'];
                    $a = ($it['qty_actual'] !== null && $it['qty_actual'] !== '') ? (float)$it['qty_actual'] : null;
                    $row = ['code' => (string)$it['mat_code'], 'name' => $nm,
                            'unit' => trim((string)($it['unit'] ?: ($it['master_unit'] ?? ''))),
                            'qty' => hs_num($q), 'actual' => $a === null ? null : hs_num($a),
                            'reason' => trim((string)($it['actual_reason'] ?? ''))];
                    if ($leg === 'return') {
                        $row['returned'] = hs_num((float)($it['qty_returned'] ?? 0));
                        $row['reason'] = trim((string)($it['return_reason'] ?? ''));
                    }
                    $its[] = $row;
                    $nItems++;
                    $qtyReq += $q;
                    $qtyAct += ($leg === 'return') ? (float)($it['qty_returned'] ?? 0) : ($a === null ? $q : $a);
                }
                $c = $confirm[$pk . '|' . $dn] ?? null;
                $docsOut[] = [
                    'docNo'      => $dn,
                    'docId'      => $doc ? (string)$doc['doc_no'] : $dn,
                    'type'       => $type,
                    'leg'        => $leg,
                    'status'     => $doc ? (string)$doc['status'] : '',
                    'gateStatus' => $gl['glStatus'],
                    'requester'  => $doc ? (string)$doc['requester_username'] : '',
                    'receiver'   => $doc ? (string)$doc['receiver_name'] : '',
                    'bypass'     => $doc ? strtolower(trim((string)$doc['origin_type'])) === 'bypass' : false,
                    'holder'     => $gl['card'] !== '' ? ($holders[$gl['card']] ?? ('CardID: ' . $gl['card'])) : '',
                    'confirmedBy'=> $c ? $c['by'] : '',
                    'confirmedAt'=> $c ? $c['at'] : '',
                    'zeroPick'   => $c ? $c['zero'] : false,
                    'zeroReasons'=> $c ? $c['reasons'] : [],
                    'items'      => $its,
                ];
            }
            if (!$docsOut) { continue; }   // R1–R5: รอบที่ไม่มีใบของตัวเอง

            $gs = array_unique($x['glStatus']);
            if ($x['roundEnd'] !== null || ($x['bypass'] && $x['end'] !== null)) { $status = 'closed'; }
            elseif (array_intersect($gs, ['Opened', 'Scanned'])) { $status = 'open'; }
            elseif (in_array('Confirmed', $gs, true)) { $status = 'confirmed'; }
            elseif ($gs && count(array_diff($gs, ['Cancelled'])) === 0) { $status = 'cancelled'; }
            elseif (in_array('Closed', $gs, true)) { $status = 'closed'; }
            else { $status = 'closed'; }

            $hNames = [];
            foreach (array_keys($x['cards']) as $c) { $hNames[] = $holders[$c] ?? ('CardID: ' . $c); }
            $re = $x['roundEnd'];
            $out[] = [
                'pk'          => $pk,
                'site'        => $x['projectId'] !== null ? ($siteCodes[$x['projectId']] ?? '') : '',
                'gate'        => $x['gate'],
                'start'       => hs_msFromDb($x['start']),
                'end'         => hs_msFromDb($x['end']),
                'startStr'    => $x['start'] ? hs_fmtDateHist($x['start']) : '',
                'endStr'      => $x['end'] ? hs_fmtDateHist($x['end']) : '',
                'status'      => $status,
                'bypass'      => $x['bypass'],
                'usedSec'     => $re ? $re['usedSec'] : ($x['start'] && $x['end'] ? max(0, strtotime($x['end']) - strtotime($x['start'])) : null),
                'usedExact'   => $re !== null && $re['usedSec'] !== null,
                'pickExtends' => $re ? $re['pickExtends'] : 0,
                'closeExtends'=> $re ? $re['closeExtends'] : 0,
                'overrunSec'  => $re ? $re['overrunSec'] : 0,
                'overCap'     => $re ? $re['overCap'] : false,
                'holders'     => array_values(array_unique($hNames)),
                'confirmers'  => array_keys($x['confirmers']),
                'types'       => array_keys($types),
                'docs'        => $docsOut,
                'hiddenDocs'  => $hidden,
                'itemCount'   => $nItems,
                'qtyReq'      => hs_num($qtyReq),
                'qtyActual'   => hs_num($qtyAct),
                'alarms'      => $x['alarms'],
            ];
        }
        usort($out, function ($a, $b) {
            if ($a['start'] !== $b['start']) { return $b['start'] <=> $a['start']; }
            return strcmp($b['pk'], $a['pk']);
        });
        if (count($out) > PH_MAX_ROUNDS) { $out = array_slice($out, 0, PH_MAX_ROUNDS); }
        return ['success' => true, 'canSeeAlarms' => $canSeeAlarms, 'rounds' => $out];
    } catch (Throwable $e) {
        error_log('getPickingHistory: ' . $e->getMessage());
        return ['success' => false, 'message' => 'โหลดรายการ Picking list ไม่สำเร็จ'];
    }
}
