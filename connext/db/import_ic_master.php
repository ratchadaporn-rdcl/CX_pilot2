<?php
/**
 * CONNEXT — db/import_ic_master.php : ออกรหัส IC ทั้งก้อนจากไฟล์ "สร้าง LLP.xlsx" แล้วผูกกับรหัส Mango (CLI · มติ 44)
 *
 * ตัวอ่าน/ตัวเขียนจริงอยู่ที่ lib/ic_import.php (การ์ด 2b ในหน้า setup_master.php เรียกชุดเดียวกัน)
 * ต้องนำเข้า LLP ก่อน (db/import_llp_master.php) — ตัวนี้ออก IC ใต้ LLP ที่ผูกไว้แล้วเท่านั้น
 *
 * วิธีใช้:
 *   php db/import_ic_master.php [--file="D:\...\Data\สร้าง LLP.xlsx"] [--dry] [--report="...\ผล.xlsx"] [--db=ชื่อฐาน] [--quiet]
 *     --file     ไม่ระบุ = เดาจากโฟลเดอร์ Data ข้าง ๆ โปรเจกต์ (เหมือน import_llp_master.php)
 *     --dry      ตรวจอย่างเดียว ไม่เขียน DB (ยังเขียนไฟล์ --report ได้)
 *     --report   เขียนไฟล์ตรวจผล .xlsx (ทะเบียน IC · Mango → IC · ขนาด/ยี่ห้อ · LLP ที่รอตั้ง Cat/Char)
 *     --db       ต่อฐานอื่นแทนตามไฟล์ settings (ใช้ทดสอบกับสำเนา เช่น --db=connext_test)
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("CLI only.\n");
}
$_SERVER['SCRIPT_NAME']   = '/connext/db/import_ic_master.php';
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2);
require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/llp_import.php';   // llpDefaultFile()
require __DIR__ . '/../lib/ic_import.php';

$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) { $opt[$m[1]] = $m[2] ?? '1'; }
}
$DRY    = isset($opt['dry']);
$QUIET  = isset($opt['quiet']);
$FILE   = (string)($opt['file'] ?? llpDefaultFile());
$REPORT = (string)($opt['report'] ?? '');
$say = function (string $s) use ($QUIET) { if (!$QUIET) { echo $s . "\n"; } };

if (isset($opt['db'])) {
    $db = (string)$opt['db'];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $db)) { fwrite(STDERR, "ERROR: ชื่อฐานข้อมูลไม่ถูกต้อง\n"); exit(1); }
    $pdo = new PDO('mysql:host=' . $DB_SETTINGS['host'] . ';dbname=' . $db . ';charset=utf8mb4',
                   $DB_SETTINGS['user'], $DB_SETTINGS['pass'],
                   [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false]);
    $pdo->exec("SET time_zone = '+07:00'");
    $say('ฐานข้อมูล: ' . $db . ' (แทน ' . $DB_SETTINGS['dbname'] . ')');
}

if ($FILE === '' || !is_file($FILE)) {
    fwrite(STDERR, "ERROR: ไม่พบไฟล์" . ($FILE !== '' ? " $FILE" : '') . "\n  ระบุด้วย --file=\"...\\สร้าง LLP.xlsx\"\n");
    exit(1);
}
if (!smSchemaReady($pdo)) {
    fwrite(STDERR, "ERROR: ยังไม่มีตาราง mango_ic_map — รัน db/migrate_setup_master.php และ db/import_llp_master.php ก่อน\n");
    exit(1);
}
if (!icHasFlagColumns($pdo) && !$DRY) {
    foreach (icEnsureFlagColumns($pdo) as $s) { $say('  ' . $s); }
}
$say('อ่านไฟล์: ' . $FILE . ($DRY ? '   [DRY RUN — ไม่เขียน DB]' : ''));

$t0 = microtime(true);
try {
    $p = icImportRead($pdo, $FILE);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: อ่านไฟล์ไม่สำเร็จ: ' . $e->getMessage() . "\n");
    exit(1);
}
$s   = $p['stat'];
$lab = iciStatusLabels();

$say(sprintf('IC_All Mat: %s รหัส Mango%s · ทะเบียนระบบ %s รหัส',
    number_format($s['file_rows']), $s['dup_rows'] ? ' (แถวซ้ำ ' . $s['dup_rows'] . ')' : '', number_format($s['sys_total'])));
$say(sprintf('รหัส IC ในแผน %s (ใหม่ %s · มีอยู่แล้ว %s) · รวมหลาย Mango %s · ธง Serial %s',
    number_format($s['ic_total']), number_format($s['ic_new']), number_format($s['ic_exist']),
    number_format($s['ic_multi']), number_format($s['ic_serial'])));
$say(sprintf('พจนานุกรม: ขนาดมาตรฐาน %d (ใหม่ %d) · ยี่ห้อใหม่ %d · หน่วยใหม่ %d · คุณสมบัติใหม่ %s',
    $s['std_sizes'], $s['size_new'], $s['brand_new'], $s['unit_new'], number_format($s['extra_new'])));
$say('ผลต่อรหัส Mango (ทั้งไฟล์ · ในระบบ · ถือยอด):');
foreach ($lab as $k => $t) {
    if (($s['by'][$k] ?? 0) === 0 && ($s['sys_by'][$k] ?? 0) === 0) { continue; }
    $say(sprintf('  - %s: %s · %s · %s', $t, number_format($s['by'][$k] ?? 0),
        number_format($s['sys_by'][$k] ?? 0), number_format($s['stock_by'][$k] ?? 0)));
}
if ($s['block_llp'] > 0) {
    $say('  → ตัวสินค้า ' . number_format($s['block_llp']) . ' ตัวยังไม่ตั้ง CatID/CharID — ตั้งที่ llp_master.php แล้วรันซ้ำ');
}
$say(sprintf('(อ่าน+วางแผน %.1f วินาที)', microtime(true) - $t0));

$n = null;
if (!$DRY) {
    $t1 = microtime(true);
    try {
        $n = icImportApply($pdo, $p, null);
    } catch (Throwable $e) {
        fwrite(STDERR, 'ERROR: เขียนไม่สำเร็จ — ไม่มีอะไรถูกบันทึก: ' . $e->getMessage() . "\n");
        exit(1);
    }
    $say('');
    $say(sprintf('เขียนเรียบร้อย (%.1f วินาที): IC %s · materials %s · ผูก Mango → IC %s%s',
        microtime(true) - $t1, number_format($n['ic']), number_format($n['material']), number_format($n['map']),
        $n['map_skip'] ? ' (ข้าม ' . $n['map_skip'] . ' — มีคนผูก/ถอดระหว่างนั้น)' : ''));
    $say(sprintf('  ขนาด %d · ยี่ห้อ %d · หน่วย %d · คุณสมบัติ %s · ผูกขนาด/ยี่ห้อกับหมวด %d/%d%s',
        $n['size'], $n['brand'], $n['unit'], number_format($n['extra']), $n['l2s'], $n['l2b'],
        $n['reopen'] ? ' · เปิดใช้ของเดิม ' . $n['reopen'] : ''));

    $v = icImportVerify($pdo, $p);
    $say(sprintf('ตรวจซ้ำด้วย icValidateParts: %s ตัว · ไม่ผ่าน %d', number_format($v['checked']), count($v['bad'])));
    foreach (array_slice($v['bad'], 0, 10) as $b) { $say('  ✗ ' . $b[0] . ' — ' . $b[1]); }
}

if ($REPORT !== '') {
    $bin = icImportReportXlsx($p, $n);
    if (@file_put_contents($REPORT, $bin) === false) {
        fwrite(STDERR, 'ERROR: เขียนไฟล์ตรวจผลไม่ได้: ' . $REPORT . "\n");
        exit(1);
    }
    $say('ไฟล์ตรวจผล: ' . $REPORT);
}
if ($DRY) { $say("\n[DRY RUN] ไม่ได้เขียนอะไรลง DB"); }
