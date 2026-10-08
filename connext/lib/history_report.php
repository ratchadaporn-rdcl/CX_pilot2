<?php
/**
 * CONNEXT — lib/history_report.php : รายงานการเบิกจ่ายหลายใบพร้อมรูป (หน้า "ประวัติเอกสาร" · 2026-09-30)
 *
 * แทนการสร้าง PDF ทีละใบ: ผู้ใช้กรอง รหัส IC / ช่วงวันที่ / ผู้นำจ่าย / ประเภท / สถานะ แล้วติ๊กเลือกใบ → Export เป็น PDF ไฟล์เดียว
 * (api/history_report.php ส่งไฟล์กลับตรง ๆ — ไม่ผ่าน JSON/base64 เพราะรายงานหลายใบพร้อมรูปใหญ่ได้หลาย MB)
 *
 *   historyReportBuild($pdo, $user, $req) → ['ok' => true, 'pdf' => binary, 'fileName', 'docs', 'photos', 'skipped']
 *                                          | ['ok' => false, 'message']
 *     $req = site (ใช้เฉพาะ R0) · docNos[] (ใบที่ติ๊ก) · ic (ส่วนของรหัส IC — กรองบรรทัดและรูปในรายงาน)
 *            · payer / from / to / type / status / q (พิมพ์บนหัวรายงานเท่านั้น — ฝั่งหน้าเว็บกรองใบมาแล้ว)
 *   hrPayerMap($pdo, $docNos)   → [doc_no => "ผู้นำจ่าย"] ใช้ร่วมกับหน้าประวัติ (lib/history_stats.php)
 *   hrPhotoRefs($raw)           → รายการ URL/พาธรูปจากคอลัมน์ photo_url (คั่นด้วย , ; หรือช่องว่าง)
 *
 * สิทธิ์ = หน้าประวัติ: R0 ทุกไซต์ · ≥ R6 ทั้งไซต์ตัวเอง · R1–R5 / ผู้รับเหมา เฉพาะใบที่ตัวเองขอ — ใบนอกสิทธิ์ถูกข้าม (นับใน skipped)
 * รูป: ไฟล์ใน uploads/ ย่อเป็น JPEG ด้านยาว ≤ HR_THUMB_PX ลง pdf/imgcache/ ก่อนฝัง (dompdf แปลง WEBP เป็น PNG ขนาดใหญ่มาก)
 *      รูปจากระบบเดิม (Google Drive) ใส่เป็นลิงก์ · ไม่เกิน HR_MAX_DOCS ใบ / HR_MAX_PHOTOS รูปต่อไฟล์
 *
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/s05.php';

const HR_MAX_DOCS   = 100;
const HR_MAX_PHOTOS = 300;
const HR_THUMB_PX   = 900;

/** รายการ URL/พาธรูปจากคอลัมน์ photo_url (คั่นด้วย , ; หรือช่องว่าง) */
function hrPhotoRefs($raw): array {
    $out = [];
    foreach (preg_split('/[,;\s]+/u', trim((string)$raw)) ?: [] as $p) {
        $p = trim($p);
        if ($p !== '') { $out[] = $p; }
    }
    return $out;
}

/** ไฟล์ในเครื่อง (uploads/…) หรือไม่ — ไม่ใช่ = ลิงก์จากระบบเดิม */
function hrIsLocalPhoto(string $ref): bool {
    return strpos($ref, 'http://') !== 0 && strpos($ref, 'https://') !== 0 && strpos($ref, 'uploads/') === 0;
}

/**
 * "ผู้นำจ่าย" ของแต่ละใบ (เหมือน PDF รายใบ): เจ้าของบัตรที่แตะเปิดใบนั้นที่ตู้ ·
 * Bypass ประตู = "Bypass ประตู (ADM)" · ใบคีย์จากแบบฟอร์มกระดาษ = "แบบฟอร์มกระดาษ" · ยังไม่ผ่านประตู = ''
 * @param array $docs [doc_no => origin_type]
 */
function hrPayerMap(PDO $pdo, array $docs): array {
    $out = [];
    $nos = array_keys($docs);
    $cards = [];
    $bypass = [];
    foreach (array_chunk($nos, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $st = $pdo->prepare("SELECT doc_no, card_id FROM gate_logs
                              WHERE doc_no IN ($ph) AND card_id IS NOT NULL AND card_id <> '' ORDER BY id");
        $st->execute($chunk);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!isset($cards[(string)$r['doc_no']])) { $cards[(string)$r['doc_no']] = trim((string)$r['card_id']); }
        }
        $st = $pdo->prepare("SELECT entity_id, user_name FROM activity_log
                              WHERE entity_type = 'document' AND action = 'gate_bypass_open' AND entity_id IN ($ph) ORDER BY id");
        $st->execute($chunk);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!isset($bypass[(string)$r['entity_id']])) { $bypass[(string)$r['entity_id']] = trim((string)$r['user_name']); }
        }
    }
    $names = s05CardHolders($pdo, array_values($cards));
    foreach ($docs as $no => $origin) {
        $no = (string)$no;
        if (isset($cards[$no])) {
            $out[$no] = $names[$cards[$no]] ?? ('CardID: ' . $cards[$no]);
        } elseif (isset($bypass[$no])) {
            $out[$no] = 'Bypass ประตู (' . $bypass[$no] . ')';
        } elseif (strtolower(trim((string)$origin)) === 'bypass') {
            $out[$no] = 'แบบฟอร์มกระดาษ';
        } else {
            $out[$no] = '';
        }
    }
    return $out;
}

/** รูปในเครื่อง → JPEG ย่อใน pdf/imgcache/ (แคชตามไฟล์ + เวลาแก้ไข) · ใช้ไม่ได้ = null */
function hrThumb(string $root, string $rel): ?string {
    $src = $root . $rel;
    if (!is_file($src)) { return null; }
    $dir = $root . 'pdf/imgcache/';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
        @file_put_contents($dir . '.htaccess', "Require all denied\n");
    }
    $dst = $dir . md5($rel . '|' . filemtime($src) . '|' . filesize($src)) . '.jpg';
    if (is_file($dst)) { @touch($dst); return $dst; }
    $info = @getimagesize($src);
    if (!$info) { return null; }
    $im = null;
    switch ((int)$info[2]) {
        case IMAGETYPE_JPEG: $im = @imagecreatefromjpeg($src); break;
        case IMAGETYPE_PNG:  $im = @imagecreatefrompng($src); break;
        case IMAGETYPE_GIF:  $im = @imagecreatefromgif($src); break;
        case IMAGETYPE_WEBP: $im = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false; break;
    }
    if (!$im) { return null; }
    $w = imagesx($im);
    $h = imagesy($im);
    $scale = min(1.0, HR_THUMB_PX / max(1, max($w, $h)));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $out = imagecreatetruecolor($nw, $nh);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));   // PNG โปร่งใส → พื้นขาว
    imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $tmp = $dst . '.' . getmypid() . '.tmp';
    $ok = @imagejpeg($out, $tmp, 72);
    imagedestroy($im);
    imagedestroy($out);
    if (!$ok || !@rename($tmp, $dst)) { @unlink($tmp); return null; }
    return $dst;
}

/** ลบรูปย่อที่ไม่ได้ใช้เกิน 14 วัน (ทำตอนสร้างรายงาน) */
function hrThumbCleanup(string $root): void {
    $cut = time() - 14 * 86400;
    foreach (glob($root . 'pdf/imgcache/*.jpg') ?: [] as $f) {
        if (@filemtime($f) < $cut) { @unlink($f); }
    }
}

/** ตรงกับตัวกรอง IC (ส่วนของรหัส ไม่สนตัวพิมพ์) — ตัวกรองว่าง = ทุกบรรทัด */
function hrIcMatch(string $matCode, string $ic): bool {
    return $ic === '' || strpos(strtoupper($matCode), $ic) !== false;
}

function historyReportBuild(PDO $pdo, ?array $user, array $req): array {
    require_once __DIR__ . '/history_stats.php';   // hs_roleNum / hs_effectiveSite / hs_effectiveName
    require_once __DIR__ . '/pdf_engine.php';
    require_once __DIR__ . '/pdf_api.php';         // _pdfSubNameByCode / _pdfResolveReceiver

    if (!$user) { return ['ok' => false, 'message' => 'กรุณาเข้าสู่ระบบ']; }
    $docNos = [];
    foreach ((array)($req['docNos'] ?? []) as $d) {
        $d = trim((string)$d);
        if ($d !== '' && preg_match('/^[A-Za-z0-9_\-.\/]{1,40}$/', $d)) { $docNos[$d] = true; }
    }
    $docNos = array_keys($docNos);
    if (!$docNos) { return ['ok' => false, 'message' => 'ยังไม่ได้เลือกใบ — ติ๊กเลือกใบในตารางก่อน Export']; }
    if (count($docNos) > HR_MAX_DOCS) {
        return ['ok' => false, 'message' => 'เลือกได้ไม่เกิน ' . HR_MAX_DOCS . ' ใบต่อรายงาน (เลือกมา ' . count($docNos) . ' ใบ) — กรองช่วงวันที่/ตัวกรองให้แคบลง หรือแยกเป็นหลายรายงาน'];
    }
    $ic = strtoupper(trim((string)($req['ic'] ?? '')));

    // ---- สิทธิ์ = หน้าประวัติ (server-authoritative) ----
    $roleNum    = hs_roleNum($pdo, $user);
    $filterSelf = ($roleNum !== 0 && $roleNum <= 5);
    $site       = hs_effectiveSite($user, $req['site'] ?? '', $roleNum);
    $where  = ["d.doc_type <> 'SC'"];
    $params = [];
    if ($site !== '') {
        $pid = hs_projectIdByCode($pdo, $site);
        if ($pid === null) { return ['ok' => false, 'message' => 'ไม่พบไซต์ ' . $site]; }
        $where[] = 'd.project_id = ?';
        $params[] = $pid;
    }
    if ($filterSelf) {
        $where[] = 'd.requester_username = ?';
        $params[] = hs_effectiveName($user);
    }
    $ph = implode(',', array_fill(0, count($docNos), '?'));
    $st = $pdo->prepare("SELECT d.*, p.code AS site_code, p.name AS p_name, p.site_ref, g.gate_code,
                                dp.code AS dest_code, dp.name AS dest_name
                           FROM documents d
                           JOIN projects p ON p.id = d.project_id
                           LEFT JOIN gates g ON g.id = d.gate_id
                           LEFT JOIN projects dp ON dp.id = d.dest_project_id
                          WHERE d.doc_no IN ($ph) AND " . implode(' AND ', $where) . '
                          ORDER BY d.doc_ts, d.id');
    $st->execute(array_merge($docNos, $params));
    $docs = $st->fetchAll(PDO::FETCH_ASSOC);
    $skipped = count($docNos) - count($docs);
    if (!$docs) { return ['ok' => false, 'message' => 'ไม่พบใบที่เลือก หรือไม่มีสิทธิ์ดูใบเหล่านี้']; }

    // ---- รายการของทุกใบ ----
    $ids = array_map(function ($d) { return (int)$d['id']; }, $docs);
    $iph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT i.*, m.name AS master_name, m.unit AS master_unit
                           FROM document_items i LEFT JOIN materials m ON m.mat_code = i.mat_code
                          WHERE i.document_id IN ($iph) ORDER BY i.document_id, i.id");
    $st->execute($ids);
    $itemsBy = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $itemsBy[(int)$r['document_id']][] = $r; }

    $origins = [];
    foreach ($docs as $d) { $origins[(string)$d['doc_no']] = (string)($d['origin_type'] ?? ''); }
    $payers = hrPayerMap($pdo, $origins);
    $subMap = _pdfSubNameByCode($pdo);
    $root   = _pdfRoot();
    hrThumbCleanup($root);

    $typeLabels = ['RD' => 'เบิกวัสดุหลัก', 'OD' => 'เบิกเบ็ดเตล็ด', 'BD' => 'ยืม-คืนอุปกรณ์', 'IN' => 'รับเข้าคลัง', 'TD' => 'เบิกโอนย้ายข้ามไซต์', 'TG' => 'ย้าย Gate (ภายในไซต์)'];
    $statusThai = [
        'awaiting approval' => 'รออนุมัติ', 'awaiting for approval' => 'รออนุมัติ', 'pending' => 'รออนุมัติ', 'awaiting revision' => 'ตีกลับให้แก้',
        'approved' => 'อนุมัติแล้ว', 'sent to gate' => 'อนุมัติแล้ว', 'rejected' => 'ไม่อนุมัติ', 'denied' => 'ไม่อนุมัติ',
        'closed' => 'นำจ่ายแล้ว', 'sent borrow' => 'ส่งยืม', 'borrowed' => 'ยืมอยู่', 'sent return' => 'ส่งคืน', 'returned' => 'คืนแล้ว',
        'completed' => 'สำเร็จ', 'opened' => 'เปิดประตูแล้ว', 'scanned' => 'สแกนแล้ว', 'confirmed' => 'ยืนยันแล้ว', 'cancelled' => 'ยกเลิก',
    ];
    $fmtDate = function ($ts) {
        if (!$ts) { return '-'; }
        try { return (new DateTime((string)$ts))->format('d/m/Y H:i'); } catch (Throwable $e) { return (string)$ts; }
    };

    $outDocs  = [];
    $summary  = [];
    $nPhotos  = 0;
    $nLinks   = 0;
    $overCap  = 0;
    $noMatch  = 0;
    foreach ($docs as $d) {
        $docNo  = (string)$d['doc_no'];
        $type   = (string)$d['doc_type'];
        $paper  = strtolower(trim((string)($d['origin_type'] ?? ''))) === 'bypass';
        $rows   = $itemsBy[(int)$d['id']] ?? [];
        $lines  = [];
        $cells  = [];
        $seen   = [];
        $itemPhotoCount = 0;
        $n = 0;
        foreach ($rows as $r) {
            $mc = (string)$r['mat_code'];
            if (!hrIcMatch($mc, $ic)) { continue; }
            $n++;
            $name = trim((string)($r['mat_name'] ?? ''));
            if ($name === '') { $name = trim((string)($r['master_name'] ?? '')); }
            $unit = trim((string)($r['master_unit'] ?? ''));
            if ($unit === '') { $unit = trim((string)($r['unit'] ?? '')); }
            $hasActual = $r['qty_actual'] !== null && $r['qty_actual'] !== '';
            $eff  = s05EffQty($r);
            $diff = $hasActual ? round((float)$r['qty_actual'] - (float)$r['qty'], 3) : null;
            $reason = trim((string)($r['actual_reason'] ?? ''));
            if ($type === 'BD' && isset($r['qty_returned']) && $r['qty_returned'] !== '') {
                $reason = trim($reason . ($reason !== '' ? ' · ' : '') . 'คืน ' . pdfQty($r['qty_returned']));
            }
            $lines[] = [
                'no' => $n, 'matCode' => $mc, 'name' => $name !== '' ? $name : '-', 'unit' => $unit,
                'qty' => pdfQty($r['qty']), 'actual' => $hasActual ? pdfQty($r['qty_actual']) : '-',
                'diff' => $diff === null ? '-' : (abs($diff) < 0.0005 ? '0' : (($diff > 0 ? '+' : '−') . pdfQty(abs($diff)))),
                'diffCls' => $diff === null || abs($diff) < 0.0005 ? '' : ($diff > 0 ? 'up' : 'down'),
                'reason' => $reason,
            ];
            $k = strtoupper($mc);
            if (!isset($summary[$k])) {
                $summary[$k] = ['code' => $mc, 'name' => $name !== '' ? $name : '-', 'unit' => $unit, 'docs' => [], 'req' => 0.0, 'eff' => 0.0];
            }
            $summary[$k]['docs'][$docNo] = true;
            $summary[$k]['req'] += (float)$r['qty'];
            $summary[$k]['eff'] += $eff;
            foreach (['photo_url' => $paper ? 'รูปสินค้าที่เบิก' : 'รูปยืนยัน', 'photo_return_url' => 'รูปยืนยันคืน'] as $col => $lbl) {
                foreach (hrPhotoRefs($r[$col] ?? '') as $ref) {
                    if (isset($seen[$ref])) { continue; }
                    $seen[$ref] = true;
                    $cells[] = ['ref' => $ref, 'label' => $lbl . ' — ' . $mc];
                    $itemPhotoCount++;
                }
            }
        }
        if (!$lines) { $noMatch++; continue; }
        // รูปรวมของใบ: ใบใหม่ต่อท้ายรูปรายรายการไว้ที่หัวใบด้วย (ซ้ำ → ข้าม) · ใบเก่ามีแต่รูปรวม ·
        // กรอง IC แล้ว: รูปรวมใส่เฉพาะเมื่อใบไม่มีรูปรายรายการเลย (ระบุไม่ได้ว่าเป็นของรายการไหน) + รูปแบบฟอร์มกระดาษเสมอ
        $allItemRefs = [];
        foreach ($rows as $r) { foreach (['photo_url', 'photo_return_url'] as $col) { foreach (hrPhotoRefs($r[$col] ?? '') as $ref) { $allItemRefs[$ref] = true; } } }
        // [2026-10-02 · GP-10] ใบ IN: รูปใบส่งของ / รูปของที่รับเข้า = หลักฐานที่มาของของ (ใส่เสมอ แม้กรองรหัส IC)
        if ($type === 'IN') {
            foreach (hrPhotoRefs($d['in_photo_url'] ?? '') as $ref) {
                if (isset($seen[$ref])) { continue; }
                $seen[$ref] = true;
                $cells[] = ['ref' => $ref, 'label' => inCtlPhotoLabel((string)($d['in_source'] ?? ''))];
            }
        }
        $docCols = $type === 'BD'
            ? ['photo_url' => 'รูปยืนยันยืม (ทั้งใบ)', 'photo_return_url' => 'รูปยืนยันคืน (ทั้งใบ)']
            : ['photo_url' => $paper ? 'รูปแบบฟอร์มกระดาษ' : 'รูปยืนยัน (ทั้งใบ)'];
        foreach ($docCols as $col => $lbl) {
            foreach (hrPhotoRefs($d[$col] ?? '') as $ref) {
                if (isset($seen[$ref]) || isset($allItemRefs[$ref])) { continue; }
                if ($ic !== '' && !$paper && $allItemRefs) { continue; }
                $seen[$ref] = true;
                $cells[] = ['ref' => $ref, 'label' => $lbl];
            }
        }
        // รูป → เซลล์ของ template (ย่อเป็น JPEG · ลิงก์ Drive · เกินเพดาน)
        $photoCells = [];
        foreach ($cells as $c) {
            $ref = $c['ref'];
            if (hrIsLocalPhoto($ref)) {
                if ($nPhotos >= HR_MAX_PHOTOS) { $overCap++; continue; }
                $thumb = hrThumb($root, $ref);
                if ($thumb !== null) {
                    $nPhotos++;
                    $photoCells[] = ['label' => $c['label'], 'src' => $thumb, 'link' => null, 'note' => null];
                } else {
                    $photoCells[] = ['label' => $c['label'], 'src' => null, 'link' => null, 'note' => '(ไม่พบไฟล์รูป / ไฟล์เสีย) ' . $ref];
                }
            } else {
                $nLinks++;
                $photoCells[] = ['label' => $c['label'], 'src' => null, 'link' => $ref, 'note' => 'รูปจากระบบเดิม (Google Drive) — คลิกเพื่อเปิด'];
            }
        }
        $photoRows = array_chunk($photoCells, 3);

        $recvRaw = trim((string)($d['receiver_name'] ?? ''));
        $receiver = $recvRaw !== '' ? _pdfResolveReceiver($recvRaw, $subMap) : '-';
        $lc = mb_strtolower(trim((string)($d['status'] ?? '')), 'UTF-8');
        $meta = [
            ['ผู้ทำเบิก', trim((string)$d['requester_username']) !== '' ? (string)$d['requester_username'] : '-'],
            [$type === 'TD' ? 'ผู้รับที่ไซต์ปลายทาง' : 'ผู้รับ', $type === 'TD' ? (trim((string)$d['usage_area']) !== '' ? (string)$d['usage_area'] : '-') : $receiver],
            ['ผู้นำจ่าย', ($payers[$docNo] ?? '') !== '' ? $payers[$docNo] : '-'],
            ['ประตู', trim((string)($d['gate_code'] ?? '')) !== '' ? (string)$d['gate_code'] : '-'],
        ];
        if (trim((string)($d['approver_username'] ?? '')) !== '' && $type !== 'OD') { $meta[] = ['ผู้อนุมัติ', (string)$d['approver_username']]; }
        if ($type !== 'TD' && trim((string)($d['usage_area'] ?? '')) !== '') { $meta[] = ['พื้นที่ใช้งาน', (string)$d['usage_area']]; }
        if ($type === 'TD') { $meta[] = ['ไซต์ปลายทาง', trim((string)($d['dest_code'] ?? '')) !== '' ? $d['dest_code'] . ' · ' . $d['dest_name'] : '-']; }
        if ($type === 'IN' && trim((string)($d['rs_no'] ?? '')) !== '') { $meta[] = ['เลขที่ใบรับสินค้า', (string)$d['rs_no']]; }
        if ($type === 'IN' && ($inSrcTxt = inCtlSourceText($d)) !== '') { $meta[] = ['ที่มาของของ', $inSrcTxt]; }   // [2026-10-02 · GP-10]
        if ($type === 'BD' && !empty($d['return_ts'])) { $meta[] = ['วันที่คืน', $fmtDate($d['return_ts'])]; }
        if ($paper) { $meta[] = ['ช่องทาง', 'Bypass — คีย์ย้อนหลังจากแบบฟอร์มกระดาษ']; }

        $outDocs[] = [
            'docNo' => $docNo, 'type' => $type, 'typeLabel' => $typeLabels[$type] ?? $type,
            'date' => $fmtDate($d['doc_ts']), 'status' => $statusThai[$lc] ?? ((string)$d['status'] !== '' ? (string)$d['status'] : '-'),
            'meta' => $meta, 'lines' => $lines, 'photoRows' => $photoRows,
            'noPhoto' => !$photoCells, 'hiddenLines' => count($rows) - count($lines),
        ];
    }
    if (!$outDocs) {
        return ['ok' => false, 'message' => 'ใบที่เลือกไม่มีรายการที่ตรงกับรหัส IC "' . $ic . '"'];
    }

    // ---- สรุปตามรหัส IC ----
    uasort($summary, function ($a, $b) { return strcmp($a['code'], $b['code']); });
    $sumRows = [];
    foreach ($summary as $s) {
        $sumRows[] = ['code' => $s['code'], 'name' => $s['name'], 'unit' => $s['unit'], 'docs' => count($s['docs']),
                      'req' => pdfQty(round($s['req'], 3)), 'eff' => pdfQty(round($s['eff'], 3))];
    }

    // ---- หัวรายงาน ----
    $siteCodes = array_values(array_unique(array_map(function ($d) { return (string)$d['site_code']; }, $docs)));
    $first = $docs[0];
    $siteHeader = count($siteCodes) === 1
        ? $first['site_code'] . '  |  ' . $first['p_name'] . (trim((string)$first['site_ref']) !== '' && $first['site_ref'] !== '-' ? '  |  SiteID: ' . $first['site_ref'] : '')
        : 'หลายไซต์: ' . implode(', ', $siteCodes);
    $thDate = function ($ymd) {
        $ymd = trim((string)$ymd);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) { return ''; }
        try { return pdfThaiShortBE(new DateTime($ymd)); } catch (Throwable $e) { return ''; }
    };
    $from = $thDate($req['from'] ?? '');
    $to   = $thDate($req['to'] ?? '');
    $typeF = strtoupper(trim((string)($req['type'] ?? '')));
    $filters = [
        ['ช่วงวันที่', $from !== '' || $to !== '' ? ($from !== '' ? $from : 'ต้นทาง') . ' – ' . ($to !== '' ? $to : 'ปัจจุบัน') : 'ทั้งหมด'],
        ['รหัส IC', $ic !== '' ? $ic . ' (แสดงเฉพาะรายการที่ตรง)' : 'ทุกรายการ'],
        ['ผู้นำจ่าย', trim((string)($req['payer'] ?? '')) !== '' ? mb_substr(trim((string)$req['payer']), 0, 80, 'UTF-8') : 'ทุกคน'],
        ['ประเภท', $typeF !== '' ? ($typeLabels[$typeF] ?? $typeF) : 'ทุกประเภท'],
    ];
    if (trim((string)($req['status'] ?? '')) !== '') { $filters[] = ['สถานะ', mb_substr(trim((string)$req['status']), 0, 40, 'UTF-8')]; }
    if (trim((string)($req['q'] ?? '')) !== '') { $filters[] = ['คำค้น', mb_substr(trim((string)$req['q']), 0, 60, 'UTF-8')]; }
    $who = trim((string)($user['fullName'] ?? '')) !== '' ? (string)$user['fullName'] : (string)($user['username'] ?? '');
    $filters[] = ['จำนวน', count($outDocs) . ' ใบ · ' . array_sum(array_map(function ($x) { return count($x['lines']); }, $outDocs)) . ' รายการ · รูป '
                          . $nPhotos . ' รูป' . ($nLinks ? ' + ลิงก์รูประบบเดิม ' . $nLinks . ' ลิงก์' : '')];
    $notes = [];
    if ($skipped > 0) { $notes[] = 'ข้าม ' . $skipped . ' ใบที่ไม่พบหรือไม่มีสิทธิ์ดู'; }
    if ($noMatch > 0) { $notes[] = 'ข้าม ' . $noMatch . ' ใบที่ไม่มีรายการตรงกับรหัส IC'; }
    if ($overCap > 0) { $notes[] = 'รูปเกิน ' . HR_MAX_PHOTOS . ' รูปต่อรายงาน — ไม่ได้ใส่อีก ' . $overCap . ' รูป (แยกเป็นหลายรายงานให้เล็กลง)'; }

    $html = pdfRenderTemplate('history_report.php', [
        'siteHeader' => $siteHeader,
        'title'      => 'รายงานการเบิกจ่าย พร้อมรูปภาพ',
        'filters'    => $filters,
        'notes'      => $notes,
        'summary'    => $sumRows,
        'docs'       => $outDocs,
        'footerText' => 'สร้างโดย ' . $who . ' · ระบบ CONNEXT — ' . date('d/m/Y H:i'),
    ]);
    $bin = renderPdf($html, 'a4', 'portrait', [
        'pageNumbers' => true,
        'headerText'  => 'รายงานการเบิกจ่าย · ' . (count($siteCodes) === 1 ? $siteCodes[0] : 'หลายไซต์') . ' · ' . date('d/m/Y'),
    ]);

    s05ActivityLog($pdo, 'report', 'history', (string)($user['username'] ?? ''), 'history_report_export', null,
        json_encode(['docs' => count($outDocs), 'photos' => $nPhotos, 'ic' => $ic, 'from' => (string)($req['from'] ?? ''),
                     'to' => (string)($req['to'] ?? ''), 'payer' => (string)($req['payer'] ?? ''),
                     'docNos' => array_slice(array_map(function ($x) { return $x['docNo']; }, $outDocs), 0, 100)], JSON_UNESCAPED_UNICODE));

    $tag = count($siteCodes) === 1 ? $siteCodes[0] : 'ALL';
    return ['ok' => true, 'pdf' => $bin, 'fileName' => 'History_' . $tag . '_' . date('Ymd_His') . '.pdf',
            'docs' => count($outDocs), 'photos' => $nPhotos, 'skipped' => $skipped + $noMatch];
}
