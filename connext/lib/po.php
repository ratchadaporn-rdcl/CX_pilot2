<?php
/**
 * CONNEXT — lib/po.php : ใบคุม (PO) → รับบางส่วน → buffer → push เข้า gate
 *
 * เฟส 2 + 3 ของสาย PO → OCR → buffer → IcCode (db/design_po_ocr_ic_v1.md)
 *
 * กติกาที่บังคับที่ชั้นนี้เสมอ (CHECK ใน DB บังคับจริงเฉพาะ MariaDB 10.2+)
 *   มติ 6  — ห้ามรับเกินยอดคุม: SUM(รับ) ต่อบรรทัด ≤ po_lines.qty
 *   มติ 8  — รับได้หลายรอบ สะสมใน qty_received
 *   มติ 11 — ตัดยอด buffer ด้วย "ใช้หน่วยซื้อไปเท่าไหร่" 1 ค่าต่อการ push
 *   มติ 18 — บรรทัด adjust ไม่ใช่ของ: ห้ามรับ ห้ามเข้า buffer
 *   มติ 5  — ขา gate ใช้ rpc_processInboundBatch ของเดิมทั้งดุ้น ไม่เขียนกลไกซ้ำ
 *   มติ 20 — ic_suggest_map จำจากที่คนเคยเลือก ไม่ใช่ AI เดา
 *
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/ic.php';
require_once __DIR__ . '/mango.php';   // มติ 35 — เพิ่มรหัส Mango ที่ทะเบียนยังไม่มี

/** ชื่อผู้ขาย → คีย์เทียบ (ตัดช่องว่าง/คำนำหน้าบริษัทออก) */
function poVendorKey(string $name): string {
    $s = trim($name);
    $s = preg_replace('/\s+/u', ' ', $s);
    $s = str_replace(['บริษัท', 'จำกัด', '(มหาชน)', 'หจก.', 'ห้างหุ้นส่วนจำกัด'], '', $s);
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    return mb_substr($s === '' ? $name : $s, 0, 120);
}

/** เก็บไฟล์ PDF ต้นฉบับใต้ uploads/po/Y-m/ — คืน path สัมพัทธ์ หรือ null */
function poSavePdf(string $bytes, string $origName): ?string {
    if ($bytes === '' || strncmp($bytes, '%PDF-', 5) !== 0) { return null; }

    $root   = defined('ROOT_PATH') ? ROOT_PATH : (dirname(__DIR__) . '/');
    $subDir = 'uploads/po/' . date('Y-m');
    $absDir = rtrim(str_replace('\\', '/', $root), '/') . '/' . $subDir;
    if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
        error_log('poSavePdf: mkdir failed ' . $absDir);
        return null;
    }

    $stem = preg_replace('/[^A-Za-z0-9_\-]/', '', pathinfo($origName, PATHINFO_FILENAME));
    $stem = substr($stem, 0, 40);
    try {
        $name = ($stem !== '' ? $stem . '_' : '') . bin2hex(random_bytes(8)) . '.pdf';
    } catch (Throwable $e) {
        return null;
    }
    if (@file_put_contents($absDir . '/' . $name, $bytes) === false) { return null; }
    return $subDir . '/' . $name;
}

/** บันทึก ocr_logs — ห้าม throw ออกไปทำ flow หลักล้ม (มติ 21) */
function poLogOcr(PDO $pdo, array $m): int {
    try {
        $st = $pdo->prepare(
            'INSERT INTO ocr_logs
               (po_no, source, model, status, file_name, file_path, payload, raw,
                error_note, ms, tokens, project_id, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            $m['po_no']      ?? null,
            $m['source']     ?? 'GEMINI',
            $m['model']      ?? null,
            $m['status']     ?? 'failed',
            $m['file_name']  ?? null,
            $m['file_path']  ?? null,
            $m['payload']    ?? null,
            $m['raw']        ?? null,
            isset($m['error_note']) ? mb_substr((string)$m['error_note'], 0, 500) : null,
            (int)($m['ms'] ?? 0),
            (int)($m['tokens'] ?? 0),
            isset($m['project_id']) ? (int)$m['project_id'] : null,
            isset($m['created_by']) ? (int)$m['created_by'] : null,
        ]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('poLogOcr: ' . $e->getMessage());
        return 0;
    }
}

/**
 * สร้างใบคุมจากผลที่คนตรวจ/แก้แล้ว
 * @param array $head  ช่องหัวใบ (po_no, po_date, vendor_*, ยอดสรุป, ...)
 * @param array $lines รายการบรรทัด (line_no, line_kind, mat_code, mat_name, qty, ...)
 * @return array ['ok','error','po_no']
 */
function poCommit(PDO $pdo, array $head, array $lines, int $projectId, ?array $user = null,
                  array $addMaster = []): array {
    $poNo = trim((string)($head['po_no'] ?? ''));
    $fail = function ($m) { return ['ok' => false, 'error' => $m, 'po_no' => '', 'mango_added' => 0]; };

    if ($poNo === '')                      { return $fail('ไม่มีเลขที่ใบสั่งซื้อ'); }
    if (mb_strlen($poNo) > 30)             { return $fail('เลขที่ใบสั่งซื้อยาวเกิน 30 ตัวอักษร'); }
    if (empty($lines))                     { return $fail('ไม่มีบรรทัดสักรายการ'); }
    if ($projectId <= 0)                   { return $fail('ยังไม่ได้เลือกไซต์'); }

    $st = $pdo->prepare('SELECT 1 FROM po_headers WHERE po_no = ?');
    $st->execute([$poNo]);
    if ($st->fetchColumn()) { return $fail('ใบ ' . $poNo . ' ถูกนำเข้าไปแล้ว — เปิดใบเดิมแทน'); }

    $st = $pdo->prepare('SELECT 1 FROM projects WHERE id = ?');
    $st->execute([$projectId]);
    if (!$st->fetchColumn()) { return $fail('ไม่พบไซต์ที่เลือก'); }

    // แปลงวันที่ DD/MM/YYYY → DATE (ปีในใบเป็น ค.ศ. อยู่แล้ว)
    $poDate = null;
    $raw    = trim((string)($head['po_date'] ?? ''));
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $raw, $mm)) {
        $poDate = sprintf('%04d-%02d-%02d', (int)$mm[3], (int)$mm[2], (int)$mm[1]);
    }

    // จับคู่ mat_code กับ materials ล่วงหน้าทีเดียว
    $codes = [];
    foreach ($lines as $l) {
        $c = trim((string)($l['mat_code'] ?? ''));
        if ($c !== '') { $codes[$c] = true; }
    }
    $matMap = [];
    $added  = ['added' => 0, 'skipped' => 0, 'errors' => []];
    if (!empty($codes)) {
        $keys = array_keys($codes);
        $ph   = implode(',', array_fill(0, count($keys), '?'));
        $q    = $pdo->prepare("SELECT id, mat_code FROM materials WHERE mat_code IN ($ph)");
        $q->execute($keys);
        foreach ($q->fetchAll() as $r) { $matMap[(string)$r['mat_code']] = (int)$r['id']; }
    }

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) { $pdo->beginTransaction(); }
    try {
        // มติ 35 — รหัสที่คนติ๊กไว้ว่า "เพิ่มเข้าทะเบียน" ต้องเกิดก่อนจับคู่
        // จะได้ผูก po_lines.material_id ให้ตั้งแต่ใบแรกที่เจอรหัสนั้น
        if (!empty($addMaster)) {
            $added = mangoAddFromPoLines($pdo, $addMaster, $user);
            if ($added['added'] > 0) {
                $keys = array_keys($codes);
                $ph2  = implode(',', array_fill(0, count($keys), '?'));
                $q2   = $pdo->prepare("SELECT id, mat_code FROM materials WHERE mat_code IN ($ph2)");
                $q2->execute($keys);
                foreach ($q2->fetchAll() as $r) { $matMap[(string)$r['mat_code']] = (int)$r['id']; }
            }
        }

        $ins = $pdo->prepare(
            'INSERT INTO po_headers
               (po_no, project_id, po_date, pr_no, vendor_name, vendor_code, vendor_tax_id,
                vendor_address, vendor_contact, vendor_phone, quotation_no, quotation_date,
                delivery_date, payment_terms, deposit_text, retention_text, page_count,
                sum_before, special_discount, sum_after, vat, grand_total, amount_in_words,
                notes, src_file, ocr_log_id, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $ins->execute([
            $poNo, $projectId, $poDate,
            trim((string)($head['pr_no'] ?? '')) ?: null,
            trim((string)($head['vendor_name'] ?? '')),
            trim((string)($head['vendor_code'] ?? '')) ?: null,
            trim((string)($head['vendor_tax_id'] ?? '')) ?: null,
            trim((string)($head['vendor_address'] ?? '')) ?: null,
            trim((string)($head['vendor_contact'] ?? '')) ?: null,
            trim((string)($head['vendor_phone'] ?? '')) ?: null,
            trim((string)($head['quotation_no'] ?? '')) ?: null,
            trim((string)($head['quotation_date'] ?? '')) ?: null,
            trim((string)($head['delivery_date'] ?? '')) ?: null,
            trim((string)($head['payment_terms'] ?? '')) ?: null,
            trim((string)($head['deposit_text'] ?? '')) ?: null,
            trim((string)($head['retention_text'] ?? '')) ?: null,
            (int)($head['page_count'] ?? 0),
            (float)($head['sum_before'] ?? 0), (float)($head['special_discount'] ?? 0),
            (float)($head['sum_after'] ?? 0), (float)($head['vat'] ?? 0),
            (float)($head['grand_total'] ?? 0),
            trim((string)($head['amount_in_words'] ?? '')) ?: null,
            isset($head['notes']) ? (string)$head['notes'] : null,
            trim((string)($head['src_file'] ?? '')) ?: null,
            (int)($head['ocr_log_id'] ?? 0) ?: null,
            $user !== null ? ((int)($user['accountId'] ?? 0) ?: null) : null,
        ]);

        $insL = $pdo->prepare(
            'INSERT INTO po_lines
               (po_no, line_no, line_kind, mat_code, material_id, mat_name, description,
                qty, unit_po_name, unit_price, discount, amount)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $seen = [];
        $n    = 0;
        foreach ($lines as $l) {
            $no = (int)($l['line_no'] ?? 0);
            if ($no <= 0 || isset($seen[$no])) { continue; }   // ตกหรือซ้ำ = ข้าม
            $seen[$no] = true;

            $code = trim((string)($l['mat_code'] ?? ''));
            $kind = ((string)($l['line_kind'] ?? 'item') === 'adjust') ? 'adjust' : 'item';
            $insL->execute([
                $poNo, $no, $kind,
                $code !== '' ? $code : null,
                isset($matMap[$code]) ? $matMap[$code] : null,
                mb_substr(trim((string)($l['mat_name'] ?? '')), 0, 255),
                trim((string)($l['description'] ?? '')) ?: null,
                (float)($l['qty'] ?? 0),
                mb_substr(trim((string)($l['unit_po_name'] ?? '')), 0, 50),
                (float)($l['unit_price'] ?? 0),
                (float)($l['discount'] ?? 0),
                (float)($l['amount'] ?? 0),
            ]);
            $n++;
        }
        if ($n === 0) { throw new RuntimeException('ไม่มีบรรทัดที่บันทึกได้'); }

        if (!empty($head['ocr_log_id'])) {
            $u = $pdo->prepare('UPDATE ocr_logs SET po_no = ? WHERE log_id = ?');
            $u->execute([$poNo, (int)$head['ocr_log_id']]);
        }

        if ($ownTx) { $pdo->commit(); }
        return ['ok' => true, 'error' => '', 'po_no' => $poNo, 'mango_added' => (int)$added['added']];

    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('poCommit ' . $poNo . ': ' . $e->getMessage());
        return $fail('บันทึกใบคุมไม่สำเร็จ: ' . $e->getMessage());
    }
}

/** หัวใบ + บรรทัด */
function poGet(PDO $pdo, string $poNo): ?array {
    $st = $pdo->prepare(
        'SELECT h.*, p.code AS proj_code, p.name AS proj_name
         FROM po_headers h JOIN projects p ON p.id = h.project_id
         WHERE h.po_no = ?'
    );
    $st->execute([$poNo]);
    $h = $st->fetch();
    if (!$h) { return null; }

    $st = $pdo->prepare(
        'SELECT l.*, (l.qty - l.qty_received) AS qty_remain,
                b.id AS buffer_id, b.qty_received AS buf_in, b.qty_consumed AS buf_out
         FROM po_lines l
         LEFT JOIN buffer_lines b ON b.po_no = l.po_no AND b.line_no = l.line_no
         WHERE l.po_no = ? ORDER BY l.line_no'
    );
    $st->execute([$poNo]);
    $h['lines'] = $st->fetchAll();

    $st = $pdo->prepare('SELECT * FROM po_receipts WHERE po_no = ? ORDER BY rcv_no');
    $st->execute([$poNo]);
    $h['receipts'] = $st->fetchAll();

    return $h;
}

/** เลขที่รอบรับถัดไป RCV-{po_no}-{nn} */
function poNextRcvNo(PDO $pdo, string $poNo): string {
    $st = $pdo->prepare('SELECT COUNT(*) FROM po_receipts WHERE po_no = ?');
    $st->execute([$poNo]);
    $n = (int)$st->fetchColumn() + 1;
    return 'RCV-' . $poNo . '-' . str_pad((string)$n, 2, '0', STR_PAD_LEFT);
}

/**
 * รับของหนึ่งรอบ → อัปเดตยอดคุม + ดันเข้า buffer (มติ 6, 7, 8, 18)
 * @param array $qtyByLine [line_no => qty]
 * @return array ['ok','error','rcv_no','n_lines']
 */
function poReceive(PDO $pdo, string $poNo, array $qtyByLine, string $rcvDate, string $note, ?array $user = null): array {
    $fail = function ($m) { return ['ok' => false, 'error' => $m, 'rcv_no' => '', 'n_lines' => 0]; };

    $po = poGet($pdo, $poNo);
    if ($po === null)                              { return $fail('ไม่พบใบ ' . $poNo); }
    if ((string)$po['status'] === 'cancelled')     { return $fail('ใบนี้ถูกยกเลิกแล้ว'); }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rcvDate)) { $rcvDate = nowBkk()->format('Y-m-d'); }

    $byNo = [];
    foreach ($po['lines'] as $l) { $byNo[(int)$l['line_no']] = $l; }

    // ── ตรวจให้ครบก่อนเขียนอะไรสักตัว ──
    $take = [];
    foreach ($qtyByLine as $no => $q) {
        $no = (int)$no;
        $q  = (float)$q;
        if ($q <= 0) { continue; }
        if (!isset($byNo[$no]))                        { return $fail('ไม่มีบรรทัดที่ ' . $no); }
        $l = $byNo[$no];
        if ((string)$l['line_kind'] === 'adjust')      { return $fail('บรรทัดที่ ' . $no . ' เป็นรายการเงิน ไม่ใช่ของที่รับได้ (มติ 18)'); }
        $remain = (float)$l['qty'] - (float)$l['qty_received'];
        if ($q > $remain + 0.00005) {
            return $fail('บรรทัดที่ ' . $no . ' รับเกินยอดคุม — เหลือรับได้ ' . rtrim(rtrim(number_format($remain, 4, '.', ''), '0'), '.')
                       . ' ' . (string)$l['unit_po_name']);
        }
        $take[$no] = $q;
    }
    if (empty($take)) { return $fail('ยังไม่ได้ใส่จำนวนที่รับสักบรรทัด'); }

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) { $pdo->beginTransaction(); }
    try {
        $rcvNo = poNextRcvNo($pdo, $poNo);
        $ins   = $pdo->prepare(
            'INSERT INTO po_receipts (rcv_no, po_no, project_id, rcv_date, note, created_by)
             VALUES (?,?,?,?,?,?)'
        );
        $ins->execute([
            $rcvNo, $poNo, (int)$po['project_id'], $rcvDate,
            trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null,
            $user !== null ? ((int)($user['accountId'] ?? 0) ?: null) : null,
        ]);

        $insL = $pdo->prepare('INSERT INTO po_receipt_lines (rcv_no, line_no, qty) VALUES (?,?,?)');
        $updL = $pdo->prepare('UPDATE po_lines SET qty_received = qty_received + ? WHERE po_no = ? AND line_no = ?');
        $upsB = $pdo->prepare(
            'INSERT INTO buffer_lines (project_id, po_no, line_no, unit_po_name, qty_received)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE qty_received = qty_received + VALUES(qty_received)'
        );

        foreach ($take as $no => $q) {
            $insL->execute([$rcvNo, $no, $q]);
            $updL->execute([$q, $poNo, $no]);
            $upsB->execute([(int)$po['project_id'], $poNo, $no, (string)$byNo[$no]['unit_po_name'], $q]);
        }

        poRefreshStatus($pdo, $poNo);

        if ($ownTx) { $pdo->commit(); }
        return ['ok' => true, 'error' => '', 'rcv_no' => $rcvNo, 'n_lines' => count($take)];

    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('poReceive ' . $poNo . ': ' . $e->getMessage());
        return $fail('บันทึกการรับไม่สำเร็จ: ' . $e->getMessage());
    }
}

/** สถานะใบคุมตามยอดที่รับแล้ว (นับเฉพาะบรรทัดที่เป็นของจริง) */
function poRefreshStatus(PDO $pdo, string $poNo): void {
    $st = $pdo->prepare(
        "SELECT COUNT(*) n, SUM(qty_received > 0) got, SUM(qty_received >= qty) full
         FROM po_lines WHERE po_no = ? AND line_kind = 'item'"
    );
    $st->execute([$poNo]);
    $r = $st->fetch();

    $n    = (int)($r['n'] ?? 0);
    $got  = (int)($r['got'] ?? 0);
    $full = (int)($r['full'] ?? 0);

    $status = 'open';
    if ($n > 0 && $full >= $n)      { $status = 'received'; }
    elseif ($got > 0)               { $status = 'partial'; }

    $u = $pdo->prepare("UPDATE po_headers SET status = ? WHERE po_no = ? AND status <> 'cancelled'");
    $u->execute([$status, $poNo]);
}

// ── buffer ────────────────────────────────────────────────────────────────

/** ของที่ยังค้างใน buffer ของไซต์นั้น */
function poBufferLines(PDO $pdo, int $projectId, string $q = '', bool $onlyRemaining = true): array {
    $w    = ['b.project_id = ?'];
    $args = [$projectId];
    if ($onlyRemaining) { $w[] = '(b.qty_received - b.qty_consumed) > 0.00005'; }
    if ($q !== '') {
        $like = '%' . likeEscape($q) . '%';
        $w[]  = '(l.mat_name LIKE ? OR l.mat_code LIKE ? OR b.po_no LIKE ? OR h.vendor_name LIKE ?)';
        array_push($args, $like, $like, $like, $like);
    }
    $sql = 'SELECT b.*, (b.qty_received - b.qty_consumed) AS qty_remain,
                   l.mat_code, l.mat_name, l.description, l.unit_price, l.line_kind,
                   h.vendor_name, h.po_date, h.status AS po_status
            FROM buffer_lines b
            JOIN po_lines l   ON l.po_no = b.po_no AND l.line_no = b.line_no
            JOIN po_headers h ON h.po_no = b.po_no
            WHERE ' . implode(' AND ', $w) . '
            ORDER BY b.updated_at DESC, b.id DESC';
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/** บรรทัด buffer หนึ่งแถวพร้อมข้อมูลรอบข้าง */
function poBufferGet(PDO $pdo, int $bufferId): ?array {
    $st = $pdo->prepare(
        'SELECT b.*, (b.qty_received - b.qty_consumed) AS qty_remain,
                l.mat_code, l.mat_name, l.description, l.line_kind,
                h.vendor_name, h.po_no AS h_po_no, p.code AS proj_code
         FROM buffer_lines b
         JOIN po_lines l   ON l.po_no = b.po_no AND l.line_no = b.line_no
         JOIN po_headers h ON h.po_no = b.po_no
         JOIN projects p   ON p.id = b.project_id
         WHERE b.id = ?'
    );
    $st->execute([$bufferId]);
    $r = $st->fetch();
    return $r ?: null;
}

/** ประวัติการนำออกของบรรทัดนั้น */
function poPushHistory(PDO $pdo, int $bufferId): array {
    $st = $pdo->prepare(
        'SELECT p.*, d.status AS doc_status,
                GROUP_CONCAT(CONCAT(a.ic_code, " × ", a.qty) SEPARATOR " · ") AS allocs
         FROM buffer_pushes p
         LEFT JOIN push_allocs a ON a.push_id = p.push_id
         LEFT JOIN documents   d ON d.doc_no  = p.doc_no
         WHERE p.buffer_id = ?
         GROUP BY p.push_id ORDER BY p.push_id DESC'
    );
    $st->execute([$bufferId]);
    return $st->fetchAll();
}

// ── ic_suggest_map (มติ 20) ───────────────────────────────────────────────

/** IC ที่เคยผูกกับ (ผู้ขาย + รหัสวัสดุ) นี้ — เรียงตัวที่ใช้บ่อยสุดก่อน */
function icSuggestFor(PDO $pdo, string $vendorKey, string $matCode): array {
    if ($matCode === '') { return []; }
    $st = $pdo->prepare(
        'SELECT s.ic_code, s.hit_count, s.last_used, i.ic_name, u.unit_name,
                (s.vendor_key = ?) AS same_vendor
         FROM ic_suggest_map s
         JOIN ic_items i ON i.ic_code = s.ic_code
         JOIN units u    ON u.unit_code = i.unit_code
         WHERE s.mat_code = ?
         ORDER BY same_vendor DESC, s.hit_count DESC, s.last_used DESC
         LIMIT 8'
    );
    $st->execute([$vendorKey, $matCode]);
    return $st->fetchAll();
}

/** จำไว้ว่าคนเลือก IC นี้ให้รหัสวัสดุนี้ของผู้ขายรายนี้ */
function icSuggestRemember(PDO $pdo, string $vendorKey, string $matCode, string $icCode): void {
    if ($matCode === '' || $icCode === '') { return; }
    try {
        $st = $pdo->prepare(
            'INSERT INTO ic_suggest_map (vendor_key, mat_code, ic_code)
             VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE hit_count = hit_count + 1'
        );
        $st->execute([$vendorKey, $matCode, $icCode]);
    } catch (Throwable $e) {
        error_log('icSuggestRemember: ' . $e->getMessage());
    }
}

// ── push จาก buffer → ใบ IN → gate (เฟส 3 — มติ 5, 10, 11) ────────────────

/**
 * ── นำของออกจาก buffer → ใบ IN "ไร้ประตู" ใบเดียว (มติ 5, 10, 11 + มติ 23) ──
 *
 * รับได้หลายบรรทัด buffer ต่อหนึ่งครั้ง แล้วรวมเป็น "ใบ IN ใบเดียว" —
 * ตรงกับหน้างานที่ของมาคันรถเดียวกันแต่มาจากหลายบรรทัด/หลายใบสั่งซื้อ
 *
 * ไม่มีพารามิเตอร์ประตูโดยตั้งใจ (มติ 23) — ใบที่ออกไม่ผูกประตู ใช้ QR เข้าประตูไหนก็ได้
 * (api/gate.php จับคู่ด้วย doc_no อย่างเดียว ไม่กรองประตู) แล้วจบงานที่ขั้นถ่ายรูปยืนยัน
 *
 * @param array $jobs [['buffer_id'=>int, 'qty_consumed'=>float,
 *                      'allocs'=>[['ic_code'=>string,'qty'=>float], ...]], ...]
 * @return array ['ok','error','doc_no','push_ids'=>int[],'n_lines'=>int]
 */
function poPushBatch(PDO $pdo, array $jobs, string $note, array $user, array $inMeta = []): array {
    $fail = function ($m) {
        return ['ok' => false, 'error' => $m, 'doc_no' => '', 'push_ids' => [], 'n_lines' => 0];
    };
    if (empty($jobs)) { return $fail('ยังไม่ได้เลือกของสักบรรทัด'); }

    // [2026-10-02 · GP-10 / OP-70] ของตามใบ PO = ของจาก supplier → ต้องแนบรูปใบส่งของ ·
    //   ออกได้เฉพาะสายสโตร์ของไซต์ของบรรทัดนั้น (ADM ทุกไซต์) — $inMeta['photos'] = [data URI, ...]
    require_once __DIR__ . '/inbound_ctl.php';
    $inAcc = inCtlAccess($pdo, $user);
    if (!$inAcc['ok']) { return $fail($inAcc['message']); }
    $inPhotos = [];
    foreach ((array)($inMeta['photos'] ?? []) as $ph) {
        if (is_string($ph) && trim($ph) !== '') { $inPhotos[] = trim($ph); }
    }
    if (!$inPhotos) { return $fail('ต้องแนบรูปใบส่งของอย่างน้อย 1 รูป (ของจาก supplier / PO — GP-10)'); }
    $userProjectId = (int)($user['projectId'] ?? 0);

    // ── ตรวจให้ครบทุกบรรทัดก่อนเขียนอะไรสักตัว ──────────────────────────
    $plan      = [];   // ต่อ 1 job: buf + qty + allocs ที่ผ่านการตรวจแล้ว
    $projectId = 0;
    $seenBuf   = [];

    foreach ($jobs as $job) {
        $bufferId = (int)($job['buffer_id'] ?? 0);
        if ($bufferId <= 0) { return $fail('บรรทัด buffer ไม่ถูกต้อง'); }
        if (isset($seenBuf[$bufferId])) {
            return $fail('บรรทัด buffer #' . $bufferId . ' ซ้ำในชุดเดียวกัน — รวมจำนวนให้เป็นบรรทัดเดียวก่อน');
        }
        $seenBuf[$bufferId] = true;

        $buf = poBufferGet($pdo, $bufferId);
        if ($buf === null)                          { return $fail('ไม่พบบรรทัดใน buffer'); }
        if ((string)$buf['line_kind'] === 'adjust') { return $fail('"' . (string)$buf['mat_name'] . '" เป็นรายการเงิน ไม่ใช่ของ (มติ 18)'); }
        if (!$inAcc['admin'] && (int)$buf['project_id'] !== $userProjectId) {   // [2026-10-02 · GP-10]
            return $fail('"' . (string)$buf['mat_name'] . '" เป็นของไซต์ ' . (string)($buf['proj_code'] ?? '')
                       . ' — สายสโตร์ออกใบรับเข้าได้เฉพาะของไซต์ตัวเอง');
        }

        // ใบ IN หนึ่งใบ = หนึ่งไซต์ (rpc_processInboundBatch resolve project จาก SiteCode)
        if ($projectId === 0) { $projectId = (int)$buf['project_id']; }
        elseif ($projectId !== (int)$buf['project_id']) {
            return $fail('เลือกของข้ามไซต์ในชุดเดียวกันไม่ได้ — แยกออกใบทีละไซต์');
        }

        $qtyConsumed = (float)($job['qty_consumed'] ?? 0);
        $remain      = (float)$buf['qty_remain'];
        if ($qtyConsumed <= 0) {
            return $fail('"' . (string)$buf['mat_name'] . '" ยังไม่ได้ระบุว่าใช้หน่วยซื้อไปเท่าไหร่');
        }
        if ($qtyConsumed > $remain + 0.00005) {
            return $fail('"' . (string)$buf['mat_name'] . '" ใช้เกินที่มีใน buffer — เหลืออยู่ '
                       . rtrim(rtrim(number_format($remain, 4, '.', ''), '0'), '.')
                       . ' ' . (string)$buf['unit_po_name']);
        }

        $clean = [];
        foreach ((array)($job['allocs'] ?? []) as $a) {
            $ic = strtoupper(trim((string)($a['ic_code'] ?? '')));
            $q  = (float)($a['qty'] ?? 0);
            if ($ic === '' && $q <= 0) { continue; }
            if ($ic === '') { return $fail('"' . (string)$buf['mat_name'] . '" มีบรรทัดที่ใส่จำนวนแต่ยังไม่ได้เลือกรหัส IC'); }
            if ($q <= 0)    { return $fail('รหัส ' . $ic . ' ยังไม่ได้ใส่จำนวน'); }

            $st = $pdo->prepare(
                'SELECT i.ic_code, i.ic_name, m.id AS material_id
                 FROM ic_items i LEFT JOIN materials m ON m.mat_code = i.ic_code
                 WHERE i.ic_code = ? AND i.is_active = 1'
            );
            $st->execute([$ic]);
            $row = $st->fetch();
            if (!$row) { return $fail('ไม่พบรหัส IC ' . $ic . ' (หรือถูกปิดใช้งาน)'); }

            $matId = $row['material_id'] !== null ? (int)$row['material_id'] : icEnsureMaterial($pdo, $ic);
            if ($matId <= 0) { return $fail('รหัส ' . $ic . ' ยังไม่มีแถวใน materials'); }

            $clean[] = ['ic_code' => $ic, 'qty' => $q, 'material_id' => $matId];
        }
        if (empty($clean)) {
            return $fail('"' . (string)$buf['mat_name'] . '" ยังไม่ได้ระบุรหัส IC สักบรรทัด (มติ 7 — เข้า gate ต้องมี IC)');
        }

        $plan[] = ['buf' => $buf, 'qty' => $qtyConsumed, 'allocs' => $clean];
    }

    $note   = trim($note);
    $noteDb = $note !== '' ? mb_substr($note, 0, 255) : null;

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) { $pdo->beginTransaction(); }
    $savedInPhotos = [];
    try {
        $insP = $pdo->prepare(
            'INSERT INTO buffer_pushes (buffer_id, project_id, qty_consumed, note, created_by)
             VALUES (?,?,?,?,?)'
        );
        $insA = $pdo->prepare('INSERT INTO push_allocs (push_id, ic_code, material_id, qty) VALUES (?,?,?,?)');
        $lock = $pdo->prepare('SELECT qty_received, qty_consumed FROM buffer_lines WHERE id = ? FOR UPDATE');
        $updB = $pdo->prepare('UPDATE buffer_lines SET qty_consumed = qty_consumed + ? WHERE id = ?');

        $pushIds  = [];
        $items    = [];
        $poNos    = [];
        $siteCode = '';

        foreach ($plan as $pl) {
            $buf      = $pl['buf'];
            $bufferId = (int)$buf['id'];
            $siteCode = (string)$buf['proj_code'];

            // กันแข่งกัน: ล็อกแถว buffer แล้วอ่านยอดใหม่ (ยอดอาจขยับหลังผ่านการตรวจ)
            $lock->execute([$bufferId]);
            $cur = $lock->fetch();
            if (!$cur) { throw new RuntimeException('ไม่พบบรรทัด buffer #' . $bufferId); }
            if ($pl['qty'] > (float)$cur['qty_received'] - (float)$cur['qty_consumed'] + 0.00005) {
                throw new RuntimeException('ยอดของ "' . (string)$buf['mat_name']
                                         . '" ใน buffer เปลี่ยนไประหว่างบันทึก — ลองใหม่อีกครั้ง');
            }

            $insP->execute([
                $bufferId, (int)$buf['project_id'], $pl['qty'], $noteDb,
                (int)($user['accountId'] ?? 0) ?: null,
            ]);
            $pushId    = (int)$pdo->lastInsertId();
            $pushIds[] = $pushId;

            foreach ($pl['allocs'] as $c) {
                $insA->execute([$pushId, $c['ic_code'], $c['material_id'], $c['qty']]);
            }
            $updB->execute([$pl['qty'], $bufferId]);

            $poNo    = (string)$buf['po_no'];
            $poNos[] = $poNo;
            foreach ($pl['allocs'] as $c) {
                $items[] = [
                    'MatCode'  => $c['ic_code'],
                    'Qty'      => $c['qty'],
                    'GateID'   => '',
                    'NoGate'   => 1,   // ห้ามเติมประตูตั้งต้นของวัสดุย้อนหลัง (มติ 23)
                    'SiteCode' => $siteCode,
                    'UserName' => (string)($user['username'] ?? ''),
                    'Notice'   => $note !== '' ? $note : ('นำเข้าจาก buffer ' . $poNo),
                    'RS'       => $poNo,
                ];
            }
        }

        // ── ออกใบ IN ด้วยกลไกเดิมทั้งดุ้น (มติ 5) — ทุก item ไม่มีประตู = ใบเดียว ──
        require_once __DIR__ . '/documents.php';
        $res = rpc_processInboundBatch($pdo, $user, [$items, ['source' => IN_SRC_SUPPLIER, 'photos' => $inPhotos]]);
        if (empty($res['success'])) {
            throw new RuntimeException('ออกใบ IN ไม่สำเร็จ: ' . (string)($res['message'] ?? ''));
        }
        $savedInPhotos = (array)($res['inPhotos'] ?? []);
        $docNo = (string)(($res['inboundIds'][0] ?? $res['inboundId']) ?? '');

        if ($docNo !== '') {
            $u = $pdo->prepare('UPDATE buffer_pushes SET doc_no = ? WHERE push_id = ?');
            foreach ($pushIds as $pid) { $u->execute([$docNo, $pid]); }

            // ธงที่มา (มติ 5) — origin_ref เป็น varchar(30) เก็บได้ใบเดียว
            // ชุดที่คร่อมหลายใบสั่งซื้อจึงเว้น ref ไว้ แล้วสืบย้อนทาง buffer_pushes.doc_no แทน
            $uniq = array_values(array_unique($poNos));
            $ref  = count($uniq) === 1 ? $uniq[0] : null;
            $u = $pdo->prepare("UPDATE documents SET origin_type = 'po', origin_ref = ? WHERE doc_no = ?");
            $u->execute([$ref, $docNo]);
        }

        // จำการจับคู่ไว้ใช้ครั้งหน้า (มติ 20)
        foreach ($plan as $pl) {
            $vk = poVendorKey((string)$pl['buf']['vendor_name']);
            $mc = trim((string)$pl['buf']['mat_code']);
            foreach ($pl['allocs'] as $c) { icSuggestRemember($pdo, $vk, $mc, $c['ic_code']); }
        }

        if ($ownTx) { $pdo->commit(); }
        return ['ok' => true, 'error' => '', 'doc_no' => $docNo,
                'push_ids' => $pushIds, 'n_lines' => count($plan)];

    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
        inCtlDeleteFiles($savedInPhotos);   // [2026-10-02] ใบไม่ถูกเขียน → ไม่เก็บรูปใบส่งของ
        error_log('poPushBatch: ' . $e->getMessage());
        return $fail($e->getMessage());
    }
}

/** นำออกบรรทัดเดียว — ทางลัดของ poPushBatch() เพื่อความเข้ากันได้ย้อนหลัง */
function poPush(PDO $pdo, int $bufferId, float $qtyConsumed, array $allocs, string $note, array $user): array {
    $r = poPushBatch($pdo, [[
        'buffer_id'    => $bufferId,
        'qty_consumed' => $qtyConsumed,
        'allocs'       => $allocs,
    ]], $note, $user);
    return ['ok' => $r['ok'], 'error' => $r['error'],
            'push_id' => $r['push_ids'][0] ?? 0, 'doc_no' => $r['doc_no']];
}

/**
 * ── ยกเลิกใบ IN ที่ออกจาก buffer → คืนยอดกลับเข้า buffer (มติ 26) ──────────
 *
 * เรียกจาก rpc_cancelRequisition() ใน lib/approval.php ในทรานแซกชันเดียวกับ
 * การตั้งสถานะ Cancelled — ยกเลิกสำเร็จแต่คืนยอดไม่สำเร็จถือว่าทั้งคู่ต้องล้ม
 *
 * ของจริง "ยังอยู่ที่ไซต์" — แค่ใบที่จะพาเข้า gate ถูกยกเลิก ยอดจึงต้องกลับไป
 * นอนใน buffer เพื่อออกใบใหม่ได้ · po_lines.qty_received ไม่แตะ (ไม่ได้ส่งของคืนผู้ขาย)
 *
 * กันคืนซ้ำด้วย buffer_pushes.cancelled_at — คืนเฉพาะรอบที่ยังเป็น NULL
 * (idempotent เรียกกี่ครั้งก็ได้ผลเท่าเดิม)
 *
 * @return array ['ok','error','n_pushes','qty'] qty = หน่วยซื้อรวมที่คืนกลับ
 */
function poRollbackPushesForDoc(PDO $pdo, string $docNo, ?array $user = null): array {
    $docNo = trim($docNo);
    $out   = ['ok' => true, 'error' => '', 'n_pushes' => 0, 'qty' => 0.0];
    if ($docNo === '') { return $out; }

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) { $pdo->beginTransaction(); }
    try {
        // ล็อกรอบที่ยังไม่ถูกยกเลิกของใบนี้
        $sel = $pdo->prepare(
            'SELECT push_id, buffer_id, qty_consumed
               FROM buffer_pushes
              WHERE doc_no = ? AND cancelled_at IS NULL
              FOR UPDATE'
        );
        $sel->execute([$docNo]);
        $rows = $sel->fetchAll();
        if (!$rows) {
            if ($ownTx) { $pdo->commit(); }
            return $out;   // ไม่ใช่ใบที่มาจาก buffer หรือคืนไปแล้ว — ไม่ใช่ error
        }

        // คืนยอดแบบไม่ให้ติดลบ (GREATEST 0) — ถ้าเคยมีมือแก้ DB มาก่อนจะได้ไม่พัง
        $upd = $pdo->prepare(
            'UPDATE buffer_lines
                SET qty_consumed = GREATEST(0, qty_consumed - ?)
              WHERE id = ?'
        );
        $stamp = $pdo->prepare(
            'UPDATE buffer_pushes SET cancelled_at = NOW(), cancelled_by = ? WHERE push_id = ?'
        );
        $by = $user !== null ? ((int)($user['accountId'] ?? 0) ?: null) : null;

        foreach ($rows as $r) {
            $upd->execute([(float)$r['qty_consumed'], (int)$r['buffer_id']]);
            $stamp->execute([$by, (int)$r['push_id']]);
            $out['n_pushes']++;
            $out['qty'] += (float)$r['qty_consumed'];
        }

        if ($ownTx) { $pdo->commit(); }
        return $out;

    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('poRollbackPushesForDoc ' . $docNo . ': ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage(), 'n_pushes' => 0, 'qty' => 0.0];
    }
}

/** ประตูของไซต์นั้น */
function poGates(PDO $pdo, int $projectId): array {
    $st = $pdo->prepare("SELECT id, gate_code, name FROM gates WHERE project_id = ? AND status = 'active' ORDER BY gate_code");
    $st->execute([$projectId]);
    return $st->fetchAll();
}
