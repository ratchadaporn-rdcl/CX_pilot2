<?php
/**
 * CONNEXT — lib/approval.php : คิวอนุมัติ + อนุมัติ/ปฏิเสธ + ยกเลิกเอกสาร
 *
 * Port ตรงจาก GAS Code.js:
 *   getApprovalRequests(roleLevel, siteCode, username)
 *   updateApprovalStatus(docId, newStatus, docType, username, roleLevel)
 *   cancelRequisition(docId, docType, username)
 *
 * FIDELITY: โครงผลลัพธ์/ข้อความ/สถานะตรงกับ GAS เดิม — client (Index.html)
 * เดิมเรียกผ่าน shim โดยไม่แก้ฝั่งนั้น
 * ตัวตน (role/site/username) เป็น server-authoritative จาก session — args จาก
 * client รับตามตำแหน่งเดิมเพื่อให้ signature ตรง แต่ใช้เป็น filter เท่านั้น
 *
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/borrow.php';   // [Scenario 05 ③ · 2026-09-29] กำหนดวันคืน + ใบยืมเกินกำหนดของผู้ยืม
require_once __DIR__ . '/doc_ext.php';  // [2026-09-29] TD เบิกโอนย้ายข้ามไซต์ (PM ต้นทางอนุมัติ) · SC ใบนับสต๊อก
require_once __DIR__ . '/doc_revise.php';   // [2026-10-06] ตีกลับให้แก้ยอด · ยอดพร้อมเบิกบนการ์ดอนุมัติ

// =========================================================================
// helpers ภายใน
// =========================================================================

/** ดึงเลข role จากสตริง 'R4' → 4 (mirror roleLevel.match(/\d+/) ของ GAS) */
function _apRoleNum($roleLevel): int {
    if (preg_match('/\d+/', (string)$roleLevel, $m)) {
        return (int)$m[0];
    }
    return 0;
}

/**
 * ระดับ role ของ "ผู้ใช้ใน session" — mirror getUserRoleLevel_ ของ GAS แต่
 * server-authoritative: อิงบัญชีใน session แล้ว re-query DB (ไม่เชื่อ args)
 *   user          → users JOIN roles ตาม username (case-insensitive ผ่าน collation)
 *   subcontractor → 1 (พฤติกรรมเดิม: SubName ใน Subcontracts = R1)
 *   หาไม่เจอ      → 99 (พฤติกรรมเดิมของ getUserRoleLevel_ ตอน miss)
 */
function _apServerRoleLevel(PDO $pdo, array $user): int {
    if (($user['accountType'] ?? '') === 'subcontractor') {
        return 1;
    }
    $username = trim((string)($user['username'] ?? ''));
    if ($username === '') {
        return 99;
    }
    try {
        $st = $pdo->prepare(
            'SELECT r.level FROM users u JOIN roles r ON r.id = u.role_id
              WHERE u.username = ? LIMIT 1'
        );
        $st->execute([$username]);
        $lvl = $st->fetchColumn();
        if ($lvl !== false) {
            return (int)$lvl;
        }
    } catch (Throwable $e) {
        error_log('_apServerRoleLevel: ' . $e->getMessage());
    }
    return 99;
}

/**
 * ชื่อ effective ของบัญชีใน session — เอกสารของ SUB บันทึก UserName เป็น
 * SubName (fullName) ไม่ใช่ SubID (mirror getEffectiveUserName ฝั่ง client)
 */
function _apEffectiveName(array $user): string {
    if (($user['accountType'] ?? '') === 'subcontractor') {
        return trim((string)($user['fullName'] ?? ''));
    }
    return trim((string)($user['username'] ?? ''));
}

/** map docType → กลุ่มเอกสาร (mirror การเลือกชีตของ GAS: ไม่รู้จัก = RD) */
function _apDocType($docType): string {
    $t = strtoupper(trim((string)$docType));
    // + TD (เบิกโอนย้ายข้ามไซต์) · SC (ใบนับสต๊อก) — 2026-09-29 (ยกเลิกใบ/อนุมัติใช้ชนิดจริง)
    if ($t === Doc::TYPE_BD || $t === Doc::TYPE_OD || $t === Doc::TYPE_IN || $t === DOC_TYPE_TD || $t === DOC_TYPE_SC) {
        return $t;
    }
    if ($t === 'TG') {   // [2026-10-02] ใบย้าย Gate (lib/gatemove.php)
        return $t;
    }
    return Doc::TYPE_RD;
}

/** แปลง float → สตริงแบบเลข JS (5.0→'5', 2.5→'2.5') สำหรับข้อความสต๊อกไม่พอ */
function _apNumStr(float $v): string {
    if ($v == floor($v) && abs($v) < 1e15) {
        return (string)(int)$v;
    }
    $s = rtrim(number_format($v, 3, '.', ''), '0');
    return rtrim($s, '.');
}

/** fmtDate ของ GAS: 'DD/MM/YYYY HH:MM' (เลขล้วน pad 2 หลัก ไม่มีวินาที) */
function _apFmtDate(?string $dbDatetime): string {
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

/** epoch ms ของ DATETIME ในโซนไทย (client ใช้เรียงเท่านั้น) */
function _apEpochMs(?string $dbDatetime): int {
    if ($dbDatetime === null || trim($dbDatetime) === '') {
        return 0;
    }
    try {
        $dt = new DateTime($dbDatetime);
        return $dt->getTimestamp() * 1000;
    } catch (Throwable $e) {
        return 0;
    }
}

/** DECIMAL → เลขแบบ GAS: จำนวนเต็มคืน int (JSON = 5 ไม่ใช่ 5.0), เศษคืน float */
function _apQtyNum($v) {
    $f = (float)$v;
    if ($f == floor($f) && abs($f) < PHP_INT_MAX) {
        return (int)$f;
    }
    return $f;
}

/** เขียน activity_log (audit กลาง) — ห้ามทำให้ธุรกรรมหลักล้ม */
function _apActivityLog(PDO $pdo, string $entityType, string $entityId, ?string $userName, string $action, ?string $oldValue, ?string $newValue): void {
    try {
        $st = $pdo->prepare(
            'INSERT INTO activity_log (entity_type, entity_id, user_name, action, old_value, new_value)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$entityType, $entityId, $userName, $action, $oldValue, $newValue]);
    } catch (Throwable $e) {
        error_log('_apActivityLog: ' . $e->getMessage());
    }
}

/**
 * สร้างแถว gate_logs สถานะ Awaiting ให้เอกสาร (idempotent — มีแถว doc_no
 * นี้แล้วไม่ซ้ำ; mirror _ensureGateLogAwaiting_ ของ GAS)
 */
function _apEnsureGateLogAwaiting(PDO $pdo, array $doc): void {
    $docNo = trim((string)$doc['doc_no']);
    if ($docNo === '') {
        return;
    }
    $sel = $pdo->prepare('SELECT id FROM gate_logs WHERE doc_no = ? LIMIT 1');
    $sel->execute([$docNo]);
    if ($sel->fetchColumn() !== false) {
        return; // มีแถวแล้ว (สถานะใดก็ตาม) — เหมือน GAS ที่เช็กแค่ DocID
    }
    $ins = $pdo->prepare(
        "INSERT INTO gate_logs (project_id, doc_no, document_id, leg, gate_id, status)
         VALUES (?, ?, ?, 'out', ?, ?)"
    );
    $ins->execute([
        (int)$doc['project_id'],
        $docNo,
        (int)$doc['id'],
        $doc['gate_id'] !== null ? (int)$doc['gate_id'] : null,
        Gate::ST_AWAITING,
    ]);
}

// =========================================================================
// getApprovalRequests(roleLevel, siteCode, username)
// =========================================================================

/**
 * คิวเอกสารรออนุมัติ (RD/BD/IN — OD auto-approve เสมอจึงไม่ขึ้นคิว)
 * visibility: R0 เห็นทั้งหมด (read-only) · R4+ เห็นทั้งไซต์ ·
 * R1–R3/SUB เห็นเฉพาะที่ตัวเองส่ง — คีย์ผลลัพธ์ตรง renderer ฝั่ง client:
 * id, type, dateStr, timestamp, subId, subName, notice, usageArea, reqName,
 * items[{matCode,matName,qty,catId}], hasC01, approver, requiredRole, canAct
 * [2026-09-29] + TD เบิกโอนย้ายข้ามไซต์: canAct เฉพาะ PM ของไซต์ต้นทาง · เพิ่ม destSite/destSiteName/requiredText
 * [Scenario 05 ③ · 2026-09-29] ใบยืม (BD) เพิ่ม dueDate · borrowerOverdue (จำนวนใบยืมเกินกำหนดที่ผู้ยืมค้าง) ·
 * borrowerOverdueDocs[{docNo,dueDate,days}] (สูงสุด 5)
 */
function rpc_getApprovalRequests(PDO $pdo, ?array $user, array $args) {
    try {
        if (!$user) {
            return ['success' => false, 'message' => 'session expired'];
        }
        // args ตามตำแหน่งเดิม (roleLevel, siteCode, username) — role/ตัวตนใช้
        // จาก session เสมอ; siteCode arg ใช้เป็น filter ได้เฉพาะ R0 (สลับไซต์ได้)
        $argSite = trim((string)($args[1] ?? ''));

        $roleNum  = _apRoleNum($user['roleLevel'] ?? '');
        $isR0     = ($roleNum === 0);
        $me       = mb_strtolower(_apEffectiveName($user), 'UTF-8');
        $selfOnly = (!$isR0 && $roleNum <= 3);

        // ---- ดึงเอกสารสถานะรออนุมัติ (เทียบ substring เหมือน GAS) ----
        $sql = "SELECT d.id, d.doc_no, d.doc_type, d.doc_ts, d.receiver_name,
                       d.usage_area, d.notice, d.rs_no, d.requester_username,
                       d.approver_username, d.project_id, d.due_date, d.gate_id, d.status,
                       s.sub_code AS recv_sub_code, s.name AS recv_sub_name,
                       dp.code AS dest_code, dp.name AS dest_name
                  FROM documents d
                  JOIN projects p ON p.id = d.project_id
                  LEFT JOIN subcontractors s ON s.id = d.receiver_sub_id
                  LEFT JOIN projects dp ON dp.id = d.dest_project_id
                 WHERE d.doc_type IN ('RD','BD','IN','TD','TG')
                   AND (d.status LIKE '%awaiting%' OR d.status LIKE '%รออนุมัติ%')";
        $params = [];
        if ($isR0) {
            // GAS: siteCode ว่าง = ไม่กรอง (เห็นทุกไซต์) — R0 เท่านั้นที่สลับไซต์ได้
            if ($argSite !== '') {
                $sql .= ' AND p.code = ?';
                $params[] = $argSite;
            }
        } else {
            // non-R0: บังคับไซต์จาก session (server-authoritative)
            $sql .= ' AND d.project_id = ?';
            $params[] = (int)($user['projectId'] ?? 0);
        }
        $sql .= ' ORDER BY d.doc_ts ASC, d.id ASC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $docs = $st->fetchAll();
        if (!$docs) {
            return ['success' => true, 'data' => []];
        }

        // ---- รายการวัสดุของทุกเอกสาร (ชื่อ/CatID จาก master ตาม matMap เดิม) ----
        $docIds = array_map(function ($d) { return (int)$d['id']; }, $docs);
        $ph = implode(',', array_fill(0, count($docIds), '?'));
        $it = $pdo->prepare(
            "SELECT di.document_id, di.mat_code, di.qty,
                    m.name AS mat_name, m.cat_id, m.id AS master_id, di.rs_no
               FROM document_items di
               LEFT JOIN materials m ON m.mat_code = di.mat_code
              WHERE di.document_id IN ($ph)
              ORDER BY di.id ASC"
        );
        $it->execute($docIds);
        $itemsByDoc = [];
        while ($row = $it->fetch()) {
            $itemsByDoc[(int)$row['document_id']][] = $row;
        }

        // กดอนุมัติได้เมื่อ role ถึงเกณฑ์ และเอกสารไม่ได้ assign ให้คนอื่น
        $canActOn = function (string $assignedApprover, int $requiredRole) use ($isR0, $roleNum, $me): bool {
            if ($isR0) { return false; }
            if ($roleNum < $requiredRole) { return false; }
            $a = mb_strtolower(trim($assignedApprover), 'UTF-8');
            return $a === '' || $a === $me;
        };

        // [2026-09-29] TD เบิกโอนย้ายข้ามไซต์ — PM (role PM) ของไซต์ต้นทางเท่านั้นที่กดได้ (ไม่ใช้เกณฑ์ระดับ R)
        $meUsername = trim((string)($user['username'] ?? ''));
        $meIsSub    = (($user['accountType'] ?? '') === 'subcontractor');
        $pmOfSite   = [];   // projectId → bool (ผู้ใช้ใน session เป็น PM ของไซต์นั้นหรือไม่)
        $canActTd = function (string $assignedApprover, int $projectId) use ($pdo, $isR0, $meIsSub, $me, $meUsername, &$pmOfSite): bool {
            if ($isR0 || $meIsSub) { return false; }
            if (!array_key_exists($projectId, $pmOfSite)) {
                $pmOfSite[$projectId] = docExtIsProjectPm($pdo, $meUsername, $projectId);
            }
            if (!$pmOfSite[$projectId]) { return false; }
            $a = mb_strtolower(trim($assignedApprover), 'UTF-8');
            return $a === '' || $a === $me;
        };

        $results = [];
        $revInfoDocs = [];    // [2026-10-06] ใบที่ตีกลับให้แก้ — เติมเหตุผลล่าสุดหลังวน
        $overdueMap = null;   // ใบยืมเกินกำหนดต่อผู้ยืม — โหลดเมื่อมีใบยืมในคิว
        foreach ($docs as $d) {
            $docType = (string)$d['doc_type'];
            $rows    = $itemsByDoc[(int)$d['id']] ?? [];

            $hasC01 = false;
            $allNAR = true;
            $items  = [];
            foreach ($rows as $r) {
                $matCode = (string)$r['mat_code'];
                // GAS: matMap[code] มีเฉพาะวัสดุใน MaterialsMain; ไม่พบ → {name: code, catId: ''}
                $inMaster = ($r['master_id'] !== null);
                $matName  = $inMaster ? (string)$r['mat_name'] : $matCode;
                $catId    = $inMaster ? (string)$r['cat_id'] : '';
                $catUpper = strtoupper(trim($catId));
                if ($catUpper === 'C01') { $hasC01 = true; }
                if ($catUpper !== 'NAR') { $allNAR = false; }
                $items[] = [
                    'matCode' => $matCode,
                    'matName' => $matName,
                    'qty'     => _apQtyNum($r['qty']),
                    'catId'   => $catId,
                ];
            }

            $isTd = ($docType === DOC_TYPE_TD);
            if ($allNAR && !$isTd) {
                continue; // NAR ล้วน → ควร auto-approve ไปแล้ว — ข้าม (พฤติกรรมเดิม) · TD ต้องผ่าน PM เสมอ
            }
            // [PHP port 2026-09-25 · มติ 52] RD ต้องผู้อนุมัติ R6 ขึ้นไปทุกใบ (ไม่ใช่แค่ C01) · BD/IN คงเดิม
            // TD: PM ของไซต์ต้นทาง (role PM = R11) — requiredRole ใช้แสดงผลเท่านั้น สิทธิ์จริงดู $canActTd
            $requiredRole = $isTd ? 11 : (($docType === Doc::TYPE_RD) ? 6 : ($hasC01 ? 6 : 4));
            if ($docType === 'TG') {
                // [2026-10-02] ใบย้าย Gate: เกณฑ์ตามหมวด IC เหมือนใบยืม (มี C01 → R6 · C02 → R4) — lib/gatemove.php
                require_once __DIR__ . '/gatemove.php';
                $requiredRole = max(1, gmDocRequiredLevel($pdo, (int)$d['id']));
            }

            $reqName = trim((string)$d['requester_username']);
            if ($selfOnly && mb_strtolower($reqName, 'UTF-8') !== $me) {
                continue;
            }

            // Receiver → subId/subName (Receiver เดิมเก็บ SubID; schema ใหม่
            // resolve เป็น receiver_sub_id แล้ว — DC:/free text คงค่า verbatim)
            if ($docType === Doc::TYPE_IN) {
                $subId   = '';
                $rs      = trim((string)$d['rs_no']);
                if ($rs === '' && $rows) {
                    $rs = trim((string)$rows[0]['rs_no']);
                }
                $subName = $rs !== '' ? $rs : '-';
            } elseif ($isTd) {
                // ผู้รับของใบโอนย้าย = ไซต์ปลายทาง
                $subId   = (string)($d['dest_code'] ?? '');
                $destNm  = trim((string)($d['dest_name'] ?? ''));
                $subName = $subId !== '' ? 'โอนไป ' . $subId . ($destNm !== '' ? ' · ' . $destNm : '') : trim((string)$d['receiver_name']);
            } elseif ($d['recv_sub_code'] !== null) {
                $subId    = fmtSubId($d['recv_sub_code']);
                $trimName = trim((string)$d['recv_sub_name']);
                $subName  = $trimName !== '' ? $trimName : $subId;
            } else {
                $subId   = fmtSubId((string)$d['receiver_name']);
                $subName = $subId; // subMap miss → GAS แสดง subId (ค่า Receiver เดิม)
            }

            $notice = (string)($d['notice'] ?? '');
            if ($docType !== Doc::TYPE_RD) {
                $notice = trim($notice); // GAS: BD/IN trim, RD ไม่ trim
            }
            $usageArea = ($docType === Doc::TYPE_IN) ? '' : trim((string)($d['usage_area'] ?? ''));
            $approver  = trim((string)($d['approver_username'] ?? ''));

            $one = [
                'id'           => (string)$d['doc_no'],
                'type'         => $docType,
                'dateStr'      => _apFmtDate($d['doc_ts']),
                'timestamp'    => _apEpochMs($d['doc_ts']),
                'subId'        => $subId,
                'subName'      => $subName,
                'notice'       => $notice,
                'usageArea'    => $usageArea,
                'reqName'      => $reqName,
                'items'        => $items,
                'hasC01'       => $hasC01,
                'approver'     => $approver,
                'requiredRole' => $requiredRole,
                'canAct'       => $isTd ? $canActTd($approver, (int)$d['project_id']) : $canActOn($approver, $requiredRole),
            ];
            // [2026-10-06] ยอดพร้อมเบิกต่อรายการ ณ ประตูของใบ (on_hand − ยอดที่อนุมัติจองแล้ว) — ของไม่พอ = อนุมัติไม่ได้ ตีกลับให้แก้
            if (in_array($docType, docReviseStockTypes(), true)) {
                $needAgg = [];
                foreach ($items as $x) { $needAgg[$x['matCode']] = ($needAgg[$x['matCode']] ?? 0.0) + (float)$x['qty']; }
                $chk = docReviseStockCheck($pdo, (int)$d['project_id'], $d['gate_id'] !== null ? (int)$d['gate_id'] : null, $needAgg);
                $shortList = [];
                foreach ($one['items'] as $ix => $x) {
                    $s = $chk[$x['matCode']] ?? null;
                    if ($s === null) { continue; }
                    $one['items'][$ix]['onHand']  = round($s['onHand'], 3);
                    $one['items'][$ix]['pending'] = round($s['pending'], 3);
                    $one['items'][$ix]['avail']   = round($s['avail'], 3);
                    $one['items'][$ix]['short']   = $s['short'];
                    $one['items'][$ix]['stockGate'] = $s['scope'] === 'gate' ? $s['gate'] : '';
                    if ($s['short']) { $shortList[$x['matCode']] = docReviseShortText($x['matCode'], $s); }
                }
                $one['stockShort'] = (bool)$shortList;
                $one['shortText']  = array_values($shortList);
            }
            // [2026-10-06 rev2] ผู้ขอแก้จำนวนใบของตัวเองได้ที่หน้าอนุมัติเลย (รออนุมัติ / ตีกลับ) — ผู้ขอคนเดียว
            $one['status']  = (string)$d['status'];
            $one['canEdit'] = ($reqName !== '' && mb_strtolower($reqName, 'UTF-8') === $me
                               && in_array($docType, docReviseStockTypes(), true) && docReviseEditableStatus((string)$d['status']));
            // [2026-10-06] ใบที่ตีกลับให้แก้ — ผู้อนุมัติกดไม่ได้ (รอผู้ขอแก้) · ผู้ขอแก้/ส่งใหม่/ยกเลิกได้
            if (strcasecmp(trim((string)$d['status']), DOC_ST_REVISE) === 0) {
                $one['revise']    = true;
                $one['canAct']    = false;
                $one['canRevise'] = ($reqName !== '' && mb_strtolower($reqName, 'UTF-8') === $me);
                $revInfoDocs[]    = $one['id'];
            }
            // [2026-10-02 · GP-16] ใบของตัวเอง: กดอนุมัติได้เฉพาะ PM ของไซต์ (ปุ่มซ่อน + บอกเหตุ)
            if ($one['canAct'] && $reqName !== '' && mb_strtolower($reqName, 'UTF-8') === $me
                && !_apSelfApproveAllowed($pdo, $user, (int)$d['project_id'])) {
                $one['canAct'] = false;
                $one['selfBlocked'] = true;
                $one['requiredText'] = 'ใบของคุณเอง — ต้องให้ผู้อนุมัติคนอื่นอนุมัติ (อนุมัติตัวเองได้เฉพาะ PM ของไซต์)';
            }
            if ($docType === 'TG') {   // [2026-10-02] ใบย้าย Gate — ผู้รับ = "ย้ายไป Gxx" · ป้าย G = ต้นทาง
                $one['requiredText'] = gmRequiredText($requiredRole);
                $one['moveTo']       = preg_match('/G\d+$/i', trim((string)$d['receiver_name']), $tgm) ? strtoupper($tgm[0]) : '';
            }
            if ($isTd) {
                $one['destSite']     = (string)($d['dest_code'] ?? '');
                $one['destSiteName'] = (string)($d['dest_name'] ?? '');
                $one['requiredText'] = 'รอผู้จัดการโครงการ (PM) ของไซต์ต้นทางอนุมัติ';
                // ใบโอนเก็บ "ผู้รับที่ไซต์ปลายทาง" ในช่องพื้นที่ใช้งาน — การ์ด/ป๊อปอัปเดิมติดป้าย "พื้นที่ใช้งาน" จึงย้ายไปขึ้นในหมายเหตุ
                $one['contact']   = $usageArea;
                $one['notice']    = trim('ผู้รับที่ปลายทาง: ' . ($usageArea !== '' ? $usageArea : '-') . ($notice !== '' ? ' · ' . $notice : ''));
                $one['usageArea'] = '';
            }
            if ($docType === Doc::TYPE_BD) {
                // [Scenario 05 ③] กำหนดวันคืน + ผู้อนุมัติเห็นจำนวนใบยืมเกินกำหนดที่ผู้ยืมค้างอยู่ก่อนกดอนุมัติ
                if ($overdueMap === null) {
                    $overdueMap = borrowOverdueByRequester($pdo, $isR0 ? null : (int)($user['projectId'] ?? 0));
                }
                $od = $overdueMap[(int)$d['project_id'] . '|' . mb_strtolower($reqName, 'UTF-8')] ?? [];
                $one['dueDate']             = (string)($d['due_date'] ?? '');
                $one['borrowerOverdue']     = count($od);
                $one['borrowerOverdueDocs'] = array_slice($od, 0, 5);
            }
            $results[] = $one;
        }

        if ($revInfoDocs) {   // [2026-10-06] เหตุผลที่ตีกลับ (activity_log revise_request ล่าสุด)
            $ri = docReviseLatestRequests($pdo, $revInfoDocs);
            foreach ($results as $ix => $r) {
                if (!empty($r['revise'])) { $results[$ix]['reviseInfo'] = $ri[$r['id']] ?? null; }
            }
        }
        // GAS เรียง oldest first ตาม timestamp (SQL เรียงแล้ว — คงไว้ให้ชัด)
        usort($results, function ($a, $b) {
            if ($a['timestamp'] == $b['timestamp']) { return 0; }
            return ($a['timestamp'] < $b['timestamp']) ? -1 : 1;
        });

        return ['success' => true, 'data' => $results];
    } catch (Throwable $e) {
        error_log('getApprovalRequests: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// =========================================================================
// updateApprovalStatus(docId, newStatus, docType, username, roleLevel)
// =========================================================================

/**
 * อนุมัติ/ปฏิเสธเอกสาร — role ของผู้กดคิดจาก session ฝั่ง server เสมอ
 * (args username/roleLevel รับไว้ตามตำแหน่งเดิมแต่ไม่ใช้ตัดสินสิทธิ์)
 *   R0 อนุมัติไม่ได้ · requiredRole จากรายการจริง (C01→6, NAR ล้วน→1, อื่น→4)
 *   เอกสาร assign ผู้อนุมัติไว้ = คนนั้นเท่านั้น (ว่าง = ใครก็ได้ที่ role ถึง)
 *   Approve นอกจากนี้มี stock guard: on_hand − pending (ใบอื่นที่อนุมัติแล้วจองไว้) ต้องพอทุกรายการ [2026-10-06]
 *   Approve → BD='Sent Borrow', IN='Sent Inbound', อื่น='Approved' + สร้าง
 *   gate_logs Awaiting · ทุกกรณีจบด้วย recalcPending
 */
/** อนุมัติใบของตัวเองได้เฉพาะ PM (role PM · active) ของไซต์ของใบ — GP-16 · 2026-10-02 */
function _apSelfApproveAllowed(PDO $pdo, ?array $user, int $projectId): bool {
    if (!$user || ($user['accountType'] ?? '') === 'subcontractor') { return false; }
    return docExtIsProjectPm($pdo, trim((string)($user['username'] ?? '')), $projectId);
}

function rpc_updateApprovalStatus(PDO $pdo, ?array $user, array $args) {
    try {
        if (!$user) {
            return ['success' => false, 'message' => 'session expired'];
        }
        $docId     = trim((string)($args[0] ?? ''));
        $newStatus = (string)($args[1] ?? '');
        $docType   = _apDocType($args[2] ?? '');
        // args[3]=username, args[4]=roleLevel — ไม่เชื่อ client (rule เดิมของ GAS
        // คือ re-derive จาก username; ที่นี่ใช้บัญชี session แทน)
        $actor = _apEffectiveName($user);

        $approverRoleNum = _apServerRoleLevel($pdo, $user);

        // R0 อนุมัติ/ปฏิเสธไม่ได้ (ข้อความเดิม)
        if ($approverRoleNum === 0) {
            return ['success' => false, 'message' => 'R0 ไม่มีสิทธิ์อนุมัติหรือปฏิเสธรายการ'];
        }

        if ($docId === '') {
            return ['success' => false, 'message' => 'Document ID not found'];
        }

        $pdo->beginTransaction();
        try {
            $ds = $pdo->prepare(
                'SELECT id, doc_no, doc_type, project_id, gate_id, status, approver_username, requester_username
                   FROM documents WHERE doc_no = ? AND doc_type = ? FOR UPDATE'
            );
            $ds->execute([$docId, $docType]);
            $doc = $ds->fetch();
            if (!$doc) {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'Document ID not found'];
            }
            $projectId = (int)$doc['project_id'];

            // [2026-10-02 · GP-16 / GP-45] เดิมไม่ตรวจสถานะ/ค่า/ไซต์/ผู้ส่ง — เปลี่ยนสถานะใบได้ทุกเมื่อ (= แก้ใบหลังอนุมัติทางอ้อม)
            //   และผู้ส่งอนุมัติใบของตัวเองได้ถ้าไม่ได้กำหนดผู้อนุมัติ
            $guardErr = null;
            if (!in_array($newStatus, [Doc::ST_APPROVED, Doc::ST_REJECTED, DOC_ST_REVISE], true)) {   // [2026-10-06] + ตีกลับให้แก้
                $guardErr = 'สถานะที่ส่งมาไม่ถูกต้อง (อนุมัติ / ไม่อนุมัติ / ตีกลับให้แก้ เท่านั้น)';
            } elseif (strcasecmp(trim((string)$doc['status']), Doc::ST_AWAITING) !== 0) {
                $guardErr = 'ใบ ' . $docId . ' ไม่ได้รออนุมัติแล้ว (สถานะ: ' . (string)$doc['status'] . ') — อนุมัติ/ปฏิเสธซ้ำ หรือแก้ใบหลังอนุมัติไม่ได้';
            } elseif (($user['accountType'] ?? '') !== 'subcontractor' && (int)($user['projectId'] ?? 0) > 0
                      && (int)($user['projectId'] ?? 0) !== $projectId) {
                $guardErr = 'ใบนี้ไม่ได้อยู่ในไซต์ของคุณ';
            } else {
                $reqUser = trim((string)($doc['requester_username'] ?? ''));
                if ($reqUser !== '' && eqUser($reqUser, $actor) && !_apSelfApproveAllowed($pdo, $user, $projectId)) {
                    $guardErr = 'อนุมัติ/ปฏิเสธใบของตัวเองไม่ได้ — ต้องให้คนอื่นอนุมัติ (อนุมัติตัวเองได้เฉพาะ PM ของไซต์)';
                }
            }
            if ($guardErr !== null) {
                $pdo->rollBack();
                return ['success' => false, 'message' => $guardErr];
            }

            // ---- requiredRole จากรายการจริงของเอกสาร (mirror getMatCatIdMap_) ----
            $it = $pdo->prepare(
                'SELECT di.id, di.mat_code, di.qty, di.stock_deducted, di.material_id,
                        m.cat_id, m.id AS master_id
                   FROM document_items di
                   LEFT JOIN materials m ON m.mat_code = di.mat_code
                  WHERE di.document_id = ? FOR UPDATE'
            );
            $it->execute([(int)$doc['id']]);
            $items = $it->fetchAll();

            $hasC01 = false;
            $allNAR = true;
            foreach ($items as $row) {
                $catUp = strtoupper(trim((string)($row['master_id'] !== null ? $row['cat_id'] : '')));
                if ($catUp === 'C01') { $hasC01 = true; }
                if ($catUp !== 'NAR') { $allNAR = false; }
            }
            if ($docType === 'TG') {
                // [2026-10-02] ใบย้าย Gate: เกณฑ์ตามหมวด IC เหมือนใบยืม (มี C01 → R6 · C02 → R4 · NAR ล้วนอนุมัติทันทีตอนออกใบ)
                require_once __DIR__ . '/gatemove.php';
                $requiredRole = max(1, gmDocRequiredLevel($pdo, (int)$doc['id']));
            } elseif ($docType === DOC_TYPE_TD) {
                // [2026-09-29] ใบโอนย้ายข้ามไซต์: PM ของไซต์ต้นทางเท่านั้น (อนุมัติ/ไม่อนุมัติ) — ไม่ใช้เกณฑ์ระดับ R
                $isPm = (($user['accountType'] ?? '') !== 'subcontractor')
                     && docExtIsProjectPm($pdo, trim((string)($user['username'] ?? '')), $projectId);
                if (!$isPm) {
                    $pdo->rollBack();
                    return [
                        'success' => false,
                        'message' => 'ใบโอนย้ายข้ามไซต์ (TD) ต้องให้ผู้จัดการโครงการ (PM) ของไซต์ต้นทางเป็นผู้อนุมัติเท่านั้น',
                    ];
                }
                $requiredRole = 0;
            } else {
                // [PHP port 2026-09-25 · มติ 52] RD ต้องผู้อนุมัติ R6 ขึ้นไปทุกใบ (ไม่ใช่แค่ C01) · BD/IN คงเดิม
                $requiredRole = $hasC01 ? 6 : ($allNAR ? 1 : (($docType === Doc::TYPE_RD) ? 6 : 4));
            }
            if ($approverRoleNum < $requiredRole) {
                $pdo->rollBack();
                return [
                    'success' => false,
                    'message' => 'ต้องการสิทธิ์ R' . $requiredRole . '+ เพื่ออนุมัติเอกสารนี้',
                ];
            }

            // ---- assigned-approver: เอกสารที่กำหนดผู้อนุมัติไว้ คนนั้นเท่านั้น ----
            // (GAS บังคับใน UI ผ่าน canAct — ฝั่ง server port นี้บังคับซ้ำตามกติกาเดียวกัน)
            $assigned = trim((string)($doc['approver_username'] ?? ''));
            if ($assigned !== '' && !eqUser($assigned, $actor)) {
                $pdo->rollBack();
                return [
                    'success' => false,
                    'message' => 'เอกสารนี้กำหนดผู้อนุมัติไว้แล้ว (' . $assigned . ') เท่านั้นที่อนุมัติได้',
                ];
            }

            // ---- [2026-10-06] ตีกลับให้แก้: ต้องมีเหตุผล · ใบ = Awaiting revision · แจ้งผู้ขอ · ไม่จอง/ไม่ขึ้นประตู ----
            if ($newStatus === DOC_ST_REVISE) {
                $revNote = trim(mb_substr((string)($args[5] ?? ''), 0, 255, 'UTF-8'));
                if (mb_strlen($revNote, 'UTF-8') < 3) {
                    $pdo->rollBack();
                    return ['success' => false, 'message' => 'ตีกลับให้แก้: ใส่เหตุผลให้ผู้ขอ (อย่างน้อย 3 ตัวอักษร)'];
                }
                $needR = [];
                foreach ($items as $row) {
                    $mc = trim((string)$row['mat_code']);
                    if ($mc !== '' && (float)$row['qty'] > 0) { $needR[$mc] = ($needR[$mc] ?? 0.0) + (float)$row['qty']; }
                }
                $shortR = [];
                if (in_array($docType, docReviseStockTypes(), true)) {
                    foreach (docReviseStockCheck($pdo, $projectId, $doc['gate_id'] !== null ? (int)$doc['gate_id'] : null, $needR) as $mc => $s) {
                        if ($s['short']) { $shortR[] = docReviseShortText($mc, $s); }
                    }
                }
                $pdo->prepare('UPDATE documents SET status = ? WHERE id = ?')->execute([DOC_ST_REVISE, (int)$doc['id']]);
                _apActivityLog($pdo, 'document', $docId, $actor, 'revise_request', (string)$doc['status'],
                    json_encode(['note' => $revNote, 'short' => $shortR], JSON_UNESCAPED_UNICODE));
                $reqU = trim((string)($doc['requester_username'] ?? ''));
                if ($reqU !== '') {
                    borrowNotify($pdo, $projectId, [$reqU], 'revise', 'ใบ ' . $docId . ' ถูกตีกลับให้แก้',
                        'เหตุผล: ' . $revNote . ($shortR ? "
" . implode("
", $shortR) : '')
                        . "
แก้จำนวนแล้วส่งใหม่ หรือยกเลิกใบ ที่หน้า \"การอนุมัติ\"", $docId, $actor);
                }
                recalcPending($pdo, $projectId);
                $pdo->commit();
                return ['success' => true, 'status' => DOC_ST_REVISE];
            }

            // BD อนุมัติ → 'Sent Borrow', IN → 'Sent Inbound' (พร้อมขึ้นประตู)
            $actualStatus = $newStatus;
            $isApproveAction = ($newStatus === Doc::ST_APPROVED);
            if ($isApproveAction) {
                if ($docType === Doc::TYPE_BD)      { $actualStatus = Doc::ST_SENT_BORROW; }
                elseif ($docType === Doc::TYPE_IN)  { $actualStatus = Doc::ST_SENT_INBOUND; }
            }

            // ---- Stock safety check (เฉพาะชนิดเบิกออก ก่อน Approve) ----
            // รวมความต้องการต่อวัสดุเฉพาะแถวที่ยังไม่ถูกตัดสต๊อก แล้วเทียบ "พร้อมเบิก" = on_hand − pending
            // [2026-10-06] pending = ยอดจองของใบอื่นที่อนุมัติแล้วเท่านั้น (ใบนี้ยังรออนุมัติจึงไม่อยู่ใน pending)
            //   เดิมเทียบ on_hand ตรง ๆ เพราะ pending รวมใบรออนุมัติ (รวมใบนี้เอง) → อนุมัติจนจองเกินของที่มีได้
            // ขาด → ปฏิเสธ (กัน race หลายใบทับวัสดุเดียวกัน — ล็อกแถวยอด FOR UPDATE)
            if ($isApproveAction && ($docType === Doc::TYPE_RD || $docType === Doc::TYPE_OD || $docType === Doc::TYPE_BD || $docType === DOC_TYPE_TD || $docType === 'TG')) {
                $need = []; // mat_code → qty รวม
                foreach ($items as $row) {
                    if (isTrueFlag($row['stock_deducted'])) { continue; }
                    $mc  = trim((string)$row['mat_code']);
                    $qty = (float)$row['qty'];
                    if ($mc === '' || $qty <= 0) { continue; }
                    $need[$mc] = ($need[$mc] ?? 0.0) + $qty;
                }
                if ($need) {
                    $shortages = [];

                    // ---- ใบผูกประตู → เทียบยอด "ของประตูนั้น" (มติ 51 — [PHP port 2026-09-25 per-gate]) ----
                    // ของที่ประตูอื่นไม่นับ (ผู้ขอเลือกประตูที่จะไปรับไว้แล้ว) · เทียบ on_hand − pending ของประตูนั้น
                    // วัสดุที่ยังไม่มีแถวรายประตูเลย (ข้อมูลยุคก่อนแยกประตู) → fallback ไปเทียบยอดรวมไซต์
                    $gateCodeOfDoc = '';
                    if ($doc['gate_id'] !== null) {
                        $gq = $pdo->prepare('SELECT gate_code FROM gates WHERE id = ?');
                        $gq->execute([(int)$doc['gate_id']]);
                        $gcv = $gq->fetchColumn();
                        $gateCodeOfDoc = $gcv === false ? '' : strtoupper(trim((string)$gcv));
                    }
                    $siteNeed = [];
                    if ($gateCodeOfDoc !== '') {
                        $mq = $pdo->prepare('SELECT id FROM materials WHERE mat_code = ?');
                        foreach ($need as $mc => $q) {
                            $mq->execute([$mc]);
                            $mid = $mq->fetchColumn();
                            if ($mid === false) { continue; } // ไม่มีใน master = ไม่ทัก (พฤติกรรมเดิม)
                            $ga = stockGateAvail($pdo, $projectId, (int)$mid, $gateCodeOfDoc, true);
                            if (!$ga['any']) { $siteNeed[$mc] = $q; continue; }
                            if ($q > $ga['avail'] + 0.0005) {   // [2026-10-06] หักยอดที่ใบอื่นอนุมัติจองไว้แล้ว
                                $shortages[] = $mc . ' (ต้องการ ' . _apNumStr($q) . ' พร้อมเบิกที่ประตู ' . $gateCodeOfDoc . ' '
                                             . _apNumStr(max(0, $ga['avail']))
                                             . ($ga['pending'] > 0.0005 ? ' — ในคลัง ' . _apNumStr($ga['on_hand'])
                                                . ' ใบอื่นที่อนุมัติแล้วจองไว้ ' . _apNumStr($ga['pending']) : '')
                                             . ')';
                            }
                        }
                    } else {
                        $siteNeed = $need;
                    }

                    if ($siteNeed) {
                        // GAS เทียบเฉพาะแถว Balance ที่มีอยู่ — วัสดุไม่มีแถว Balance = ไม่ทัก
                        $bph = implode(',', array_fill(0, count($siteNeed), '?'));
                        $bs  = $pdo->prepare(
                            "SELECT m.mat_code, b.on_hand, b.pending
                               FROM stock_balances b
                               JOIN materials m ON m.id = b.material_id
                              WHERE b.project_id = ? AND m.mat_code IN ($bph)
                              FOR UPDATE"
                        );
                        $bs->execute(array_merge([$projectId], array_keys($siteNeed)));
                        while ($b = $bs->fetch()) {
                            $mc = trim((string)$b['mat_code']);
                            if (!isset($siteNeed[$mc])) { continue; }
                            $onhand = (float)$b['on_hand'];
                            $avail  = $onhand - (float)$b['pending'];   // [2026-10-06] หักยอดที่ใบอื่นอนุมัติจองไว้แล้ว
                            if ($siteNeed[$mc] > $avail + 0.0005) {
                                $shortages[] = $mc . ' (ต้องการ ' . _apNumStr($siteNeed[$mc]) . ' พร้อมเบิก ' . _apNumStr(max(0, $avail))
                                             . ((float)$b['pending'] > 0.0005 ? ' — ในคลัง ' . _apNumStr($onhand)
                                                . ' ใบอื่นที่อนุมัติแล้วจองไว้ ' . _apNumStr((float)$b['pending']) : '')
                                             . ')';
                            }
                        }
                    }
                    if ($shortages) {
                        $pdo->rollBack();
                        return [
                            'success' => false,
                            'message' => ($gateCodeOfDoc !== '' ? 'สต๊อกที่ประตู ' . $gateCodeOfDoc . ' ไม่พอ — ' : 'สต๊อกไม่พอ — ')
                                       . implode(', ', $shortages)
                                       . "\nกรุณาให้ผู้ขอลดจำนวน หรือยกเลิกเอกสารอื่นก่อน (ใบรออนุมัติไม่จองของ — ใบที่อนุมัติก่อนได้ของก่อน)",
                        ];
                    }
                }
            }

            // ---- เขียนสถานะ + ผู้อนุมัติ (GAS ทับคอลัมน์ Approver ด้วยผู้กดจริง) ----
            $oldStatus = (string)$doc['status'];
            $up = $pdo->prepare(
                'UPDATE documents SET status = ?, approver_username = ?, approved_by = ? WHERE id = ?'
            );
            $up->execute([$actualStatus, $actor !== '' ? $actor : $doc['approver_username'], $actor, (int)$doc['id']]);

            // เพิ่งอนุมัติ → พร้อมขึ้นหน้า QR: สร้าง gate_logs Awaiting (idempotent)
            // GAS ห่อ try/catch แค่ log — ล้มไม่ทำให้การอนุมัติล้ม
            if ($isApproveAction) {
                try {
                    _apEnsureGateLogAwaiting($pdo, $doc);
                } catch (Throwable $e) {
                    error_log('approve gate_logs create failed for ' . $docId . ': ' . $e->getMessage());
                }
            }

            // ไม่ตัด OnHand ตอนอนุมัติ — ใบอนุมัติยังเป็น "จอง" (Pending) จนปิดที่ประตู
            recalcPending($pdo, $projectId);

            _apActivityLog(
                $pdo, 'document', $docId, $actor,
                $isApproveAction ? 'approve' : ($newStatus === Doc::ST_REJECTED ? 'reject' : mb_strtolower($newStatus, 'UTF-8')),
                $oldStatus, $actualStatus
            );

            $pdo->commit();
            return ['success' => true];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    } catch (Throwable $e) {
        error_log('updateApprovalStatus: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// =========================================================================
// cancelRequisition(docId, docType, username)
// =========================================================================

/**
 * ยกเลิกเอกสาร — เฉพาะผู้ส่งเอง (เทียบ requester กับชื่อ effective ของ session)
 * อนุญาตเฉพาะสถานะที่ยังไม่ปิดงานที่ประตู · คืนสต๊อกที่เคยตัด · ตั้ง
 * documents.status = 'Cancelled' และ gate_logs.status = 'Cancelled'
 * (ประตูเลิกฟังใบนี้) · จบด้วย recalcPending
 */
function rpc_cancelRequisition(PDO $pdo, ?array $user, array $args) {
    try {
        if (!$user) {
            return ['success' => false, 'message' => 'session expired'];
        }
        $docId   = trim((string)($args[0] ?? ''));
        $docType = _apDocType($args[1] ?? '');
        // args[2]=username — ตัวตนใช้จาก session (server-authoritative)

        $meUser = trim((string)($user['username'] ?? ''));
        $meFull = trim((string)($user['fullName'] ?? ''));
        if ($docId === '' || ($meUser === '' && $meFull === '')) {
            return ['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วน'];
        }

        $pdo->beginTransaction();
        try {
            $ds = $pdo->prepare(
                'SELECT id, doc_no, doc_type, project_id, requester_username, status
                   FROM documents WHERE doc_no = ? AND doc_type = ? FOR UPDATE'
            );
            $ds->execute([$docId, $docType]);
            $doc = $ds->fetch();
            if (!$doc) {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'ไม่พบเอกสาร'];
            }

            // Ownership: ผู้ส่งเท่านั้น (SUB บันทึกเอกสารด้วย SubName = fullName
            // ของ session — ยอมรับได้ทั้ง username และ fullName)
            $docOwner = trim((string)$doc['requester_username']);
            $isOwner  = $docOwner !== '' && (
                ($meUser !== '' && eqUser($docOwner, $meUser)) ||
                ($meFull !== '' && eqUser($docOwner, $meFull))
            );
            if (!$isOwner) {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'คุณไม่สามารถยกเลิกเอกสารของผู้อื่นได้'];
            }

            // สถานะที่ห้ามยกเลิก (เทียบ substring lowercase — เหมือน GAS)
            // + 'sent return' (Scenario 05 · 2026-09-28): ใบยืมที่แจ้งคืนแล้วคือของออกไปแล้ว — ยกเลิก = ยอดกลับเข้าคลังทั้งที่ของยังไม่คืน
            $currentStatus = trim((string)$doc['status']);
            $lower = mb_strtolower($currentStatus, 'UTF-8');
            $disallowed = ['completed', 'borrowed', 'returned', 'closed', 'cancelled', 'rejected', 'sent return'];
            foreach ($disallowed as $s) {
                if (strpos($lower, $s) !== false) {
                    $pdo->rollBack();
                    return ['success' => false, 'message' => 'เอกสารนี้ไม่สามารถยกเลิกได้ (สถานะ: ' . $currentStatus . ')'];
                }
            }

            // [Scenario 05 · 2026-09-28] แตะบัตรที่ประตูแล้ว (Opened ขึ้นไป) ยกเลิกไม่ได้เด็ดขาด — เดิมห้ามเฉพาะใบที่ปิดงานแล้ว
            // รอบที่แตะบัตรแล้วจบได้ทางเดียว: ถ่ายรูปยืนยันครบทุกใบแล้วปิดประตู · ของที่ตัดไปโดยไม่ได้หยิบ นำกลับด้วยใบรับเข้า (IN)
            $gs = $pdo->prepare('SELECT status, picking_id FROM gate_logs WHERE doc_no = ? FOR UPDATE');
            $gs->execute([$docId]);
            $glNow = $gs->fetch();
            if ($glNow) {
                $gLower = strtolower(trim((string)$glNow['status']));
                if (in_array($gLower, ['opened', 'scanned', 'confirmed', 'closed'], true)) {
                    $pdo->rollBack();
                    $pk = trim((string)($glNow['picking_id'] ?? ''));
                    return ['success' => false,
                            'message' => 'ใบนี้ถูกแตะบัตรที่ประตูแล้ว' . ($pk !== '' ? ' (รอบ ' . $pk . ')' : '') . ' — ยกเลิกไม่ได้' . "\n"
                                       . 'ให้ถ่ายรูปยืนยันแล้วปิดประตูให้จบรอบ ของที่ไม่ได้หยิบ (หยิบจริง 0) ไม่ถูกตัดสต๊อก '
                                       . 'ส่วนของที่ตัดไปโดยไม่ได้ใช้ให้นำกลับด้วยใบรับเข้า (IN)'];
                }
            }

            // คืนสต๊อกที่เคยตัด — นับแถวที่จะถูกคืนก่อน (mirror return count ของ
            // _restoreStockForDoc_: ธง=1 + mat ไม่ว่าง + qty>0)
            $restoredCount = 0;
            try {
                $cs = $pdo->prepare(
                    "SELECT COUNT(*) FROM document_items
                      WHERE document_id = ? AND stock_deducted = 1
                        AND TRIM(mat_code) <> '' AND qty > 0"
                );
                $cs->execute([(int)$doc['id']]);
                $restoredCount = (int)$cs->fetchColumn();
                if ($restoredCount > 0) {
                    restoreStockForDoc($pdo, $docId);
                }
            } catch (Throwable $e) {
                // GAS: restore ล้ม = log แล้วไปต่อ (ยกเลิกเอกสารต่อ)
                error_log('cancel restore failed: ' . $e->getMessage());
                $restoredCount = 0;
            }

            // ── คืนยอดกลับ buffer ถ้าใบนี้ออกมาจากสาย PO → buffer (มติ 26) ──
            // ต่างจาก GAS โดยตั้งใจ: GAS ไม่มีแนวคิด buffer เลย
            // ตั้งใจ "ไม่" ห่อ try/catch แบบ restore สต๊อก — ยกเลิกใบแล้วยอดไม่คืน
            // คือของหายจากระบบเงียบ ๆ ล้มทั้งรายการดีกว่าปล่อยให้เพี้ยน
            require_once __DIR__ . '/po.php';
            $rb = poRollbackPushesForDoc($pdo, $docId, $user);
            if (!$rb['ok']) {
                throw new RuntimeException('คืนยอดกลับ buffer ไม่สำเร็จ: ' . $rb['error']);
            }

            // ตั้งสถานะ Cancelled
            $pdo->prepare('UPDATE documents SET status = ? WHERE id = ?')
                ->execute([Doc::ST_CANCELLED, (int)$doc['id']]);

            // gate_logs ของ doc นี้ → 'Cancelled' (เทียบ doc_no ตรงตัวเหมือน GAS —
            // แถวขาคืน 'xxxRT' ไม่โดน) · gate_logs.status เป็น VARCHAR รองรับค่านี้
            try {
                $pdo->prepare('UPDATE gate_logs SET status = ? WHERE doc_no = ?')
                    ->execute([Gate::ST_CANCELLED, $docId]);
            } catch (Throwable $e) {
                error_log('cancel gate_logs update failed: ' . $e->getMessage());
            }

            recalcPending($pdo, (int)$doc['project_id']);

            _apActivityLog($pdo, 'document', $docId, _apEffectiveName($user), 'cancel',
                $currentStatus, Doc::ST_CANCELLED);

            $pdo->commit();

            $msg = $restoredCount > 0 ? 'ยกเลิกเอกสารและคืนสต๊อกเรียบร้อย' : 'ยกเลิกเอกสารเรียบร้อย';
            if ($rb['n_pushes'] > 0) {
                $msg .= ' · คืนของกลับเข้า buffer '
                      . rtrim(rtrim(number_format($rb['qty'], 4, '.', ''), '0'), '.')
                      . ' หน่วยซื้อ (' . $rb['n_pushes'] . ' รอบ) — ออกใบใหม่ได้เลย';
            }
            return [
                'success'       => true,
                'restored'      => $restoredCount,
                'bufferPushes'  => $rb['n_pushes'],
                'bufferQty'     => $rb['qty'],
                'message'       => $msg,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    } catch (Throwable $e) {
        error_log('cancelRequisition: ' . $e->getMessage());
        return ['success' => false, 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()];
    }
}
