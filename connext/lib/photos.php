<?php
/**
 * CONNEXT — lib/photos.php : เก็บรูปถ่ายยืนยัน (แทน Drive folder ของ GAS)
 *
 * GAS (saveConfirmationData) รับ data URI `data:<mime>;base64,<payload>`
 * → เซฟลง Drive แล้วเก็บ URL คั่น comma ในคอลัมน์ PhotoURL (append ได้
 * เมื่อยืนยันซ้ำ). PHP เก็บไฟล์ใต้ uploads/photos/Y-m/ แล้วคืน path สัมพัทธ์
 *
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/../helpers.php';

/**
 * แปลง data URI → ไฟล์รูปใต้ uploads/photos/Y-m/
 * - whitelist: image/webp, image/jpeg, image/png (image/jpg = jpeg)
 * - ตรวจชนิดไฟล์จริงจาก bytes ที่ decode แล้ว (ไม่เชื่อ mime ที่ client อ้าง)
 * - ชื่อไฟล์สุ่ม 16 ไบต์ hex + นามสกุลตามชนิดจริง
 *
 * @return string|null path สัมพัทธ์ เช่น 'uploads/photos/2026-07/ab12...ef.webp'
 *                     (forward slash เสมอ) หรือ null เมื่อไม่ผ่านตรวจ
 */
function savePhotoDataUri(string $dataUri, string $prefix = ''): ?string {
    // mirror regex GAS: /^data:(.+);base64,(.*)$/ — แต่บังคับ payload ไม่ว่าง
    if (!preg_match('#^data:([^;,]+);base64,(.+)$#s', $dataUri, $m)) {
        return null;
    }
    $claimedMime = strtolower(trim($m[1]));
    $allowed = [
        'image/webp' => 'webp',
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/png'  => 'png',
    ];
    if (!isset($allowed[$claimedMime])) {
        return null;
    }

    $bytes = base64_decode($m[2], true);
    if ($bytes === false || $bytes === '') {
        return null;
    }

    // ตรวจชนิดจริงจาก bytes — ใช้ finfo; ถ้า extension ไม่มีให้ดู magic bytes
    $realMime = _photoSniffMime($bytes);
    if ($realMime === null || !isset($allowed[$realMime])) {
        return null;
    }
    $ext = $allowed[$realMime];

    $root   = defined('ROOT_PATH') ? ROOT_PATH : (dirname(__DIR__) . '/');
    $subDir = 'uploads/photos/' . date('Y-m'); // เวลาไทย (config ตั้ง tz แล้ว)
    $absDir = rtrim(str_replace('\\', '/', $root), '/') . '/' . $subDir;
    if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
        error_log('savePhotoDataUri: mkdir failed for ' . $absDir);
        return null;
    }

    // prefix (เช่น doc no) — เก็บเฉพาะอักขระปลอดภัย
    $prefix = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$prefix);

    try {
        $name = ($prefix !== '' ? $prefix . '_' : '') . bin2hex(random_bytes(16)) . '.' . $ext;
    } catch (Throwable $e) {
        error_log('savePhotoDataUri: random_bytes failed: ' . $e->getMessage());
        return null;
    }

    if (@file_put_contents($absDir . '/' . $name, $bytes) === false) {
        error_log('savePhotoDataUri: write failed for ' . $absDir . '/' . $name);
        return null;
    }

    return $subDir . '/' . $name;
}

/** ชนิด MIME จริงของ bytes (finfo ก่อน, magic bytes สำรอง) — null = ไม่รู้จัก */
function _photoSniffMime(string $bytes): ?string {
    if (class_exists('finfo')) {
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $mime = $fi->buffer($bytes);
        if (is_string($mime) && $mime !== '') {
            return strtolower($mime);
        }
    }
    // fallback magic bytes (กันโฮสต์ที่ปิด fileinfo)
    if (strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) === 0) { return 'image/png'; }
    if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0)      { return 'image/jpeg'; }
    if (strlen($bytes) >= 12 && strncmp($bytes, 'RIFF', 4) === 0
        && substr($bytes, 8, 4) === 'WEBP')            { return 'image/webp'; }
    return null;
}

/**
 * ต่อรายการ URL รูปแบบ comma-join (พฤติกรรม GAS ตอนยืนยันซ้ำ:
 * `existingPhotos ? existingPhotos + ", " + photoLinksStr : photoLinksStr`)
 */
function photoUrlsAppend(?string $existing, array $newUrls): string {
    $clean = [];
    foreach ($newUrls as $u) {
        $u = trim((string)$u);
        if ($u !== '') { $clean[] = $u; }
    }
    $joined   = implode(', ', $clean);
    $existing = trim((string)$existing);
    if ($existing === '') { return $joined; }
    if ($joined === '')   { return $existing; }
    return $existing . ', ' . $joined;
}
