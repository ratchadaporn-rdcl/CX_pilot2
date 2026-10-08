<?php
/**
 * CONNEXT — setup_master.php : จัดการรหัสวัสดุ — Mango → LLP → IC แล้วย้ายยอดสต๊อก
 *
 * มติ 40-42 (ต่อจากมติ 33/34/38):
 *   · ระบบแสดงผล/เบิกได้เฉพาะรหัส IC จาก ic_items — รหัส Mango เป็นทะเบียนอ้างอิงเท่านั้น
 *   · ข้อมูลตั้งต้นจากชีตเดิม (ยอดคงเหลือ / วัสดุในโครงการ / ราคาหักเงิน / เอกสาร) ยังคีย์ด้วย
 *     รหัส Mango — หน้านี้คือที่เดียวที่ ADM ใช้ผูกแล้วสั่ง "ย้ายยอด" ให้ไปอยู่ใต้ IC
 *   · การผูกเป็น 2 ขั้น (มติ 42):
 *       ขั้น 1  Mango → LLP (ตัวสินค้า) — นำเข้าทีเดียวจากไฟล์ "สร้าง LLP.xlsx" ของฝ่ายจัดซื้อ (ข้อ 2)
 *               แล้วแก้/เพิ่ม/ถอดรายตัวในตารางข้อ 3
 *       ขั้น 2  LLP → IC (เติมขนาด/ยี่ห้อ/หน่วย/คุณสมบัติ) — ไล่บันไดในจอ ออกรหัสแล้วผูกให้เลย
 *   · มติ 45: 1 Mango มี LLP ได้ตัวเดียว (ผูกซ้ำ = "เปลี่ยนตัวสินค้า") · 1 LLP มีหลาย Mango และออกได้หลาย IC ·
 *     1 Mango มีหลาย IC ได้แต่ต้องอยู่ใต้ LLP ของมัน ("＋ เพิ่ม IC") · IC "หลัก" รับบรรทัดเอกสารเดิม/ราคาที่ล็อก
 *   · CatID/CharID เป็นของ LLP (มติ 28-29) — ตั้งที่ llp_master.php หรือในจอนี้ตอนไล่บันได
 *
 * logic อยู่ใน lib/setup_master.php · ตัวนำเข้าไฟล์อยู่ lib/llp_import.php
 * ADM (R0) เท่านั้น — งานแตะ master ตามมติ 16 · PHP 7.4-compatible เท่านั้น
 */

require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/setup_master.php';
require __DIR__ . '/lib/llp_import.php';
require __DIR__ . '/lib/ic_import.php';
require_once __DIR__ . '/includes/xlsx_lite.php';

$user = uiGuard();
if (!uiIsAdmin($user)) {
    http_response_code(403);
    if (uiIsEmbedded()) { uiEmbedNotice('หน้าจัดการรหัสวัสดุสงวนไว้สำหรับผู้ดูแลระบบ (ADM)', false); }
    echo '<meta charset="utf-8"><p style="font-family:sans-serif;padding:24px">'
       . 'หน้าจัดการรหัสวัสดุสงวนไว้สำหรับผู้ดูแลระบบ (ADM)</p>';
    exit;
}

const SM_PER_PAGE  = 100;
const SM_ERR_SHOW  = 40;
const SM_PLAN_SHOW = 300;
const SM_SHEET     = 'ผูก Mango → LLP';

$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$action = $isPost ? (string)($_POST['action'] ?? '') : '';
$csrfOk = $isPost && hash_equals(csrfToken(), (string)($_POST['csrf'] ?? ''));
$notice = null;    // [ok|warn|bad, ข้อความ]
$plan   = null;    // ผล preview ไฟล์ผูกแบบง่าย
$wb     = null;    // ผล preview ไฟล์ต้นฉบับ (สร้าง LLP.xlsx)
$icp    = null;    // ผล preview ออกรหัส IC จากไฟล์ (ข้อ 2b)
$ready  = smSchemaReady($pdo);

if ($isPost && !$csrfOk) {
    $notice = ['bad', 'CSRF token ไม่ถูกต้อง — โหลดหน้าใหม่แล้วลองอีกครั้ง'];
    $action = '';
}

if ($action === 'upgrade_schema' && !$ready) {
    try {
        $done   = smSchemaUpgrade($pdo);
        $ready  = smSchemaReady($pdo);
        $notice = ['ok', 'อัปเดตโครงสร้างฐานข้อมูลแล้ว (' . count($done) . ' คำสั่ง)'];
    } catch (Throwable $ex) {
        $notice = ['bad', 'อัปเดตโครงสร้างไม่สำเร็จ: ' . $ex->getMessage()];
    }
}

/** ลิสต์โครงการที่ยังใช้งาน */
$projects = $pdo->query("SELECT id, code, name FROM projects WHERE status = 'active' ORDER BY code")->fetchAll();
$projOpt  = [];
foreach ($projects as $p) { $projOpt[(int)$p['id']] = (string)$p['code'] . ' · ' . (string)$p['name']; }

$fProj = (int)($_REQUEST['project'] ?? 0);
if ($fProj > 0 && !isset($projOpt[$fProj])) { $fProj = 0; }

/** ยอดเป็นข้อความสำหรับช่องกรอก/ไฟล์ — ตัดศูนย์ท้าย (16550 · 12.5 · 0) */
function smNum($v): string {
    $s = rtrim(rtrim(number_format((float)$v, 3, '.', ''), '0'), '.');
    return $s === '' || $s === '-0' ? '0' : $s;
}

// ═══════════════════════════════════════════════════════════════════════════
// ส่งออก .xlsx — 1 แถวต่อ (Mango × LLP × IC) · ยังไม่ผูก = แถวเดียวช่องว่างให้กรอก
// ═══════════════════════════════════════════════════════════════════════════
if ($ready && isset($_GET['export'])) {
    $scope = (string)($_GET['scope'] ?? 'stock');
    $rows  = smRows($pdo, ['scope' => $scope === 'all' ? '' : 'stock', 'project' => $fProj]);

    $data = [smXlsxHead()];
    foreach ($rows as $r) {
        $base = [(string)$r['mat_code'], (string)$r['name'], (string)$r['unit'],
                 (float)$r['on_hand'] != 0 ? smNum($r['on_hand']) : ''];
        if (!$r['llps']) { $data[] = array_merge($base, ['', '', '', '']); continue; }
        foreach ($r['llps'] as $b) {
            if (!$b['ics']) {
                $data[] = array_merge($base, [(string)$b['llp_code'], (string)$b['llp_name'], '', '']);
                continue;
            }
            foreach ($b['ics'] as $ic) {
                $data[] = array_merge($base, [(string)$b['llp_code'], (string)$b['llp_name'],
                                              (string)$ic['ic_code'], (string)$ic['ic_name'] . ($ic['is_primary'] ? ' (หลัก)' : '')]);
            }
        }
    }
    $note = [
        ['วิธีกรอก', ''],
        ['1. คอลัมน์ E "รหัส LLP" = รหัสตัวสินค้า 8 หลัก (เช่น CON05002) — ขั้นที่ 1 ของการผูก', ''],
        ['2. คอลัมน์ G "รหัส IC" = รหัส 20 หลักที่ออกแล้ว (ถ้ามี) — ใส่แล้วระบบจะผูก LLP ของ IC นั้นให้เอง', ''],
        ['3. ห้ามแก้คอลัมน์ "รหัส Mango" — ระบบใช้เป็นกุญแจจับคู่', ''],
        ['4. รหัส Mango ตัวเดียวมีตัวสินค้า (LLP) ได้ตัวเดียว แต่มีหลาย IC ได้ (ใต้ LLP เดียวกัน): เพิ่มแถวที่มีรหัส Mango เดิม + รหัส IC อีกตัว', ''],
        ['   ใส่ LLP ต่างจากที่ผูกไว้จะไม่ถูกนำเข้า — เปลี่ยนตัวสินค้าที่หน้าจอข้อ 3 (ต้องเห็นว่า IC ไหนจะหลุด)', ''],
        ['5. เว้นว่างทั้ง E และ G = ข้ามแถวนั้น · การนำเข้า "เพิ่ม" การผูกเท่านั้น ไม่ถอดของเดิม', ''],
        ['', ''],
        ['ถ้าต้องการนำเข้าทั้งก้อนจากไฟล์ "สร้าง LLP.xlsx" ของฝ่ายจัดซื้อ ใช้ข้อ 2 ในหน้าเว็บแทน (เร็วกว่ามาก)', ''],
    ];
    $xlsx = xlsxWrite([
        SM_SHEET   => ['header' => true, 'widths' => [16, 44, 10, 12, 12, 34, 22, 34], 'rows' => $data],
        'คำอธิบาย' => ['header' => true, 'widths' => [110, 10], 'rows' => $note],
    ]);
    $name = 'CONNEXT-MangoLLP-' . ($scope === 'all' ? 'ทั้งหมด-' : 'มีสต๊อก-') . date('Ymd-Hi') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . strlen($xlsx));
    echo $xlsx;
    exit;
}

/** ไฟล์ชั่วคราวของ preview — เก็บ path ไว้ใน session รอ commit */
function smStashPath(string $token, string $box = 'sm_import'): ?string {
    $b = $_SESSION[$box] ?? [];
    return isset($b[$token]['path']) && is_file($b[$token]['path']) ? (string)$b[$token]['path'] : null;
}
function smStash(string $box, string $token, string $path): void {
    $b = $_SESSION[$box] ?? [];
    foreach ($b as $k => $v) {
        if ((int)($v['ts'] ?? 0) < time() - 3600) { @unlink((string)$v['path']); unset($b[$k]); }
    }
    $b[$token] = ['path' => $path, 'ts' => time()];
    $_SESSION[$box] = $b;
}

/** ข้อความสรุปผลการย้าย */
function smMigrateMsg(array $n): string {
    return number_format($n['rows']) . ' รายการ (' . $n['projects'] . ' โครงการ)'
         . ($n['split'] > 0 ? ' · แยกยอดหลาย IC ' . $n['split'] . ' รายการ' : '')
         . ' · ยอดคงเหลือ ' . $n['bal'] . ' แถว · รายประตู ' . $n['gate'] . ' · วัสดุในโครงการ ' . $n['pm']
         . ' · ราคาหักเงิน ' . $n['rate'] . ' · บรรทัดเอกสาร ' . number_format($n['doc'])
         . ' · ราคาที่ล็อกในใบหักเงิน ' . $n['ddr'];
}

// ═══════════════════════════════════════════════════════════════════════════
// งานเขียน
// ═══════════════════════════════════════════════════════════════════════════
if ($ready && $csrfOk) {

    // ── นำเข้าไฟล์ต้นฉบับ "สร้าง LLP.xlsx" (ข้อ 2) ─────────────────────────
    if ($action === 'wb_preview' || $action === 'wb_commit') {
        $path  = '';
        $token = (string)($_POST['token'] ?? '');
        if ($action === 'wb_commit' && $token !== '') {
            $path = (string)(smStashPath($token, 'sm_wb') ?? '');
        }
        if ($path === '') {
            if (isset($_FILES['wbfile']) && ($_FILES['wbfile']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $token = bin2hex(random_bytes(8));
                $tmp   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cnx_wb_' . $token . '.xlsx';
                if (move_uploaded_file($_FILES['wbfile']['tmp_name'], $tmp)) { $path = $tmp; smStash('sm_wb', $token, $tmp); }
                else { $notice = ['bad', 'ย้ายไฟล์ที่อัปโหลดไม่สำเร็จ']; }
            } else {
                $p = trim((string)($_POST['wbpath'] ?? ''));
                if ($p !== '' && is_file($p)) { $path = $p; $token = ''; }
                elseif ($p !== '')            { $notice = ['bad', 'ไม่พบไฟล์ตามที่ระบุ: ' . $p]; }
                else                          { $notice = ['bad', 'ยังไม่ได้เลือกไฟล์ หรือระบุที่อยู่ไฟล์']; }
            }
        }
        if ($path !== '') {
            try {
                $parsed = llpImportRead($pdo, $path, (string)($_POST['charcat'] ?? '1') === '1');
                if ($action === 'wb_commit') {
                    $n = llpImportApply($pdo, $parsed, $user);
                    if ($token !== '') {
                        $b = $_SESSION['sm_wb'] ?? [];
                        @unlink($path);
                        unset($b[$token]);
                        $_SESSION['sm_wb'] = $b;
                    }
                    $notice = ['ok', 'นำเข้าไฟล์ต้นฉบับเรียบร้อย — L1 ' . $n['l1'] . ' · L2 ' . $n['l2']
                                   . ' · ตัวสินค้าใหม่ ' . number_format($n['llp']) . ' (แก้ชื่อ ' . $n['llp_upd'] . ')'
                                   . ' · ผูก Mango → LLP เพิ่ม ' . number_format($n['pair']) . ' คู่'
                                   . ' · ตั้ง CatID/CharID ให้ ' . number_format($n['cc']) . ' ตัวสินค้า'
                                   . ' · หน่วยเก็บเพิ่ม ' . $n['unit']];
                } else {
                    $wb = $parsed['stat'];
                    $wb['token']   = $token;
                    $wb['path']    = $path;
                    $wb['charcat'] = (string)($_POST['charcat'] ?? '1') === '1';
                }
            } catch (Throwable $ex) {
                $notice = ['bad', 'อ่าน/นำเข้าไฟล์ไม่สำเร็จ: ' . $ex->getMessage()];
            }
        }
    }

    // ── ออกรหัส IC ทั้งก้อนจากไฟล์ (ข้อ 2b · มติ 44) ──────────────────────────
    if ($action === 'ic_preview' || $action === 'ic_commit' || $action === 'ic_report') {
        $path  = '';
        $token = (string)($_POST['token'] ?? '');
        if ($token !== '') { $path = (string)(smStashPath($token, 'sm_ic') ?? ''); }
        if ($path === '') {
            $token = '';
            if (isset($_FILES['icfile']) && ($_FILES['icfile']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $token = bin2hex(random_bytes(8));
                $tmp   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cnx_ic_' . $token . '.xlsx';
                if (move_uploaded_file($_FILES['icfile']['tmp_name'], $tmp)) { $path = $tmp; smStash('sm_ic', $token, $tmp); }
                else { $token = ''; $notice = ['bad', 'ย้ายไฟล์ที่อัปโหลดไม่สำเร็จ']; }
            } else {
                $p = trim((string)($_POST['icpath'] ?? ''));
                if ($p !== '' && is_file($p)) { $path = $p; }
                elseif ($p !== '')            { $notice = ['bad', 'ไม่พบไฟล์ตามที่ระบุ: ' . $p]; }
                else                          { $notice = ['bad', 'ยังไม่ได้เลือกไฟล์ หรือระบุที่อยู่ไฟล์']; }
            }
        }
        if ($path !== '') {
            try {
                $parsed = icImportRead($pdo, $path);     // commit ก็อ่านใหม่ — กัน DB ขยับระหว่างกดยืนยัน
                if ($action === 'ic_report') {
                    $xlsx = icImportReportXlsx($parsed, null);
                    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                    $fn = 'CONNEXT-ทะเบียนIC-ตรวจ-' . date('Ymd-Hi') . '.xlsx';
                    header('Content-Disposition: attachment; filename="CONNEXT-IC-check-' . date('Ymd-Hi') . '.xlsx"; '
                         . "filename*=UTF-8''" . rawurlencode($fn));
                    header('Content-Length: ' . strlen($xlsx));
                    echo $xlsx;
                    exit;
                }
                if ($action === 'ic_commit') {
                    icEnsureFlagColumns($pdo);          // ALTER ต้องอยู่นอกทรานแซกชัน
                    $n = icImportApply($pdo, $parsed, $user);
                    if ($token !== '') {
                        $b = $_SESSION['sm_ic'] ?? [];
                        @unlink($path);
                        unset($b[$token]);
                        $_SESSION['sm_ic'] = $b;
                    }
                    $st = $parsed['stat'];
                    $notice = ['ok', 'ออกรหัส IC เรียบร้อย — IC ใหม่ ' . number_format($n['ic'])
                                   . ' · ผูก Mango → IC ' . number_format($n['map'])
                                   . ' · ขนาดใหม่ ' . $n['size'] . ' · ยี่ห้อใหม่ ' . $n['brand']
                                   . ' · คุณสมบัติใหม่ ' . number_format($n['extra'])
                                   . ($st['sys_by']['llp_unset'] > 0 ? ' · ยังติด CatID/CharID ' . number_format($st['sys_by']['llp_unset']) . ' รหัสในระบบ' : '')
                                   . ' — ขั้นต่อไป: ตรวจแล้วกด "ย้ายยอด" (ข้อ 4)'];
                } else {
                    $icp          = $parsed['stat'];
                    $icp['token'] = $token;
                    $icp['path']  = $path;
                }
            } catch (Throwable $ex) {
                $notice = ['bad', 'อ่านไฟล์/ออกรหัส IC ไม่สำเร็จ — ไม่มีอะไรถูกบันทึก: ' . $ex->getMessage()];
            }
        }
    }
    if ($action === 'ic_discard') {
        $token = (string)($_POST['token'] ?? '');
        $path  = smStashPath($token, 'sm_ic');
        if ($path !== null) { @unlink($path); }
        $b = $_SESSION['sm_ic'] ?? [];
        unset($b[$token]);
        $_SESSION['sm_ic'] = $b;
        $notice = ['warn', 'ยกเลิกการออกรหัส IC แล้ว — ไม่มีอะไรถูกบันทึก'];
    }

    // ── ผูก/ถอด รายตัวแบบไม่ใช้ JS ─────────────────────────────────────────
    if ($action === 'map_llp_one') {
        $r = smMapLlp($pdo, (string)($_POST['mat'] ?? ''), (string)($_POST['llp'] ?? ''), $user,
                      (string)($_POST['replace'] ?? '') === '1');
        if (!$r['ok'])          { $notice = ['bad', $r['error'] . ($r['need_confirm'] ? ' (ติ๊กช่อง "ยอมให้ IC หลุด" แล้วกดใหม่)' : '')]; }
        elseif (!$r['changed']) { $notice = ['warn', 'ผูกไว้แบบนี้อยู่แล้ว — ไม่มีอะไรเปลี่ยน']; }
        else {
            $notice = ['ok', ($r['old'] ? 'เปลี่ยนตัวสินค้าของ ' . (string)$r['mat']['mat_code'] . ' จาก ' . implode(', ', $r['old']) . ' เป็น '
                                        : 'ผูก ' . (string)$r['mat']['mat_code'] . ' → ')
                           . (string)$r['llp']['llp_code'] . ' (' . (string)$r['llp']['llp_name'] . ') แล้ว'
                           . ($r['dropped'] ? ' · ถอด IC ' . implode(', ', $r['dropped']) : '')
                           . (!$r['llp']['is_set'] ? ' · ⚠ ตัวสินค้านี้ยังไม่ตั้ง CatID/CharID — ออกรหัส IC ไม่ได้จนกว่าจะตั้ง' : '')];
        }
    }
    if ($action === 'unmap_llp_one') {
        $r = smUnmapLlp($pdo, (string)($_POST['mat'] ?? ''), (string)($_POST['llp'] ?? ''), $user);
        $notice = $r['ok'] ? ['ok', 'ถอด ' . strtoupper(trim((string)($_POST['llp'] ?? ''))) . ' ออกจาก '
                                  . mangoNormalizeCode((string)($_POST['mat'] ?? '')) . ' แล้ว']
                           : ['bad', $r['error']];
    }
    if ($action === 'attach_ic_one') {
        $r = smAttachIc($pdo, (string)($_POST['mat'] ?? ''), (string)($_POST['ic'] ?? ''), $user);
        $notice = $r['ok'] ? ['ok', 'ผูก ' . (string)$r['mat']['mat_code'] . ' → ' . (string)$r['ic']['ic_code'] . ' แล้ว']
                           : ['bad', $r['error']];
    }
    if ($action === 'detach_ic_one') {
        $r = smDetachIc($pdo, (string)($_POST['mat'] ?? ''), (string)($_POST['ic'] ?? ''), $user);
        $notice = $r['ok'] ? ['ok', 'ถอด IC ' . strtoupper(trim((string)($_POST['ic'] ?? ''))) . ' แล้ว (ตัวสินค้ายังผูกอยู่)']
                           : ['bad', $r['error']];
    }
    if ($action === 'primary_one') {
        $r = smSetPrimary($pdo, (string)($_POST['mat'] ?? ''), (string)($_POST['ic'] ?? ''), $user);
        $notice = $r['ok'] ? ['ok', 'ตั้ง ' . strtoupper(trim((string)($_POST['ic'] ?? ''))) . ' เป็น IC หลักแล้ว'] : ['bad', $r['error']];
    }

    // ── ไฟล์ผูกแบบง่าย (Mango/LLP/IC) ──────────────────────────────────────
    if ($action === 'preview') {
        if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $notice = ['bad', 'ยังไม่ได้เลือกไฟล์ หรืออัปโหลดไม่สำเร็จ'];
        } else {
            $token = bin2hex(random_bytes(8));
            $tmp   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cnx_sm_' . $token . '.xlsx';
            if (!move_uploaded_file($_FILES['file']['tmp_name'], $tmp)) {
                $notice = ['bad', 'ย้ายไฟล์ที่อัปโหลดไม่สำเร็จ'];
            } else {
                try {
                    $plan          = smBuildPlan($pdo, $tmp);
                    $plan['token'] = $token;
                    $plan['name']  = (string)($_FILES['file']['name'] ?? 'ไฟล์');
                    smStash('sm_import', $token, $tmp);
                } catch (Throwable $ex) {
                    @unlink($tmp);
                    $notice = ['bad', 'อ่านไฟล์ไม่สำเร็จ: ' . $ex->getMessage()];
                }
            }
        }
    }
    if ($action === 'commit') {
        $token = (string)($_POST['token'] ?? '');
        $path  = smStashPath($token);
        if ($path === null) {
            $notice = ['bad', 'ไม่พบไฟล์ที่ตรวจไว้ — อัปโหลดใหม่อีกครั้ง'];
        } else {
            try {
                $p = smBuildPlan($pdo, $path);      // คำนวณใหม่ กัน DB ขยับระหว่างกดยืนยัน
                $n = smCommitPlan($pdo, $p, $user);
                $b = $_SESSION['sm_import'] ?? [];
                @unlink($path);
                unset($b[$token]);
                $_SESSION['sm_import'] = $b;
                $notice = [$p['errors'] ? 'warn' : 'ok',
                           'นำเข้าเรียบร้อย — ผูกตัวสินค้าเพิ่ม ' . number_format($n['llp']) . ' คู่'
                           . ($n['ic'] > 0 ? ' · ผูก IC เพิ่ม ' . number_format($n['ic']) : '')
                           . ($p['errors'] ? ' · ข้ามบรรทัดที่มีปัญหา ' . count($p['errors']) . ' บรรทัด' : '')];
            } catch (Throwable $ex) {
                $notice = ['bad', 'นำเข้าไม่สำเร็จ: ' . $ex->getMessage()];
            }
        }
    }
    if ($action === 'discard') {
        $token = (string)($_POST['token'] ?? '');
        $path  = smStashPath($token);
        if ($path !== null) { @unlink($path); }
        $b = $_SESSION['sm_import'] ?? [];
        unset($b[$token]);
        $_SESSION['sm_import'] = $b;
        $notice = ['warn', 'ยกเลิกการนำเข้าแล้ว — ไม่มีอะไรถูกบันทึก'];
    }

    // ── ย้ายยอด ────────────────────────────────────────────────────────────
    if ($action === 'migrate') {
        $mp = (int)($_POST['mig_project'] ?? 0);
        if ($mp > 0 && !isset($projOpt[$mp])) { $mp = 0; }
        $sp = $_POST['sp'] ?? [];
        $mq = mangoNormalizeCode((string)($_POST['mig_q'] ?? ''));
        try {
            $n = smMigrate($pdo, $mp, $user, is_array($sp) ? $sp : [], $mq);
            $notice = $n['rows'] === 0
                ? ['warn', 'ไม่มีอะไรให้ย้าย — ทุกรหัสที่มี IC แล้วไม่ได้ถือยอดในขอบเขตนี้']
                : ['ok', 'ย้ายยอดเรียบร้อย — ' . smMigrateMsg($n)];
        } catch (Throwable $ex) {
            $notice = ['bad', 'ย้ายยอดไม่สำเร็จ — ไม่มีอะไรถูกเปลี่ยน: ' . $ex->getMessage()];
        }
    }
    if ($action === 'migrate_one') {
        $mat = mangoNormalizeCode((string)($_POST['mat'] ?? ''));
        try {
            $pl = smMigratePlan($pdo, $fProj, $mat);
            $needSplit = 0;
            foreach ($pl['rows'] as $r) { if ($r['split']) { $needSplit++; } }
            if (!$pl['rows']) {
                $notice = ['warn', $mat . ' ไม่มียอด/รายการที่ต้องย้าย'
                                 . ($pl['blockers'] ? ' (' . (string)$pl['blockers'][0]['reason'] . ')' : '')];
            } elseif ($needSplit > 0) {
                $notice = ['warn', $mat . ' มีหลาย IC — ต้องระบุยอดแยกต่อ IC ก่อน: กรอกในแผนการย้าย (ข้อ 4) ด้านล่าง'];
                $_GET['mig']   = '1';
                $_GET['mig_q'] = $mat;
            } else {
                $n = smMigrate($pdo, $fProj, $user, [], $mat);
                $notice = ['ok', 'ยกยอด ' . $mat . ' ไปรหัส IC แล้ว — ' . smMigrateMsg($n)];
            }
        } catch (Throwable $ex) {
            $notice = ['bad', 'ยกยอดไม่สำเร็จ — ไม่มีอะไรถูกเปลี่ยน: ' . $ex->getMessage()];
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// ข้อมูลสำหรับจอ
// ═══════════════════════════════════════════════════════════════════════════
$q     = trim((string)($_REQUEST['q'] ?? ''));
$scope = (string)($_REQUEST['scope'] ?? 'stock');
$state = (string)($_REQUEST['state'] ?? '');
$fL1   = strtoupper(trim((string)($_REQUEST['l1'] ?? '')));
$page  = max(1, (int)($_REQUEST['page'] ?? 1));
$mig   = (string)($_GET['mig'] ?? '') === '1';
$migQ  = mangoNormalizeCode((string)($_GET['mig_q'] ?? ''));
if (!in_array($scope, ['stock', 'doc', 'all'], true)) { $scope = 'stock'; }
if (!in_array($state, ['', 'no_llp', 'llp_only', 'has_ic', 'multi_ic', 'multi_llp'], true)) { $state = ''; }

$stats = $ready ? smStats($pdo, $fProj) : null;
$found = 0; $rows = []; $pages = 1; $l1s = [];
if ($ready) {
    $filter = ['q' => $q, 'scope' => $scope, 'state' => $state, 'l1' => $fL1, 'project' => $fProj];
    $found  = smCount($pdo, $filter);
    $rows   = smRows($pdo, $filter + ['limit' => SM_PER_PAGE, 'offset' => ($page - 1) * SM_PER_PAGE]);
    $pages  = max(1, (int)ceil($found / SM_PER_PAGE));
    $l1s    = $pdo->query('SELECT l1_code, l1_name FROM l1_groups WHERE is_active = 1 ORDER BY sort_order, l1_code')->fetchAll();
}
$migPlan = ($ready && $mig) ? smMigratePlan($pdo, $fProj, $migQ) : null;

$catLabels  = icCatLabels();
$charLabels = icCharLabels();

/** ลิงก์ของจอนี้ที่คงค่ากรองไว้ */
function smUrl(array $over = []): string {
    $qs = array_merge([
        'q'       => (string)($_REQUEST['q'] ?? ''),
        'scope'   => (string)($_REQUEST['scope'] ?? 'stock'),
        'state'   => (string)($_REQUEST['state'] ?? ''),
        'l1'      => (string)($_REQUEST['l1'] ?? ''),
        'project' => (string)($_REQUEST['project'] ?? ''),
        'page'    => (string)($_REQUEST['page'] ?? '1'),
    ], $over);
    $qs = array_filter($qs, function ($v) { return $v !== '' && $v !== null && $v !== '0'; });
    return uiUrl('setup_master.php' . ($qs ? '?' . http_build_query($qs) : ''));
}

/** hidden input ค่ากรองปัจจุบัน — ใส่ในฟอร์ม POST เล็ก ๆ ให้กลับมาหน้าเดิมหลังบันทึก */
$keep = '';
foreach (['q' => $q, 'scope' => $scope, 'state' => $state, 'l1' => $fL1,
          'project' => (string)$fProj, 'page' => (string)$page] as $k => $v) {
    $keep .= '<input type="hidden" name="' . $k . '" value="' . e($v) . '">';
}

/**
 * ก้อน HTML ของการผูกทั้งหมดของ Mango หนึ่งตัว (PHP ตอนโหลดหน้า · JS วาดแบบเดียวกันหลังแก้)
 * โครง: LLP (ขั้น 1) → IC ใต้มัน (ขั้น 2)
 */
function smMapCell(array $r, string $keep): string {
    if (!$r['llps']) { return '<span class="small">— ยังไม่ผูกตัวสินค้า —</span>'; }
    $h = '';
    foreach ($r['llps'] as $b) {
        $h .= '<div class="lblk">';
        $h .= '<div class="lhd"><span class="mono"><b>' . e($b['llp_code']) . '</b></span> ' . e($b['llp_name']);
        if ($b['missing'])                  { $h .= ' <span class="pill p-bad">ไม่พบใน llp_products</span>'; }
        elseif ((int)$b['is_active'] !== 1) { $h .= ' <span class="pill p-bad">ปิดใช้งาน</span>'; }
        if (!$b['missing']) {
            if ($b['is_set']) {
                $h .= ' <span class="pill ' . ($b['cat_id'] === 'C01' ? 'p-warn' : 'p-muted') . '">' . e((string)$b['cat_id']) . '</span>'
                    . ' <span class="pill p-muted">' . e((string)$b['char_id']) . '</span>';
            } else {
                $h .= ' <span class="pill p-bad" title="ออกรหัส IC ใต้ตัวสินค้านี้ไม่ได้จนกว่าจะตั้ง">ยังไม่ตั้ง Cat/Char</span>';
            }
            $h .= '<div class="small">' . e($b['l1_name']) . ' › ' . e($b['l2_name']) . '</div>';
        }
        $h .= '</div>';

        foreach ($b['ics'] as $ic) {
            $h .= '<div class="icrow' . ($ic['is_primary'] ? ' prim' : '') . '">';
            $h .= '<span class="mono"><b>' . e($ic['ic_code']) . '</b></span>'
                . ' <a href="' . e(uiUrl(APP_BASE . '/ic_edit.php?ic=' . urlencode((string)$ic['ic_code']))) . '" title="แก้ไข IC (ชื่อ/สเปก/หน่วย)">✏️</a>';
            if ($ic['is_primary']) { $h .= ' <span class="pill p-info" title="รับบรรทัดเอกสารเดิม/ราคาที่ล็อก">หลัก</span>'; }
            if ($ic['missing'])    { $h .= ' <span class="pill p-bad">ไม่พบใน ic_items</span>'; }
            else {
                $h .= ' ' . e($ic['ic_name']) . ' <span class="small">· ' . e($ic['unit_name']) . '</span>';
                if (!smUnitSame((string)$r['unit'], (string)$ic['unit_name'])) {
                    $h .= ' <span class="pill p-warn" title="หน่วยของ Mango กับหน่วยเก็บของ IC ต่างกัน">หน่วยต่างกัน</span>';
                }
            }
            $h .= ' <span class="icact">';
            if (!$ic['is_primary']) {
                $h .= '<form method="post" class="inl" data-act="primary"><input type="hidden" name="csrf" value="' . e(csrfToken()) . '">'
                    . '<input type="hidden" name="action" value="primary_one"><input type="hidden" name="mat" value="' . e($r['mat_code']) . '">'
                    . '<input type="hidden" name="ic" value="' . e($ic['ic_code']) . '">' . $keep
                    . '<button type="submit" class="mini ghost">ตั้งเป็นหลัก</button></form> ';
            }
            $h .= '<form method="post" class="inl" data-act="detach_ic"><input type="hidden" name="csrf" value="' . e(csrfToken()) . '">'
                . '<input type="hidden" name="action" value="detach_ic_one"><input type="hidden" name="mat" value="' . e($r['mat_code']) . '">'
                . '<input type="hidden" name="ic" value="' . e($ic['ic_code']) . '">' . $keep
                . '<button type="submit" class="mini ghost" title="ถอด IC ตัวนี้ (ตัวสินค้ายังผูกอยู่)">✕ ถอด IC</button></form>';
            $h .= '</span></div>';
        }
        if (!$b['ics']) {
            $h .= '<div class="icrow none">ยังไม่ได้ออกรหัส IC ใต้ตัวสินค้านี้'
                . ' <span class="icact"><button type="button" class="mini" data-make="' . e($r['mat_code']) . '" data-llp="' . e($b['llp_code']) . '">🏷️ ออกรหัส IC</button></span></div>';
        }
        $h .= '<div class="lact">';
        if ($b['ics']) {
            // 1 Mango มีหลาย IC ได้ใต้ LLP เดียวกัน (มติ 45) — เช่น ขนาดเดิมแต่ "แบบยาว" · ตั้งต้นจากสเปกของ IC หลัก
            $base = (string)$b['ics'][0]['ic_code'];
            foreach ($b['ics'] as $ic) { if ($ic['is_primary']) { $base = (string)$ic['ic_code']; break; } }
            $h .= '<button type="button" class="mini ghost" data-make="' . e($r['mat_code']) . '" data-llp="' . e($b['llp_code'])
                . '" data-base="' . e($base) . '" title="ออก/ผูก IC อีกตัวใต้ตัวสินค้าเดิม (ของรหัสนี้มีหลายสเปก)">＋ เพิ่ม IC</button> ';
        }
        $h .= '<form method="post" class="inl" data-act="unmap_llp" onsubmit="return confirm(\'ถอด '
            . e($b['llp_code']) . ' ออกจาก ' . e($r['mat_code']) . ' ?\')">'
            . '<input type="hidden" name="csrf" value="' . e(csrfToken()) . '"><input type="hidden" name="action" value="unmap_llp_one">'
            . '<input type="hidden" name="mat" value="' . e($r['mat_code']) . '"><input type="hidden" name="llp" value="' . e($b['llp_code']) . '">' . $keep
            . '<button type="submit" class="mini ghost" title="ถอดตัวสินค้านี้ (พร้อม IC ใต้มัน)">✕ ถอดตัวสินค้า</button></form></div>';
        $h .= '</div>';
    }
    return $h;
}

uiHead('จัดการรหัสวัสดุ', 'ผูกรหัส Mango → ตัวสินค้า (LLP) → รหัส IC แล้วย้ายยอดให้ระบบแสดงเป็น IC', $user, '🗂️', uiIsEmbedded());
uiBackToAdmin('materials');
?>

<?php if ($notice !== null): ?>
  <div class="banner b-<?= $notice[0] === 'ok' ? 'ok' : ($notice[0] === 'warn' ? 'warn' : 'bad') ?>"><?= e($notice[1]) ?></div>
<?php endif; ?>

<?php if (!$ready): ?>
<div class="card">
  <h2><span class="num">!</span> ต้องอัปเดตโครงสร้างฐานข้อมูลก่อน</h2>
  <p class="small">
    ยังไม่มีตาราง <span class="mono">mango_ic_map</span> (การผูก Mango → LLP → IC) — กดปุ่มนี้ครั้งเดียว
    ระบบจะสร้าง/อัปเกรดให้ (เท่ากับรัน <span class="mono">db/migrate_setup_master.php</span>) ไม่แตะข้อมูลเดิม
  </p>
  <form method="post" style="margin-top:10px">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="action" value="upgrade_schema">
    <button type="submit">🛠 อัปเดตโครงสร้างฐานข้อมูล</button>
  </form>
</div>
<?php uiFoot(); exit; endif; ?>


<div class="card">
  <h2><span class="num">1</span> สถานะ
    <span class="sp">
      <?php if ($fProj > 0): ?>ยอดเฉพาะโครงการ <?= e($projOpt[$fProj]) ?><?php else: ?>ยอดรวมทุกโครงการ<?php endif; ?>
      · บันได: L1 <?= $stats['l1_total'] ?> · L2 <?= $stats['l2_total'] ?> · ตัวสินค้า <?= number_format($stats['llp_total']) ?>
    </span>
  </h2>

  <div class="row" style="align-items:stretch">
    <div class="stat"><b><?= number_format($stats['total']) ?></b><span>รหัส Mango ทั้งหมด</span></div>
    <div class="stat"><b><?= number_format($stats['with_llp']) ?></b><span>ผูกตัวสินค้า (LLP) แล้ว</span></div>
    <div class="stat <?= $stats['no_llp'] > 0 ? 'warn' : '' ?>"><b><?= number_format($stats['no_llp']) ?></b><span>ยังไม่ผูก LLP</span></div>
    <div class="stat"><b><?= number_format($stats['with_ic']) ?></b><span>ออกรหัส IC แล้ว</span></div>
    <div class="stat <?= $stats['rows_on_mango'] > 0 ? 'bad' : '' ?>"><b><?= number_format($stats['rows_on_mango']) ?></b><span>แถวยอดที่ยังอยู่บน Mango<br>(<?= fmtQ($stats['on_hand_on_mango']) ?> หน่วย)</span></div>
    <div style="flex:1;min-width:250px" class="small">
      <b>ของที่ถือยอดจริง <?= number_format($stats['with_stock']) ?> รหัส</b> →
      ผูก LLP แล้ว <?= number_format($stats['stock_llp']) ?> ·
      ออก IC แล้ว <?= number_format($stats['stock_ic']) ?> ·
      <span class="<?= $stats['stock_no_llp'] > 0 ? 'bad-t' : '' ?>">ยังไม่ผูก <?= number_format($stats['stock_no_llp']) ?></span><br>
      ลำดับงาน: <b>นำเข้าไฟล์</b> (ข้อ 2) → <b>ออกรหัส IC จากไฟล์</b> (ข้อ 2b) → <b>ผูก/แก้รายตัว</b> (ข้อ 3) → <b>ย้ายยอด</b> (ข้อ 4)<br>
      กติกา: 1 Mango มีตัวสินค้า (LLP) ได้ตัวเดียว · มีหลาย IC ได้ใต้ LLP นั้น (มติ 45)<br>
      <?php if ($stats['multi_llp'] > 0): ?>
        <span class="pill p-bad">รหัส Mango <?= number_format($stats['multi_llp']) ?> ตัวผูกไว้หลายตัวสินค้า</span>
        — ผิดกติกา ต้องเลือกให้เหลือตัวเดียว: <a href="<?= smUrl(['state' => 'multi_llp', 'scope' => 'all', 'page' => '1']) ?>">ดูรายการ</a><br>
      <?php endif; ?>
      <?php if ($stats['llp_unset'] > 0): ?>
        <span class="pill p-warn">ตัวสินค้า <?= number_format($stats['llp_unset']) ?> ตัวยังไม่ตั้ง CatID/CharID</span>
        — ออกรหัส IC ใต้มันไม่ได้ (มติ 32) ตั้งที่ <a href="<?= APP_BASE ?>/llp_master.php">ตั้งค่าตัวสินค้า (LLP)</a>
      <?php else: ?>
        <span class="pill p-ok">ตัวสินค้าทุกตัวตั้ง CatID/CharID ครบแล้ว</span>
      <?php endif; ?>
    </div>
  </div>
</div>


<div class="card">
  <h2><span class="num">2</span> นำเข้าไฟล์จัดหมวดจากฝ่ายจัดซื้อ
    <span class="sp">ไฟล์ "สร้าง LLP.xlsx" — ชีต LL Code + Create_LLP_All Mat + IC_All Mat</span>
  </h2>

  <?php if ($wb === null): ?>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
      <input type="hidden" name="action" value="wb_preview">
      <?= $keep ?>
      <div class="row" style="align-items:flex-end">
        <div style="flex:1;min-width:260px">
          <label class="fld">ที่อยู่ไฟล์บนเครื่องเซิร์ฟเวอร์ (เว้นว่างถ้าจะอัปโหลด)</label>
          <input type="text" name="wbpath" value="<?= e(llpDefaultFile()) ?>" style="width:100%" class="mono">
        </div>
        <div style="flex:1;min-width:220px">
          <label class="fld">หรืออัปโหลดไฟล์ .xlsx</label>
          <input type="file" name="wbfile" accept=".xlsx" style="width:100%">
        </div>
        <div><button type="submit">🔍 ตรวจไฟล์</button></div>
      </div>
      <p class="small" style="margin-top:8px">
        ระบบจะอ่านบันได L1/L2 · ตัวสินค้า (LLP) · การผูก Mango → LLP · หน่วยเก็บ แล้วสรุปให้ดูก่อน ยังไม่เขียนอะไร ·
        รันซ้ำได้ (เพิ่มของใหม่ + แก้ชื่อ ไม่ลบการผูกที่ทำเองในจอ)
      </p>
    </form>

  <?php else: ?>
    <div class="banner b-warn">ตรวจไฟล์แล้ว — <b>ยังไม่บันทึกอะไรทั้งสิ้น</b> <span class="small mono"><?= e($wb['path']) ?></span></div>
    <div class="row" style="margin:11px 0">
      <div class="stat"><b><?= $wb['l1'] ?> / <?= $wb['l2'] ?></b><span>กลุ่มใหญ่ (L1) / หมวด (L2)</span></div>
      <div class="stat"><b><?= number_format($wb['llp']) ?></b><span>ตัวสินค้าในไฟล์<br>(ใหม่ <?= number_format($wb['llp_new']) ?>)</span></div>
      <div class="stat"><b><?= number_format($wb['pair_use']) ?></b><span>คู่ Mango → LLP ที่ใช้ได้<br>(ผูกเพิ่ม <?= number_format($wb['pair_new']) ?>)</span></div>
      <div class="stat <?= $wb['db_no_llp'] > 0 ? 'warn' : '' ?>"><b><?= number_format($wb['db_no_llp']) ?></b><span>รหัสในระบบที่ไฟล์<br>ยังไม่ได้จัดหมวด</span></div>
      <div class="stat"><b><?= number_format($wb['cc_set']) ?></b><span>ตั้ง CatID/CharID ให้ได้<br>(ไม่ตรงกัน <?= $wb['cc_mixed'] ?>)</span></div>
      <div class="stat"><b><?= $wb['units_new'] ?></b><span>หน่วยเก็บใหม่</span></div>
    </div>
    <p class="small">
      ข้ามแถวที่ยังจัดหมวดไม่เสร็จ (LLP ไม่ครบ 8 หลัก) <b><?= number_format($wb['skip']['llp_sn']) ?></b> แถว ·
      รหัสในไฟล์ที่ระบบยังไม่มีในทะเบียน <b><?= number_format($wb['mat_not_db']) ?></b> ·
      LLP ที่ยังไม่มี Mango ผูก <b><?= number_format($wb['cc_no_mango']) ?></b>
      <?php if ($wb['skip']['multi'] > 0): ?>
        · รหัสเดียวมีหลาย LLP ในไฟล์ (ใช้แถวแรก) <b><?= number_format($wb['skip']['multi']) ?></b>
      <?php endif; ?>
      <?php if ($wb['skip_sample']): ?>
        <br>ตัวอย่างแถวที่ข้าม:
        <?php foreach (array_slice($wb['skip_sample'], 0, 6) as $x): ?>
          <span class="mono"><?= e($x['mat']) ?></span> → "<?= e($x['llp']) ?>" ·
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if ($wb['pair_conflict'] > 0): ?>
        <br><span class="pill p-warn">LLP ในไฟล์ไม่ตรงกับที่ผูกไว้ <?= number_format($wb['pair_conflict']) ?> รหัส</span>
        — ไม่เปลี่ยนให้ (1 Mango มีตัวสินค้าได้ตัวเดียว · IC ใต้ตัวเดิมจะหลุด) เปลี่ยนเองที่ข้อ 3:
        <?php foreach ($wb['conflict_sample'] as $x): ?>
          <span class="mono"><?= e($x['mat']) ?></span> (ผูก <?= e($x['db']) ?> · ไฟล์ <?= e($x['file']) ?>) ·
        <?php endforeach; ?>
      <?php endif; ?>
    </p>
    <form method="post" class="row" style="margin-top:12px">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
      <input type="hidden" name="token" value="<?= e((string)$wb['token']) ?>">
      <input type="hidden" name="wbpath" value="<?= e((string)$wb['path']) ?>">
      <input type="hidden" name="charcat" value="<?= $wb['charcat'] ? '1' : '0' ?>">
      <?= $keep ?>
      <div><button type="submit" name="action" value="wb_commit">✓ ยืนยันนำเข้า</button></div>
      <div class="small">เขียนในทรานแซกชันเดียว — พลาดตรงไหน rollback ทั้งชุด</div>
    </form>
  <?php endif; ?>

  <details style="margin-top:10px">
    <summary>ไฟล์ผูกแบบง่าย (รหัส Mango / รหัส LLP / รหัส IC) — สำหรับแก้เป็นชุดเล็ก ๆ</summary>
    <div class="row" style="margin-top:9px">
      <a href="<?= uiUrl('setup_master.php?export=xlsx&scope=stock' . ($fProj > 0 ? '&project=' . $fProj : '')) ?>" data-cnx-no-embed>
        <button type="button" class="ghost">⤓ โหลดไฟล์ตั้งต้น — เฉพาะที่ถือยอด (<?= number_format($stats['with_stock']) ?>)</button></a>
      <a href="<?= uiUrl('setup_master.php?export=xlsx&scope=all') ?>" data-cnx-no-embed>
        <button type="button" class="ghost">⤓ ทั้งทะเบียน (<?= number_format($stats['total']) ?>)</button></a>
    </div>
    <?php if ($plan === null): ?>
      <form method="post" enctype="multipart/form-data" class="row" style="align-items:flex-end;margin-top:9px">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="preview">
        <?= $keep ?>
        <div style="flex:1;min-width:240px">
          <label class="fld">ไฟล์ .xlsx ที่กรอกแล้ว</label>
          <input type="file" name="file" accept=".xlsx" required style="width:100%">
        </div>
        <div><button type="submit" class="ghost">ตรวจไฟล์ก่อนนำเข้า</button></div>
      </form>
    <?php else: $willDo = count($plan['new_llp']) + count($plan['new_ic']); ?>
      <div class="banner b-warn" style="margin-top:9px">ตรวจไฟล์ <b><?= e($plan['name']) ?></b> แล้ว — <b>ยังไม่บันทึกอะไร</b></div>
      <div class="row" style="margin:11px 0">
        <div class="stat"><b><?= number_format(count($plan['new_llp'])) ?></b><span>ผูกตัวสินค้าเพิ่ม</span></div>
        <div class="stat"><b><?= number_format(count($plan['new_ic'])) ?></b><span>ผูก IC เพิ่ม</span></div>
        <div class="stat <?= $plan['multi'] ? 'warn' : '' ?>"><b><?= number_format($plan['multi']) ?></b><span>Mango ที่จะมีหลาย IC<br>(ย้ายยอดต้องแยกยอด)</span></div>
        <div class="stat"><b><?= number_format($plan['same']) ?></b><span>ผูกอยู่แล้ว</span></div>
        <div class="stat"><b><?= number_format($plan['blank']) ?></b><span>เว้นว่าง (ข้าม)</span></div>
        <div class="stat <?= $plan['errors'] ? 'bad' : '' ?>"><b><?= number_format(count($plan['errors'])) ?></b><span>บรรทัดมีปัญหา</span></div>
      </div>
      <?php if ($plan['errors']): ?>
        <div class="scroll" style="max-height:210px;margin-bottom:11px">
          <table>
            <tr><th>บรรทัด</th><th>รหัส Mango</th><th>ปัญหา</th></tr>
            <?php foreach (array_slice($plan['errors'], 0, SM_ERR_SHOW) as $er): ?>
              <tr><td class="num"><?= (int)$er['line'] ?></td><td class="mono"><?= e((string)$er['mat']) ?></td>
                  <td class="small"><?= e((string)$er['msg']) ?></td></tr>
            <?php endforeach; ?>
          </table>
        </div>
      <?php endif; ?>
      <form method="post" class="row">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="token" value="<?= e((string)$plan['token']) ?>">
        <?= $keep ?>
        <div><button type="submit" name="action" value="commit" <?= $willDo === 0 ? 'disabled' : '' ?>>✓ ยืนยัน <?= number_format($willDo) ?> รายการ</button></div>
        <div><button type="submit" name="action" value="discard" class="ghost">ยกเลิก</button></div>
      </form>
    <?php endif; ?>
  </details>
</div>


<div class="card">
  <h2><span class="num">2b</span> ออกรหัส IC จากไฟล์ แล้วผูกกับรหัส Mango
    <span class="sp">ไฟล์เดียวกับข้อ 2 — ชีต IC_All Mat (ขนาด · ยี่ห้อ · หน่วยเก็บ · ชื่อในPO)</span>
  </h2>

  <?php if ($icp === null): ?>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
      <input type="hidden" name="action" value="ic_preview">
      <?= $keep ?>
      <div class="row" style="align-items:flex-end">
        <div style="flex:1;min-width:260px">
          <label class="fld">ที่อยู่ไฟล์บนเครื่องเซิร์ฟเวอร์ (เว้นว่างถ้าจะอัปโหลด)</label>
          <input type="text" name="icpath" value="<?= e(llpDefaultFile()) ?>" style="width:100%" class="mono">
        </div>
        <div style="flex:1;min-width:220px">
          <label class="fld">หรืออัปโหลดไฟล์ .xlsx</label>
          <input type="file" name="icfile" accept=".xlsx" style="width:100%">
        </div>
        <div><button type="submit">🔍 ตรวจไฟล์</button></div>
      </div>
      <p class="small" style="margin-top:8px">
        ทำหลังนำเข้าข้อ 2 — ออก IC ใต้ตัวสินค้าที่ผูกไว้แล้ว: <b>ขนาดมาตรฐาน</b> (ใช้ร่วมหลายตัวสินค้า เช่น 1/2" · 6 มม.) เข้าพจนานุกรมขนาด ·
        รุ่น/เบอร์/S/N/สเปกเฉพาะตัวเป็น <b>คุณสมบัติเพิ่ม</b> ของตัวสินค้านั้น · ชื่อ IC = ชื่อในPO ·
        แล้วผูกกับรหัส Mango ที่ยังไม่มี IC · ไม่ย้ายยอด (ทำที่ข้อ 4) · รันซ้ำได้
      </p>
    </form>

  <?php else: $sb = $icp['sys_by']; $kb = $icp['stock_by']; ?>
    <div class="banner b-warn">ตรวจไฟล์แล้ว — <b>ยังไม่บันทึกอะไรทั้งสิ้น</b> <span class="small mono" style="word-break:break-all"><?= e($icp['path']) ?></span></div>
    <div class="row" style="margin:11px 0">
      <div class="stat"><b><?= number_format($icp['ic_new']) ?></b><span>รหัส IC ใหม่<br>(ในแผน <?= number_format($icp['ic_total']) ?>)</span></div>
      <div class="stat"><b><?= number_format($icp['map_new']) ?></b><span>ผูก Mango → IC<br>(ที่ถือยอด <?= number_format($kb['map']) ?>)</span></div>
      <div class="stat <?= $sb['llp_unset'] > 0 ? 'warn' : '' ?>"><b><?= number_format($sb['llp_unset']) ?></b><span>รหัสในระบบที่ติด<br>CatID/CharID (ถือยอด <?= number_format($kb['llp_unset']) ?>)</span></div>
      <div class="stat <?= $sb['no_llp'] > 0 ? 'warn' : '' ?>"><b><?= number_format($sb['no_llp'] + $sb['not_in_file']) ?></b><span>รหัสในระบบที่ยังไม่มี LLP<br>(ถือยอด <?= number_format($kb['no_llp'] + $kb['not_in_file']) ?>)</span></div>
      <div class="stat"><b><?= number_format($sb['has_ic']) ?></b><span>ผูก IC ไว้แล้ว<br>(ไม่แตะ)</span></div>
    </div>
    <p class="small">
      พจนานุกรมที่จะเพิ่ม: ขนาด <b><?= $icp['size_new'] ?></b> (ขนาดมาตรฐานทั้งไฟล์ <?= $icp['std_sizes'] ?>) ·
      ยี่ห้อ <b><?= $icp['brand_new'] ?></b> · หน่วยเก็บ <b><?= $icp['unit_new'] ?></b> ·
      คุณสมบัติเพิ่ม <b><?= number_format($icp['extra_new']) ?></b> ·
      IC ที่รวมรหัส Mango ซ้ำ <b><?= number_format($icp['ic_multi']) ?></b> · ตั้งธง Serial ตาม Mango <b><?= number_format($icp['ic_serial']) ?></b> ·
      รหัสนอกทะเบียนระบบที่ได้ IC ด้วย <b><?= number_format($icp['by']['issued']) ?></b>
      <?php if ($icp['block_llp'] > 0): ?>
        <br><span class="pill p-warn">ตัวสินค้า <?= number_format($icp['block_llp']) ?> ตัวยังไม่ตั้ง CatID/CharID</span>
        — รหัสใต้มันยังออก IC ไม่ได้ (มติ 32) ตั้งที่ <a href="<?= APP_BASE ?>/llp_master.php">ตั้งค่าตัวสินค้า (LLP)</a>
        แล้วกลับมาตรวจไฟล์ซ้ำ ระบบออกส่วนที่เหลือให้ (รายชื่ออยู่ในไฟล์ตรวจผล ชีต "รอตั้ง Cat-Char")
      <?php endif; ?>
    </p>
    <form method="post" class="row" style="margin-top:12px">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
      <input type="hidden" name="token" value="<?= e((string)$icp['token']) ?>">
      <input type="hidden" name="icpath" value="<?= e((string)$icp['path']) ?>">
      <?= $keep ?>
      <div><button type="submit" name="action" value="ic_commit" <?= ($icp['ic_new'] + $icp['map_new']) === 0 ? 'disabled' : '' ?>>✓ ยืนยันออกรหัส <?= number_format($icp['ic_new']) ?> IC + ผูก <?= number_format($icp['map_new']) ?> รหัส</button></div>
      <div><button type="submit" name="action" value="ic_report" class="ghost">⤓ ไฟล์ตรวจผล (.xlsx)</button></div>
      <?php if ((string)$icp['token'] !== ''): ?>
        <div><button type="submit" name="action" value="ic_discard" class="ghost">ยกเลิก</button></div>
      <?php else: ?>
        <div><a href="<?= smUrl() ?>"><button type="button" class="ghost">ยกเลิก</button></a></div>
      <?php endif; ?>
      <div class="small">เขียนในทรานแซกชันเดียว — พลาดตรงไหน rollback ทั้งชุด</div>
    </form>
  <?php endif; ?>
</div>


<div class="card">
  <h2><span class="num">3</span> รหัส Mango <span class="sp">พบ <?= number_format($found) ?> รหัส · หน้า <?= $page ?>/<?= $pages ?></span></h2>

  <form method="get" class="row">
    <?php if (uiIsEmbedded()): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
    <div style="flex:1;min-width:200px">
      <label class="fld">รหัส Mango / ชื่อ / กลุ่มย่อย / รหัส LLP / IC</label>
      <input type="text" name="q" value="<?= e($q) ?>" style="width:100%" placeholder="พิมพ์แล้วกด Enter">
    </div>
    <div>
      <label class="fld">แสดง</label>
      <select name="scope" onchange="this.form.submit()">
        <option value="stock" <?= $scope === 'stock' ? 'selected' : '' ?>>เฉพาะที่ถือยอด (<?= number_format($stats['with_stock']) ?>)</option>
        <option value="doc"   <?= $scope === 'doc'   ? 'selected' : '' ?>>เฉพาะที่เคยอยู่ในเอกสาร</option>
        <option value="all"   <?= $scope === 'all'   ? 'selected' : '' ?>>ทั้งทะเบียน (<?= number_format($stats['total']) ?>)</option>
      </select>
    </div>
    <div>
      <label class="fld">สถานะการผูก</label>
      <select name="state" onchange="this.form.submit()">
        <option value="">— ทั้งหมด —</option>
        <option value="no_llp"   <?= $state === 'no_llp'   ? 'selected' : '' ?>>ยังไม่ผูก LLP</option>
        <option value="llp_only" <?= $state === 'llp_only' ? 'selected' : '' ?>>ผูก LLP แล้ว · ยังไม่มี IC</option>
        <option value="has_ic"   <?= $state === 'has_ic'   ? 'selected' : '' ?>>มี IC แล้ว</option>
        <option value="multi_ic" <?= $state === 'multi_ic' ? 'selected' : '' ?>>มีหลาย IC</option>
        <?php if ($stats['multi_llp'] > 0 || $state === 'multi_llp'): ?>
          <option value="multi_llp" <?= $state === 'multi_llp' ? 'selected' : '' ?>>⚠ ผูกหลายตัวสินค้า (ต้องแก้)</option>
        <?php endif; ?>
      </select>
    </div>
    <div>
      <label class="fld">กลุ่มใหญ่ (L1)</label>
      <select name="l1" onchange="this.form.submit()">
        <option value="">— ทั้งหมด —</option>
        <?php foreach ($l1s as $g): ?>
          <option value="<?= e((string)$g['l1_code']) ?>" <?= (string)$g['l1_code'] === $fL1 ? 'selected' : '' ?>>
            <?= e((string)$g['l1_code']) ?> · <?= e((string)$g['l1_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="fld">โครงการ (ยอด)</label>
      <select name="project" onchange="this.form.submit()">
        <option value="0">— ทุกโครงการ —</option>
        <?php foreach ($projOpt as $pid => $lb): ?>
          <option value="<?= $pid ?>" <?= $pid === $fProj ? 'selected' : '' ?>><?= e($lb) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="align-self:flex-end"><button type="submit">ค้นหา</button></div>
  </form>

  <?php if (empty($rows)): ?>
    <p class="small" style="margin-top:11px">ไม่พบรหัสที่ตรงเงื่อนไข</p>
  <?php else: ?>
    <div class="scroll" style="margin-top:11px">
      <table id="smTable">
        <tr>
          <th style="width:130px">รหัส Mango</th><th>ชื่อวัสดุ · หน่วย</th>
          <th class="num" style="width:90px">คงเหลือ<br><span style="font-weight:400">(บน Mango)</span></th>
          <th>ตัวสินค้า (LLP) ที่ผูก → รหัส IC</th><th style="width:130px"></th>
        </tr>
        <?php foreach ($rows as $r):
            $code  = (string)$r['mat_code'];
            $holds = (int)$r['n_bal'] > 0 || (int)$r['n_pm'] > 0;
            $nLlp  = count($r['llps']);
            $nIc   = (int)$r['n_ic'];
        ?>
          <tr data-mat="<?= e($code) ?>" data-holds="<?= $holds ? 1 : 0 ?>" class="<?= $holds && $nLlp === 0 ? 'warnrow' : '' ?>">
            <td class="mono"><?= e($code) ?>
              <?php if ($r['subgroup_name'] !== null && $r['subgroup_name'] !== ''): ?>
                <div class="small"><?= e((string)$r['subgroup_name']) ?></div><?php endif; ?>
            </td>
            <td class="small c-name" data-name="<?= e((string)$r['name']) ?>" data-unit="<?= e((string)$r['unit']) ?>">
              <?= e((string)$r['name']) ?> · <?= e((string)$r['unit']) ?></td>
            <td class="num">
              <?php if ($holds): ?>
                <?= fmtQ($r['on_hand']) ?>
                <?php if ((float)$r['pending'] > 0): ?><div class="small">จอง <?= fmtQ($r['pending']) ?></div><?php endif; ?>
                <?php if ((int)$r['n_bal'] > 1): ?><div class="small"><?= (int)$r['n_bal'] ?> โครงการ</div><?php endif; ?>
              <?php else: ?><span class="small">—</span><?php endif; ?>
            </td>
            <td class="c-map"><?= smMapCell($r, $keep) ?></td>
            <td class="c-act">
              <button type="button" class="mini <?= $nLlp > 0 ? 'ghost' : '' ?>" data-pick="<?= e($code) ?>"
                      title="<?= $nLlp > 0 ? '1 รหัส Mango มีตัวสินค้าได้ตัวเดียว — เลือกตัวใหม่แทนตัวเดิม' : 'เลือกตัวสินค้า (LLP) ให้รหัสนี้' ?>">
                <?= $nLlp > 1 ? '⚠ เลือกให้เหลือตัวเดียว' : ($nLlp > 0 ? '🔁 เปลี่ยนตัวสินค้า' : '🔗 ผูกตัวสินค้า') ?></button>
              <?php if ($holds && $nIc === 1): ?>
                <form method="post" class="inl" onsubmit="return confirm('ยกยอดของ <?= e($code) ?> ไปรหัส IC ทั้งก้อน — ทำแล้วย้อนกลับเองไม่ได้ ยืนยันไหม?')">
                  <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                  <input type="hidden" name="action" value="migrate_one">
                  <input type="hidden" name="mat" value="<?= e($code) ?>">
                  <?= $keep ?>
                  <button type="submit" class="mini" title="มี IC เดียว — ยกยอดไปได้เลย">🚚 ยกยอดมา</button>
                </form>
              <?php elseif ($holds && $nIc > 1): ?>
                <a href="<?= smUrl(['mig' => '1', 'mig_q' => $code]) ?>#migrate"><button type="button" class="mini ghost" title="มีหลาย IC — ต้องกรอกยอดแยกก่อน">✂ แยกยอด</button></a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>

    <?php if ($pages > 1): ?>
      <div class="row" style="margin-top:11px;align-items:center">
        <?php if ($page > 1): ?>
          <a href="<?= smUrl(['page' => (string)($page - 1)]) ?>"><button type="button" class="ghost">‹ ก่อนหน้า</button></a>
        <?php endif; ?>
        <span class="small">หน้า <?= $page ?> จาก <?= $pages ?></span>
        <?php if ($page < $pages): ?>
          <a href="<?= smUrl(['page' => (string)($page + 1)]) ?>"><button type="button" class="ghost">ถัดไป ›</button></a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <details>
    <summary>ผูกโดยพิมพ์รหัสเอง (ไม่ใช้ตัวเลือก)</summary>
    <form method="post" class="row" style="margin-top:9px;align-items:flex-end">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
      <?= $keep ?>
      <div><label class="fld">รหัส Mango</label><input type="text" name="mat" required style="width:150px" class="mono"></div>
      <div><label class="fld">รหัส LLP (8 หลัก)</label><input type="text" name="llp" style="width:130px" class="mono"></div>
      <div><button type="submit" name="action" value="map_llp_one" class="ghost">ผูก/เปลี่ยนตัวสินค้า</button>
        <label class="small" style="display:block;margin-top:3px" title="1 Mango มีตัวสินค้าได้ตัวเดียว — ใส่ LLP อื่น = เปลี่ยน">
          <input type="checkbox" name="replace" value="1"> ยอมให้ IC ใต้ตัวเดิมหลุด (กรณีเปลี่ยน)</label></div>
      <div style="border-left:1px solid var(--line);padding-left:10px">
        <label class="fld">หรือรหัส IC (20 หลัก)</label><input type="text" name="ic" style="width:220px" class="mono"></div>
      <div><button type="submit" name="action" value="attach_ic_one" class="ghost">ผูก IC</button></div>
    </form>
  </details>
</div>


<div class="card" id="migrate">
  <h2><span class="num">4</span> ย้ายยอดสต๊อกจากรหัส Mango ไปรหัส IC
    <span class="sp">ทำหลังออกรหัส IC ครบ · ทั้งชุดในทรานแซกชันเดียว</span>
  </h2>

  <form method="get" class="row" style="align-items:flex-end">
    <?php if (uiIsEmbedded()): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
    <input type="hidden" name="mig" value="1">
    <input type="hidden" name="q" value="<?= e($q) ?>">
    <input type="hidden" name="scope" value="<?= e($scope) ?>">
    <input type="hidden" name="state" value="<?= e($state) ?>">
    <input type="hidden" name="l1" value="<?= e($fL1) ?>">
    <div>
      <label class="fld">ขอบเขต</label>
      <select name="project">
        <option value="0">— ทุกโครงการ —</option>
        <?php foreach ($projOpt as $pid => $lb): ?>
          <option value="<?= $pid ?>" <?= $pid === $fProj ? 'selected' : '' ?>><?= e($lb) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="fld">เฉพาะรหัส Mango (ว่าง = ทั้งหมด)</label>
      <input type="text" name="mig_q" value="<?= e($migQ) ?>" class="mono" style="width:170px" placeholder="เช่น PM080010200000">
    </div>
    <div><button type="submit" class="ghost">🔍 ดูแผนการย้าย (ยังไม่ทำอะไร)</button></div>
  </form>

  <p class="small" style="margin-top:8px">
    ย้าย/รวมไปเป็นของรหัส IC: ยอดคงเหลือ · ยอดรายประตู · วัสดุในโครงการ + ประตูจ่าย · ราคาหักเงิน ·
    <b>รายการในเอกสารทุกใบ</b> (→ IC หลัก — ชื่อ/หน่วยที่บันทึกไว้คงเดิม) · ราคาที่ล็อกในใบหักเงิน · แล้วคิดยอดจองใหม่<br>
    <b>IC เดียว</b> ยกทั้งก้อน · <b>หลาย IC</b> กรอกยอดแยกต่อ IC ด้านล่าง (ผลรวมต้องเท่ายอดคงเหลือ) ·
    IC ที่มียอดในโครงการนั้นอยู่แล้วจะถูก <b>บวกรวม</b>
  </p>

  <?php if ($migPlan !== null): $mr = $migPlan['rows']; $mb = $migPlan['blockers']; $ms = $migPlan['sum'];
        $splitRows = array_filter($mr, function ($r) { return $r['split']; });
        $wholeRows = array_filter($mr, function ($r) { return !$r['split']; }); ?>
    <div class="row" style="margin:11px 0">
      <div class="stat"><b><?= number_format($ms['rows']) ?></b><span>รายการที่จะย้าย<br>(โครงการ × Mango)</span></div>
      <div class="stat"><b><?= fmtQ($ms['on_hand']) ?></b><span>ยอดคงเหลือรวมที่ย้าย</span></div>
      <div class="stat <?= $ms['split'] ? 'warn' : '' ?>"><b><?= number_format($ms['split']) ?></b><span>หลาย IC<br>(ต้องกรอกยอดแยก)</span></div>
      <div class="stat <?= $ms['merge'] ? 'warn' : '' ?>"><b><?= number_format($ms['merge']) ?></b><span>บวกรวมกับยอด IC ที่มีอยู่</span></div>
      <div class="stat"><b><?= number_format($ms['doc']) ?></b><span>บรรทัดเอกสารที่เปลี่ยนรหัส</span></div>
      <div class="stat <?= $mb ? 'bad' : '' ?>"><b><?= number_format(count($mb)) ?></b><span>ยังย้ายไม่ได้<br>(ไม่มีรหัส IC)</span></div>
    </div>

    <?php if ($mb): ?>
      <div class="banner b-bad" style="font-weight:400">
        <b><?= count($mb) ?> รายการถือยอดอยู่แต่ยังไม่มีรหัส IC</b> — ย้ายได้เฉพาะที่ออกรหัส IC แล้ว
      </div>
      <div class="scroll" style="max-height:220px;margin-bottom:11px">
        <table>
          <tr><th>โครงการ</th><th>รหัส Mango</th><th>ชื่อ · หน่วย</th><th class="num">คงเหลือ</th><th>ติดตรงไหน</th><th></th></tr>
          <?php foreach (array_slice($mb, 0, SM_PLAN_SHOW) as $b): ?>
            <tr>
              <td class="mono"><?= e($b['project_code']) ?></td>
              <td class="mono"><?= e($b['mat_code']) ?></td>
              <td class="small"><?= e($b['name']) ?> · <?= e($b['unit']) ?></td>
              <td class="num"><?= fmtQ($b['on_hand']) ?></td>
              <td class="small"><?= e($b['reason']) ?>
                <?php if ($b['llps']): ?><div class="mono small"><?= e((string)$b['llps'][0]['llp_code']) ?> · <?= e((string)$b['llps'][0]['llp_name']) ?></div><?php endif; ?>
              </td>
              <td style="white-space:nowrap"><?php if ($b['llps']): ?>
                    <button type="button" class="mini" data-make="<?= e($b['mat_code']) ?>" data-llp="<?= e((string)$b['llps'][0]['llp_code']) ?>">🏷️ ออกรหัส IC</button>
                    <button type="button" class="mini ghost" data-pick="<?= e($b['mat_code']) ?>" title="ตัวสินค้าไม่ถูก — เลือกตัวอื่น หรือสร้างตัวใหม่">🔁 เปลี่ยนตัวสินค้า</button>
                  <?php else: ?>
                    <button type="button" class="mini ghost" data-pick="<?= e($b['mat_code']) ?>">🔗 ผูกตัวสินค้า</button>
                  <?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
    <?php endif; ?>

    <?php if ($mr): ?>
      <form method="post" id="migForm"
            onsubmit="return confirm('ย้ายยอด <?= number_format($ms['rows']) ?> รายการไปรหัส IC — ทำแล้วย้อนกลับเองไม่ได้ ยืนยันไหม?')">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="migrate">
        <input type="hidden" name="mig_project" value="<?= $fProj ?>">
        <input type="hidden" name="mig_q" value="<?= e($migQ) ?>">
        <?= $keep ?>

        <?php if ($splitRows): ?>
          <h3 class="small" style="margin:12px 0 6px">
            <b>หลาย IC — กรอกยอดแยกต่อ IC</b> (<?= count($splitRows) ?> รายการ) · ผลรวมแต่ละรายการต้องเท่ายอดคงเหลือ
          </h3>
          <div class="scroll">
            <table class="splittbl">
              <tr><th>โครงการ</th><th>รหัส Mango</th><th>ชื่อ · หน่วย</th><th class="num">คงเหลือ</th><th>→ รหัส IC</th><th>ชื่อ IC · หน่วยเก็บ</th><th class="num">ยอดแยก</th></tr>
              <?php foreach ($splitRows as $c): $n = count($c['ics']); $gid = 'g_' . $c['project_id'] . '_' . preg_replace('/[^A-Za-z0-9]/', '_', $c['mat_code']); ?>
                <?php foreach ($c['ics'] as $k => $ic): ?>
                  <tr class="splitrow <?= $k === 0 ? 'first' : '' ?>" data-group="<?= e($gid) ?>" data-total="<?= smNum($c['on_hand']) ?>">
                    <?php if ($k === 0): ?>
                      <td class="mono" rowspan="<?= $n ?>"><?= e($c['project_code']) ?></td>
                      <td class="mono" rowspan="<?= $n ?>"><?= e($c['mat_code']) ?></td>
                      <td class="small" rowspan="<?= $n ?>"><?= e($c['name']) ?> · <?= e($c['unit']) ?>
                        <?= $c['n_doc'] ? '<div class="small">เอกสาร ' . (int)$c['n_doc'] . ' บรรทัด → IC หลัก</div>' : '' ?></td>
                      <td class="num" rowspan="<?= $n ?>"><b><?= fmtQ($c['on_hand']) ?></b>
                        <div class="small sumcell" id="sum_<?= e($gid) ?>"></div></td>
                    <?php endif; ?>
                    <td class="mono"><?= e($ic['ic_code']) ?> <?= $ic['is_primary'] ? '<span class="pill p-info">หลัก</span>' : '' ?></td>
                    <td class="small"><?= e($ic['ic_name']) ?> · <?= e($ic['unit_name']) ?>
                      <?= $ic['unit_warn'] ? '<span class="pill p-warn">หน่วยต่างกัน</span>' : '' ?>
                      <?= $ic['merge'] ? '<span class="pill p-info">บวกรวม</span>' : '' ?></td>
                    <td class="num">
                      <input type="text" inputmode="decimal" class="splitq" style="width:110px;text-align:right"
                             name="sp[<?= $c['project_id'] ?>][<?= e($c['mat_code']) ?>][<?= e($ic['ic_code']) ?>]"
                             value="<?= $ic['is_primary'] ? smNum($c['on_hand']) : '0' ?>">
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endforeach; ?>
            </table>
          </div>
        <?php endif; ?>

        <?php if ($wholeRows): ?>
          <h3 class="small" style="margin:12px 0 6px">IC เดียว — ยกยอดทั้งก้อน (แสดง <?= min(count($wholeRows), SM_PLAN_SHOW) ?> จาก <?= count($wholeRows) ?>)</h3>
          <div class="scroll" style="max-height:340px">
            <table>
              <tr><th>โครงการ</th><th>รหัส Mango</th><th>ชื่อ · หน่วย</th><th class="num">คงเหลือ</th><th class="num">จอง</th>
                  <th>→ รหัส IC</th><th>ชื่อ IC · หน่วยเก็บ</th><th>อะไรบ้าง</th></tr>
              <?php foreach (array_slice($wholeRows, 0, SM_PLAN_SHOW) as $c): $ic = $c['ics'][0]; ?>
                <tr class="<?= $c['unit_warn'] || $c['merge'] ? 'warnrow' : '' ?>">
                  <td class="mono"><?= e($c['project_code']) ?></td>
                  <td class="mono"><?= e($c['mat_code']) ?></td>
                  <td class="small"><?= e($c['name']) ?> · <?= e($c['unit']) ?></td>
                  <td class="num"><?= fmtQ($c['on_hand']) ?></td>
                  <td class="num"><?= fmtQ($c['pending']) ?></td>
                  <td class="mono"><b><?= e($ic['ic_code']) ?></b></td>
                  <td class="small"><?= e($ic['ic_name']) ?> · <?= e($ic['unit_name']) ?>
                    <?= $ic['unit_warn'] ? '<span class="pill p-warn">หน่วยต่างกัน</span>' : '' ?>
                    <?= $ic['merge'] ? '<span class="pill p-info">บวกรวม</span>' : '' ?></td>
                  <td class="small">
                    <?= $c['has_bal'] ? 'ยอด · ' : '' ?><?= $c['n_gate'] ? 'ประตู ' . $c['n_gate'] . ' · ' : '' ?>
                    <?= $c['has_pm'] ? 'ในโครงการ · ' : '' ?><?= $c['has_rate'] ? 'ราคา · ' : '' ?>
                    <?= $c['n_doc'] ? 'เอกสาร ' . $c['n_doc'] . ' บรรทัด' : '' ?><?= $c['n_ddr'] ? ' · ราคาล็อก ' . $c['n_ddr'] : '' ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </table>
          </div>
        <?php endif; ?>

        <div class="row" style="margin-top:13px">
          <div><button type="submit" id="migGo">🚚 ย้ายยอด <?= number_format($ms['rows']) ?> รายการไปรหัส IC</button></div>
          <div class="small" id="migHint">คิดยอดจอง (pending) ใหม่ให้ <?= count($migPlan['projects']) ?> โครงการหลังย้าย</div>
        </div>
      </form>
    <?php else: ?>
      <p class="small" style="margin-top:11px">ไม่มีรายการที่มี IC แล้วและยังถือยอดอยู่ในขอบเขตนี้ — ไม่มีอะไรให้ย้าย</p>
    <?php endif; ?>
  <?php endif; ?>
</div>


<!-- ═══════════ ตัวเลือก LLP / IC (modal) ═══════════ -->
<div class="smp-scrim" id="smpScrim" hidden>
  <div class="smp" role="dialog" aria-modal="true" aria-labelledby="smpTitle">
    <div class="smp-hd">
      <div style="min-width:0;flex:1">
        <h2 id="smpTitle">ผูกรหัส <b id="smpMat">—</b></h2>
        <div class="meta" id="smpMeta">—</div>
      </div>
      <button type="button" class="smp-x" id="smpClose" aria-label="ปิด">✕</button>
    </div>

    <div class="smp-tabs">
      <button type="button" class="smp-tab on" data-tab="llp" id="smpTabLlp">① เลือกตัวสินค้า (LLP)</button>
      <button type="button" class="smp-tab" data-tab="ic">② ออก/ผูกรหัส IC</button>
    </div>

    <div class="smp-body">
      <!-- ① ตัวสินค้า -->
      <div class="smp-pane on" id="smpLlp">
        <div id="smpCur" class="small"></div>
        <div class="row" style="margin:9px 0">
          <input type="text" id="smpQ" placeholder="พิมพ์ชื่อตัวสินค้า หรือรหัส LLP" style="flex:1;min-width:200px" autocomplete="off">
          <select id="smpL1" style="min-width:150px"><option value="">— ทุกกลุ่มใหญ่ —</option></select>
          <button type="button" class="ghost" id="smpSearch">ค้นหา</button>
        </div>
        <div id="smpResults" class="small">…</div>
        <!-- ไม่มีตัวที่ถูกในรายการ (เช่นไฟล์จัดซื้อให้เลข LLP ชนกับสินค้าอื่น) → สร้างตัวสินค้าใหม่แล้วผูกในที่เดียว -->
        <div class="ccbox" id="smpNew" style="margin-top:12px">
          <b>ไม่มีตัวที่ถูกในรายการ?</b> สร้างตัวสินค้า (LLP) ใหม่แล้วผูกให้รหัสนี้ — เลขลำดับ 3 หลักระบบไล่ให้เอง
          <div class="row" style="margin-top:8px;align-items:flex-end">
            <div><label class="fld">กลุ่มใหญ่ (L1)</label><select id="nlL1" style="min-width:150px"></select></div>
            <div><label class="fld">หมวด (L2)</label><select id="nlL2" style="min-width:230px"></select></div>
            <div style="flex:1;min-width:170px"><label class="fld">ชื่อตัวสินค้า</label>
              <input type="text" id="nlName" style="width:100%" maxlength="255" autocomplete="off"></div>
            <div><button type="button" class="mini" id="nlGo">＋ สร้างแล้วผูก</button></div>
          </div>
        </div>
      </div>

      <!-- ② ไล่บันได -->
      <div class="smp-pane" id="smpMake">
        <div id="icFind" style="margin-bottom:10px">
          <div class="row">
            <input type="text" id="icQ" placeholder="ค้น IC ที่ออกแล้ว (ชื่อ/รหัส)" style="flex:1;min-width:200px" autocomplete="off">
            <button type="button" class="ghost" id="icSearch">ค้น IC ที่ออกแล้ว</button>
          </div>
          <div id="icResults" class="small" style="margin-top:6px"></div>
        </div>
        <div id="smpLock" class="ccbox" hidden style="margin:0 0 10px"></div>
        <div class="ladder">
          <div class="step"><div class="t"><b>1</b> กลุ่มใหญ่ (L1)</div><select id="stL1"></select></div>
          <div class="step"><div class="t"><b>2</b> หมวด (L2)</div><select id="stL2"></select></div>
          <div class="step"><div class="t"><b>3</b> ตัวสินค้า (LLP)</div>
            <input type="text" id="stLlpQ" placeholder="กรองชื่อตัวสินค้า…" autocomplete="off" style="margin-bottom:5px">
            <select id="stLlp" size="6"></select>
            <div class="row" style="margin-top:5px;gap:5px">
              <input type="text" id="stLlpNew" placeholder="เพิ่มตัวสินค้าใหม่…" style="flex:1;min-width:110px">
              <button type="button" class="mini ghost" id="stLlpAdd">+ เพิ่ม</button>
            </div>
          </div>
          <div class="step"><div class="t"><b>4</b> ขนาด</div><select id="stSize"></select>
            <div class="row" style="margin-top:5px;gap:5px">
              <input type="text" id="stSizeNew" placeholder="เพิ่มขนาด…" style="flex:1;min-width:90px">
              <button type="button" class="mini ghost" id="stSizeAdd">+</button>
            </div>
          </div>
          <div class="step"><div class="t"><b>5</b> ยี่ห้อ</div><select id="stBrand"></select>
            <div class="row" style="margin-top:5px;gap:5px">
              <input type="text" id="stBrandNew" placeholder="เพิ่มยี่ห้อ…" style="flex:1;min-width:90px">
              <button type="button" class="mini ghost" id="stBrandAdd">+</button>
            </div>
          </div>
          <div class="step"><div class="t"><b>6</b> หน่วยเก็บ <span style="color:var(--red)">*</span></div><select id="stUnit"></select>
            <div class="row" style="margin-top:5px;gap:5px">
              <input type="text" id="stUnitNew" placeholder="เพิ่มหน่วย…" maxlength="50" style="flex:1;min-width:90px">
              <button type="button" class="mini ghost" id="stUnitAdd" title="ชื่อที่มีอยู่แล้วระบบเลือกตัวเดิมให้ ไม่สร้างซ้ำ">+</button>
            </div>
          </div>
          <div class="step"><div class="t"><b>7</b> คุณสมบัติเพิ่ม</div><select id="stExtra"></select>
            <div class="row" style="margin-top:5px;gap:5px">
              <input type="text" id="stExtraNew" placeholder="เพิ่มคุณสมบัติ…" style="flex:1;min-width:90px">
              <button type="button" class="mini ghost" id="stExtraAdd">+</button>
            </div>
          </div>
        </div>

        <div id="smpCharcat" class="ccbox" hidden></div>

        <div class="row" style="margin-top:12px;align-items:flex-end">
          <div style="flex:1;min-width:220px">
            <label class="fld">ชื่อวัสดุที่จะบันทึก (แก้ได้)</label>
            <input type="text" id="stName" style="width:100%" autocomplete="off">
          </div>
          <div><label class="fld">รหัส IC ที่จะได้</label><div class="code" id="stCode">— — — —</div></div>
        </div>
        <div id="smpExists" class="small" style="margin-top:6px"></div>
      </div>
    </div>

    <div class="smp-ft">
      <div class="small" id="smpStatus" style="flex:1;min-width:0"></div>
      <button type="button" class="ghost" id="smpCancel">ปิด</button>
      <button type="button" id="smpMakeGo" disabled hidden>🏷️ ออกรหัสแล้วผูก</button>
    </div>
  </div>
</div>

<style>
.stat{background:#f1f5f9;border-radius:9px;padding:9px 15px;min-width:104px}.stat b{display:block;font-size:1.25rem;line-height:1.2}.stat span{font-size:.72rem;color:#64748b;line-height:1.35;display:block}.stat.warn{background:#fef3c7}.stat.bad{background:#fee2e2}tr.warnrow td{background:#fffbeb}.bad-t{color:var(--red);font-weight:700}
.lblk{border-left:3px solid var(--line);padding:3px 0 3px 8px;margin-bottom:6px}.lblk:last-child{margin-bottom:0}.lhd .mono b{color:var(--navy-2)}
.icrow{margin:3px 0 0 12px;padding:2px 0;font-size:.8rem}.icrow.prim{background:#f8fafc}.icrow.none{color:var(--muted);font-style:italic}
.icact{white-space:nowrap}.lact{margin:4px 0 0 12px}.inl{display:inline}
.splittbl .splitrow.first td{border-top:2px solid #cbd5e1}.splittbl tr.badsum td{background:#fee2e2}.splittbl tr.oksum td{background:#f0fdf4}.sumcell{font-weight:400}.sumcell.bad{color:var(--red);font-weight:700}
.ccbox{margin-top:12px;background:#f8fafc;border:1px solid var(--line);border-radius:10px;padding:10px 12px;font-size:.8rem}.ccbox.bad{background:var(--red-bg);border-color:#fca5a5}.ccbox.good{background:var(--green-bg);border-color:#86efac}.ccbox select{min-width:180px}
.smp-scrim[hidden]{display:none}.smp-scrim{position:fixed;inset:0;z-index:80;background:rgba(15,23,42,.55);display:flex;align-items:flex-start;justify-content:center;padding:22px 12px;overflow:auto}
.smp{width:min(1000px,100%);background:#fff;border-radius:14px;display:flex;flex-direction:column;max-height:calc(100vh - 44px);box-shadow:0 26px 64px -14px rgba(15,23,42,.5)}
.smp-hd{background:var(--navy);color:#fff;padding:13px 16px;display:flex;align-items:flex-start;gap:12px;border-radius:14px 14px 0 0}.smp-hd h2{font-size:1rem;font-weight:400;color:#a8bad6;margin:0}.smp-hd h2 b{color:#fff}.smp-hd .meta{font-size:.74rem;color:#c5d2e6;margin-top:3px}
.smp-x{background:transparent;border:1px solid rgba(255,255,255,.3);color:#fff;border-radius:8px;width:30px;height:30px;padding:0;flex:0 0 auto}
.smp-tabs{display:flex;gap:2px;padding:8px 12px 0;border-bottom:1px solid var(--line);background:#f8fafc}.smp-tab{background:transparent;color:var(--muted);border:0;border-bottom:3px solid transparent;border-radius:0;padding:8px 12px;font-size:.8rem}.smp-tab.on{color:var(--navy);border-bottom-color:var(--gold)}
.smp-body{flex:1;min-height:180px;overflow:auto;padding:12px 16px}.smp-pane{display:none}.smp-pane.on{display:block}
.smp-ft{border-top:1px solid var(--line);padding:10px 16px;display:flex;gap:9px;align-items:center}
.smr{display:flex;align-items:center;gap:10px;padding:7px 9px;border-bottom:1px solid var(--line);cursor:pointer}.smr:hover{background:var(--blue-bg)}.smr.have{opacity:.55;cursor:default}.smr .c{font-family:ui-monospace,Consolas,monospace;font-size:.72rem;font-weight:700;color:var(--navy-2);background:#f1f5f9;border-radius:6px;padding:3px 7px;flex:0 0 auto}.smr .n{flex:1;min-width:0;font-size:.85rem;color:var(--text)}.smr .u{font-size:.72rem;color:var(--muted);background:#f8fafc;border:1px solid var(--line);border-radius:999px;padding:2px 9px;flex:0 0 auto}.smr .go{font-size:.74rem;font-weight:700;color:var(--navy-2);flex:0 0 auto}
.smsec{font-size:.72rem;font-weight:700;color:var(--muted);margin:10px 0 4px;display:flex;align-items:center;gap:6px}.smsec .dot{width:6px;height:6px;border-radius:50%;background:var(--gold)}
.ladder select{width:100%}.ladder input{width:100%}.step select[size]{height:auto}
#smpStatus.ok{color:#15803d}#smpStatus.bad{color:var(--red)}
.busy{opacity:.5;pointer-events:none}
</style>

<script>
(function () {
  'use strict';
  var BASE = <?= json_encode(APP_BASE) ?>;
  var CSRF = <?= json_encode(csrfToken()) ?>;
  var CAT_LABELS  = <?= json_encode($catLabels, JSON_UNESCAPED_UNICODE) ?>;
  var CHAR_LABELS = <?= json_encode($charLabels, JSON_UNESCAPED_UNICODE) ?>;
  var KEEP = <?= json_encode($keep) ?>;
  var L1S  = <?= json_encode($l1s, JSON_UNESCAPED_UNICODE) ?>;
  var MIG_URL = <?= json_encode(smUrl(['mig' => '1'])) ?>;
  var NONE = '000';

  var $ = function (id) { return document.getElementById(id); };
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }
  function api(url, opts) {
    return fetch(url, Object.assign({credentials: 'same-origin'}, opts || {}))
      .then(function (r) { return r.json().catch(function () { throw new Error('เซิร์ฟเวอร์ตอบไม่ใช่ JSON (HTTP ' + r.status + ')'); }); })
      .then(function (j) { if (!j.ok) { throw new Error(j.error || 'ผิดพลาดไม่ทราบสาเหตุ'); } return j; });
  }
  function form(obj) {
    var b = new URLSearchParams();
    b.set('csrf', CSRF);
    Object.keys(obj).forEach(function (k) { b.set(k, obj[k]); });
    return {method: 'POST', body: b};
  }
  function status(kind, html) {
    var s = $('smpStatus');
    s.className = 'small ' + (kind || '');
    s.innerHTML = html || '';
  }
  var flashT = null;
  function flash(html, kind) {
    var old = $('smFlash');
    if (old) { old.parentNode.removeChild(old); }
    var d = document.createElement('div');
    d.id = 'smFlash';
    d.className = 'banner b-' + (kind || 'ok');
    d.innerHTML = html;
    var wrap = document.querySelector('.wrap');
    wrap.insertBefore(d, wrap.firstChild.nextSibling);
    clearTimeout(flashT);
    flashT = setTimeout(function () { if (d.parentNode) { d.parentNode.removeChild(d); } }, 7000);
  }

  // ── วาดช่อง "ตัวสินค้าที่ผูก" ใหม่ (โครงเดียวกับ smMapCell ฝั่ง PHP) ──────
  function mapCellHtml(mat, llps) {
    if (!llps.length) { return '<span class="small">— ยังไม่ผูกตัวสินค้า —</span>'; }
    var h = '';
    llps.forEach(function (b) {
      h += '<div class="lblk"><div class="lhd"><span class="mono"><b>' + esc(b.llp_code) + '</b></span> ' + esc(b.llp_name);
      if (b.missing) { h += ' <span class="pill p-bad">ไม่พบใน llp_products</span>'; }
      else if (b.is_set) {
        h += ' <span class="pill ' + (b.cat_id === 'C01' ? 'p-warn' : 'p-muted') + '">' + esc(b.cat_id) + '</span>'
           + ' <span class="pill p-muted">' + esc(b.char_id) + '</span>'
           + '<div class="small">' + esc(b.path) + '</div>';
      } else {
        h += ' <span class="pill p-bad">ยังไม่ตั้ง Cat/Char</span><div class="small">' + esc(b.path) + '</div>';
      }
      h += '</div>';
      b.ics.forEach(function (ic) {
        h += '<div class="icrow' + (ic.is_primary ? ' prim' : '') + '"><span class="mono"><b>' + esc(ic.ic_code) + '</b></span>'
           + ' <a href="' + esc(BASE + '/ic_edit.php?ic=' + encodeURIComponent(ic.ic_code)) + '" title="แก้ไข IC (ชื่อ/สเปก/หน่วย)">✏️</a>'
           + (ic.is_primary ? ' <span class="pill p-info">หลัก</span>' : '')
           + (ic.missing ? ' <span class="pill p-bad">ไม่พบใน ic_items</span>'
                         : ' ' + esc(ic.ic_name) + ' <span class="small">· ' + esc(ic.unit) + '</span>'
                           + (ic.unit_warn ? ' <span class="pill p-warn">หน่วยต่างกัน</span>' : ''))
           + ' <span class="icact">'
           + (ic.is_primary ? '' : ff('primary_one', mat, {ic: ic.ic_code}, 'primary', 'ตั้งเป็นหลัก') + ' ')
           + ff('detach_ic_one', mat, {ic: ic.ic_code}, 'detach_ic', '✕ ถอด IC')
           + '</span></div>';
      });
      if (!b.ics.length) {
        h += '<div class="icrow none">ยังไม่ได้ออกรหัส IC ใต้ตัวสินค้านี้ <span class="icact">'
           + '<button type="button" class="mini" data-make="' + esc(mat) + '" data-llp="' + esc(b.llp_code) + '">🏷️ ออกรหัส IC</button></span></div>';
      }
      h += '<div class="lact">';
      if (b.ics.length) {
        var base = b.ics[0].ic_code;
        b.ics.forEach(function (x) { if (x.is_primary) { base = x.ic_code; } });
        h += '<button type="button" class="mini ghost" data-make="' + esc(mat) + '" data-llp="' + esc(b.llp_code)
           + '" data-base="' + esc(base) + '" title="ออก/ผูก IC อีกตัวใต้ตัวสินค้าเดิม (ของรหัสนี้มีหลายสเปก)">＋ เพิ่ม IC</button> ';
      }
      h += ff('unmap_llp_one', mat, {llp: b.llp_code}, 'unmap_llp', '✕ ถอดตัวสินค้า') + '</div>';
      h += '</div>';
    });
    return h;
  }
  function ff(action, mat, extra, act, label) {
    var h = '<form method="post" class="inl" data-act="' + act + '"><input type="hidden" name="csrf" value="' + esc(CSRF) + '">'
          + '<input type="hidden" name="action" value="' + action + '"><input type="hidden" name="mat" value="' + esc(mat) + '">';
    Object.keys(extra).forEach(function (k) { h += '<input type="hidden" name="' + k + '" value="' + esc(extra[k]) + '">'; });
    return h + KEEP + '<button type="submit" class="mini ghost">' + label + '</button></form>';
  }
  function paintRow(mat, llps) {
    var tr = document.querySelector('tr[data-mat="' + CSS.escape(mat) + '"]');
    if (!tr) { return false; }
    tr.querySelector('.c-map').innerHTML = mapCellHtml(mat, llps);
    var holds = tr.dataset.holds === '1';
    var nIc = 0;
    llps.forEach(function (b) { nIc += b.ics.length; });
    tr.classList.toggle('warnrow', holds && llps.length === 0);
    var h = '<button type="button" class="mini ' + (llps.length ? 'ghost' : '') + '" data-pick="' + esc(mat) + '">'
          + (llps.length > 1 ? '⚠ เลือกให้เหลือตัวเดียว' : (llps.length ? '🔁 เปลี่ยนตัวสินค้า' : '🔗 ผูกตัวสินค้า')) + '</button> ';
    if (holds && nIc === 1) {
      h += '<form method="post" class="inl" onsubmit="return confirm(\'ยกยอดของ ' + esc(mat) + ' ไปรหัส IC ทั้งก้อน — ทำแล้วย้อนกลับเองไม่ได้ ยืนยันไหม?\')">'
         + '<input type="hidden" name="csrf" value="' + esc(CSRF) + '"><input type="hidden" name="action" value="migrate_one">'
         + '<input type="hidden" name="mat" value="' + esc(mat) + '">' + KEEP
         + '<button type="submit" class="mini">🚚 ยกยอดมา</button></form>';
    } else if (holds && nIc > 1) {
      h += '<a href="' + esc(MIG_URL + (MIG_URL.indexOf('?') === -1 ? '?' : '&') + 'mig_q=' + encodeURIComponent(mat)) + '#migrate">'
         + '<button type="button" class="mini ghost">✂ แยกยอด</button></a>';
    }
    tr.querySelector('.c-act').innerHTML = h;
    return true;
  }

  // ── ฟอร์มเล็กในตาราง: มี JS ก็ยิง API แทนการโหลดหน้า ─────────────────────
  var ACT_API = {primary: 'primary', detach_ic: 'detach_ic', unmap_llp: 'unmap_llp'};
  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    if (!f.dataset || !f.dataset.act || !f.closest('#smTable')) { return; }
    var a = ACT_API[f.dataset.act];
    if (!a) { return; }
    ev.preventDefault();
    ev.stopImmediatePropagation();
    var mat = f.querySelector('[name=mat]').value;
    var ic  = f.querySelector('[name=ic]') ? f.querySelector('[name=ic]').value : '';
    var llp = f.querySelector('[name=llp]') ? f.querySelector('[name=llp]').value : '';
    if (a === 'unmap_llp' && !confirm('ถอด ' + llp + ' ออกจาก ' + mat + ' ?')) { return; }
    api(BASE + '/api/setup_master_api.php', form({a: a, mat: mat, ic: ic, llp: llp}))
      .then(function (j) {
        paintRow(j.mat.mat_code, j.llps);
        flash('บันทึกแล้ว — ' + esc(j.mat.mat_code), 'ok');
      })
      .catch(function (e) { flash(esc(e.message), 'bad'); });
  }, true);

  // ── ผลรวมยอดแยกในแผนย้าย ────────────────────────────────────────────────
  function checkSplits() {
    var groups = {};
    Array.prototype.forEach.call(document.querySelectorAll('tr.splitrow'), function (tr) {
      var g = tr.dataset.group;
      if (!groups[g]) { groups[g] = {total: parseFloat(tr.dataset.total) || 0, sum: 0, rows: [], bad: false}; }
      var v = String(tr.querySelector('.splitq').value || '').replace(/,/g, '').trim();
      var n = v === '' ? 0 : Number(v);
      if (!isFinite(n) || n < 0) { groups[g].bad = true; n = 0; }
      groups[g].sum += n;
      groups[g].rows.push(tr);
    });
    var allOk = true;
    Object.keys(groups).forEach(function (g) {
      var x = groups[g];
      var ok = !x.bad && Math.abs(x.sum - x.total) < 0.0005;
      if (!ok) { allOk = false; }
      x.rows.forEach(function (tr) { tr.classList.toggle('badsum', !ok); tr.classList.toggle('oksum', ok); });
      var cell = $('sum_' + g);
      if (cell) {
        cell.className = 'small sumcell' + (ok ? '' : ' bad');
        cell.textContent = ok ? '✓ รวมครบ' : (x.bad ? 'ตัวเลขไม่ถูกต้อง' : 'รวม ' + x.sum.toLocaleString() + ' · ขาด/เกิน ' + (x.total - x.sum).toLocaleString());
      }
    });
    var go = $('migGo');
    if (go) {
      go.disabled = !allOk;
      var hint = $('migHint');
      if (hint && !allOk) { hint.textContent = 'ยอดแยกบางรายการยังรวมไม่เท่ายอดคงเหลือ — แก้ให้ครบก่อน'; }
    }
  }
  document.addEventListener('input', function (ev) { if (ev.target.classList && ev.target.classList.contains('splitq')) { checkSplits(); } });
  if (document.querySelector('tr.splitrow')) { checkSplits(); }

  // ── modal ───────────────────────────────────────────────────────────────
  var M = {mat: '', name: '', unit: '', llps: [], inTable: false, base: ''};
  var picked = {l1: '', l2: '', llp: '', size: NONE, brand: NONE, unit: '', extra: NONE};
  var steps = null, nameTouched = false;

  // 1 Mango มีตัวสินค้าได้ตัวเดียว (มติ 45) — ผูกไว้แล้ว = บันไดแท็บ ② ล็อกที่ตัวนั้น ออก/ผูกได้เฉพาะ IC ใต้มัน
  function lockedLlp() { return M.llps.length === 1 ? M.llps[0].llp_code : ''; }
  function nIcs() { var n = 0; M.llps.forEach(function (b) { n += b.ics.length; }); return n; }
  // ตั้งต้นบันไดจาก IC ตัวอย่าง (ปุ่ม "＋ เพิ่ม IC") — ได้ขนาด/ยี่ห้อ/หน่วยเดิม เหลือเลือกคุณสมบัติที่ต่าง
  function stepsFrom(llp, base) {
    var p = llp ? {l1: llp.substr(0, 3), l2: llp.substr(3, 2), llp: llp} : {};
    if (base && base.length === 20 && base.substr(0, 8) === llp) {
      p.size = base.substr(8, 3); p.brand = base.substr(11, 3); p.unit = base.substr(14, 3); p.extra = NONE;
    }
    return p;
  }

  function open(mat, tab, llp, base) {
    var tr = document.querySelector('tr[data-mat="' + CSS.escape(mat) + '"]');
    var nm = tr ? tr.querySelector('.c-name') : null;
    M.mat = mat; M.name = nm ? nm.dataset.name : ''; M.unit = nm ? nm.dataset.unit : ''; M.llps = [];
    M.inTable = !!tr; M.base = base || '';
    $('smpMat').textContent = mat;
    $('smpMeta').textContent = (M.name || '(ไม่มีชื่อ)') + (M.unit ? ' · หน่วย ' + M.unit : '');
    status('', '');
    $('smpCur').innerHTML = '';
    $('smpResults').innerHTML = '…';
    $('icResults').innerHTML = '';
    picked = {l1: '', l2: '', llp: '', size: NONE, brand: NONE, unit: '', extra: NONE};
    nameTouched = false; steps = null;
    $('stName').value = ''; $('stCode').textContent = '— — — —'; $('smpExists').innerHTML = '';
    $('smpCharcat').hidden = true;
    $('smpMakeGo').disabled = true;
    if (!$('smpL1').options.length || $('smpL1').options.length === 1) {
      var o = '<option value="">— ทุกกลุ่มใหญ่ —</option>';
      L1S.forEach(function (g) { o += '<option value="' + esc(g.l1_code) + '">' + esc(g.l1_code) + ' · ' + esc(g.l1_name) + '</option>'; });
      $('smpL1').innerHTML = o;
    }

    $('smpScrim').hidden = false;
    placeInView();
    setTab(tab || 'llp');
    api(BASE + '/api/setup_master_api.php?a=info&mat=' + encodeURIComponent(mat))
      .then(function (j) {
        M.name = j.mat.name; M.unit = j.mat.unit; M.llps = j.llps;
        $('smpMeta').textContent = (M.name || '(ไม่มีชื่อ)') + (M.unit ? ' · หน่วย ' + M.unit : '')
          + (j.llps.length ? ' · ตัวสินค้า ' + j.llps.map(function (b) { return b.llp_code; }).join(', ')
                             + ' · IC ' + nIcs() + ' ตัว' : '');
        $('smpTabLlp').textContent = j.llps.length ? '① เปลี่ยนตัวสินค้า (LLP)' : '① เลือกตัวสินค้า (LLP)';
        paintCur();
        newLlpReset();
        $('smpQ').value = M.name || mat;
        if (tab === 'ic') {
          var target = lockedLlp() || llp || (M.llps.length ? M.llps[0].llp_code : '');
          $('icQ').value = lockedLlp() ? '' : (M.name || '');
          searchIc();
          loadSteps(stepsFrom(target, M.base));
        } else {
          searchLlp();
        }
      })
      .catch(function (e) { status('bad', esc(e.message)); });
    setTimeout(function () { try { (tab === 'ic' ? $('icQ') : $('smpQ')).focus(); } catch (e) {} }, 40);
  }
  function close() { $('smpScrim').hidden = true; }

  function placeInView() {
    var sc = $('smpScrim');
    sc.style.paddingTop = ''; sc.style.alignItems = '';
    try {
      var fe = window.frameElement;
      if (!fe) { return; }
      var r = fe.getBoundingClientRect();
      var TOPBAR = 72;
      var visTop = Math.max(0, -r.top) + TOPBAR;
      var visH   = Math.min(window.innerHeight - visTop, (fe.ownerDocument.defaultView || window).innerHeight - TOPBAR);
      sc.style.alignItems = 'flex-start';
      sc.style.paddingTop = (visTop + 12) + 'px';
      sc.querySelector('.smp').style.maxHeight = Math.max(320, visH - 36) + 'px';
    } catch (e) {}
  }
  function setTab(t) {
    Array.prototype.forEach.call(document.querySelectorAll('.smp-tab'), function (b) { b.classList.toggle('on', b.dataset.tab === t); });
    $('smpLlp').classList.toggle('on', t === 'llp');
    $('smpMake').classList.toggle('on', t === 'ic');
    $('smpMakeGo').hidden = t !== 'ic';
    if (t === 'ic' && steps === null) {
      var target = lockedLlp() || (M.llps.length ? M.llps[0].llp_code : '');
      if ($('icResults').innerHTML === '' && lockedLlp()) { $('icQ').value = ''; searchIc(); }
      loadSteps(stepsFrom(target, M.base));
    }
  }
  function paintCur() {
    if (!M.llps.length) { $('smpCur').innerHTML = '<div class="smsec"><span class="dot"></span> ยังไม่ผูกตัวสินค้า — เลือกจากรายการด้านล่าง</div>'; return; }
    var h = M.llps.length > 1
      ? '<div class="banner b-warn" style="margin:0 0 6px">ผูกไว้ ' + M.llps.length + ' ตัวสินค้า — ผิดกติกา <b>1 Mango มีตัวสินค้าได้ตัวเดียว</b> '
        + 'เลือกตัวที่ถูก (จากรายการนี้หรือด้านล่าง) ระบบจะถอดตัวอื่นพร้อม IC ใต้มันออก</div>'
      : '<div class="smsec"><span class="dot"></span> ผูกอยู่ตอนนี้ — 1 Mango มีตัวสินค้าได้ตัวเดียว เลือกตัวใหม่ด้านล่าง = เปลี่ยน'
        + (nIcs() ? ' (IC ที่ผูกอยู่ ' + nIcs() + ' ตัวจะหลุด)' : '') + '</div>';
    var pickOne = M.llps.length > 1;   // ข้อมูลรุ่นก่อนมติ 45 — ให้กดเลือกตัวที่จะเหลือได้
    M.llps.forEach(function (b) {
      h += '<div class="smr' + (pickOne ? '" data-llp="' + esc(b.llp_code) : ' have') + '"><span class="c">' + esc(b.llp_code) + '</span><span class="n">' + esc(b.llp_name)
         + ' <span class="small">· ' + esc(b.path) + '</span></span>'
         + '<span class="u">' + (b.is_set ? esc(b.cat_id + '/' + b.char_id) : 'ยังไม่ตั้ง Cat/Char') + '</span>'
         + '<span class="u">IC ' + b.ics.length + '</span>'
         + '<span class="go">' + (pickOne ? 'ใช้ตัวนี้ตัวเดียว ›' : 'ผูกอยู่') + '</span></div>';
    });
    $('smpCur').innerHTML = h;
  }

  // ── ① ค้นตัวสินค้า ──────────────────────────────────────────────────────
  function searchLlp() {
    var q = $('smpQ').value.trim();
    $('smpResults').innerHTML = 'กำลังค้น…';
    var have = {};
    M.llps.forEach(function (b) { have[b.llp_code] = true; });
    api(BASE + '/api/setup_master_api.php?a=llp&q=' + encodeURIComponent(q) + '&l1=' + encodeURIComponent($('smpL1').value))
      .then(function (j) {
        var h = '<div class="smsec"><span class="dot"></span> ตัวสินค้าที่ตรง "' + esc(q) + '" <span style="font-weight:400">' + j.rows.length + ' รายการ</span></div>';
        if (!j.rows.length) { h += '<div class="small" style="padding:8px 2px">ไม่พบ — ลองคำสั้นลง หรือสร้างตัวสินค้าใหม่ด้านล่าง</div>'; }
        j.rows.forEach(function (r) {
          if (have[r.llp_code] && M.llps.length === 1) {
            h += '<div class="smr have"><span class="c">' + esc(r.llp_code) + '</span><span class="n">' + esc(r.llp_name) + '</span><span class="go">ผูกอยู่</span></div>';
          } else {
            h += '<div class="smr" data-llp="' + esc(r.llp_code) + '"><span class="c">' + esc(r.llp_code) + '</span>'
               + '<span class="n">' + esc(r.llp_name) + ' <span class="small">· ' + esc(r.l1_name) + ' › ' + esc(r.l2_name) + '</span></span>'
               + '<span class="u">' + (r.is_set ? esc(r.cat_id + '/' + r.char_id) : '⚠ ยังไม่ตั้ง Cat/Char') + '</span>'
               + '<span class="u">IC ' + r.n_ic + ' · Mango ' + r.n_mango + '</span>'
               + '<span class="go">' + (have[r.llp_code] ? 'ใช้ตัวนี้ตัวเดียว ›' : (M.llps.length ? 'เปลี่ยนเป็น ›' : 'ผูก ›')) + '</span></div>';
          }
        });
        $('smpResults').innerHTML = h;
      })
      .catch(function (e) { $('smpResults').innerHTML = '<span style="color:var(--red)">' + esc(e.message) + '</span>'; });
  }
  // IC ที่จะหลุดถ้าเปลี่ยนตัวสินค้าเป็น llp (1 Mango = 1 LLP · มติ 45)
  function dropsFor(llp) {
    var drop = [], old = [];
    M.llps.forEach(function (b) {
      if (b.llp_code === llp) { return; }
      old.push(b.llp_code);
      b.ics.forEach(function (x) { drop.push(x.ic_code); });
    });
    return {drop: drop, old: old};
  }
  function confirmDrop(d, llpLabel) {
    return !d.drop.length || confirm('เปลี่ยนตัวสินค้าของ ' + M.mat + ' จาก ' + d.old.join(', ') + ' เป็น ' + llpLabel + '\n\n'
      + 'IC ที่ผูกอยู่ ' + d.drop.length + ' ตัวจะถูกถอดออก:\n' + d.drop.join('\n')
      + '\n\n(ยอดที่ย้ายไปแล้วยังอยู่ที่ IC เดิม) ยืนยันไหม?');
  }
  function doMapLlp(llp, confirmed) {
    // 1 Mango = 1 LLP (มติ 45) — เลือกตัวอื่น = เปลี่ยน · IC ใต้ตัวเดิมจะหลุด ต้องยืนยันก่อน
    var d = dropsFor(llp), drop = d.drop, old = d.old;
    if (!confirmed && !confirmDrop(d, llp)) { return Promise.resolve(); }
    status('', old.length ? 'กำลังเปลี่ยน…' : 'กำลังผูก…');
    return api(BASE + '/api/setup_master_api.php', form({a: 'map_llp', mat: M.mat, llp: llp, replace: drop.length ? '1' : ''}))
      .then(function (j) {
        M.llps = j.llps;
        if (M.inTable) { paintRow(j.mat.mat_code, j.llps); }
        $('smpTabLlp').textContent = '① เปลี่ยนตัวสินค้า (LLP)';
        steps = null; M.base = '';           // บันได/รายการ IC แท็บ ② ต้องล็อกตามตัวใหม่
        $('icResults').innerHTML = '';
        paintCur();
        searchLlp();
        newLlpReset();
        var what = j.old && j.old.length ? 'เปลี่ยน ' + esc(j.old.join(', ')) + ' → ' : 'ผูก ';
        var lost = j.dropped && j.dropped.length ? ' · ถอด IC ' + j.dropped.length + ' ตัว' : '';
        status('ok', what + esc(j.llp_code) + ' · ' + esc(j.llp_name) + ' แล้ว' + lost + (j.is_set ? '' : ' — ⚠ ตัวสินค้านี้ยังไม่ตั้ง Cat/Char'));
        flash(esc(j.mat.mat_code) + ': ' + what + esc(j.llp_code) + ' · ' + esc(j.llp_name) + ' แล้ว' + lost, 'ok');
        if (!M.inTable) { setTimeout(function () { location.reload(); }, 600); }
      })
      .catch(function (e) { status('bad', esc(e.message)); });
  }

  // ── ① สร้างตัวสินค้าใหม่แล้วผูก (ตั้งต้น L1/L2 จากตัวที่ผูกอยู่ + ชื่อจาก Mango) ────────
  function newLlpReset() {
    var cur = M.llps.length ? M.llps[0].llp_code : '';
    var o = '<option value="">— เลือก —</option>';
    L1S.forEach(function (g) { o += '<option value="' + esc(g.l1_code) + '">' + esc(g.l1_code) + ' · ' + esc(g.l1_name) + '</option>'; });
    $('nlL1').innerHTML = o;
    $('nlL1').value = cur ? cur.substr(0, 3) : '';
    $('nlName').value = M.name || '';
    loadNlL2(cur ? cur.substr(3, 2) : '');
  }
  function loadNlL2(want) {
    var l1 = $('nlL1').value;
    if (!l1) { $('nlL2').innerHTML = '<option value="">(เลือกกลุ่มใหญ่ก่อน)</option>'; return; }
    $('nlL2').innerHTML = '<option value="">กำลังโหลด…</option>';
    api(BASE + '/api/ic_api.php?a=steps&l1=' + encodeURIComponent(l1))
      .then(function (j) {
        var o = '<option value="">— เลือกหมวด —</option>';
        j.l2.forEach(function (r) {
          o += '<option value="' + esc(r.l2_code) + '"' + (String(r.l2_code) === String(want) ? ' selected' : '') + '>'
             + esc(r.l2_code) + ' · ' + esc(r.l2_name) + '</option>';
        });
        $('nlL2').innerHTML = o;
      })
      .catch(function (e) { $('nlL2').innerHTML = '<option value="">โหลดไม่สำเร็จ</option>'; status('bad', esc(e.message)); });
  }
  function doNewLlp() {
    var l1 = $('nlL1').value, l2 = $('nlL2').value, name = $('nlName').value.trim();
    if (!l1 || !l2 || !name) { status('bad', 'เลือกกลุ่มใหญ่ + หมวด แล้วใส่ชื่อตัวสินค้าก่อน'); return; }
    // ถามก่อนสร้าง — ถ้าไปยกเลิกหลังสร้างแล้วจะเหลือตัวสินค้าลอย ๆ ไม่มีใครผูก
    if (!confirmDrop(dropsFor(''), 'ตัวสินค้าใหม่ "' + name + '" (' + l1 + l2 + '…)')) { return; }
    $('nlGo').disabled = true;
    status('', 'กำลังสร้างตัวสินค้า…');
    api(BASE + '/api/ic_api.php', form({a: 'add_llp', l1: l1, l2: l2, name: name}))
      .then(function (j) { return doMapLlp(j.code, true); })
      .catch(function (e) { status('bad', esc(e.message)); })
      .then(function () { $('nlGo').disabled = false; });
  }

  // ── ② IC: ค้นที่ออกแล้ว ─────────────────────────────────────────────────
  function searchIc() {
    var q = $('icQ').value.trim();
    var only = lockedLlp();               // ผูกตัวสินค้าไว้แล้ว = ผูกได้เฉพาะ IC ใต้ตัวนั้น (มติ 45)
    $('icResults').innerHTML = 'กำลังค้น…';
    var have = {};
    M.llps.forEach(function (b) { b.ics.forEach(function (x) { have[x.ic_code] = true; }); });
    api(BASE + '/api/ic_api.php?a=search&q=' + encodeURIComponent(q) + (only ? '&llp=' + encodeURIComponent(only) : ''))
      .then(function (j) {
        if (!j.rows.length) {
          $('icResults').innerHTML = '<span class="small">' + (only ? 'ยังไม่มี IC ใต้ ' + esc(only) + (q ? ' ที่ตรงคำนี้' : '') : 'ไม่พบ IC ที่ออกแล้วตรงคำนี้')
            + ' — ไล่บันไดออกรหัสใหม่ด้านล่าง</span>';
          return;
        }
        var h = only ? '<div class="smsec"><span class="dot"></span> IC ที่ออกแล้วใต้ ' + esc(only) + ' <span style="font-weight:400">'
                       + j.rows.length + ' ตัว — กดเพื่อผูกเพิ่ม</span></div>' : '';
        j.rows.forEach(function (r) {
          if (have[r.ic_code]) {
            h += '<div class="smr have"><span class="c">' + esc(r.ic_code) + '</span><span class="n">' + esc(r.ic_name) + '</span><span class="go">ผูกแล้ว</span></div>';
          } else {
            h += '<div class="smr" data-ic="' + esc(r.ic_code) + '"><span class="c">' + esc(r.ic_code) + '</span>'
               + '<span class="n">' + esc(r.ic_name) + '</span><span class="u">' + esc(r.unit_name) + '</span><span class="go">ผูก ›</span></div>';
          }
        });
        $('icResults').innerHTML = h;
      })
      .catch(function (e) { $('icResults').innerHTML = '<span style="color:var(--red)">' + esc(e.message) + '</span>'; });
  }
  function doAttachIc(ic) {
    status('', 'กำลังผูก…');
    return api(BASE + '/api/setup_master_api.php', form({a: 'attach_ic', mat: M.mat, ic: ic}))
      .then(function (j) {
        M.llps = j.llps;
        if (!M.inTable) { location.reload(); return; }
        paintRow(j.mat.mat_code, j.llps);
        close();
        flash('ผูก ' + esc(j.mat.mat_code) + ' → ' + esc(j.ic_code) + ' · ' + esc(j.ic_name)
              + (j.unit_warn ? ' · ⚠ หน่วยต่างกัน (' + esc(M.unit) + ' ↔ ' + esc(j.unit) + ')' : ''), j.unit_warn ? 'warn' : 'ok');
      })
      .catch(function (e) { status('bad', esc(e.message)); });
  }

  // ── ② ไล่บันได (api/ic_api.php?a=steps) ─────────────────────────────────
  var seq = 0;
  function loadSteps(over) {
    var p = Object.assign({}, picked, over || {});
    var my = ++seq;
    $('smpMake').classList.add('busy');
    return api(BASE + '/api/ic_api.php?a=steps&' + new URLSearchParams(Object.assign({q: $('stLlpQ').value.trim()}, p)).toString())
      .then(function (j) {
        if (my !== seq) { return; }
        steps = j; picked = j.picked; paintMake();
      })
      .catch(function (e) { status('bad', 'โหลดบันไดไม่สำเร็จ: ' + esc(e.message)); })
      .then(function () { if (my === seq) { $('smpMake').classList.remove('busy'); } });
  }
  function fill(sel, rows, codeKey, nameKey, val, blankLabel) {
    var h = blankLabel !== undefined ? '<option value="">' + esc(blankLabel) + '</option>' : '';
    rows.forEach(function (r) {
      h += '<option value="' + esc(r[codeKey]) + '"' + (String(r[codeKey]) === String(val) ? ' selected' : '') + '>'
         + esc(r[codeKey]) + ' · ' + esc(r[nameKey]) + '</option>';
    });
    sel.innerHTML = h;
  }
  function paintMake() {
    var j = steps;
    fill($('stL1'),   j.l1,   'l1_code',   'l1_name',   picked.l1,   '— เลือกกลุ่มใหญ่ —');
    fill($('stL2'),   j.l2,   'l2_code',   'l2_name',   picked.l2,   picked.l1 ? '— เลือกหมวด —' : '(เลือก L1 ก่อน)');
    fill($('stLlp'),  j.llp,  'llp_code',  'llp_name',  picked.llp);
    fill($('stSize'), j.size, 'size_code', 'size_name', picked.size);
    fill($('stBrand'),j.brand,'brand_code','brand_name',picked.brand);
    fill($('stUnit'), j.unit, 'unit_code', 'unit_name', picked.unit, '— เลือกหน่วยเก็บ —');
    fill($('stExtra'), [{extra_code: NONE, extra_name: 'ไม่มี'}].concat(j.extra || []), 'extra_code', 'extra_name', picked.extra);
    $('stL2').disabled = !picked.l1; $('stLlp').disabled = !picked.l2;
    $('stSize').disabled = !picked.l2; $('stBrand').disabled = !picked.l2; $('stExtra').disabled = !picked.llp;

    // ผูกตัวสินค้าไว้แล้ว → ล็อกชั้น 1-3 (มติ 45) ออก/ผูกได้เฉพาะ IC ใต้ตัวนั้น = IC เพิ่มของรหัสนี้
    var lk = lockedLlp(), lb = $('smpLock');
    ['stL1', 'stLlpQ', 'stLlpNew', 'stLlpAdd'].forEach(function (id) { $(id).disabled = !!lk; });
    if (lk) {
      $('stL2').disabled = true; $('stLlp').disabled = true;
      lb.hidden = false;
      lb.innerHTML = '🔒 ตัวสินค้าล็อกไว้ที่ <b>' + esc(lk) + '</b> · ' + esc(M.llps[0].llp_name)
        + ' — 1 Mango มีตัวสินค้าได้ตัวเดียว · IC ที่ออก/ผูกจากตรงนี้จะเป็น <b>IC เพิ่ม</b> ของ ' + esc(M.mat)
        + (nIcs() ? ' (มีอยู่ ' + nIcs() + ' ตัว — ตอนย้ายยอดต้องกรอกยอดแยก)' : '')
        + ' · ถ้าจะใช้ตัวสินค้าอื่น เปลี่ยนที่แท็บ ①';
    } else {
      lb.hidden = true;
    }

    if (!nameTouched) { $('stName').value = j.ic_name || ''; }
    $('stCode').textContent = j.ic_code || '— — — —';
    $('smpExists').innerHTML = j.exists ? '<span class="pill p-info">รหัสนี้ออกไว้แล้ว</span> ' + esc(j.exists_name) + ' — กดปุ่มก็ผูกกับตัวเดิมได้เลย' : '';

    var cc = $('smpCharcat');
    if (!picked.llp) { cc.hidden = true; }
    else {
      cc.hidden = false;
      if (j.charcat.is_set) {
        cc.className = 'ccbox good';
        cc.innerHTML = 'ตัวสินค้า <b>' + esc(picked.llp) + '</b>: '
          + '<span class="pill ' + (j.charcat.cat_id === 'C01' ? 'p-warn' : 'p-muted') + '">' + esc(j.charcat.cat_id) + '</span> '
          + '<span class="pill p-muted">' + esc(j.charcat.char_id) + '</span> '
          + '<span class="small">' + esc(j.charcat.cat_label) + ' · ' + esc(j.charcat.char_label) + '</span>';
      } else {
        cc.className = 'ccbox bad';
        var o1 = '', o2 = '';
        Object.keys(CAT_LABELS).forEach(function (k)  { o1 += '<option value="' + k + '">' + esc(CAT_LABELS[k])  + '</option>'; });
        Object.keys(CHAR_LABELS).forEach(function (k) { o2 += '<option value="' + k + '">' + esc(CHAR_LABELS[k]) + '</option>'; });
        cc.innerHTML = '<b>ตัวสินค้า ' + esc(picked.llp) + ' ยังไม่ตั้งหมวดอนุมัติ/ลักษณะวัสดุ</b> — ออกรหัส IC ใต้มันไม่ได้ (มติ 32)'
          + '<div class="row" style="margin-top:8px"><select id="ccCat"><option value="">— CatID —</option>' + o1 + '</select>'
          + '<select id="ccChar"><option value="">— CharID —</option>' + o2 + '</select>'
          + '<button type="button" class="mini" id="ccSave">ตั้งค่าให้ตัวสินค้านี้</button></div>';
      }
    }
    var ready = j.complete && j.charcat.is_set;
    var attached = false;                 // ประกอบได้รหัสที่ผูกกับ Mango นี้อยู่แล้ว (เช่นเปิดจาก "＋ เพิ่ม IC")
    M.llps.forEach(function (b) { b.ics.forEach(function (x) { if (x.ic_code === j.ic_code) { attached = true; } }); });
    $('smpMakeGo').disabled = !ready || attached;
    $('smpMakeGo').textContent = attached ? '✓ ผูกอยู่แล้ว' : (j.exists ? '🔗 ผูกกับรหัสนี้' : '🏷️ ออกรหัสแล้วผูก');
    if (attached) {
      $('smpExists').innerHTML = '<span class="pill p-info">ผูกกับ ' + esc(M.mat) + ' อยู่แล้ว</span> '
        + 'เลือก/เพิ่ม <b>คุณสมบัติเพิ่ม</b> (ข้อ 7 เช่น "แบบยาว") หรือขนาดอื่น เพื่อออก IC ตัวใหม่ของรหัสนี้';
    }
    status('', ready ? '' : (!picked.llp ? 'เลือกกลุ่ม → หมวด → ตัวสินค้า แล้วเลือกหน่วยเก็บ' : (!picked.unit ? 'เลือกหน่วยเก็บ (ข้อ 6)' : '')));
  }
  function makeGo() {
    if (!steps || !steps.complete) { return; }
    $('smpMakeGo').disabled = true;
    status('', 'กำลังออกรหัส…');
    api(BASE + '/api/ic_api.php', form({a: 'create_ic', llp: picked.llp, size: picked.size, brand: picked.brand,
                                        unit: picked.unit, extra: picked.extra, ic_name: $('stName').value}))
      .then(function (j) { return doAttachIc(j.ic_code); })
      .catch(function (e) { status('bad', esc(e.message)); $('smpMakeGo').disabled = false; });
  }
  function addLadder(a, extra, inputId) {
    var name = $(inputId).value.trim();
    if (!name) { status('bad', 'พิมพ์ชื่อก่อน'); return; }
    api(BASE + '/api/ic_api.php', form(Object.assign({a: a, name: name, l1: picked.l1, l2: picked.l2, llp: picked.llp}, extra || {})))
      .then(function (j) {
        $(inputId).value = '';
        var over = {};
        if (a === 'add_llp')   { over.llp = j.code; }
        if (a === 'add_size')  { over.size = j.code; }
        if (a === 'add_brand') { over.brand = j.code; }
        if (a === 'add_extra') { over.extra = j.code; }
        if (a === 'add_unit')  { over.unit = j.code; }
        var msg = j.existed ? '"' + esc(j.name) + '" มีอยู่แล้ว (รหัส ' + esc(j.code) + ') — เลือกให้แล้ว'
                            : 'เพิ่ม ' + esc(j.code) + ' · ' + esc(j.name) + ' แล้ว';
        // บันไดวาดใหม่แล้วค่อยขึ้นข้อความ — ไม่งั้น paintMake ล้างทิ้งทันที
        return loadSteps(over).then(function () { status('ok', msg); });
      })
      .catch(function (e) { status('bad', esc(e.message)); });
  }

  // ── events ──────────────────────────────────────────────────────────────
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('button[data-pick]');
    if (b) { open(b.dataset.pick, 'llp'); return; }
    var mk = ev.target.closest('button[data-make]');
    if (mk) { open(mk.dataset.make, 'ic', mk.dataset.llp || '', mk.dataset.base || ''); return; }
    var t = ev.target.closest('.smp-tab');
    if (t) { setTab(t.dataset.tab); return; }
    var l = ev.target.closest('.smr[data-llp]');
    if (l) { doMapLlp(l.dataset.llp); return; }
    var i = ev.target.closest('.smr[data-ic]');
    if (i) { doAttachIc(i.dataset.ic); return; }
    if (ev.target.id === 'ccSave') {
      var cat = $('ccCat').value, chr = $('ccChar').value;
      if (!cat || !chr) { status('bad', 'เลือกทั้ง CatID และ CharID'); return; }
      api(BASE + '/api/ic_api.php', form({a: 'set_charcat', llp: picked.llp, cat_id: cat, char_id: chr}))
        .then(function (j) {
          status('ok', 'ตั้งค่า ' + esc(picked.llp) + ' แล้ว' + (j.ic > 0 ? ' · อัปเดต IC ใต้มัน ' + j.ic + ' รหัส' : ''));
          return loadSteps({});
        })
        .catch(function (e) { status('bad', esc(e.message)); });
      return;
    }
    if (ev.target === $('smpScrim')) { close(); }
  });
  $('smpClose').addEventListener('click', close);
  $('smpCancel').addEventListener('click', close);
  $('smpSearch').addEventListener('click', searchLlp);
  $('smpL1').addEventListener('change', searchLlp);
  $('smpQ').addEventListener('keydown', function (ev) { if (ev.key === 'Enter' || ev.keyCode === 13) { ev.preventDefault(); searchLlp(); } });
  $('icSearch').addEventListener('click', searchIc);
  $('icQ').addEventListener('keydown', function (ev) { if (ev.key === 'Enter' || ev.keyCode === 13) { ev.preventDefault(); searchIc(); } });
  $('smpMakeGo').addEventListener('click', makeGo);
  $('stName').addEventListener('input', function () { nameTouched = true; });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !$('smpScrim').hidden) { close(); } });

  $('stL1').addEventListener('change',   function () { loadSteps({l1: this.value, l2: '', llp: '', extra: NONE}); });
  $('stL2').addEventListener('change',   function () { loadSteps({l2: this.value, llp: '', extra: NONE}); });
  $('stLlp').addEventListener('change',  function () { loadSteps({llp: this.value, extra: NONE}); });
  $('stSize').addEventListener('change', function () { loadSteps({size: this.value || NONE}); });
  $('stBrand').addEventListener('change',function () { loadSteps({brand: this.value || NONE}); });
  $('stUnit').addEventListener('change', function () { loadSteps({unit: this.value}); });
  $('stExtra').addEventListener('change',function () { loadSteps({extra: this.value || NONE}); });
  var qT = null;
  $('stLlpQ').addEventListener('input', function () { clearTimeout(qT); qT = setTimeout(function () { loadSteps({}); }, 250); });
  $('stLlpAdd').addEventListener('click',   function () { addLadder('add_llp',   {}, 'stLlpNew'); });
  $('stSizeAdd').addEventListener('click',  function () { addLadder('add_size',  {code: ''}, 'stSizeNew'); });
  $('stBrandAdd').addEventListener('click', function () { addLadder('add_brand', {code: ''}, 'stBrandNew'); });
  $('stExtraAdd').addEventListener('click', function () { addLadder('add_extra', {}, 'stExtraNew'); });
  $('stUnitAdd').addEventListener('click',  function () { addLadder('add_unit',  {code: ''}, 'stUnitNew'); });
  $('nlL1').addEventListener('change', function () { loadNlL2(''); });
  $('nlGo').addEventListener('click', doNewLlp);
})();
</script>

<?php
uiFoot('ผูก 2 ขั้น: Mango → ตัวสินค้า (LLP) → รหัส IC · CatID/CharID เป็นของ LLP (มติ 28-29) · '
     . 'รหัส Mango ห้ามถือยอด ของทุกชิ้นต้องอยู่ใต้ IC (มติ 34)');
