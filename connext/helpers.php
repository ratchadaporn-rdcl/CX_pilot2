<?php
/**
 * CONNEXT — helpers.php : utilities + PHP 7.4 polyfills
 */

// ---- PHP 8 polyfills (server เป้าหมาย 7.4) ----------------------------
if (!function_exists('str_contains')) {
    function str_contains(string $h, string $n): bool { return $n === '' || strpos($h, $n) !== false; }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $h, string $n): bool { return strncmp($h, $n, strlen($n)) === 0; }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $h, string $n): bool {
        $len = strlen($n);
        return $len === 0 || substr($h, -$len) === $n;
    }
}

// ---- Output escaping ---------------------------------------------------
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ---- JSON responses (ใช้ใน api/) ---------------------------------------
function jsonOut(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function jsonFail(string $error, int $code = 200): void {
    // หมายเหตุ: default 200 เพราะ shim ฝั่ง client แปลง ok=false → failure handler เอง
    jsonOut(['ok' => false, 'error' => $error], $code);
}

// ---- เวลาไทย / รูปแบบวันที่ตามระบบเดิม ---------------------------------
/** เวลาปัจจุบัน (Asia/Bangkok — ตั้งใน config แล้ว) */
function nowBkk(): DateTime { return new DateTime('now'); }

/** DDMMYY สำหรับเลขเอกสาร เช่น 130526 (พฤติกรรมเดิมของ GAS bangkokDate_) */
function docDateKey(?DateTime $dt = null): string {
    $dt = $dt ?: nowBkk();
    return $dt->format('dmy');
}

/** 'd/m/Y, G:i:s' — รูปแบบ timestamp ที่ GAS เขียนลงชีต (เช่น 17/6/2026, 6:41:10) */
function gasTimestamp(?DateTime $dt = null): string {
    $dt = $dt ?: nowBkk();
    return $dt->format('j/n/Y, G:i:s');
}

/** แปลงสตริง timestamp แบบชีตเดิม ('17/6/2026, 6:41:10' หรือ '1/7/2026') → DateTime|null */
function parseGasTimestamp(string $s): ?DateTime {
    $s = trim($s);
    if ($s === '') return null;
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})(?:,?\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$#', $s, $m)) {
        $dt = new DateTime();
        $dt->setDate((int)$m[3], (int)$m[2], (int)$m[1]);
        $dt->setTime((int)($m[4] ?? 0), (int)($m[5] ?? 0), (int)($m[6] ?? 0));
        return $dt;
    }
    $ts = strtotime($s);
    return $ts !== false ? (new DateTime())->setTimestamp($ts) : null;
}

/** ชื่อเดือนไทย + ปี พ.ศ. (ใช้ใน PDF ตามเดิม) */
function thaiMonthName(int $m): string {
    $names = [1=>'มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน',
              'กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
    return $names[$m] ?? '';
}
function thaiDateFull(DateTime $dt): string {
    return $dt->format('j') . ' ' . thaiMonthName((int)$dt->format('n')) . ' ' . ((int)$dt->format('Y') + 543);
}

// ---- ค่าคงที่ธุรกิจ (ยกจาก Code.js — ห้ามเปลี่ยนค่า) --------------------
class Doc {
    const TYPE_RD = 'RD'; // เบิกวัสดุหลัก
    const TYPE_OD = 'OD'; // เบิกเบ็ดเตล็ด
    const TYPE_BD = 'BD'; // ยืม-คืน
    const TYPE_IN = 'IN'; // รับเข้าคลัง

    // สถานะเอกสาร — สตริงเดิมเป๊ะ (client เทียบสตริง)
    const ST_AWAITING   = 'Awaiting approval';
    const ST_APPROVED   = 'Approved';
    const ST_COMPLETED  = 'Completed';
    const ST_CANCELLED  = 'Cancelled';
    const ST_REJECTED   = 'Rejected';
    const ST_SENT_BORROW = 'Sent Borrow';
    const ST_BORROWED   = 'Borrowed';
    const ST_SENT_RETURN = 'Sent Return';
    const ST_RETURNED   = 'Returned';
    const ST_SENT_INBOUND = 'Sent Inbound';

    // สถานะที่นับเป็น Pending stock — [2026-10-06] เฉพาะอนุมัติแล้ว (GAS นับ 'awaiting approval' ด้วย) · ใช้จริงที่ recalcPending (lib/stock.php)
    const PENDING_STATUSES = ['approved', 'sent borrow'];
}
class Gate {
    const ST_AWAITING  = 'Awaiting';
    const ST_OPENED    = 'Opened';
    const ST_SCANNED   = 'Scanned';
    const ST_CONFIRMED = 'Confirmed';
    const ST_CLOSED    = 'Closed';
    const ST_CANCELLED = 'Cancelled'; // cancelRequisition เขียนลง gate log ให้ประตูเลิกฟัง doc นี้
}
const VAT_RATE = 0.07; // ใบหักเงิน VAT 7%

// ---- เปรียบเทียบแบบระบบเดิม --------------------------------------------
/** เทียบ username แบบ case-insensitive + trim (พฤติกรรม eqUser_ ของ GAS) */
function eqUser(string $a, string $b): bool {
    return mb_strtolower(trim($a), 'UTF-8') === mb_strtolower(trim($b), 'UTF-8');
}
/** ค่า boolean จากชีต/DB ('TRUE', true, 1, 'ใช่') — พฤติกรรม _isTrueFlag_ */
function isTrueFlag($v): bool {
    if (is_bool($v)) return $v;
    $s = mb_strtolower(trim((string)$v), 'UTF-8');
    return in_array($s, ['true', '1', 'yes', 'y', 'ใช่'], true);
}
/** SubID เก็บแบบ 3 หลักเสมอ (พฤติกรรม fmtSubId_: '2' → '002') */
function fmtSubId($v): string {
    $s = trim((string)$v);
    if ($s !== '' && ctype_digit($s)) return str_pad($s, 3, '0', STR_PAD_LEFT);
    return $s;
}

// ---- LIKE escape (§12.7) -----------------------------------------------
function likeEscape(string $raw): string {
    return str_replace(['%', '_'], ['\\%', '\\_'], $raw);
}

// ---- Asset URL + cache-bust ตาม filemtime (pwa-advanced §2) --------------
function assetHref(string $rel): string {
    $mt = @filemtime(ROOT_PATH . ltrim($rel, '/')) ?: APP_VERSION;
    return APP_BASE . '/' . ltrim($rel, '/') . '?v=' . $mt;
}
