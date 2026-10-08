<?php
/**
 * CONNEXT — admin.php : หน้าตั้งค่าสำหรับผู้ดูแลระบบ (ADM / R0 เท่านั้น)
 *
 * รวมงานตั้งค่าที่เดิมต้องเข้า phpMyAdmin ไว้ที่เดียว:
 *   ?t=gates     ประตู — เพิ่ม/แก้/ปิดใช้งาน · ประเภท มี/ไม่มี CCTV · key ต่อตู้ · ค่าความปลอดภัยตู้/QR (2026-10-02)
 *                (ธง hardware_close เดิม: ช่องติ๊กเอาออกแล้ว — ประตูใหม่ = มีตู้ + กลอนเสมอ · ประตูเก่าที่ยังไม่มีตู้มีปุ่มเปลี่ยน)
 *   ?t=projects  โครงการ/ไซต์ — เพิ่ม/แก้/เก็บเข้ากรุ
 *   ?t=materials วัสดุ — ค้น/แก้ชื่อ-หน่วย-หมวด + ผูกประตูตั้งต้นรายไซต์ (แบ่งหน้า ?pg= · ตัวกรอง ?scope=)
 *                + ลงยอดคงเหลือที่ยังไม่อยู่ประตูใดไว้ที่ประตูตั้งต้น (ยอดยกมาจากชีต — ไม่งั้นเบิกไม่ได้)
 *   ?t=ladder    บันได IC — สรุปจำนวนแต่ละชั้น + ทางเข้าไปแก้
 *   ?t=users     ผู้ใช้ + บทบาท/สิทธิ์ — เพิ่ม/แก้/ปิดใช้งาน · ตั้งรหัสผ่านใหม่ · แก้สิทธิ์รายบทบาท
 *
 * ไม่มีปุ่มลบถาวรโดยตั้งใจ — ทุกตารางถูกอ้างจาก documents/gate_logs/stock_balances
 * การลบจึงทำให้ประวัติกำพร้า ใช้ "ปิดใช้งาน / เก็บเข้ากรุ" แทนทุกกรณี
 *
 * PHP 7.4-compatible เท่านั้น
 */

require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/ic.php';   // IC_CAT_IDS / IC_CHAR_IDS + ตัวตรวจค่า (มติ 31)
require_once __DIR__ . '/lib/stock.php';   // stockUnallocatedList / stockAllocateUnassigned (ยอดยังไม่ลงประตู)
require_once __DIR__ . '/lib/gate_sec.php';   // 2026-10-02: ประเภทประตู · key ต่อตู้ · app_settings (GP-46 · GP-22)
require_once __DIR__ . '/lib/notify.php';     // 2026-10-02: แจ้งเตือนผู้ดูแลระบบทุกครั้งที่แก้ค่าตั้งประตู/หมวดวัสดุ
require_once __DIR__ . '/lib/jobs.php';       // 2026-10-02: งานตั้งเวลา (สถานะรอบล่าสุดในหน้านี้)

$user = uiGuard();
if (!uiIsAdmin($user)) {
    http_response_code(403);
    uiHead('ตั้งค่าระบบ', 'เฉพาะผู้ดูแลระบบ', $user, '⚙️', uiIsEmbedded());
    echo '<div class="alert bad"><i class="fa-solid fa-lock" aria-hidden="true"></i>'
       . '<div>หน้านี้สำหรับผู้ดูแลระบบ (ADM) เท่านั้น</div></div>';
    uiFoot();
    exit;
}

// ไอคอน = คลาส Font Awesome ชุดเดียวกับแอปหลัก
$tabs = [
    'gates'     => ['fa-door-open',     'ประตู (Gate)'],
    'projects'  => ['fa-building',      'โครงการ / ไซต์'],
    'materials' => ['fa-boxes-stacked', 'วัสดุ'],
    'ladder'    => ['fa-layer-group',   'บันได IC'],
    'users'     => ['fa-users-gear',    'ผู้ใช้ / สิทธิ์'],
];
$t = (string)($_GET['t'] ?? 'gates');
if (!isset($tabs[$t])) { $t = 'gates'; }

$isPost = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
$csrfOk = $isPost && hash_equals(csrfToken(), (string)($_POST['csrf'] ?? ''));
$action = $isPost ? (string)($_POST['action'] ?? '') : '';
$notice = null;

if ($isPost && !$csrfOk) {
    $notice = ['bad', 'CSRF token ไม่ถูกต้อง — โหลดหน้าใหม่แล้วลองอีกครั้ง'];
    $action = '';
}

/** จดบันทึกการแก้ master ลง activity_log — ล้มเหลวห้ามลาก flow หลักล้ม */
function admLog(PDO $pdo, array $user, string $entity, $id, string $act, $old, $new): void {
    try {
        $st = $pdo->prepare(
            'INSERT INTO activity_log (entity_type, entity_id, user_name, action, old_value, new_value)
             VALUES (?,?,?,?,?,?)'
        );
        $st->execute([
            $entity, (string)$id, (string)($user['username'] ?? ''), $act,
            is_string($old) ? $old : json_encode($old, JSON_UNESCAPED_UNICODE),
            is_string($new) ? $new : json_encode($new, JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Throwable $e) {
        error_log('admLog: ' . $e->getMessage());
    }
}

// ═══════════════════════════════════════════════════════════════════════
// ประตู
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'gate_save') {
    $id    = (int)($_POST['id'] ?? 0);
    $code  = strtoupper(trim((string)($_POST['gate_code'] ?? '')));
    $name  = trim((string)($_POST['name'] ?? ''));
    $proj  = (int)($_POST['project_id'] ?? 0);
    // [2026-10-02 · GP-46] ประเภทประตู มี/ไม่มี CCTV แทนช่องติ๊ก "มีตัวล็อกจริง" (hardware_close ไม่รับจากฟอร์มแล้ว —
    //   ประตูใหม่ = มีตู้ + กลอนเสมอ · ประตูเก่าที่ยังไม่มีตู้เปลี่ยนได้ด้วยปุ่ม gate_hw_on)
    $cctv  = (($_POST['gate_type'] ?? 'cctv') === 'nocctv') ? 0 : 1;
    $stat  = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    if (!preg_match('/^G\d{1,3}$/', $code)) {
        $notice = ['bad', 'รหัสประตูต้องเป็นรูปแบบ G ตามด้วยตัวเลข เช่น G01 — เพราะ api/gate.php ถอดรหัสประตูจากท้ายเลขเอกสารด้วยแพตเทิร์นนี้'];
    } elseif ($name === '') {
        $notice = ['bad', 'ต้องใส่ชื่อประตู'];
    } elseif ($proj <= 0) {
        $notice = ['bad', 'ต้องเลือกโครงการ'];
    } else {
        // รหัสประตูซ้ำในโครงการเดียวกันไม่ได้ — เลขเอกสารจะชนกัน
        $dup = $pdo->prepare('SELECT id FROM gates WHERE gate_code = ? AND project_id = ? AND id <> ?');
        $dup->execute([$code, $proj, $id]);
        if ($dup->fetchColumn()) {
            $notice = ['bad', 'โครงการนี้มีประตู ' . $code . ' อยู่แล้ว'];
        } elseif ($id > 0) {
            $old = $pdo->prepare('SELECT * FROM gates WHERE id = ?');
            $old->execute([$id]);
            $oldRow = $old->fetch();
            $st = $pdo->prepare('UPDATE gates SET gate_code=?, name=?, project_id=?, has_cctv=?, status=? WHERE id=?');
            $st->execute([$code, $name, $proj, $cctv, $stat, $id]);
            admLog($pdo, $user, 'gate', $id, 'update', $oldRow,
                   compact('code', 'name', 'proj', 'cctv', 'stat'));
            $chg = admGateChanges($pdo, $oldRow ?: [], ['gate_code' => $code, 'name' => $name, 'project_id' => $proj, 'has_cctv' => $cctv, 'status' => $stat]);
            if ($chg) {
                cnxNotifyAdmins($pdo, 'gate_setting', 'แก้ค่าตั้งประตู ' . $code . ' (' . admProjCode($pdo, $proj) . ')',
                                implode(' · ', $chg), (string)($user['username'] ?? ''), $proj);
            }
            $notice = ['ok', 'บันทึกประตู ' . $code . ' แล้ว' . ($chg ? ' — แจ้งเตือนผู้ดูแลระบบแล้ว' : '')];
        } else {
            $st = $pdo->prepare('INSERT INTO gates (gate_code, name, project_id, hardware_close, has_cctv, status) VALUES (?,?,?,1,?,?)');
            $st->execute([$code, $name, $proj, $cctv, $stat]);
            admLog($pdo, $user, 'gate', $pdo->lastInsertId(), 'create', null,
                   compact('code', 'name', 'proj', 'cctv', 'stat'));
            cnxNotifyAdmins($pdo, 'gate_setting', 'เพิ่มประตู ' . $code . ' (' . admProjCode($pdo, $proj) . ')',
                            'ประเภท: ' . ($cctv ? 'มี CCTV' : 'ไม่มี CCTV') . ' · มีตู้ + กลอน', (string)($user['username'] ?? ''), $proj);
            $notice = ['ok', 'เพิ่มประตู ' . $code . ' แล้ว'];
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════
// [2026-10-02] ประตูเก่าที่ยังไม่มีตู้ → มีตู้ + กลอน · key ต่อตู้ (GP-22/23) · ค่าความปลอดภัยตู้/QR (GP-17/21/22)
// ทุกการแก้: activity_log + แจ้งเตือนผู้ดูแลระบบ (GP-46)
// ═══════════════════════════════════════════════════════════════════════
/** ข้อความสรุปสิ่งที่เปลี่ยนของประตู */
function admGateChanges(PDO $pdo, array $old, array $new): array {
    $out = [];
    if ((string)($old['gate_code'] ?? '') !== (string)$new['gate_code']) { $out[] = 'รหัส ' . ($old['gate_code'] ?? '-') . ' → ' . $new['gate_code']; }
    if ((string)($old['name'] ?? '') !== (string)$new['name'])           { $out[] = 'ชื่อ "' . ($old['name'] ?? '') . '" → "' . $new['name'] . '"'; }
    if ((int)($old['project_id'] ?? 0) !== (int)$new['project_id'])      { $out[] = 'ไซต์ ' . admProjCode($pdo, (int)($old['project_id'] ?? 0)) . ' → ' . admProjCode($pdo, (int)$new['project_id']); }
    if ((int)($old['has_cctv'] ?? 1) !== (int)$new['has_cctv']) {
        $out[] = 'ประเภท ' . ((int)($old['has_cctv'] ?? 1) ? 'มี CCTV' : 'ไม่มี CCTV') . ' → ' . ((int)$new['has_cctv'] ? 'มี CCTV' : 'ไม่มี CCTV (ไม่มี ALARM 4 · ข้ามตรวจกล้อง)');
    }
    if ((string)($old['status'] ?? '') !== (string)$new['status'])       { $out[] = 'สถานะ ' . ($old['status'] ?? '-') . ' → ' . $new['status']; }
    return $out;
}
function admProjCode(PDO $pdo, int $id): string {
    $st = $pdo->prepare('SELECT code FROM projects WHERE id = ?');
    $st->execute([$id]);
    $v = $st->fetchColumn();
    return $v === false ? ('#' . $id) : (string)$v;
}
function admGateRow(PDO $pdo, int $id) {
    $st = $pdo->prepare('SELECT g.*, p.code AS proj_code FROM gates g JOIN projects p ON p.id = g.project_id WHERE g.id = ?');
    $st->execute([$id]);
    return $st->fetch();
}
$newGateKey = null;   // key ที่เพิ่งสร้าง — แสดงครั้งเดียว

if ($action === 'gate_hw_on') {
    $g = admGateRow($pdo, (int)($_POST['id'] ?? 0));
    if (!$g) {
        $notice = ['bad', 'ไม่พบประตู'];
    } elseif (isTrueFlag($g['hardware_close'])) {
        $notice = ['warn', 'ประตู ' . $g['gate_code'] . ' เป็นแบบมีตู้ + กลอนอยู่แล้ว'];
    } else {
        $pdo->prepare('UPDATE gates SET hardware_close = 1 WHERE id = ?')->execute([(int)$g['id']]);
        admLog($pdo, $user, 'gate', (int)$g['id'], 'hardware_on', ['hardware_close' => 0], ['hardware_close' => 1]);
        cnxNotifyAdmins($pdo, 'gate_setting', 'ประตู ' . $g['gate_code'] . ' (' . $g['proj_code'] . ') เปลี่ยนเป็นมีตู้ + กลอน',
                        'ใบของประตูนี้จะปิดงาน/ตัดสต๊อกตอนตู้ปิดประตู (เดิมปิดงานตอนถ่ายรูปยืนยัน)', (string)($user['username'] ?? ''), (int)$g['project_id']);
        $notice = ['ok', 'ประตู ' . $g['gate_code'] . ' เปลี่ยนเป็นมีตู้ + กลอนแล้ว — ใบที่เปิดรอบหลังจากนี้ต้องปิดประตูที่ตู้'];
    }
}

if ($action === 'gate_key_new' || $action === 'gate_key_revoke') {
    $g = admGateRow($pdo, (int)($_POST['id'] ?? 0));
    if (!$g) {
        $notice = ['bad', 'ไม่พบประตู'];
    } elseif ($action === 'gate_key_new') {
        $key = gateSecNewKey($pdo, (int)$g['id'], (string)($user['username'] ?? ''));
        admLog($pdo, $user, 'gate', (int)$g['id'], 'api_key_new', ['hint' => $g['api_key_hint'] ?? null], ['hint' => substr($key, -4)]);
        cnxNotifyAdmins($pdo, 'gate_key', 'สร้าง key ตู้ใหม่ ' . $g['gate_code'] . ' (' . $g['proj_code'] . ')',
                        'key ลงท้าย …' . substr($key, -4) . ($g['api_key_hint'] ? ' แทน key เดิม …' . $g['api_key_hint'] . ' (key เดิมใช้ไม่ได้แล้ว)' : ''),
                        (string)($user['username'] ?? ''), (int)$g['project_id']);
        $newGateKey = ['gate' => (string)$g['gate_code'], 'site' => (string)$g['proj_code'], 'key' => $key];
        $notice = ['ok', 'สร้าง key ใหม่ของตู้ ' . $g['gate_code'] . ' แล้ว — คัดลอกไปใส่ที่ตู้ตอนนี้ (หน้านี้แสดงครั้งเดียว)'];
    } else {
        gateSecRevokeKey($pdo, (int)$g['id']);
        admLog($pdo, $user, 'gate', (int)$g['id'], 'api_key_revoke', ['hint' => $g['api_key_hint'] ?? null], null);
        cnxNotifyAdmins($pdo, 'gate_key', 'ยกเลิก key ตู้ ' . $g['gate_code'] . ' (' . $g['proj_code'] . ')',
                        'ตู้นี้ต้องใช้ key กลางแทน' . (appSettingOn($pdo, 'gate_shared_key_ok') ? '' : ' — แต่ key กลางปิดอยู่: ตู้นี้จะต่อเว็บไม่ได้'),
                        (string)($user['username'] ?? ''), (int)$g['project_id']);
        $notice = ['ok', 'ยกเลิก key ของตู้ ' . $g['gate_code'] . ' แล้ว'];
    }
}

if ($action === 'gate_sec_save') {
    $want = ['qr_require_code' => !empty($_POST['qr_require_code']) ? '1' : '0',
             'gate_shared_key_ok' => !empty($_POST['gate_shared_key_ok']) ? '1' : '0'];
    $labels = ['qr_require_code' => 'บังคับรหัสตรวจสอบ QR', 'gate_shared_key_ok' => 'รับ key กลาง'];
    $chg = [];
    foreach ($want as $k => $v) {
        if (appSetting($pdo, $k) === $v) { continue; }
        appSettingSet($pdo, $k, $v, (string)($user['username'] ?? ''));
        $chg[] = $labels[$k] . ': ' . ($v === '1' ? 'เปิด' : 'ปิด');
    }
    if ($want['gate_shared_key_ok'] === '0') {
        $noKey = (int)$pdo->query("SELECT COUNT(*) FROM gates WHERE status = 'active' AND hardware_close = 1 AND api_key_hash IS NULL")->fetchColumn();
        if ($noKey > 0) { $chg[] = '⚠ ยังมีตู้ที่ไม่มี key ของตัวเอง ' . $noKey . ' ตู้ — ตู้เหล่านั้นต่อเว็บไม่ได้'; }
    }
    if ($chg) {
        cnxNotifyAdmins($pdo, 'gate_setting', 'แก้ค่าความปลอดภัยตู้ / QR', implode(' · ', $chg), (string)($user['username'] ?? ''));
        $notice = ['ok', 'บันทึกแล้ว — ' . implode(' · ', $chg)];
    } else {
        $notice = ['warn', 'ไม่มีค่าที่เปลี่ยน'];
    }
}

// ═══════════════════════════════════════════════════════════════════════
// [2026-10-02 · health] แจ้งเตือน R&D นอกแอป (Teams / อีเมล) + งานตั้งเวลา — lib/notify.php · lib/jobs.php
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'notify_save') {
    $by = (string)($user['username'] ?? '');
    $vals = [
        'reconcile_every_min' => (string)max(0, min(60, (int)($_POST['reconcile_every_min'] ?? 5))),
        'sc_weekly_random'    => !empty($_POST['sc_weekly_random']) ? '1' : '0',
    ];
    if (CNX_NOTIFY_EXTERNAL) {
        $vals += [
            'notify_teams_url' => trim((string)($_POST['notify_teams_url'] ?? '')),
            'notify_email_to'  => trim((string)($_POST['notify_email_to'] ?? '')),
            'smtp_host'        => trim((string)($_POST['smtp_host'] ?? '')),
            'smtp_port'        => (string)max(1, min(65535, (int)($_POST['smtp_port'] ?? 587))),
            'smtp_secure'      => in_array($_POST['smtp_secure'] ?? 'tls', ['tls', 'ssl', 'none'], true) ? (string)$_POST['smtp_secure'] : 'tls',
            'smtp_user'        => trim((string)($_POST['smtp_user'] ?? '')),
            'smtp_from'        => trim((string)($_POST['smtp_from'] ?? '')),
        ];
    }
    $err = null;
    if (isset($vals['notify_teams_url']) && $vals['notify_teams_url'] !== '' && !preg_match('#^https://#i', $vals['notify_teams_url'])) { $err = 'URL ของ Teams ต้องขึ้นต้นด้วย https://'; }
    foreach (preg_split('/[,;\s]+/', $vals['notify_email_to'] ?? '') ?: [] as $em) {
        if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) { $err = 'อีเมลผู้รับไม่ถูกต้อง: ' . $em; }
    }
    if ($err !== null) {
        $notice = ['bad', $err];
    } else {
        $chg = [];
        foreach ($vals as $k => $v) {
            if (appSetting($pdo, $k) === $v) { continue; }
            appSettingSet($pdo, $k, $v, $by);
            $chg[] = $k;
        }
        $pass = CNX_NOTIFY_EXTERNAL ? (string)($_POST['smtp_pass'] ?? '') : '';
        if (CNX_NOTIFY_EXTERNAL && !empty($_POST['smtp_pass_clear'])) { appSettingSet($pdo, 'smtp_pass', '', $by, true); $chg[] = 'smtp_pass (ล้าง)'; }
        elseif ($pass !== '') { appSettingSet($pdo, 'smtp_pass', $pass, $by, true); $chg[] = 'smtp_pass'; }
        if ($chg) {
            cnxNotifyAdmins($pdo, 'gate_setting', 'แก้ค่าการแจ้งเตือน / งานตั้งเวลา', 'เปลี่ยน: ' . implode(', ', $chg), $by);
            $notice = ['ok', 'บันทึกแล้ว — ' . implode(', ', $chg)];
        } else {
            $notice = ['warn', 'ไม่มีค่าที่เปลี่ยน'];
        }
    }
}
if ($action === 'notify_test' && CNX_NOTIFY_EXTERNAL) {
    $r =cnxNotifyExternal($pdo, 'test', 'ทดสอบการแจ้งเตือน', 'ข้อความทดสอบจากหน้าตั้งค่าระบบ — ถ้าเห็นข้อความนี้ ช่องทางนี้ใช้ได้', (string)($user['username'] ?? ''));
    $txt = [];
    foreach (['teams' => 'Teams', 'email' => 'อีเมล'] as $k => $lbl) {
        $txt[] = $lbl . ': ' . ($r[$k] === null ? 'ยังไม่ได้ตั้งค่า' : ($r[$k] ? 'ส่งสำเร็จ' : 'ส่งไม่สำเร็จ (ดู error log ของ PHP)'));
    }
    $okAny = $r['teams'] === true || $r['email'] === true;
    $notice = [$okAny ? 'ok' : 'warn', 'ทดสอบส่ง — ' . implode(' · ', $txt)];
}

// ═══════════════════════════════════════════════════════════════════════
// เวลาหยิบของต่อรอบ (Scenario 05 ⑧ · 2026-09-28) — ต่อไซต์ · ตู้ดึงพร้อมรายชื่อบัตรตอนเปิดเครื่อง
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'gate_timing_save') {
    $proj = (int)($_POST['project_id'] ?? 0);
    list($vals, $err) = s05ValidateGateSettings($_POST['pick_min_per_item'] ?? '', $_POST['pick_cap_min'] ?? '', $_POST['extend_min'] ?? '',
                                                ($_POST['store_over_cap'] ?? '0'));
    if ($proj <= 0) {
        $notice = ['bad', 'ต้องเลือกโครงการ'];
    } elseif ($err !== null) {
        $notice = ['bad', $err];
    } else {
        s05SaveGateSettings($pdo, $proj, $vals, (string)($user['username'] ?? ''));
        $pc = $pdo->prepare('SELECT code FROM projects WHERE id = ?');
        $pc->execute([$proj]);
        $notice = ['ok', 'บันทึกเวลาหยิบของของไซต์ ' . (string)$pc->fetchColumn() . ' แล้ว — '
                       . s05Num($vals['pickMinPerItem']) . ' นาที/รายการ · เพดาน ' . $vals['pickCapMin'] . ' นาที · เลื่อนครั้งละ '
                       . $vals['extendMin'] . ' นาที · '
                       . (!empty($vals['storeOverCap']) ? 'สายสโตร์ขอเกินเพดานได้' : 'เพดานตายตัวทุกบัตร')
                       . ' (ตู้ใช้ค่าใหม่หลังรีสตาร์ท)'];
    }
}

// ═══════════════════════════════════════════════════════════════════════
// โครงการ
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'proj_save') {
    $id   = (int)($_POST['id'] ?? 0);
    $code = strtoupper(trim((string)($_POST['code'] ?? '')));
    $name = trim((string)($_POST['name'] ?? ''));
    $ref  = trim((string)($_POST['site_ref'] ?? ''));
    $stat = ($_POST['status'] ?? 'active') === 'archived' ? 'archived' : 'active';

    if ($code === '' || $name === '') {
        $notice = ['bad', 'ต้องใส่ทั้งรหัสและชื่อโครงการ'];
    } else {
        $dup = $pdo->prepare('SELECT id FROM projects WHERE code = ? AND id <> ?');
        $dup->execute([$code, $id]);
        if ($dup->fetchColumn()) {
            $notice = ['bad', 'มีโครงการรหัส ' . $code . ' อยู่แล้ว'];
        } elseif ($id > 0) {
            $old = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
            $old->execute([$id]);
            $oldRow = $old->fetch();
            $st = $pdo->prepare('UPDATE projects SET code=?, name=?, site_ref=?, status=? WHERE id=?');
            $st->execute([$code, $name, $ref !== '' ? $ref : null, $stat, $id]);
            admLog($pdo, $user, 'project', $id, 'update', $oldRow, compact('code', 'name', 'ref', 'stat'));
            $notice = ['ok', 'บันทึกโครงการ ' . $code . ' แล้ว'];
        } else {
            $st = $pdo->prepare('INSERT INTO projects (code, name, site_ref, status) VALUES (?,?,?,?)');
            $st->execute([$code, $name, $ref !== '' ? $ref : null, $stat]);
            admLog($pdo, $user, 'project', $pdo->lastInsertId(), 'create', null, compact('code', 'name', 'ref', 'stat'));
            $notice = ['ok', 'เพิ่มโครงการ ' . $code . ' แล้ว'];
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════
// วัสดุ
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'mat_save') {
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $unit = trim((string)($_POST['unit'] ?? ''));
    $cat  = icNormalizeCat((string)($_POST['cat_id'] ?? ''));
    $chr  = icNormalizeChar((string)($_POST['char_id'] ?? ''));
    $sub  = trim((string)($_POST['subgroup_name'] ?? ''));

    if ($id <= 0 || $name === '') {
        $notice = ['bad', 'ต้องเลือกวัสดุและใส่ชื่อ'];
    } else {
        $old = $pdo->prepare('SELECT * FROM materials WHERE id = ?');
        $old->execute([$id]);
        $oldRow = $old->fetch();

        // มติ 29: แถวรหัส IC เอา cat/char มาจากตัวสินค้า (LLP) — ที่นี่แก้ไม่ได้
        // (จอซ่อนช่องให้แล้ว ตรงนี้กันคนยิง POST ตรงอีกชั้น)
        $isIc = $oldRow !== false && (string)$oldRow['code_type'] === 'ic';
        if ($isIc) {
            $cat = $oldRow['cat_id'] !== null ? (string)$oldRow['cat_id'] : null;
            $chr = $oldRow['char_id'] !== null ? (string)$oldRow['char_id'] : null;
        } elseif ($cat === null) {
            $cat = $oldRow !== false ? (string)$oldRow['cat_id'] : 'C02';
        }

        $st = $pdo->prepare('UPDATE materials SET name=?, unit=?, cat_id=?, char_id=?, subgroup_name=? WHERE id=?');
        $st->execute([$name, $unit, $cat, $chr, $sub !== '' ? $sub : null, $id]);
        admLog($pdo, $user, 'material', $id, 'update', $oldRow, compact('name', 'unit', 'cat', 'chr', 'sub'));
        if ($oldRow && ((string)$oldRow['cat_id'] !== (string)$cat || (string)($oldRow['char_id'] ?? '') !== (string)($chr ?? ''))) {
            // [2026-10-02 · GP-46] เปลี่ยนหมวดอนุมัติ/ลักษณะวัสดุ (เช่น C01 → NAR = เบิกไม่ต้องอนุมัติ) — แจ้งทุกครั้ง
            cnxNotifyAdmins($pdo, 'material_category', 'เปลี่ยนหมวดวัสดุ ' . (string)$oldRow['mat_code'],
                            (string)$oldRow['cat_id'] . '/' . (string)($oldRow['char_id'] ?? '-') . ' → ' . (string)$cat . '/' . (string)($chr ?? '-'),
                            (string)($user['username'] ?? ''));
        }
        $notice = ['ok', 'บันทึกวัสดุ ' . (string)($oldRow['mat_code'] ?? '') . ' แล้ว'];
    }
}

if ($action === 'mat_gate') {
    $matId  = (int)($_POST['material_id'] ?? 0);
    $projId = (int)($_POST['project_id'] ?? 0);
    $gateId = (int)($_POST['gate_id'] ?? 0);

    $gateOk = true;
    if ($gateId > 0) {
        $chk = $pdo->prepare("SELECT 1 FROM gates WHERE id = ? AND project_id = ? AND status = 'active'");
        $chk->execute([$gateId, $projId]);
        $gateOk = (bool)$chk->fetchColumn();
    }

    if ($matId <= 0 || $projId <= 0) {
        $notice = ['bad', 'ต้องระบุทั้งวัสดุและโครงการ'];
    } elseif (!$gateOk) {
        $notice = ['bad', 'ประตูที่เลือกไม่ได้อยู่ในไซต์นี้ หรือถูกปิดใช้งานแล้ว'];
    } else {
        $sel = $pdo->prepare('SELECT id FROM project_materials WHERE project_id = ? AND material_id = ?');
        $sel->execute([$projId, $matId]);
        $pmId = $sel->fetchColumn();
        if ($pmId) {
            $pdo->prepare('UPDATE project_materials SET gate_id = ? WHERE id = ?')
                ->execute([$gateId > 0 ? $gateId : null, (int)$pmId]);
        } else {
            $pdo->prepare('INSERT INTO project_materials (project_id, material_id, gate_id) VALUES (?,?,?)')
                ->execute([$projId, $matId, $gateId > 0 ? $gateId : null]);
        }
        admLog($pdo, $user, 'project_material', $matId, 'set_gate', null,
               ['project_id' => $projId, 'gate_id' => $gateId]);
        $notice = ['ok', 'ตั้งประตูตั้งต้นให้วัสดุนี้ในไซต์ที่เลือกแล้ว'];

        // ยอดที่ยังไม่อยู่ประตูใด → ลงที่ประตูนี้ด้วย เบิกได้ทันที (ของที่อยู่ประตูอื่นแล้วไม่ย้าย)
        if ($gateId > 0) {
            try {
                $al = stockAllocateUnassigned($pdo, $projId, $matId, $gateId);
                if ($al['items'] > 0) {
                    admLog($pdo, $user, 'stock_gate_balances', $projId, 'allocate_unassigned', null,
                           ['gate_id' => $gateId, 'rows' => $al['rows']]);
                    $notice = ['ok', 'ตั้งประตูตั้งต้นแล้ว · ลงยอดที่ยังไม่อยู่ประตูใด ' . fmtQ($al['qty'])
                                   . ' หน่วยไว้ที่ประตูนี้ด้วย (เบิกได้ทันที)'];
                }
            } catch (Throwable $e) {
                $notice = ['bad', 'ตั้งประตูแล้ว แต่ลงยอดที่ประตูไม่สำเร็จ: ' . $e->getMessage()];
            }
        }
    }
}

// ยอดคงเหลือที่ยังไม่ลงประตู → ลงที่ประตูตั้งต้นของแต่ละวัสดุ ทั้งไซต์ — [2026-09-23 gate-stock]
// ย้ายของข้ามประตู (มติ 51 — [PHP port 2026-09-25 per-gate]) — ยอดรวมไซต์ไม่เปลี่ยน
// ใช้จัดของให้ตรงกับที่วางจริง เช่น ปูนสกิม 60 ถุงที่ G02 → แบ่ง 50 ไป G03 (ฟอร์มเบิกจะเห็นสองประตูทันที)
if ($action === 'mat_transfer') {
    $matId  = (int)($_POST['material_id'] ?? 0);
    $projId = (int)($_POST['project_id'] ?? 0);
    $fromId = (int)($_POST['from_gate_id'] ?? 0);
    $toId   = (int)($_POST['to_gate_id'] ?? 0);
    $qty    = (float)str_replace(',', '', trim((string)($_POST['qty'] ?? '0')));
    $note   = trim((string)($_POST['note'] ?? ''));
    try {
        $r = stockTransferBetweenGates($pdo, $projId, $matId, $fromId, $toId, $qty, (string)($user['username'] ?? ''), $note);
        $notice = ['ok', 'ย้าย ' . $r['mat_code'] . ' จำนวน ' . fmtQ($r['qty']) . ' ' . $r['unit']
                         . ' จาก ' . $r['from']['gate_code'] . ' → ' . $r['to']['gate_code'] . ' แล้ว'
                         . ' (ตอนนี้ ' . $r['from']['gate_code'] . ' ' . fmtQ($r['from']['on_hand'])
                         . ' · ' . $r['to']['gate_code'] . ' ' . fmtQ($r['to']['on_hand']) . ')'];
    } catch (Throwable $e) {
        $notice = ['bad', 'ย้ายของไม่สำเร็จ (ไม่มีอะไรถูกเปลี่ยน): ' . $e->getMessage()];
    }
}

if ($action === 'gate_alloc') {
    $projId = (int)($_POST['project_id'] ?? 0);
    if ($projId <= 0) {
        $notice = ['bad', 'ต้องเลือกไซต์'];
    } else {
        try {
            $al = stockAllocateUnassigned($pdo, $projId);
            $byGate = [];
            foreach ($al['rows'] as $r) { $byGate[$r[3]] = ($byGate[$r[3]] ?? 0) + 1; }
            admLog($pdo, $user, 'stock_gate_balances', $projId, 'allocate_unassigned', null, [
                'items' => $al['items'], 'qty' => $al['qty'], 'by_gate' => $byGate,
                'rows' => array_slice($al['rows'], 0, 1000), 'skipped' => $al['skipped'],
            ]);
            $parts = [];
            foreach ($byGate as $gc => $n) { $parts[] = $gc . ' ' . number_format($n) . ' รายการ'; }
            $notice = [$al['skipped'] ? 'warn' : 'ok',
                'ลงยอดที่ประตูแล้ว ' . number_format($al['items']) . ' รายการ รวม ' . fmtQ($al['qty']) . ' หน่วย'
                . ($parts ? ' (' . implode(' · ', $parts) . ')' : '')
                . ($al['skipped'] ? ' · ข้าม ' . count($al['skipped']) . ' รายการ — ดูตารางด้านล่าง' : '')];
        } catch (Throwable $e) {
            $notice = ['bad', 'ลงยอดที่ประตูไม่สำเร็จ (ไม่มีอะไรถูกเปลี่ยน): ' . $e->getMessage()];
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════
// ผู้ใช้ + บทบาท/สิทธิ์
// ═══════════════════════════════════════════════════════════════════════

/**
 * จำนวน "ผู้ดูแลที่ยังใช้งานได้" ในระบบ = user active + role level 0
 * ใช้เป็นตัวกันล็อกตัวเองออก: ทุก mutation ที่แตะ users/roles จะนับใหม่หลังแก้
 * ถ้าเหลือ 0 = ย้อนกลับทั้งรายการ (กันเคสที่คิดไม่ถึงได้ทุกทาง ไม่ต้องไล่เดาเงื่อนไข)
 */
function admAdminCount(PDO $pdo): int {
    return (int)$pdo->query(
        "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
          WHERE u.status = 'active' AND r.level = 0"
    )->fetchColumn();
}

/** ตัดการล็อกอินค้าง (remember token) ของบัญชีนั้นทิ้ง */
/**
 * [2026-10-08] รหัสบัตร RFID ที่กรอก → รูปแบบเดียวกับที่ตู้อ่าน (เลขฐาน 16 ตัวพิมพ์ใหญ่ เช่น 0FA76A)
 *   ตัดช่องว่าง/ขีด · ตัวพิมพ์เล็ก → ใหญ่ · ตัวอักษร O → เลข 0 (ฐาน 16 ไม่มีตัว O — พิมพ์พลาดบ่อย)
 *   ไม่ใช่ฐาน 16 / สั้นยาวผิด → error · ซ้ำกับผู้ใช้อื่นที่ยังใช้งาน → error
 * @return array [รหัส|'' , error|null, ข้อความว่าแปลงอะไรให้|'' ]
 */
function admNormCardId(PDO $pdo, string $raw, int $selfId): array {
    $s = strtoupper(preg_replace('/[\s\-:]+/u', '', $raw));
    if ($s === '') { return ['', null, '']; }
    $fixed = strtr($s, ['O' => '0']);
    $note = $fixed !== $s ? 'แปลงตัวอักษร O เป็นเลข 0 ให้แล้ว (' . $s . ' → ' . $fixed . ')' : '';
    if (!preg_match('/^[0-9A-F]{4,16}$/', $fixed)) {
        return ['', 'รหัสบัตร "' . $raw . '" ไม่ถูกรูปแบบ — ใช้เลข 0–9 และตัวอักษร A–F 4–16 ตัว ตามที่ตู้แสดงตอนแตะบัตร (เช่น 0FA76A)', ''];
    }
    $dup = $pdo->prepare("SELECT username, full_name FROM users WHERE id <> ? AND status <> 'inactive'
                            AND UPPER(REPLACE(TRIM(card_id), ' ', '')) = ? LIMIT 1");
    $dup->execute([$selfId, $fixed]);
    if ($d = $dup->fetch()) {
        return ['', 'บัตร ' . $fixed . ' ผูกกับ ' . (string)$d['username'] . ' (' . (string)$d['full_name'] . ') อยู่แล้ว — บัตรหนึ่งใบใช้ได้คนเดียว', ''];
    }
    return [$fixed, null, $note];
}

function admRevokeTokens(PDO $pdo, int $userId): int {
    try {
        $st = $pdo->prepare("DELETE FROM remember_tokens WHERE account_type = 'user' AND account_id = ?");
        $st->execute([$userId]);
        return $st->rowCount();
    } catch (Throwable $e) {
        error_log('admRevokeTokens: ' . $e->getMessage());
        return 0;
    }
}

// ── แก้ผู้ใช้เดิม ───────────────────────────────────────────────────────
if ($action === 'user_save') {
    $id    = (int)($_POST['id'] ?? 0);
    $full  = trim((string)($_POST['full_name'] ?? ''));
    $emp   = trim((string)($_POST['emp_code'] ?? ''));
    $role  = (int)($_POST['role_id'] ?? 0);
    $proj  = (int)($_POST['project_id'] ?? 0);
    $card  = trim((string)($_POST['card_id'] ?? ''));
    $stat  = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    list($cardNorm, $cardErr, $cardNote) = admNormCardId($pdo, $card, $id);   // [2026-10-08]

    if ($id <= 0 || $full === '' || $role <= 0 || $proj <= 0) {
        $notice = ['bad', 'ต้องกรอกชื่อ-นามสกุล เลือกบทบาท และเลือกไซต์ให้ครบ'];
    } elseif ($cardErr !== null && $stat !== 'inactive') {
        $notice = ['bad', $cardErr];
    } else {
        $card = $cardErr === null ? $cardNorm : $card;   // ปิดใช้งาน = เก็บค่าเดิมได้ (ไม่ส่งให้ตู้อยู่แล้ว)
        $old = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $old->execute([$id]);
        $oldRow = $old->fetch();
        if (!$oldRow) {
            $notice = ['bad', 'ไม่พบผู้ใช้'];
        } else {
            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare(
                    'UPDATE users SET full_name=?, emp_code=?, role_id=?, project_id=?, card_id=?, status=?
                      WHERE id=?'
                );
                $st->execute([$full, $emp !== '' ? $emp : null, $role, $proj,
                              $card !== '' ? $card : null, $stat, $id]);

                if (admAdminCount($pdo) === 0) {
                    throw new RuntimeException(
                        'แก้ไม่ได้ — จะไม่เหลือผู้ดูแลระบบที่ใช้งานได้เลยสักคน '
                        . '(ต้องมี user สถานะ active ที่บทบาทอยู่ระดับ 0 อย่างน้อย 1 คน)'
                    );
                }

                // ปิดใช้งาน / ย้ายบทบาท = session เดิมไม่ควรอยู่ต่อ
                $revoked = 0;
                if ($stat === 'inactive' || (int)$oldRow['role_id'] !== $role) {
                    $revoked = admRevokeTokens($pdo, $id);
                }

                unset($oldRow['password']);   // ห้ามให้ hash หลุดลง log เด็ดขาด
                admLog($pdo, $user, 'user', $id, 'update', $oldRow,
                       ['full_name' => $full, 'emp_code' => $emp, 'role_id' => $role,
                        'project_id' => $proj, 'card_id' => $card, 'status' => $stat]);
                $pdo->commit();

                $notice = ['ok', 'บันทึกผู้ใช้ ' . (string)$oldRow['username'] . ' แล้ว'
                    . ($cardNote !== '' ? ' · ' . $cardNote : '')
                    . ($card !== '' && $card !== (string)($oldRow['card_id'] ?? '') ? ' · บัตร ' . $card . ' แตะที่ตู้ได้ทันที (ไม่ต้องรีสตาร์ท)' : '')
                    . ($revoked > 0 ? ' · ตัดการล็อกอินค้างของบัญชีนี้ ' . $revoked . ' เครื่อง' : '')];
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $notice = ['bad', $e->getMessage()];
            }
        }
    }
}

// ── เพิ่มผู้ใช้ใหม่ ─────────────────────────────────────────────────────
if ($action === 'user_new') {
    $uname = trim((string)($_POST['username'] ?? ''));
    $full  = trim((string)($_POST['full_name'] ?? ''));
    $emp   = trim((string)($_POST['emp_code'] ?? ''));
    $role  = (int)($_POST['role_id'] ?? 0);
    $proj  = (int)($_POST['project_id'] ?? 0);
    $pass  = (string)($_POST['password'] ?? '');

    $perr = validatePasswordPolicy($pass);
    if ($uname === '' || $full === '' || $role <= 0 || $proj <= 0) {
        $notice = ['bad', 'ต้องกรอกชื่อผู้ใช้ ชื่อ-นามสกุล บทบาท และไซต์ให้ครบ'];
    } elseif (preg_match('/\s/', $uname)) {
        $notice = ['bad', 'ชื่อผู้ใช้ต้องไม่มีช่องว่าง'];
    } elseif ($perr !== null) {
        $notice = ['bad', 'รหัสผ่าน: ' . $perr];
    } else {
        $dup = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $dup->execute([$uname]);
        if ($dup->fetchColumn()) {
            $notice = ['bad', 'มีชื่อผู้ใช้ ' . $uname . ' อยู่แล้ว'];
        } else {
            $st = $pdo->prepare(
                'INSERT INTO users (username, password, full_name, emp_code, role_id, project_id, status)
                 VALUES (?,?,?,?,?,?,"active")'
            );
            $st->execute([$uname, password_hash($pass, PASSWORD_DEFAULT), $full,
                          $emp !== '' ? $emp : null, $role, $proj]);
            // ห้ามบันทึกรหัสผ่านลง log ไม่ว่าในรูปแบบใด
            admLog($pdo, $user, 'user', $pdo->lastInsertId(), 'create', null,
                   ['username' => $uname, 'full_name' => $full, 'role_id' => $role, 'project_id' => $proj]);
            $notice = ['ok', 'เพิ่มผู้ใช้ ' . $uname . ' แล้ว — แจ้งรหัสผ่านให้เจ้าตัวแล้วบอกให้เปลี่ยนเองทันที'];
        }
    }
}

// ── ตั้งรหัสผ่านใหม่ให้ผู้ใช้ (ADM ไม่ต้องรู้รหัสเดิม) ──────────────────
if ($action === 'user_pass') {
    $id    = (int)($_POST['id'] ?? 0);
    $pass  = (string)($_POST['new_password'] ?? '');
    $kick  = !empty($_POST['revoke']);

    $perr = validatePasswordPolicy($pass);
    if ($id <= 0) {
        $notice = ['bad', 'ไม่พบผู้ใช้'];
    } elseif ($perr !== null) {
        $notice = ['bad', $perr];
    } else {
        $sel = $pdo->prepare('SELECT username FROM users WHERE id = ?');
        $sel->execute([$id]);
        $uname = $sel->fetchColumn();
        if ($uname === false) {
            $notice = ['bad', 'ไม่พบผู้ใช้'];
        } else {
            $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')
                ->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
            $revoked = $kick ? admRevokeTokens($pdo, $id) : 0;

            // จดว่า "ใครตั้งรหัสให้ใคร เมื่อไหร่" — ไม่จดตัวรหัสผ่าน
            admLog($pdo, $user, 'user', $id, 'reset_password', null,
                   ['username' => $uname, 'revoked_tokens' => $revoked]);
            $notice = ['ok', 'ตั้งรหัสผ่านใหม่ให้ ' . (string)$uname . ' แล้ว'
                . ($kick ? ' · ตัดการล็อกอินค้าง ' . $revoked . ' เครื่อง' : '')
                . ' — แจ้งเจ้าตัวแล้วบอกให้เปลี่ยนเองทันที'];
        }
    }
}

// ── แก้บทบาท/สิทธิ์ ────────────────────────────────────────────────────
if ($action === 'role_save') {
    $id    = (int)($_POST['id'] ?? 0);
    $name  = trim((string)($_POST['name'] ?? ''));
    $level = (int)($_POST['level'] ?? 1);
    $req   = !empty($_POST['can_req'])         ? 1 : 0;
    $dc    = !empty($_POST['can_daily_check']) ? 1 : 0;
    $sc    = !empty($_POST['sc'])              ? 1 : 0;
    $bs    = !empty($_POST['bs'])              ? 1 : 0;
    if ($level < 0)  { $level = 0; }
    if ($level > 12) { $level = 12; }

    if ($id <= 0 || $name === '') {
        $notice = ['bad', 'ต้องเลือกบทบาทและใส่ชื่อ'];
    } else {
        $old = $pdo->prepare('SELECT * FROM roles WHERE id = ?');
        $old->execute([$id]);
        $oldRow = $old->fetch();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                'UPDATE roles SET name=?, level=?, can_req=?, can_daily_check=?, sc=?, bs=? WHERE id=?'
            );
            $st->execute([$name, $level, $req, $dc, $sc, $bs, $id]);

            if (admAdminCount($pdo) === 0) {
                throw new RuntimeException(
                    'แก้ไม่ได้ — จะไม่เหลือผู้ดูแลระบบที่ใช้งานได้เลยสักคน '
                    . '(ลดระดับบทบาทนี้ลงจากระดับ 0 แล้วไม่มีใครเหลือเป็น ADM)'
                );
            }

            admLog($pdo, $user, 'role', $id, 'update', $oldRow,
                   compact('name', 'level', 'req', 'dc', 'sc', 'bs'));
            $pdo->commit();
            $notice = ['ok', 'บันทึกบทบาท ' . (string)($oldRow['role_code'] ?? '') . ' แล้ว'
                . ' — ผู้ใช้บทบาทนี้จะเห็นผลตอนล็อกอินรอบถัดไป'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $notice = ['bad', $e->getMessage()];
        }
    }
}

// ── ข้อมูลที่ทุกแท็บใช้ ────────────────────────────────────────────────
$projects = $pdo->query('SELECT * FROM projects ORDER BY status, code')->fetchAll();
$projById = [];
foreach ($projects as $p) { $projById[(int)$p['id']] = $p; }

// ในกรอบของแอปหลัก: ซ่อนเมนูโมดูล — แท็บของหน้านี้ทำหน้าที่นำทางแทน
// และแอปหลักมีเมนู "ตั้งค่าระบบ" ของตัวเองอยู่แล้ว ใส่ซ้ำจะไฮไลต์เพี้ยน
uiHead('ตั้งค่าระบบ', 'ประตู · โครงการ · วัสดุ · ผู้ใช้ · บันได IC — สำหรับผู้ดูแลระบบ', $user, '⚙️', uiIsEmbedded());
?>

<style>
/* โครงเดียวกับหน้า "เบิก-จ่าย" / "ตั้งค่า" ของแอปหลัก: แถบแท็บ + เนื้อหาในกล่องเดียว แบ่งเป็นส่วน ๆ */
.adm-sec+.adm-sec{margin-top:1.75rem;padding-top:1.5rem;border-top:1px solid var(--border)}
.adm-hd{display:flex;align-items:center;flex-wrap:wrap;gap:.4rem .75rem;margin-bottom:.9rem}
.adm-hd h3{display:flex;align-items:center;gap:.55rem;font-size:1.05rem;font-weight:600;line-height:1.4;color:var(--secondary)}
.adm-hd h3>i{width:1.15em;text-align:center;color:var(--primary)}
.adm-hd .sub{font-size:.8rem;color:var(--text-muted)}
.adm-hd .end{margin-left:auto;display:flex;flex-wrap:wrap;gap:.4rem}
.note{margin-top:.75rem;font-size:.8rem;line-height:1.7;color:var(--text-muted)}
.mini-form{display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end}
.mini-form>div{min-width:0}
.mini-form .grow{flex:1 1 200px}
.mini-form .grow input,.mini-form .grow select{width:100%}
.chk{display:inline-flex;align-items:center;gap:.45rem;min-height:42px;font-size:.88rem;white-space:nowrap;cursor:pointer}
.chk-group{display:flex;flex-wrap:wrap;gap:0 1.1rem}
.edit-row{display:none}
.edit-row.on{display:table-row}
.tbl tr.edit-row>td{background:#f8fafc;box-shadow:inset 3px 0 0 var(--primary-light);padding:1rem 1.1rem}
.edit-row form+form{margin-top:.9rem;padding-top:.9rem;border-top:1px dashed var(--border)}
.tbl td.act{width:1%;white-space:nowrap;text-align:right}
.tbl .sub-line{font-size:.8rem;color:var(--text-muted)}
.search{position:relative}
.search>i{position:absolute;left:.8rem;top:50%;transform:translateY(-50%);font-size:.85rem;color:var(--text-muted);pointer-events:none}
.search input{padding-left:2.25rem}
.actions{display:flex;flex-wrap:wrap;gap:.6rem}
.tools{display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:1rem}
.tool{display:flex;flex-direction:column;gap:.8rem;padding:1.1rem;border:1px solid var(--border);border-radius:var(--radius-lg);background:var(--surface)}
.tool-hd{display:flex;align-items:flex-start;gap:.85rem}
.tool-hd h4{font-size:.98rem;font-weight:600;line-height:1.4;color:var(--secondary)}
.tool-hd p{margin-top:.15rem;font-size:.8rem;line-height:1.5;color:var(--text-muted)}
.tool .body{flex:1;font-size:.86rem;line-height:1.65}
.tool .alert{margin-bottom:0}
.tool .btn{align-self:flex-start}
.ico{width:46px;height:46px;border-radius:12px;flex:0 0 auto;display:flex;align-items:center;justify-content:center;font-size:1.2rem}
.metrics{display:grid;grid-template-columns:1fr;gap:.85rem}
@media(min-width:600px){.metrics{grid-template-columns:repeat(2,1fr)}}
@media(min-width:1000px){.metrics{grid-template-columns:repeat(4,1fr)}}
.metric{display:flex;align-items:center;gap:.85rem;padding:.85rem 1rem;border:1px solid var(--border);border-radius:var(--radius-lg);background:var(--surface)}
.metric .l{font-size:.8rem;line-height:1.35;color:var(--text-muted)}
.metric .v{font-size:1.45rem;font-weight:700;line-height:1.25;color:var(--secondary);font-variant-numeric:tabular-nums}
@media(max-width:640px){
  .mini-form>div{flex:1 1 100%}
  .mini-form>div>input,.mini-form>div>select{width:100%!important}
  .mini-form button{width:100%}
  .tool .btn{align-self:stretch}
}
/* แบ่งหน้าตารางวัสดุ */
.pager{display:flex;flex-wrap:wrap;align-items:center;gap:.35rem;margin:.75rem 0}
.pager .info{margin-right:.4rem;font-size:.8rem;color:var(--text-muted)}
.pager .pg{min-width:36px;height:36px;padding:0 .6rem;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text-main);font-size:.86rem;font-weight:600;text-decoration:none;font-variant-numeric:tabular-nums}
.pager a.pg:hover{border-color:var(--primary-light);color:var(--primary)}
.pager .pg.cur{background:var(--primary);border-color:var(--primary);color:#fff}
.pager .pg.off{opacity:.4}
.pager .pg.gap{min-width:auto;padding:0 .1rem;border:0;background:none;color:var(--text-muted)}
.pager .jump{display:inline-flex;align-items:center;gap:.35rem;margin-left:auto;font-size:.8rem;color:var(--text-muted)}
.pager .jump input{width:5.5rem;padding:.3rem .5rem}
.pager .jump button{padding:.3rem .8rem;font-size:.8rem}
.qty-warn{font-size:.78rem;font-weight:600;color:#b45309;white-space:nowrap}
/* สวิตช์เปิด/ปิด — แบบเดียวกับ .scfg-switch ของแอปหลัก (เขียว = เปิด) · ข้อความ เปิด/ปิด ตามตำแหน่งสวิตช์ */
.sw{position:relative;display:inline-flex;align-items:center;gap:.5rem;flex:none;cursor:pointer;user-select:none;-webkit-tap-highlight-color:transparent}
.sw input{position:absolute;opacity:0;width:1px;height:1px}
.sw-track{position:relative;width:46px;height:26px;border-radius:999px;background:#cbd5e1;transition:background-color .18s}
.sw-track::after{content:"";position:absolute;top:3px;left:3px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:left .18s}
.sw input:checked+.sw-track{background:#16a34a}
.sw input:checked+.sw-track::after{left:23px}
.sw input:focus-visible+.sw-track{outline:2px solid var(--primary-light);outline-offset:2px}
.sw-state{min-width:1.9em;font-size:.82rem;font-weight:700;color:#b91c1c}
.sw-state::after{content:"ปิด"}
.sw input:checked~.sw-state{color:#16a34a}
.sw input:checked~.sw-state::after{content:"เปิด"}
/* การ์ดค่าตั้งแบบสวิตช์: สวิตช์ชิดขวาของหัวการ์ด · สลับแล้วยังไม่กดบันทึก = การ์ดเหลือง (:default = ค่าที่บันทึกไว้) */
.tool-hd>div{min-width:0}
.tool-hd .sw{margin-left:auto;margin-top:.6rem}
.tool-hd h4 label{cursor:pointer}
.tool .unsaved{display:none;margin-left:.35rem;vertical-align:middle}
.tool:has(.sw input:checked:not(:default)),.tool:has(.sw input:default:not(:checked)){border-color:#fcd34d;background:#fffbeb}
.tool:has(.sw input:checked:not(:default)) .unsaved,.tool:has(.sw input:default:not(:checked)) .unsaved{display:inline-block}
.tool .body .pill{margin-bottom:.3rem}
.nw{white-space:nowrap}
@media(max-width:640px){.tool.opt .ico{display:none}}
.actions.foot{margin-top:1rem;align-items:center}
.actions.foot .hint{margin-top:0}
.form-section>.actions.foot{padding-top:.9rem;border-top:1px solid var(--border)}
.hint{margin-top:.3rem;font-size:.76rem;line-height:1.55;color:var(--text-muted)}
.hint .mono{overflow-wrap:anywhere}
/* ฟอร์มหลายกลุ่ม (แจ้งเตือน R&D) — หัวกลุ่ม + ช่องกรอกแบบกริดที่ยืดตามจอ */
.grp+.grp{margin-top:1rem;padding-top:1rem;border-top:1px dashed var(--border)}
.grp-t{display:flex;align-items:center;flex-wrap:wrap;gap:.2rem .5rem;margin-bottom:.65rem;font-size:.9rem;font-weight:600;color:var(--secondary)}
.grp-t>i{width:1.1em;text-align:center;color:var(--primary)}
.grp-t .sub{font-size:.78rem;font-weight:400;color:var(--text-muted)}
.fields{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.75rem 1rem}
.fields.wide{grid-template-columns:repeat(auto-fit,minmax(280px,1fr))}
.fields>div{min-width:0}
.fields input:not([type=checkbox]),.fields select{width:100%}
.chk-s{display:inline-flex;align-items:center;gap:.35rem;margin-top:.35rem;font-size:.8rem;color:var(--text-muted);cursor:pointer}
.sw-row{display:flex;align-items:flex-start;gap:.75rem;align-self:center}
.sw-row .sw{margin-top:.1rem}
.sw-row .sw-txt{font-size:.88rem;line-height:1.5;cursor:pointer}
.sw-row .sw-txt .hint{display:block;margin-top:0}
@media(min-width:700px){.fields .span2{grid-column:span 2}}
</style>

<div class="tabs-container">
  <nav class="tabs-header" aria-label="หมวดการตั้งค่า">
    <?php foreach ($tabs as $k => $v): ?>
      <a href="<?= APP_BASE ?>/admin.php?t=<?= $k ?>" class="tab-btn<?= $k === $t ? ' active' : '' ?>"<?= $k === $t ? ' aria-current="page"' : '' ?>>
        <i class="fa-solid <?= $v[0] ?>" aria-hidden="true"></i> <?= e($v[1]) ?>
      </a>
    <?php endforeach; ?>
    <?php /* เมนูย่อยที่เป็นหน้าแยก (มติ 37) — ลิงก์ตรง ไม่ใช่ ?t= */ ?>
    <a href="<?= uiUrl(APP_BASE . '/po_ocr_test.php') ?>" class="tab-btn">
      <i class="fa-solid fa-microscope" aria-hidden="true"></i> ตั้งค่า OCR
    </a>
    <?php /* Scenario 05 ⑩ — คีย์ใบย้อนหลังจากแบบฟอร์มกระดาษ (ADM) */ ?>
    <a href="<?= uiUrl(APP_BASE . '/bypass.php') ?>" class="tab-btn">
      <i class="fa-solid fa-file-pen" aria-hidden="true"></i> Bypass (คีย์ย้อนหลัง)
    </a>
  </nav>

<div class="tab-content">

<?php if ($notice !== null): ?>
  <?php $nKind = in_array($notice[0], ['ok', 'warn'], true) ? $notice[0] : 'bad'; ?>
  <div class="alert <?= $nKind ?>" role="status" data-autohide>
    <i class="fa-solid <?= ['ok' => 'fa-circle-check', 'warn' => 'fa-triangle-exclamation', 'bad' => 'fa-circle-xmark'][$nKind] ?>" aria-hidden="true"></i>
    <div><?= e($notice[1]) ?></div>
  </div>
<?php endif; ?>

<?php // ══════════════════════════════════════ ประตู ══════════════════
if ($t === 'gates'):
    $gates = $pdo->query(
        'SELECT g.*, p.code AS proj_code, p.name AS proj_name,
                (SELECT COUNT(*) FROM documents d WHERE d.gate_id = g.id) AS n_docs,
                (SELECT COUNT(*) FROM project_materials pm WHERE pm.gate_id = g.id) AS n_mats
         FROM gates g JOIN projects p ON p.id = g.project_id
         ORDER BY p.code, g.gate_code'
    )->fetchAll();
?>
<?php if ($newGateKey !== null): ?>
<section class="adm-sec">
  <div class="alert warn">
    <i class="fa-solid fa-key" aria-hidden="true"></i>
    <div>
      <b>key ใหม่ของตู้ <?= e($newGateKey['gate']) ?> (<?= e($newGateKey['site']) ?>) — แสดงครั้งเดียว คัดลอกเก็บไว้ตอนนี้</b>
      <div style="margin:8px 0"><input type="text" readonly class="mono" style="width:100%;max-width:520px" value="<?= e($newGateKey['key']) ?>" onclick="this.select()"></div>
      ใส่ที่ตู้ในไฟล์ <span class="mono">gate_local.json</span> ข้างโปรแกรมตู้:
      <span class="mono">{"api_key": "…", "site": "<?= e($newGateKey['site']) ?>", "gate": "<?= e($newGateKey['gate']) ?>"}</span> แล้วรีสตาร์ทโปรแกรมตู้ ·
      key เดิมของตู้นี้ใช้ไม่ได้แล้ว · ระบบเก็บแค่ค่า hash — ลืม = สร้างใหม่
    </div>
  </div>
</section>
<?php endif; ?>

<?php
    $secQr  = appSettingOn($pdo, 'qr_require_code');
    $secKey = appSettingOn($pdo, 'gate_shared_key_ok');
    $nNoKey = (int)$pdo->query("SELECT COUNT(*) FROM gates WHERE status = 'active' AND hardware_close = 1 AND api_key_hash IS NULL")->fetchColumn();
?>
<section class="adm-sec">
  <div class="adm-hd">
    <h3><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> ความปลอดภัยตู้ / QR</h3>
    <span class="sub">ผลพิจารณา 2 ต.ค. 2026</span>
  </div>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="action" value="gate_sec_save">
    <div class="tools">
      <div class="tool opt">
        <div class="tool-hd">
          <span class="ico <?= $secQr ? 'icon-green' : 'icon-yellow' ?>"><i class="fa-solid fa-qrcode" aria-hidden="true"></i></span>
          <div>
            <h4><label for="sec-qr">บังคับรหัสตรวจสอบ QR</label><span class="pill p-warn unsaved">ยังไม่บันทึก</span></h4>
            <p>ตู้ไม่รับ QR ที่ไม่มีรหัส หรือรหัสไม่ตรง · <span class="nw">GP-17/21</span></p>
          </div>
          <label class="sw"><input type="checkbox" role="switch" id="sec-qr" name="qr_require_code" value="1" <?= $secQr ? 'checked' : '' ?>
              data-ask-on="<?= e("เปิดบังคับรหัสตรวจสอบ QR?\nตู้ที่ยังไม่อัปเดตโปรแกรมจะสแกน QR ไม่ผ่านทันที — อัปเดตครบทุกตู้แล้วใช่ไหม") ?>"><span class="sw-track" aria-hidden="true"></span><span class="sw-state" aria-hidden="true"></span></label>
        </div>
        <div class="body">
          <?php if ($secQr): ?>
            <span class="pill p-ok"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> ตู้ตรวจรหัสทุก QR</span>
            <div class="hint">ตู้ที่ยังไม่อัปเดตโปรแกรม (ไม่ส่งรหัสมาให้ตรวจ) จะสแกน QR ไม่ผ่าน</div>
          <?php else: ?>
            <span class="pill p-warn"><i class="fa-solid fa-hourglass-half" aria-hidden="true"></i> ยังไม่บังคับ — รออัปเดตโปรแกรมตู้</span>
            <div class="hint">QR จากหน้าเว็บมีรหัสแล้ว แต่ตู้รุ่นเก่ายังไม่ส่งรหัสมาให้ตรวจ — เปิดเมื่ออัปเดตโปรแกรมครบทุกตู้</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="tool opt">
        <div class="tool-hd">
          <span class="ico <?= $nNoKey === 0 ? 'icon-green' : ($secKey ? 'icon-yellow' : 'icon-red') ?>"><i class="fa-solid fa-key" aria-hidden="true"></i></span>
          <div>
            <h4><label for="sec-key">ยังรับ key กลาง</label><span class="pill p-warn unsaved">ยังไม่บันทึก</span></h4>
            <p>ตู้ที่ยังไม่มี key ของตัวเองต่อเว็บด้วย key กลาง — ต้องส่งรหัส G มากับทุกคำสั่ง · <span class="nw">GP-22/23</span></p>
          </div>
          <label class="sw"><input type="checkbox" role="switch" id="sec-key" name="gate_shared_key_ok" value="1" <?= $secKey ? 'checked' : '' ?>
              <?= $nNoKey > 0 ? 'data-ask-off="' . e("ปิดรับ key กลาง?\nตู้ " . $nNoKey . " ตู้ที่ยังไม่มี key ของตัวเองจะต่อเว็บไม่ได้ทันที") . '"' : '' ?>><span class="sw-track" aria-hidden="true"></span><span class="sw-state" aria-hidden="true"></span></label>
        </div>
        <div class="body">
          <?php if ($nNoKey > 0): ?>
            <span class="pill <?= $secKey ? 'p-warn' : 'p-bad' ?>"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> ตู้ที่ยังไม่มี key ของตัวเอง <?= $nNoKey ?> ตู้</span>
            <div class="hint"><?= $secKey ? 'ปิดได้เมื่อทุกตู้มี key ของตัวเองแล้ว' : 'ตู้เหล่านี้ต่อเว็บไม่ได้ — สร้าง key ให้ หรือเปิดรับ key กลางอีกครั้ง' ?>
              · สร้าง key ได้ที่ตาราง <a href="#gate-list">ประตูทั้งหมด</a> ด้านล่าง</div>
          <?php else: ?>
            <span class="pill p-ok"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> ทุกตู้มี key ของตัวเองแล้ว</span>
            <div class="hint"><?= $secKey ? 'ปิดรับ key กลางได้แล้ว' : 'ไม่มีตู้ใดใช้ key กลาง' ?></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="actions foot"><button type="submit"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> บันทึก</button></div>
  </form>
</section>

<?php
    $nf = function ($k) use ($pdo) { return appSetting($pdo, $k); };
    $jobsLog = __DIR__ . '/settings/jobs.log';
    $jobsLast = is_file($jobsLog) ? trim((string)(array_slice(file($jobsLog, FILE_IGNORE_NEW_LINES) ?: [], -1)[0] ?? '')) : '';
?>
<section class="adm-sec">
  <div class="adm-hd">
    <h3><i class="fa-solid fa-bell" aria-hidden="true"></i> แจ้งเตือน R&amp;D + งานตั้งเวลา</h3>
    <span class="sub">ผลพิจารณา 2 ต.ค. 2026</span>
  </div>
  <form method="post" class="form-section">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="action" value="notify_save">

    <?php if (!CNX_NOTIFY_EXTERNAL): ?>
    <div class="grp">
      <div class="grp-t"><i class="fa-solid fa-bell" aria-hidden="true"></i> แจ้งเตือนในแอป
        <span class="sub">ตู้เงียบเกิน 5 นาที · กล้อง/ตัวตรวจจับ/หัวอ่านเสีย · แก้ค่าตั้งประตู/หมวดวัสดุ · key ตู้ — แจ้งที่แถบแจ้งเตือนของผู้ใช้ระดับ 0 (Teams / อีเมลยังปิดอยู่)</span></div>
    </div>
    <?php else: ?>
    <div class="grp">
      <div class="grp-t"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> ช่องทางแจ้งเตือนนอกแอป
        <span class="sub">ตู้เงียบเกิน 5 นาที · กล้อง/ตัวตรวจจับ/หัวอ่านเสีย · แก้ค่าตั้งประตู/หมวดวัสดุ · key ตู้ — แจ้งในแอป (ผู้ใช้ระดับ 0) แล้วส่งต่อทางนี้</span></div>
      <div class="fields wide">
        <div><label class="fld" for="nf-teams">Teams webhook URL</label>
          <input type="url" id="nf-teams" name="notify_teams_url" value="<?= e($nf('notify_teams_url')) ?>" placeholder="https://…">
          <div class="hint">Workflows “เมื่อได้รับคำขอ webhook” หรือ Incoming Webhook · ว่าง = ไม่ส่งทาง Teams</div></div>
        <div><label class="fld" for="nf-mail">อีเมลผู้รับ</label>
          <input type="text" id="nf-mail" name="notify_email_to" value="<?= e($nf('notify_email_to')) ?>" placeholder="rd@…">
          <div class="hint">หลายคนคั่นด้วย , · ว่าง = ไม่ส่งอีเมล · ส่งผ่าน SMTP ด้านล่าง</div></div>
      </div>
    </div>

    <div class="grp">
      <div class="grp-t"><i class="fa-solid fa-server" aria-hidden="true"></i> เซิร์ฟเวอร์อีเมล (SMTP)</div>
      <div class="fields">
        <div><label class="fld" for="nf-host">SMTP host</label>
          <input type="text" id="nf-host" name="smtp_host" value="<?= e($nf('smtp_host')) ?>" placeholder="smtp.office365.com"></div>
        <div><label class="fld" for="nf-port">พอร์ต</label>
          <input type="number" id="nf-port" name="smtp_port" value="<?= e($nf('smtp_port')) ?>"></div>
        <div><label class="fld" for="nf-secure">เข้ารหัส</label>
          <select id="nf-secure" name="smtp_secure">
            <?php foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL (465)', 'none' => 'ไม่เข้ารหัส'] as $v => $l): ?>
              <option value="<?= $v ?>" <?= $nf('smtp_secure') === $v ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select></div>
        <div><label class="fld" for="nf-user">ผู้ใช้ SMTP</label>
          <input type="text" id="nf-user" name="smtp_user" value="<?= e($nf('smtp_user')) ?>" autocomplete="off"></div>
        <div><label class="fld" for="nf-pass">รหัสผ่าน SMTP<?= $nf('smtp_pass') !== '' ? ' <span class="pill p-ok">ตั้งไว้แล้ว</span>' : '' ?></label>
          <input type="password" id="nf-pass" name="smtp_pass" value="" autocomplete="new-password" placeholder="เว้นว่าง = ใช้ค่าเดิม">
          <label class="chk-s"><input type="checkbox" name="smtp_pass_clear" value="1"> ล้างรหัสผ่าน</label></div>
        <div><label class="fld" for="nf-from">ผู้ส่ง (From)</label>
          <input type="email" id="nf-from" name="smtp_from" value="<?= e($nf('smtp_from')) ?>" placeholder="ว่าง = ผู้ใช้ SMTP"></div>
      </div>
    </div>
    <?php endif; ?>

    <div class="grp">
      <div class="grp-t"><i class="fa-solid fa-clock" aria-hidden="true"></i> งานตั้งเวลา</div>
      <div class="fields">
        <div><label class="fld" for="nf-rec">กระทบยอดอัตโนมัติทุก (นาที)</label>
          <input type="number" id="nf-rec" name="reconcile_every_min" min="0" max="60" value="<?= e($nf('reconcile_every_min')) ?>">
          <div class="hint">0 = ปิด · สูงสุด 60</div></div>
        <div class="sw-row span2">
          <label class="sw"><input type="checkbox" role="switch" id="nf-sc" name="sc_weekly_random" value="1" <?= isTrueFlag($nf('sc_weekly_random')) ? 'checked' : '' ?>><span class="sw-track" aria-hidden="true"></span><span class="sw-state" aria-hidden="true"></span></label>
          <label class="sw-txt" for="nf-sc"><b>สุ่มนับสต๊อกรายสัปดาห์ต่อ G</b>
            <span class="hint">แจ้งสายสโตร์ในวันที่สุ่มได้ · <span class="nw">GP-03</span></span></label>
        </div>
      </div>
      <div class="hint">ตัวตั้งเวลา: ตั้ง Windows Task Scheduler ให้รัน <span class="mono">C:\xampp\php\php.exe C:\xampp\htdocs\connext\cron\jobs.php</span> ทุก 1 นาที
        (ระหว่างยังไม่ตั้ง งานรันตอนตู้ส่งสัญญาณชีพ/เปิด Dashboard) · รอบล่าสุด: <span class="mono"><?= e($jobsLast !== '' ? mb_substr($jobsLast, 0, 160) : 'ยังไม่เคยรันจากตัวตั้งเวลา') ?></span></div>
    </div>

    <div class="actions foot">
      <button type="submit"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> บันทึก</button>
      <?php if (CNX_NOTIFY_EXTERNAL): ?>
      <button type="submit" form="notify-test" class="ghost"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> ส่งข้อความทดสอบ</button>
      <span class="hint">ทดสอบใช้ค่าที่บันทึกไว้แล้ว — แก้แล้วกดบันทึกก่อน</span>
      <?php endif; ?>
    </div>
  </form>
  <form method="post" id="notify-test">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="action" value="notify_test">
  </form>
</section>

<section class="adm-sec" id="gate-list">
  <div class="adm-hd">
    <h3><i class="fa-solid fa-door-open" aria-hidden="true"></i> ประตูทั้งหมด</h3>
    <span class="pill p-muted"><?= count($gates) ?> ประตู</span>
  </div>

  <div class="alert warn" data-fold="gate-types">
    <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
    <div>
      <b>ประเภทประตู (ผลพิจารณา 2 ต.ค. 2026 · GP-46) — แก้ได้เฉพาะผู้ดูแลระบบ · ทุกการแก้จดบันทึก + แจ้งเตือนผู้ดูแลระบบ</b>
      <ul>
        <li><b>มี CCTV</b> = ตู้ตรวจกล้องตอนเปิดเครื่อง + ALARM 4 (ตรวจจับคนในพื้นที่หลังปิดประตู)</li>
        <li><b>ไม่มี CCTV</b> = ตู้ข้ามการตรวจกล้อง · ไม่มี ALARM 4 (รอยืนยัน) — ตู้อ่านค่านี้ตอนเปิดเครื่อง (ต้องอัปเดตโปรแกรมตู้ก่อน)</li>
        <li>ทุก G มีตู้ + กลอน (รอยืนยัน): ช่องติ๊ก "มีตัวล็อกจริง" เอาออกแล้ว — ประตูใหม่ = มีตู้เสมอ (ตัดสต๊อกเมื่อตู้ปิดประตู) ·
          ประตูเดิมที่ยังเป็น <b>ยังไม่มีตู้</b> (ปิดงานตอนถ่ายรูปยืนยัน) มีปุ่ม "เปลี่ยนเป็นมีตู้ + กลอน" — กดเมื่อติดตั้งตู้แล้วเท่านั้น
          (ไม่มีตู้จริงแล้วเปลี่ยน = เอกสารค้างรอปิดประตู)</li>
        <li><b>key ตู้</b> (GP-22/23): สร้าง key ของแต่ละตู้แล้วใส่ที่ตู้ — เว็บรู้ไซต์และ G จาก key เอง ·
          ตู้ที่ยังใช้ key กลางต้องส่งรหัส G ทุกคำสั่ง</li>
        <li>รหัสต้องเป็น <span class="mono">G</span> + ตัวเลข เพราะระบบถอดรหัสประตูจากท้ายเลขเอกสาร
          (<span class="mono">IN270826<b>01</b>G01</span>)</li>
      </ul>
    </div>
  </div>

  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>ประตู</th><th>ชื่อ</th><th>โครงการ</th><th>ประเภท</th><th>key ตู้</th><th>สถานะ</th>
          <th class="num">เอกสาร</th><th class="num">วัสดุผูกไว้</th><th></th></tr></thead>
      <tbody>
      <?php if (empty($gates)): ?>
        <tr><td colspan="9" class="empty">ยังไม่มีประตู — เพิ่มได้จากฟอร์มด้านล่าง</td></tr>
      <?php endif; ?>
      <?php foreach ($gates as $g): ?>
        <tr class="<?= $g['status'] === 'inactive' ? 'dim' : '' ?>">
          <td class="mono"><b><?= e((string)$g['gate_code']) ?></b></td>
          <td><?= e((string)$g['name']) ?></td>
          <td class="small"><?= e((string)$g['proj_code']) ?> · <?= e((string)$g['proj_name']) ?></td>
          <td>
            <?php if (isTrueFlag($g['has_cctv'] ?? 1)): ?>
              <span class="pill p-ok"><i class="fa-solid fa-video" aria-hidden="true"></i> มี CCTV</span>
            <?php else: ?>
              <span class="pill p-warn"><i class="fa-solid fa-video-slash" aria-hidden="true"></i> ไม่มี CCTV</span>
            <?php endif; ?>
            <?php if (!isTrueFlag($g['hardware_close'])): ?>
              <div style="margin-top:4px">
                <span class="pill p-muted"><i class="fa-solid fa-qrcode" aria-hidden="true"></i> ยังไม่มีตู้ — ปิดงานตอนถ่ายรูป</span>
                <form method="post" style="display:inline" onsubmit="return confirm('ติดตั้งตู้ + กลอนที่ประตู <?= e((string)$g['gate_code']) ?> แล้วใช่ไหม?\nหลังเปลี่ยน ใบของประตูนี้ต้องปิดประตูที่ตู้จึงจะปิดงาน/ตัดสต๊อก')">
                  <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                  <input type="hidden" name="action" value="gate_hw_on">
                  <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                  <button class="ghost mini" type="submit"><i class="fa-solid fa-lock" aria-hidden="true"></i> เปลี่ยนเป็นมีตู้ + กลอน</button>
                </form>
              </div>
            <?php endif; ?>
          </td>
          <td class="small">
            <?php if (!empty($g['api_key_hash'])): ?>
              <span class="pill p-ok"><i class="fa-solid fa-key" aria-hidden="true"></i> key ของตู้ …<?= e((string)$g['api_key_hint']) ?></span>
              <div class="small"><?= e(substr((string)$g['api_key_at'], 0, 16)) ?> · <?= e((string)$g['api_key_by']) ?></div>
            <?php else: ?>
              <span class="pill p-muted">ใช้ key กลาง</span>
            <?php endif; ?>
            <form method="post" style="display:inline" onsubmit="return confirm('สร้าง key ใหม่ให้ตู้ <?= e((string)$g['gate_code']) ?>?\nkey เดิมของตู้นี้ (ถ้ามี) จะใช้ไม่ได้ทันที — ต้องใส่ key ใหม่ที่ตู้')">
              <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="action" value="gate_key_new">
              <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
              <button class="ghost mini" type="submit"><i class="fa-solid fa-key" aria-hidden="true"></i> สร้าง key ใหม่</button>
            </form>
            <?php if (!empty($g['api_key_hash'])): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('ยกเลิก key ของตู้ <?= e((string)$g['gate_code']) ?>? ตู้จะต้องใช้ key กลางแทน')">
                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action" value="gate_key_revoke">
                <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                <button class="ghost mini" type="submit">ยกเลิก key</button>
              </form>
            <?php endif; ?>
          </td>
          <td><span class="pill <?= $g['status'] === 'active' ? 'p-ok' : 'p-muted' ?>">
            <?= $g['status'] === 'active' ? 'ใช้งาน' : 'ปิดใช้งาน' ?></span></td>
          <td class="num"><?= (int)$g['n_docs'] ?></td>
          <td class="num"><?= (int)$g['n_mats'] ?></td>
          <td class="act"><button class="ghost mini" type="button" data-edit="g<?= (int)$g['id'] ?>" aria-expanded="false"><i class="fa-solid fa-pen" aria-hidden="true"></i> แก้ไข</button></td>
        </tr>
        <tr class="edit-row" id="g<?= (int)$g['id'] ?>">
          <td colspan="9">
            <form method="post" class="mini-form">
              <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="action" value="gate_save">
              <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
              <div><label class="fld">รหัส</label>
                <input type="text" name="gate_code" value="<?= e((string)$g['gate_code']) ?>" style="width:100px" class="mono"></div>
              <div class="grow"><label class="fld">ชื่อ</label>
                <input type="text" name="name" value="<?= e((string)$g['name']) ?>"></div>
              <div><label class="fld">โครงการ</label>
                <select name="project_id">
                  <?php foreach ($projects as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === (int)$g['project_id'] ? 'selected' : '' ?>>
                      <?= e((string)$p['code']) ?> · <?= e((string)$p['name']) ?></option>
                  <?php endforeach; ?>
                </select></div>
              <div><label class="fld">สถานะ</label>
                <select name="status">
                  <option value="active"   <?= $g['status'] === 'active'   ? 'selected' : '' ?>>ใช้งาน</option>
                  <option value="inactive" <?= $g['status'] === 'inactive' ? 'selected' : '' ?>>ปิดใช้งาน</option>
                </select></div>
              <div><label class="fld">ประเภทประตู</label>
                <select name="gate_type">
                  <option value="cctv"   <?= isTrueFlag($g['has_cctv'] ?? 1) ? 'selected' : '' ?>>มี CCTV</option>
                  <option value="nocctv" <?= isTrueFlag($g['has_cctv'] ?? 1) ? '' : 'selected' ?>>ไม่มี CCTV</option>
                </select></div>
              <div><button type="submit"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> บันทึก</button></div>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="adm-sec">
  <div class="adm-hd"><h3><i class="fa-solid fa-plus" aria-hidden="true"></i> เพิ่มประตูใหม่</h3></div>
  <form method="post" class="mini-form form-section">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="action" value="gate_save">
    <input type="hidden" name="id" value="0">
    <div><label class="fld">รหัส *</label>
      <input type="text" name="gate_code" placeholder="G03" style="width:100px" class="mono" required></div>
    <div class="grow"><label class="fld">ชื่อ *</label>
      <input type="text" name="name" placeholder="เช่น ประตูหลังไซต์" required></div>
    <div><label class="fld">โครงการ *</label>
      <select name="project_id" required>
        <?php foreach ($projects as $p): if ($p['status'] !== 'active') continue; ?>
          <option value="<?= (int)$p['id'] ?>"><?= e((string)$p['code']) ?> · <?= e((string)$p['name']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div><label class="fld">ประเภทประตู</label>
      <select name="gate_type"><option value="cctv" selected>มี CCTV</option><option value="nocctv">ไม่มี CCTV</option></select></div>
    <div><button type="submit"><i class="fa-solid fa-plus" aria-hidden="true"></i> เพิ่มประตู</button></div>
  </form>
  <p class="note">
    ใบ IN ที่ออกจากสาย <b>PO → buffer</b> ไม่ผูกประตูเลย (มติ 23) — QR ใบนั้นสแกนได้ทุกประตูอยู่แล้ว
    ประตูในหน้านี้ใช้กับใบเบิก RD/OD/BD และการรับเข้าคลังแบบเดิม
  </p>
</section>

<?php
    // ── เวลาหยิบของต่อรอบ (Scenario 05 ⑧) — ค่าต่อไซต์ ตู้ดึงพร้อมรายชื่อบัตร (getCardList → timing) ──
    // เฉพาะไซต์ที่มีประตูใช้งาน (ค่านี้มีผลกับตู้เท่านั้น) — ไซต์ที่ตั้งค่าไว้แล้วแต่ปิดประตูหมดยังแสดงให้แก้ได้
    s05EnsureStoreOverCapCol($pdo);   // [2026-10-08] สวิตช์ "สายสโตร์ขอเกินเพดานได้"
    $timingRows = $pdo->query(
        "SELECT p.id, p.code, p.name, gs.pick_min_per_item, gs.pick_cap_min, gs.extend_min, gs.store_over_cap, gs.updated_by, gs.updated_at,
                (SELECT COUNT(*) FROM gates g WHERE g.project_id = p.id AND g.status = 'active') AS n_gates
           FROM projects p LEFT JOIN gate_settings gs ON gs.project_id = p.id
          WHERE p.status = 'active'
            AND (gs.project_id IS NOT NULL OR EXISTS (SELECT 1 FROM gates g WHERE g.project_id = p.id AND g.status = 'active'))
          ORDER BY p.code"
    )->fetchAll();
?>
<section class="adm-sec">
  <div class="adm-hd">
    <h3><i class="fa-solid fa-stopwatch" aria-hidden="true"></i> เวลาหยิบของต่อรอบ</h3>
    <span class="pill p-muted">ตู้ดึงค่านี้ตอนเปิดเครื่อง</span>
  </div>
  <p class="note">
    เวลาหยิบของของรอบ = <b>นาทีต่อรายการ × จำนวนรหัส IC ที่ไม่ซ้ำในรอบ</b> ไม่เกิน <b>เพดาน</b> ·
    กด “ขอเวลาเพิ่ม” + แตะบัตรได้ครั้งละ <b>นาทีต่อการเลื่อน</b> (เท่าปุ่มเลื่อนของ ALARM 3)
    — กดได้เฉพาะ <b>1 นาทีสุดท้าย</b> หรือหลังหมดเวลาแล้ว (ตู้รุ่น 8 ต.ค. 2026 ขึ้นไป) ·
    เวลารวม (ทั้งหยิบของ และเลื่อนเวลาปิดประตูของ ALARM 3) <b>ไม่เกินเพดาน</b> —
    ติ๊ก <b>“สายสโตร์เกินเพดานได้”</b> = บัตรสายสโตร์ขอต่อเกินเพดานได้ (ทุกครั้งขึ้นเตือนบน Dashboard) · ไม่ติ๊ก = เพดานตายตัวทุกบัตร ·
    ค่าตั้งต้นตามเอกสาร 05: <span class="mono">3</span> นาที/รายการ · เพดาน <span class="mono">120</span> นาที · เลื่อนครั้งละ <span class="mono">5</span> นาที
  </p>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>โครงการ</th><th class="num">ประตู</th><th>ค่าที่ใช้กับตู้ของไซต์</th><th>แก้ล่าสุด</th></tr></thead>
      <tbody>
      <?php if (empty($timingRows)): ?>
        <tr><td colspan="4" class="empty">ยังไม่มีไซต์ที่มีประตู</td></tr>
      <?php endif; ?>
      <?php foreach ($timingRows as $tr):
            $isSet = $tr['pick_min_per_item'] !== null; ?>
        <tr class="<?= (int)$tr['n_gates'] === 0 ? 'dim' : '' ?>">
          <td class="small"><b class="mono"><?= e((string)$tr['code']) ?></b> · <?= e((string)$tr['name']) ?></td>
          <td class="num"><?= (int)$tr['n_gates'] ?></td>
          <td>
            <form method="post" class="row" style="margin:0;gap:.4rem .5rem;flex-wrap:wrap">
              <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="action" value="gate_timing_save">
              <input type="hidden" name="project_id" value="<?= (int)$tr['id'] ?>">
              <input type="number" name="pick_min_per_item" step="0.5" min="0.5" max="30" style="width:74px"
                     value="<?= e($isSet ? s05Num($tr['pick_min_per_item']) : s05Num(S05_DEFAULT_PICK_MIN_PER_ITEM)) ?>"
                     aria-label="นาทีต่อรายการ <?= e((string)$tr['code']) ?>"><span class="small">นาที/รายการ ·</span>
              <span class="small">เพดาน</span>
              <input type="number" name="pick_cap_min" step="1" min="5" max="600" style="width:80px"
                     value="<?= e($isSet ? (string)(int)$tr['pick_cap_min'] : (string)S05_DEFAULT_PICK_CAP_MIN) ?>"
                     aria-label="เพดานนาที <?= e((string)$tr['code']) ?>"><span class="small">นาที ·</span>
              <span class="small">เลื่อนครั้งละ</span>
              <input type="number" name="extend_min" step="1" min="1" max="60" style="width:66px"
                     value="<?= e($isSet ? (string)(int)$tr['extend_min'] : (string)S05_DEFAULT_EXTEND_MIN) ?>"
                     aria-label="นาทีต่อการเลื่อน <?= e((string)$tr['code']) ?>"><span class="small">นาที ·</span>
              <label class="small" style="display:inline-flex;align-items:center;gap:.3rem;cursor:pointer"
                     title="ติ๊ก = บัตรสายสโตร์ขอเวลาเกินเพดานได้ (ขึ้นเตือน OVER CAP) · ไม่ติ๊ก = ครบเพดานแล้วไม่มีใครขอเพิ่มได้">
                <input type="hidden" name="store_over_cap" value="0">
                <input type="checkbox" name="store_over_cap" value="1"<?= ($isSet && (int)($tr['store_over_cap'] ?? 0) === 1) || (!$isSet && S05_DEFAULT_STORE_OVER_CAP) ? ' checked' : '' ?>
                       aria-label="สายสโตร์เกินเพดานได้ <?= e((string)$tr['code']) ?>">สายสโตร์เกินเพดานได้</label>
              <button type="submit" class="mini"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> บันทึก</button>
            </form>
          </td>
          <td class="small"><?= $isSet ? e((string)$tr['updated_by']) . '<br>' . e(substr((string)$tr['updated_at'], 0, 16)) : 'ค่าตั้งต้น' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php // ══════════════════════════════════ โครงการ ═════════════════════
elseif ($t === 'projects'):
    $rows = $pdo->query(
        'SELECT p.*,
                (SELECT COUNT(*) FROM gates g WHERE g.project_id = p.id) AS n_gates,
                (SELECT COUNT(*) FROM documents d WHERE d.project_id = p.id) AS n_docs,
                (SELECT COUNT(*) FROM project_materials pm WHERE pm.project_id = p.id) AS n_mats
         FROM projects p ORDER BY p.status, p.code'
    )->fetchAll();
?>
<section class="adm-sec">
  <div class="adm-hd">
    <h3><i class="fa-solid fa-building" aria-hidden="true"></i> โครงการ / ไซต์</h3>
    <span class="pill p-muted"><?= count($rows) ?> โครงการ</span>
  </div>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>รหัส</th><th>ชื่อ</th><th>site_ref</th><th>สถานะ</th>
          <th class="num">ประตู</th><th class="num">เอกสาร</th><th class="num">วัสดุ</th><th></th></tr></thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="8" class="empty">ยังไม่มีโครงการ — เพิ่มได้จากฟอร์มด้านล่าง</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $p): ?>
        <tr class="<?= $p['status'] === 'archived' ? 'dim' : '' ?>">
          <td class="mono"><b><?= e((string)$p['code']) ?></b></td>
          <td><?= e((string)$p['name']) ?></td>
          <td class="small mono"><?= e((string)($p['site_ref'] ?? '—')) ?></td>
          <td><span class="pill <?= $p['status'] === 'active' ? 'p-ok' : 'p-muted' ?>">
            <?= $p['status'] === 'active' ? 'ใช้งาน' : 'เก็บเข้ากรุ' ?></span></td>
          <td class="num"><?= (int)$p['n_gates'] ?></td>
          <td class="num"><?= (int)$p['n_docs'] ?></td>
          <td class="num"><?= (int)$p['n_mats'] ?></td>
          <td class="act"><button class="ghost mini" type="button" data-edit="p<?= (int)$p['id'] ?>" aria-expanded="false"><i class="fa-solid fa-pen" aria-hidden="true"></i> แก้ไข</button></td>
        </tr>
        <tr class="edit-row" id="p<?= (int)$p['id'] ?>">
          <td colspan="8">
            <form method="post" class="mini-form">
              <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="action" value="proj_save">
              <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <div><label class="fld">รหัส</label>
                <input type="text" name="code" value="<?= e((string)$p['code']) ?>" style="width:110px" class="mono"></div>
              <div class="grow"><label class="fld">ชื่อ</label>
                <input type="text" name="name" value="<?= e((string)$p['name']) ?>"></div>
              <div><label class="fld">site_ref</label>
                <input type="text" name="site_ref" value="<?= e((string)($p['site_ref'] ?? '')) ?>" style="width:140px"></div>
              <div><label class="fld">สถานะ</label>
                <select name="status">
                  <option value="active"   <?= $p['status'] === 'active'   ? 'selected' : '' ?>>ใช้งาน</option>
                  <option value="archived" <?= $p['status'] === 'archived' ? 'selected' : '' ?>>เก็บเข้ากรุ</option>
                </select></div>
              <div><button type="submit"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> บันทึก</button></div>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="note">
    เปลี่ยน<b>รหัส</b>โครงการมีผลกับการจับคู่ SiteCode ที่ Pi ส่งเข้ามาและเลขที่ PO ที่ถอดไซต์จากรหัส —
    แก้แล้วต้องไปแก้ฝั่ง Pi ด้วย · โครงการที่ “เก็บเข้ากรุ” จะหายจาก dropdown เลือกไซต์ แต่ประวัติยังอยู่ครบ
  </p>
</section>

<section class="adm-sec">
  <div class="adm-hd"><h3><i class="fa-solid fa-plus" aria-hidden="true"></i> เพิ่มโครงการใหม่</h3></div>
  <form method="post" class="mini-form form-section">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="action" value="proj_save">
    <input type="hidden" name="id" value="0">
    <div><label class="fld">รหัส *</label><input type="text" name="code" style="width:110px" class="mono" required></div>
    <div class="grow"><label class="fld">ชื่อ *</label><input type="text" name="name" required></div>
    <div><label class="fld">site_ref</label><input type="text" name="site_ref" style="width:140px"></div>
    <div><button type="submit"><i class="fa-solid fa-plus" aria-hidden="true"></i> เพิ่มโครงการ</button></div>
  </form>
</section>

<?php // ═══════════════════════════════════ วัสดุ ══════════════════════
elseif ($t === 'materials'):
    $mq     = trim((string)($_GET['q'] ?? ''));
    $mtype  = (string)($_GET['type'] ?? '');
    $mproj  = (int)($_GET['proj'] ?? ($user['projectId'] ?? 0));
    $mscope = (string)($_GET['scope'] ?? '');
    $mpage  = max(1, (int)($_GET['pg'] ?? 1));
    $mper   = 100;
    $scopes = [
        ''        => '— ทุกวัสดุในทะเบียน —',
        'site'    => 'อยู่ในไซต์นี้',
        'stock'   => 'มีของคงเหลือ',
        'nogate'  => 'อยู่ในไซต์แต่ยังไม่ผูกประตู',
        'unalloc' => 'มียอดที่ยังไม่อยู่ประตูใด',
    ];
    if (!isset($scopes[$mscope])) { $mscope = ''; }

    $w = ['1=1']; $ar = [];
    if ($mq !== '') {
        $like = '%' . likeEscape($mq) . '%';
        $w[]  = '(m.mat_code LIKE ? OR m.name LIKE ?)';
        array_push($ar, $like, $like);
    }
    if ($mtype === 'ic' || $mtype === 'mango') { $w[] = 'm.code_type = ?'; $ar[] = $mtype; }
    if ($mscope === 'site')    { $w[] = '(pm.id IS NOT NULL OR b.id IS NOT NULL)'; }
    if ($mscope === 'stock')   { $w[] = 'b.on_hand > 0'; }
    if ($mscope === 'nogate')  { $w[] = '(pm.id IS NOT NULL OR b.id IS NOT NULL) AND pm.gate_id IS NULL'; }
    if ($mscope === 'unalloc') { $w[] = 'b.on_hand <> COALESCE(gs.s, 0)'; }

    $from = 'FROM materials m
            LEFT JOIN project_materials pm ON pm.material_id = m.id AND pm.project_id = ' . $mproj . '
            LEFT JOIN gates g   ON g.id = pm.gate_id
            LEFT JOIN stock_balances b ON b.material_id = m.id AND b.project_id = ' . $mproj . '
            LEFT JOIN (SELECT material_id, SUM(on_hand) AS s FROM stock_gate_balances
                        WHERE project_id = ' . $mproj . ' GROUP BY material_id) gs ON gs.material_id = m.id
            WHERE ' . implode(' AND ', $w);
    $st = $pdo->prepare('SELECT COUNT(*) ' . $from);
    $st->execute($ar);
    $mTotal = (int)$st->fetchColumn();
    $mPages = max(1, (int)ceil($mTotal / $mper));
    if ($mpage > $mPages) { $mpage = $mPages; }

    // รหัส IC ขึ้นก่อน — ระบบเบิกด้วย IC เท่านั้น (มติ 34) ประตูตั้งต้นมีผลกับ IC
    $st = $pdo->prepare('SELECT m.*, pm.gate_id, g.gate_code, b.on_hand, COALESCE(gs.s, 0) AS gate_sum ' . $from . '
            ORDER BY (m.code_type = \'ic\') DESC, m.mat_code LIMIT ' . $mper . ' OFFSET ' . (($mpage - 1) * $mper));
    $st->execute($ar);
    $mats = $st->fetchAll();

    // ของอยู่ประตูไหนเท่าไหร่ (เฉพาะแถวในหน้านี้)
    $gateQty    = [];
    $gateOnHand = [];   // material_id → gate_id → on_hand (ฟอร์มย้ายของข้ามประตู · มติ 51)
    $ids = array_map('intval', array_column($mats, 'id'));
    if ($ids) {
        $gq = $pdo->prepare('SELECT x.material_id, x.gate_id, gg.gate_code, x.on_hand FROM stock_gate_balances x
                               JOIN gates gg ON gg.id = x.gate_id
                              WHERE x.project_id = ? AND x.on_hand <> 0
                                AND x.material_id IN (' . implode(',', $ids) . ')
                              ORDER BY gg.gate_code');
        $gq->execute([$mproj]);
        foreach ($gq->fetchAll() as $r) {
            $gateQty[(int)$r['material_id']][] = $r['gate_code'] . ' ' . fmtQ($r['on_hand']);
            $gateOnHand[(int)$r['material_id']][(int)$r['gate_id']] = (float)$r['on_hand'];
        }
    }

    /** ลิงก์หน้าอื่นของตาราง — คงเงื่อนไขค้นเดิมไว้ */
    $mUrl = function (int $pg) use ($mq, $mtype, $mproj, $mscope): string {
        $qs = array_filter(['t' => 'materials', 'q' => $mq, 'type' => $mtype, 'proj' => $mproj, 'scope' => $mscope],
                           function ($v) { return $v !== ''; });
        if ($pg > 1) { $qs['pg'] = $pg; }
        return '?' . http_build_query($qs);
    };

    // ยอดคงเหลือที่ยังไม่อยู่ประตูใด — ทุกไซต์ (ฟอร์มเบิกเลือกได้เฉพาะประตูที่มีของ)
    $unalloc = stockUnallocatedList($pdo);
    $uaSites = [];
    foreach ($unalloc as $r) {
        $k = (int)$r['project_id'];
        if (!isset($uaSites[$k])) {
            $uaSites[$k] = ['code' => $r['project_code'], 'items' => 0, 'qty' => 0.0, 'gates' => [], 'odd' => 0, 'nogate' => 0];
        }
        if ($r['unalloc'] < 0) { $uaSites[$k]['odd']++; continue; }
        if ($r['target_gate_id'] === null) { $uaSites[$k]['nogate']++; continue; }
        $uaSites[$k]['items']++;
        $uaSites[$k]['qty'] += $r['unalloc'];
        $gc = $r['target_gate_code'] . ($r['target_is_default'] ? '' : ' (ประตูแรก)');
        $uaSites[$k]['gates'][$gc] = ($uaSites[$k]['gates'][$gc] ?? 0) + 1;
    }

    $gatesOfProj = $pdo->prepare("SELECT id, gate_code, name FROM gates WHERE project_id = ? AND status='active' ORDER BY gate_code");
    $gatesOfProj->execute([$mproj]);
    $gp = $gatesOfProj->fetchAll();
    // ทะเบียน Mango — งานเพิ่ม/นำเข้าเป็นชุดอยู่ที่หน้าเฉพาะของมัน (มติ 35)
    $nMango  = (int)$pdo->query("SELECT COUNT(*) FROM materials WHERE code_type='mango'")->fetchColumn();
    $nOrphan = (int)$pdo->query(
        "SELECT COUNT(DISTINCT pl.mat_code) FROM po_lines pl
          LEFT JOIN materials m ON m.mat_code = pl.mat_code
          WHERE pl.mat_code IS NOT NULL AND pl.mat_code <> '' AND m.id IS NULL"
    )->fetchColumn();
    // รหัส Mango ที่ยังถือยอด/อยู่ในโครงการ — ผิดกติกามติ 34 ต้องไปผูก IC แล้วย้ายยอดที่ setup_master.php (มติ 40)
    $nMangoHold = (int)$pdo->query(
        "SELECT COUNT(*) FROM materials m WHERE m.code_type = 'mango'
            AND (EXISTS (SELECT 1 FROM stock_balances b WHERE b.material_id = m.id)
              OR EXISTS (SELECT 1 FROM project_materials p WHERE p.material_id = m.id))"
    )->fetchColumn();
?>
<section class="adm-sec">
  <div class="adm-hd">
    <h3><i class="fa-solid fa-screwdriver-wrench" aria-hidden="true"></i> ทะเบียน / ผูกรหัสวัสดุ</h3>
    <span class="sub">ระบบแสดง/เบิกเป็นรหัส IC เท่านั้น (มติ 34/40)</span>
  </div>
  <div class="tools">
    <div class="tool">
      <div class="tool-hd">
        <span class="ico icon-blue"><i class="fa-solid fa-book-open" aria-hidden="true"></i></span>
        <div>
          <h4>ทะเบียนวัสดุ Mango</h4>
          <p>รหัสอ้างอิงสำหรับจับคู่ใบสั่งซื้อ — ไม่ใช่รหัสที่เบิก (มติ 34)</p>
        </div>
      </div>
      <div class="body">
        มี <b><?= number_format($nMango) ?></b> รหัสในทะเบียน ·
        <?= $nOrphan > 0
            ? 'มี <b>' . number_format($nOrphan) . '</b> รหัสที่ใบสั่งซื้อเคยอ้างแต่ยังไม่มีในทะเบียน'
            : 'ทุกรหัสที่ใบสั่งซื้ออ้างถึงมีในทะเบียนครบ' ?>
        <div class="small" style="margin-top:.35rem">
          หน้านี้แก้รายตัว + ผูกประตูได้ · <b>เพิ่มรหัสใหม่ / นำเข้าเป็นชุดด้วย Excel</b> ให้ไปที่หน้าทะเบียน
        </div>
      </div>
      <a class="btn btn-secondary" href="<?= APP_BASE ?>/mango_master.php"><i class="fa-solid fa-book-open" aria-hidden="true"></i> เปิดหน้าทะเบียนวัสดุ Mango</a>
    </div>

    <div class="tool">
      <div class="tool-hd">
        <span class="ico <?= $nMangoHold > 0 ? 'icon-yellow' : 'icon-green' ?>"><i class="fa-solid fa-folder-tree" aria-hidden="true"></i></span>
        <div>
          <h4>จัดการรหัสวัสดุ — ผูกรหัส Mango → LLP → IC แล้วย้ายยอด</h4>
          <p>CatID/CharID มาจากตัวสินค้า (LLP) ของ IC ที่ผูก ไม่ได้ตั้งที่วัสดุ (มติ 28)</p>
        </div>
      </div>
      <?php if ($nMangoHold > 0): ?>
        <div class="alert warn">
          <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
          <div>มี <b><?= number_format($nMangoHold) ?></b> รหัส Mango ที่ยังถือยอด/อยู่ในโครงการ — ยอดพวกนี้ยังโชว์เป็นรหัส Mango
            และเบิกไม่ได้ จนกว่าจะผูกกับรหัส IC แล้วย้ายยอด</div>
        </div>
      <?php else: ?>
        <div class="alert ok">
          <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
          <div>ไม่มีรหัส Mango ที่ถือยอดค้างอยู่ — ของทุกชิ้นอยู่ใต้รหัส IC แล้ว</div>
        </div>
      <?php endif; ?>
      <div class="body small">
        ผูกทีละตัว (ค้น IC ที่ออกแล้ว หรือไล่บันไดออกรหัสใหม่) หรือเป็นชุดด้วย Excel → ดูแผน → ย้ายยอดในทรานแซกชันเดียว
      </div>
      <a class="btn" href="<?= APP_BASE ?>/setup_master.php"><i class="fa-solid fa-folder-tree" aria-hidden="true"></i> เปิดหน้าจัดการรหัสวัสดุ (Mango → LLP → IC)</a>
    </div>
  </div>
</section>

<?php if ($uaSites): ?>
<section class="adm-sec" id="gate-alloc">
  <div class="adm-hd">
    <h3><i class="fa-solid fa-door-open" aria-hidden="true"></i> ยอดคงเหลือที่ยังไม่อยู่ประตูใด</h3>
    <span class="sub">ฟอร์มเบิก / เบ็ดเตล็ด / ยืม เลือกได้เฉพาะประตูที่มีของ</span>
  </div>
  <div class="alert warn">
    <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
    <div>ยอดยกมาจากชีตเดิมมีแต่<b>ยอดรวมรายไซต์</b> ยังไม่ได้ลงว่าอยู่ประตูไหน — วัสดุพวกนี้ฟอร์มเบิกจะขึ้น
      "ไม่มีของในประตูใดเลย" และ<b>เบิกไม่ได้</b> · ปุ่มด้านล่างลงยอดส่วนนั้นไว้ที่<b>ประตูตั้งต้น</b>ของแต่ละวัสดุ
      (ประตูที่ระบบเดิมผูกไว้) · ไม่แตะยอดรวม ยอดจอง หรือเอกสารใด ๆ · ตั้งประตูรายตัวในตารางด้านล่างก็ลงยอดให้เหมือนกัน</div>
  </div>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>ไซต์</th><th class="num">รายการ</th><th class="num">จำนวนรวม</th><th>จะลงที่ประตู</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($uaSites as $pid => $s):
          $msg = 'ลงยอด ' . number_format($s['items']) . ' รายการ (รวม ' . fmtQ($s['qty']) . ' หน่วย) ของไซต์ '
               . $s['code'] . ' ไว้ที่ประตูตั้งต้นของแต่ละวัสดุ?' . "\n\n" . 'ทำแล้วเบิก/เบ็ดเตล็ด/ยืมของพวกนี้ได้ทันที'; ?>
        <tr>
          <td class="mono"><?= e($s['code']) ?></td>
          <td class="num"><?= number_format($s['items']) ?></td>
          <td class="num"><?= fmtQ($s['qty']) ?></td>
          <td class="small">
            <?php foreach ($s['gates'] as $gc => $n): ?>
              <span class="pill p-info mono"><?= e($gc) ?></span> <?= number_format($n) ?> รายการ&nbsp;
            <?php endforeach; ?>
            <?php if ($s['nogate']): ?>
              <div class="sub-line">ข้าม <?= number_format($s['nogate']) ?> รายการ — ไซต์นี้ไม่มีประตูที่ใช้งาน (เพิ่มที่แท็บประตูก่อน)</div>
            <?php endif; ?>
            <?php if ($s['odd']): ?>
              <div class="sub-line">⚠ <?= number_format($s['odd']) ?> รายการ ยอดรายประตูเกินยอดรวม — ปุ่มนี้ไม่แตะ ให้ตรวจเป็นรายตัว</div>
            <?php endif; ?>
          </td>
          <td class="act">
            <a class="btn btn-secondary" href="<?= e('?' . http_build_query(['t' => 'materials', 'proj' => $pid, 'scope' => 'unalloc'])) ?>#mat-list"><i class="fa-solid fa-list" aria-hidden="true"></i> ดูรายการ</a>
            <?php if ($s['items'] > 0): ?>
              <form method="post" style="display:inline" onsubmit="return confirm(<?= e(json_encode($msg, JSON_UNESCAPED_UNICODE)) ?>)">
                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action" value="gate_alloc">
                <input type="hidden" name="project_id" value="<?= (int)$pid ?>">
                <button type="submit"><i class="fa-solid fa-door-open" aria-hidden="true"></i> ลงยอดที่ประตูตั้งต้น</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<?php
    // ตัวแบ่งหน้า — ใช้ทั้งบนและล่างตาราง
    $pagerHtml = '';
    if ($mTotal > 0) {
        $from1 = ($mpage - 1) * $mper + 1;
        $to1   = min($mTotal, $mpage * $mper);
        $pg = function (int $p, string $label, string $aria = '') use ($mUrl, $mpage, $mPages): string {
            if ($p < 1 || $p > $mPages) { return '<span class="pg off" aria-hidden="true">' . $label . '</span>'; }
            if ($p === $mpage && $aria === '') { return '<span class="pg cur" aria-current="page">' . $label . '</span>'; }
            return '<a class="pg" href="' . e($mUrl($p)) . '#mat-list"' . ($aria !== '' ? ' aria-label="' . e($aria) . '"' : '') . '>' . $label . '</a>';
        };
        $pagerHtml = '<nav class="pager" aria-label="หน้าของตารางวัสดุ"><span class="info">แสดง ' . number_format($from1) . '–'
                   . number_format($to1) . ' จาก ' . number_format($mTotal) . ' รายการ</span>';
        if ($mPages > 1) {
            $pagerHtml .= $pg($mpage - 1, '<i class="fa-solid fa-chevron-left" aria-hidden="true"></i>', 'หน้าก่อน');
            $win = [1, $mPages];
            for ($p = $mpage - 2; $p <= $mpage + 2; $p++) { if ($p >= 1 && $p <= $mPages) { $win[] = $p; } }
            $win = array_unique($win);
            sort($win);
            $prev = 0;
            foreach ($win as $p) {
                if ($prev > 0 && $p > $prev + 1) { $pagerHtml .= '<span class="pg gap">…</span>'; }
                $pagerHtml .= $pg($p, (string)$p);
                $prev = $p;
            }
            $pagerHtml .= $pg($mpage + 1, '<i class="fa-solid fa-chevron-right" aria-hidden="true"></i>', 'หน้าถัดไป');
            $pagerHtml .= '<form method="get" class="jump"><input type="hidden" name="t" value="materials">';
            foreach (['q' => $mq, 'type' => $mtype, 'proj' => (string)$mproj, 'scope' => $mscope] as $k => $v) {
                if ($v !== '') { $pagerHtml .= '<input type="hidden" name="' . $k . '" value="' . e($v) . '">'; }
            }
            $pagerHtml .= 'ไปหน้า <input type="number" name="pg" min="1" max="' . $mPages . '" value="' . $mpage . '" aria-label="เลขหน้า">'
                        . ' / ' . number_format($mPages) . ' <button type="submit">ไป</button></form>';
        }
        $pagerHtml .= '</nav>';
    }
?>
<section class="adm-sec" id="mat-list">
  <div class="adm-hd">
    <h3><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> ค้นวัสดุ</h3>
    <span class="sub">หน้าละ <?= $mper ?> รายการ · รหัส IC ขึ้นก่อน</span>
  </div>
  <form method="get" class="mini-form" style="margin-bottom:1rem">
    <input type="hidden" name="t" value="materials">
    <div class="grow"><label class="fld">รหัส หรือ ชื่อ</label>
      <div class="search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
        <input type="text" name="q" value="<?= e($mq) ?>" style="width:100%" placeholder="เช่น เหล็ก, STR01, PG14"></div></div>
    <div><label class="fld">ชนิดรหัส</label>
      <select name="type">
        <option value=""      <?= $mtype === ''      ? 'selected' : '' ?>>— ทั้งหมด —</option>
        <option value="ic"    <?= $mtype === 'ic'    ? 'selected' : '' ?>>IC (ออกจากบันได)</option>
        <option value="mango" <?= $mtype === 'mango' ? 'selected' : '' ?>>Mango (ของเดิม)</option>
      </select></div>
    <div><label class="fld">ไซต์ (ใช้ดูสต๊อก/ประตู)</label>
      <select name="proj">
        <?php foreach ($projects as $p): if ($p['status'] !== 'active') continue; ?>
          <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $mproj ? 'selected' : '' ?>>
            <?= e((string)$p['code']) ?> · <?= e((string)$p['name']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div><label class="fld">แสดง</label>
      <select name="scope">
        <?php foreach ($scopes as $k => $lbl): ?>
          <option value="<?= e($k) ?>" <?= $mscope === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div><button type="submit"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> ค้นหา</button></div>
  </form>

  <?= $pagerHtml ?>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>รหัส</th><th>ชื่อ</th><th>หน่วย</th><th>หมวด</th>
          <th class="num">คงเหลือ / อยู่ที่ประตู</th><th>ประตูตั้งต้น</th><th></th></tr></thead>
      <tbody>
      <?php if (empty($mats)): ?>
        <tr><td colspan="7" class="empty">ไม่พบวัสดุที่ตรงกับเงื่อนไข</td></tr>
      <?php endif; ?>
      <?php foreach ($mats as $m): ?>
        <tr>
          <td class="mono small">
            <?= e((string)$m['mat_code']) ?><br>
            <span class="pill <?= $m['code_type'] === 'ic' ? 'p-info' : 'p-muted' ?>"><?= e((string)$m['code_type']) ?></span>
          </td>
          <td><?= e((string)$m['name']) ?>
            <?php if (!empty($m['subgroup_name'])): ?>
              <div class="sub-line"><?= e((string)$m['subgroup_name']) ?></div>
            <?php endif; ?>
          </td>
          <td class="small"><?= e((string)$m['unit']) ?></td>
          <td class="small"><?= e((string)$m['cat_id']) ?> / <?= e((string)($m['char_id'] ?? '—')) ?></td>
          <td class="num"><?= $m['on_hand'] === null ? '—' : fmtQ($m['on_hand']) ?>
            <?php if (!empty($gateQty[(int)$m['id']])): ?>
              <div class="sub-line mono"><?= e(implode(' · ', $gateQty[(int)$m['id']])) ?></div>
            <?php endif; ?>
            <?php $ua = $m['on_hand'] === null ? 0.0 : round((float)$m['on_hand'] - (float)$m['gate_sum'], 3); ?>
            <?php if ($ua > 0): ?>
              <div class="qty-warn" title="เบิกไม่ได้จนกว่าจะลงประตู — กด แก้ไข → ตั้งประตู">ยังไม่อยู่ประตูใด <?= fmtQ($ua) ?></div>
            <?php elseif ($ua < 0): ?>
              <div class="qty-warn" title="ผลรวมรายประตูมากกว่ายอดรวม — ควรตรวจ">รายประตูเกิน <?= fmtQ(-$ua) ?></div>
            <?php endif; ?>
          </td>
          <td class="small"><?= $m['gate_code'] !== null ? '<span class="mono">' . e((string)$m['gate_code']) . '</span>' : '<span class="p-muted pill">ไม่ผูก</span>' ?></td>
          <td class="act"><button class="ghost mini" type="button" data-edit="m<?= (int)$m['id'] ?>" aria-expanded="false"><i class="fa-solid fa-pen" aria-hidden="true"></i> แก้ไข</button></td>
        </tr>
        <tr class="edit-row" id="m<?= (int)$m['id'] ?>">
          <td colspan="7">
            <form method="post" class="mini-form">
              <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="action" value="mat_save">
              <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
              <div class="grow"><label class="fld">ชื่อ</label>
                <input type="text" name="name" value="<?= e((string)$m['name']) ?>"></div>
              <div><label class="fld">หน่วย</label>
                <input type="text" name="unit" value="<?= e((string)$m['unit']) ?>" style="width:100px"></div>
              <?php if ((string)$m['code_type'] === 'ic'): ?>
                <?php /* มติ 29: รหัส IC เอาค่ามาจากตัวสินค้า (LLP) แก้ที่นี่ไม่ได้ ไม่งั้นสองตารางเพี้ยนจากกัน */ ?>
                <div><label class="fld">cat_id / char_id</label>
                  <div style="padding:.55rem 0">
                    <span class="pill p-muted"><?= e((string)$m['cat_id']) ?></span>
                    <span class="pill p-muted"><?= e((string)($m['char_id'] ?? '—')) ?></span>
                  </div></div>
                <div class="small" style="min-width:190px;align-self:center">
                  ค่ามาจากตัวสินค้า (LLP) —
                  <a href="<?= APP_BASE ?>/llp_master.php?q=<?= e(substr((string)$m['mat_code'], 0, 8)) ?>">แก้ที่หน้าตั้งค่าตัวสินค้า</a>
                </div>
              <?php else: ?>
                <div><label class="fld">cat_id</label>
                  <select name="cat_id">
                    <?php foreach (IC_CAT_IDS as $c): ?>
                      <option value="<?= $c ?>" <?= (string)$m['cat_id'] === $c ? 'selected' : '' ?>><?= $c ?></option>
                    <?php endforeach; ?>
                  </select></div>
                <div><label class="fld">char_id</label>
                  <select name="char_id">
                    <option value="">— ไม่ระบุ —</option>
                    <?php foreach (IC_CHAR_IDS as $c): ?>
                      <option value="<?= $c ?>" <?= (string)($m['char_id'] ?? '') === $c ? 'selected' : '' ?>><?= $c ?></option>
                    <?php endforeach; ?>
                  </select></div>
              <?php endif; ?>
              <div style="min-width:170px"><label class="fld">subgroup</label>
                <input type="text" name="subgroup_name" value="<?= e((string)($m['subgroup_name'] ?? '')) ?>" style="width:100%"></div>
              <div><button type="submit"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> บันทึก</button></div>
            </form>

            <form method="post" class="mini-form">
              <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="action" value="mat_gate">
              <input type="hidden" name="material_id" value="<?= (int)$m['id'] ?>">
              <input type="hidden" name="project_id" value="<?= $mproj ?>">
              <div><label class="fld">ประตูตั้งต้นในไซต์นี้ (ใช้ตอนออกใบที่ไม่ระบุประตู)</label>
                <select name="gate_id">
                  <option value="0">— ไม่ผูกประตู —</option>
                  <?php foreach ($gp as $g): ?>
                    <option value="<?= (int)$g['id'] ?>" <?= (int)$g['id'] === (int)$m['gate_id'] ? 'selected' : '' ?>>
                      <?= e((string)$g['gate_code']) ?> · <?= e((string)$g['name']) ?></option>
                  <?php endforeach; ?>
                </select></div>
              <div><button class="ghost" type="submit"><i class="fa-solid fa-door-open" aria-hidden="true"></i> ตั้งประตู</button></div>
              <?php if ($ua > 0): ?>
                <div class="small" style="align-self:center">ยอด <b><?= fmtQ($ua) ?></b> ที่ยังไม่อยู่ประตูใดจะลงที่ประตูที่ตั้งด้วย</div>
              <?php endif; ?>
            </form>
            <?php // ย้ายของข้ามประตู (มติ 51) — เฉพาะรหัส IC ที่มีของอยู่ และไซต์มี ≥ 2 ประตู
                  $moh = $gateOnHand[(int)$m['id']] ?? [];
                  if ((string)$m['code_type'] === 'ic' && $moh && count($gp) >= 2):
                      $fromDef = (int)array_key_first($moh);
                      $toDef   = 0;
                      foreach ($gp as $g) { if ((int)$g['id'] !== $fromDef) { $toDef = (int)$g['id']; break; } }
            ?>
            <form method="post" class="mini-form">
              <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="action" value="mat_transfer">
              <input type="hidden" name="material_id" value="<?= (int)$m['id'] ?>">
              <input type="hidden" name="project_id" value="<?= $mproj ?>">
              <div><label class="fld">ย้ายของข้ามประตู — จาก</label>
                <select name="from_gate_id">
                  <?php foreach ($gp as $g): $gid = (int)$g['id']; ?>
                    <option value="<?= $gid ?>" <?= $gid === $fromDef ? 'selected' : '' ?>><?= e((string)$g['gate_code']) ?> · <?= e((string)$g['name']) ?><?= isset($moh[$gid]) ? ' (มี ' . fmtQ($moh[$gid]) . ')' : ' (ไม่มีของนี้)' ?></option>
                  <?php endforeach; ?>
                </select></div>
              <div><label class="fld">ไปประตู</label>
                <select name="to_gate_id">
                  <?php foreach ($gp as $g): $gid = (int)$g['id']; ?>
                    <option value="<?= $gid ?>" <?= $gid === $toDef ? 'selected' : '' ?>><?= e((string)$g['gate_code']) ?> · <?= e((string)$g['name']) ?><?= isset($moh[$gid]) ? ' (มี ' . fmtQ($moh[$gid]) . ')' : '' ?></option>
                  <?php endforeach; ?>
                </select></div>
              <div><label class="fld">จำนวน (<?= e((string)$m['unit']) ?>)</label>
                <input type="number" name="qty" min="0.001" step="any" placeholder="0" style="width:110px" required></div>
              <div class="grow"><label class="fld">หมายเหตุ</label>
                <input type="text" name="note" placeholder="เช่น ย้ายไปไว้ใกล้หน้างานโซน B"></div>
              <div><button class="ghost" type="submit" onclick="return confirm('ย้ายของข้ามประตูตามที่ระบุ?')"><i class="fa-solid fa-right-left" aria-hidden="true"></i> ย้ายของ</button></div>
              <div class="small" style="align-self:center;min-width:220px">ยอดรวมไซต์ไม่เปลี่ยน · ย้ายได้เฉพาะของที่ยังไม่ถูกจองให้ใบที่รอรับ · ฟอร์มเบิกเห็นประตูใหม่ทันที</div>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= $pagerHtml ?>
  <p class="note">
    รหัสวัสดุ (<span class="mono">mat_code</span>) แก้ไม่ได้จากหน้านี้โดยตั้งใจ — เอกสารเก่าเก็บรหัสไว้เป็นข้อความ
    เปลี่ยนแล้วประวัติจะขาด · ต้องออกรหัสใหม่แทน (ที่หน้า <a href="<?= APP_BASE ?>/ic_new.php">ออกรหัส IC</a>)
  </p>
</section>

<?php // ══════════════════════════════════ ผู้ใช้ ══════════════════════
elseif ($t === 'users'):
    $uq     = trim((string)($_GET['q'] ?? ''));
    $ustat  = (string)($_GET['ustat'] ?? '');
    $uproj  = (int)($_GET['uproj'] ?? 0);

    $w = ['1=1']; $ar = [];
    if ($uq !== '') {
        $like = '%' . likeEscape($uq) . '%';
        $w[]  = '(u.username LIKE ? OR u.full_name LIKE ? OR u.emp_code LIKE ?)';
        array_push($ar, $like, $like, $like);
    }
    if ($ustat === 'active' || $ustat === 'inactive') { $w[] = 'u.status = ?'; $ar[] = $ustat; }
    if ($uproj > 0) { $w[] = 'u.project_id = ?'; $ar[] = $uproj; }

    $st = $pdo->prepare(
        'SELECT u.*, r.role_code, r.name AS role_name, r.level, r.can_req, r.can_daily_check, r.sc, r.bs,
                p.code AS proj_code, p.name AS proj_name,
                (SELECT COUNT(*) FROM remember_tokens rt
                  WHERE rt.account_type = "user" AND rt.account_id = u.id
                    AND rt.expires_at > NOW()) AS n_tokens
           FROM users u
           JOIN roles r    ON r.id = u.role_id
           JOIN projects p ON p.id = u.project_id
          WHERE ' . implode(' AND ', $w) . '
          ORDER BY r.level, u.username LIMIT 300'
    );
    $st->execute($ar);
    $users = $st->fetchAll();

    $roles = $pdo->query('SELECT id, role_code, name, level FROM roles ORDER BY level, role_code')->fetchAll();
    $nAdmin = admAdminCount($pdo);
?>
<section class="adm-sec">
  <div class="adm-hd">
    <h3><i class="fa-solid fa-users" aria-hidden="true"></i> ผู้ใช้</h3>
    <div class="end">
      <span class="pill p-muted"><?= count($users) ?> คน</span>
      <span class="pill p-info"><i class="fa-solid fa-user-shield" aria-hidden="true"></i> ผู้ดูแลที่ใช้งานได้ <?= $nAdmin ?> คน</span>
    </div>
  </div>

  <div class="alert info" data-fold="users-from-roles">
    <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
    <div>
      <b>สิทธิ์ทั้งหมดมาจาก “บทบาท” ไม่ได้ตั้งรายคน</b> — อยากให้ใครเบิกของได้ ให้ย้ายเขาไปบทบาทที่ติ๊ก
      <span class="mono">can_req</span> ไว้ หรือแก้บทบาทนั้นในส่วน “บทบาท / สิทธิ์” ด้านล่าง (มีผลกับทุกคนในบทบาทเดียวกัน)
      <ul>
        <li><b>ชื่อผู้ใช้แก้ไม่ได้หลังสร้าง</b> — เอกสารเก่าเก็บชื่อไว้เป็นข้อความ (<span class="mono">documents.requester_username</span>)
          และตัวเช็ก “ยกเลิกได้เฉพาะเจ้าของใบ” เทียบด้วยชื่อนี้ · เปลี่ยนแล้วประวัติขาดและยกเลิกใบตัวเองไม่ได้</li>
        <li>ระบบ<b>ไม่แสดงรหัสผ่านเดิม</b>ไม่ว่ากรณีใด (เก็บเป็น bcrypt) — ทำได้แค่ตั้งรหัสใหม่ทับ</li>
      </ul>
    </div>
  </div>

  <form method="get" class="mini-form" style="margin-bottom:1rem">
    <input type="hidden" name="t" value="users">
    <div class="grow"><label class="fld">ชื่อผู้ใช้ / ชื่อ-นามสกุล / รหัสพนักงาน</label>
      <div class="search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
        <input type="text" name="q" value="<?= e($uq) ?>" style="width:100%"></div></div>
    <div><label class="fld">สถานะ</label>
      <select name="ustat">
        <option value=""         <?= $ustat === ''         ? 'selected' : '' ?>>— ทั้งหมด —</option>
        <option value="active"   <?= $ustat === 'active'   ? 'selected' : '' ?>>ใช้งาน</option>
        <option value="inactive" <?= $ustat === 'inactive' ? 'selected' : '' ?>>ปิดใช้งาน</option>
      </select></div>
    <div><label class="fld">ไซต์</label>
      <select name="uproj">
        <option value="0">— ทั้งหมด —</option>
        <?php foreach ($projects as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $uproj ? 'selected' : '' ?>>
            <?= e((string)$p['code']) ?> · <?= e((string)$p['name']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div><button type="submit"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> ค้นหา</button></div>
  </form>

  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>ชื่อผู้ใช้</th><th>ชื่อ-นามสกุล</th><th>บทบาท</th><th>สิทธิ์</th>
          <th>ไซต์</th><th>สถานะ</th><th class="num">ล็อกอินค้าง</th><th></th></tr></thead>
      <tbody>
      <?php if (empty($users)): ?>
        <tr><td colspan="8" class="empty">ไม่พบผู้ใช้ที่ตรงกับเงื่อนไข</td></tr>
      <?php endif; ?>
      <?php foreach ($users as $u): ?>
        <tr class="<?= $u['status'] === 'inactive' ? 'dim' : '' ?>">
          <td class="mono"><b><?= e((string)$u['username']) ?></b>
            <?php if ((int)$u['id'] === (int)$user['accountId']): ?>
              <span class="pill p-info">คุณ</span>
            <?php endif; ?>
          </td>
          <td><?= e((string)$u['full_name']) ?>
            <?php if (!empty($u['emp_code'])): ?><div class="sub-line mono"><?= e((string)$u['emp_code']) ?></div><?php endif; ?>
          </td>
          <td class="small"><span class="mono"><?= e((string)$u['role_code']) ?></span> · R<?= (int)$u['level'] ?>
            <div class="sub-line"><?= e((string)$u['role_name']) ?></div></td>
          <td class="small">
            <?php if ((int)$u['level'] === 0): ?><span class="pill p-bad">ADM</span> <?php endif; ?>
            <?php if (isTrueFlag($u['can_req'])): ?><span class="pill p-ok">เบิกของ</span> <?php endif; ?>
            <?php if (isTrueFlag($u['can_daily_check'])): ?><span class="pill p-info">ตรวจประจำวัน</span> <?php endif; ?>
            <?php if (isTrueFlag($u['sc'])): ?><span class="pill p-warn">SC</span> <?php endif; ?>
            <?php if (isTrueFlag($u['bs'])): ?><span class="pill p-warn">BS</span><?php endif; ?>
          </td>
          <td class="small"><?= e((string)$u['proj_code']) ?></td>
          <td><span class="pill <?= $u['status'] === 'active' ? 'p-ok' : 'p-muted' ?>">
            <?= $u['status'] === 'active' ? 'ใช้งาน' : 'ปิดใช้งาน' ?></span></td>
          <td class="num"><?= (int)$u['n_tokens'] ?></td>
          <td class="act"><button class="ghost mini" type="button" data-edit="u<?= (int)$u['id'] ?>" aria-expanded="false"><i class="fa-solid fa-pen" aria-hidden="true"></i> แก้ไข</button></td>
        </tr>
        <tr class="edit-row" id="u<?= (int)$u['id'] ?>">
          <td colspan="8">
            <form method="post" class="mini-form">
              <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="action" value="user_save">
              <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <div><label class="fld">ชื่อผู้ใช้ (แก้ไม่ได้)</label>
                <input type="text" value="<?= e((string)$u['username']) ?>" style="width:130px" class="mono" readonly></div>
              <div class="grow"><label class="fld">ชื่อ-นามสกุล *</label>
                <input type="text" name="full_name" value="<?= e((string)$u['full_name']) ?>"></div>
              <div><label class="fld">รหัสพนักงาน</label>
                <input type="text" name="emp_code" value="<?= e((string)($u['emp_code'] ?? '')) ?>" style="width:120px" class="mono"></div>
              <div><label class="fld">บทบาท *</label>
                <select name="role_id">
                  <?php foreach ($roles as $r): ?>
                    <option value="<?= (int)$r['id'] ?>" <?= (int)$r['id'] === (int)$u['role_id'] ? 'selected' : '' ?>>
                      <?= e((string)$r['role_code']) ?> · R<?= (int)$r['level'] ?> · <?= e((string)$r['name']) ?></option>
                  <?php endforeach; ?>
                </select></div>
              <div><label class="fld">ไซต์ *</label>
                <select name="project_id">
                  <?php foreach ($projects as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === (int)$u['project_id'] ? 'selected' : '' ?>>
                      <?= e((string)$p['code']) ?> · <?= e((string)$p['name']) ?></option>
                  <?php endforeach; ?>
                </select></div>
              <div><label class="fld">บัตร (RFID)</label>
                <input type="text" name="card_id" value="<?= e((string)($u['card_id'] ?? '')) ?>" style="width:130px;text-transform:uppercase" class="mono"
                       placeholder="เช่น 0FA76A" autocomplete="off" spellcheck="false"
                       title="รหัสที่ตู้แสดงตอนแตะบัตร — เลข 0–9 และ A–F (ศูนย์ ไม่ใช่ตัว O)"></div>
              <div><label class="fld">สถานะ</label>
                <select name="status">
                  <option value="active"   <?= $u['status'] === 'active'   ? 'selected' : '' ?>>ใช้งาน</option>
                  <option value="inactive" <?= $u['status'] === 'inactive' ? 'selected' : '' ?>>ปิดใช้งาน</option>
                </select></div>
              <div><button type="submit"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> บันทึก</button></div>
            </form>

            <form method="post" class="mini-form"
                  onsubmit="return confirm('ตั้งรหัสผ่านใหม่ให้ <?= e((string)$u['username']) ?> ?\n\nรหัสเดิมจะใช้ไม่ได้ทันที');">
              <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="action" value="user_pass">
              <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <div><label class="fld">ตั้งรหัสผ่านใหม่ (ไม่ต้องรู้รหัสเดิม)</label>
                <input type="text" name="new_password" style="width:210px" class="mono"
                       placeholder="ห้ามเว้นวรรค / ภาษาไทย" autocomplete="off"></div>
              <div><label class="chk">
                  <input type="checkbox" name="revoke" value="1" checked>
                  เตะออกจากทุกเครื่อง</label></div>
              <div><button class="ghost" type="submit"><i class="fa-solid fa-key" aria-hidden="true"></i> ตั้งรหัสใหม่</button></div>
              <div class="small" style="flex:1;min-width:180px;align-self:center">
                ระบบไม่เก็บรหัสไว้ให้ดูย้อนหลัง — คัดลอกไปแจ้งเจ้าตัวก่อนปิดหน้านี้
              </div>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="adm-sec">
  <div class="adm-hd"><h3><i class="fa-solid fa-user-plus" aria-hidden="true"></i> เพิ่มผู้ใช้ใหม่</h3></div>
  <form method="post" class="mini-form form-section">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="action" value="user_new">
    <div><label class="fld">ชื่อผู้ใช้ *</label>
      <input type="text" name="username" style="width:140px" class="mono" required></div>
    <div class="grow"><label class="fld">ชื่อ-นามสกุล *</label>
      <input type="text" name="full_name" required></div>
    <div><label class="fld">รหัสพนักงาน</label>
      <input type="text" name="emp_code" style="width:120px" class="mono"></div>
    <div><label class="fld">บทบาท *</label>
      <select name="role_id" required>
        <?php foreach ($roles as $r): ?>
          <option value="<?= (int)$r['id'] ?>"><?= e((string)$r['role_code']) ?> · R<?= (int)$r['level'] ?> · <?= e((string)$r['name']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div><label class="fld">ไซต์ *</label>
      <select name="project_id" required>
        <?php foreach ($projects as $p): if ($p['status'] !== 'active') continue; ?>
          <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === (int)($user['projectId'] ?? 0) ? 'selected' : '' ?>>
            <?= e((string)$p['code']) ?> · <?= e((string)$p['name']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div><label class="fld">รหัสผ่านตั้งต้น *</label>
      <input type="text" name="password" style="width:180px" class="mono" autocomplete="off" required></div>
    <div><button type="submit"><i class="fa-solid fa-user-plus" aria-hidden="true"></i> เพิ่มผู้ใช้</button></div>
  </form>
</section>

<section class="adm-sec">
  <?php
    $roleFull = $pdo->query(
        'SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS n_users
           FROM roles r ORDER BY r.level, r.role_code'
    )->fetchAll();
  ?>
  <div class="adm-hd">
    <h3><i class="fa-solid fa-user-tag" aria-hidden="true"></i> บทบาท / สิทธิ์</h3>
    <span class="pill p-muted"><?= count($roleFull) ?> บทบาท</span>
  </div>
  <div class="alert warn" data-fold="roles-scope">
    <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
    <div>
      แก้ที่นี่ <b>มีผลกับผู้ใช้ทุกคนในบทบาทนั้นพร้อมกัน</b> · ผู้ที่ล็อกอินค้างอยู่จะเห็นผลตอนเข้าใหม่
      <ul>
        <li><span class="mono">ระดับ 0</span> = ผู้ดูแลระบบ (ADM) เห็นหน้านี้และแก้บันได IC ได้ ·
          <span class="mono">can_req</span> = สายคลัง เข้าโมดูล PO/buffer/IC ได้ ·
          <span class="mono">SC</span> = เลขาไซต์ · <span class="mono">BS</span> = ผู้ดูแลผู้รับเหมา</li>
        <li>ระบบกันไม่ให้แก้จนไม่เหลือ ADM ที่ใช้งานได้เลย — ถ้าเจอข้อความเตือน แปลว่ากำลังจะล็อกตัวเองออก</li>
      </ul>
    </div>
  </div>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>รหัส</th><th>ชื่อ</th><th class="num">ระดับ</th><th>สิทธิ์</th><th class="num">ผู้ใช้</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($roleFull as $r): ?>
        <tr>
          <td class="mono"><b><?= e((string)$r['role_code']) ?></b></td>
          <td><?= e((string)$r['name']) ?></td>
          <td class="num">R<?= (int)$r['level'] ?></td>
          <td class="small">
            <?php if ((int)$r['level'] === 0): ?><span class="pill p-bad">ADM</span> <?php endif; ?>
            <?php if (isTrueFlag($r['can_req'])): ?><span class="pill p-ok">เบิกของ</span> <?php endif; ?>
            <?php if (isTrueFlag($r['can_daily_check'])): ?><span class="pill p-info">ตรวจประจำวัน</span> <?php endif; ?>
            <?php if (isTrueFlag($r['sc'])): ?><span class="pill p-warn">SC</span> <?php endif; ?>
            <?php if (isTrueFlag($r['bs'])): ?><span class="pill p-warn">BS</span><?php endif; ?>
          </td>
          <td class="num"><?= (int)$r['n_users'] ?></td>
          <td class="act"><button class="ghost mini" type="button" data-edit="r<?= (int)$r['id'] ?>" aria-expanded="false"><i class="fa-solid fa-pen" aria-hidden="true"></i> แก้ไข</button></td>
        </tr>
        <tr class="edit-row" id="r<?= (int)$r['id'] ?>">
          <td colspan="6">
            <form method="post" class="mini-form">
              <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="action" value="role_save">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <div><label class="fld">รหัส (แก้ไม่ได้)</label>
                <input type="text" value="<?= e((string)$r['role_code']) ?>" style="width:90px" class="mono" readonly></div>
              <div class="grow"><label class="fld">ชื่อ</label>
                <input type="text" name="name" value="<?= e((string)$r['name']) ?>"></div>
              <div><label class="fld">ระดับ (0 = ADM)</label>
                <input type="number" name="level" value="<?= (int)$r['level'] ?>" min="0" max="12" style="width:90px"></div>
              <div><label class="fld">สิทธิ์</label>
                <div class="chk-group">
                  <label class="chk"><input type="checkbox" name="can_req" value="1" <?= isTrueFlag($r['can_req']) ? 'checked' : '' ?>> เบิกของ</label>
                  <label class="chk"><input type="checkbox" name="can_daily_check" value="1" <?= isTrueFlag($r['can_daily_check']) ? 'checked' : '' ?>> ตรวจประจำวัน</label>
                  <label class="chk"><input type="checkbox" name="sc" value="1" <?= isTrueFlag($r['sc']) ? 'checked' : '' ?>> SC</label>
                  <label class="chk"><input type="checkbox" name="bs" value="1" <?= isTrueFlag($r['bs']) ? 'checked' : '' ?>> BS</label>
                </div></div>
              <div><button type="submit"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> บันทึก</button></div>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php // ═════════════════════════════════ บันได IC ═════════════════════
else:
    $cnt = function (string $sql) use ($pdo) { return (int)$pdo->query($sql)->fetchColumn(); };
    // [ป้าย, จำนวน, ไอคอน, สี] — น้ำเงิน = ชั้นหมวด · ม่วง = ส่วนเติมสเปก · เขียว = รหัสที่ออกแล้ว
    $stats = [
        ['กลุ่มหลัก (L1)',     $cnt('SELECT COUNT(*) FROM l1_groups'),     'fa-sitemap',        'icon-blue'],
        ['หมวด (L2)',          $cnt('SELECT COUNT(*) FROM l2_categories'), 'fa-folder-tree',    'icon-blue'],
        ['ตัวสินค้า (LLP)',    $cnt('SELECT COUNT(*) FROM llp_products'),  'fa-cubes',          'icon-blue'],
        ['ขนาด',               $cnt('SELECT COUNT(*) FROM sizes'),         'fa-ruler-combined', 'icon-purple'],
        ['ยี่ห้อ',             $cnt('SELECT COUNT(*) FROM brands'),        'fa-tag',            'icon-purple'],
        ['หน่วยเก็บ',          $cnt('SELECT COUNT(*) FROM units'),         'fa-scale-balanced', 'icon-purple'],
        ['คุณสมบัติเพิ่ม',     $cnt('SELECT COUNT(*) FROM extra_attrs'),   'fa-sliders',        'icon-purple'],
        ['รหัส IC ที่ออกแล้ว', $cnt('SELECT COUNT(*) FROM ic_items'),      'fa-barcode',        'icon-green'],
    ];
    // ตัวสินค้าที่ยังไม่ตั้ง CatID/CharID = ออก IC ใต้มันไม่ได้ (มติ 32)
    $llpUnset = $cnt('SELECT COUNT(*) FROM llp_products WHERE cat_id IS NULL OR char_id IS NULL');
    $llpTotal = $cnt('SELECT COUNT(*) FROM llp_products');
    $recent = $pdo->query(
        'SELECT i.ic_code, i.ic_name, u.unit_name, i.created_at
         FROM ic_items i JOIN units u ON u.unit_code = i.unit_code
         ORDER BY i.created_at DESC LIMIT 15'
    )->fetchAll();
?>
<section class="adm-sec">
  <div class="adm-hd"><h3><i class="fa-solid fa-layer-group" aria-hidden="true"></i> บันได IC — จำนวนแต่ละชั้น</h3></div>
  <div class="metrics">
    <?php foreach ($stats as $s): ?>
      <div class="metric">
        <span class="ico <?= $s[3] ?>"><i class="fa-solid <?= $s[2] ?>" aria-hidden="true"></i></span>
        <div><div class="l"><?= e($s[0]) ?></div><div class="v"><?= number_format($s[1]) ?></div></div>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="note">
    รหัส IC 20 ตัว = <span class="mono">L1(3) + L2(2) + ตัวสินค้า(3) + ขนาด(3) + ยี่ห้อ(3) + หน่วย(3) + คุณสมบัติ(3)</span><br>
    เพิ่มตัวสินค้า/ขนาด/ยี่ห้อ/คุณสมบัติได้จากแผงไล่บันไดในหน้า
    <a href="<?= APP_BASE ?>/rc.php">รับของ/buffer</a> และหน้า
    <a href="<?= APP_BASE ?>/ic_new.php">ออกรหัส IC</a> โดยตรง (มติ 16 — สงวนให้ ADM)
  </p>
</section>

<section class="adm-sec">
  <div class="adm-hd">
    <h3><i class="fa-solid fa-cubes" aria-hidden="true"></i> ตั้งค่าตัวสินค้า — หมวดอนุมัติ / ลักษณะวัสดุ</h3>
    <span class="sub">ตั้งครั้งเดียวที่ตัวสินค้า IC ทุกตัวใต้มันได้ค่าเดียวกัน (มติ 28)</span>
  </div>

  <?php if ($llpUnset > 0): ?>
    <div class="alert warn">
      <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
      <div>ยังไม่ได้ตั้งค่า <b><?= number_format($llpUnset) ?></b> จาก <?= number_format($llpTotal) ?> ตัวสินค้า —
        ตัวที่ยังไม่ตั้ง <b>ออกรหัส IC ไม่ได้</b> คนรับของจะติดตรงนั้นทันที</div>
    </div>
  <?php else: ?>
    <div class="alert ok">
      <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
      <div>ตั้งค่าครบทั้ง <?= number_format($llpTotal) ?> ตัวสินค้าแล้ว</div>
    </div>
  <?php endif; ?>

  <p class="small">
    ตั้งทีละมาก ๆ ด้วยไฟล์ Excel (โหลดไฟล์ตั้งต้น → กรอก CatID/CharID จาก dropdown ในไฟล์ →
    อัปกลับ → ดูสรุปผลกระทบก่อนกดยืนยัน) หรือแก้ทีละตัวจากตารางในหน้านั้นก็ได้
  </p>
  <div class="actions" style="margin-top:.85rem">
    <a class="btn" href="<?= APP_BASE ?>/llp_master.php"><i class="fa-solid fa-cubes" aria-hidden="true"></i> เปิดหน้าตั้งค่าตัวสินค้า (LLP)</a>
  </div>
</section>

<section class="adm-sec">
  <div class="adm-hd">
    <h3><i class="fa-solid fa-screwdriver-wrench" aria-hidden="true"></i> เครื่องมือสาย IC / OCR</h3>
    <span class="sub">ย้ายมาจากเมนูหน้ารับของตามใบ PO (มติ 37)</span>
  </div>
  <div class="actions">
    <a class="btn" href="<?= APP_BASE ?>/setup_master.php"><i class="fa-solid fa-folder-tree" aria-hidden="true"></i> จัดการรหัสวัสดุ (Mango → LLP → IC)</a>
    <a class="btn" href="<?= APP_BASE ?>/ic_new.php"><i class="fa-solid fa-barcode" aria-hidden="true"></i> ออกรหัส IC</a>
    <a class="btn btn-secondary" href="<?= APP_BASE ?>/ic_list.php"><i class="fa-solid fa-book" aria-hidden="true"></i> ทะเบียน IC</a>
  </div>
  <p class="note">
    จัดการรหัสวัสดุ = ผูก/เปลี่ยนตัวสินค้า (LLP) ของรหัส Mango · ออก/ผูก IC · ย้ายยอด ·
    ออกรหัส IC = ไล่บันไดออกรหัสทีละตัวนอกจอรับของ · ทะเบียน IC = รหัสที่ออกไปแล้วทั้งหมด (กด ✏️ แก้ชื่อ/สเปก) ·
    ส่วนตั้งค่า OCR อยู่ที่แท็บ “ตั้งค่า OCR” ด้านบน
    — งานรับของหน้างานจริงออกรหัสได้ในจอ <a href="<?= APP_BASE ?>/rc.php">รับของ/buffer</a> อยู่แล้ว
  </p>
</section>

<section class="adm-sec">
  <div class="adm-hd"><h3><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> รหัส IC ที่ออกล่าสุด</h3></div>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>รหัส</th><th>ชื่อ</th><th>หน่วยเก็บ</th><th>ออกเมื่อ</th></tr></thead>
      <tbody>
      <?php if (empty($recent)): ?>
        <tr><td colspan="4" class="empty">ยังไม่มีรหัส IC ในระบบ</td></tr>
      <?php endif; ?>
      <?php foreach ($recent as $r): ?>
        <tr>
          <td class="mono small"><?= e((string)$r['ic_code']) ?></td>
          <td><?= e((string)$r['ic_name']) ?></td>
          <td class="small"><?= e((string)$r['unit_name']) ?></td>
          <td class="small"><?= e(substr((string)$r['created_at'], 0, 16)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

</div><!-- /.tab-content -->
</div><!-- /.tabs-container -->

<script>
// ปุ่ม "แก้ไข" เปิด/ปิดแถวฟอร์มใต้แถวข้อมูล
document.addEventListener('click', function (ev) {
  var b = ev.target.closest('[data-edit]');
  if (!b) { return; }
  var row = document.getElementById(b.dataset.edit);
  if (!row) { return; }
  var on = row.classList.toggle('on');
  b.innerHTML = on
    ? '<i class="fa-solid fa-xmark" aria-hidden="true"></i> ปิด'
    : '<i class="fa-solid fa-pen" aria-hidden="true"></i> แก้ไข';
  b.setAttribute('aria-expanded', on ? 'true' : 'false');
  if (on) { var f = row.querySelector('input:not([type=hidden]):not([readonly]),select'); if (f) { f.focus(); } }
});

// สวิตช์ที่มี data-ask-on / data-ask-off: ถามยืนยันก่อนบันทึกเมื่อสลับไปทางที่ตู้อาจใช้งานไม่ได้
// (เทียบกับค่าที่บันทึกไว้ = defaultChecked)
document.addEventListener('submit', function (ev) {
  if (ev.defaultPrevented) { return; }
  var boxes = ev.target.querySelectorAll('input[data-ask-on],input[data-ask-off]');
  for (var i = 0; i < boxes.length; i++) {
    var c = boxes[i];
    var msg = c.checked && !c.defaultChecked ? c.getAttribute('data-ask-on')
            : (!c.checked && c.defaultChecked ? c.getAttribute('data-ask-off') : null);
    if (msg && !confirm(msg)) { ev.preventDefault(); return; }
  }
});
</script>

<?php
uiFoot('<i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> ทุกการแก้ในหน้านี้ถูกจดไว้ใน activity_log · ไม่มีปุ่มลบถาวรโดยตั้งใจ — ใช้ “ปิดใช้งาน / เก็บเข้ากรุ” แทน');
