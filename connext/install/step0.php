<?php
/**
 * CONNEXT Installer — Step 0: ตรวจสอบความพร้อมของระบบ (pre-flight)
 * PHP 7.4-compatible
 */
if (!defined('CONNEXT_INSTALLER')) { http_response_code(403); exit('Forbidden'); }

$checks = array();

// 1) PHP version
$checks[] = array(
    'label'    => 'PHP เวอร์ชัน 7.4 ขึ้นไป',
    'detail'   => 'เวอร์ชันปัจจุบัน: ' . PHP_VERSION,
    'pass'     => (PHP_VERSION_ID >= 70400),
    'required' => true,
);

// 2) Extensions ที่จำเป็น
$requiredExt = array(
    'pdo_mysql' => 'ใช้เชื่อมต่อฐานข้อมูล MySQL/MariaDB',
    'mbstring'  => 'ใช้จัดการข้อความภาษาไทย (UTF-8)',
    'fileinfo'  => 'ใช้ตรวจสอบชนิดไฟล์ที่อัปโหลด',
);
foreach ($requiredExt as $ext => $why) {
    $checks[] = array(
        'label'    => 'PHP Extension: ' . $ext,
        'detail'   => $why,
        'pass'     => extension_loaded($ext),
        'required' => true,
    );
}

// 3) gd — ไม่บังคับ (ไม่ได้ใช้ในส่วนสำคัญ)
$checks[] = array(
    'label'    => 'PHP Extension: gd (ไม่บังคับ)',
    'detail'   => 'ไม่มีผลต่อการทำงานหลักของระบบ',
    'pass'     => extension_loaded('gd'),
    'required' => false,
);

// 4) โฟลเดอร์ที่ต้องเขียนได้
$writableDirs = array(
    'settings' => 'เก็บไฟล์ตั้งค่าระบบ (config.php / database.php)',
    'uploads'  => 'เก็บรูปถ่ายและไฟล์แนบเอกสาร',
);
foreach ($writableDirs as $dir => $why) {
    $path = INST_ROOT . '/' . $dir;
    $ok   = is_dir($path) && is_writable($path);
    $checks[] = array(
        'label'    => 'โฟลเดอร์ ' . $dir . '/ เขียนได้',
        'detail'   => $ok ? $why : 'ไม่พบโฟลเดอร์หรือไม่มีสิทธิ์เขียน — กรุณาสร้างโฟลเดอร์และตั้งสิทธิ์ให้ PHP เขียนได้',
        'pass'     => $ok,
        'required' => true,
    );
}

// 5) ไฟล์ schema ที่ต้องใช้ตอนติดตั้ง
$sqlOk = is_file(INST_ROOT . '/db/db_setup.sql') && is_readable(INST_ROOT . '/db/db_setup.sql');
$checks[] = array(
    'label'    => 'พบไฟล์ db/db_setup.sql',
    'detail'   => $sqlOk ? 'โครงสร้างฐานข้อมูลพร้อมติดตั้ง' : 'ไม่พบไฟล์ db/db_setup.sql — กรุณาอัปโหลดไฟล์ให้ครบ',
    'pass'     => $sqlOk,
    'required' => true,
);

// สรุปผล
$allRequiredPass = true;
$hasWarning = false;
foreach ($checks as $c) {
    if ($c['required'] && !$c['pass']) { $allRequiredPass = false; }
    if (!$c['required'] && !$c['pass']) { $hasWarning = true; }
}
if ($allRequiredPass) {
    inst_allow(1);
}
?>
<h2>ขั้นตอนที่ 1 — ตรวจสอบความพร้อมของระบบ</h2>
<p class="sub">ตรวจสอบเวอร์ชัน PHP, ส่วนขยาย และสิทธิ์การเขียนไฟล์ ก่อนเริ่มติดตั้ง CONNEXT</p>

<?php if ($allRequiredPass): ?>
  <div class="alert alert-success">&#10003; ระบบพร้อมสำหรับการติดตั้ง<?php echo $hasWarning ? ' (มีคำเตือนบางรายการ แต่ไม่กระทบการใช้งาน)' : ''; ?></div>
<?php else: ?>
  <div class="alert alert-error">&#10007; ระบบยังไม่พร้อม — กรุณาแก้ไขรายการที่ไม่ผ่าน แล้วกด "ตรวจสอบอีกครั้ง"</div>
<?php endif; ?>

<table class="checks">
  <?php foreach ($checks as $c): ?>
  <tr>
    <td>
      <?php echo inst_e($c['label']); ?>
      <span class="hint"><?php echo inst_e($c['detail']); ?></span>
    </td>
    <td class="st">
      <?php if ($c['pass']): ?>
        <span class="st-pass">&#10003; ผ่าน</span>
      <?php elseif (!$c['required']): ?>
        <span class="st-warn">&#9888; เตือน</span>
      <?php else: ?>
        <span class="st-fail">&#10007; ไม่ผ่าน</span>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
</table>

<div class="btn-row">
  <a class="btn btn-ghost" href="index.php?step=0">&#8635; ตรวจสอบอีกครั้ง</a>
  <?php if ($allRequiredPass): ?>
    <a class="btn right" href="index.php?step=1">ถัดไป &rarr;</a>
  <?php else: ?>
    <span class="btn right disabled">ถัดไป &rarr;</span>
  <?php endif; ?>
</div>
