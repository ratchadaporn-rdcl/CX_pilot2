<?php
/**
 * CONNEXT — cron/jobs.php : ตัวเรียกงานตั้งเวลา (lib/jobs.php) สำหรับ Windows Task Scheduler / cron — 2026-10-02
 *
 *   ตู้ออฟไลน์เกิน 5 นาที (GP-32/33 · AL-27) · reconcile ทุก 5 นาที (GP-41) · ใบยืมเกินกำหนดหลังเที่ยงคืน (OP-30) ·
 *   สุ่มนับสต๊อกรายสัปดาห์ (GP-03)
 *
 * ตั้งให้รันทุก 1 นาที (Command Prompt แบบ Administrator — ครั้งเดียว):
 *   schtasks /Create /TN "CONNEXT jobs" /SC MINUTE /MO 1 /TR "C:\xampp\php\php.exe C:\xampp\htdocs\connext\cron\jobs.php" /RU SYSTEM
 * รันมือ (บังคับทุกงานทันที): C:\xampp\php\php.exe C:\xampp\htdocs\connext\cron\jobs.php --force
 *
 * เรียกผ่านเว็บไม่ได้ (CLI เท่านั้น) · ผลแต่ละรอบต่อท้าย settings/jobs.log (เก็บ 500 บรรทัดล่าสุด)
 * PHP 7.4-compatible เท่านั้น
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
$_SERVER['SCRIPT_NAME']   = $_SERVER['SCRIPT_NAME'] ?? '/connext/cron/jobs.php';
$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2);
require __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/jobs.php';

$force = in_array('--force', $argv ?? [], true);
$res = cnxJobsMaybeRun($pdo, 'full', $force);
$line = date('Y-m-d H:i:s') . ' ' . json_encode($res, JSON_UNESCAPED_UNICODE);
echo $line . "\n";

$log = __DIR__ . '/../settings/jobs.log';
$old = is_file($log) ? (file($log, FILE_IGNORE_NEW_LINES) ?: []) : [];
$old[] = $line;
@file_put_contents($log, implode("\n", array_slice($old, -500)) . "\n", LOCK_EX);
