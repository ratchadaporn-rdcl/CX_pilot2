<?php
/**
 * CONNEXT — db/import_llp_master.php : นำเข้าบันได LLP + การผูก Mango → LLP จากไฟล์ของฝ่ายจัดซื้อ (CLI)
 *
 * ตัวอ่าน/ตัวเขียนจริงอยู่ที่ lib/llp_import.php (หน้า setup_master.php เรียกชุดเดียวกัน)
 *
 * วิธีใช้:
 *   php db/import_llp_master.php [--file="D:\...\Data\สร้าง LLP.xlsx"] [--dry] [--no-charcat] [--quiet]
 *     --file        ไม่ระบุ = เดาจากโฟลเดอร์ Data ข้าง ๆ โปรเจกต์
 *     --dry         ตรวจอย่างเดียว ไม่เขียน DB
 *     --no-charcat  ไม่ต้องสรุป CatID/CharID ให้ LLP (ปล่อยให้ ADM ตั้งเองทั้งหมด)
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("CLI only.\n");
}
$_SERVER['SCRIPT_NAME']   = '/connext/db/import_llp_master.php';
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2);
require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/llp_import.php';

$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) { $opt[$m[1]] = $m[2] ?? '1'; }
}
$DRY   = isset($opt['dry']);
$QUIET = isset($opt['quiet']);
$FILE  = (string)($opt['file'] ?? llpDefaultFile());

if ($FILE === '' || !is_file($FILE)) {
    fwrite(STDERR, "ERROR: ไม่พบไฟล์" . ($FILE !== '' ? " $FILE" : '') . "\n  ระบุด้วย --file=\"...\\สร้าง LLP.xlsx\"\n");
    exit(1);
}
$say = function (string $s) use ($QUIET) { if (!$QUIET) { echo $s . "\n"; } };
$say('อ่านไฟล์: ' . $FILE . ($DRY ? '   [DRY RUN — ไม่เขียน DB]' : ''));

if (!smSchemaReady($pdo)) {
    $say('อัปเดตโครงสร้าง mango_ic_map ก่อน…');
    if (!$DRY) { foreach (smSchemaUpgrade($pdo) as $s) { $say('  ' . $s); } }
}

try {
    $p = llpImportRead($pdo, $FILE, !isset($opt['no-charcat']));
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: อ่านไฟล์ไม่สำเร็จ: ' . $e->getMessage() . "\n");
    exit(1);
}
$s = $p['stat'];

$say(sprintf('LL Code: L1 = %d · L2 = %d%s', $s['l1'], $s['l2'], $s['ll_bad'] ? ' · ข้ามแถวรูปแบบผิด ' . $s['ll_bad'] : ''));
$say(sprintf('Create_LLP_All Mat: LLP ไม่ซ้ำ = %d (ใหม่ %d) · รหัส Mango = %d · คู่ = %d',
    $s['llp'], $s['llp_new'], $s['mat_file'], $s['pair_file']));
$say(sprintf('  ข้าม: LLP ไม่ครบ 8 หลัก %d · LL ไม่อยู่ในชีต LL Code %d · ไม่มีรหัส Mango %d · คู่ซ้ำ %d · รหัสเดียวหลาย LLP (ใช้แถวแรก) %d',
    $s['skip']['llp_sn'], $s['skip']['no_ll'], $s['skip']['no_mat'], $s['skip']['dup'], $s['skip']['multi']));
if ($s['skip_sample']) {
    $ex = [];
    foreach ($s['skip_sample'] as $x) { $ex[] = $x['mat'] . ' → "' . $x['llp'] . '"'; }
    $say('  ตัวอย่างที่ยังจัดหมวดไม่เสร็จ: ' . implode(' · ', $ex));
}
$say(sprintf('ทะเบียนในระบบ %d รหัส · ไฟล์ผูกให้ได้ %d รหัส (%d คู่ · ใหม่ %d) · รหัสในไฟล์ที่ระบบไม่มี %d',
    $s['db_total'], $s['mat_use'], $s['pair_use'], $s['pair_new'], $s['mat_not_db']));
$say('  รหัสในระบบที่ไฟล์ยังไม่ได้จัด LLP = ' . $s['db_no_llp']);
if ($s['pair_conflict'] > 0) {
    $ex = [];
    foreach ($s['conflict_sample'] as $x) { $ex[] = $x['mat'] . ' (ผูก ' . $x['db'] . ' · ไฟล์ ' . $x['file'] . ')'; }
    $say('  ⚠ LLP ในไฟล์ไม่ตรงกับที่ผูกไว้ ' . $s['pair_conflict'] . ' รหัส — ไม่เปลี่ยนให้ (1 Mango = 1 LLP · เปลี่ยนเองที่จอ): '
         . implode(' · ', $ex));
}
$say(sprintf('CatID/CharID: ตั้งให้ได้ %d LLP · ค่าไม่ตรงกันในกลุ่ม (ต้องรีวิวเอง) %d · LLP ที่ไม่มี Mango ผูก %d',
    $s['cc_set'], $s['cc_mixed'], $s['cc_no_mango']));
$say('หน่วยเก็บ: เพิ่มใหม่ ' . $s['units_new'] . ' หน่วย');

if ($DRY) { $say("\n[DRY RUN] ไม่ได้เขียนอะไรลง DB"); exit(0); }

try {
    $n = llpImportApply($pdo, $p, null);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: นำเข้าไม่สำเร็จ — ไม่มีอะไรถูกเขียน: ' . $e->getMessage() . "\n");
    exit(1);
}

$say('');
$say('นำเข้าเรียบร้อย:');
$say(sprintf('  L1 %d · L2 %d%s%s', $n['l1'], $n['l2'],
    $n['l1_off'] ? ' (ปิดใช้งาน L1 เดิม ' . $n['l1_off'] . ')' : '',
    $n['l2_off'] ? ' (ปิดใช้งาน L2 เดิม ' . $n['l2_off'] . ')' : ''));
$say(sprintf('  ตัวสินค้า (LLP) เพิ่มใหม่ %d · แก้ชื่อ %d', $n['llp'], $n['llp_upd']));
$say(sprintf('  ผูก Mango → LLP เพิ่ม %d คู่', $n['pair']));
$say(sprintf('  ตั้ง CatID/CharID ให้ LLP %d ตัว (ที่เหลือรีวิวที่หน้า "ตั้งค่าตัวสินค้า (LLP)")', $n['cc']));
$say(sprintf('  หน่วยเก็บเพิ่ม %d', $n['unit']));

$st = smStats($pdo);
$say('');
$say(sprintf('สถานะตอนนี้: Mango %s รหัส · ผูก LLP แล้ว %s · ยังไม่ผูก %s · ออก IC แล้ว %s',
    number_format($st['total']), number_format($st['with_llp']), number_format($st['no_llp']), number_format($st['with_ic'])));
$say(sprintf('              ของที่ถือยอด %s รหัส → ผูก LLP แล้ว %s · ยังไม่ผูก %s',
    number_format($st['with_stock']), number_format($st['stock_llp']), number_format($st['stock_no_llp'])));
$say(sprintf('              LLP ทั้งหมด %s · ยังไม่ตั้ง CatID/CharID %s',
    number_format($st['llp_total']), number_format($st['llp_unset'])));
