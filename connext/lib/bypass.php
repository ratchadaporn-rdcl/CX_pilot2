<?php
/**
 * CONNEXT — lib/bypass.php : คีย์ใบย้อนหลังจากแบบฟอร์มกระดาษ (Scenario 05 ⑩ — หน้า Bypass)
 * [PHP port 2026-09-28]
 *
 * ใช้วันที่ระบบใช้ไม่ได้ทั้งวัน: หน้างานจ่ายของด้วยแบบฟอร์มกระดาษ (ผู้เบิก · ผู้รับ · วัสดุ · จำนวน · G · ลายเซ็น)
 * เมื่อระบบกลับ ADM คีย์ย้อนหลังภายใน 1 วันทำการ → ระบบออกใบ RD / OD / IN สถานะ Completed ที่ G นั้น
 * ตัดหรือเพิ่มสต๊อกทันทีโดยไม่ผ่านประตู · ใบติดป้าย bypass (documents.origin_type = 'bypass') · activity_log
 * ขึ้นในตรวจสอบประจำวัน / Dashboard ตามวันที่จ่ายจริง (doc_ts = วันเวลาที่จ่ายจริง · เลขใบใช้วันที่จ่ายจริง)
 *
 * กติกาเดียวกับใบปกติ: รหัส IC เท่านั้น · RD ไม่มีวัสดุ NAR · OD ใช้กับ NAR เท่านั้น ·
 * RD/OD ยอดที่ G ต้องพอ (คงเหลือ − จอง) — ไม่พอบันทึกได้เมื่อยืนยัน "ตามแบบฟอร์ม" (ยอดปัดที่ 0 + error_logs)
 *
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/docnum.php';
require_once __DIR__ . '/photos.php';
require_once __DIR__ . '/documents.php';   // _docsMaterialsMap · _docsNarRuleError · _docsSubMaps · _docsResolveReceiver
require_once __DIR__ . '/s05.php';
require_once __DIR__ . '/doc_ext.php';     // TD โอนย้ายข้ามไซต์ (2026-09-29)

const BYPASS_ORIGIN    = 'bypass';
const BYPASS_MAX_ITEMS = 30;
const BYPASS_MAX_DAYS  = 60;   // คีย์ย้อนหลังได้ไม่เกิน (กันพิมพ์ปีผิด) — กติกาหน้างานคือภายใน 1 วันทำการ
// รูปสินค้าที่เบิก (2026-09-29): RD/OD/TD บังคับอย่างน้อยรายการละ 1 รูป (กติกาเดียวกับหน้าถ่ายรูปยืนยัน) · IN แนบได้ไม่บังคับ
const BYPASS_ITEM_PHOTO_MAX  = 6;               // ต่อรายการ (หลังรวมบรรทัดรหัสซ้ำ)
const BYPASS_PHOTO_TOTAL_MAX = 60;              // ทั้งใบ
const BYPASS_PHOTO_MAX_BYTES = 8 * 1024 * 1024; // ต่อรูป (หน้าเว็บย่อรูปก่อนส่ง — ปกติ < 200 KB)

/**
 * สร้างใบ bypass
 * $in = [type, project_id, gate_id, dispensed_at ('Y-m-d H:i' / 'Y-m-d\TH:i'), requester (username),
 *        receiver (SubID/ชื่อ/'DC:…'), usage_area, rs_no, note, force(bool),
 *        dest_project_id + dest_contact (TD เท่านั้น — 2026-09-29),
 *        items: [[mat_code, qty, charge(bool), photos[dataUri…]], ...]  — หรือคีย์ mat_code/qty/charge/photos
 *        photos = รูปสินค้าที่เบิกของรายการนั้น (RD/OD/TD บังคับ ≥ 1 · IN ไม่บังคับ) → document_items.photo_url]
 * $photo = รูปแบบฟอร์มกระดาษ ['dataUri' => ...] หรือ ['path' => ไฟล์ชั่วคราวจาก upload] → documents.photo_url
 * @return array ['ok'=>bool, 'error'=>string, 'docNo'=>string, 'warnings'=>[]]
 */
function bypassCreate(PDO $pdo, array $user, array $in, array $photo): array {
    $type = strtoupper(trim((string)($in['type'] ?? '')));
    if (!in_array($type, [Doc::TYPE_RD, Doc::TYPE_OD, Doc::TYPE_IN, DOC_TYPE_TD], true)) {
        return ['ok' => false, 'error' => 'เลือกชนิดใบ: RD เบิกวัสดุหลัก · OD เบิกเบ็ดเตล็ด · TD โอนย้ายข้ามไซต์ · IN รับเข้าคลัง'];
    }
    $isIn = $type === Doc::TYPE_IN;
    $isTd = $type === DOC_TYPE_TD;

    // ---- โครงการ + ประตู ----
    $projectId = (int)($in['project_id'] ?? 0);
    $st = $pdo->prepare("SELECT id, code FROM projects WHERE id = ? AND status = 'active'");
    $st->execute([$projectId]);
    $proj = $st->fetch();
    if (!$proj) { return ['ok' => false, 'error' => 'ไม่พบโครงการ']; }
    $gateId = (int)($in['gate_id'] ?? 0);
    $st = $pdo->prepare("SELECT id, gate_code FROM gates WHERE id = ? AND project_id = ? AND status = 'active'");
    $st->execute([$gateId, $projectId]);
    $gate = $st->fetch();
    if (!$gate) { return ['ok' => false, 'error' => 'เลือกประตู (G) ของโครงการนี้ที่จ่ายของจริง']; }
    $gateCode = strtoupper(trim((string)$gate['gate_code']));

    // ---- วันเวลาที่จ่ายจริง ----
    $raw = str_replace('T', ' ', trim((string)($in['dispensed_at'] ?? '')));
    $dt  = DateTime::createFromFormat('Y-m-d H:i', substr($raw, 0, 16));
    if (!$dt) { return ['ok' => false, 'error' => 'ใส่วันเวลาที่จ่ายของจริงตามแบบฟอร์ม']; }
    $now = nowBkk();
    if ($dt > (clone $now)->modify('+5 minutes')) { return ['ok' => false, 'error' => 'วันเวลาที่จ่ายจริงอยู่ในอนาคต']; }
    if ($dt < (clone $now)->modify('-' . BYPASS_MAX_DAYS . ' days')) {
        return ['ok' => false, 'error' => 'วันที่จ่ายเก่าเกิน ' . BYPASS_MAX_DAYS . ' วัน — ตรวจปีในแบบฟอร์มอีกครั้ง'];
    }

    // ---- ผู้เบิก / ผู้รับ ----
    $requester = trim((string)($in['requester'] ?? ''));
    $st = $pdo->prepare('SELECT username FROM users WHERE username = ?');
    $st->execute([$requester]);
    $reqUser = $st->fetchColumn();
    if ($reqUser === false) { return ['ok' => false, 'error' => 'เลือกผู้เบิก/ผู้รับเข้าตามแบบฟอร์ม']; }
    $dest = null;
    $destContact = '';
    if ($isTd) {
        // TD (แบบฟอร์มโอนย้าย): ไซต์ปลายทาง + ผู้รับที่ปลายทาง แทนผู้รับเหมา — ต้นทางตัดอย่างเดียว (ปลายทางคีย์ IN เอง)
        $dest = docExtProjectById($pdo, (int)($in['dest_project_id'] ?? 0));
        if (!$dest || strtolower($dest['status']) !== 'active') { return ['ok' => false, 'error' => 'เลือกไซต์ปลายทางตามแบบฟอร์มโอนย้าย']; }
        if ($dest['id'] === $projectId) { return ['ok' => false, 'error' => 'ไซต์ปลายทางต้องไม่ใช่ไซต์เดียวกับต้นทาง']; }
        $destContact = trim(mb_substr((string)($in['dest_contact'] ?? ''), 0, 150, 'UTF-8'));
        if ($destContact === '') { return ['ok' => false, 'error' => 'ใส่ผู้รับของที่ไซต์ปลายทางตามแบบฟอร์ม']; }
        list($receiverName, $receiverSubId) = ['โอนไป ' . $dest['code'], null];
    } else {
        $receiverRaw = trim((string)($in['receiver'] ?? ''));
        if (!$isIn && $receiverRaw === '') { return ['ok' => false, 'error' => 'ใส่ผู้รับของตามแบบฟอร์ม']; }
        list($receiverName, $receiverSubId) = $isIn ? [null, null] : _docsResolveReceiver($receiverRaw, _docsSubMaps($pdo));
    }

    // ---- รายการ ----
    $rows = [];
    foreach ((array)($in['items'] ?? []) as $it) {
        $mc = strtoupper(trim((string)($it['mat_code'] ?? $it[0] ?? '')));
        $q  = trim((string)($it['qty'] ?? $it[1] ?? ''));
        if ($mc === '' && $q === '') { continue; }
        if ($mc === '' || !is_numeric($q) || (float)$q <= 0) {
            return ['ok' => false, 'error' => 'รายการ ' . ($mc !== '' ? $mc : '(ไม่มีรหัส)') . ': ใส่รหัส IC และจำนวนมากกว่า 0'];
        }
        $ph = $it['photos'] ?? ($it[3] ?? []);
        $ph = array_values(array_filter(is_array($ph) ? $ph : [], function ($u) { return is_string($u) && $u !== ''; }));
        $rows[] = ['MatCode' => $mc, 'Qty' => round((float)$q, 3), 'Charge' => !empty($it['charge'] ?? ($it[2] ?? false)), 'Photos' => $ph];
    }
    if (!$rows) { return ['ok' => false, 'error' => 'ใส่รายการวัสดุอย่างน้อย 1 รายการ']; }
    if (count($rows) > BYPASS_MAX_ITEMS) { return ['ok' => false, 'error' => 'ใบเดียวได้ไม่เกิน ' . BYPASS_MAX_ITEMS . ' รายการ — แยกเป็นหลายใบ']; }
    $matMap = _docsMaterialsMap($pdo, array_column($rows, 'MatCode'));
    $icErr  = _docsIcOnlyError($pdo, $rows, $matMap);
    if ($icErr !== null) { return ['ok' => false, 'error' => $icErr]; }
    if (!$isIn && !$isTd) {   // TD โอนได้ทุกชนิดรวม WMS (ตามแท็บโอนย้าย)
        $narErr = _docsNarRuleError($rows, $type, $matMap);
        if ($narErr !== null) { return ['ok' => false, 'error' => $narErr]; }
    }
    // รหัสซ้ำในใบเดียว → รวมจำนวน + รวมรูป (แบบฟอร์มกระดาษมักเขียนแยกบรรทัด)
    $merged = [];
    foreach ($rows as $r) {
        $k = $r['MatCode'];
        if (!isset($merged[$k])) { $merged[$k] = $r; }
        else {
            $merged[$k]['Qty'] += $r['Qty'];
            $merged[$k]['Charge'] = $merged[$k]['Charge'] || $r['Charge'];
            $merged[$k]['Photos'] = array_merge($merged[$k]['Photos'], $r['Photos']);
        }
    }
    $rows = array_values($merged);

    // ---- รูปสินค้าที่เบิก (รายรายการ) — ตรวจก่อนเขียนอะไร ----
    $noPhoto = [];
    $tooMany = [];
    $total   = 0;
    foreach ($rows as $r) {
        $n = count($r['Photos']);
        $total += $n;
        if (!$isIn && $n === 0) { $noPhoto[] = $r['MatCode']; }
        if ($n > BYPASS_ITEM_PHOTO_MAX) { $tooMany[] = $r['MatCode'] . ' (' . $n . ' รูป)'; }
        foreach ($r['Photos'] as $u) {
            if (strlen($u) > BYPASS_PHOTO_MAX_BYTES * 4 / 3 + 100) {
                return ['ok' => false, 'error' => 'รูปสินค้าของ ' . $r['MatCode'] . ' ใหญ่เกิน ' . (int)(BYPASS_PHOTO_MAX_BYTES / 1048576) . ' MB — ถ่าย/เลือกใหม่'];
            }
        }
    }
    if ($noPhoto) {
        return ['ok' => false, 'error' => 'แนบรูปสินค้าที่เบิกอย่างน้อยรายการละ 1 รูป — ยังไม่มีรูป: ' . implode(', ', $noPhoto)];
    }
    if ($tooMany) {
        return ['ok' => false, 'error' => 'รูปสินค้าได้ไม่เกินรายการละ ' . BYPASS_ITEM_PHOTO_MAX . ' รูป — ' . implode(', ', $tooMany)];
    }
    if ($total > BYPASS_PHOTO_TOTAL_MAX) {
        return ['ok' => false, 'error' => 'รูปสินค้ารวมได้ไม่เกิน ' . BYPASS_PHOTO_TOTAL_MAX . ' รูปต่อใบ (ตอนนี้ ' . $total . ' รูป) — แยกเป็นหลายใบ'];
    }

    // ---- รูปแบบฟอร์ม (บังคับ) ----
    $dataUri = (string)($photo['dataUri'] ?? '');
    if ($dataUri === '' && !empty($photo['path']) && is_file((string)$photo['path'])) {
        $bytes = (string)file_get_contents((string)$photo['path']);
        if (strlen($bytes) > 12 * 1024 * 1024) { return ['ok' => false, 'error' => 'รูปแบบฟอร์มใหญ่เกิน 12 MB']; }
        $mime = _photoSniffMime($bytes) ?? 'application/octet-stream';
        $dataUri = 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }
    if ($dataUri === '') { return ['ok' => false, 'error' => 'แนบรูปแบบฟอร์มกระดาษ (มีลายเซ็น)']; }

    $force    = !empty($in['force']);
    $actor    = trim((string)($user['username'] ?? ''));
    $note     = trim((string)($in['note'] ?? ''));
    $warnings = [];

    $photoRel = null;
    $itemPhotoRels = [];   // ไฟล์รูปสินค้าที่เซฟแล้ว — ลบทิ้งถ้าบันทึกไม่สำเร็จ
    $root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__) . '/';
    $pdo->beginTransaction();
    try {
        // ---- ยอดที่ G (เบิก) — ไม่พอ = ต้องยืนยันตามแบบฟอร์ม ----
        if (!$isIn) {
            $short = [];
            foreach ($rows as $r) {
                $mid = (int)$matMap[$r['MatCode']]['id'];
                $ga  = stockGateAvail($pdo, $projectId, $mid, $gateCode, true);
                $avail = $ga['found'] ? $ga['avail'] : 0.0;
                if ($r['Qty'] > $avail + 0.0005) {
                    $short[] = $r['MatCode'] . ' (จ่าย ' . s05Num($r['Qty']) . ' พร้อมเบิกที่ ' . $gateCode . ' ' . s05Num(max(0, $avail)) . ')';
                }
            }
            if ($short && !$force) {
                $pdo->rollBack();
                return ['ok' => false, 'needForce' => true,
                        'error' => 'ยอดในระบบที่ประตู ' . $gateCode . ' ไม่พอ — ' . implode(', ', $short)
                                 . "\nถ้าแบบฟอร์มถูกต้อง (ของมีจริงแต่ยอดในระบบเพี้ยน) ให้ติ๊ก \"บันทึกตามแบบฟอร์ม\" แล้วบันทึกอีกครั้ง"];
            }
            if ($short) { $warnings[] = 'ยอดในระบบไม่พอ: ' . implode(', ', $short) . ' — ยอดที่ขาดถูกปัดเป็น 0 และจดใน Error log'; }
        }

        $photoRel = savePhotoDataUri($dataUri, 'BYPASS_' . $type);
        if ($photoRel === null) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'ไฟล์รูปแบบฟอร์มใช้ไม่ได้ (รองรับ JPG / PNG / WEBP)'];
        }

        // เลขใบ = ชนิด + วันที่จ่ายจริง (DDMMYY) + เลขรันของวันนั้น + รหัส G
        //   [2026-10-06] ใบ IN ไม่มีรหัส G ต่อท้าย (กติกาเดียวกับใบ IN ปกติ) — G ที่รับเข้าจริงอยู่ที่ documents.gate_id
        $dateKey = docDateKey($dt);
        $docNo   = $type . $dateKey . _docRunningStr(_docCounterNext($pdo, $type, $dateKey)) . ($isIn ? '' : $gateCode);

        // รูปสินค้าที่เบิก → ไฟล์ (ชื่อผูกเลขใบ + รหัส IC) · ไฟล์เสีย = ยกเลิกทั้งใบ
        foreach ($rows as $i => $r) {
            $urls = [];
            foreach ($r['Photos'] as $n => $u) {
                $rel = savePhotoDataUri($u, $docNo . '_' . $r['MatCode'] . '_' . ($n + 1));
                if ($rel === null) {
                    $pdo->rollBack();
                    @unlink($root . $photoRel);
                    foreach ($itemPhotoRels as $f) { @unlink($root . $f); }
                    return ['ok' => false, 'error' => 'รูปสินค้าของ ' . $r['MatCode'] . ' ใช้ไม่ได้ (รองรับ JPG / PNG / WEBP) — เลือกรูปใหม่'];
                }
                $urls[] = $rel;
                $itemPhotoRels[] = $rel;
            }
            $rows[$i]['PhotoUrl'] = $urls ? photoUrlsAppend('', $urls) : null;
        }

        $ins = $pdo->prepare(
            'INSERT INTO documents
                (doc_no, doc_type, project_id, requester_username, receiver_name, receiver_sub_id, gate_id,
                 usage_area, notice, approver_username, approved_by, status, rs_no, photo_url,
                 origin_type, origin_ref, doc_ts)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $usageArea = $isTd ? $destContact : ($isIn ? null : (trim((string)($in['usage_area'] ?? '')) ?: null));
        $ins->execute([
            $docNo, $type, $projectId, (string)$reqUser, $receiverName, $receiverSubId, $gateId,
            $usageArea,
            '[BYPASS] คีย์ย้อนหลังจากแบบฟอร์มกระดาษโดย ' . $actor . ($note !== '' ? ' — ' . $note : ''),
            $actor, $actor, Doc::ST_COMPLETED,
            $isIn ? (trim((string)($in['rs_no'] ?? '')) ?: null) : null,
            $photoRel, BYPASS_ORIGIN, 'PAPER', $dt->format('Y-m-d H:i:s'),
        ]);
        $documentId = (int)$pdo->lastInsertId();
        if ($isTd) {
            $pdo->prepare('UPDATE documents SET dest_project_id = ? WHERE id = ?')->execute([$dest['id'], $documentId]);
        }

        $insItem = $pdo->prepare(
            'INSERT INTO document_items
                (document_id, material_id, mat_code, mat_name, unit, qty, qty_actual, photo_url, usage_area, notice, rs_no, charge_money, stock_deducted)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)'
        );
        foreach ($rows as $r) {
            $mi = $matMap[$r['MatCode']];
            // IN: ลงทะเบียนวัสดุให้ G นั้นถ้ายังไม่มี (แถวยอดรายประตูเริ่ม 0)
            ensureProjectMaterial($pdo, $projectId, $r['MatCode'], $gateCode, false);
            $insItem->execute([
                $documentId, $mi['id'], $r['MatCode'], $mi['name'], $mi['unit'], $r['Qty'], $r['Qty'],
                $r['PhotoUrl'], $usageArea, null,
                $isIn ? (trim((string)($in['rs_no'] ?? '')) ?: null) : null,
                (!$isIn && !$isTd && $r['Charge']) ? 1 : 0,
            ]);
        }

        // ตัด/เพิ่มสต๊อกทันทีที่ G ของใบ (ติดธง stock_deducted — ยกเลิกไม่ได้ เพราะสถานะ Completed)
        deductStockForDoc($pdo, $docNo, $isIn ? 'in' : 'out');
        recalcPending($pdo, $projectId);

        s05ActivityLog($pdo, 'document', $docNo, $actor, 'bypass_create', null, json_encode([
            'type' => $type, 'site' => $proj['code'], 'gate' => $gateCode, 'dispensedAt' => $dt->format('Y-m-d H:i'),
            'requester' => (string)$reqUser, 'receiver' => $receiverName, 'rs' => $in['rs_no'] ?? null,
            'dest' => $dest ? $dest['code'] : null,
            'items' => array_map(function ($r) { return [$r['MatCode'], $r['Qty'], $r['Charge'] ? 1 : 0, count($r['Photos'])]; }, $rows),
            'force' => $force, 'photo' => $photoRel, 'itemPhotos' => count($itemPhotoRels),
        ], JSON_UNESCAPED_UNICODE));
        if ($warnings) {
            _stockErrorLog($pdo, $projectId, $gateCode, 'Bypass ' . $docNo . ': ' . implode(' · ', $warnings) . ' | by=' . $actor);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if ($photoRel !== null) { @unlink($root . $photoRel); }
        foreach ($itemPhotoRels as $f) { @unlink($root . $f); }
        error_log('bypassCreate: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'บันทึกไม่สำเร็จ: ' . $e->getMessage()];
    }
    return ['ok' => true, 'error' => '', 'docNo' => $docNo, 'warnings' => $warnings];
}

// =========================================================================
// Bypass ประตู — ใบที่ออกในระบบแล้ว แต่ตู้/ประตูใช้ไม่ได้ [2026-09-29]
//   bypass ได้เฉพาะ "ตอนเปิด" กับ "ตอนปิด" — ขั้นถ่ายรูปยืนยันทำตามปกติ (หยิบจริง ≤ ที่ขอ · รูปรายรายการ) ที่หน้าเดิม
//   1) bypassGateOpen  = แทนการแตะบัตรที่ตู้: ใบที่ "รอสแกน" (Awaiting) เท่านั้น → Opened (ประตูมีตู้) / Scanned (ไม่มีตู้)
//                        + เลขรอบใหม่ต่อประตู (ใบนับสต๊อกแยกรอบเสมอ) · ไม่มีบัตร (card_id ว่าง)
//   2) ผู้ขอ/สโตร์ถ่ายรูปยืนยันที่หน้า "ถ่ายรูปยืนยัน" (ใบนับ = กรอกผลนับที่หน้าตรวจสอบประจำวัน) → Confirmed
//      ประตูไม่มีตู้: ยืนยันแล้วปิดงาน/ตัดสต๊อกเอง (flow เดิม) — ไม่ต้องปิด
//   3) bypassGateClose = แทนตู้ส่ง closeGate: ทุกใบในรอบต้องยืนยันแล้ว → Closed + ตัด/เพิ่มสต๊อก (finalizeGateDoc)
//   บันทึก: gate_round_events (bypass_open / bypass_close · cardholder = ADM) · activity_log · error_logs "Gate bypass …"
//   ไม่แตะ documents.origin_type ('bypass' = คีย์จากกระดาษ · 'po' = สาย PO buffer)
// =========================================================================

const BYPASS_GATE_MAX = 30;

/** ใบยังมีชีวิตและอยู่ในขั้นไปรับ/นำของที่ประตู — ล้อ gateDocIsLive (api/gate.php) */
function _bpDocLive(string $type, string $status, bool $isReturn): bool {
    $s = mb_strtolower(trim($status), 'UTF-8');
    if ($isReturn) { return $type === Doc::TYPE_BD && strpos($s, 'sent return') !== false; }
    if (in_array($type, [Doc::TYPE_RD, Doc::TYPE_OD, 'TD', 'SC'], true)) {
        return strpos($s, 'approved') !== false || strpos($s, 'อนุมัติแล้ว') !== false;
    }
    if ($type === Doc::TYPE_BD) { return strpos($s, 'sent borrow') !== false; }
    if ($type === Doc::TYPE_IN) { return $s === mb_strtolower(Doc::ST_SENT_INBOUND, 'UTF-8'); }
    if ($type === 'TG') { return strpos($s, 'approved') !== false || strpos($s, 'in transit') !== false; }   // [2026-10-02] ย้าย Gate 2 ขา
    return false;
}

/** ประตูไม่มีตู้ (scan-flow) — ไม่รู้ประตู/ไม่อยู่ในทะเบียน = scan-flow (ล้อ api/gate.php) */
function _bpScanFlow($hardwareClose): bool {
    return $hardwareClose === null || !isTrueFlag($hardwareClose);
}

function _bpTypeLabel(string $type, bool $isReturn): string {
    if ($isReturn) { return 'BD คืน'; }
    $m = ['RD' => 'RD เบิก', 'OD' => 'OD เบ็ดเตล็ด', 'BD' => 'BD ยืม', 'TD' => 'TD โอนย้าย', 'IN' => 'IN รับเข้า', 'SC' => 'SC นับสต๊อก', 'TG' => 'TG ย้าย Gate'];
    return $m[$type] ?? $type;
}

/** ใบที่รอสแกนที่ประตูของโครงการ (เลือก bypass เปิดได้) */
function bypassGateCandidates(PDO $pdo, int $projectId): array {
    $st = $pdo->prepare(
        "SELECT gl.doc_no, gl.leg, gl.gate_id, g.gate_code, g.name AS gate_name, g.hardware_close,
                d.id AS doc_id, d.doc_type, d.status, d.doc_ts, d.requester_username, d.receiver_name, d.rs_no,
                dp.code AS dest_code,
                (SELECT COUNT(*) FROM document_items di WHERE di.document_id = d.id) AS n_items
           FROM gate_logs gl
           JOIN documents d ON d.id = gl.document_id
           LEFT JOIN gates g ON g.id = gl.gate_id
           LEFT JOIN projects dp ON dp.id = d.dest_project_id
          WHERE gl.project_id = ? AND gl.status = 'Awaiting'
          ORDER BY d.doc_ts DESC, gl.id DESC
          LIMIT 300"
    );
    $st->execute([$projectId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $isRet = (string)$r['leg'] === 'return' || (bool)preg_match('/RT$/', (string)$r['doc_no']);
        $type  = (string)$r['doc_type'];
        if (!_bpDocLive($type, (string)$r['status'], $isRet)) { continue; }
        $who = $type === Doc::TYPE_IN ? ('RS ' . (string)($r['rs_no'] ?? '-'))
             : ($type === 'TD' ? 'โอนไป ' . (string)($r['dest_code'] ?? '-') : (string)($r['receiver_name'] ?? ''));
        $out[] = [
            'docNo'     => (string)$r['doc_no'],
            'type'      => $type,
            'isReturn'  => $isRet,
            'typeLabel' => _bpTypeLabel($type, $isRet),
            'gate'      => strtoupper(trim((string)($r['gate_code'] ?? ''))),
            'gateId'    => $r['gate_id'] !== null ? (int)$r['gate_id'] : null,
            'gateName'  => (string)($r['gate_name'] ?? ''),
            'scanFlow'  => $r['gate_code'] !== null ? _bpScanFlow($r['hardware_close']) : true,
            'requester' => (string)$r['requester_username'],
            'who'       => $who,
            'items'     => (int)$r['n_items'],
            'docTs'     => substr((string)$r['doc_ts'], 0, 16),
        ];
    }
    return $out;
}

/**
 * Bypass เปิด — แทนการแตะบัตรที่ตู้
 * @param array $docNos   เลขใบตามแถว gate_logs (ขาคืนของใบยืมลงท้าย RT)
 * @param array $gateFor  ['<docNo>' => gate_id] — ใบรับเข้า (IN) เลือก/เปลี่ยนประตูที่รับของเข้าได้ (ใบอื่นใช้ประตูของใบ)
 * @return array ['ok'=>bool, 'error'=>string, 'rounds'=>[[pickingId, gate, scanFlow, docs[]]]]
 */
function bypassGateOpen(PDO $pdo, array $user, int $projectId, array $docNos, array $gateFor, string $note): array {
    $actor = trim((string)($user['username'] ?? ''));
    $note  = trim(mb_substr($note, 0, 200, 'UTF-8'));
    $list  = [];
    foreach ($docNos as $d) {
        $d = strtoupper(trim((string)$d));
        if ($d !== '' && !in_array($d, $list, true)) { $list[] = $d; }
    }
    if (!$list) { return ['ok' => false, 'error' => 'เลือกเลขเอกสารที่จะ bypass เปิดอย่างน้อย 1 ใบ']; }
    if (count($list) > BYPASS_GATE_MAX) { return ['ok' => false, 'error' => 'เลือกได้ครั้งละไม่เกิน ' . BYPASS_GATE_MAX . ' ใบ']; }

    $rounds = [];
    $pdo->beginTransaction();
    try {
        $sel = $pdo->prepare(
            "SELECT gl.id, gl.doc_no, gl.leg, gl.status, gl.gate_id, g.gate_code, g.hardware_close,
                    d.id AS doc_id, d.doc_type, d.status AS doc_status, d.gate_id AS doc_gate_id
               FROM gate_logs gl
               JOIN documents d ON d.id = gl.document_id
               LEFT JOIN gates g ON g.id = gl.gate_id
              WHERE gl.doc_no = ? AND gl.project_id = ?
              FOR UPDATE"
        );
        $gateSel = $pdo->prepare("SELECT id, gate_code, hardware_close FROM gates WHERE id = ? AND project_id = ? AND status = 'active'");
        $groups = [];   // key → ['gate'=>code, 'scanFlow'=>bool, 'rows'=>[...]]
        foreach ($list as $docNo) {
            $sel->execute([$docNo, $projectId]);
            $r = $sel->fetch();
            if (!$r) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'ไม่พบใบ ' . $docNo . ' ที่ประตูของโครงการนี้'];
            }
            if (strcasecmp(trim((string)$r['status']), Gate::ST_AWAITING) !== 0) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'ใบ ' . $docNo . ' ไม่ได้รอสแกนแล้ว (สถานะที่ประตู ' . $r['status'] . ') — bypass เปิดได้เฉพาะใบที่ยังไม่แตะบัตร'];
            }
            $isRet = (string)$r['leg'] === 'return' || (bool)preg_match('/RT$/', $docNo);
            $type  = (string)$r['doc_type'];
            if (!_bpDocLive($type, (string)$r['doc_status'], $isRet)) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'ใบ ' . $docNo . ' สถานะ ' . $r['doc_status'] . ' — ยังไม่พร้อมผ่านประตู'];
            }
            $gateCode = strtoupper(trim((string)($r['gate_code'] ?? '')));
            $hw = $r['hardware_close'];
            // ใบรับเข้า: รับเข้าที่ประตูไหนก็ได้ (กติกาเดิมของตู้) — เลือกประตูได้ · ใบไม่มีประตูต้องเลือก
            $want = isset($gateFor[$docNo]) ? (int)$gateFor[$docNo] : 0;
            if ($type === Doc::TYPE_IN && !$isRet && $want > 0 && $want !== (int)$r['gate_id']) {
                $gateSel->execute([$want, $projectId]);
                $gr = $gateSel->fetch();
                if (!$gr) { $pdo->rollBack(); return ['ok' => false, 'error' => 'ประตูที่เลือกให้ใบ ' . $docNo . ' ไม่ใช่ประตูของโครงการนี้']; }
                $pdo->prepare('UPDATE gate_logs SET gate_id = ? WHERE id = ?')->execute([(int)$gr['id'], (int)$r['id']]);
                $pdo->prepare('UPDATE documents SET gate_id = ? WHERE id = ?')->execute([(int)$gr['id'], (int)$r['doc_id']]);
                s05ActivityLog($pdo, 'document', $docNo, $actor, 'in_gate_rebind', $gateCode !== '' ? $gateCode : null, strtoupper((string)$gr['gate_code']));
                $gateCode = strtoupper(trim((string)$gr['gate_code']));
                $hw = $gr['hardware_close'];
            }
            if ($gateCode === '') {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'ใบ ' . $docNo . ' ยังไม่มีประตู — เลือกประตูที่รับของเข้า'];
            }
            $key = $type === 'SC' ? 'SC|' . $docNo : $gateCode;   // ใบนับสต๊อกต้องเปิดแยกรอบ (กติกาเดียวกับตู้)
            if (!isset($groups[$key])) { $groups[$key] = ['gate' => $gateCode, 'scanFlow' => _bpScanFlow($hw), 'rows' => []]; }
            $groups[$key]['rows'][] = ['id' => (int)$r['id'], 'docNo' => $docNo, 'docId' => (int)$r['doc_id']];
        }

        $upd = $pdo->prepare('UPDATE gate_logs SET picking_id = ?, status = ?, scanned_at = NOW(), card_id = NULL WHERE id = ?');
        $ev  = $pdo->prepare(
            'INSERT INTO gate_round_events (project_id, gate_code, picking_id, event, item_count, cardholder, detail) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($groups as $g) {
            $pk = nextPickingId($pdo);
            $status = $g['scanFlow'] ? Gate::ST_SCANNED : Gate::ST_OPENED;
            $docs = [];
            $ids = [];
            foreach ($g['rows'] as $row) {
                $upd->execute([$pk, $status, $row['id']]);
                $docs[] = $row['docNo'];
                $ids[$row['docId']] = true;
            }
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $cq = $pdo->prepare("SELECT COUNT(DISTINCT UPPER(TRIM(mat_code))) FROM document_items WHERE document_id IN ($ph) AND TRIM(mat_code) <> ''");
            $cq->execute(array_keys($ids));
            $ev->execute([$projectId, $g['gate'], $pk, 'bypass_open', (int)$cq->fetchColumn(), $actor,
                          json_encode(['docs' => $docs, 'note' => $note, 'scanFlow' => $g['scanFlow']], JSON_UNESCAPED_UNICODE)]);
            foreach ($docs as $dn) {
                s05ActivityLog($pdo, 'document', $dn, $actor, 'gate_bypass_open', null,
                    json_encode(['picking' => $pk, 'gate' => $g['gate'], 'note' => $note], JSON_UNESCAPED_UNICODE));
            }
            _stockErrorLog($pdo, $projectId, $g['gate'], 'Gate bypass open PickingID=' . $pk . ' (' . $g['gate'] . '): ' . implode(', ', $docs)
                . ' — เปิดรอบแทนการแตะบัตร (ตู้/ประตูใช้ไม่ได้)' . ($note !== '' ? ' — ' . $note : '') . ' | by=' . $actor);
            $rounds[] = ['pickingId' => $pk, 'gate' => $g['gate'], 'scanFlow' => $g['scanFlow'], 'docs' => $docs];
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('bypassGateOpen: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'bypass เปิดไม่สำเร็จ: ' . $e->getMessage()];
    }
    return ['ok' => true, 'error' => '', 'rounds' => $rounds];
}

/** รอบที่เปิดด้วย bypass (60 วัน) + สถานะรายใบ · state: open (รอถ่ายรูป) · ready (ยืนยันครบ รอ bypass ปิด) · done */
function bypassGateRounds(PDO $pdo, int $projectId): array {
    $st = $pdo->prepare(
        "SELECT picking_id, gate_code, cardholder, created_at, detail FROM gate_round_events
          WHERE project_id = ? AND event = 'bypass_open' AND created_at >= NOW() - INTERVAL 60 DAY
          ORDER BY id DESC LIMIT 60"
    );
    $st->execute([$projectId]);
    $closeSel = $pdo->prepare("SELECT cardholder, created_at FROM gate_round_events WHERE picking_id = ? AND event = 'bypass_close' ORDER BY id DESC LIMIT 1");
    $rowsSel = $pdo->prepare(
        "SELECT gl.doc_no, gl.status, g.hardware_close, d.doc_type, d.requester_username
           FROM gate_logs gl LEFT JOIN gates g ON g.id = gl.gate_id LEFT JOIN documents d ON d.id = gl.document_id
          WHERE gl.picking_id = ? AND gl.project_id = ? ORDER BY gl.id"
    );
    $out = [];
    foreach ($st->fetchAll() as $e) {
        $pk = (string)$e['picking_id'];
        $rowsSel->execute([$pk, $projectId]);
        $rows = $rowsSel->fetchAll();
        if (!$rows) { continue; }
        $scanFlow = _bpScanFlow($rows[0]['hardware_close']);
        $open = 0; $confirmed = 0; $closed = 0; $docs = [];
        foreach ($rows as $r) {
            $s = strtolower(trim((string)$r['status']));
            if ($s === 'opened' || $s === 'scanned') { $open++; $txt = (string)$r['doc_type'] === 'SC' ? 'รอกรอกผลนับ' : 'รอถ่ายรูปยืนยัน'; }
            elseif ($s === 'confirmed') { $confirmed++; $txt = $scanFlow ? 'ยืนยันแล้ว — ปิดงานแล้ว' : 'ยืนยันแล้ว — รอ bypass ปิด'; }
            elseif ($s === 'closed') { $closed++; $txt = 'ปิดงานแล้ว'; }
            else { $txt = (string)$r['status']; }
            $docs[] = ['docNo' => (string)$r['doc_no'], 'status' => (string)$r['status'], 'statusThai' => $txt,
                       'type' => (string)($r['doc_type'] ?? ''), 'requester' => (string)($r['requester_username'] ?? '')];
        }
        $done = $open === 0 && ($scanFlow ? true : $confirmed === 0);
        $state = $open > 0 ? 'open' : ($done ? 'done' : 'ready');
        $closeSel->execute([$pk]);
        $cl = $closeSel->fetch();
        $out[] = [
            'pickingId' => $pk, 'gate' => (string)$e['gate_code'], 'scanFlow' => $scanFlow, 'state' => $state,
            'openedBy' => (string)$e['cardholder'], 'openedAt' => substr((string)$e['created_at'], 0, 16),
            'closedBy' => $cl ? (string)$cl['cardholder'] : '', 'closedAt' => $cl ? substr((string)$cl['created_at'], 0, 16) : '',
            'note' => (string)(json_decode((string)$e['detail'], true)['note'] ?? ''),
            'docs' => $docs, 'pending' => $open,
        ];
    }
    return $out;
}

/** Bypass ปิด — แทนตู้ส่ง closeGate: ทุกใบในรอบต้องยืนยันแล้ว → Closed + ตัด/เพิ่มสต๊อก */
function bypassGateClose(PDO $pdo, array $user, int $projectId, string $pickingId, string $note): array {
    $actor = trim((string)($user['username'] ?? ''));
    $pk    = trim($pickingId);
    $note  = trim(mb_substr($note, 0, 200, 'UTF-8'));
    if ($pk === '') { return ['ok' => false, 'error' => 'ไม่ระบุเลขรอบ']; }
    $chk = $pdo->prepare("SELECT gate_code FROM gate_round_events WHERE picking_id = ? AND project_id = ? AND event = 'bypass_open' LIMIT 1");
    $chk->execute([$pk, $projectId]);
    $gateCode = $chk->fetchColumn();
    if ($gateCode === false) {
        return ['ok' => false, 'error' => 'รอบ ' . $pk . ' ไม่ได้เปิดด้วย bypass — รอบที่เปิดจากตู้ต้องปิดที่ตู้'];
    }
    $closed = [];
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            "SELECT gl.id, gl.doc_no, gl.status, g.hardware_close
               FROM gate_logs gl LEFT JOIN gates g ON g.id = gl.gate_id
              WHERE gl.picking_id = ? AND gl.project_id = ? FOR UPDATE"
        );
        $st->execute([$pk, $projectId]);
        $rows = $st->fetchAll();
        if (!$rows) { $pdo->rollBack(); return ['ok' => false, 'error' => 'ไม่พบใบในรอบ ' . $pk]; }
        $pending = [];
        foreach ($rows as $r) {
            $s = strtolower(trim((string)$r['status']));
            if ($s === 'opened' || $s === 'scanned') { $pending[] = (string)$r['doc_no']; }
        }
        if ($pending) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'ยังมีใบที่ยังไม่ถ่ายรูปยืนยัน/กรอกผลนับ: ' . implode(', ', $pending)
                    . ' — ให้ยืนยันที่หน้าเดิมก่อน แล้วค่อย bypass ปิด (เหมือนตู้ที่ปิดประตูได้เมื่อยืนยันครบ)'];
        }
        $upd = $pdo->prepare('UPDATE gate_logs SET status = ? WHERE id = ?');
        foreach ($rows as $r) {
            if (strcasecmp(trim((string)$r['status']), Gate::ST_CONFIRMED) !== 0) { continue; }
            if (_bpScanFlow($r['hardware_close'])) { continue; }   // ประตูไม่มีตู้ปิดงานไปแล้วตอนยืนยัน
            $upd->execute([Gate::ST_CLOSED, (int)$r['id']]);
            $closed[] = (string)$r['doc_no'];
        }
        if (!$closed) { $pdo->rollBack(); return ['ok' => false, 'error' => 'รอบ ' . $pk . ' ปิดงานครบแล้ว']; }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('bypassGateClose: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'bypass ปิดไม่สำเร็จ: ' . $e->getMessage()];
    }
    // ปิดงาน/ตัดสต๊อกทีละใบ — ล้อ closeGate ของตู้ (error ต่อใบไม่ล้มทั้งชุด)
    $failed = [];
    foreach ($closed as $dn) {
        try {
            finalizeGateDoc($pdo, $dn);
        } catch (Throwable $e) {
            $failed[] = $dn;
            error_log('bypassGateClose finalize ' . $dn . ': ' . $e->getMessage());
        }
    }
    try {
        $pdo->prepare('INSERT INTO gate_round_events (project_id, gate_code, picking_id, event, cardholder, detail) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$projectId, (string)$gateCode, $pk, 'bypass_close', $actor,
                       json_encode(['closedDocs' => $closed, 'note' => $note, 'failed' => $failed], JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable $e) {
        error_log('bypassGateClose event: ' . $e->getMessage());
    }
    foreach ($closed as $dn) {
        s05ActivityLog($pdo, 'document', $dn, $actor, 'gate_bypass_close', null,
            json_encode(['picking' => $pk, 'note' => $note], JSON_UNESCAPED_UNICODE));
    }
    _stockErrorLog($pdo, $projectId, (string)$gateCode, 'Gate bypass close PickingID=' . $pk . ' (' . $gateCode . '): ' . implode(', ', $closed)
        . ' — ปิดรอบแทนตู้ ตัด/เพิ่มสต๊อกแล้ว' . ($note !== '' ? ' — ' . $note : '') . ' | by=' . $actor);
    return ['ok' => !$failed, 'error' => $failed ? 'ปิดงานไม่สำเร็จบางใบ: ' . implode(', ', $failed) . ' (ดู error log ของเซิร์ฟเวอร์)' : '',
            'closed' => $closed, 'failed' => $failed];
}

/** ใบ bypass ล่าสุด (หน้า Bypass) */
function bypassRecent(PDO $pdo, int $projectId = 0, int $limit = 30): array {
    $sql = "SELECT d.doc_no, d.doc_type, d.doc_ts, d.requester_username, d.receiver_name, d.rs_no, d.approved_by,
                   d.created_at, d.photo_url, g.gate_code, p.code AS site_code,
                   (SELECT COUNT(*) FROM document_items di WHERE di.document_id = d.id) AS n_items
              FROM documents d
              JOIN projects p ON p.id = d.project_id
              LEFT JOIN gates g ON g.id = d.gate_id
             WHERE d.origin_type = ?";
    $ar = [BYPASS_ORIGIN];
    if ($projectId > 0) { $sql .= ' AND d.project_id = ?'; $ar[] = $projectId; }
    $sql .= ' ORDER BY d.created_at DESC, d.id DESC LIMIT ' . max(1, min(200, $limit));
    $st = $pdo->prepare($sql);
    $st->execute($ar);
    return $st->fetchAll();
}
