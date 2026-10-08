<?php
/**
 * CONNEXT — lib/gate_api.php : QR page + gate polling + photo-confirm page
 *
 * Port 1:1 จาก GAS Code.js:
 *   getApprovedDocuments(siteCode)                → rpc_getApprovedDocuments
 *   getAwaitingGateDocIds(siteCode)               → rpc_getAwaitingGateDocIds
 *   getConfirmableGateSignature(siteCode)         → rpc_getConfirmableGateSignature
 *   getConfirmableDocuments(site, user, roleName) → rpc_getConfirmableDocuments
 *   checkGateStatusForDoc(docId)                  → rpc_checkGateStatusForDoc
 *   saveConfirmationData(payload)                 → rpc_saveConfirmationData
 *   getPickRoundState(pickingIds)                 → rpc_getPickRoundState   (ใหม่ — Scenario 05)
 *
 * [Scenario 05 · 2026-09-28] หน้าถ่ายรูปยืนยัน:
 *   - รูปผูกกับรายการ (รหัส IC) อย่างน้อยรายการละ 1 รูป (รายการที่หยิบจริง 0 ไม่ต้องถ่าย) — กติกาข้อ 21
 *   - ช่อง "หยิบจริง" ต่อรายการ (RD/OD/BD ขาออก): 0 ≤ หยิบจริง ≤ จำนวนที่ขอ (ฉบับแก้ 2026-09-29 — ห้ามเพิ่มเกินที่ขอ
 *     ต้องการมากกว่าให้ออกใบใหม่ · เดิม +3 และ ≤ ยอดพร้อมเบิกของ G) · ลดต้องมีเหตุผล · เก็บแยกจากจำนวนที่ขอ ·
 *     activity_log ทุกรายการที่แก้ (⑦) · ใบ IN ใช้ยอดตามใบ
 *   - [2026-09-29] ขาคืนของใบยืม (…RT): ช่อง "คืนจริง" 0 ≤ คืนจริง ≤ ยอดค้าง (ยืมจริง − ที่ตีเป็นชำรุด/สูญหาย) ·
 *     ลดต้องมีเหตุผล → document_items.qty_returned / return_reason (③ ขั้น 4 · ⑦) · ส่วนที่ไม่คืนรอสายสโตร์ตี
 *   - บันทึกได้เฉพาะใบที่แตะบัตรแล้ว (Opened/Scanned) · บันทึกแล้วแก้ไม่ได้ · ผู้ขอหรือสายสโตร์เท่านั้น
 *   - หน้าเข้าโหมด "รอปิดประตู" เฉพาะเมื่อทุกใบของรอบยืนยันครบ (ข้อมูลรอบจาก rounds / getPickRoundState)
 *   - [2026-09-29] หน้า QR: การ์ดขาคืน (…RT · isReturn) ขึ้นแล้ว (พอร์ตเดิมไม่แสดง) · ใบยืมมีกำหนดวันคืน (dueDate)
 *
 * ตัวตน/สิทธิ์ (site, username, canReq) ใช้จาก session + re-query DB เสมอ —
 * args จาก client รับไว้ตามลายเซ็นเดิมเพื่อ shim แต่ไม่ใช้ตัดสินสิทธิ์
 *
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/stock.php';   // finalizeGateDoc, _stockGateCodeFromDocNo
require_once __DIR__ . '/photos.php';  // savePhotoDataUri, photoUrlsAppend
require_once __DIR__ . '/s05.php';     // จำนวนหยิบจริง · รอบเบิก · เจ้าของบัตร
require_once __DIR__ . '/borrow.php';  // ใบยืม: ยอดค้างคืน · กำหนดวันคืน (Scenario 05 ③ · 2026-09-29)

// =========================================================================
// helpers ภายใน
// =========================================================================

/** site (project) ที่มีผลจริง = ของ session เสมอ (server-authoritative) */
function _gateProjectId(?array $user): int {
    return (int)($user['projectId'] ?? 0);
}

/** SubID(3 หลัก) → SubName (mirror subMap: อ่านทั้งชีต แถวหลังทับแถวก่อน) */
function _gateSubNameMap(PDO $pdo): array {
    $map = [];
    $st = $pdo->query('SELECT sub_code, name FROM subcontractors ORDER BY id');
    while ($row = $st->fetch()) {
        $map[fmtSubId($row['sub_code'])] = trim((string)$row['name']);
    }
    return $map;
}

/**
 * mirror `subMap[fmtSubId_(v)] || fmtSubId_(v)` — receiver ในชีตอาจเป็น SubID
 * (เลข → resolve เป็นชื่อ) หรือ SubName/'DC:...' ตรง ๆ (fmtSubId คืนค่าเดิม)
 */
function _gateResolveReceiver(array $subMap, $receiver): string {
    $key = fmtSubId((string)$receiver);
    if ($key !== '' && isset($subMap[$key]) && $subMap[$key] !== '') {
        return $subMap[$key];
    }
    return $key;
}

/** 'dd/mm/yyyy hh:mm' — mirror fmtDate ใน getApprovedDocuments */
function _gateFmtDate(?string $dbDatetime): string {
    if ($dbDatetime === null || trim($dbDatetime) === '') {
        return '';
    }
    try {
        $dt = new DateTime($dbDatetime);
        return $dt->format('d/m/Y H:i');
    } catch (Throwable $e) {
        return trim($dbDatetime);
    }
}

/** DATETIME (เวลาไทยใน DB) → epoch ms (client ใช้เรียง openedAt แบบตัวเลข) */
function _gateEpochMs(?string $dbDatetime): int {
    if ($dbDatetime === null || trim($dbDatetime) === '') {
        return 0;
    }
    try {
        $dt = new DateTime($dbDatetime); // tz Bangkok ตาม config
        return $dt->getTimestamp() * 1000;
    } catch (Throwable $e) {
        return 0;
    }
}

/** mirror userCanReq_: Users → RoleID → Roles.CanReq (server-authoritative) */
function _gateUserCanReq(PDO $pdo, ?array $user): bool {
    if (!$user || ($user['accountType'] ?? '') !== 'user') {
        return false; // subcontractor ไม่มี role → CanReq=false (พฤติกรรมเดิม)
    }
    $username = trim((string)($user['username'] ?? ''));
    if ($username === '') {
        return false;
    }
    try {
        $st = $pdo->prepare(
            'SELECT r.can_req FROM users u JOIN roles r ON r.id = u.role_id WHERE u.username = ? LIMIT 1'
        );
        $st->execute([$username]);
        $v = $st->fetchColumn();
        return $v !== false && isTrueFlag($v);
    } catch (Throwable $e) {
        error_log('_gateUserCanReq: ' . $e->getMessage());
        return false;
    }
}

/**
 * mirror gateUsesScanFlow_(gateId): '' → false (flow เดิม/ปลอดภัยสุด)
 * เดิมเทียบกับ const HARDWARE_CLOSE_GATES=['G01'] — ตอนนี้อ่าน gates.hardware_close;
 * ไม่พบแถว gate ใน DB = ไม่อยู่ในลิสต์ hardware → scan-flow (พฤติกรรม GAS เดิม)
 */
function _gateUsesScanFlow(PDO $pdo, string $gateCode, int $projectId): bool {
    $gateCode = strtoupper(trim($gateCode));
    // ต่างจาก GAS โดยตั้งใจ (CHANGES-FROM-GAS.md §ใบ IN ไร้ประตู): ใบไร้ประตู = scan-flow
    // → ถ่ายรูปยืนยันแล้ว finalizeGateDoc() ทำงาน ของเข้าสต๊อกจริง
    // เดิมคืน false ทำให้ finalize ถูกข้าม ใบค้างที่ Confirmed ตลอดกาล
    if ($gateCode === '') {
        return true;
    }
    $st = $pdo->prepare('SELECT hardware_close FROM gates WHERE gate_code = ? AND project_id = ? LIMIT 1');
    $st->execute([$gateCode, $projectId]);
    $hw = $st->fetchColumn();
    if ($hw === false) {
        return true; // ไม่รู้จักประตูนี้ = ไม่ใช่ประตู hardware → scan-flow (mirror GAS)
    }
    return !isTrueFlag($hw);
}

/**
 * สรุปรอบ (picking) สำหรับหน้าถ่ายรูปยืนยัน: {pickingId: {gate, total, confirmed, closed, allConfirmed, pending:[docId...]}}
 * ใบขาคืน (…RT) นับเป็นใบหนึ่งของรอบเหมือนใบอื่น
 */
function _gateRoundsSummary(PDO $pdo, array $pickingIds, int $projectId, bool $anyProject = false): array {
    $ids = [];
    foreach ($pickingIds as $p) {
        $p = trim((string)$p);
        if ($p !== '') { $ids[$p] = true; }
    }
    if (!$ids) { return []; }
    $keys = array_keys($ids);
    $ph = implode(',', array_fill(0, count($keys), '?'));
    $sql = "SELECT gl.picking_id, gl.doc_no, gl.status, g.gate_code, g.hardware_close, gl.project_id
              FROM gate_logs gl LEFT JOIN gates g ON g.id = gl.gate_id
             WHERE gl.picking_id IN ($ph)";
    $ar = $keys;
    if (!$anyProject) { $sql .= ' AND gl.project_id = ?'; $ar[] = $projectId; }
    $st = $pdo->prepare($sql . ' ORDER BY gl.scanned_at, gl.id');
    $st->execute($ar);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $pk = (string)$r['picking_id'];
        $gate = strtoupper(trim((string)($r['gate_code'] ?? '')));
        $key = $pk;
        if (!isset($out[$key])) {
            // scanFlow = ประตูไม่มีตู้ (ธง hardware_close ไม่ติ๊ก) — ปิดงานตอนยืนยัน ไม่มีขั้น "รอปิดประตู"
            $out[$key] = ['pickingId' => $pk, 'gate' => $gate, 'total' => 0, 'confirmed' => 0,
                          'closed' => false, 'allConfirmed' => false, 'pending' => [],
                          'scanFlow' => $gate === '' || ($r['hardware_close'] !== null && !isTrueFlag($r['hardware_close']))];
        }
        $s = strtolower(trim((string)$r['status']));
        $out[$key]['total']++;
        if ($s === 'confirmed' || $s === 'closed') { $out[$key]['confirmed']++; }
        if ($s === 'closed') { $out[$key]['closed'] = true; }
        if ($s === 'opened' || $s === 'scanned') { $out[$key]['pending'][] = trim((string)$r['doc_no']); }
    }
    foreach ($out as $k => $v) {
        $out[$k]['allConfirmed'] = $v['total'] > 0 && $v['confirmed'] === $v['total'];
    }
    return $out;
}

/** สรุปรอบตามเลขรอบ — หน้าถ่ายรูปยืนยัน poll หลังบันทึก (รอใบอื่นในรอบ → รอปิดประตู → ปิดแล้ว) */
function rpc_getPickRoundState(PDO $pdo, ?array $user, array $args) {
    try {
        $ids = (isset($args[0]) && is_array($args[0])) ? $args[0] : [];
        $isR0 = (string)($user['roleLevel'] ?? '') === 'R0';
        return ['success' => true, 'rounds' => _gateRoundsSummary($pdo, $ids, _gateProjectId($user), $isR0)];
    } catch (Throwable $e) {
        error_log('getPickRoundState: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage(), 'rounds' => []];
    }
}

// =========================================================================
// getApprovedDocuments(siteCode) — การ์ด QR: ใบอนุมัติแล้วที่ประตูยัง Awaiting
// client ใช้: {success, data:[{docId,type,dateStr,receiver,reqName,siteCode,
//              items:[{matCode,matName,qty,unit}]}]} เรียง docId มาก→น้อย
// =========================================================================
function rpc_getApprovedDocuments(PDO $pdo, ?array $user, array $args) {
    try {
        $projectId = _gateProjectId($user);

        // ต้องมีแถว GateLogs และสถานะประตูยัง 'awaiting' (trim+lower — mirror GAS)
        // [Scenario 05 ③ · 2026-09-29] + ขาคืนของใบยืม: แถวประตู "<เลขใบ>RT" (mirror GAS: Sent Return → DocID+RT, isReturn)
        //   พอร์ตเดิม join แค่เลขใบตรงตัว การ์ด QR ขาคืนจึงไม่เคยขึ้น
        $st = $pdo->prepare(
            "SELECT d.id, d.doc_no, d.doc_type, d.status, d.doc_ts, d.receiver_name,
                    d.requester_username, d.rs_no, d.due_date, p.code AS site_code, 0 AS is_return
               FROM documents d
               JOIN projects  p  ON p.id = d.project_id
               JOIN gate_logs gl ON gl.doc_no = d.doc_no
              WHERE d.project_id = ?
                AND LOWER(TRIM(gl.status)) = 'awaiting'
             UNION ALL
             SELECT d.id, d.doc_no, d.doc_type, d.status, d.doc_ts, d.receiver_name,
                    d.requester_username, d.rs_no, d.due_date, p.code AS site_code, 1 AS is_return
               FROM documents d
               JOIN projects  p  ON p.id = d.project_id
               JOIN gate_logs gl ON gl.doc_no = CONCAT(d.doc_no, 'RT')
              WHERE d.project_id = ? AND d.doc_type = 'BD'
                AND LOWER(TRIM(gl.status)) = 'awaiting'
              ORDER BY id"
        );
        $st->execute([$projectId, $projectId]);
        $rows = $st->fetchAll();

        // กรองสถานะเอกสารตามชนิด (สตริงเดิมเป๊ะจาก GAS)
        $docs = [];
        foreach ($rows as $r) {
            $type    = (string)$r['doc_type'];
            $statusL = mb_strtolower(trim((string)$r['status']), 'UTF-8');
            $ok = false;
            if ((int)$r['is_return'] === 1) {
                $ok = strpos($statusL, 'sent return') !== false;   // ขาคืน: แจ้งคืนแล้ว รอสแกนที่ G เดิม
            } elseif ($type === Doc::TYPE_RD || $type === Doc::TYPE_OD || $type === 'TD' || $type === 'TG') {
                // + TD เบิกโอนย้ายข้ามไซต์ (2026-09-29) · SC ใบนับสต๊อกไม่ขึ้นหน้านี้ (QR อยู่ที่หน้าตรวจสอบประจำวัน)
                // + TG ใบย้าย Gate ขาเบิกออก (2026-10-02) — ขานำเข้าต่อท้ายด้านล่าง (gmQrInLegCards)
                $ok = strpos($statusL, 'approved') !== false;
            } elseif ($type === Doc::TYPE_BD) {
                $ok = strpos($statusL, 'sent borrow') !== false;
            } elseif ($type === Doc::TYPE_IN) {
                // GAS เทียบตรงตัว (case-sensitive) หลัง trim
                $ok = trim((string)$r['status']) === Doc::ST_SENT_INBOUND;
            }
            if ($ok) {
                $docs[] = $r;
            }
        }

        // [2026-10-02] การ์ดขานำเข้าของใบย้าย Gate (TG…GxxGyy · รอสแกนที่ G ปลายทาง) — lib/gatemove.php
        require_once __DIR__ . '/gatemove.php';
        $tgInCards = gmQrInLegCards($pdo, $projectId);
        if (!$docs) {
            usort($tgInCards, function ($a, $b) { return strcmp($b['docId'], $a['docId']); });
            return ['success' => true, 'data' => $tgInCards];
        }

        $subMap = _gateSubNameMap($pdo);

        // รายการวัสดุ + ชื่อ/หน่วยจาก master (mirror mainMap — lookup ณ เวลาที่เรียก)
        $ids = array_map(function ($d) { return (int)$d['id']; }, $docs);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $it  = $pdo->prepare(
            "SELECT di.document_id, di.mat_code, di.mat_name, di.qty,
                    m.name AS master_name, m.unit AS master_unit
               FROM document_items di
               LEFT JOIN materials m ON m.mat_code = di.mat_code
              WHERE di.document_id IN ($ph)
              ORDER BY di.id"
        );
        $it->execute($ids);
        $itemsByDoc = [];
        while ($row = $it->fetch()) {
            $itemsByDoc[(int)$row['document_id']][] = $row;
        }

        $results = [];
        $today = borrowToday();
        foreach ($docs as $d) {
            $type = (string)$d['doc_type'];
            $items = [];
            if ((int)$d['is_return'] === 1) {
                // ขาคืน: ยอดที่ต้องนำมาคืน = ยืมจริง − ที่ตีเป็นชำรุด/สูญหายแล้ว
                foreach (borrowDocItems($pdo, (int)$d['id']) as $bi) {
                    if ($bi['outstanding'] <= 0.0005) { continue; }
                    $items[] = ['matCode' => $bi['matCode'], 'matName' => $bi['matName'],
                                'qty' => $bi['outstanding'], 'unit' => $bi['unit']];
                }
                if (!$items) { continue; }
                $results[] = [
                    'docId'       => (string)$d['doc_no'] . 'RT',
                    'type'        => $type,
                    'isReturn'    => true,
                    'dateStr'     => _gateFmtDate($d['doc_ts']),
                    'receiver'    => _gateResolveReceiver($subMap, (string)$d['receiver_name']),
                    'reqName'     => (string)$d['requester_username'],
                    'siteCode'    => (string)$d['site_code'],
                    'items'       => $items,
                    'dueDate'     => (string)($d['due_date'] ?? ''),
                    'overdueDays' => borrowOverdueDays((string)($d['due_date'] ?? ''), $today),
                ];
                continue;
            }
            foreach ($itemsByDoc[(int)$d['id']] ?? [] as $row) {
                $code      = (string)$row['mat_code'];
                $hasMaster = $row['master_name'] !== null;
                if ($type === Doc::TYPE_OD) {
                    // OD: ชื่อจาก snapshot แถว (คอลัมน์ Name) ก่อน แล้วค่อย master
                    $snap    = (string)$row['mat_name'];
                    $matName = $snap !== '' ? $snap : ($hasMaster ? (string)$row['master_name'] : $code);
                } else {
                    // RD/BD/IN: mainMap[code] || {name: code}
                    $matName = $hasMaster ? (string)$row['master_name'] : $code;
                }
                $unit = $hasMaster ? (string)$row['master_unit'] : '';
                $items[] = [
                    'matCode' => $code,
                    'matName' => $matName,
                    'qty'     => (float)$row['qty'],
                    'unit'    => $unit,
                ];
            }

            if ($type === Doc::TYPE_IN) {
                $receiver = trim((string)$d['rs_no']); // IN: receiver = เลข RS
            } else {
                $receiver = _gateResolveReceiver($subMap, (string)$d['receiver_name']);
            }

            $one = [
                'docId'    => (string)$d['doc_no'],
                'type'     => $type,
                'dateStr'  => _gateFmtDate($d['doc_ts']),
                'receiver' => $receiver,
                'reqName'  => (string)$d['requester_username'],
                'siteCode' => (string)$d['site_code'],
                'items'    => $items,
            ];
            if ($type === Doc::TYPE_BD) {
                $one['dueDate'] = (string)($d['due_date'] ?? '');   // Scenario 05 ③: กำหนดวันคืนบนการ์ด QR
            }
            $results[] = $one;
        }

        $results = array_merge($results, $tgInCards);   // [2026-10-02] ขานำเข้าใบย้าย Gate
        // mirror: filtered.sort((a,b) => b.docId.localeCompare(a.docId))
        usort($results, function ($a, $b) {
            return strcmp($b['docId'], $a['docId']);
        });

        return ['success' => true, 'data' => $results];
    } catch (Throwable $e) {
        error_log('getApprovedDocuments: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// =========================================================================
// getAwaitingGateDocIds(siteCode) — poll เบา: DocID ที่ GateLogs='Awaiting'
// คืน array สตริงเรียงตามลำดับแถว (mirror อ่านชีตบน→ล่าง)
// =========================================================================
function rpc_getAwaitingGateDocIds(PDO $pdo, ?array $user, array $args) {
    try {
        $projectId = _gateProjectId($user);
        $st = $pdo->prepare(
            "SELECT doc_no FROM gate_logs
              WHERE project_id = ? AND LOWER(TRIM(status)) = 'awaiting'
              ORDER BY id"
        );
        $st->execute([$projectId]);
        $out = [];
        while (($id = $st->fetchColumn()) !== false) {
            $id = trim((string)$id);
            if ($id !== '') {
                $out[] = $id;
            }
        }
        return $out;
    } catch (Throwable $e) {
        error_log('getAwaitingGateDocIds: ' . $e->getMessage());
        return [];
    }
}

// =========================================================================
// getConfirmableGateSignature(siteCode) — poll เบาหน้าถ่ายรูป: DocID ที่
// GateLogs เป็น Opened/Scanned (client diff กับที่แสดงอยู่)
// =========================================================================
function rpc_getConfirmableGateSignature(PDO $pdo, ?array $user, array $args) {
    try {
        $projectId = _gateProjectId($user);
        $st = $pdo->prepare(
            "SELECT doc_no FROM gate_logs
              WHERE project_id = ? AND LOWER(TRIM(status)) IN ('opened','scanned')
              ORDER BY id"
        );
        $st->execute([$projectId]);
        $out = [];
        while (($id = $st->fetchColumn()) !== false) {
            $id = trim((string)$id);
            if ($id !== '') {
                $out[] = $id;
            }
        }
        return $out;
    } catch (Throwable $e) {
        error_log('getConfirmableGateSignature: ' . $e->getMessage());
        return [];
    }
}

// =========================================================================
// checkGateStatusForDoc(docId) — poll สถานะประตูของใบเดียว (modal QR ทุก 3 วิ)
// คืน {status: 'Awaiting'|'Opened'|...|'None'}
// =========================================================================
function rpc_checkGateStatusForDoc(PDO $pdo, ?array $user, array $args) {
    try {
        $target = trim((string)($args[0] ?? ''));
        if ($target === '') {
            return ['status' => 'None'];
        }
        $st = $pdo->prepare('SELECT status FROM gate_logs WHERE doc_no = ? LIMIT 1');
        $st->execute([$target]);
        $status = $st->fetchColumn();
        if ($status === false) {
            return ['status' => 'None'];
        }
        $status = trim((string)$status);
        // mirror GAS: สถานะว่างคง 'None'
        return ['status' => $status !== '' ? $status : 'None'];
    } catch (Throwable $e) {
        error_log('checkGateStatusForDoc: ' . $e->getMessage());
        return ['status' => 'None'];
    }
}

// =========================================================================
// getConfirmableDocuments(siteCode, username, roleName) — หน้าถ่ายรูปยืนยัน
// เอกสารที่ประตู Opened/Scanned (หรือไม่มี gate entry) รอถ่ายรูป
// มองเห็น: role ชื่อมี 'store' หรือ CanReq → ทั้งไซต์, อื่น ๆ → ของตัวเอง
// คืน {success, data:[{id,type,(isReturn),subName,status,userName,
//   matCode,matName,qty, items:[{matCode,matName,qty,unit,subgroup}],
//   openedAt(ms), gateStatus:'Opened'|'Scanned'|''}]} เรียง openedAt น้อย→มาก
// =========================================================================
/**
 * ยอดในระบบ (on_hand) ของวัสดุที่ประตูหนึ่ง — หน้าถ่ายรูปยืนยันเตือนเมื่อไม่พอ/ติดลบ (GP-42 · 2026-10-02)
 * null = ไม่มีแถวของประตูนี้ (ข้อมูลยุคก่อนแยกรายประตู)
 */
function _gateItemOnHand(PDO $pdo, int $projectId, int $materialId, string $gateCode): ?float {
    static $cache = [];
    $gateCode = strtoupper(trim($gateCode));
    if ($projectId <= 0 || $materialId <= 0 || $gateCode === '') { return null; }
    $k = $projectId . '|' . $materialId . '|' . $gateCode;
    if (!array_key_exists($k, $cache)) {
        $st = $pdo->prepare('SELECT gb.on_hand FROM stock_gate_balances gb JOIN gates g ON g.id = gb.gate_id
                              WHERE gb.project_id = ? AND gb.material_id = ? AND g.gate_code = ?');
        $st->execute([$projectId, $materialId, $gateCode]);
        $v = $st->fetchColumn();
        $cache[$k] = ($v === false) ? null : round((float)$v, 3);
    }
    return $cache[$k];
}

function rpc_getConfirmableDocuments(PDO $pdo, ?array $user, array $args) {
    try {
        $projectId  = _gateProjectId($user);
        // ตัวตนจาก session (ไม่เชื่อ args — mirror เจตนา server-authoritative ของ GAS)
        $callerName = trim((string)($user['username'] ?? ''));
        $roleNameL  = mb_strtolower(trim((string)($user['role'] ?? '')), 'UTF-8');
        $seeAll     = strpos($roleNameL, 'store') !== false || _gateUserCanReq($pdo, $user);

        // เอกสารของไซต์ — ลำดับ mirror GAS: อ่านชีต RD → OD → BD → IN บน→ล่าง
        $st = $pdo->prepare(
            "SELECT d.id, d.doc_no, d.doc_type, d.status, d.receiver_name,
                    d.requester_username, d.rs_no, d.gate_id, d.project_id
               FROM documents d
              WHERE d.project_id = ?
              ORDER BY FIELD(d.doc_type, 'RD','OD','BD','IN','TD'), d.id"
        );
        $st->execute([$projectId]);
        $rows = $st->fetchAll();

        // กรองสถานะต่อชนิด + กรองเจ้าของ + คำนวณ id (BD ขาคืน → +RT)
        $cands = []; // แต่ละตัว: doc row + 'gid' (gate doc id) + 'isReturn'
        foreach ($rows as $r) {
            $type    = (string)$r['doc_type'];
            $statusL = mb_strtolower(trim((string)$r['status']), 'UTF-8');
            $isReturn = false;
            $ok = false;
            if ($type === Doc::TYPE_RD || $type === 'TD') {
                // + TD เบิกโอนย้ายข้ามไซต์ (2026-09-29) ถ่ายรูปยืนยันเหมือนใบเบิก · SC ใบนับสต๊อกบันทึกผลที่หน้าตรวจสอบประจำวัน
                $ok = strpos($statusL, 'approved') !== false || strpos($statusL, 'อนุมัติแล้ว') !== false;
            } elseif ($type === 'TG') {
                // [2026-10-02] ใบย้าย Gate: ขาเบิกออก (Approved) / ขานำเข้า (In Transit — แถวประตูเลขใบ + G ปลายทาง)
                $ok = strpos($statusL, 'approved') !== false || strpos($statusL, 'in transit') !== false;
            } elseif ($type === Doc::TYPE_OD) {
                $ok = strpos($statusL, 'approved') !== false
                   || strpos($statusL, 'sent to gate') !== false
                   || strpos($statusL, 'อนุมัติแล้ว') !== false;
            } elseif ($type === Doc::TYPE_BD) {
                $isReturn = strpos($statusL, 'sent return') !== false;
                $ok = $isReturn || strpos($statusL, 'sent borrow') !== false;
            } elseif ($type === Doc::TYPE_IN) {
                $ok = strpos($statusL, 'sent inbound') !== false;
            }
            if (!$ok) {
                continue;
            }
            $rowUser = trim((string)$r['requester_username']);
            // mirror GAS: เทียบตรงตัว (===) หลัง trim
            if (!$seeAll && $callerName !== '' && $rowUser !== $callerName) {
                continue;
            }
            $r['rowUser']  = $rowUser;
            $r['isReturn'] = $isReturn;
            $r['gid']      = $isReturn ? ((string)$r['doc_no'] . 'RT') : (string)$r['doc_no'];
            $r['tgIn']     = false;
            if ($type === 'TG' && strpos($statusL, 'in transit') !== false) {
                // [2026-10-02] ใบย้าย Gate ระหว่างย้าย → ขานำเข้า (แถวประตู leg in)
                require_once __DIR__ . '/gatemove.php';
                $tgRow = gmInLegRow($pdo, (int)$r['id']);
                if (!$tgRow) { continue; }
                $r['gid']  = (string)$tgRow['doc_no'];
                $r['tgIn'] = true;
            }
            $cands[] = $r;
        }

        if (!$cands) {
            return ['success' => true, 'data' => []];
        }

        // GateLogs ของ id ที่เกี่ยว (ขาคืนใช้แถว ...RT)
        $gids = array_values(array_unique(array_map(function ($c) { return $c['gid']; }, $cands)));
        $ph   = implode(',', array_fill(0, count($gids), '?'));
        $gl   = $pdo->prepare(
            "SELECT gl.doc_no, gl.status, gl.scanned_at, gl.picking_id, gl.card_id, gl.gate_id, g.gate_code
               FROM gate_logs gl LEFT JOIN gates g ON g.id = gl.gate_id
              WHERE gl.doc_no IN ($ph)"
        );
        $gl->execute($gids);
        $gateByDoc = [];
        while ($g = $gl->fetch()) {
            $gateByDoc[trim((string)$g['doc_no'])] = $g;
        }

        // gate check (mirror flush_): มีแถว gate แต่ยังไม่ Opened/Scanned → ข้าม
        // (ไม่มีแถว gate เลย = ผ่าน — พฤติกรรมเดิมของเอกสารแบบเก่า)
        $visible = [];
        foreach ($cands as $c) {
            $g = $gateByDoc[$c['gid']] ?? null;
            $gateStatus = '';
            $openedAt   = 0;
            if ($g !== null) {
                $gst = trim((string)$g['status']);
                if ($gst !== Gate::ST_OPENED && $gst !== Gate::ST_SCANNED) {
                    continue; // มี gate entry แต่ยังไม่ถูกสแกน → ยังไม่ขึ้นหน้าถ่ายรูป
                }
                $gateStatus = $gst;
                $openedAt   = _gateEpochMs($g['scanned_at']);
            }
            $c['gateStatus'] = $gateStatus;
            $c['openedAt']   = $openedAt;
            $c['pickingId']  = $g !== null ? trim((string)($g['picking_id'] ?? '')) : '';
            $c['cardId']     = $g !== null ? trim((string)($g['card_id'] ?? '')) : '';
            $c['gateCode']   = $g !== null ? strtoupper(trim((string)($g['gate_code'] ?? ''))) : '';
            $c['glGateId']   = ($g !== null && $g['gate_id'] !== null) ? (int)$g['gate_id'] : null;
            $visible[] = $c;
        }

        if (!$visible) {
            return ['success' => true, 'data' => []];
        }

        $subMap  = _gateSubNameMap($pdo);
        $holders = s05CardHolders($pdo, array_map(function ($c) { return $c['cardId']; }, $visible));

        // รายการวัสดุ + unit/subgroup จาก master (mirror matMap/unitMap/subgroupMap)
        $docIds = array_map(function ($c) { return (int)$c['id']; }, $visible);
        $ph2    = implode(',', array_fill(0, count($docIds), '?'));
        $it     = $pdo->prepare(
            "SELECT di.id AS item_id, di.document_id, di.material_id, di.mat_code, di.mat_name, di.qty, di.qty_actual, di.stock_deducted,
                    m.id AS master_id, m.name AS master_name, m.unit AS master_unit, m.subgroup_name AS master_subgroup
               FROM document_items di
               LEFT JOIN materials m ON m.mat_code = di.mat_code
              WHERE di.document_id IN ($ph2)
              ORDER BY di.id"
        );
        $it->execute($docIds);
        $itemsByDoc = [];
        while ($row = $it->fetch()) {
            $itemsByDoc[(int)$row['document_id']][] = $row;
        }

        $results = [];
        $order   = 0;
        foreach ($visible as $c) {
            $type      = (string)$c['doc_type'];
            $statusRaw = (string)$c['status'];

            if ($type === Doc::TYPE_IN) {
                $rs = (string)$c['rs_no'];
                $subName = $rs !== '' ? $rs : '-';
            } else {
                $subName = _gateResolveReceiver($subMap, (string)$c['receiver_name']);
                if ($subName === '') {
                    $subName = '-';
                }
            }

            if ($statusRaw === '') { // mirror `data[i][statusIdx] || default`
                if ($type === Doc::TYPE_BD)     { $statusRaw = 'Pending'; }
                elseif ($type === Doc::TYPE_IN) { $statusRaw = Doc::ST_SENT_INBOUND; }
                else                            { $statusRaw = Doc::ST_APPROVED; }
            }

            $doc = [
                'id'       => $c['gid'],
                'type'     => $type,
                'subName'  => $subName,
                'status'   => $statusRaw,
                'userName' => $c['rowUser'],
                'items'    => [],
            ];
            if ($type === Doc::TYPE_BD) {
                $doc['isReturn'] = (bool)$c['isReturn'];
            }
            // Scenario 05 ⑦ (ฉบับแก้ 2026-09-29): แก้จำนวนได้ทั้งใบเบิก/ยืมขาออก (หยิบจริง 0 … จำนวนที่ขอ — ห้ามเกินที่ขอ)
            // และขาคืนของใบยืม (คืนจริง 0 … ยอดค้าง = ยืมจริง − ที่ตีเป็นชำรุด/สูญหายแล้ว) · ใบ IN ยังใช้ยอดตามใบ
            $isRet             = (bool)$c['isReturn'];
            $editable          = $isRet ? ($type === Doc::TYPE_BD) : (in_array($type, [Doc::TYPE_RD, Doc::TYPE_OD, Doc::TYPE_BD, 'TD', 'TG'], true) && empty($c['tgIn']));
            $doc['pickingId']  = $c['pickingId'];
            $doc['gateCode']   = $c['gateCode'] !== '' ? $c['gateCode'] : _stockGateCodeFromDocNo((string)$c['gid']);
            $doc['cardId']     = $c['cardId'];
            $doc['cardholder'] = $c['cardId'] !== '' ? ($holders[$c['cardId']] ?? '') : '';
            $doc['editable']   = $editable;
            $doc['returnMode'] = $isRet;
            if ($type === 'TG') { $doc['moveLeg'] = !empty($c['tgIn']) ? 'in' : 'out'; }   // [2026-10-02] ใบย้าย Gate
            $doc['maxOver']    = S05_MAX_OVER_PICK;
            $retItems = [];
            if ($isRet) {
                foreach (borrowDocItems($pdo, (int)$c['id']) as $bi) { $retItems[$bi['id']] = $bi; }
            }

            // mirror pushItem_: items[] + ค่าย่อ matCode/matName/qty
            foreach ($itemsByDoc[(int)$c['id']] ?? [] as $row) {
                $code      = trim((string)$row['mat_code']);
                $hasMaster = $row['master_name'] !== null;
                $masterNm  = $hasMaster ? (string)$row['master_name'] : '';
                if ($type === Doc::TYPE_OD) {
                    $snap    = (string)$row['mat_name'];
                    $matName = $snap !== '' ? $snap : ($masterNm !== '' ? $masterNm : $code);
                } else {
                    $matName = $masterNm !== '' ? $masterNm : $code; // matMap[c] || c
                }
                $qty  = (float)$row['qty'];
                if (!empty($c['tgIn'])) {
                    // [2026-10-02] ขานำเข้าใบย้าย Gate: จำนวน = ที่เบิกออก (หยิบจริงขาเบิกออก) · รายการที่ไม่ได้เบิกออกไม่แสดง
                    if (!isTrueFlag($row['stock_deducted'] ?? 0)) { continue; }
                    $qty = s05EffQty($row);
                }
                $unit = $hasMaster ? trim((string)$row['master_unit']) : '';
                $sub  = ($hasMaster && $row['master_subgroup'] !== null) ? trim((string)$row['master_subgroup']) : '';
                $extra = [];
                if ($isRet) {
                    // ขาคืน: ต้องคืน = ยอดค้าง (ยืมจริง − คืนแล้วรอบก่อน − ตีชำรุด/สูญหายแล้ว) · ไม่เหลือ = ไม่แสดง
                    // [2026-10-08] ทยอยคืน — รอบถัดไปคืนได้เท่าที่ยังค้าง
                    $bi = $retItems[(int)$row['item_id']] ?? null;
                    if ($bi) {
                        $qty = (float)$bi['outstanding'];
                        $extra = ['borrowed' => $bi['borrowed'], 'writtenOff' => $bi['writtenOff'], 'returnedBefore' => (float)($bi['returned'] ?? 0)];
                    }
                    if ($qty <= 0.0005) { continue; }
                }

                $itemOut = [
                    'matCode'  => $code,
                    'matName'  => $matName !== '' ? $matName : ($code !== '' ? $code : '-'),
                    'qty'      => $qty,
                    'unit'     => $unit,
                    'subgroup' => $sub,
                    'itemId'   => (int)$row['item_id'],
                    'reqQty'   => $qty,
                ] + $extra;
                if ($editable) {
                    $itemOut['maxQty'] = round($qty, 3);   // เพดาน = จำนวนที่ขอ (ขาคืน = ยอดค้าง) — ต้องการมากกว่านี้ให้ออกใบใหม่
                }
                if ($editable && !$isRet && (int)($row['material_id'] ?? 0) > 0) {
                    // [2026-10-02 · GP-42] ยอดในระบบที่ประตูของรอบ (ยังไม่ตัดใบนี้) — ไม่พอ/ติดลบ = หน้าจอเตือนตอนหยิบ
                    $itemOut['gateOnHand'] = _gateItemOnHand($pdo, (int)$c['project_id'], (int)$row['material_id'], (string)$doc['gateCode']);
                }
                $doc['items'][] = $itemOut;
                $n = count($doc['items']);
                if ($n === 1) {
                    $doc['matCode'] = $code;
                    $doc['matName'] = $matName !== '' ? $matName : ($code !== '' ? $code : '-');
                    $doc['qty']     = $qty;
                } else {
                    $doc['matName'] = 'หลายรายการ (' . $n . ')';
                    $doc['qty']     = '-';
                }
            }

            $doc['openedAt']   = $c['openedAt'];
            $doc['gateStatus'] = $c['gateStatus'];
            $doc['_ord']       = $order++; // tiebreak คงลำดับเดิม (Array.sort ของ V8 เสถียร)
            $results[] = $doc;
        }

        // mirror: results.sort((a,b) => (a.openedAt||0) - (b.openedAt||0))
        usort($results, function ($a, $b) {
            $d = $a['openedAt'] - $b['openedAt'];
            if ($d !== 0) {
                return $d < 0 ? -1 : 1;
            }
            return $a['_ord'] - $b['_ord'];
        });
        foreach ($results as &$rr) {
            unset($rr['_ord']);
        }
        unset($rr);

        // สรุปรอบของใบที่แสดง (Scenario 05 ① ขั้น 6): หน้าเข้าโหมด "รอปิดประตู" เฉพาะเมื่อทุกใบของรอบยืนยันครบ
        $pks = [];
        foreach ($visible as $c) { if ($c['pickingId'] !== '') { $pks[] = $c['pickingId']; } }
        $rounds = _gateRoundsSummary($pdo, $pks, $projectId);

        return ['success' => true, 'data' => $results, 'rounds' => (object)$rounds];
    } catch (Throwable $e) {
        error_log('getConfirmableDocuments: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// =========================================================================
// saveConfirmationData(payload) — ถ่ายรูปยืนยันทีละใบ (Scenario 05: รูปรายรายการ + จำนวนหยิบจริง)
// payload = {docId, notes, items:[{itemId, actualQty, reason, images:[dataURI]}]}
//           (หน้าจอรุ่นเก่าส่ง images:[dataURI] ทั้งใบ — รับได้เฉพาะใบที่มีรายการเดียว)
//   1) ตรวจทุกอย่างก่อนเขียน: ใบต้องแตะบัตรแล้ว (Opened/Scanned) · ผู้ขอหรือสายสโตร์ ·
//      รายการที่หยิบจริง > 0 ต้องมีรูป ≥ 1 · 0 ≤ หยิบจริง ≤ จำนวนที่ขอ (ขาคืน: ≤ ยอดค้าง) · ลดต้องมีเหตุผล
//   2) รูปผูกกับรายการ (document_items.photo_url / photo_return_url) + ต่อท้ายรูปรวมของใบ (PDF/หน้าเดิม)
//   3) หยิบจริง/เหตุผลเก็บแยกจากจำนวนที่ขอ · activity_log ทุกรายการที่แก้ · หมายเหตุ "[Confirm]: ..."
//   4) GateLogs → 'Confirmed' (ยังไม่ตัดสต๊อก — Pi ส่ง closeGate เมื่อประตูปิดสนิท) ·
//      ประตู scan-flow = ปิดงานทันที (เดิม) · รอบที่ตู้ปิดไปแล้ว = ปิดงานทันที + error_logs (กันใบค้าง)
//   [2026-09-30] ขาออกที่หยิบจริง 0 ทุกรายการ = ยกเลิกใบทางอ้อมหลังแตะบัตร → ALARM "Zero pick:" ใน error_logs +
//      activity_log zero_pick (บันทึกได้ตามเดิม · Dashboard แยกเป็นหมวดนี้ ไม่นับเป็น "ของไม่พอ" — lib/pick_alerts.php)
// =========================================================================
function rpc_saveConfirmationData(PDO $pdo, ?array $user, array $args) {
    try {
        $payload  = (isset($args[0]) && is_array($args[0])) ? $args[0] : [];
        $docId    = trim((string)($payload['docId'] ?? ''));
        $notes    = trim((string)($payload['notes'] ?? ''));
        $legacyIm = (isset($payload['images']) && is_array($payload['images'])) ? $payload['images'] : [];
        $hasItems = isset($payload['items']) && is_array($payload['items']);

        if ($docId === '') {
            return ['success' => false, 'message' => 'Could not locate Document ID to update.'];
        }
        // RT (ขาคืน) — เฉพาะ BD (GAS ค้นเฉพาะชีต Borrow_Return)
        $isReturn = str_ends_with($docId, 'RT'); // /RT$/ ตัวใหญ่เท่านั้น
        $lookupId = $isReturn ? substr($docId, 0, -2) : $docId;
        // [2026-10-02] ใบย้าย Gate — ขานำเข้า (เลขใบ + G ปลายทาง) ชี้ใบ TG ฐาน · lib/gatemove.php
        $tgIn = false;
        if (!$isReturn && strncasecmp($docId, 'TG', 2) === 0) {
            require_once __DIR__ . '/gatemove.php';
            $tgp = gmParseNo($docId);
            if ($tgp && $tgp['leg'] === 'in') { $lookupId = $tgp['base']; $tgIn = true; }
        }
        $actor    = (($user['accountType'] ?? '') === 'subcontractor')
                  ? trim((string)($user['fullName'] ?? '')) : trim((string)($user['username'] ?? ''));

        // ---- 0) อ่านใบ + ตรวจสิทธิ์/สถานะ (ยังไม่เขียนอะไร) ----
        $d = $pdo->prepare(
            'SELECT id, doc_no, doc_type, project_id, gate_id, status, requester_username
               FROM documents WHERE doc_no = ?'
        );
        $d->execute([$lookupId]);
        $doc = $d->fetch();
        if (!$doc || ($isReturn && (string)$doc['doc_type'] !== Doc::TYPE_BD) || ($tgIn && (string)$doc['doc_type'] !== 'TG')) {
            return ['success' => false, 'message' => 'Could not locate Document ID to update.'];
        }
        $documentId = (int)$doc['id'];
        $projectId  = (int)$doc['project_id'];
        $docType    = (string)$doc['doc_type'];
        if ($docType === 'SC') {
            // [2026-09-29] ใบนับสต๊อกไม่ถ่ายรูปยืนยัน — บันทึกผลนับที่หน้า "ตรวจสอบประจำวัน" → แท็บนับสต๊อก (lib/stockcount.php)
            return ['success' => false, 'message' => 'ใบนับสต๊อก ' . $docId . ' บันทึกผลนับที่หน้า "ตรวจสอบประจำวัน" → แท็บ "นับสต๊อก"'];
        }
        $isR0       = (string)($user['roleLevel'] ?? '') === 'R0';
        if (!$isR0 && $projectId !== _gateProjectId($user)) {
            return ['success' => false, 'message' => 'ใบนี้ไม่ได้อยู่ในไซต์ของคุณ'];
        }
        $owner = trim((string)$doc['requester_username']);
        $isOwner = $owner !== '' && (eqUser($owner, (string)($user['username'] ?? '')) || eqUser($owner, (string)($user['fullName'] ?? '')));
        if (!$isOwner && !$isR0 && !s05UserIsStore($pdo, $user)) {
            return ['success' => false, 'message' => 'บันทึกยืนยันได้เฉพาะผู้ขอเบิกของใบนี้หรือสายสโตร์'];
        }
        if ($docType === 'TG') {   // [2026-10-02] ใบย้าย Gate: ถ่ายรูปยืนยันได้เฉพาะสายสโตร์ (ทั้ง 2 ขา)
            require_once __DIR__ . '/gatemove.php';
            if (!gmIsStoreUser($pdo, $user)) {
                return ['success' => false, 'message' => 'ใบย้าย Gate ถ่ายรูปยืนยันได้เฉพาะเจ้าหน้าที่สโตร์ (AST / ST1 / ST2 / SST)'];
            }
        }

        $glSel = $pdo->prepare(
            'SELECT gl.id, gl.status, gl.picking_id, gl.gate_id, g.gate_code
               FROM gate_logs gl LEFT JOIN gates g ON g.id = gl.gate_id
              WHERE gl.doc_no = ? LIMIT 1'
        );
        $glSel->execute([$docId]);
        $glRow = $glSel->fetch();
        if ($glRow) {
            $gs = strtolower(trim((string)$glRow['status']));
            if ($gs === 'awaiting') {
                return ['success' => false, 'message' => 'ใบ ' . $docId . ' ยังไม่ถูกแตะบัตรที่ประตู — สแกน QR และแตะบัตรที่ตู้ก่อน'];
            }
            if ($gs !== 'opened' && $gs !== 'scanned') {
                $why = $gs === 'confirmed' ? 'บันทึกยืนยันไปแล้ว แก้ไขไม่ได้'
                     : ($gs === 'closed' ? 'ปิดงานแล้ว' : ($gs === 'cancelled' ? 'ถูกยกเลิกแล้ว' : 'สถานะที่ประตู ' . $glRow['status']));
                return ['success' => false, 'message' => 'ใบ ' . $docId . ' ' . $why];
            }
        }

        $it = $pdo->prepare('SELECT id, material_id, mat_code, qty, qty_actual FROM document_items WHERE document_id = ? ORDER BY id');
        $it->execute([$documentId]);
        $items = $it->fetchAll();
        if (!$items) {
            return ['success' => false, 'message' => 'ใบ ' . $docId . ' ไม่มีรายการวัสดุ'];
        }
        // ⑦ (ฉบับแก้ 2026-09-29): ใบเบิก/ยืมขาออก แก้ "หยิบจริง" · ขาคืนของใบยืม แก้ "คืนจริง" · ใบ IN ยอดตามใบ
        $editable = $isReturn ? ($docType === Doc::TYPE_BD) : (in_array($docType, [Doc::TYPE_RD, Doc::TYPE_OD, Doc::TYPE_BD, 'TD', 'TG'], true) && !$tgIn);
        $retMap = [];
        if ($isReturn) {
            foreach (borrowDocItems($pdo, $documentId) as $bi) { $retMap[$bi['id']] = $bi; }
        }

        // ข้อมูลที่หน้าจอส่งมา → ต่อรายการ (itemId ก่อน · สำรองด้วยรหัสวัสดุ)
        $inputs = [];
        if ($hasItems) {
            $byCode = [];
            foreach ($payload['items'] as $x) {
                if (!is_array($x)) { continue; }
                $rec = [
                    'actual' => array_key_exists('actualQty', $x) ? $x['actualQty'] : null,
                    'reason' => (string)($x['reason'] ?? ''),
                    'images' => (isset($x['images']) && is_array($x['images'])) ? $x['images'] : [],
                ];
                $iid = (int)($x['itemId'] ?? 0);
                if ($iid > 0) { $inputs[$iid] = $rec; }
                elseif (trim((string)($x['matCode'] ?? '')) !== '') { $byCode[strtoupper(trim((string)$x['matCode']))] = $rec; }
            }
            foreach ($items as $row) {
                $k = strtoupper(trim((string)$row['mat_code']));
                if (!isset($inputs[(int)$row['id']]) && isset($byCode[$k])) { $inputs[(int)$row['id']] = $byCode[$k]; }
            }
        } elseif (count($items) === 1) {
            $inputs[(int)$items[0]['id']] = ['actual' => null, 'reason' => '', 'images' => $legacyIm];
        } else {
            return ['success' => false, 'message' => 'หน้าจอเป็นรุ่นเก่า — กรุณารีเฟรชหน้า (ต้องถ่ายรูปยืนยันรายรายการ อย่างน้อยรายการละ 1 รูป)'];
        }

        // ---- 1) ตรวจรายรายการ ----
        $gateLabel = $glRow && trim((string)$glRow['gate_code']) !== '' ? trim((string)$glRow['gate_code']) : _stockGateCodeFromDocNo($docId);
        $errors = [];
        $plan   = [];
        $eps    = 0.0005;
        $what   = $isReturn ? 'จำนวนคืนจริง' : 'จำนวนหยิบจริง';
        foreach ($items as $row) {
            $iid = (int)$row['id'];
            $mc  = trim((string)$row['mat_code']);
            if ($isReturn) {
                // ต้องคืน = ยอดค้าง (ยืมจริง − คืนแล้วรอบก่อน − ตีชำรุด/สูญหายแล้ว) — [2026-10-08] ทยอยคืนหลายรอบ
                $bi  = $retMap[$iid] ?? null;
                $req = $bi ? (float)$bi['outstanding'] : (float)$row['qty'];
                if ($req <= $eps) {
                    // ตีเป็นชำรุด/สูญหายหมดแล้ว — ไม่มีอะไรต้องคืน ไม่ต้องถ่ายรูป
                    $plan[] = ['id' => $iid, 'matCode' => $mc, 'req' => 0.0, 'actual' => 0.0, 'reason' => null, 'images' => [], 'urls' => []];
                    continue;
                }
            } else {
                $req = $tgIn ? s05EffQty($row) : (float)$row['qty'];   // [2026-10-02] ขานำเข้าใบย้าย Gate = เท่าที่เบิกออก
            }
            $in  = $inputs[$iid] ?? ['actual' => null, 'reason' => '', 'images' => []];
            $actual = $req;
            if ($editable && $in['actual'] !== null && trim((string)$in['actual']) !== '') {
                if (!is_numeric($in['actual'])) { $errors[] = $mc . ': ' . $what . 'ต้องเป็นตัวเลข'; continue; }
                $actual = round((float)$in['actual'], 3);
            }
            if ($actual < 0) {
                $errors[] = $mc . ': ' . $what . 'ต่ำสุด 0';
                continue;
            }
            if ($editable && $actual > $req + S05_MAX_OVER_PICK + $eps) {
                // เอกสาร 05 ⑦ ฉบับแก้: ห้ามเพิ่มเกินจำนวนที่ขอ — ต้องการมากกว่าที่ขอให้ออกใบเบิกใหม่
                $errors[] = $isReturn
                    ? $mc . ': คืนได้ไม่เกินที่ยืมอยู่ (ต้องคืน ' . s05Num($req) . ')'
                    : $mc . ': หยิบจริงได้ไม่เกินจำนวนที่ขอ (ขอ ' . s05Num($req) . ') — ต้องการมากกว่าที่ขอให้ออกใบเบิกใหม่';
                continue;
            }
            $reason = trim(mb_substr((string)$in['reason'], 0, 255, 'UTF-8'));
            if ($actual < $req - $eps && $reason === '') {
                $errors[] = $isReturn
                    ? $mc . ': คืนน้อยกว่าที่ค้าง (' . s05Num($actual) . ' จาก ' . s05Num($req) . ') ต้องใส่เหตุผล — ส่วนที่เหลือยังค้างยืม (ทยอยคืนรอบถัดไป หรือสายสโตร์ตีชำรุด/สูญหาย)'
                    : $mc . ': หยิบน้อยกว่าที่ขอ (' . s05Num($actual) . ' จาก ' . s05Num($req) . ') ต้องเลือกเหตุผล (' . implode(' / ', S05_PICK_REASONS) . ')';
                continue;
            }
            // [2026-10-02 · GP-05] ขาออก: เหตุผลเลือกจากรายการเท่านั้น (Dashboard นับ "ของไม่พอ" จากเหตุผลนี้)
            if (!$isReturn && $editable && $actual < $req - $eps && !in_array($reason, S05_PICK_REASONS, true)) {
                $errors[] = $mc . ': เหตุผลต้องเลือกจากรายการ — ' . implode(' / ', S05_PICK_REASONS);
                continue;
            }
            // หยิบครบตามที่ขอ = ไม่มีเหตุผล (กันเหตุผลค้างจากตอนลดแล้วเพิ่มกลับ)
            if ($actual >= $req - $eps) { $reason = ''; }
            $imgs = [];
            foreach ($in['images'] as $b64) {
                if (is_string($b64) && $b64 !== '') { $imgs[] = $b64; }
            }
            if ($actual > $eps && !$imgs) {
                $errors[] = $mc . ': ต้องถ่ายรูปอย่างน้อย 1 รูป';
                continue;
            }
            $plan[] = ['id' => $iid, 'matCode' => $mc, 'req' => $req, 'actual' => $actual,
                       'reason' => $actual < $req - $eps ? $reason : ($reason !== '' ? $reason : null), 'images' => $imgs, 'urls' => []];
        }
        if ($errors) {
            return ['success' => false, 'message' => "บันทึกไม่ได้ — แก้ไขรายการต่อไปนี้:\n• " . implode("\n• ", $errors), 'errors' => $errors];
        }

        // ---- 2) เซฟรูปรายรายการ (ไฟล์ — นอกทรานแซกชัน) ----
        $saved = [];
        foreach ($plan as $k => $p) {
            $n = 0;
            foreach ($p['images'] as $b64) {
                $n++;
                $rel = savePhotoDataUri($b64, $docId . '_' . $p['id'] . '_' . $n);
                if ($rel !== null) { $plan[$k]['urls'][] = $rel; $saved[] = $rel; }
            }
            if ($p['actual'] > $eps && !$plan[$k]['urls']) {
                $errors[] = $p['matCode'] . ': ไฟล์รูปใช้ไม่ได้ (รองรับ JPG / PNG / WEBP) — ถ่ายใหม่อีกครั้ง';
            }
        }
        if ($errors) {
            foreach ($saved as $rel) { @unlink((defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__) . '/') . $rel); }
            return ['success' => false, 'message' => "บันทึกไม่ได้:\n• " . implode("\n• ", $errors), 'errors' => $errors];
        }

        // ---- 3) เขียนทั้งหมดในทรานแซกชันเดียว ----
        $changed = 0;
        $pdo->beginTransaction();
        try {
            $dl = $pdo->prepare('SELECT id, notice, photo_url, photo_return_url FROM documents WHERE id = ? FOR UPDATE');
            $dl->execute([$documentId]);
            $docLocked = $dl->fetch();
            if ($glRow) {
                // กันกดซ้ำ/สองเครื่องพร้อมกัน — สถานะต้องยังเป็น Opened/Scanned
                $gq = $pdo->prepare('SELECT status FROM gate_logs WHERE id = ? FOR UPDATE');
                $gq->execute([(int)$glRow['id']]);
                $cur = strtolower(trim((string)$gq->fetchColumn()));
                if ($cur !== 'opened' && $cur !== 'scanned') {
                    $pdo->rollBack();
                    foreach ($saved as $rel) { @unlink((defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__) . '/') . $rel); }
                    return ['success' => false, 'message' => 'ใบ ' . $docId . ' ถูกบันทึกยืนยันไปแล้ว'];
                }
            }

            $itemPhotoCol = ($isReturn || $tgIn) ? 'photo_return_url' : 'photo_url';   // ขานำเข้าใบย้าย Gate → รูปขาที่ 2
            $curPhotoSel  = $pdo->prepare("SELECT $itemPhotoCol FROM document_items WHERE id = ? FOR UPDATE");
            $updOut = $pdo->prepare("UPDATE document_items SET $itemPhotoCol = ?, qty_actual = ?, actual_reason = ? WHERE id = ?");
            $updRet = $pdo->prepare("UPDATE document_items SET $itemPhotoCol = ?, qty_returned = ?, return_reason = ? WHERE id = ?");
            // [2026-10-08] ขาคืนของใบยืม: จด "คืนรอบนี้" แยกจากคืนรวม — ปิดประตูแล้ว finalize สะสมลง qty_returned (ทยอยคืนหลายรอบ)
            $updRound = $pdo->prepare("UPDATE document_items SET $itemPhotoCol = ?, qty_return_round = ?, return_reason = ? WHERE id = ?");
            $allUrls = [];
            foreach ($plan as $p) {
                $curPhotoSel->execute([$p['id']]);
                $photos = photoUrlsAppend((string)$curPhotoSel->fetchColumn(), $p['urls']);
                if ($isReturn || $tgIn) {
                    // ขาคืน (③ ขั้น 4 · ⑦): จดจำนวนคืนจริงแยกจากที่ยืม · [2026-10-02] ขานำเข้าใบย้าย Gate: qty_returned = นำเข้า — ปิดประตูแล้วคืนยอดเข้า G เดิมเท่านี้ (finalizeGateDoc)
                    // ส่วนที่ไม่ได้คืนค้างเป็น "รอตีชำรุด/สูญหาย" (lib/borrow.php)
                    if ($isReturn) {
                        $updRound->execute([$photos, $p['actual'], $p['reason'], $p['id']]);
                    } else {
                        $updRet->execute([$photos, $p['actual'], $p['reason'], $p['id']]);
                    }
                    if (abs($p['actual'] - $p['req']) > $eps) {
                        $changed++;
                        s05ActivityLog($pdo, 'document', $lookupId, $actor, 'return_actual',
                            s05Num($p['req']),
                            json_encode(['itemId' => $p['id'], 'matCode' => $p['matCode'], 'due' => $p['req'],
                                         'returned' => $p['actual'], 'diff' => round($p['actual'] - $p['req'], 3),
                                         'reason' => $p['reason'], 'gate' => $gateLabel], JSON_UNESCAPED_UNICODE));
                    }
                } else {
                    $updOut->execute([$photos, $p['actual'], $p['reason'], $p['id']]);
                    if (abs($p['actual'] - $p['req']) > $eps) {
                        $changed++;
                        s05ActivityLog($pdo, 'document', $lookupId, $actor, 'pick_actual',
                            s05Num($p['req']),
                            json_encode(['itemId' => $p['id'], 'matCode' => $p['matCode'], 'req' => $p['req'],
                                         'actual' => $p['actual'], 'diff' => round($p['actual'] - $p['req'], 3),
                                         'reason' => $p['reason'], 'gate' => $gateLabel], JSON_UNESCAPED_UNICODE));
                    }
                }
                foreach ($p['urls'] as $u) { $allUrls[] = $u; }
            }

            // หมายเหตุ — ต่อท้ายทุกแถวรายการ (grain เดิมของชีต) + header
            if ($notes !== '') {
                $first = '[Confirm]: ' . $notes;
                $more  = "\n" . $first;
                $pdo->prepare(
                    "UPDATE document_items
                        SET notice = CASE WHEN notice IS NULL OR notice = '' THEN ? ELSE CONCAT(notice, ?) END
                      WHERE document_id = ?"
                )->execute([$first, $more, $documentId]);
                $existingNote = trim((string)$docLocked['notice']) !== '' ? (string)$docLocked['notice'] : '';
                $pdo->prepare('UPDATE documents SET notice = ? WHERE id = ?')
                    ->execute([$existingNote !== '' ? $existingNote . $more : $first, $documentId]);
            }

            // รูปรวมของใบ (หน้า/รายงานเดิมอ่านคอลัมน์นี้) — ขาคืน RT → photo_return_url
            $docPhotoCol = ($isReturn || $tgIn) ? 'photo_return_url' : 'photo_url';
            $pdo->prepare("UPDATE documents SET $docPhotoCol = ? WHERE id = ?")
                ->execute([photoUrlsAppend((string)$docLocked[$docPhotoCol], $allUrls), $documentId]);

            // GateLogs → 'Confirmed' (แถวของ docId เต็ม รวม RT ถ้ามี) — ยังไม่ตัดสต๊อก
            $pdo->prepare('UPDATE gate_logs SET status = ? WHERE doc_no = ?')->execute([Gate::ST_CONFIRMED, $docId]);
            s05ActivityLog($pdo, 'document', $docId, $actor, 'gate_confirm', null,
                json_encode(['items' => count($plan), 'photos' => count($allUrls), 'changed' => $changed,
                             'picking' => $glRow ? (string)$glRow['picking_id'] : ''], JSON_UNESCAPED_UNICODE));
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }

        // ---- 3b) ALARM: ลดทุกรายการเป็น 0 (2026-09-30 · ช่องโหว่ GP-05) ----
        // ใบที่แตะบัตรแล้วยกเลิกไม่ได้ (กติกา 2) — หยิบจริง 0 ทุกรายการ = ยกเลิกใบทางอ้อม และถ้าเหตุผลเขียนว่าของไม่พอ
        // Dashboard จะขึ้น "ของไม่พอ" ปลอม → บันทึกเป็น ALARM แยกประเภท (ยังบันทึกได้ตามเดิม ไม่ตัดสต๊อกรายการที่เป็น 0)
        $zeroPick = false;
        if (!$isReturn && $editable && $plan) {
            $reqSum = 0.0;
            $zeroPick = true;
            foreach ($plan as $p) {
                $reqSum += (float)$p['req'];
                if ($p['actual'] > $eps) { $zeroPick = false; break; }
            }
            if ($reqSum <= $eps) { $zeroPick = false; }
        }
        if ($zeroPick) {
            $why = [];
            foreach ($plan as $p) {
                $rs = trim((string)($p['reason'] ?? ''));
                if ($rs !== '' && !in_array($rs, $why, true)) { $why[] = $rs; }
            }
            $pk0 = $glRow ? trim((string)$glRow['picking_id']) : '';
            $holder = '';
            $cq = $pdo->prepare("SELECT card_id FROM gate_logs WHERE doc_no = ? AND card_id IS NOT NULL AND card_id <> '' LIMIT 1");
            $cq->execute([$docId]);
            $cid = trim((string)$cq->fetchColumn());
            if ($cid !== '') { $holder = s05CardHolders($pdo, [$cid])[$cid] ?? ('CardID: ' . $cid); }
            _stockErrorLog($pdo, $projectId, $gateLabel !== '' ? $gateLabel : null,
                'Zero pick: ' . $docId . ' หยิบจริง 0 ทุกรายการ (' . count($plan) . ' รายการ) หลังแตะบัตร = ยกเลิกใบทางอ้อม'
                . ($pk0 !== '' ? ' PickingID=' . $pk0 : '')
                . ' | by=' . ($actor !== '' ? $actor : '-')
                . ($holder !== '' ? ' | cardholder=' . $holder : '')
                . ($why ? ' | reasons=' . mb_substr(implode(' / ', $why), 0, 300, 'UTF-8') : ''));
            s05ActivityLog($pdo, 'document', $docId, $actor, 'zero_pick', null,
                json_encode(['items' => count($plan), 'picking' => $pk0, 'gate' => $gateLabel, 'cardholder' => $holder,
                             'reasons' => $why], JSON_UNESCAPED_UNICODE));
        }

        // ---- 4) ปิดงาน ----
        // ประตู scan-flow → ปิดงาน (สถานะเอกสาร + ตัดสต๊อก) ทันที (mirror: finalizeGate = docGateId || _gateFromDocId_)
        // ประตูมีตู้ → รอ closeGate · ยกเว้นรอบที่ตู้ปิดไปแล้ว (ไม่มีใครส่งปิดให้ใบนี้อีก) → ปิดงานทันที + บันทึกไว้
        $docGateCode = $glRow ? trim((string)$glRow['gate_code']) : '';
        $finalizeGate = $docGateCode !== '' ? $docGateCode : _stockGateCodeFromDocNo($docId);
        $pickingId = $glRow ? trim((string)$glRow['picking_id']) : '';
        $scanFlow = _gateUsesScanFlow($pdo, $finalizeGate, $projectId);
        $late = false;
        if (!$scanFlow && $pickingId !== '') {
            $rs = _gateRoundsSummary($pdo, [$pickingId], $projectId, true);
            $late = !empty($rs[$pickingId]['closed']);
        }
        $finalized = false;
        if ($scanFlow || $late) {
            try {
                if ($late) {
                    $pdo->prepare('UPDATE gate_logs SET status = ? WHERE doc_no = ?')->execute([Gate::ST_CLOSED, $docId]);
                    _stockErrorLog($pdo, $projectId, $finalizeGate !== '' ? $finalizeGate : null,
                        'Late confirm: ' . $docId . ' ยืนยันหลังตู้ปิดรอบ PickingID=' . $pickingId . ' แล้ว — ปิดงาน/ตัดสต๊อกทันที'
                        . ($actor !== '' ? ' | by=' . $actor : ''));
                }
                finalizeGateDoc($pdo, $docId);
                $finalized = true;
            } catch (Throwable $e) {
                // mirror GAS: finalize พังไม่ทำให้การเซฟรูปล้ม — log ไว้พอ
                error_log('confirm finalize failed for ' . $docId . ': ' . $e->getMessage());
            }
        }

        $round = null;
        if ($pickingId !== '') {
            $rs = _gateRoundsSummary($pdo, [$pickingId], $projectId, true);
            $round = $rs[$pickingId] ?? null;
        }
        return ['success' => true, 'message' => 'อัพโหลดและบันทึกข้อมูลเรียบร้อยแล้ว',
                'changed' => $changed, 'finalized' => $finalized, 'scanFlow' => $scanFlow, 'round' => $round,
                'zeroPick' => $zeroPick];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('saveConfirmationData: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
