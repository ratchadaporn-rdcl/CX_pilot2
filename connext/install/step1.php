<?php
/**
 * CONNEXT Installer — Step 1: ข้อมูลระบบ (แอป + โครงการแรก + ผู้ดูแลระบบ)
 * PHP 7.4-compatible
 */
if (!defined('CONNEXT_INSTALLER')) { http_response_code(403); exit('Forbidden'); }

/** ตรวจนโยบายรหัสผ่าน: อย่างน้อย 4 ตัวอักษร, ห้ามช่องว่าง, ห้ามอักษรไทย */
function inst_password_error($pw) {
    if (strlen($pw) < 4) {
        return 'รหัสผ่านต้องยาวอย่างน้อย 4 ตัวอักษร';
    }
    if (preg_match('/\s/u', $pw)) {
        return 'รหัสผ่านต้องไม่มีช่องว่าง';
    }
    if (preg_match('/[\x{0E00}-\x{0E7F}]/u', $pw)) {
        return 'รหัสผ่านต้องไม่มีอักษรภาษาไทย (แป้นพิมพ์อาจสลับภาษาโดยไม่รู้ตัว)';
    }
    return '';
}

// ค่าเริ่มต้น / ค่าที่เคยกรอกไว้ใน session
$saved = isset($_SESSION['connext_install']['app']) && is_array($_SESSION['connext_install']['app'])
    ? $_SESSION['connext_install']['app'] : array();

$vals = array(
    'app_name'        => isset($saved['app_name']) ? $saved['app_name'] : 'CONNEXT',
    'project_code'    => isset($saved['project_code']) ? $saved['project_code'] : 'ARI',
    'project_name'    => isset($saved['project_name']) ? $saved['project_name'] : 'เวีย อารีย์',
    'admin_full_name' => isset($saved['admin_full_name']) ? $saved['admin_full_name'] : '',
    'admin_username'  => isset($saved['admin_username']) ? $saved['admin_username'] : '',
);

$errors = array();

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $vals['app_name']        = trim(isset($_POST['app_name']) ? (string)$_POST['app_name'] : '');
    $vals['project_code']    = strtoupper(trim(isset($_POST['project_code']) ? (string)$_POST['project_code'] : ''));
    $vals['project_name']    = trim(isset($_POST['project_name']) ? (string)$_POST['project_name'] : '');
    $vals['admin_full_name'] = trim(isset($_POST['admin_full_name']) ? (string)$_POST['admin_full_name'] : '');
    $vals['admin_username']  = trim(isset($_POST['admin_username']) ? (string)$_POST['admin_username'] : '');
    $pw1 = isset($_POST['admin_password']) ? (string)$_POST['admin_password'] : '';
    $pw2 = isset($_POST['admin_password2']) ? (string)$_POST['admin_password2'] : '';

    // -- ตรวจสอบ --
    if ($vals['app_name'] === '') {
        $errors[] = 'กรุณากรอกชื่อระบบ';
    } elseif (mb_strlen($vals['app_name'], 'UTF-8') > 100) {
        $errors[] = 'ชื่อระบบต้องไม่เกิน 100 ตัวอักษร';
    }

    if ($vals['project_code'] === '') {
        $errors[] = 'กรุณากรอกรหัสโครงการ';
    } elseif (!preg_match('/^[A-Z0-9_-]{1,20}$/', $vals['project_code'])) {
        $errors[] = 'รหัสโครงการต้องเป็นอักษรอังกฤษ/ตัวเลข (A-Z, 0-9, -, _) ไม่เกิน 20 ตัวอักษร';
    }

    if ($vals['project_name'] === '') {
        $errors[] = 'กรุณากรอกชื่อโครงการ';
    } elseif (mb_strlen($vals['project_name'], 'UTF-8') > 150) {
        $errors[] = 'ชื่อโครงการต้องไม่เกิน 150 ตัวอักษร';
    }

    if ($vals['admin_full_name'] === '') {
        $errors[] = 'กรุณากรอกชื่อ-นามสกุลผู้ดูแลระบบ';
    } elseif (mb_strlen($vals['admin_full_name'], 'UTF-8') > 150) {
        $errors[] = 'ชื่อ-นามสกุลต้องไม่เกิน 150 ตัวอักษร';
    }

    if ($vals['admin_username'] === '') {
        $errors[] = 'กรุณากรอกชื่อผู้ใช้ (username) ของผู้ดูแลระบบ';
    } elseif (preg_match('/\s/u', $vals['admin_username'])) {
        $errors[] = 'ชื่อผู้ใช้ต้องไม่มีช่องว่าง';
    } elseif (mb_strlen($vals['admin_username'], 'UTF-8') > 100) {
        $errors[] = 'ชื่อผู้ใช้ต้องไม่เกิน 100 ตัวอักษร';
    }

    $pwErr = inst_password_error($pw1);
    if ($pwErr !== '') {
        $errors[] = $pwErr;
    } elseif ($pw1 !== $pw2) {
        $errors[] = 'รหัสผ่านทั้งสองช่องไม่ตรงกัน กรุณากรอกใหม่';
    }

    if (empty($errors)) {
        $_SESSION['connext_install']['app'] = array(
            'app_name'        => $vals['app_name'],
            'project_code'    => $vals['project_code'],
            'project_name'    => $vals['project_name'],
            'admin_full_name' => $vals['admin_full_name'],
            'admin_username'  => $vals['admin_username'],
            'admin_password'  => $pw1,
        );
        inst_allow(2);
        inst_redirect(2);
    }
}
?>
<h2>ขั้นตอนที่ 2 — ข้อมูลระบบ</h2>
<p class="sub">ตั้งชื่อระบบ สร้างโครงการแรก และบัญชีผู้ดูแลระบบ (Administrator)</p>

<?php if (!empty($errors)): ?>
  <div class="alert alert-error">
    <strong>&#10007; กรุณาแก้ไขข้อมูลต่อไปนี้:</strong>
    <ul>
      <?php foreach ($errors as $err): ?>
        <li><?php echo inst_e($err); ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post" action="index.php?step=1" autocomplete="off">
  <h3 class="sec">ข้อมูลระบบ</h3>
  <div class="form-group">
    <label>ชื่อระบบ (App Name)</label>
    <input type="text" name="app_name" value="<?php echo inst_e($vals['app_name']); ?>" placeholder="CONNEXT" required>
  </div>

  <h3 class="sec">โครงการแรก</h3>
  <div class="form-grid">
    <div class="form-group">
      <label>รหัสโครงการ (Project Code)</label>
      <input type="text" name="project_code" value="<?php echo inst_e($vals['project_code']); ?>"
             placeholder="ARI" maxlength="20" autocapitalize="characters" autocorrect="off" spellcheck="false" required>
      <span class="hint">อักษรอังกฤษ/ตัวเลข ไม่เกิน 20 ตัว — ใช้ฝังในเลขเอกสาร</span>
    </div>
    <div class="form-group">
      <label>ชื่อโครงการ</label>
      <input type="text" name="project_name" value="<?php echo inst_e($vals['project_name']); ?>" placeholder="เวีย อารีย์" required>
    </div>
  </div>

  <h3 class="sec">บัญชีผู้ดูแลระบบ (Administrator)</h3>
  <div class="form-group">
    <label>ชื่อ-นามสกุล</label>
    <input type="text" name="admin_full_name" value="<?php echo inst_e($vals['admin_full_name']); ?>" placeholder="ชื่อ นามสกุล" required>
  </div>
  <div class="form-group">
    <label>ชื่อผู้ใช้ (Username)</label>
    <input type="text" name="admin_username" value="<?php echo inst_e($vals['admin_username']); ?>"
           placeholder="เช่น admin" autocapitalize="off" autocorrect="off" spellcheck="false" required>
    <span class="hint">ห้ามมีช่องว่าง — ใช้สำหรับเข้าสู่ระบบ</span>
  </div>
  <div class="form-grid">
    <div class="form-group">
      <label>รหัสผ่าน</label>
      <input type="password" name="admin_password" placeholder="อย่างน้อย 4 ตัวอักษร" autocomplete="new-password" required>
    </div>
    <div class="form-group">
      <label>ยืนยันรหัสผ่าน</label>
      <input type="password" name="admin_password2" placeholder="กรอกรหัสผ่านซ้ำ" autocomplete="new-password" required>
    </div>
  </div>
  <span class="hint">นโยบายรหัสผ่าน: อย่างน้อย 4 ตัวอักษร &middot; ห้ามมีช่องว่าง &middot; ห้ามมีอักษรภาษาไทย</span>

  <div class="btn-row">
    <a class="btn btn-ghost" href="index.php?step=0">&larr; ย้อนกลับ</a>
    <button type="submit" class="btn right">ถัดไป &rarr;</button>
  </div>
</form>
