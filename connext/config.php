<?php
/**
 * CONNEXT — config.php (entry ทุกไฟล์ require ตัวนี้ก่อน)
 * PHP 7.4-compatible เท่านั้น — ห้ามใช้ syntax PHP 8
 */

// ---- Installer guard --------------------------------------------------
if (!is_file(__DIR__ . '/settings/config.php') || !is_file(__DIR__ . '/settings/database.php')) {
    // ยังไม่ติดตั้ง — ส่งไป installer (ยกเว้นกำลังอยู่ใน installer เอง)
    $self = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if (strpos($self, '/install/') === false) {
        header('Location: install/index.php');
        exit;
    }
    return; // ให้ installer ทำงานต่อโดยไม่มี DB
}

// ---- Timezone: ทุกอย่างเป็นเวลาไทย (เลขเอกสารอิง DDMMYY ไทย) ----------
date_default_timezone_set('Asia/Bangkok');

// ---- Settings ----------------------------------------------------------
$APP_SETTINGS = require __DIR__ . '/settings/config.php';
$DB_SETTINGS  = require __DIR__ . '/settings/database.php';

define('APP_NAME', $APP_SETTINGS['app_name'] ?? 'CONNEXT');
define('APP_VERSION', '1.0.0');
define('APP_ENV', $APP_SETTINGS['env'] ?? 'production');

// APP_BASE — subdirectory-safe (ห้าม hard-code path)
$_docRoot = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: ''), '/');
$_cfgDir  = rtrim(str_replace('\\', '/', realpath(__DIR__)), '/');
$_rel     = ($_docRoot !== '' && strpos($_cfgDir, $_docRoot) === 0)
    ? ltrim(substr($_cfgDir, strlen($_docRoot)), '/')
    : '';
define('APP_BASE', $_rel !== '' ? '/' . rtrim($_rel, '/') : '');
define('ROOT_PATH', __DIR__ . '/');

// APP_BUILD — ใช้เทียบเวอร์ชันฝั่ง client (แทน MD5(Index.html) ของ GAS)
// อิง filemtime ของไฟล์หลัก → แก้ไฟล์แล้ว client เห็น banner อัปเดตเอง
$_buildParts = [];
foreach (['index.php', 'js/app.js', 'css/app.css'] as $_bf) {
    if (is_file(ROOT_PATH . $_bf)) { $_buildParts[] = (string)filemtime(ROOT_PATH . $_bf); }
}
define('APP_BUILD', APP_VERSION . '-' . substr(md5(implode('|', $_buildParts)), 0, 10));

// ---- Error handling ----------------------------------------------------
if (APP_ENV === 'development') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}
ini_set('log_errors', '1');

// ---- Database ----------------------------------------------------------
try {
    $pdo = new PDO(
        'mysql:host=' . $DB_SETTINGS['host'] . ';dbname=' . $DB_SETTINGS['dbname'] . ';charset=utf8mb4',
        $DB_SETTINGS['user'],
        $DB_SETTINGS['pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    // ให้ NOW()/CURRENT_TIMESTAMP ฝั่ง MySQL ตรงเวลาไทยเสมอ (shared hosting ตั้ง tz อื่นบ่อย)
    $pdo->exec("SET time_zone = '+07:00'");
} catch (Throwable $e) {
    error_log('DB connect failed: ' . $e->getMessage());
    http_response_code(500);
    die('ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาติดต่อผู้ดูแลระบบ');
}

require_once __DIR__ . '/helpers.php';

// ---- Scenario 05 (2026-09-28): คอลัมน์หยิบจริง/รูปรายรายการ + ตาราง gate_settings / gate_round_events ----
// สร้างเองครั้งแรก แล้วจดเครื่องหมายไว้ที่ settings/.s05-schema-v1-<db> (คำขอถัดไปไม่แตะ DB)
require_once __DIR__ . '/lib/s05.php';
s05EnsureSchema($pdo, (string)($DB_SETTINGS['dbname'] ?? ''));

// ---- Scenario 05 ใบยืม (2026-09-29): กำหนดวันคืน · คืนบางส่วน · ตีชำรุด/สูญหาย + ตรวจเกินกำหนดรายวัน ----
// สคีมาสร้างเองครั้งแรก (settings/.borrow-schema-v1-<db>) · งานตรวจเกินกำหนดรันกับคำขอแรกของวัน (settings/.borrow-daily-<db>)
require_once __DIR__ . '/lib/borrow.php';
borrowEnsureSchema($pdo, (string)($DB_SETTINGS['dbname'] ?? ''));
borrowDailyTick($pdo, (string)($DB_SETTINGS['dbname'] ?? ''));

// ---- ความปลอดภัยตู้ (2026-10-02 · GP-17/21/22/23/46): app_settings · gates.has_cctv / api_key_* ----
// (settings/.appsettings-schema-v1-<db> · settings/.gatesec-schema-v1-<db>) — lib/gate_sec.php · lib/qr_sign.php
require_once __DIR__ . '/lib/gate_sec.php';
appSettingsEnsureSchema($pdo, (string)($DB_SETTINGS['dbname'] ?? ''));
gateSecEnsureSchema($pdo, (string)($DB_SETTINGS['dbname'] ?? ''));
// ---- สัญญาณชีพตู้ + งานตั้งเวลา (2026-10-02 · GP-30/31/32/33/41 · AL-27): ตาราง gate_status — lib/gate_health.php · lib/jobs.php ----
require_once __DIR__ . '/lib/gate_health.php';
ghEnsureSchema($pdo, (string)($DB_SETTINGS['dbname'] ?? ''));

// ---- ใบรับเข้าคลัง IN (2026-10-02 · GP-10): สายสโตร์ของไซต์เท่านั้น · ที่มาของของ + รูปใบส่งของ/รูปของ ----
// documents.in_source / in_ref / in_reason / in_photo_url (settings/.inctl-schema-v1-<db>)
require_once __DIR__ . '/lib/inbound_ctl.php';
inCtlEnsureSchema($pdo, (string)($DB_SETTINGS['dbname'] ?? ''));

// ---- เอกสารชนิดเพิ่ม (2026-09-29): TD เบิกโอนย้ายข้ามไซต์ · SC ใบนับสต๊อก ----
// doc_type ENUM + 'TD','SC' · documents.dest_project_id · ตาราง stock_adjustments (settings/.docext-schema-v1-<db>)
require_once __DIR__ . '/lib/doc_ext.php';
docExtEnsureSchema($pdo, (string)($DB_SETTINGS['dbname'] ?? ''));

// ---- ย้าย Gate (2026-10-02): TG ใบย้ายของระหว่าง G ภายในไซต์ (QR เบิกออก G ต้นทาง → QR นำเข้า G ปลายทาง) ----
// doc_type ENUM + 'TG' · documents.dest_gate_id · gate_logs.leg + 'in' (settings/.gatemove-schema-v1-<db>)
require_once __DIR__ . '/lib/gatemove.php';
gmEnsureSchema($pdo, (string)($DB_SETTINGS['dbname'] ?? ''));
