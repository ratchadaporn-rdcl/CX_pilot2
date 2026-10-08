<?php
/**
 * CONNEXT — api/gate.php : endpoint สำหรับ gate controller (IRIV PiControl CM4)
 * แทน GAS doGet(e)/doPost(e) ส่วน controller ทั้งหมด — Pi firmware parse
 * response ตามรูปแบบเดิมเป๊ะ (JSON ของ ContentService / plain text ของ legacy poll)
 *
 * GAS ที่ port:
 *   doGet   ?action=getCardList&siteCode=..   → getCardListBySite_
 *   doGet   ?action=checkPickingStatus&pickingId=..&gateId=..  → checkPickingStatus_
 *   doGet   ?docID=..                          → legacy status poll (plain text)
 *   doPost  action=submitPickingList           → submitPickingList_
 *   doPost  action=closeGate                   → handleGateClosedByPicking_
 *   doPost  action=logError                    → logError_
 *   doPost  legacy {docID, status}             → single-doc update + scan-flow rewrite
 *   doGet   ?action=reconcile (ใหม่ — cron)    → reconcileGateFinalization
 *
 * เพิ่มตามเอกสาร 05 Scenario การทำงานของระบบ (2026-09-28) — คำตอบเดิมทุกคีย์คงอยู่ เพิ่มคีย์ใหม่เท่านั้น:
 *   GET  getCardList        + data[].Role/Store · storeCards[] · cardRoles{} · settings{pickMinPerItem,pickCapMin,extendMin,storeOverCap} ⑧
 *   GET  getGateSettings    ใหม่ — ค่าตั้งเวลาหยิบของของไซต์ + เวลา server
 *   GET  checkPickingStatus + docs[] (สถานะรายใบ · ผู้นำจ่าย) · itemCount · open · closed (① ขั้น 6)
 *   POST submitPickingList  รับเฉพาะใบ "รอสแกน" (Awaiting) ที่ยังมีชีวิตและเป็นของ G นี้ · ใบ IN สแกนได้ทุก G
 *                           (จด G ที่สแกนทับ G ในใบ) · ไม่มีใบผ่าน = ไม่ออกเลขรอบ · + itemCount · docs[] · rejected[]
 *   POST addToPicking       ใหม่ — เพิ่มใบเข้ารอบเดิม (เลขรอบเดิม + บัตรที่แตะ) ปฏิเสธเมื่อรอบปิด/ยืนยันครบแล้ว ⑥
 *   POST logRoundEvent      ใหม่ — หมดเวลาหยิบของ/ขอเวลาเพิ่ม/เลื่อนปิดประตู/สรุปรอบ → gate_round_events + error_logs ⑧ ⑨
 *   POST closeGate          ตัดสต๊อกตามจำนวนหยิบจริง (lib/stock.php) · + สรุปรอบถ้าตู้ส่งมา · + docs[]
 *   POST legacy {docID,status} ห้ามย้อนใบที่แตะบัตรแล้วกลับเป็น Awaiting · Closed ได้เฉพาะใบที่ Confirmed
 *
 * ไม่มี session/CSRF (device API) — auth ด้วย settings 'gate_api_key':
 *   ถ้าคีย์ใน settings ไม่ว่าง ต้องส่ง ?key= (GET) หรือ key ใน body (POST)
 *   ให้ตรง (hash_equals) มิฉะนั้น 403 · คีย์ว่าง = compat mode (เปิดหมดแบบ GAS)
 *
 * PHP 7.4-compatible เท่านั้น
 */

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/stock.php';   // finalizeGateDoc()
require_once __DIR__ . '/../lib/docnum.php';  // nextPickingId()
require_once __DIR__ . '/../lib/s05.php';     // รอบเบิก · จำนวนรหัส IC ของรอบ · ค่าตั้งเวลา (Scenario 05)

// =========================================================================
// Response helpers — โครงตรง ContentService ของ GAS
// =========================================================================

/** JSON ตามรูปแบบ createTextOutput(JSON.stringify(...)).setMimeType(JSON) */
function gateJson($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** plain text ตาม createTextOutput(...) (legacy ?docID poll) */
function gateText(string $s): void {
    header('Content-Type: text/plain; charset=utf-8');
    echo $s;
    exit;
}

// =========================================================================
// helpers ภายใน (mirror GAS)
// =========================================================================

/** mirror _gateFromDocId_: ตัด RT ท้ายก่อน แล้วหา G{n} ท้ายเลขเอกสาร */
function gateCodeFromDocNo(string $docNo): string {
    if ($docNo === '') return '';
    $clean = preg_replace('/RT$/i', '', trim($docNo));
    if (preg_match('/G(\d+)$/i', $clean, $m)) {
        return 'G' . $m[1];
    }
    return '';
}

/**
 * mirror gateUsesScanFlow_: true = ประตูใช้ flow Scanned/จบที่ Confirmed
 *   '' (ไม่ทราบประตู) → false (flow เดิม/hardware — ปลอดภัยสุด)
 *   ประตูที่ไม่อยู่ในทะเบียน gates → true (GAS: indexOf ใน HARDWARE_CLOSE_GATES === -1)
 */
function gateUsesScanFlow(PDO $pdo, string $gateCode, ?int $projectId = null): bool {
    $g = strtoupper(trim($gateCode));
    // ต่างจาก GAS โดยตั้งใจ (CHANGES-FROM-GAS.md §ใบ IN ไร้ประตู): '' = ใบที่ออกมาโดย
    // ไม่ผูกประตู → ถือเป็น scan-flow เพื่อให้จบงานที่ขั้น "ถ่ายรูปยืนยัน" ได้
    // เดิมคืน false = ต้องรอ hardware ปิดประตู ซึ่งไม่มีวันเกิดกับใบที่ไม่มีประตู
    if ($g === '') return true;
    if ($projectId !== null && $projectId > 0) {
        $st = $pdo->prepare('SELECT hardware_close FROM gates WHERE gate_code = ? AND project_id = ?');
        $st->execute([$g, $projectId]);
        $hw = $st->fetchColumn();
        if ($hw !== false) return !isTrueFlag($hw);
    }
    // ไม่รู้โครงการ / ไม่พบในโครงการ → ดูข้ามโครงการ (GAS ใช้ const กลางตัวเดียว)
    $st = $pdo->prepare('SELECT MAX(hardware_close) FROM gates WHERE gate_code = ?');
    $st->execute([$g]);
    $hw = $st->fetchColumn();
    if ($hw === false || $hw === null) return true; // ไม่อยู่ในทะเบียน = ไม่ใช่ hardware gate
    return !isTrueFlag($hw);
}

/** scan-flow ของแถว gate_logs: ใช้ hardware_close ที่ join มาก่อน ค่อย fallback ตามเลขเอกสาร */
function gateRowUsesScanFlow(PDO $pdo, array $row): bool {
    $rowGate = trim((string)($row['gate_code'] ?? ''));
    if ($rowGate !== '' && $row['hardware_close'] !== null) {
        return !isTrueFlag($row['hardware_close']);
    }
    if ($rowGate === '') {
        $rowGate = gateCodeFromDocNo((string)($row['doc_no'] ?? ''));
    }
    $projectId = isset($row['project_id']) ? (int)$row['project_id'] : null;
    return gateUsesScanFlow($pdo, $rowGate, $projectId);
}

/** mirror logError_: เขียน error_logs (SiteCode → project_id ถ้า resolve ได้) — ไม่โยน error */
function gateLogError(PDO $pdo, string $siteCode, string $gateCode, string $message): void {
    try {
        $projectId = null;
        $siteCode = trim($siteCode);
        if ($siteCode !== '') {
            $st = $pdo->prepare('SELECT id FROM projects WHERE code = ?');
            $st->execute([$siteCode]);
            $pid = $st->fetchColumn();
            if ($pid !== false) $projectId = (int)$pid;
        }
        $ins = $pdo->prepare('INSERT INTO error_logs (project_id, gate_code, message) VALUES (?, ?, ?)');
        $ins->execute([$projectId, trim($gateCode) !== '' ? trim($gateCode) : null, $message]);
    } catch (Throwable $e) {
        error_log('gateLogError failed: ' . $e->getMessage() . ' | ' . $message);
    }
}

/** projects.code → id (null เมื่อว่าง/ไม่พบ) */
function gateProjectIdByCode(PDO $pdo, string $siteCode): ?int {
    $siteCode = trim($siteCode);
    if ($siteCode === '') return null;
    $st = $pdo->prepare('SELECT id FROM projects WHERE code = ?');
    $st->execute([$siteCode]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int)$id;
}

// =========================================================================
// กติกาการรับใบที่ประตู — Scenario 05 [PHP port 2026-09-28]
//   เว็บรับเฉพาะใบ "รอสแกน" (Awaiting) · ใบที่แตะบัตรแล้ว (Opened) ปิดงานแล้ว ยกเลิกแล้ว
//   ไม่มีในระบบ หรือเป็นของ G อื่น = ข้าม + error_logs · ไม่มีใบที่รับได้เลย = ประตูไม่เปิด
//   ใบ IN (รับเข้า) สแกนได้ทุก G ของไซต์ของใบ: จด G ของตู้ที่สแกน (ของเข้า G ที่สแกน)
//     [2026-10-06] ใบ IN ใหม่ไม่มีรหัส G ในเลขใบ · ตู้ไซต์อื่น = ข้าม (gateSecFilterDocs) · G ที่ไม่ใช่ประตูที่ใช้งาน
//     ของไซต์ของใบ / ไม่รู้ G = ข้าม (เดิมรับไว้แล้วยอดตกประตูตั้งต้น) · legacy {docID, status} เปิดใบ IN ที่ยังไม่มี G ไม่ได้
//   ขาคืนของใบยืม (…RT) ต้องคืนที่ G เดิมของใบ
// =========================================================================

/** ใบยังมีชีวิตและอยู่ในขั้นที่ไปรับของ/นำของเข้าได้ (ตรงกับการ์ดหน้า QR) */
function gateDocIsLive(string $docType, string $docStatus, bool $isReturnLeg): bool {
    $s = mb_strtolower(trim($docStatus), 'UTF-8');
    if ($isReturnLeg) {
        return $docType === Doc::TYPE_BD && strpos($s, 'sent return') !== false;
    }
    // + TD เบิกโอนย้ายข้ามไซต์ · SC ใบนับสต๊อก (2026-09-29) — ขึ้นประตูได้เมื่อ Approved
    if ($docType === Doc::TYPE_RD || $docType === Doc::TYPE_OD || $docType === 'TD' || $docType === 'SC') {
        return strpos($s, 'approved') !== false || strpos($s, 'อนุมัติแล้ว') !== false;
    }
    if ($docType === Doc::TYPE_BD) {
        return strpos($s, 'sent borrow') !== false;
    }
    if ($docType === Doc::TYPE_IN) {
        return $s === mb_strtolower(Doc::ST_SENT_INBOUND, 'UTF-8');
    }
    if ($docType === 'TG') {   // [2026-10-02] ใบย้าย Gate: ขาเบิกออก Approved · ขานำเข้า In Transit (แยกขาที่ gmGateAcceptError)
        return strpos($s, 'approved') !== false || strpos($s, 'in transit') !== false;
    }
    return false;
}

/** ใบนับสต๊อก (SC) — ดูจากเลขเอกสาร (SC + วันที่ + เลขรัน + Gxx) */
function gateIsStockCountNo(string $docNo): bool {
    return (bool)preg_match('/^SC\d/i', trim($docNo));
}

/**
 * [2026-09-29] ใบนับสต๊อกต้องเปิดประตูแยกรอบ — สแกนมาพร้อมใบอื่น = ข้ามใบนับ (ใบอื่นเข้ารอบตามปกติ)
 * คืน [docSet ที่เหลือ, rejected[], skipped[]]
 */
function gateSplitStockCount(array $docSet): array {
    $sc = [];
    $other = [];
    foreach ($docSet as $d) {
        if (gateIsStockCountNo($d)) { $sc[] = $d; } else { $other[] = $d; }
    }
    if (!$sc || !$other) {
        return [$docSet, [], []];
    }
    $why = 'ใบนับสต๊อกต้องแตะบัตรเปิดประตูแยกรอบ (สแกนใบนับสต๊อกใบเดียว)';
    $rejected = [];
    $skipped  = [];
    foreach ($sc as $d) {
        $rejected[] = ['docId' => $d, 'reason' => $why, 'kind' => 'stockcount'];
        $skipped[]  = $d . ' (' . $why . ')';
    }
    return [$other, $rejected, $skipped];
}

/** เหตุผลสั้น ๆ ที่ใบนี้ "ไม่ได้รอสแกน" (สำหรับ skipped · error_logs · จอตู้) */
function gateStatusRejectReason(string $glStatus, string $pickingId): string {
    $s = strtolower(trim($glStatus));
    if ($s === 'opened' || $s === 'scanned') {
        return 'แตะบัตรไปแล้ว' . ($pickingId !== '' ? ' อยู่ในรอบ ' . $pickingId : '');
    }
    if ($s === 'confirmed') return 'ถ่ายรูปยืนยันแล้ว รอปิดประตู';
    if ($s === 'closed')    return 'ปิดงานแล้ว';
    if ($s === 'cancelled') return 'ยกเลิกแล้ว';
    return 'สถานะที่ประตู ' . ($glStatus !== '' ? $glStatus : '-');
}

/**
 * รับใบเข้ารอบ — ใช้ร่วมกันทั้งตอนเริ่มรอบ (submitPickingList) และเพิ่มใบระหว่างประตูเปิด (addToPicking)
 * ต้องเรียกภายในทรานแซกชัน (ล็อกแถว gate_logs ของใบ) · ไม่เขียน error_logs เอง — คืนข้อความให้ผู้เรียกจดหลังคอมมิต
 * @return array accepted[] (docNo) · notFound[] · skipped[] (ข้อความ) · wrongGate[] · rejected[{docId,reason,kind}] · logs[]
 */
function gateAcceptDocs(PDO $pdo, array $docSet, string $gateId, string $pickingId, string $cardId, bool $strict): array {
    $out = ['accepted' => [], 'notFound' => [], 'skipped' => [], 'wrongGate' => [], 'rejected' => [], 'logs' => []];
    $rowSel = $pdo->prepare(
        'SELECT gl.id, gl.doc_no, gl.project_id, gl.gate_id, gl.status, gl.leg, gl.document_id, gl.picking_id,
                g.gate_code, g.hardware_close,
                d.doc_type, d.status AS doc_status, d.gate_id AS doc_gate_id
           FROM gate_logs gl
           LEFT JOIN gates g ON g.id = gl.gate_id
           LEFT JOIN documents d ON d.id = gl.document_id
          WHERE gl.doc_no = ?
          FOR UPDATE'
    );
    $docByNo    = $pdo->prepare('SELECT id, doc_type, status, gate_id FROM documents WHERE doc_no = ? FOR UPDATE');
    $gateRowSel = $pdo->prepare("SELECT id, hardware_close FROM gates WHERE gate_code = ? AND project_id = ? AND status = 'active'");
    $setRowGate = $pdo->prepare('UPDATE gate_logs SET gate_id = ? WHERE id = ?');
    $setDocGate = $pdo->prepare('UPDATE documents SET gate_id = ? WHERE id = ?');
    // GAS ทับ Timestamp ตอนแตะบัตร → ที่นี่คือ scanned_at (หน้า confirm ใช้เรียง)
    $updNoCard   = $pdo->prepare('UPDATE gate_logs SET picking_id = ?, status = ?, scanned_at = NOW() WHERE id = ?');
    $updWithCard = $pdo->prepare('UPDATE gate_logs SET picking_id = ?, status = ?, scanned_at = NOW(), card_id = ? WHERE id = ?');

    $statusSkips = [];
    $deadSkips   = [];
    foreach ($docSet as $doc) {
        if ($doc === '') continue;
        // collation utf8mb4_unicode_ci = เทียบ case-insensitive (GAS มี alias lowercase)
        $rowSel->execute([$doc]);
        $rows = $rowSel->fetchAll();
        if (!$rows) {
            $out['notFound'][] = $doc;
            continue;
        }
        foreach ($rows as $row) {
            $docNo    = trim((string)$row['doc_no']);
            $isReturn = (bool)preg_match('/RT$/i', $docNo) || (string)$row['leg'] === 'return';
            $docType  = (string)($row['doc_type'] ?? '');
            $docSt    = (string)($row['doc_status'] ?? '');
            $docId    = $row['document_id'] !== null ? (int)$row['document_id'] : 0;
            $docGate  = $row['doc_gate_id'] !== null ? (int)$row['doc_gate_id'] : null;
            if ($docType === '') {
                // แถวเก่าที่ไม่มี document_id — หาใบจากเลข (ขาคืนตัด RT ท้าย)
                $docByNo->execute([$isReturn ? preg_replace('/RT$/i', '', $docNo) : $docNo]);
                $d = $docByNo->fetch();
                if ($d) {
                    $docType = (string)$d['doc_type'];
                    $docSt   = (string)$d['status'];
                    $docId   = (int)$d['id'];
                    $docGate = $d['gate_id'] !== null ? (int)$d['gate_id'] : null;
                }
            }

            // 1) ต้องรอสแกน (Awaiting) — Opened/Confirmed/Closed/Cancelled = ข้าม
            $glSt = trim((string)$row['status']);
            if (strcasecmp($glSt, Gate::ST_AWAITING) !== 0) {
                $why = gateStatusRejectReason($glSt, trim((string)($row['picking_id'] ?? '')));
                $out['rejected'][] = ['docId' => $docNo, 'reason' => $why, 'kind' => 'status'];
                $out['skipped'][]  = $docNo . ' (' . $why . ')';
                $statusSkips[]     = $docNo . ' (' . $glSt . ')';
                continue;
            }
            // 2) ใบต้องยังมีชีวิต (อนุมัติแล้ว / ส่งยืม / ส่งคืน / รับเข้า) — ยกเลิก/ปฏิเสธ/ปิดงาน = ข้าม
            if ($docType === '' || !gateDocIsLive($docType, $docSt, $isReturn)) {
                $why = $docType === '' ? 'ไม่พบใบในระบบ' : ('สถานะใบ ' . ($docSt !== '' ? $docSt : '-'));
                $out['rejected'][] = ['docId' => $docNo, 'reason' => $why, 'kind' => 'doc'];
                $out['skipped'][]  = $docNo . ' (' . $why . ')';
                $deadSkips[]       = $docNo . ' (' . $why . ')';
                continue;
            }
            // 2b) [2026-10-02] ใบย้าย Gate (TG): ขาต้องตรงสถานะใบ + บัตรที่แตะต้องเป็นบัตรสายสโตร์ของไซต์ (lib/gatemove.php)
            if ($docType === 'TG') {
                require_once __DIR__ . '/../lib/gatemove.php';
                $tgWhy = gmGateAcceptError($pdo, $row, $docSt, $cardId);
                if ($tgWhy !== null) {
                    $out['rejected'][] = ['docId' => $docNo, 'reason' => $tgWhy, 'kind' => 'tg'];
                    $out['skipped'][]  = $docNo . ' (' . $tgWhy . ')';
                    $deadSkips[]       = $docNo . ' (' . $tgWhy . ')';
                    continue;
                }
            }

            // 3) ประตู — เบิก G ไหน สแกน G นั้น (มติ 51) · ใบ IN สแกนได้ทุก G · ขาคืนต้อง G เดิม
            $rowGate = strtoupper(trim((string)($row['gate_code'] ?? '')));
            if ($rowGate === '') { $rowGate = strtoupper(gateCodeFromDocNo($docNo)); }
            if ($gateId !== '') {
                if ($docType === Doc::TYPE_IN && !$isReturn) {
                    // ของเข้าทุกอย่าง: สแกนที่ G ไหน = รับเข้า G นั้น — G ในใบเป็นเพียงแผน
                    $gateRowSel->execute([$gateId, (int)$row['project_id']]);
                    $gr = $gateRowSel->fetch();
                    if ($gr) {
                        if ($row['gate_id'] === null || (int)$row['gate_id'] !== (int)$gr['id']) {
                            $setRowGate->execute([(int)$gr['id'], (int)$row['id']]);
                        }
                        if ($docId > 0 && ($docGate === null || $docGate !== (int)$gr['id'])) {
                            $setDocGate->execute([(int)$gr['id'], $docId]);
                            if ($rowGate !== '' && strcasecmp($rowGate, $gateId) !== 0) {
                                s05ActivityLog($pdo, 'document', $docNo, 'gate:' . $gateId, 'in_gate_rebind',
                                    $rowGate, strtoupper($gateId));
                            }
                        }
                        $row['gate_code']      = strtoupper($gateId);
                        $row['hardware_close'] = $gr['hardware_close'];
                    }
                    if (!$gr) {
                        // [2026-10-06] ยอดรับเข้าต้องขึ้นที่ G ที่สแกน — G นี้ไม่ใช่ประตูที่ใช้งานของไซต์ของใบ = ไม่รับ
                        //   (เดิมรับไว้โดยไม่ผูกประตู แล้วยอดตกไปประตูตั้งต้นตอนปิดงาน) · ใบของไซต์อื่นถูกข้ามก่อนถึงตรงนี้ (gateSecFilterDocs)
                        $why = 'ประตู ' . strtoupper($gateId) . ' ไม่ใช่ประตูที่ใช้งานของไซต์ของใบนี้ — รับเข้าไม่ได้';
                        $out['rejected'][] = ['docId' => $docNo, 'reason' => $why, 'kind' => 'gate'];
                        $out['skipped'][]  = $docNo . ' (' . $why . ')';
                        $out['logs'][]     = 'submitPickingList: ใบรับเข้า ' . $docNo . ' — ' . $why;
                        continue;
                    }
                } elseif ($rowGate !== '' && $strict && strcasecmp($rowGate, $gateId) !== 0) {
                    $out['wrongGate'][] = $docNo . ' (ออกให้ประตู ' . $rowGate . ')';
                    $out['skipped'][]   = $docNo . ' (ออกให้ประตู ' . $rowGate . ')';
                    $out['rejected'][]  = ['docId' => $docNo, 'reason' => 'ใบไม่ได้ออกให้ประตูนี้ (ออกให้ ' . $rowGate . ')', 'kind' => 'gate'];
                    continue;
                } elseif ($rowGate === '' && $row['gate_id'] === null) {
                    // ใบไร้ประตู (IN จาก buffer · มติ 23 — กันไว้เผื่อชนิดอื่น) → จดประตูที่สแกน
                    $gateRowSel->execute([$gateId, (int)$row['project_id']]);
                    $gr = $gateRowSel->fetch();
                    if ($gr) {
                        $setRowGate->execute([(int)$gr['id'], (int)$row['id']]);
                        $row['gate_code']      = $gateId;
                        $row['hardware_close'] = $gr['hardware_close'];
                    }
                }
            }

            // [2026-10-06] ใบรับเข้าต้องรู้ G ของตู้ที่สแกน (ยอดขึ้นที่ G นั้น) — คำขอไม่มีรหัส G = ไม่รับ
            if ($docType === Doc::TYPE_IN && !$isReturn && $gateId === '') {
                $why = 'ไม่รู้ประตูของตู้ที่สแกน — ใบรับเข้าต้องสแกนที่ตู้ G ของไซต์';
                $out['rejected'][] = ['docId' => $docNo, 'reason' => $why, 'kind' => 'gate'];
                $out['skipped'][]  = $docNo . ' (' . $why . ')';
                $out['logs'][]     = 'submitPickingList: ใบรับเข้า ' . $docNo . ' — ' . $why;
                continue;
            }
            // 4) เข้ารอบ: ประตูมีตู้ = Opened · scan-flow = Scanned (+ เลขรอบ · บัตรที่แตะ · เวลา)
            $scanStatus = gateRowUsesScanFlow($pdo, $row) ? Gate::ST_SCANNED : Gate::ST_OPENED;
            if ($cardId !== '') {
                $updWithCard->execute([$pickingId, $scanStatus, $cardId, (int)$row['id']]);
            } else {
                $updNoCard->execute([$pickingId, $scanStatus, (int)$row['id']]);
            }
            $out['accepted'][] = $docNo;
        }
    }

    if ($out['notFound']) {
        $out['logs'][] = 'submitPickingList: DocIDs not in GateLogs: ' . implode(', ', $out['notFound']);
    }
    if ($out['wrongGate']) {
        $out['logs'][] = 'submitPickingList: ใบไม่ได้ออกให้ประตูนี้ (สแกนที่ ' . $gateId . '): ' . implode(', ', $out['wrongGate']);
    }
    if ($statusSkips) {
        $out['logs'][] = 'submitPickingList: ข้ามใบที่ไม่ได้รอสแกน (สแกนที่ ' . ($gateId !== '' ? $gateId : '-') . '): ' . implode(', ', $statusSkips);
    }
    if ($deadSkips) {
        $out['logs'][] = 'submitPickingList: ข้ามใบที่ยกเลิก/ปิดงาน/ยังไม่อนุมัติ (สแกนที่ ' . ($gateId !== '' ? $gateId : '-') . '): ' . implode(', ', $deadSkips);
    }
    return $out;
}

/** สถานะรอบ: missing (ไม่พบ) · closed (ปิดประตูแล้ว) · complete (ยืนยันครบ รอปิดประตู) · open */
function gateRoundState(array $rows): string {
    if (!$rows) return 'missing';
    $open = 0;
    foreach ($rows as $r) {
        $s = strtolower($r['status']);
        if ($s === 'closed') return 'closed';
        if ($s === 'opened' || $s === 'scanned') $open++;
    }
    return $open > 0 ? 'open' : 'complete';
}

/** unique + trim รักษาลำดับ (mirror new Set(pickingList.map(trim))) */
function gateDocSet($pickingList): array {
    if (!is_array($pickingList)) return [];
    $docSet = [];
    foreach ($pickingList as $d) {
        $t = trim((string)$d);
        if ($t !== '' && !in_array($t, $docSet, true)) $docSet[] = $t;
    }
    return $docSet;
}

/** ส่วนท้ายคำตอบเรื่องรอบ (ใช้ทั้งเริ่มรอบ/เพิ่มใบ/ถามสถานะ): จำนวนรหัส IC · เวลาหยิบของที่ได้ · สถานะรายใบ */
function gateRoundPayload(PDO $pdo, array $rows, ?int $projectId): array {
    $items    = s05RoundItemCount($pdo, $rows);
    $settings = s05GateSettings($pdo, (int)$projectId);
    return [
        'icCount'      => $items,          // ชื่อที่ตู้ใช้ (connext_access.py)
        'itemCount'    => $items,
        'pickLimitMin' => min((float)$settings['pickCapMin'], round($settings['pickMinPerItem'] * $items, 2)),
        'timing'       => s05GateTimingOut($settings),
        'docs'         => s05RoundDocsOut($pdo, $rows),
    ];
}

// =========================================================================
// Parse request + API key gate
// =========================================================================

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// mirror doPost: JSON body ก่อน แล้วเติม parameter ที่ยังไม่มี (form POST + query string)
$body = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $parsed = json_decode($raw, true);
        if (is_array($parsed)) $body = $parsed;
    }
    foreach ($_POST as $k => $v) {
        if (!array_key_exists($k, $body)) $body[$k] = $v;
    }
    foreach ($_GET as $k => $v) {
        if (!array_key_exists($k, $body)) $body[$k] = $v;
    }
}

// ---- API key (ใหม่ — GAS ไม่มี auth): settings 'gate_api_key' ----
// [2026-10-02 · GP-22/GP-23] key ต่อตู้ (gates.api_key_hash — ตั้งที่ admin.php) มาก่อน: เว็บรู้ไซต์ + G จาก key
//   แล้วทับรหัสไซต์/G ที่ตู้ส่งมา (lib/gate_sec.php) · key กลางเดิมยังใช้ได้ระหว่างเปลี่ยน (app_settings gate_shared_key_ok)
//   แต่คำสั่งที่เปลี่ยนสถานะรอบต้องมีรหัส G (ดูหัวส่วน POST)
require_once __DIR__ . '/../lib/gate_sec.php';
$gateApiKey = isset($APP_SETTINGS['gate_api_key']) ? (string)$APP_SETTINGS['gate_api_key'] : '';
$givenKey   = ($method === 'POST') ? (string)($body['key'] ?? '') : (string)($_GET['key'] ?? '');
$gateBound  = $givenKey !== '' ? gateSecKeyLookup($pdo, $givenKey) : null;
require_once __DIR__ . '/../lib/jobs.php';   // [2026-10-02 · GP-41] jobtoken ของงานตั้งเวลา · heartbeat → รันงาน
if ($gateBound !== null) {
    gateSecBindRequest($gateBound, $body);
} elseif ($method === 'GET' && (string)($_GET['action'] ?? '') === 'reconcile' && cnxJobTokenValid((string)($_GET['jobtoken'] ?? ''))) {
    // [2026-10-02 · GP-41] งานตั้งเวลา (lib/jobs.php) เรียก reconcile เองทุก N นาที — token เปลี่ยนทุกชั่วโมง ใช้ได้กับ reconcile เท่านั้น
} elseif ($gateApiKey !== '') {
    if (!hash_equals($gateApiKey, $givenKey) || !gateSecSharedKeyAllowed($pdo)) {
        gateJson(['success' => false, 'message' => 'Unauthorized'], 403);
    }
}

// =========================================================================
// GET — mirror doGet
// =========================================================================
if ($method === 'GET') {
    $action = trim((string)($_GET['action'] ?? ''));

    // ── GET ?action=getCardList&siteCode=SC01 — mirror getCardListBySite_ ──
    // (รับ &site= สำรองตามสเปกใหม่ — GAS อ่าน siteCode)
    if ($action === 'getCardList') {
        try {
            $siteCode = trim((string)($_GET['siteCode'] ?? $_GET['site'] ?? ''));
            $st = $pdo->prepare(
                'SELECT u.card_id, u.full_name, u.status, p.code AS project_code,
                        r.role_code, r.can_req, r.level
                   FROM users u
                   JOIN projects p ON p.id = u.project_id
                   LEFT JOIN roles r ON r.id = u.role_id'
            );
            $st->execute();
            $list = [];
            $cardMap = [];
            $roleMap = [];
            $storeCards = [];
            while ($row = $st->fetch()) {
                // mirror GAS: กรอง site เฉพาะเมื่อระบุ, ข้าม inactive, เอาเฉพาะแถวมีบัตร
                if ($siteCode !== '' && trim((string)$row['project_code']) !== $siteCode) continue;
                if (mb_strtolower(trim((string)$row['status']), 'UTF-8') === 'inactive') continue;
                // [2026-10-08] ตัวพิมพ์ใหญ่ ไม่มีช่องว่าง = รูปแบบเดียวกับที่ตู้อ่าน (ข้อมูลเก่าที่กรอกตัวเล็กยังใช้ได้)
                $cardId   = strtoupper(preg_replace('/\s+/u', '', (string)$row['card_id']));
                $fullName = trim((string)$row['full_name']);
                if ($cardId !== '') {
                    // บทบาทมากับรายชื่อบัตร (Scenario 05 ⑧): ขอเวลาเพิ่มจนเวลารวมเกินเพดาน รับเฉพาะบัตรสายสโตร์
                    // สายสโตร์ = can_req ระดับ R1–R3 (AST/ST1/ST2/SST) — ADM (R0) มี can_req เพื่อสิทธิ์หน้าเว็บ ไม่นับ
                    $isStore = isTrueFlag($row['can_req'] ?? 0) && (int)($row['level'] ?? 0) >= 1;
                    $role    = trim((string)($row['role_code'] ?? ''));
                    $list[] = ['CardID' => $cardId, 'Fullname' => $fullName, 'Role' => $role, 'Store' => $isStore];
                    $cardMap[$cardId] = $fullName;
                    $roleMap[$cardId] = ['role' => $role, 'store' => $isStore];
                    if ($isStore) $storeCards[] = $cardId;
                }
            }
            // cardList ต้องเป็น JSON object เสมอ (ว่าง = {} ไม่ใช่ []) — board memory ของ Pi
            // timing = ค่าตั้งเวลาหยิบของของไซต์ (ตู้ดึงตอนเปิดเครื่องพร้อมรายชื่อบัตร · ⑧) · settings = ชื่อเดียวกันสำรอง
            $settings = s05GateSettings($pdo, (int)gateProjectIdByCode($pdo, $siteCode));
            $timing   = s05GateTimingOut($settings);   // [2026-10-08] + storeOverCap
            gateJson([
                'success'    => true,
                'cardList'   => (object)$cardMap,
                'data'       => $list,
                'cardRoles'  => (object)$roleMap,
                'storeCards' => $storeCards,
                'timing'     => $timing,
                'settings'   => $timing,
                // [2026-10-02 · GP-46 / GP-17] ประเภทตู้ (มี/ไม่มี CCTV) · บังคับรหัสตรวจสอบ QR แล้วหรือยัง
                'gate'          => gateSecGateInfo($pdo, $siteCode, trim((string)($_GET['gateId'] ?? $_GET['GateID'] ?? ''))),
                'qrRequireCode' => qrRequireCode($pdo),
                'keyBound'      => $gateBound !== null,
            ]);
        } catch (Throwable $err) {
            gateJson(['success' => false, 'message' => $err->getMessage()]);
        }
    }

    // ── GET ?action=getGateSettings&siteCode=ARI — ใหม่ (Scenario 05 ⑧): นาทีต่อรายการ · เพดาน · นาทีต่อการเลื่อน ──
    if ($action === 'getGateSettings') {
        try {
            $siteCode = trim((string)($_GET['siteCode'] ?? $_GET['site'] ?? ''));
            $settings = s05GateSettings($pdo, (int)gateProjectIdByCode($pdo, $siteCode));
            $timing   = s05GateTimingOut($settings);
            gateJson([
                'success'    => true,
                'timing'     => $timing,
                'settings'   => $timing,
                'custom'     => $settings['custom'],
                'serverTime' => date('Y-m-d H:i:s'),
                // [2026-10-02 · GP-46 / GP-17] ประเภทตู้ (มี/ไม่มี CCTV) · บังคับรหัสตรวจสอบ QR แล้วหรือยัง
                'gate'          => gateSecGateInfo($pdo, $siteCode, trim((string)($_GET['gateId'] ?? $_GET['GateID'] ?? ''))),
                'qrRequireCode' => qrRequireCode($pdo),
                'keyBound'      => $gateBound !== null,
            ]);
        } catch (Throwable $err) {
            gateJson(['success' => false, 'message' => $err->getMessage()]);
        }
    }

    // ── GET ?action=checkPickingStatus&pickingId=PK19062601&gateId=G01 ──
    // mirror checkPickingStatus_: ทุกแถวของ picking (กรอง gate ถ้าระบุ) Confirmed ครบหรือยัง
    if ($action === 'checkPickingStatus') {
        try {
            $pickingId = trim((string)($_GET['pickingId'] ?? ''));
            $gateId    = trim((string)($_GET['gateId'] ?? ''));
            if ($pickingId === '') {
                gateJson(['success' => false, 'message' => 'pickingId required']);
            }
            $st = $pdo->prepare(
                'SELECT gl.status, gl.project_id, g.gate_code
                   FROM gate_logs gl
                   LEFT JOIN gates g ON g.id = gl.gate_id
                  WHERE gl.picking_id = ?'
            );
            $st->execute([$pickingId]);
            $total = 0;
            $confirmed = 0;
            $projectId = null;
            while ($row = $st->fetch()) {
                $rowGate = trim((string)($row['gate_code'] ?? ''));
                if ($gateId !== '' && $rowGate !== $gateId) continue;
                $total++;
                if ($projectId === null) $projectId = (int)$row['project_id'];
                if (trim((string)$row['status']) === Gate::ST_CONFIRMED) $confirmed++;
            }
            // สถานะรายใบ (Scenario 05 ① ขั้น 6 — เดิมตอบแค่ยอดรวม): ตู้แสดงตารางใบของรอบ + ALARM 2 แสดงใบที่ยังไม่ยืนยัน
            $rows = s05RoundRows($pdo, $pickingId, $gateId);
            $open = 0;
            $closed = false;
            foreach ($rows as $r) {
                $s = strtolower($r['status']);
                if ($s === 'opened' || $s === 'scanned') $open++;
                if ($s === 'closed') $closed = true;
            }
            gateJson([
                'success'      => true,
                'allConfirmed' => ($total > 0 && $confirmed === $total),
                'total'        => $total,
                'confirmed'    => $confirmed,
                'open'         => $open,
                'closed'       => $closed,
            ] + gateRoundPayload($pdo, $rows, $projectId));
        } catch (Throwable $err) {
            gateJson(['success' => false, 'message' => $err->getMessage()]);
        }
    }

    // ── GET ?action=reconcile — ใหม่ (cron): mirror reconcileGateFinalization ──
    // ไล่ทุกแถว gate_logs ที่ถึงปลายทางของประตูตัวเอง (hardware=Closed / scan-flow=Confirmed)
    // แล้ว re-run finalize เฉพาะใบที่ยังปิดไม่ครบ (สถานะยังไม่ปลายทาง หรือธงตัดสต๊อกยังไม่ครบ)
    if ($action === 'reconcile') {
        try {
            $st = $pdo->prepare(
                'SELECT gl.doc_no, gl.status, gl.project_id, g.gate_code, g.hardware_close
                   FROM gate_logs gl
                   LEFT JOIN gates g ON g.id = gl.gate_id'
            );
            $st->execute();

            // 1) DocID ที่ gate ถึงปลายทาง (mirror ส่วนแรกของ reconcileGateFinalization)
            $terminalDocs = [];
            while ($row = $st->fetch()) {
                $docNo = trim((string)$row['doc_no']);
                if ($docNo === '' || isset($terminalDocs[$docNo])) continue;
                $glStatus = trim((string)$row['status']);
                $scanFlow = gateRowUsesScanFlow($pdo, $row);
                $terminalStatus = $scanFlow ? Gate::ST_CONFIRMED : Gate::ST_CLOSED;
                if ($glStatus === $terminalStatus) {
                    $terminalDocs[$docNo] = true;
                }
            }
            if (!$terminalDocs) {
                gateJson(['success' => true, 'finalized' => 0, 'flagged' => 0,
                          'message' => 'reconcile: ไม่มีเอกสารถึงปลายทาง']);
            }

            // 2) finalize เฉพาะใบที่ยังไม่ปิดครบ (idempotent — finalizeGateDoc มี guard เอง)
            $docSel  = $pdo->prepare('SELECT id, doc_type, status FROM documents WHERE doc_no = ?');
            $flagSel = $pdo->prepare('SELECT COUNT(*) FROM document_items WHERE document_id = ? AND stock_deducted = 0');
            $finalized = 0; $flagged = 0; $errors = 0;

            foreach (array_keys($terminalDocs) as $docNo) {
                // [2026-10-02] ใบย้าย Gate (TG) — ขาเบิกออก/ขานำเข้า ตรวจแยก (lib/gatemove.php)
                if (strncasecmp($docNo, 'TG', 2) === 0) {
                    require_once __DIR__ . '/../lib/gatemove.php';
                    if (gmIsMoveNo($docNo)) {
                        try {
                            if (gmNeedsFinalize($pdo, $docNo)) { finalizeGateDoc($pdo, $docNo); $finalized++; }
                        } catch (Throwable $e) {
                            $errors++;
                            error_log('reconcile TG ' . $docNo . ': ' . $e->getMessage());
                        }
                        continue;
                    }
                }
                $isReturnLeg = str_ends_with($docNo, 'RT'); // GAS ใช้ /RT$/ ตัวใหญ่
                $lookupNo    = $isReturnLeg ? substr($docNo, 0, -2) : $docNo;
                $docSel->execute([$lookupNo]);
                $doc = $docSel->fetch();
                if (!$doc) continue; // mirror: no rows matched DocID — เงียบ
                $statusRaw = trim((string)$doc['status']);
                $statusL   = mb_strtolower($statusRaw, 'UTF-8');

                if ($isReturnLeg) {
                    if ((string)$doc['doc_type'] !== Doc::TYPE_BD) continue;
                    if (strpos($statusL, 'cancel') !== false || strpos($statusL, 'reject') !== false) continue;
                    $needsWork = (strpos($statusL, 'returned') === false);
                    $flagOnly  = false;
                } else {
                    // ใบตาย: cancelled/rejected/returned (รวม Sent Return) ห้าม finalize
                    if (strpos($statusL, 'cancel') !== false || strpos($statusL, 'reject') !== false
                        || strpos($statusL, 'return') !== false) continue;
                    $closedStatus = ((string)$doc['doc_type'] === Doc::TYPE_BD) ? Doc::ST_BORROWED : Doc::ST_COMPLETED;
                    if ((string)$doc['doc_type'] === 'SC' && eqUser($statusRaw, $closedStatus)) {
                        continue;   // ใบนับสต๊อกปิดแล้ว — ไม่มีการตัดสต๊อก ไม่ต้องเติมธง (2026-09-29)
                    }
                    if (eqUser($statusRaw, $closedStatus)) {
                        // ปิดแล้ว — เหลือแค่ธงตัดสต๊อกที่ยังไม่ TRUE (mirror "เติมธง" ของ GAS)
                        $flagSel->execute([(int)$doc['id']]);
                        $needsWork = ((int)$flagSel->fetchColumn() > 0);
                        $flagOnly  = true;
                    } else {
                        $needsWork = true;
                        $flagOnly  = false;
                    }
                }
                if (!$needsWork) continue;
                try {
                    finalizeGateDoc($pdo, $docNo);
                    if ($flagOnly) { $flagged++; } else { $finalized++; }
                } catch (Throwable $e) {
                    $errors++;
                    error_log('reconcile finalizeGateDoc ' . $docNo . ': ' . $e->getMessage());
                }
            }
            gateJson([
                'success'   => true,
                'finalized' => $finalized,
                'flagged'   => $flagged,
                'errors'    => $errors,
                'message'   => 'reconcile: finalize ' . $finalized . ' ใบ, เติมธง ' . $flagged . ' ใบ',
            ]);
        } catch (Throwable $err) {
            gateJson(['success' => false, 'message' => $err->getMessage()]);
        }
    }

    // ── LEGACY: GET ?docID=RD130526G01 — poll สถานะ (plain text ตาม GAS) ──
    if (isset($_GET['docID']) && (string)$_GET['docID'] !== '') {
        try {
            $st = $pdo->prepare('SELECT status FROM gate_logs WHERE doc_no = ? ORDER BY id LIMIT 1');
            $st->execute([(string)$_GET['docID']]);
            $status = $st->fetchColumn();
            if ($status === false) {
                gateText('Not Found');
            }
            gateText((string)$status);
        } catch (Throwable $err) {
            gateText('Error: ' . $err->getMessage());
        }
    }

    // GAS เสิร์ฟ SPA สำหรับ GET อื่น — ที่นี่เป็น endpoint อุปกรณ์ล้วน จึงตอบ error
    gateJson(['success' => false, 'message' => 'Missing docID or action']);
}

// =========================================================================
// POST — mirror doPost
// =========================================================================
if ($method !== 'POST') {
    gateJson(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    $action = mb_strtolower(trim((string)($body['action'] ?? $body['Action'] ?? '')), 'UTF-8');

    // [2026-10-02 · GP-22] ตู้ที่ยังใช้ key กลาง: คำสั่งที่เปิด/เพิ่ม/ปิดรอบต้องมีรหัส G (+ ไซต์ สำหรับเปิด/เพิ่มใบ)
    //   เดิมไม่ใส่ GateID = ข้ามการตรวจ G · closeGate ไม่ใส่ gateId = ปิดทุก G ของรอบนั้น · ตู้ที่มี key ของตัวเองได้รหัสจาก key อยู่แล้ว
    if ($gateBound === null && in_array($action, ['submitpickinglist', 'submitpicking', 'addtopicking', 'addpicking', 'addpickinglist',
                                                  'closegate', 'close', 'logroundevent', 'roundevent', 'logtimer', 'heartbeat'], true)) {
        $cmdGate = trim((string)($body['GateID'] ?? $body['gateId'] ?? $body['gateID'] ?? ''));
        $cmdSite = trim((string)($body['SiteCode'] ?? $body['siteCode'] ?? ''));
        $needSite = in_array($action, ['submitpickinglist', 'submitpicking', 'addtopicking', 'addpicking', 'addpickinglist', 'heartbeat'], true);
        if ($cmdGate === '' || ($needSite && $cmdSite === '')) {
            gateJson(['success' => false, 'updated' => 0,
                      'message' => 'ต้องระบุรหัสประตู (GateID)' . ($needSite ? ' และรหัสไซต์ (SiteCode)' : '') . ' — ตู้ที่ยังใช้ key กลางต้องส่งทุกคำสั่ง']);
        }
    }

    // ── [2026-10-02 · GP-30/31/32/33 · AL-27] สัญญาณชีพตู้ทุก 1 นาที (+ สถานะกล้อง/ตัวตรวจจับ/หัวอ่าน) ──
    // body: SiteCode, GateID (ตู้ที่มี key ของตัวเองได้จาก key), version, screen, pickingId, devices{camera,detector,reader,lock}, alarms[]
    // ตอบตู้ทันที (ค่าตั้งล่าสุด: ประเภทตู้ · บังคับรหัส QR) แล้วรันงานตั้งเวลาต่อ (ตู้ออฟไลน์ · reconcile · …) — lib/jobs.php
    if ($action === 'heartbeat') {
        $hbSite = trim((string)($body['SiteCode'] ?? $body['siteCode'] ?? ''));
        $hbGate = trim((string)($body['GateID'] ?? $body['gateId'] ?? ''));
        $hb = ghHeartbeat($pdo, $hbSite, $hbGate, $body);
        if (!$hb['ok']) {
            gateJson(['success' => false, 'message' => $hb['message']]);
        }
        cnxRespondThenContinue(['success' => true, 'serverTime' => date('Y-m-d H:i:s'), 'qrRequireCode' => qrRequireCode($pdo),
                                'gate' => gateSecGateInfo($pdo, $hbSite, $hbGate), 'keyBound' => $gateBound !== null]);
        try { cnxJobsMaybeRun($pdo, 'full'); } catch (Throwable $e) { error_log('heartbeat jobs: ' . $e->getMessage()); }
        exit;
    }

    // ── card tap → PickingList → PickingID + Opened/Scanned — mirror submitPickingList_ ──
    if ($action === 'submitpickinglist' || $action === 'submitpicking') {
        $siteCode    = trim((string)($body['SiteCode'] ?? $body['siteCode'] ?? ''));
        $gateId      = trim((string)($body['GateID'] ?? $body['gateId'] ?? ''));
        $cardId      = trim((string)($body['CardID'] ?? $body['cardId'] ?? '')); // Pi เขียนบัตรที่แตะ (schema card_id)
        $pickingList = $body['PickingList'] ?? $body['pickingList'] ?? ($body['docIds'] ?? []);
        if (!is_array($pickingList)) $pickingList = [];
        if (!count($pickingList)) {
            gateJson(['success' => false, 'message' => 'PickingList is empty']);
        }

        $docSet = gateDocSet($pickingList);
        // ใบนับสต๊อก (SC) มากับใบอื่น → ข้ามใบนับ เปิดรอบให้ใบอื่นตามปกติ (2026-09-29)
        list($docSet, $scRejected, $scSkipped) = gateSplitStockCount($docSet);
        // [2026-10-02 · GP-17/GP-21] ใบต้องเป็นของไซต์ตู้ + รหัสตรวจสอบ QR (ตู้รุ่นใหม่ส่ง Codes) — lib/gate_sec.php
        list($docSet, $secRejected, $secSkipped, $secLogs) = gateSecFilterDocs($pdo, $docSet, $siteCode, gateSecCodes($body), $cardId);

        // ---- ประตูต้องตรงกับใบ — [PHP port 2026-09-25 per-gate · มติ 51] ----
        // ใบที่ออกให้ G03 มาแตะบัตรที่ G01 → ไม่เปิด (ของถูกจอง/ตัดที่ G03) · ปิดกติกาได้ด้วย
        // settings 'gate_strict_match' => false (permissive เดิมของ GAS) · กติกาการรับใบอื่นดู gateAcceptDocs()
        $strictGate = !isset($APP_SETTINGS['gate_strict_match']) || isTrueFlag($APP_SETTINGS['gate_strict_match']);

        $pdo->beginTransaction();
        try {
            // PKddmmyyXX — รันรายวัน (แทนการสแกนชีตของ GAS ด้วย doc_counters FOR UPDATE)
            $pickingId = nextPickingId($pdo);
            $res = gateAcceptDocs($pdo, $docSet, $gateId, $pickingId, $cardId, $strictGate);
            if ($res['accepted']) {
                $pdo->commit();
            } else {
                // ไม่มีใบที่รับได้เลย → ตอบให้ตู้ไม่เปิด และไม่เปลืองเลขรอบ (Scenario 05 กติกาการรับใบ)
                $pdo->rollBack();
                $pickingId = '';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        if ($secRejected) {   // [2026-10-02 · GP-17/GP-21]
            $res['rejected'] = array_merge($res['rejected'], $secRejected);
            $res['skipped']  = array_merge($res['skipped'], $secSkipped);
            $res['logs']     = array_merge($res['logs'], $secLogs);
        }
        if ($scRejected) {
            $res['rejected'] = array_merge($res['rejected'], $scRejected);
            $res['skipped']  = array_merge($res['skipped'], $scSkipped);
            $res['logs'][]   = 'submitPickingList: ใบนับสต๊อกต้องเปิดประตูแยกรอบ — ข้าม ' . implode(', ', array_column($scRejected, 'docId'));
        }
        foreach ($res['logs'] as $msg) {
            gateLogError($pdo, $siteCode, $gateId, $msg);
        }

        $rows = $pickingId !== '' ? s05RoundRows($pdo, $pickingId, $gateId) : [];
        $projectId = gateProjectIdByCode($pdo, $siteCode);
        gateJson([
            'success'   => true,
            'pickingId' => $pickingId,
            'updated'   => count($res['accepted']),
            'notFound'  => $res['notFound'],
            'skipped'   => $res['skipped'],   // Pi พิมพ์ WARNING · updated = 0 → ไม่เปิดประตู
            'wrongGate' => $res['wrongGate'],
            'unmatched' => $res['notFound'],
            'rejected'  => $res['rejected'],
            'message'   => $res['accepted'] ? '' : 'ไม่มีใบที่รอสแกนของประตูนี้ — ประตูไม่เปิด',
        ] + gateRoundPayload($pdo, $rows, $projectId));
    }

    // ── เพิ่มใบเข้ารอบเดิม (ปุ่ม "เพิ่มใบเบิก" บนหน้า "ประตูเปิด") — ใหม่ Scenario 05 ⑥ ──
    // body: {pickingId, GateID, SiteCode, CardID (บัตรที่แตะยืนยัน — คนเดิมหรือคนใหม่), PickingList[]}
    // ไม่ออกเลขรอบใหม่ · ใบที่เพิ่ม = Opened + เลขรอบเดิม + บัตรที่แตะ (ใบเดิมคงบัตรคนแรก)
    // ปฏิเสธเมื่อรอบปิดแล้ว หรือทุกใบในรอบยืนยันครบแล้ว (ตู้อยู่หน้า "กรุณาปิดประตู") · กติกาการรับใบเหมือนตอนเริ่มรอบ
    if ($action === 'addtopicking' || $action === 'addpicking' || $action === 'addpickinglist') {
        $siteCode    = trim((string)($body['SiteCode'] ?? $body['siteCode'] ?? ''));
        $gateId      = trim((string)($body['GateID'] ?? $body['gateId'] ?? ''));
        $cardId      = trim((string)($body['CardID'] ?? $body['cardId'] ?? ''));
        $pickingId   = trim((string)($body['pickingId'] ?? $body['PickingID'] ?? ''));
        $docSet      = gateDocSet($body['PickingList'] ?? $body['pickingList'] ?? ($body['docIds'] ?? []));
        if ($pickingId === '') {
            gateJson(['success' => false, 'message' => 'pickingId required', 'updated' => 0]);
        }
        if (!$docSet) {
            gateJson(['success' => false, 'message' => 'PickingList is empty', 'updated' => 0]);
        }
        $strictGate = !isset($APP_SETTINGS['gate_strict_match']) || isTrueFlag($APP_SETTINGS['gate_strict_match']);

        // [2026-09-29] ใบนับสต๊อก (SC) ไม่เพิ่มเข้ารอบใคร และรอบนับสต๊อกไม่รับใบเพิ่ม
        $scAdd = [];
        foreach ($docSet as $d) { if (gateIsStockCountNo($d)) { $scAdd[] = $d; } }
        if ($scAdd) {
            $docSet = array_values(array_diff($docSet, $scAdd));
        }
        // [2026-10-02 · GP-17/GP-21] ใบต้องเป็นของไซต์ตู้ + รหัสตรวจสอบ QR
        list($docSet, $secRejected, $secSkipped, $secLogs) = gateSecFilterDocs($pdo, $docSet, $siteCode, gateSecCodes($body), $cardId);
        if (!$docSet && $secRejected && !$scAdd) {
            foreach ($secLogs as $m) { gateLogError($pdo, $siteCode, $gateId, $m); }
            gateJson(['success' => false, 'message' => $secRejected[0]['reason'], 'pickingId' => $pickingId,
                      'updated' => 0, 'added' => [], 'skipped' => $secSkipped, 'rejected' => $secRejected]);
        }

        $pdo->beginTransaction();
        try {
            $roundRows = s05RoundRows($pdo, $pickingId, $gateId, true);
            $state = gateRoundState($roundRows);
            $roundIsCount = false;
            foreach ($roundRows as $rr) {
                if ($rr['docType'] === 'SC' || gateIsStockCountNo($rr['docNo'])) { $roundIsCount = true; break; }
            }
            if ($roundIsCount || !$docSet) {
                $pdo->rollBack();
                $why = $roundIsCount
                    ? 'รอบ ' . $pickingId . ' เป็นรอบนับสต๊อก — เพิ่มใบเข้ารอบไม่ได้ (ปิดประตูแล้วแตะบัตรใหม่)'
                    : 'ใบนับสต๊อกต้องแตะบัตรเปิดประตูแยกรอบ — เพิ่มเข้ารอบเดิมไม่ได้';
                gateLogError($pdo, $siteCode, $gateId, 'addToPicking rejected PickingID=' . $pickingId
                    . ' docs=' . implode(',', array_merge($docSet, $scAdd)) . ' — ' . $why . ($cardId !== '' ? ' | card=' . $cardId : ''));
                gateJson(['success' => false, 'message' => $why, 'roundState' => $state, 'pickingId' => $pickingId,
                          'updated' => 0, 'added' => [], 'skipped' => array_merge($docSet, $scAdd)]);
            }
            if ($state !== 'open') {
                $pdo->rollBack();
                $why = [
                    'missing'  => 'ไม่พบรอบ ' . $pickingId . ($gateId !== '' ? ' ที่ประตู ' . $gateId : ''),
                    'closed'   => 'รอบ ' . $pickingId . ' ปิดประตูไปแล้ว — เริ่มรอบใหม่',
                    'complete' => 'ทุกใบในรอบ ' . $pickingId . ' ยืนยันครบแล้ว (รอปิดประตู) — ปิดประตูแล้วเริ่มรอบใหม่',
                ][$state];
                gateLogError($pdo, $siteCode, $gateId, 'addToPicking rejected PickingID=' . $pickingId
                    . ' docs=' . implode(',', $docSet) . ' — ' . $why . ($cardId !== '' ? ' | card=' . $cardId : ''));
                gateJson(['success' => false, 'message' => $why, 'roundState' => $state, 'pickingId' => $pickingId,
                          'updated' => 0, 'added' => [], 'skipped' => $docSet]);
            }
            $res = gateAcceptDocs($pdo, $docSet, $gateId, $pickingId, $cardId, $strictGate);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        if ($scAdd) {
            $whySc = 'ใบนับสต๊อกต้องแตะบัตรเปิดประตูแยกรอบ';
            foreach ($scAdd as $d) {
                $res['rejected'][] = ['docId' => $d, 'reason' => $whySc, 'kind' => 'stockcount'];
                $res['skipped'][]  = $d . ' (' . $whySc . ')';
            }
            $res['logs'][] = 'addToPicking: ' . $whySc . ' — ข้าม ' . implode(', ', $scAdd);
        }
        if ($secRejected) {   // [2026-10-02 · GP-17/GP-21]
            $res['rejected'] = array_merge($res['rejected'], $secRejected);
            $res['skipped']  = array_merge($res['skipped'], $secSkipped);
            $res['logs']     = array_merge($res['logs'], $secLogs);
        }
        foreach ($res['logs'] as $msg) {
            gateLogError($pdo, $siteCode, $gateId, str_replace('submitPickingList:', 'addToPicking:', $msg));
        }
        if ($res['accepted']) {
            $holder = $cardId !== '' ? (s05CardHolders($pdo, [$cardId])[$cardId] ?? '') : '';
            s05ActivityLog($pdo, 'picking', $pickingId, $holder !== '' ? $holder : ('card:' . $cardId), 'gate_add_docs',
                null, json_encode(['gate' => $gateId, 'docs' => $res['accepted'], 'card' => $cardId], JSON_UNESCAPED_UNICODE));
        }
        $rows = s05RoundRows($pdo, $pickingId, $gateId);
        gateJson([
            'success'    => count($res['accepted']) > 0,
            'pickingId'  => $pickingId,
            'roundState' => gateRoundState($rows),
            'updated'    => count($res['accepted']),
            'added'      => $res['accepted'],
            'notFound'   => $res['notFound'],
            'skipped'    => $res['skipped'],
            'wrongGate'  => $res['wrongGate'],
            'rejected'   => $res['rejected'],
            'message'    => $res['accepted'] ? '' : 'ไม่มีใบที่เพิ่มเข้ารอบได้',
        ] + gateRoundPayload($pdo, $rows, gateProjectIdByCode($pdo, $siteCode)));
    }

    // ── gate ล็อกจริง → Closed ต่อ PickingID+GateID — mirror handleGateClosedByPicking_ ──
    // ตัดสต๊อกตามจำนวนหยิบจริง (finalizeGateDoc — Scenario 05 ⑦) · ตู้ส่งสรุปรอบมาด้วยได้ (usedSec · itemCount ·
    // extends · overrunSec · cardholder) → gate_round_events 'round_end' (⑧ ขั้น 5)
    if ($action === 'closegate' || $action === 'close') {
        $pickingId = trim((string)($body['pickingId'] ?? $body['PickingID'] ?? ''));
        $gateId    = trim((string)($body['gateId'] ?? $body['GateID'] ?? ''));
        if ($pickingId === '') {
            gateJson(['success' => false, 'message' => 'pickingId required']);
        }
        // mirror GAS: ชีต GateLogs ว่าง → error
        $cnt = (int)$pdo->query('SELECT COUNT(*) FROM gate_logs')->fetchColumn();
        if ($cnt === 0) {
            gateJson(['success' => false, 'message' => 'GateLogs empty']);
        }

        $closedDocs = [];
        $notConfirmed = [];
        $projectId = null;
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                'SELECT gl.id, gl.doc_no, gl.status, gl.project_id, g.gate_code
                   FROM gate_logs gl
                   LEFT JOIN gates g ON g.id = gl.gate_id
                  WHERE gl.picking_id = ?
                  FOR UPDATE'
            );
            $st->execute([$pickingId]);
            $rows = $st->fetchAll();

            $upd = $pdo->prepare('UPDATE gate_logs SET status = ? WHERE id = ?');
            foreach ($rows as $row) {
                $rowGate = trim((string)($row['gate_code'] ?? ''));
                if ($gateId !== '' && $rowGate !== $gateId) continue;
                if ($projectId === null) $projectId = (int)$row['project_id'];
                $rs = trim((string)$row['status']);
                if ($rs === Gate::ST_OPENED || $rs === Gate::ST_SCANNED) {
                    $notConfirmed[] = trim((string)$row['doc_no']);
                }
                if ($rs !== Gate::ST_CONFIRMED) continue; // เฉพาะ Confirmed
                $upd->execute([Gate::ST_CLOSED, (int)$row['id']]);
                $docNo = trim((string)$row['doc_no']);
                if ($docNo !== '') $closedDocs[] = $docNo;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        // ปิดงาน/ตัดสต๊อกทีละใบ — error ต่อใบไม่ล้มทั้งชุด (mirror try/catch ของ GAS)
        foreach ($closedDocs as $docNo) {
            try {
                finalizeGateDoc($pdo, $docNo);
            } catch (Throwable $e) {
                error_log('handleGateClosed err ' . $docNo . ': ' . $e->getMessage());
            }
        }

        if ($notConfirmed) {
            // ตู้ไม่ควรส่ง closeGate ก่อนยืนยันครบ (กลอนไม่มีไฟจนครบ) — ใบที่ค้างจะปิดงานเองเมื่อถ่ายรูปยืนยัน
            // (ตู้ไม่ส่ง SiteCode มากับ closeGate → จดด้วยโครงการของรอบ ให้ขึ้นใน Error log ของไซต์)
            _stockErrorLog($pdo, $projectId, $gateId !== '' ? $gateId : null, 'closeGate PickingID=' . $pickingId
                . ' ขณะยังมีใบไม่ได้ถ่ายรูปยืนยัน: ' . implode(', ', $notConfirmed)
                . ' — ใบเหล่านี้จะตัดสต๊อกเมื่อบันทึกรูปยืนยัน');
        }
        // สรุปรอบจากตู้ (ถ้าส่งมา — connext_access.py ส่ง usedSec · icCount · pickExtends · closeExtends · overrunSec):
        // เวลาที่ใช้ · จำนวนรายการ · จำนวนครั้งที่เลื่อน · เวลาที่เกิน (⑧ ขั้น 5) → gate_round_events 'round_end'
        $statKeys = ['usedSec', 'icCount', 'itemCount', 'pickExtends', 'closeExtends', 'extends', 'overrunSec'];
        $hasStats = false;
        foreach ($statKeys as $k) { if (isset($body[$k])) { $hasStats = true; break; } }
        if ($hasStats) {
            try {
                $settings = s05GateSettings($pdo, (int)$projectId);
                $usedMin  = round(((float)($body['usedSec'] ?? 0)) / 60, 2);
                $ic       = $body['icCount'] ?? ($body['itemCount'] ?? null);
                $pickExt  = $body['pickExtends'] ?? ($body['extends'] ?? null);
                $pdo->prepare(
                    'INSERT INTO gate_round_events (project_id, gate_code, picking_id, event, item_count, seq, card_id, cardholder, total_min, over_cap, detail)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $projectId, $gateId !== '' ? $gateId : null, $pickingId, 'round_end',
                    is_numeric($ic) ? (int)$ic : null,
                    is_numeric($pickExt) ? (int)$pickExt : null,
                    trim((string)($body['cardId'] ?? $body['CardID'] ?? '')) ?: null,
                    trim((string)($body['cardholder'] ?? '')) ?: null,
                    $usedMin, $usedMin > (float)$settings['pickCapMin'] ? 1 : 0,
                    json_encode(['usedSec' => $body['usedSec'] ?? null, 'overrunSec' => $body['overrunSec'] ?? null,
                                 'pickExtends' => $pickExt, 'closeExtends' => $body['closeExtends'] ?? null,
                                 'closedDocs' => $closedDocs], JSON_UNESCAPED_UNICODE),
                ]);
            } catch (Throwable $e) {
                error_log('closeGate round_end log: ' . $e->getMessage());
            }
        }

        $after = s05RoundRows($pdo, $pickingId, $gateId);
        gateJson(['success' => true, 'closedDocs' => $closedDocs, 'notConfirmed' => $notConfirmed,
                  'docs' => s05RoundDocsOut($pdo, $after)]);
    }

    // ── log ของรอบจากตู้ — ใหม่ Scenario 05 ⑧ ⑨ ──
    // body: {siteCode, gateId, pickingId, event, itemCount, seq (ครั้งที่), cardId, cardholder, totalMin, addMin, overCap, message}
    //   event: pick_timeout (หมดเวลาหยิบของ) · pick_extend (ขอเวลาเพิ่ม +5) · close_timeout (ALARM 3) ·
    //          close_extend (เลื่อนเวลาปิดประตู +5) · round_end (สรุปรอบ) · อื่น ๆ ตามที่ตู้ส่ง
    //   ทุกครั้งที่หมดเวลา/เลื่อน → error_logs (เลขรอบ · จำนวนรายการ · ครั้งที่ · ชื่อผู้แตะบัตร)
    //   เวลารวมเกินเพดาน (overCap หรือ totalMin > เพดาน) → ขึ้นเตือนบน Dashboard (gate_round_events.over_cap)
    if ($action === 'logroundevent' || $action === 'roundevent' || $action === 'logtimer') {
        $siteCode  = trim((string)($body['siteCode'] ?? $body['SiteCode'] ?? ''));
        $gateId    = trim((string)($body['gateId'] ?? $body['GateID'] ?? ''));
        $pickingId = trim((string)($body['pickingId'] ?? $body['PickingID'] ?? ''));
        $event     = strtolower(preg_replace('/[^A-Za-z0-9_]/', '', (string)($body['event'] ?? '')));
        if ($event === '') {
            gateJson(['success' => false, 'message' => 'event required']);
        }
        $event     = substr($event, 0, 30);
        $cardId    = trim((string)($body['cardId'] ?? $body['CardID'] ?? ''));
        $holder    = trim((string)($body['cardholder'] ?? ''));
        if ($holder === '' && $cardId !== '') { $holder = s05CardHolders($pdo, [$cardId])[$cardId] ?? ''; }
        $itemCount = isset($body['itemCount']) && is_numeric($body['itemCount']) ? (int)$body['itemCount'] : null;
        $seq       = isset($body['seq']) && is_numeric($body['seq']) ? (int)$body['seq'] : null;
        $totalMin  = isset($body['totalMin']) && is_numeric($body['totalMin']) ? round((float)$body['totalMin'], 2) : null;
        $addMin    = isset($body['addMin']) && is_numeric($body['addMin']) ? (float)$body['addMin'] : null;
        $projectId = gateProjectIdByCode($pdo, $siteCode);
        if ($projectId === null && $pickingId !== '') {
            $pq = $pdo->prepare('SELECT project_id FROM gate_logs WHERE picking_id = ? LIMIT 1');
            $pq->execute([$pickingId]);
            $pv = $pq->fetchColumn();
            if ($pv !== false) $projectId = (int)$pv;
        }
        $settings = s05GateSettings($pdo, (int)$projectId);
        $overCap  = isTrueFlag($body['overCap'] ?? false) || ($totalMin !== null && $totalMin > (float)$settings['pickCapMin'] + 0.001);
        $note     = trim((string)($body['message'] ?? ''));

        $labels = [
            'pick_timeout'  => 'Pick time expired',
            'pick_extend'   => 'Pick time extended',
            'close_timeout' => 'Close-door timeout',
            'close_extend'  => 'Close-door time extended',
            'round_end'     => 'Round finished',
        ];
        $msg = ($labels[$event] ?? ('Round event ' . $event))
             . ($addMin !== null ? ' +' . s05Num($addMin) . 'm' : '')
             . ($seq !== null ? ' (#' . $seq . ')' : '')
             . ' PickingID=' . ($pickingId !== '' ? $pickingId : '-')
             . ($itemCount !== null ? ' items=' . $itemCount : '')
             . ($totalMin !== null ? ' total=' . s05Num($totalMin) . 'm' : '')
             . ($overCap ? ' OVER-CAP(' . (int)$settings['pickCapMin'] . 'm) เกินเพดานเวลา' : '')
             . ($note !== '' ? ' — ' . $note : '')
             . ($cardId !== '' ? ' card=' . $cardId : '')
             . ($holder !== '' ? ' | cardholder=' . $holder : '');
        try {
            $pdo->prepare(
                'INSERT INTO gate_round_events (project_id, gate_code, picking_id, event, item_count, seq, card_id, cardholder, total_min, over_cap, detail)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$projectId, $gateId !== '' ? $gateId : null, $pickingId !== '' ? $pickingId : null, $event,
                        $itemCount, $seq, $cardId !== '' ? $cardId : null, $holder !== '' ? $holder : null,
                        $totalMin, $overCap ? 1 : 0, $note !== '' ? $note : null]);
        } catch (Throwable $e) {
            error_log('logRoundEvent insert: ' . $e->getMessage());
        }
        // สรุปรอบปกติไม่ใช่ error — ลง error_logs เฉพาะหมดเวลา/เลื่อน/เกินเพดาน/อื่น ๆ (โครงการ = ไซต์ที่ส่งมา หรือของรอบ)
        if ($event !== 'round_end' || $overCap) {
            _stockErrorLog($pdo, $projectId, $gateId !== '' ? $gateId : null, $msg);
        }
        gateJson(['success' => true, 'overCap' => $overCap, 'message' => $msg]);
    }

    // ── controller log error — mirror doPost action=logError → logError_ ──
    if ($action === 'logerror' || $action === 'error') {
        gateLogError(
            $pdo,
            trim((string)($body['siteCode'] ?? $body['SiteCode'] ?? ($body['site'] ?? ''))),
            trim((string)($body['gateId'] ?? $body['GateID'] ?? '')),
            trim((string)($body['message'] ?? $body['Message'] ?? ''))
        );
        gateJson(['success' => true]);
    }

    // ── LEGACY: single-doc status update (backward-compatible) ──
    $searchDocID = trim((string)($body['docID'] ?? $body['DocID'] ?? ($body['docId'] ?? '')));
    $newStatus   = trim((string)($body['status'] ?? $body['Status'] ?? ($body['newStatus'] ?? 'Opened')));
    if ($newStatus === '') $newStatus = 'Opened'; // mirror default 'Opened'
    if ($searchDocID === '') {
        gateJson(['success' => false, 'message' => 'Missing docID or action']);
    }

    $updated = 0;
    $refused = [];
    $wroteScanFlow = false;
    // ใบไร้ประตูที่เพิ่งถูกเขียนเป็น Confirmed — ไม่มี hardware มาสั่งปิดให้ จึงต้อง
    // finalize ตรงนี้เอง (รักษาพฤติกรรมเดิมของ legacy Closed → ตัดยอดทันที)
    $glessConfirmed = false;
    // Scenario 05: ใบที่แตะบัตรแล้วเอาออกจากรอบ/ย้อนกลับไม่ได้ · Confirmed ตั้งได้จากหน้าถ่ายรูปยืนยันเท่านั้น
    // (ต้องมีรูปครบทุกรายการ) · Closed (ตัดสต๊อก) ได้เฉพาะใบที่ Confirmed แล้ว · Closed/Cancelled = ปลายทาง
    $rank = function (string $s): int {
        $s = strtolower(trim($s));
        if ($s === 'awaiting') return 0;
        if ($s === 'opened' || $s === 'scanned') return 1;
        if ($s === 'confirmed') return 2;
        if ($s === 'closed') return 3;
        if ($s === 'cancelled') return 9;
        return -1;
    };
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'SELECT gl.id, gl.doc_no, gl.project_id, gl.status, g.gate_code, g.hardware_close, d.doc_type
               FROM gate_logs gl
               LEFT JOIN gates g ON g.id = gl.gate_id
               LEFT JOIN documents d ON d.id = gl.document_id
              WHERE gl.doc_no = ?
              FOR UPDATE'
        );
        $st->execute([$searchDocID]);
        $rows = $st->fetchAll();

        $upd = $pdo->prepare('UPDATE gate_logs SET status = ? WHERE id = ?');
        foreach ($rows as $row) {
            $curR = $rank((string)$row['status']);
            $newR = $rank($newStatus);
            $why  = '';
            if ($curR >= 3) {
                $why = 'ใบอยู่ในสถานะ ' . $row['status'] . ' แล้ว (ปลายทาง)';
            } elseif ($newR === 0 && $curR >= 1) {
                $why = 'ใบที่แตะบัตรแล้วเอาออกจากรอบไม่ได้';
            } elseif ($newR === 2) {
                $why = 'ยืนยันใบได้จากหน้า "ถ่ายรูปยืนยัน" เท่านั้น (ต้องมีรูปครบทุกรายการ)';
            } elseif ($newR === 3 && $curR !== 2) {
                $why = 'ปิดงาน/ตัดสต๊อกได้เฉพาะใบที่ถ่ายรูปยืนยันแล้ว';
            } elseif ($newR === 1 && $curR === 2) {
                $why = 'ใบยืนยันแล้ว ย้อนกลับไม่ได้';
            } elseif ($newR === 1 && $curR === 0 && (string)($row['doc_type'] ?? '') === Doc::TYPE_IN
                      && trim((string)($row['gate_code'] ?? '')) === '') {
                // [2026-10-06] ใบ IN ไม่มี G ในใบ — ต้องเปิดผ่าน submitPickingList (รู้ G ของตู้ → ยอดขึ้นที่ G นั้น)
                $why = 'ใบรับเข้ายังไม่รู้ประตู — ต้องสแกนที่ตู้ G ของไซต์ (ยอดขึ้นที่ G ที่สแกน)';
            }
            if ($why !== '') {
                $refused[] = trim((string)$row['doc_no']) . ' (' . $why . ')';
                $refusedProject = (int)$row['project_id'];
                $refusedGate    = trim((string)($row['gate_code'] ?? ''));
                continue;
            }
            // ประตู scan-flow: "Opened" → "Scanned" และไม่รับ "Closed" (จบที่ Confirmed)
            $statusToSet = $newStatus;
            $gateless = trim((string)($row['gate_code'] ?? '')) === ''
                     && gateCodeFromDocNo((string)($row['doc_no'] ?? '')) === '';
            if (gateRowUsesScanFlow($pdo, $row)) {
                if ($newStatus === Gate::ST_OPENED) $statusToSet = Gate::ST_SCANNED;
                if ($newStatus === Gate::ST_CLOSED) $statusToSet = Gate::ST_CONFIRMED;
                $wroteScanFlow = true;
                if ($gateless && $newStatus === Gate::ST_CLOSED) { $glessConfirmed = true; }
            }
            $upd->execute([$statusToSet, (int)$row['id']]);
            $updated++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // Closed → ปิดงาน/ตัดยอด เฉพาะประตูที่มีตัวล็อก — ประตู scan-flow ปิดงานตอน
    // Confirmed (ขั้นถ่ายรูปยืนยัน) แทน ไม่ทำซ้ำที่นี่ (mirror doPost)
    if ($updated > 0 && $newStatus === Gate::ST_CLOSED && (!$wroteScanFlow || $glessConfirmed)) {
        try {
            finalizeGateDoc($pdo, $searchDocID);
        } catch (Throwable $e) {
            // GAS handleGateClosed_ กลืน error ภายใน — คงพฤติกรรม (ยังตอบ success)
            error_log('handleGateClosed err ' . $searchDocID . ': ' . $e->getMessage());
        }
    }

    if ($refused) {
        _stockErrorLog($pdo, $refusedProject ?? null, ($refusedGate ?? '') !== '' ? $refusedGate : null,
            'legacy status ' . $newStatus . ' ถูกปฏิเสธ: ' . implode(', ', $refused));
    }
    gateJson(['success' => $updated > 0 || !$refused, 'updated' => $updated, 'refused' => $refused]);

} catch (Throwable $err) {
    error_log('gate.php doPost: ' . $err->getMessage());
    gateJson(['success' => false, 'message' => $err->getMessage()]);
}
