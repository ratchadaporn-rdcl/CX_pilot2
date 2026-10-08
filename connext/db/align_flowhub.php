<?php
/**
 * CONNEXT — db/align_flowhub.php : จัดเลข LLP/IC ในระบบให้ตรง LLP-Flowhub.xlsx (CLI · มติ 48)
 *
 * ตัวจริงอยู่ที่ lib/llp_align.php — IC เปลี่ยนเลขในที่ (material id เดิม ยอด/เอกสารไม่ต้องย้าย)
 * ทรานแซกชันเดียว ตรวจความถูกต้องก่อน commit · สำรองฐานก่อนรันของจริงทุกครั้ง
 *
 * วิธีใช้:
 *   php db/align_flowhub.php --file="D:\...\Data\LLP-Flowhub.xlsx" [--dry] [--report="...\แผน.xlsx"] [--db=ชื่อฐาน] [--quiet]
 *     --dry      วางแผนอย่างเดียว ไม่เขียน DB (ยังเขียนไฟล์ --report ได้)
 *     --report   ไฟล์ .xlsx แผน/ผล (ตัวสินค้า · IC เปลี่ยนเลข · Mango ย้าย · ประเด็น)
 *     --db       ต่อฐานอื่นแทนตามไฟล์ settings (ทดสอบกับสำเนา เช่น --db=connext_test)
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("CLI only.\n");
}
$_SERVER['SCRIPT_NAME']   = '/connext/db/align_flowhub.php';
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2);
require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/llp_align.php';

$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) { $opt[$m[1]] = $m[2] ?? '1'; }
}
$DRY    = isset($opt['dry']);
$QUIET  = isset($opt['quiet']);
$FILE   = (string)($opt['file'] ?? '');
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
    fwrite(STDERR, "ERROR: ไม่พบไฟล์" . ($FILE !== '' ? " $FILE" : '') . "\n  ระบุด้วย --file=\"...\\LLP-Flowhub.xlsx\"\n");
    exit(1);
}
if (!smSchemaReady($pdo)) {
    fwrite(STDERR, "ERROR: ยังไม่มีตาราง mango_ic_map — รัน db/migrate_setup_master.php ก่อน\n");
    exit(1);
}
$say('อ่านไฟล์: ' . $FILE . ($DRY ? '   [DRY RUN — ไม่เขียน DB]' : ''));

$t0 = microtime(true);
try {
    $p = lalPlan($pdo, $FILE);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: วางแผนไม่สำเร็จ: ' . $e->getMessage() . "\n");
    exit(1);
}
$s = $p['stat'];
$say(sprintf('ไฟล์: %s แถว · รหัส LLP %s · รหัส Mango %s (ไม่มีในทะเบียนระบบ %s) · แถวไม่มีรหัส Mango %s',
    number_format($s['fh_rows']), number_format($s['fh_codes']), number_format($s['fh_mango']), number_format($s['fh_mango_missing']),
    number_format($s['fh_no_mango'] ?? 0)));
$say(sprintf('Mango ที่จะตรงไฟล์หลังจัด: %s / %s · ย้ายตัวสินค้า %s · ผูกใหม่ %s',
    number_format($s['fh_mango_aligned']), number_format($s['fh_mango_target']), number_format($s['mango_moves']), number_format($s['mango_new'])));
$say(sprintf('IC เปลี่ยนเลข %s (ยอดคงเหลือบนตัวที่เปลี่ยน %s — ไม่ย้ายยอด) · ขนาดใหม่ %d',
    number_format($s['ic_moves']), number_format($s['ic_stock'], 2), $s['sizes_new']));
$say(sprintf('ตัวสินค้า: สร้าง %d · เปลี่ยนชื่อ %d · แก้ Cat/Char %d · ลบ %d · หลบไปเลขถัดไป %d',
    $s['llp_new'], $s['llp_rename'], $s['llp_charcat'], $s['llp_delete'], $s['displaced']));
$byType = [];
foreach ($p['issues'] as $x) { $byType[$x['type']][] = $x['msg']; }
foreach ($byType as $t => $msgs) {
    $say('ประเด็น ' . $t . ': ' . count($msgs));
    foreach (array_slice($msgs, 0, 6) as $m) { $say('  - ' . $m); }
}
$say(sprintf('(วางแผน %.1f วินาที)', microtime(true) - $t0));

$n = null;
if (!$DRY) {
    if ($s['collisions'] > 0) {
        fwrite(STDERR, 'ERROR: มี IC ที่จะได้รหัสซ้ำกัน ' . $s['collisions'] . " ตัว — รวมยอดก่อนแล้วรันใหม่ (ไม่ได้เขียนอะไร)\n");
        exit(1);
    }
    $t1 = microtime(true);
    try {
        $n = lalApply($pdo, $p, null);
    } catch (Throwable $e) {
        fwrite(STDERR, 'ERROR: เขียนไม่สำเร็จ — ไม่มีอะไรถูกบันทึก: ' . $e->getMessage() . "\n");
        exit(1);
    }
    $say('');
    $say(sprintf('เขียนเรียบร้อย (%.1f วินาที): IC %s · แถวอ้างอิง %s · Mango ย้าย %s ผูกใหม่ %s · LLP สร้าง %d แก้ %d ลบ %d · ขนาด %d · คุณสมบัติ %s',
        microtime(true) - $t1, number_format($n['ic']), number_format($n['refs']), number_format($n['map']), number_format($n['map_new']),
        $n['llp_new'], $n['llp_upd'], $n['llp_del'], $n['sizes'], number_format($n['extras'])));
}
if ($REPORT !== '') {
    if (@file_put_contents($REPORT, lalReportXlsx($p, $n)) === false) {
        fwrite(STDERR, 'ERROR: เขียนไฟล์รายงานไม่ได้: ' . $REPORT . "\n");
        exit(1);
    }
    $say('ไฟล์รายงาน: ' . $REPORT);
}
if ($DRY) { $say("\n[DRY RUN] ไม่ได้เขียนอะไรลง DB"); }
