<?php
/**
 * CONNEXT — Web Installer (router + layout)
 * PHP 7.4-compatible เท่านั้น — ห้ามใช้ syntax PHP 8
 *
 * ไฟล์นี้ standalone: ห้าม require ../config.php
 * (config.php จะ redirect กลับมาที่ installer เมื่อ settings ยังไม่ครบ — วนลูป)
 *
 * ขั้นตอน: ?step=0 ตรวจสอบระบบ → 1 ข้อมูลระบบ → 2 ฐานข้อมูล → 3 ติดตั้ง → 4 เสร็จสิ้น
 */

error_reporting(E_ALL);
ini_set('display_errors', '0'); // แสดง error ผ่าน UI ของ installer เอง

date_default_timezone_set('Asia/Bangkok');
session_start();

define('CONNEXT_INSTALLER', 1);
define('INST_ROOT', dirname(__DIR__)); // โฟลเดอร์ connext/

// ---- Polyfills PHP 8 (installer ทำงานก่อนที่ helpers.php จะใช้ได้) ------
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        $len = strlen($needle);
        return $len === 0 || substr($haystack, -$len) === $needle;
    }
}

// ---- Helpers ------------------------------------------------------------
/** HTML escape */
function inst_e($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** redirect ไป step ที่กำหนด (ล้าง output buffer ก่อน) */
function inst_redirect($step) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Location: index.php?step=' . (int)$step);
    exit;
}

/** ปลดล็อกให้เข้า step ที่สูงขึ้นได้ */
function inst_allow($n) {
    $cur = isset($_SESSION['connext_install']['max_step']) ? (int)$_SESSION['connext_install']['max_step'] : 0;
    if ((int)$n > $cur) {
        $_SESSION['connext_install']['max_step'] = (int)$n;
    }
}

// ---- Session state -------------------------------------------------------
if (!isset($_SESSION['connext_install']) || !is_array($_SESSION['connext_install'])) {
    $_SESSION['connext_install'] = array('max_step' => 0);
}

$installed = is_file(INST_ROOT . '/settings/config.php') && is_file(INST_ROOT . '/settings/database.php');
$justDone  = !empty($_SESSION['connext_install']['done']);

// ติดตั้งครบแล้ว (จาก session อื่น / เข้ามาใหม่) → บล็อก ไม่ให้รัน installer ซ้ำ
$blocked = ($installed && !$justDone);

$step = isset($_GET['step']) ? (int)$_GET['step'] : 0;
if ($step < 0 || $step > 4) { $step = 0; }

// เพิ่งติดตั้งเสร็จใน session นี้ → แสดงหน้าเสร็จสิ้นเสมอ
if ($justDone && $installed) { $step = 4; }

// ห้ามข้าม step ที่ยังไม่ปลดล็อก
$maxStep = isset($_SESSION['connext_install']['max_step']) ? (int)$_SESSION['connext_install']['max_step'] : 0;
if (!$blocked && $step > $maxStep) { $step = $maxStep; }

// ---- รัน step file (จับ output ไว้ก่อน — step อาจ redirect ระหว่าง POST) --
$stepFiles = array(0 => 'step0.php', 1 => 'step1.php', 2 => 'step2.php', 3 => 'step3.php', 4 => 'complete.php');
$content = '';
if (!$blocked) {
    ob_start();
    require __DIR__ . '/' . $stepFiles[$step];
    $content = ob_get_clean();
}

$stepLabels = array('ตรวจสอบระบบ', 'ข้อมูลระบบ', 'ฐานข้อมูล', 'ติดตั้ง', 'เสร็จสิ้น');

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>ติดตั้งระบบ CONNEXT</title>
<style>
@font-face{font-family:"Prompt";font-style:normal;font-weight:300;font-display:swap;src:url(../fonts/prompt-v12-latin_thai-300.woff2) format("woff2")}@font-face{font-family:"Prompt";font-style:normal;font-weight:400;font-display:swap;src:url(../fonts/prompt-v12-latin_thai-regular.woff2) format("woff2")}@font-face{font-family:"Prompt";font-style:normal;font-weight:500;font-display:swap;src:url(../fonts/prompt-v12-latin_thai-500.woff2) format("woff2")}@font-face{font-family:"Prompt";font-style:normal;font-weight:600;font-display:swap;src:url(../fonts/prompt-v12-latin_thai-600.woff2) format("woff2")}@font-face{font-family:"Prompt";font-style:normal;font-weight:700;font-display:swap;src:url(../fonts/prompt-v12-latin_thai-700.woff2) format("woff2")}*{margin:0;padding:0;box-sizing:border-box;font-family:"Prompt",sans-serif}body{min-height:100vh;min-height:100dvh;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#122d4f 0%,#000000 50%,#0073ff 100%);padding:16px;overflow-x:hidden;overflow-y:auto;color:#fff}body::before,body::after{content:"";position:fixed;border-radius:50%;filter:blur(80px);opacity:.15;animation:float 8s ease-in-out infinite;pointer-events:none}body::before{width:400px;height:400px;background:#ffffff;top:-100px;left:-100px}body::after{width:300px;height:300px;background:#2458a6;bottom:-80px;right:-80px;animation-delay:4s}@keyframes float{0%,100%{transform:translate(0,0) scale(1)}50%{transform:translate(30px,20px) scale(1.1)}}.wrap{width:100%;max-width:640px;position:relative;z-index:1;animation:slideUp .6s ease-out;padding:8px 0 20px}@keyframes slideUp{from{opacity:0;transform:translateY(40px)}to{opacity:1;transform:translateY(0)}}.brand{text-align:center;margin-bottom:16px}.brand .logo{width:64px;height:64px;border-radius:50%;background:rgba(255,255,255,.1);backdrop-filter:blur(10px);border:2px solid rgba(255,255,255,.2);display:inline-flex;align-items:center;justify-content:center;font-size:1.4rem;font-weight:700;color:#fdd655;margin-bottom:10px;letter-spacing:1px}.brand h1{font-size:1.7rem;font-weight:700;letter-spacing:1px}.brand p{color:rgba(255,255,255,.6);font-size:.8rem;margin-top:4px;line-height:1.45}.stepper{display:flex;margin:20px 0 18px}.step{flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;position:relative;text-decoration:none;min-width:0}.step:not(:first-child)::before{content:"";position:absolute;top:17px;right:50%;width:100%;height:2px;background:rgba(255,255,255,.15);z-index:0}.step.done:not(:first-child)::before,.step.active:not(:first-child)::before{background:linear-gradient(90deg,#fdd655,#ffe387);opacity:.7}.step .dot{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:.88rem;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.18);color:rgba(255,255,255,.55);position:relative;z-index:1;transition:all .25s}.step.active .dot{background:linear-gradient(135deg,#fdd655,#ffe387);color:#000;border-color:transparent;box-shadow:0 4px 18px rgba(253,214,85,.35)}.step.done .dot{background:rgba(105,240,174,.14);border-color:rgba(105,240,174,.5);color:#69f0ae}.step .lb{font-size:.68rem;color:rgba(255,255,255,.5);text-align:center;white-space:nowrap}.step.active .lb{color:#fff;font-weight:500}.step.done .lb{color:rgba(105,240,174,.75)}@media(max-width:430px){.step .lb{display:none}.step.active .lb{display:block}}.card{background:rgba(255,255,255,.08);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,.12);border-radius:20px;padding:24px 18px;box-shadow:0 20px 60px rgba(0,0,0,.3)}@media(min-width:480px){.card{padding:30px 26px}}.card h2{font-size:1.2rem;font-weight:600;margin-bottom:6px}.card .sub{color:rgba(255,255,255,.6);font-size:.84rem;line-height:1.5;margin-bottom:18px}.card h3.sec{font-size:.92rem;font-weight:600;color:#ffe387;margin:20px 0 12px;padding-bottom:6px;border-bottom:1px solid rgba(255,255,255,.1)}.card h3.sec:first-of-type{margin-top:4px}table.checks{width:100%;border-collapse:collapse;font-size:.88rem}table.checks td{padding:11px 6px;border-bottom:1px solid rgba(255,255,255,.08);color:rgba(255,255,255,.88);vertical-align:top}table.checks tr:last-child td{border-bottom:none}table.checks td.st{width:86px;text-align:right;white-space:nowrap;font-weight:500}.hint{display:block;font-size:.75rem;color:rgba(255,255,255,.45);margin-top:2px;line-height:1.4}.st-pass{color:#69f0ae}.st-fail{color:#ff6b6b}.st-warn{color:#fdd655}.form-group{margin-bottom:15px}.form-group label{display:block;color:rgba(255,255,255,.7);font-size:.85rem;margin-bottom:7px}.form-group input{width:100%;min-height:48px;padding:12px 15px;border-radius:12px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.06);color:#fff;font-size:1rem;transition:all .3s;outline:none}.form-group input::placeholder{color:rgba(255,255,255,.3)}.form-group input:focus{border-color:rgba(253,214,85,.7);background:rgba(255,255,255,.1);box-shadow:0 0 0 3px rgba(253,214,85,.15)}.form-grid{display:grid;grid-template-columns:1fr;gap:0 14px}@media(min-width:560px){.form-grid{grid-template-columns:1fr 1fr}}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:12px 22px;border:none;border-radius:12px;background:linear-gradient(135deg,#fdd655,#ffe387);color:#000;font-size:1rem;font-weight:600;cursor:pointer;text-decoration:none;transition:all .25s;touch-action:manipulation;-webkit-tap-highlight-color:transparent}.btn:hover{transform:translateY(-2px);box-shadow:0 8px 25px rgba(253,214,85,.35)}.btn:active{transform:translateY(0)}.btn.disabled,.btn:disabled{opacity:.4;pointer-events:none}.btn-ghost{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.18);color:#fff;font-weight:400}.btn-ghost:hover{box-shadow:none;background:rgba(255,255,255,.14)}.btn-row{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:22px;flex-wrap:wrap}.btn-row .right{margin-left:auto}.btn-block{width:100%}.alert{padding:13px 15px;border-radius:12px;font-size:.88rem;line-height:1.55;margin-bottom:16px}.alert-error{background:rgba(255,107,107,.12);border:1px solid rgba(255,107,107,.35);color:#ffb3b3}.alert-success{background:rgba(105,240,174,.1);border:1px solid rgba(105,240,174,.35);color:#9af5c6}.alert-warn{background:rgba(253,214,85,.1);border:1px solid rgba(253,214,85,.4);color:#ffe9a3}.alert ul{margin:6px 0 0 20px}table.kv{width:100%;border-collapse:collapse;font-size:.88rem;margin-bottom:6px}table.kv td{padding:9px 6px;border-bottom:1px solid rgba(255,255,255,.08)}table.kv tr:last-child td{border-bottom:none}table.kv td.k{color:rgba(255,255,255,.55);width:42%}table.kv td.v{color:#fff;word-break:break-all}ul.loglist{margin:8px 0 0 20px;font-size:.84rem;color:rgba(255,255,255,.75);line-height:1.7}.danger-box{background:rgba(255,107,107,.14);border:2px solid rgba(255,107,107,.55);border-radius:14px;padding:18px 16px;text-align:center;margin:20px 0}.danger-box h3{color:#ff8a8a;font-size:1.02rem;margin-bottom:6px}.danger-box p{font-size:.85rem;color:rgba(255,255,255,.8);line-height:1.6}.danger-box code{background:rgba(0,0,0,.35);padding:2px 8px;border-radius:6px;color:#ffe387;font-size:.85rem}.success-icon{width:72px;height:72px;margin:6px auto 14px;border-radius:50%;background:rgba(105,240,174,.12);border:2px solid rgba(105,240,174,.6);display:flex;align-items:center;justify-content:center;font-size:2rem;color:#69f0ae}.center{text-align:center}.footer{text-align:center;margin-top:16px;color:rgba(255,255,255,.35);font-size:.7rem;line-height:1.5}







































































































































































</style>
</head>
<body>
<div class="wrap">
  <div class="brand">
    <div class="logo">CX</div>
    <h1>CONNEXT</h1>
    <p>ตัวช่วยติดตั้งระบบ · CONstruction Node for EXchange &amp; Tracking</p>
  </div>

<?php if ($blocked): ?>
  <div class="card center">
    <div class="success-icon">&#10003;</div>
    <h2>ติดตั้งแล้ว</h2>
    <p class="sub" style="margin-top:8px;">
      ระบบ CONNEXT ได้รับการติดตั้งเรียบร้อยแล้ว (พบไฟล์ <code style="color:#ffe387;">settings/config.php</code>)<br>
      ตัวติดตั้งจะไม่ทำงานซ้ำเพื่อป้องกันข้อมูลถูกเขียนทับ
    </p>
    <div class="danger-box">
      <h3>&#9888; คำเตือนด้านความปลอดภัย</h3>
      <p>กรุณาลบโฟลเดอร์ <code>install/</code> ออกจากเซิร์ฟเวอร์ทันที<br>
      เพื่อป้องกันไม่ให้ผู้อื่นเข้าถึงตัวติดตั้งระบบ</p>
    </div>
    <a class="btn btn-block" href="../index.php">ไปหน้าเข้าสู่ระบบ</a>
  </div>
<?php else: ?>
  <div class="stepper">
    <?php foreach ($stepLabels as $i => $lb):
        $cls = ($i < $step) ? 'done' : (($i === $step) ? 'active' : '');
        // ย้อนกลับไป step ที่ผ่านแล้วได้ (ยกเว้นเมื่อติดตั้งเสร็จแล้ว)
        $clickable = (!$justDone && $i <= $maxStep && $i < 4);
        $tag  = $clickable ? 'a' : 'div';
        $href = $clickable ? ' href="index.php?step=' . (int)$i . '"' : '';
    ?>
    <<?php echo $tag . $href; ?> class="step <?php echo $cls; ?>">
      <div class="dot"><?php echo ($i < $step) ? '&#10003;' : ($i + 1); ?></div>
      <div class="lb"><?php echo inst_e($lb); ?></div>
    </<?php echo $tag; ?>>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <?php echo $content; ?>
  </div>
<?php endif; ?>

  <div class="footer">CONNEXT Installer v1.0 &middot; PHP <?php echo inst_e(PHP_VERSION); ?> &middot; เวลาไทย (Asia/Bangkok)</div>
</div>
</body>
</html>
