<?php
/**
 * CONNEXT Installer — Step 4: ติดตั้งสำเร็จ
 * PHP 7.4-compatible
 */
if (!defined('CONNEXT_INSTALLER')) { http_response_code(403); exit('Forbidden'); }

// ต้องติดตั้งสำเร็จแล้วเท่านั้น
$installedNow = is_file(INST_ROOT . '/settings/config.php') && is_file(INST_ROOT . '/settings/database.php');
if (empty($_SESSION['connext_install']['done']) || !$installedNow) {
    inst_redirect(0);
}

$sum = isset($_SESSION['connext_install']['summary']) && is_array($_SESSION['connext_install']['summary'])
    ? $_SESSION['connext_install']['summary'] : array();

$appName   = isset($sum['app_name']) ? $sum['app_name'] : 'CONNEXT';
$projCode  = isset($sum['project_code']) ? $sum['project_code'] : '';
$projName  = isset($sum['project_name']) ? $sum['project_name'] : '';
$adminUser = isset($sum['admin_username']) ? $sum['admin_username'] : '';
?>
<div class="center">
  <div class="success-icon">&#10003;</div>
  <h2>ติดตั้งสำเร็จ!</h2>
  <p class="sub" style="margin-top:6px;">
    ระบบ <?php echo inst_e($appName); ?> พร้อมใช้งานแล้ว
  </p>
</div>

<table class="kv">
  <?php if ($projCode !== ''): ?>
    <tr><td class="k">โครงการแรก</td><td class="v"><?php echo inst_e($projCode . ' — ' . $projName); ?></td></tr>
  <?php endif; ?>
  <?php if ($adminUser !== ''): ?>
    <tr><td class="k">เข้าสู่ระบบด้วยบัญชี</td><td class="v"><?php echo inst_e($adminUser); ?> (รหัสผ่านที่ตั้งไว้ในขั้นตอนที่ 2)</td></tr>
  <?php endif; ?>
  <tr><td class="k">ไฟล์ตั้งค่า</td><td class="v">settings/config.php &middot; settings/database.php</td></tr>
</table>

<div class="danger-box">
  <h3>&#9888; สำคัญมาก — ลบโฟลเดอร์ตัวติดตั้งทันที</h3>
  <p>
    กรุณาลบโฟลเดอร์ <code>install/</code> ออกจากเซิร์ฟเวอร์<strong>ทันที</strong><br>
    ถึงแม้ตัวติดตั้งจะปิดตัวเองอัตโนมัติเมื่อพบ <code>settings/config.php</code> แล้วก็ตาม<br>
    การลบโฟลเดอร์ทิ้งคือวิธีที่ปลอดภัยที่สุด
  </p>
</div>

<a class="btn btn-block" href="../index.php">เข้าสู่ระบบ <?php echo inst_e($appName); ?> &rarr;</a>
