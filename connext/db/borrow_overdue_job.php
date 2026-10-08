<?php
/**
 * CONNEXT — db/borrow_overdue_job.php : งานตรวจใบยืมเกินกำหนดคืน (เอกสาร 05 ③ ขั้น 5) แบบ CLI
 *
 * ปกติไม่ต้องรันเอง — config.php เรียก borrowDailyTick() ให้คำขอแรกของวัน (หลังเที่ยงคืน) เป็นคนรัน
 * สคริปต์นี้มีไว้ตั้ง Windows Task Scheduler ให้รันตรงเวลาแม้วันนั้นไม่มีใครเปิดระบบ (รันซ้ำได้ปลอดภัย —
 * error_logs ลงวันละครั้งต่อใบ)
 *
 * วิธีใช้:
 *   C:\xampp\php\php.exe C:\xampp\htdocs\connext\db\borrow_overdue_job.php [--date=YYYY-MM-DD]
 *     --date   วันที่ใช้ตัดสิน (ทดสอบ) — ไม่ระบุ = วันนี้ (เวลาไทย)
 * ตัวอย่างตั้งเวลา 00:05 ทุกวัน (ผู้ดูแลเครื่องสั่งเอง):
 *   schtasks /Create /TN "CONNEXT borrow overdue" /SC DAILY /ST 00:05 /TR "C:\xampp\php\php.exe C:\xampp\htdocs\connext\db\borrow_overdue_job.php"
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("CLI only.\n");
}
$_SERVER['SCRIPT_NAME']   = '/connext/db/borrow_overdue_job.php';
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2);
require __DIR__ . '/../config.php';   // สคีมา + งานรายวัน (ถ้าวันนี้ยังไม่ได้รัน)
require_once __DIR__ . '/../lib/borrow.php';

$date = null;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--date=(\d{4}-\d{2}-\d{2})$/', $a, $m)) { $date = $m[1]; }
}
$r = borrowOverdueRun($pdo, $date);
echo date('Y-m-d H:i:s') . ' borrow overdue: ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
