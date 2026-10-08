<?php
/**
 * CONNEXT — lib/inbound_ctl.php : คุมการออกใบรับเข้าคลัง (IN) — GP-10 / OP-70 (ผลพิจารณา 2 ต.ค. 2026)
 *
 *   (1) ของจาก supplier / PO ต้องแนบรูปใบส่งของ (+ เลขที่ RS / PO / ใบส่งของ)
 *   (2) ไม่มีใบส่งของ: ใส่เลขอ้างอิงใบ TD / BD หรือเหตุผล + รูปของที่รับเข้า
 *   (3) ออกใบ IN ได้เฉพาะสายสโตร์ (roles.can_req) ของไซต์นั้น · ชื่อผู้ออกใบ = บัญชีที่ล็อกอิน
 *       ADM (ระดับ 0 + can_req) ออกให้ไซต์ไหนก็ได้ (ไซต์ตามที่เลือก — เหมือนเดิม)
 *
 * เดิม (โค้ด 29 ก.ย.): ทุกบัญชีที่ล็อกอินสร้าง IN ได้ · ชื่อผู้ขอ/ไซต์มาจากข้อมูลที่หน้าจอส่ง · ไม่มีหลักฐานที่มาของของ
 *   → ออกใบ IN ปลอมเพิ่มยอดกลบของหายได้
 *
 * สคีมา: documents.in_source / in_ref / in_reason / in_photo_url (สร้างเองครั้งแรก — settings/.inctl-schema-v1-<db>)
 * ผู้เรียก: rpc_processInboundBatch (lib/documents.php) · poPushBatch (lib/po.php — หน้า "รับของตามใบ PO")
 *
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/photos.php';

const IN_SRC_SUPPLIER = 'supplier';   // มีใบส่งของ (supplier / PO)
const IN_SRC_NONOTE   = 'nonote';     // ไม่มีใบส่งของ — อ้างอิงใบ TD / BD หรือเหตุผล
const IN_MAX_PHOTOS   = 6;

function inCtlEnsureSchema(PDO $pdo, string $dbName = ''): void {
    static $done = [];
    $key = $dbName !== '' ? $dbName : '_';
    if (isset($done[$key])) { return; }
    $done[$key] = true;

    $safe   = preg_replace('/[^A-Za-z0-9_]/', '_', $key);
    $marker = __DIR__ . '/../settings/.inctl-schema-v1-' . $safe;
    if (is_file($marker)) { return; }
    if ($pdo->inTransaction()) { return; }

    try {
        $cols = [];
        foreach ($pdo->query('SHOW COLUMNS FROM documents')->fetchAll() as $c) { $cols[(string)$c['Field']] = true; }
        $add = [];
        if (!isset($cols['in_source'])) {
            $add[] = "ADD COLUMN in_source VARCHAR(12) NULL DEFAULT NULL COMMENT 'ใบ IN: supplier = มีใบส่งของ (supplier/PO) · nonote = ไม่มีใบส่งของ (GP-10)'";
        }
        if (!isset($cols['in_ref'])) {
            $add[] = "ADD COLUMN in_ref VARCHAR(30) NULL DEFAULT NULL COMMENT 'ใบ IN ไม่มีใบส่งของ: เลขใบ TD/BD ที่อ้างอิง'";
        }
        if (!isset($cols['in_reason'])) {
            $add[] = "ADD COLUMN in_reason VARCHAR(255) NULL DEFAULT NULL COMMENT 'ใบ IN ไม่มีใบส่งของ: เหตุผล'";
        }
        if (!isset($cols['in_photo_url'])) {
            $add[] = "ADD COLUMN in_photo_url TEXT NULL DEFAULT NULL COMMENT 'ใบ IN: รูปใบส่งของ (supplier) / รูปของที่รับเข้า (nonote) — คั่น , '";
        }
        if ($add) { $pdo->exec('ALTER TABLE documents ' . implode(', ', $add)); }
        @file_put_contents($marker, date('c') . "\n");
    } catch (Throwable $e) {
        error_log('inCtlEnsureSchema: ' . $e->getMessage());
    }
}

/**
 * สิทธิ์ออกใบรับเข้าคลัง — ตรวจจาก roles ใน DB (ไม่เชื่อค่าที่หน้าจอส่ง)
 * @return array ['ok' => bool, 'message' => string, 'admin' => bool (ADM: ออกให้ไซต์ไหนก็ได้)]
 */
function inCtlAccess(PDO $pdo, ?array $user): array {
    $no = function (string $m) { return ['ok' => false, 'message' => $m, 'admin' => false]; };
    if (!$user) { return $no('session หมดอายุ — ล็อกอินใหม่'); }
    if (($user['accountType'] ?? '') !== 'user') {
        return $no('ออกใบรับเข้าคลังได้เฉพาะสายสโตร์ของไซต์ (บัญชีผู้รับเหมาออกไม่ได้)');
    }
    $r = docExtUserRole($pdo, $user);
    if (!$r['canReq'] || $r['level'] >= 99) {
        return $no('ออกใบรับเข้าคลังได้เฉพาะสายสโตร์ของไซต์ (AST / ST1 / ST2 / SST)');
    }
    $admin = ($r['level'] === 0);
    if (!$admin && (int)($user['projectId'] ?? 0) <= 0) {
        return $no('บัญชีนี้ไม่ได้ผูกกับไซต์ — ออกใบรับเข้าคลังไม่ได้');
    }
    return ['ok' => true, 'message' => '', 'admin' => $admin];
}

/**
 * ตรวจ "ที่มาของของ" ของใบ IN (ก่อนเขียนอะไรทั้งสิ้น)
 * @param array  $meta        ['source' => supplier|nonote, 'rs' => เลขที่ RS/PO/ใบส่งของ, 'ref' => เลขใบ TD/BD,
 *                             'reason' => เหตุผล, 'photos' => [data URI, ...]]
 * @param string $rsFromItems เลขที่ RS/PO จากรายการ (ช่องเดิมของฟอร์ม / เลขใบ PO ของสาย buffer)
 * @return array ['error' => ?string, 'source', 'rs', 'ref', 'reason', 'photos' => [data URI]]
 */
function inCtlCheckMeta(PDO $pdo, array $meta, string $rsFromItems, int $projectId): array {
    $out = ['error' => null, 'source' => '', 'rs' => '', 'ref' => '', 'reason' => '', 'photos' => []];
    $src = strtolower(trim((string)($meta['source'] ?? '')));
    if (!in_array($src, [IN_SRC_SUPPLIER, IN_SRC_NONOTE], true)) {
        $out['error'] = 'เลือกที่มาของของก่อน: "มีใบส่งของ (supplier / PO)" หรือ "ไม่มีใบส่งของ" (GP-10)';
        return $out;
    }
    $out['source'] = $src;

    $photos = [];
    foreach ((array)($meta['photos'] ?? []) as $p) {
        $p = is_string($p) ? trim($p) : '';
        if ($p === '') { continue; }
        if (strpos($p, 'data:image/') !== 0) {
            $out['error'] = 'รูปที่แนบไม่ใช่ไฟล์รูป — ถ่ายหรือเลือกรูปใหม่';
            return $out;
        }
        $photos[] = $p;
    }
    if (count($photos) > IN_MAX_PHOTOS) {
        $out['error'] = 'แนบรูปได้ไม่เกิน ' . IN_MAX_PHOTOS . ' รูปต่อครั้ง';
        return $out;
    }
    $out['photos'] = $photos;

    if ($src === IN_SRC_SUPPLIER) {
        $rs = trim((string)($meta['rs'] ?? ''));
        if ($rs === '') { $rs = trim($rsFromItems); }
        if ($rs === '') {
            $out['error'] = 'ของจาก supplier / PO: ต้องระบุเลขที่ใบรับสินค้า (RS) / PO / ใบส่งของ';
            return $out;
        }
        if (!$photos) {
            $out['error'] = 'ของจาก supplier / PO: ต้องแนบรูปใบส่งของอย่างน้อย 1 รูป';
            return $out;
        }
        $out['rs'] = mb_substr($rs, 0, 50, 'UTF-8');
        return $out;
    }

    // ไม่มีใบส่งของ: เลขอ้างอิง TD/BD หรือเหตุผล + รูปของ
    $ref    = strtoupper(preg_replace('/\s+/', '', (string)($meta['ref'] ?? '')));
    $reason = trim(preg_replace('/\s+/u', ' ', (string)($meta['reason'] ?? '')));
    if ($ref === '' && mb_strlen($reason, 'UTF-8') < 3) {
        $out['error'] = 'ไม่มีใบส่งของ: ใส่เลขอ้างอิงใบ TD / BD หรือเหตุผล (อย่างน้อย 3 ตัวอักษร)';
        return $out;
    }
    if ($ref !== '') {
        $refErr = inCtlRefError($pdo, $ref, $projectId);
        if ($refErr !== null) { $out['error'] = $refErr; return $out; }
    }
    if (!$photos) {
        $out['error'] = 'ไม่มีใบส่งของ: ต้องแนบรูปของที่รับเข้าอย่างน้อย 1 รูป';
        return $out;
    }
    $out['ref']    = mb_substr($ref, 0, 30);
    $out['reason'] = mb_substr($reason, 0, 255, 'UTF-8');
    return $out;
}

/**
 * เลขอ้างอิงต้องเป็นใบ TD (ไซต์นี้เป็นต้นทางหรือปลายทาง) หรือ BD ของไซต์นี้ — พิมพ์เลขฐานไม่มีรหัส G ก็ได้
 * @return string|null ข้อความผิดพลาด หรือ null เมื่อผ่าน
 */
function inCtlRefError(PDO $pdo, string $ref, int $projectId): ?string {
    if (!preg_match('/^(TD|BD)[0-9A-Z]{4,}$/', $ref, $m)) {
        return 'เลขอ้างอิงต้องเป็นเลขใบ TD (โอนย้ายข้ามไซต์) หรือ BD (ยืม) เช่น TD021026001G01';
    }
    $st = $pdo->prepare("SELECT doc_no, doc_type, project_id, dest_project_id FROM documents
                          WHERE doc_type = ? AND (doc_no = ? OR doc_no LIKE ?) ORDER BY doc_no LIMIT 20");
    $st->execute([$m[1], $ref, $ref . 'G%']);
    foreach ($st->fetchAll() as $d) {
        if ((int)$d['project_id'] === $projectId) { return null; }
        if ($m[1] === 'TD' && (int)($d['dest_project_id'] ?? 0) === $projectId) { return null; }
    }
    return 'ไม่พบใบ ' . $ref . ' ของไซต์นี้ (ใบ TD ที่ไซต์นี้เป็นต้นทาง/ปลายทาง หรือใบ BD ของไซต์นี้)';
}

/**
 * เก็บรูปลง uploads/photos/Y-m/ — รูปใดไม่ผ่านตรวจชนิดไฟล์ = ลบที่เก็บไปแล้วทั้งชุด
 * @return array ['paths' => string[], 'error' => ?string]
 */
function inCtlSavePhotos(array $dataUris, string $prefix): array {
    $paths = [];
    foreach ($dataUris as $uri) {
        $p = savePhotoDataUri((string)$uri, $prefix);
        if ($p === null) {
            inCtlDeleteFiles($paths);
            return ['paths' => [], 'error' => 'รูปที่แนบไม่ใช่ไฟล์รูปที่รองรับ (JPEG / PNG / WebP) — ถ่ายหรือเลือกรูปใหม่'];
        }
        $paths[] = $p;
    }
    return ['paths' => $paths, 'error' => null];
}

/** ลบไฟล์รูปที่เก็บไปแล้ว (ใบไม่ถูกเขียน) — รับเฉพาะพาธใต้ uploads/photos/ */
function inCtlDeleteFiles(array $paths): void {
    $root = rtrim(str_replace('\\', '/', defined('ROOT_PATH') ? ROOT_PATH : (dirname(__DIR__) . '/')), '/') . '/';
    foreach ($paths as $p) {
        $p = (string)$p;
        if (strpos($p, 'uploads/photos/') !== 0 || strpos($p, '..') !== false) { continue; }
        if (is_file($root . $p)) { @unlink($root . $p); }
    }
}

/** ข้อความ "ที่มาของของ" ของใบ IN (PDF / รายงาน) — '' = ใบก่อน 2 ต.ค. 2026 */
function inCtlSourceText(array $doc): string {
    $src = (string)($doc['in_source'] ?? '');
    if ($src === IN_SRC_SUPPLIER) {
        $rs = trim((string)($doc['rs_no'] ?? ''));
        return 'มีใบส่งของ (supplier / PO)' . ($rs !== '' ? ' · เลขที่ ' . $rs : '');
    }
    if ($src === IN_SRC_NONOTE) {
        $bits = [];
        if (trim((string)($doc['in_ref'] ?? '')) !== '')    { $bits[] = 'อ้างอิง ' . trim((string)$doc['in_ref']); }
        if (trim((string)($doc['in_reason'] ?? '')) !== '') { $bits[] = 'เหตุผล: ' . trim((string)$doc['in_reason']); }
        return 'ไม่มีใบส่งของ' . ($bits ? ' · ' . implode(' · ', $bits) : '');
    }
    return '';
}

/** ป้ายรูปที่มาของของ */
function inCtlPhotoLabel(string $source): string {
    return $source === IN_SRC_SUPPLIER ? 'รูปใบส่งของ' : 'รูปของที่รับเข้า (ไม่มีใบส่งของ)';
}
