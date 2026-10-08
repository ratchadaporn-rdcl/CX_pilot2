<?php
/**
 * CONNEXT — lib/ui.php : โครงหน้าร่วมของโมดูล PO → OCR → buffer → IcCode
 *
 * มติ 15: โมดูลนี้เป็น "หน้า PHP แยก" ไม่ยัดเข้า index.php ที่ generate จาก GAS
 * ไฟล์นี้จึงเป็นเปลือกหน้าเดียวที่ทุกหน้าในโมดูลใช้ร่วมกัน (topbar + CSS + nav)
 *
 * มติ 16: สิทธิ์เข้าโมดูล = ธงสายคลังเดิม roles.can_req · งานแตะ master สงวนให้ ADM (R0)
 *
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/../helpers.php';

/**
 * อยู่ในกรอบของแอปหลักไหม (index.php ฝังหน้าโมดูลด้วย <iframe ...?embed=1>)
 * โหมดนี้ตัด topbar ของโมดูลทิ้ง เพราะแอปหลักมีแถบของตัวเองอยู่แล้ว
 */
function uiIsEmbedded(): bool {
    return isset($_REQUEST['embed']) && (string)$_REQUEST['embed'] === '1';
}

/** ต่อ embed=1 ให้ URL ภายในโมดูล เมื่ออยู่ในกรอบ (ลิงก์ที่ PHP สร้างเอง) */
function uiUrl(string $path): string {
    if (!uiIsEmbedded()) { return $path; }
    return $path . (strpos($path, '?') === false ? '?' : '&') . 'embed=1';
}

/**
 * เมนูของโมดูล — เฟสถัดไปเพิ่มรายการตรงนี้ที่เดียว
 * ส่ง $user มาด้วยเพื่อกรองเมนูที่สงวนไว้ให้ ADM (หน้าตั้งค่าระบบ)
 * ในกรอบ: ตัด admin.php ออก เพราะแอปหลักมีเมนู "ตั้งค่าระบบ" ของตัวเองแยกอยู่แล้ว
 */
function uiNav(?array $user = null): array {
    // เมนูโมดูลเหลือเฉพาะงานรับของประจำวัน (มติ 37) — ออกรหัส IC / ทะเบียน IC /
    // ทดสอบ OCR ย้ายไปเป็นการ์ดเครื่องมือในหน้าตั้งค่าระบบ (admin.php ?t=ladder)
    // ตัวหน้ายังเปิดตรงได้ด้วยสิทธิ์เดิม แค่ไม่โชว์ในแถบนี้แล้ว
    $nav = [
        ['f' => 'po.php',           'label' => 'ใบคุม (PO)',   'icon' => '📋'],
        ['f' => 'rc.php',           'label' => 'รับของ/buffer', 'icon' => '📦'],
        ['f' => 'mango_bal.php',    'label' => 'Mango → IC',   'icon' => '🔗'],
    ];
    // จัดการรหัสวัสดุ (ผูก Mango → LLP → IC) — ADM เท่านั้น (มติ 42)
    // คนละหน้ากับ mango_bal.php ซึ่งเป็น "รายงาน" ยอดที่แปลงผ่าน buffer แล้ว
    if ($user !== null && uiIsAdmin($user)) {
        $nav[] = ['f' => 'setup_master.php', 'label' => 'จัดการรหัสวัสดุ', 'icon' => '🗂️'];
    }
    // หน้าตั้งค่า master (ประตู/โครงการ/วัสดุ/ผู้ใช้/บันได) — ADM เท่านั้น
    // ในกรอบไม่ต้องมี: แอปหลักมีเมนูของมันเอง ใส่ซ้ำแล้วไฮไลต์เมนูนอกจะเพี้ยน
    if ($user !== null && uiIsAdmin($user) && !uiIsEmbedded()) {
        $nav[] = ['f' => 'admin.php', 'label' => 'ตั้งค่าระบบ', 'icon' => '⚙️'];
    }
    return $nav;
}

/**
 * ประตูเข้าโมดูล — ไม่ล็อกอินเด้งไปหน้าแอป · ไม่มี can_req ตอบ 403
 * @return array user ใน session
 */
function uiGuard(): array {
    $user = currentUser();
    if (!$user) { $user = tryRememberLogin(); }
    if (!$user) {
        // ในกรอบห้าม redirect ไป index.php — SPA ทั้งแอปจะถูกโหลดซ้อนอยู่ในกรอบ
        // ให้แสดงแผงเล็ก ๆ ที่พาโหลดหน้าแม่ใหม่แทน
        if (uiIsEmbedded()) { uiEmbedNotice('session หมดอายุ หรือยังไม่ได้ล็อกอิน', true); }
        header('Location: ' . APP_BASE . '/index.php');
        exit;
    }
    if (empty($user['canReq']) && (string)($user['roleLevel'] ?? '') !== 'R0') {
        http_response_code(403);
        if (uiIsEmbedded()) { uiEmbedNotice('หน้านี้สำหรับสายคลังเท่านั้น (สิทธิ์ CanReq)', false); }
        echo '<meta charset="utf-8"><p style="font-family:sans-serif;padding:24px">'
           . 'หน้านี้สำหรับสายคลังเท่านั้น (สิทธิ์ CanReq)</p>';
        exit;
    }
    return $user;
}

/**
 * ประตูเข้าสำหรับ endpoint ที่ตอบ JSON — ไม่ redirect ไม่พ่น HTML
 * ล้มเหลว = ตอบ JSON {ok:false,error} พร้อม HTTP code แล้วจบสคริปต์
 * @return array user ใน session
 */
function uiApiGuard(): array {
    $user = currentUser();
    if (!$user) { $user = tryRememberLogin(); }
    if (!$user) { uiApiFail('ยังไม่ได้ล็อกอิน หรือ session หมดอายุ — เปิดหน้าใหม่แล้วล็อกอินอีกครั้ง', 401); }
    if (empty($user['canReq']) && (string)($user['roleLevel'] ?? '') !== 'R0') {
        uiApiFail('หน้านี้สำหรับสายคลังเท่านั้น (สิทธิ์ CanReq)', 403);
    }
    return $user;
}

/** ตอบ JSON แล้วจบ */
function uiApiOut(array $payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** ตอบ error แล้วจบ */
function uiApiFail(string $msg, int $code = 400): void {
    uiApiOut(['ok' => false, 'error' => $msg], $code);
}

/** POST เท่านั้น + CSRF — ใช้กับ action ที่เขียนข้อมูล */
function uiApiRequirePost(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { uiApiFail('ต้องเป็น POST', 405); }
    if (!hash_equals(csrfToken(), (string)($_POST['csrf'] ?? ''))) {
        uiApiFail('CSRF token ไม่ถูกต้อง — โหลดหน้าใหม่แล้วลองอีกครั้ง', 419);
    }
}

/** แผงแจ้งเตือนเล็ก ๆ สำหรับโหมดในกรอบ แล้วจบสคริปต์ */
function uiEmbedNotice(string $msg, bool $reloadTop): void {
    ?><!DOCTYPE html><html lang="th"><head>
<script src="<?= assetHref('js/inventory-theme.js') ?>"></script><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="<?= assetHref('css/prompt.css') ?>" rel="stylesheet"><style>
body{font-family:"Prompt",sans-serif;background:transparent;margin:0;padding:3px;color:#1e293b}.box{background:#fff;border-radius:16px;box-shadow:0 1px 3px rgba(0,0,0,.1);padding:22px;text-align:center}p{font-size:.92rem;margin:0 0 14px}button{font:inherit;font-weight:600;padding:.55rem 1.2rem;border:0;border-radius:8px;background:#1e3a8a;color:#fff;cursor:pointer}button:hover{background:#1d4ed8}
</style><link rel="stylesheet" href="<?= assetHref('css/inventory-brand.css') ?>"><link rel="stylesheet" href="<?= assetHref('css/inventory-theme.css') ?>">
<link rel="stylesheet" href="<?= assetHref('css/inventory-palette.css') ?>"><link rel="stylesheet" href="<?= assetHref('css/theme-dark-modules.css') ?>"><link rel="stylesheet" href="<?= assetHref('css/theme-dark-fixes.css') ?>"></head><body><div class="box">
    <p><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></p>
    <?php if ($reloadTop): ?>
      <button type="button" onclick="try{top.location.reload()}catch(e){location.reload()}">โหลดแอปใหม่</button>
    <?php endif; ?>
    </div><script>parent.postMessage({cnxFrameHeight: 140}, location.origin);</script>
    </body></html><?php
    exit;
}

/** เป็น ADM (R0) ไหม — งานแตะ master ต้องผ่านตัวนี้ */
function uiIsAdmin(array $user): bool {
    return (string)($user['roleLevel'] ?? '') === 'R0';
}

/** เงิน 2 ตำแหน่ง */
function fmtM($v): string { return number_format((float)$v, 2); }
/** จำนวน — ตัดทศนิยมท้ายที่ไม่จำเป็นออก (2,000.00 → 2,000 · 4,565.82 คงเดิม) */
function fmtQ($v): string {
    $s = number_format((float)$v, 2);
    return (substr($s, -3) === '.00') ? substr($s, 0, -3) : $s;
}

/**
 * ไอคอนหัวหน้า/เมนู → Font Awesome ชุดเดียวกับแอปหลัก
 * หน้าต่าง ๆ ยังส่งอีโมจิเดิมมาได้ (ไม่ต้องไล่แก้ทุกหน้า) · ส่งชื่อคลาสตรง ๆ ก็ได้ เช่น 'fa-gear'
 * อีโมจิที่ไม่รู้จักแสดงตามเดิม
 */
function uiIcon(string $icon): string {
    static $map = [
        "\u{2699}"  => 'fa-sliders',        // ⚙ ตั้งค่าระบบ (ไอคอนเดียวกับเมนูในแอปหลัก)
        "\u{1F4CB}" => 'fa-file-invoice',   // 📋 ใบคุม (PO)
        "\u{1F4E6}" => 'fa-truck-ramp-box', // 📦 รับของ / buffer
        "\u{1F517}" => 'fa-link',           // 🔗 Mango → IC
        "\u{1F5C2}" => 'fa-folder-tree',    // 🗂 จัดการรหัสวัสดุ
        "\u{1F4D2}" => 'fa-book',           // 📒 ทะเบียน IC
        "\u{1F3F7}" => 'fa-barcode',        // 🏷 สร้างรหัส IC
        "\u{1F9E9}" => 'fa-cubes',          // 🧩 ตั้งค่าตัวสินค้า (LLP)
        "\u{1F4DA}" => 'fa-book-open',      // 📚 ทะเบียนวัสดุ Mango
        "\u{1F52C}" => 'fa-microscope',     // 🔬 ทดสอบ / ตั้งค่า OCR
    ];
    $k = str_replace("\u{FE0F}", '', trim($icon));
    if (isset($map[$k])) {
        $cls = $map[$k];
    } elseif (preg_match('/^fa-[a-z0-9-]+$/', $k)) {
        $cls = $k;
    } else {
        return e($icon);
    }
    return '<i class="fa-solid ' . $cls . '" aria-hidden="true"></i>';
}

/**
 * เปิดหน้า: doctype → head → topbar → <div class="wrap">
 *
 * หน้าตาเดียวกับแอปหลัก (index.php): ฟอนต์ Prompt · ตัวแปรสี/มุม/เงาชุดเดียวกัน · ไอคอน Font Awesome
 * ชื่อตัวแปรเดิมของโมดูล (--navy, --line, --muted, …) ยังอยู่เป็น alias — CSS เฉพาะหน้าที่อ้างชื่อเดิม
 * เปลี่ยนตามเองโดยไม่ต้องแก้ทีละหน้า
 * กฎพื้นฐานของ element (input/button/table) ห่อด้วย :where() ให้ specificity = 0
 * คลาสของแต่ละหน้าจึงชนะเสมอ ปุ่ม/ช่องที่ตั้งขนาดเองไว้ (เช่นแผงเลือก IC ใน rc.php) ไม่ถูกทับ
 */
function uiHead(string $title, string $subtitle, array $user, string $icon = '📦', bool $hideNav = false): void {
    $self  = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $embed = uiIsEmbedded();

    // เมนูโมดูล — หน้าเดี่ยว: ปุ่มเม็ดยาใต้หัวหน้า (แบบเมนูแอปหลัก) · ในกรอบ: แถบแท็บเหนือเนื้อหา
    $nav = '';
    if (!$hideNav) {
        foreach (uiNav($user) as $n) {
            $on   = $n['f'] === $self;
            $nav .= '<a href="' . e(uiUrl(APP_BASE . '/' . $n['f'])) . '"'
                  . ($on ? ' class="on" aria-current="page"' : '') . '>'
                  . uiIcon((string)$n['icon']) . ' ' . e($n['label']) . '</a>';
        }
        if (!$embed) {
            $nav .= '<a href="' . APP_BASE . '/index.php" class="home">'
                  . '<i class="fa-solid fa-arrow-left" aria-hidden="true"></i> กลับแอปหลัก</a>';
        }
        $nav = '<nav class="nav" aria-label="เมนูโมดูล">' . $nav . '</nav>';
    }
    ?><!DOCTYPE html>
<html lang="th">
<head>
<script src="<?= assetHref('js/inventory-theme.js') ?>"></script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#1e3a8a">
<title>CONNEXT | <?= e($title) ?></title>
<?php if ($embed): ?><base target="_self"><?php endif; ?>
<link href="<?= assetHref('css/prompt.css') ?>" rel="stylesheet">
<link href="<?= assetHref('vendor-assets/fontawesome/css/all.min.css') ?>" rel="stylesheet">
<link href="<?= assetHref('css/sarabun.css') ?>" rel="stylesheet">
<style>
:root{
  --primary:#1e3a8a;--primary-light:#3b82f6;--secondary:#0f172a;--accent:#f59e0b;
  --success:#10b981;--danger:#ef4444;--surface:#fff;--background:#f1f5f9;
  --text-main:#1e293b;--text-muted:#64748b;--border:#e2e8f0;
  --radius-lg:16px;--radius-md:10px;
  --shadow-sm:0 1px 3px rgba(0,0,0,.1);--shadow-md:0 4px 6px -1px rgba(0,0,0,.1);
  --navy:var(--primary);--navy-2:#1d4ed8;--gold:#fbbf24;--gold-soft:#fef3c7;
  --bg:var(--background);--card:var(--surface);--line:var(--border);--text:var(--text-main);--muted:var(--text-muted);
  --green:#16a34a;--green-bg:#dcfce7;--red:#dc2626;--red-bg:#fee2e2;
  --amber:#d97706;--amber-bg:#fef3c7;--blue:#2563eb;--blue-bg:#dbeafe
}
*{margin:0;padding:0;box-sizing:border-box}
html{font-size:16px}
html,body{background:var(--background);font-family:"Prompt",sans-serif;color:var(--text-main)}
body{min-height:100vh;padding-bottom:60px;font-size:.95rem}
a{color:var(--navy-2)}

/* หัวหน้า (เปิดหน้าเดี่ยว) — แบบเดียวกับ navbar ของแอปหลัก */
.appbar{background:var(--surface);box-shadow:var(--shadow-sm);position:relative;z-index:5}
.topbar{padding:.75rem 1rem;display:flex;align-items:center;gap:10px}
.topbar .logo{width:40px;height:40px;border-radius:8px;flex:0 0 auto;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,var(--primary),var(--primary-light));color:#fff;font-size:1.1rem;box-shadow:var(--shadow-sm)}
.topbar .ttl{min-width:0}
.topbar h1{font-size:1.05rem;font-weight:700;color:var(--secondary);letter-spacing:.3px;line-height:1.3}
.topbar p{font-size:.72rem;color:var(--text-muted);line-height:1.4}
.topbar .who{margin-left:auto;text-align:right;flex:0 0 auto}
.topbar .who b{display:block;font-size:.9rem;font-weight:600;white-space:nowrap}
.topbar .who span{display:inline-block;margin-top:2px;padding:2px 8px;border-radius:12px;background:var(--border);color:var(--text-muted);font-size:.75rem;white-space:nowrap}
.nav{display:flex;gap:.5rem;padding:0 1rem .75rem;overflow-x:auto;scrollbar-width:none;-ms-overflow-style:none}
.nav::-webkit-scrollbar{display:none}
.nav a{display:inline-flex;align-items:center;gap:6px;flex:0 0 auto;white-space:nowrap;padding:.55rem .95rem;border-radius:999px;border:1px solid var(--border);background:#f8fafc;color:var(--text-muted);font-size:.88rem;font-weight:500;text-decoration:none;transition:color .2s,background-color .2s,border-color .2s}
.nav a:hover,.nav a.on{color:var(--primary);background:rgba(59,130,246,.08);border-color:rgba(59,130,246,.18)}
.nav a.on{font-weight:600}
.nav a.home{margin-left:auto}
.wrap{max-width:1400px;margin:0 auto;padding:1rem .85rem 2.5rem}

/* การ์ด */
.card{background:var(--surface);border-radius:var(--radius-lg);box-shadow:var(--shadow-sm);padding:1.1rem 1.25rem;margin-bottom:1rem}
.card h2{font-size:1.05rem;font-weight:600;color:var(--secondary);line-height:1.4;margin-bottom:.85rem;display:flex;align-items:center;flex-wrap:wrap;gap:.35rem .6rem}
.card h2 .num{min-width:28px;height:28px;padding:0 7px;border-radius:8px;flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;background:rgba(59,130,246,.1);color:var(--primary);font-size:.8rem;font-weight:700}
.card h2 .sp{margin-left:auto;font-weight:400;font-size:.8rem;color:var(--text-muted)}
.row{display:flex;flex-wrap:wrap;gap:.6rem;align-items:center}

/* ฟอร์ม */
label.fld{display:block;font-size:.8rem;font-weight:600;color:var(--text-muted);margin-bottom:.3rem}
:where(input[type=file],input[type=text],input[type=number],input[type=password],input[type=search],input[type=email],input[type=tel],input[type=url],input[type=date],input[type=month],select,textarea){font-family:inherit;font-size:.92rem;line-height:1.5;color:var(--text-main);padding:.55rem .8rem;border:1px solid var(--border);border-radius:8px;background:var(--surface);max-width:100%;outline:none;transition:border-color .2s,box-shadow .2s}
:where(input:focus,select:focus,textarea:focus){border-color:var(--primary-light);box-shadow:0 0 0 3px rgba(59,130,246,.12)}
:where(input[readonly]){background:#f8fafc;color:var(--text-muted)}
:where(input[type=file]){padding:.4rem .5rem;cursor:pointer}
:where(input[type=file])::file-selector-button{font-family:inherit;font-size:.85rem;font-weight:600;margin-right:.6rem;padding:.3rem .8rem;border:1px solid var(--border);border-radius:6px;background:#f1f5f9;color:var(--text-main);cursor:pointer}
:where(input[type=checkbox],input[type=radio]){accent-color:var(--primary)}
:where(button){font-family:inherit;font-size:.92rem;font-weight:600;line-height:1.5;padding:.55rem 1.1rem;border:1px solid transparent;border-radius:8px;background:var(--primary);color:#fff;cursor:pointer;transition:background-color .2s,border-color .2s,color .2s}
:where(button:not(:disabled):hover){background-color:#1d4ed8}
:where(button:focus-visible){outline:2px solid var(--primary-light);outline-offset:2px}
:where(button:disabled){opacity:.45;cursor:not-allowed}
button.ghost{background:#f1f5f9;color:var(--text-main);border-color:var(--border)}
button.ghost:not(:disabled):hover{background:#e2e8f0}
button.mini{padding:.25rem .7rem;font-size:.8rem;white-space:nowrap}
/* ปุ่มที่เป็นลิงก์ — เท่าปุ่มหลักของแอป (.btn-primary / .btn-secondary) */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:.5rem;font-family:inherit;font-size:.92rem;font-weight:600;line-height:1.5;padding:.55rem 1.1rem;border:1px solid transparent;border-radius:8px;background:var(--primary);color:#fff;text-decoration:none;text-align:center;cursor:pointer;transition:background-color .2s,border-color .2s}
.btn:hover{background:#1d4ed8;color:#fff}
.btn-secondary{background:#f1f5f9;color:var(--text-main);border-color:var(--border)}
.btn-secondary:hover{background:#e2e8f0;color:var(--text-main)}
@media(max-width:768px){:where(input[type=text],input[type=number],input[type=password],input[type=search],input[type=email],input[type=tel],input[type=url],select,textarea){font-size:16px}}

/* ป้ายสถานะ / แถบแจ้ง */
.pill{display:inline-block;font-size:.75rem;font-weight:600;line-height:1.5;padding:.1rem .6rem;border-radius:999px;white-space:nowrap}
.p-ok{background:var(--green-bg);color:#15803d}
.p-bad{background:var(--red-bg);color:#b91c1c}
.p-warn{background:var(--amber-bg);color:#b45309}
.p-info{background:var(--blue-bg);color:#1d4ed8}
.p-muted{background:#f1f5f9;color:var(--text-muted)}
.banner{border-radius:var(--radius-md);border:1px solid transparent;padding:.75rem 1rem;margin-bottom:1rem;font-size:.9rem;font-weight:600;line-height:1.6}
.b-ok{background:var(--green-bg);color:#14532d;border-color:#86efac}
.b-bad{background:var(--red-bg);color:#7f1d1d;border-color:#fca5a5}
.b-warn{background:var(--amber-bg);color:#92400e;border-color:#fcd34d}
.b-info{background:#eef2f7;color:#33415c;border-color:#c7d4e8}
/* แถบแจ้งมีไอคอน (แบบ .rate-alert ของแอปหลัก) — เนื้อความต้องห่อด้วย <div> */
.alert{display:flex;align-items:flex-start;gap:.65rem;padding:.8rem 1rem;margin-bottom:1rem;border-radius:var(--radius-md);border:1px solid;font-size:.88rem;line-height:1.65}
.alert>i{flex:none;margin-top:.22rem;font-size:1.05rem}
.alert>div{flex:1;min-width:0}
.alert ul{margin:.3rem 0 0;padding-left:1.15rem}
.alert li+li{margin-top:.15rem}
.alert.warn{background:#fef3c7;border-color:#fcd34d;color:#92400e}
.alert.warn>i{color:#d97706}
.alert.info{background:#eef2f7;border-color:#c7d4e8;color:#33415c}
.alert.info>i{color:var(--primary)}
.alert.ok{background:#dcfce7;border-color:#86efac;color:#14532d}
.alert.ok>i{color:#16a34a}
.alert.bad{background:#fee2e2;border-color:#fca5a5;color:#7f1d1d}
.alert.bad>i{color:#dc2626}
/* [2026-10-05] data-fold = กล่องคำอธิบายถาวร กดย่อเหลือบรรทัดแรก (จำต่อเครื่อง · ข้อความเปลี่ยน = กางใหม่) ·
   data-autohide = แถบผลการกด หายเอง (ok 5 วิ · warn 10 วิ · bad ค้างจนกดปิด) — ปุ่มใส่โดยสคริปต์ใน uiFoot() */
.alert-x{flex:none;margin:-.15rem -.4rem 0 .1rem;padding:.2rem .45rem;border:0;border-radius:6px;background:none;color:inherit;opacity:.6;cursor:pointer;font:inherit;font-size:.95rem;line-height:1.2}
.alert-x:hover,.alert-x:focus-visible{opacity:1;background:rgba(0,0,0,.07)}
.alert.is-folded{cursor:pointer;padding-top:.5rem;padding-bottom:.5rem}
.alert.is-folded>div{max-height:1.65em;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
.alert.is-gone{opacity:0;transition:opacity .35s}
@media (prefers-reduced-motion:reduce){.alert.is-gone{transition:none}}
.icon-blue{background:rgba(59,130,246,.1);color:var(--primary-light)}
.icon-green{background:rgba(16,185,129,.1);color:var(--success)}
.icon-yellow{background:rgba(245,158,11,.1);color:var(--accent)}
.icon-red{background:rgba(239,68,68,.1);color:var(--danger)}
.icon-purple{background:rgba(139,92,246,.1);color:#8b5cf6}

/* ตาราง — ค่าเริ่มต้นแบบ .data-table (Dashboard/ประวัติ) · .tbl = หัวสีน้ำเงินแบบหน้า "ตั้งค่า" */
:where(table){width:100%;border-collapse:collapse;font-size:.86rem}
:where(th,td){padding:.6rem .75rem;border-bottom:1px solid var(--border);text-align:left;vertical-align:top}
:where(th){background:#f8fafc;border-bottom-width:2px;color:#334155;font-size:.8rem;font-weight:600;white-space:nowrap}
td.num,th.num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
tr.adjust td{background:#fffbeb}
tr.dim td{opacity:.55}
.scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}
.tbl-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;border:1px solid var(--border);border-radius:var(--radius-lg);background:var(--surface)}
.tbl{font-size:.88rem;min-width:600px}
.tbl thead tr{background:var(--primary)}
.tbl thead th{background:none;color:#fff;font-size:.84rem;padding:.65rem .8rem;border:0}
.tbl tbody td{padding:.6rem .8rem;border-bottom:0;border-top:1px solid var(--border);vertical-align:middle}
.tbl tbody tr:first-child>td{border-top:0}
.tbl tbody tr:not(.edit-row):hover>td{background:#f8fafc}
.tbl td.empty{padding:1.6rem .8rem;text-align:center;color:var(--text-muted)}

/* แท็บ — เหมือน .tabs-container / .tab-btn ของแอปหลักทุกค่า */
.tabs-container{background:var(--surface);border-radius:var(--radius-lg);box-shadow:var(--shadow-md);overflow:hidden;margin-bottom:1rem}
.tabs-header{display:flex;background:#f8fafc;border-bottom:1px solid var(--border);overflow-x:auto;-webkit-overflow-scrolling:touch;scrollbar-width:none}
.tabs-header::-webkit-scrollbar{display:none}
.tab-btn{position:relative;display:flex;align-items:center;gap:8px;flex-shrink:0;white-space:nowrap;padding:.9rem 1rem;border:0;background:none;color:var(--text-muted);font-family:inherit;font-size:.84rem;font-weight:600;text-decoration:none;cursor:pointer;transition:color .2s,background-color .2s}
.tab-btn:hover{color:var(--primary)}
.tab-btn.active{color:var(--primary);background:var(--surface)}
.tab-btn.active::after{content:"";position:absolute;left:0;bottom:0;width:100%;height:3px;background:var(--primary);border-radius:3px 3px 0 0}
.tab-content{padding:1rem}
.form-section{background:#f8fafc;padding:1rem;border-radius:var(--radius-md);border:1px solid var(--border)}
@media(min-width:768px){.tab-content{padding:1.5rem}}

/* อื่น ๆ */
.kv{display:grid;grid-template-columns:170px 1fr;gap:.3rem .75rem;font-size:.88rem}
.kv dt{color:var(--text-muted)}
.kv dd{word-break:break-word}
details{margin-top:.75rem}
summary{cursor:pointer;font-size:.86rem;font-weight:600;color:var(--primary)}
pre{background:var(--secondary);color:#e2e8f0;padding:.75rem;border-radius:var(--radius-md);overflow:auto;font-size:.75rem;line-height:1.5;max-height:460px;margin-top:.5rem}
.small{font-size:.8rem;color:var(--text-muted)}
.mono{font-family:ui-monospace,"Cascadia Mono",Consolas,monospace}
.desc{color:var(--text-muted);font-size:.78rem;white-space:pre-wrap}
.ladder{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:.75rem}
.step{border:1px solid var(--border);border-radius:var(--radius-md);padding:.75rem .85rem;background:#f8fafc}
.step .t{font-size:.78rem;font-weight:600;color:var(--text-muted);margin-bottom:.45rem;display:flex;align-items:center;gap:6px}
.step .t b{width:20px;height:20px;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;background:var(--primary);color:#fff;font-size:.7rem}
.step select,.step input{width:100%}
.code{display:inline-block;font-family:ui-monospace,"Cascadia Mono",Consolas,monospace;font-size:1.05rem;font-weight:700;letter-spacing:1px;padding:.6rem .9rem;border-radius:10px;background:var(--primary);color:var(--gold)}
.code .g{color:#fff;opacity:.85}
.code .sep{opacity:.35;font-weight:400}
.backbar{margin-bottom:.85rem}
.backbar a{display:inline-flex;align-items:center;gap:.45rem;padding:.45rem 1rem;border-radius:999px;border:1px solid var(--border);background:var(--surface);color:var(--primary);font-size:.86rem;font-weight:600;text-decoration:none;box-shadow:var(--shadow-sm)}
.backbar a:hover{background:#eff6ff;border-color:rgba(59,130,246,.3)}
@media(max-width:640px){.card{padding:1rem}.kv{grid-template-columns:1fr}.topbar .who{display:none}}
<?php if ($embed): ?>
/* ในกรอบของแอปหลัก: พื้นโปร่งให้เห็นพื้นหน้าแม่ · เว้นขอบนิดเดียวไม่ให้เงาการ์ดโดนตัด */
html,body{background:transparent}
body{padding-bottom:0;min-height:0}
.wrap{max-width:none;padding:2px 3px 16px}
.card:last-of-type{margin-bottom:0}
.nav{padding:0 .35rem;margin-bottom:1rem;gap:0;border-radius:var(--radius-lg);background:var(--surface);box-shadow:var(--shadow-sm)}
.nav a{position:relative;padding:.9rem 1rem;border:0;border-radius:0;background:none;font-size:.84rem;font-weight:600}
.nav a:hover,.nav a.on{background:none;border-color:transparent}
.nav a.on::after{content:"";position:absolute;left:0;bottom:0;width:100%;height:3px;background:var(--primary);border-radius:3px 3px 0 0}
<?php endif; ?>
</style>
<link rel="stylesheet" href="<?= assetHref('css/inventory-brand.css') ?>"><link rel="stylesheet" href="<?= assetHref('css/inventory-theme.css') ?>">
<link rel="stylesheet" href="<?= assetHref('css/inventory-palette.css') ?>"><link rel="stylesheet" href="<?= assetHref('css/theme-dark-modules.css') ?>"><link rel="stylesheet" href="<?= assetHref('css/theme-dark-fixes.css') ?>"></head>
<body>

<?php if (!$embed): ?>
<header class="appbar">
  <div class="topbar">
    <div class="logo"><?= uiIcon($icon) ?></div>
    <div class="ttl">
      <h1><?= e($title) ?></h1>
      <p><?= e($subtitle) ?></p>
    </div>
    <div class="who">
      <b><?= e((string)($user['fullName'] ?? '')) ?></b>
      <span><?= e((string)($user['roleId'] ?? '')) ?> · <?= e((string)($user['siteCode'] ?? '')) ?></span>
    </div>
  </div>
  <?= $nav ?>
</header>
<?php endif; ?>

<div class="wrap">
<?php if ($embed) { echo $nav; } ?>
<?php
}

/**
 * แถบ "← กลับหน้าตั้งค่าระบบ" — โชว์เฉพาะตอนถูกเปิดในกรอบ (มติ 37)
 * หน้าเครื่องมือ (llp_master/mango_master/ic_new/ic_list/po_ocr_test) เข้าได้จาก
 * หน้าตั้งค่าระบบเป็นหลัก จึงต้องมีทางเดินกลับ ไม่งั้นผู้ใช้ติดอยู่ในกรอบ
 */
function uiBackToAdmin(string $tab = ''): void {
    if (!uiIsEmbedded()) { return; }
    $url = APP_BASE . '/admin.php?embed=1' . ($tab !== '' ? '&t=' . rawurlencode($tab) : '');
    echo '<div class="backbar"><a href="' . e($url) . '">'
       . '<i class="fa-solid fa-arrow-left" aria-hidden="true"></i> กลับหน้าตั้งค่าระบบ</a></div>';
}

/** ปิดหน้า */
function uiFoot(string $note = ''): void {
    $embed = uiIsEmbedded();
    ?>
  <?php if ($note !== ''): ?>
    <p class="small" style="text-align:center;margin-top:18px"><?= $note ?></p>
  <?php endif; ?>
</div>
<script>
/* [2026-10-05] .alert[data-fold="<id>"] ย่อ/กาง (จำใน localStorage ต่อเครื่อง — ข้อความเปลี่ยน = กางใหม่) ·
   .alert[data-autohide] ปุ่มปิด + หายเอง (ok 5 วิ · warn 10 วิ · bad ค้างจนกดปิด · เอาเมาส์วางไว้ = หยุดนับ) */
(function () {
  'use strict';
  function sig(s) { var h = 0; for (var i = 0; i < s.length; i++) { h = (h * 31 + s.charCodeAt(i)) | 0; } return String(h); }
  function load(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function save(k, v) { try { if (v === null) { localStorage.removeItem(k); } else { localStorage.setItem(k, v); } } catch (e) {} }
  function bodyOf(box) {
    for (var i = 0; i < box.children.length; i++) { if (box.children[i].tagName === 'DIV') { return box.children[i]; } }
    return null;
  }
  function button(box) {
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'alert-x';
    box.appendChild(b);
    return b;
  }
  function label(b, icon, text) {
    b.innerHTML = '<i class="fa-solid ' + icon + '" aria-hidden="true"></i>';
    b.title = text; b.setAttribute('aria-label', text);
  }

  document.querySelectorAll('.alert[data-fold]').forEach(function (box) {
    var body = bodyOf(box);
    if (!body) { return; }
    var key = 'cnx.fold.' + box.getAttribute('data-fold');
    var mark = sig(body.textContent.replace(/\s+/g, ' ').trim());
    var b = button(box);
    function set(folded, remember) {
      box.classList.toggle('is-folded', folded);
      label(b, folded ? 'fa-chevron-down' : 'fa-chevron-up', folded ? 'แสดงทั้งหมด' : 'ย่อ');
      b.setAttribute('aria-expanded', folded ? 'false' : 'true');
      if (remember) { save(key, folded ? mark : null); }
    }
    set(load(key) === mark, false);
    b.addEventListener('click', function (ev) { ev.stopPropagation(); set(!box.classList.contains('is-folded'), true); });
    box.addEventListener('click', function (ev) {
      if (box.classList.contains('is-folded')) { ev.preventDefault(); set(false, true); }
    });
  });

  document.querySelectorAll('.alert[data-autohide]').forEach(function (box) {
    var b = button(box);
    label(b, 'fa-xmark', 'ปิด');
    function gone() { box.classList.add('is-gone'); setTimeout(function () { box.style.display = 'none'; }, 360); }
    b.addEventListener('click', gone);
    var ms = box.classList.contains('ok') ? 5000 : (box.classList.contains('warn') ? 10000 : 0);
    if (!ms) { return; }
    var t = null, held = 0;
    function arm() { clearTimeout(t); if (!held) { t = setTimeout(gone, ms); } }
    function hold(on) { held = Math.max(0, held + (on ? 1 : -1)); if (held) { clearTimeout(t); } else { arm(); } }
    box.addEventListener('mouseenter', function () { hold(true); });
    box.addEventListener('mouseleave', function () { hold(false); });
    box.addEventListener('focusin', function () { hold(true); });
    box.addEventListener('focusout', function () { hold(false); });
    if (document.hidden) {
      document.addEventListener('visibilitychange', function wake() {
        if (!document.hidden) { document.removeEventListener('visibilitychange', wake); arm(); }
      });
    } else {
      arm();
    }
  });
})();
</script>
<?php if ($embed): ?>
<script>

(function () {
  'use strict';
  var HERE = location.origin;

   
   
  function stamp(root) {
    (root || document).querySelectorAll('a[href]').forEach(function (a) {
      if (a.target === '_blank' || a.dataset.cnxNoEmbed) { return; }
      var u;
      try { u = new URL(a.getAttribute('href'), location.href); } catch (e) { return; }
      if (u.origin !== HERE || !/\.php$/i.test(u.pathname)) { return; }
      if (u.pathname.indexOf('/index.php') !== -1) { return; }    
      if (u.searchParams.get('embed') === '1') { return; }
      u.searchParams.set('embed', '1');
      a.setAttribute('href', u.pathname + u.search + u.hash);
    });
    (root || document).querySelectorAll('form').forEach(function (f) {
      if (f.querySelector('input[name="embed"]')) { return; }
      var i = document.createElement('input');
      i.type = 'hidden'; i.name = 'embed'; i.value = '1';
      f.appendChild(i);
    });
  }
  stamp(document);
   
  try {
    new MutationObserver(function () { stamp(document); })
      .observe(document.body, {childList: true, subtree: true});
  } catch (e) {}

   
   
   
   
   
   
   
  function contentHeight() {
    var b  = document.body;
    var cs = window.getComputedStyle(b);
    var max = 0;
    for (var i = 0; i < b.children.length; i++) {
      var el = b.children[i];
      if (!el.getBoundingClientRect) { continue; }
      var s = window.getComputedStyle(el);
       
      if (s.position === 'fixed' || s.display === 'none') { continue; }
      var r = el.getBoundingClientRect();
      if (r.width === 0 && r.height === 0) { continue; }
      var bottom = r.bottom + (window.pageYOffset || 0) + (parseFloat(s.marginBottom) || 0);
      if (bottom > max) { max = bottom; }
    }
    return Math.ceil(max + (parseFloat(cs.paddingBottom) || 0));
  }

  var last = 0, ticks = 0;
  function report(force) {
    var h = contentHeight();
    if (h <= 0) { return; }
     
     
    if (force || Math.abs(h - last) >= 4) {
      last = h;
      try { parent.postMessage({cnxFrameHeight: h, cnxFramePath: location.pathname + location.search}, HERE); } catch (e) {}
    }
  }
  report(true);
  window.addEventListener('load', function () { report(true); });
   
   
  try { new ResizeObserver(function () { report(false); }).observe(document.body); } catch (e) {}
   
   
   
  setInterval(function () { ticks++; report(ticks % 4 === 0); }, 500);
})();
</script>
<?php endif; ?>
</body>
</html>
<?php
}
