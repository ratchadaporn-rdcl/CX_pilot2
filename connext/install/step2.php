<?php
/**
 * CONNEXT Installer — Step 2: ตั้งค่าฐานข้อมูล + ทดสอบการเชื่อมต่อ
 * PHP 7.4-compatible
 *
 * Flow: กรอกข้อมูล → "ทดสอบการเชื่อมต่อ" (form roundtrip)
 *  - เชื่อมต่อ + พบฐานข้อมูล → บันทึกลง session, ปลดล็อกขั้นถัดไป
 *  - เชื่อมต่อเซิร์ฟเวอร์ได้แต่ไม่พบฐานข้อมูล → เสนอปุ่ม "สร้างฐานข้อมูล"
 *    (CREATE DATABASE IF NOT EXISTS ... utf8mb4)
 */
if (!defined('CONNEXT_INSTALLER')) { http_response_code(403); exit('Forbidden'); }

/** สร้าง PDO เชื่อมต่อ MySQL — $withDb=false คือต่อระดับเซิร์ฟเวอร์เท่านั้น */
function inst_pdo_connect(array $db, $withDb) {
    $dsn = 'mysql:host=' . $db['host'] . ';charset=utf8mb4';
    if ($withDb) {
        $dsn .= ';dbname=' . $db['dbname'];
    }
    return new PDO($dsn, $db['user'], $db['pass'], array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ));
}

/** ตรวจว่า PDOException คือ "unknown database" (MySQL 1049) หรือไม่ */
function inst_is_unknown_db($e) {
    if (!($e instanceof PDOException)) { return false; }
    $info = $e->errorInfo;
    if (is_array($info) && isset($info[1]) && (int)$info[1] === 1049) { return true; }
    return (strpos($e->getMessage(), '1049') !== false)
        || (stripos($e->getMessage(), 'unknown database') !== false);
}

// ค่าเริ่มต้น / ค่าที่เคยกรอก
$saved = isset($_SESSION['connext_install']['db']) && is_array($_SESSION['connext_install']['db'])
    ? $_SESSION['connext_install']['db'] : array();

$db = array(
    'host'   => isset($saved['host']) ? $saved['host'] : 'localhost',
    'dbname' => isset($saved['dbname']) ? $saved['dbname'] : 'connext',
    'user'   => isset($saved['user']) ? $saved['user'] : 'root',
    'pass'   => isset($saved['pass']) ? $saved['pass'] : '',
);

$dbOk        = !empty($_SESSION['connext_install']['db_ok']);
$errors      = array();
$successMsg  = '';
$offerCreate = false;

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string)$_POST['action'] : 'test';

    $db['host']   = trim(isset($_POST['db_host']) ? (string)$_POST['db_host'] : '');
    $db['dbname'] = trim(isset($_POST['db_name']) ? (string)$_POST['db_name'] : '');
    $db['user']   = trim(isset($_POST['db_user']) ? (string)$_POST['db_user'] : '');
    $db['pass']   = isset($_POST['db_pass']) ? (string)$_POST['db_pass'] : '';

    // แก้ไขค่าใหม่ = ต้องทดสอบใหม่เสมอ
    $dbOk = false;
    $_SESSION['connext_install']['db_ok'] = false;
    $_SESSION['connext_install']['db'] = $db;

    if ($db['host'] === '') {
        $errors[] = 'กรุณากรอก Host ของฐานข้อมูล';
    }
    if ($db['dbname'] === '' || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $db['dbname'])) {
        $errors[] = 'ชื่อฐานข้อมูลต้องเป็นอักษรอังกฤษ/ตัวเลข/ขีดล่าง (a-z, 0-9, _) ไม่เกิน 64 ตัวอักษร';
    }
    if ($db['user'] === '') {
        $errors[] = 'กรุณากรอกชื่อผู้ใช้ฐานข้อมูล';
    }

    if (empty($errors)) {
        // ขอสร้างฐานข้อมูลก่อน (ถ้ากดปุ่มสร้าง)
        if ($action === 'create') {
            try {
                $pdoSrv = inst_pdo_connect($db, false);
                $pdoSrv->exec('CREATE DATABASE IF NOT EXISTS `' . $db['dbname'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $pdoSrv = null;
            } catch (PDOException $e) {
                $errors[] = 'สร้างฐานข้อมูลไม่สำเร็จ: ' . $e->getMessage();
            }
        }

        // ทดสอบเชื่อมต่อแบบระบุฐานข้อมูล
        if (empty($errors)) {
            try {
                $pdoTest = inst_pdo_connect($db, true);
                $verRow  = $pdoTest->query('SELECT VERSION()')->fetchColumn();
                $pdoTest = null;

                $_SESSION['connext_install']['db_ok'] = true;
                $dbOk = true;
                inst_allow(3);
                $successMsg = 'เชื่อมต่อฐานข้อมูล "' . $db['dbname'] . '" สำเร็จ (เซิร์ฟเวอร์: ' . $verRow . ')';
                if ($action === 'create') {
                    $successMsg = 'สร้างฐานข้อมูล "' . $db['dbname'] . '" และเชื่อมต่อสำเร็จ (เซิร์ฟเวอร์: ' . $verRow . ')';
                }
            } catch (PDOException $e) {
                if (inst_is_unknown_db($e)) {
                    // ต่อเซิร์ฟเวอร์ได้ไหม? ถ้าได้ → เสนอสร้างฐานข้อมูล
                    try {
                        $pdoSrv = inst_pdo_connect($db, false);
                        $pdoSrv = null;
                        $offerCreate = true;
                    } catch (PDOException $e2) {
                        $errors[] = 'เชื่อมต่อเซิร์ฟเวอร์ฐานข้อมูลไม่สำเร็จ: ' . $e2->getMessage();
                    }
                } else {
                    $errors[] = 'เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ' . $e->getMessage();
                }
            }
        }
    }
} elseif ($dbOk) {
    $successMsg = 'ทดสอบการเชื่อมต่อฐานข้อมูล "' . $db['dbname'] . '" ผ่านแล้ว — กด "ถัดไป" เพื่อดำเนินการต่อ';
}
?>
<h2>ขั้นตอนที่ 3 — ตั้งค่าฐานข้อมูล</h2>
<p class="sub">กรอกข้อมูลการเชื่อมต่อ MySQL/MariaDB แล้วกด "ทดสอบการเชื่อมต่อ" ก่อนไปขั้นถัดไป</p>

<?php if (!empty($errors)): ?>
  <div class="alert alert-error">
    <strong>&#10007; พบปัญหา:</strong>
    <ul>
      <?php foreach ($errors as $err): ?>
        <li><?php echo inst_e($err); ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($successMsg !== ''): ?>
  <div class="alert alert-success">&#10003; <?php echo inst_e($successMsg); ?></div>
<?php endif; ?>

<?php if ($offerCreate): ?>
  <div class="alert alert-warn">
    &#9888; เชื่อมต่อเซิร์ฟเวอร์ฐานข้อมูลได้ แต่ยังไม่พบฐานข้อมูลชื่อ
    "<strong><?php echo inst_e($db['dbname']); ?></strong>"<br>
    กดปุ่ม "สร้างฐานข้อมูล" ด้านล่างเพื่อให้ระบบสร้างให้อัตโนมัติ (utf8mb4)
  </div>
<?php endif; ?>

<form method="post" action="index.php?step=2" autocomplete="off">
  <div class="form-grid">
    <div class="form-group">
      <label>Host</label>
      <input type="text" name="db_host" value="<?php echo inst_e($db['host']); ?>" placeholder="localhost" autocapitalize="off" autocorrect="off" spellcheck="false" required>
    </div>
    <div class="form-group">
      <label>ชื่อฐานข้อมูล (Database)</label>
      <input type="text" name="db_name" value="<?php echo inst_e($db['dbname']); ?>" placeholder="connext" autocapitalize="off" autocorrect="off" spellcheck="false" required>
    </div>
    <div class="form-group">
      <label>ชื่อผู้ใช้ (DB User)</label>
      <input type="text" name="db_user" value="<?php echo inst_e($db['user']); ?>" placeholder="root" autocapitalize="off" autocorrect="off" spellcheck="false" required>
    </div>
    <div class="form-group">
      <label>รหัสผ่าน (DB Password)</label>
      <input type="password" name="db_pass" value="<?php echo inst_e($db['pass']); ?>" placeholder="เว้นว่างได้ถ้าไม่มี" autocomplete="new-password">
    </div>
  </div>

  <div class="btn-row">
    <a class="btn btn-ghost" href="index.php?step=1">&larr; ย้อนกลับ</a>
    <?php if ($offerCreate): ?>
      <button type="submit" name="action" value="create" class="btn right">&#43; สร้างฐานข้อมูล "<?php echo inst_e($db['dbname']); ?>"</button>
    <?php else: ?>
      <button type="submit" name="action" value="test" class="btn <?php echo $dbOk ? 'btn-ghost' : ''; ?> right">&#9889; ทดสอบการเชื่อมต่อ</button>
    <?php endif; ?>
  </div>

  <?php if ($dbOk): ?>
    <div class="btn-row" style="margin-top:12px;">
      <a class="btn btn-block" href="index.php?step=3">ถัดไป &rarr;</a>
    </div>
  <?php endif; ?>
</form>
