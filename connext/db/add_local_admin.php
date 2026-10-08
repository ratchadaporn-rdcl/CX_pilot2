<?php
/**
 * CONNEXT — db/add_local_admin.php (เฉพาะเครื่อง local)
 * สร้าง/รีเซ็ตบัญชี admin (role ADM level 0, โครงการ ARI) — ใช้หลังรัน import_from_sheet.php --fresh
 * ซึ่งจะล้างตาราง users ทั้งหมดรวมบัญชีนี้ด้วย
 *
 * วิธีใช้ (CLI เท่านั้น):  C:\xampp\php\php.exe db\add_local_admin.php [username] [password]
 *   ค่าเริ่มต้น: admin / admin1234
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("CLI only.\n");
}
$_SERVER['SCRIPT_NAME']   = '/connext/db/add_local_admin.php';
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2);
require __DIR__ . '/../config.php';

$username = trim((string)($argv[1] ?? 'admin')) ?: 'admin';
$password = (string)($argv[2] ?? 'admin1234');

$pdo->exec("INSERT IGNORE INTO roles (role_code, name, level, can_req, can_daily_check, sc, bs)
            VALUES ('ADM', 'Administrator', 0, 1, 1, 1, 1)");
$roleId = (int)$pdo->query("SELECT id FROM roles WHERE role_code = 'ADM'")->fetchColumn();
$projId = (int)$pdo->query("SELECT id FROM projects WHERE code = 'ARI'")->fetchColumn();
if ($projId <= 0) {
    $projId = (int)$pdo->query("SELECT id FROM projects ORDER BY id LIMIT 1")->fetchColumn();
}
if ($projId <= 0) {
    fwrite(STDERR, "ERROR: ไม่มีโครงการในตาราง projects — import ข้อมูลก่อน\n");
    exit(1);
}

$st = $pdo->prepare(
    "INSERT INTO users (username, password, full_name, role_id, project_id, status)
     VALUES (?, ?, 'ผู้ดูแลระบบ (Local)', ?, ?, 'active')
     ON DUPLICATE KEY UPDATE password = VALUES(password), role_id = VALUES(role_id),
                             project_id = VALUES(project_id), status = 'active'"
);
$st->execute([$username, password_hash($password, PASSWORD_BCRYPT), $roleId, $projId]);
echo "OK: user '$username' พร้อมใช้ (role ADM level 0, project id $projId)\n";
