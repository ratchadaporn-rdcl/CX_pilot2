<?php
/**
 * CONNEXT — db/migrate_setup_master.php : สร้างตารางผูกรหัส Mango → IC (มติ 40/41)
 *
 *   mango_ic_map (id, mat_code, ic_code, is_primary, mapped_by, mapped_at)
 *     1 แถวต่อ (Mango × IC) — 1 Mango ผูกได้หลาย IC · is_primary = IC หลัก
 *
 * ระบบที่ติดตั้งใหม่ด้วย db/db_setup.sql รุ่นนี้มีตารางครบแล้ว — สคริปต์นี้สำหรับฐานข้อมูล
 * ที่ติดตั้งไว้ก่อน · รันซ้ำได้ · ถ้าเจอคอลัมน์ materials.ic_code ของรุ่นแรก (1:1) จะย้ายข้อมูล
 * เข้าตารางใหม่แล้วถอดคอลัมน์ทิ้ง · หน้า setup_master.php ก็มีปุ่มอัปเดตโครงสร้างให้กดได้เอง
 * สำหรับโฮสต์ที่ไม่มี CLI
 *
 * วิธีใช้ (CLI):  C:\xampp\php\php.exe db\migrate_setup_master.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("CLI only.\n");
}
$_SERVER['SCRIPT_NAME']   = '/connext/db/migrate_setup_master.php';
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2);
require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/setup_master.php';

$done = smSchemaUpgrade($pdo);
if (!$done) {
    echo "OK: ตาราง mango_ic_map มีอยู่แล้ว และ materials ไม่มีคอลัมน์ ic_code รุ่นเก่าค้าง\n";
    exit(0);
}
foreach ($done as $sql) { echo "  ran: $sql\n"; }
echo "OK: อัปเดตโครงสร้างฐานข้อมูลแล้ว (" . count($done) . " คำสั่ง)\n";
