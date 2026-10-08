<?php
/**
 * CONNEXT — mango_master.php : ทะเบียนรหัสวัสดุ Mango
 *
 * มติ 35 — เดิมทะเบียนนี้เพิ่มไม่ได้เลย (มาจาก import ครั้งเดียว 6,814 รหัส)
 * หน้านี้เปิดทางให้ เพิ่ม / แก้ / นำเข้าเป็นชุดด้วย Excel — โครงเดียวกับ llp_master.php
 *
 * รหัส Mango ไม่ใช่ของที่เบิกแล้ว (มติ 34) แต่ยังต้องมีเพื่อ:
 *   จับคู่บรรทัดใบสั่งซื้อตอน OCR · ic_suggest_map · รายงานแปลงรหัส Mango → IC
 *
 * ADM (R0) เท่านั้น — งานแตะ master ตามมติ 16
 * (เพิ่มรหัสระหว่างตรวจใบ PO ทำได้ด้วยสิทธิ์คลัง — คนละทางกัน ดู po.php)
 *
 * PHP 7.4-compatible เท่านั้น
 */

require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/mango.php';
require __DIR__ . '/includes/xlsx_lite.php';

$user = uiGuard();
if (!uiIsAdmin($user)) {
    http_response_code(403);
    if (uiIsEmbedded()) { uiEmbedNotice('หน้าทะเบียนวัสดุ Mango สงวนไว้สำหรับผู้ดูแลระบบ (ADM)', false); }
    echo '<meta charset="utf-8"><p style="font-family:sans-serif;padding:24px">'
       . 'หน้าทะเบียนวัสดุ Mango สงวนไว้สำหรับผู้ดูแลระบบ (ADM)</p>';
    exit;
}

const MM_PER_PAGE = 200;
const MM_ERR_SHOW = 40;
const MM_SHEET    = 'ทะเบียน Mango';
const MM_HEAD     = ['รหัส Mango', 'ชื่อวัสดุ', 'หน่วย', 'กลุ่มย่อย', 'CatID', 'CharID', 'ใช้ในใบ PO'];

$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$action = $isPost ? (string)($_POST['action'] ?? '') : '';
$csrfOk = $isPost && hash_equals(csrfToken(), (string)($_POST['csrf'] ?? ''));
$notice = null;
$plan   = null;

// ═══════════════════════════════════════════════════════════════════════════
// ส่งออก .xlsx — ตารางทะเบียน + ชีตคำอธิบาย
// ═══════════════════════════════════════════════════════════════════════════
if (isset($_GET['export'])) {
    $rows = mangoRows($pdo, ['q' => (string)($_GET['q'] ?? '')]);

    $data = [MM_HEAD];
    foreach ($rows as $r) {
        $data[] = [
            (string)$r['mat_code'],
            (string)$r['name'],
            (string)$r['unit'],
            (string)($r['subgroup_name'] ?? ''),
            (string)$r['cat_id'],
            (string)($r['char_id'] ?? ''),
            (string)(int)$r['po_uses'],
        ];
    }

    $last = max(2, count($data)) + 200;    // เผื่อแถวที่ผู้ใช้เพิ่มเองท้ายไฟล์
    $note = [
        ['ความหมายของแต่ละช่อง', ''],
        ['', ''],
        ['รหัส Mango', 'กุญแจของแถว — ต้องตรงกับรหัสที่พิมพ์อยู่บนใบสั่งซื้อ (ปกติ 14 หลัก)'],
        ['ชื่อวัสดุ', 'บังคับกรอกสำหรับรหัสใหม่ · ใช้โชว์ตอนจับคู่บรรทัดใบ PO'],
        ['หน่วย', 'หน่วยซื้อตามใบ เช่น เส้น กล่อง ชุด'],
        ['กลุ่มย่อย', 'ไม่บังคับ — ใช้จัดกลุ่มในทะเบียนเฉย ๆ'],
        ['CatID / CharID', 'ไม่บังคับ · **ไม่มีผลกับฟอร์มเบิกแล้ว** (เบิกได้เฉพาะรหัส IC — มติ 34)'],
        ['', 'เก็บไว้เพื่อให้ทะเบียนตรงกับระบบ Mango ต้นทางเท่านั้น'],
        ['ใช้ในใบ PO', 'ระบบเติมให้ — จำนวนบรรทัดใบสั่งซื้อที่อ้างรหัสนี้ (แก้ไม่มีผล)'],
        ['', ''],
        ['กติกาการกรอก', ''],
        ['1. เพิ่มรหัสใหม่ได้เลย', 'พิมพ์ต่อท้ายแถวสุดท้าย ใส่รหัส + ชื่อ (+ หน่วย) ให้ครบ'],
        ['2. แก้ของเดิม', 'แก้ช่องที่ต้องการทับไปได้เลย — ช่องที่เว้นว่างจะไม่ล้างค่าเดิม'],
        ['3. ห้ามแก้ช่องรหัส', 'เปลี่ยนรหัสเดิม = ระบบจะนับเป็นรหัสใหม่ ของเก่ายังอยู่'],
        ['4. รหัสซ้ำในไฟล์', 'ใช้บรรทัดแรก บรรทัดที่ซ้ำจะถูกรายงานและข้าม'],
        ['5. ลบแถวออกจากไฟล์', 'ไม่ได้แปลว่าลบจากระบบ — การนำเข้าไม่เคยลบอะไรทิ้ง'],
    ];

    $xlsx = xlsxWrite([
        MM_SHEET => [
            'header' => true,
            'widths' => [18, 46, 12, 26, 10, 10, 12],
            'rows'   => $data,
            'validations' => [
                ['sqref' => 'E2:E' . $last, 'list' => IC_CAT_IDS,
                 'errorTitle' => 'CatID ไม่ถูกต้อง',
                 'error'      => 'เว้นว่างได้ หรือเลือก ' . implode(' / ', IC_CAT_IDS)],
                ['sqref' => 'F2:F' . $last, 'list' => IC_CHAR_IDS,
                 'errorTitle' => 'CharID ไม่ถูกต้อง',
                 'error'      => 'เว้นว่างได้ หรือเลือก ' . implode(' / ', IC_CHAR_IDS)],
            ],
        ],
        'คำอธิบาย' => ['header' => true, 'widths' => [26, 74], 'rows' => $note],
    ]);

    $name = 'CONNEXT-Mango-' . date('Ymd-Hi') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . strlen($xlsx));
    echo $xlsx;
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// อ่านไฟล์ที่อัปมา → แผนการนำเข้า (ใช้ทั้ง preview และ commit)
// ═══════════════════════════════════════════════════════════════════════════
function mmBuildPlan(PDO $pdo, string $path): array {
    $sheets = xlsxReadSheets($path);

    $norm = function ($s) { return strtolower(preg_replace('/\s+/u', '', (string)$s)); };
    $rows = null; $map = [];
    foreach ($sheets as $sheetRows) {
        if (!$sheetRows) { continue; }
        $idx = ['code' => null, 'name' => null, 'unit' => null, 'sub' => null, 'cat' => null, 'char' => null];
        foreach ($sheetRows[0] as $i => $h) {
            $h = $norm($h);
            if ($idx['code'] === null && (strpos($h, 'รหัส') !== false || strpos($h, 'matcode') !== false)) { $idx['code'] = $i; }
            if ($idx['name'] === null && (strpos($h, 'ชื่อ') !== false || strpos($h, 'name') !== false))     { $idx['name'] = $i; }
            if ($idx['unit'] === null && (strpos($h, 'หน่วย') !== false || strpos($h, 'unit') !== false))    { $idx['unit'] = $i; }
            if ($idx['sub']  === null && (strpos($h, 'กลุ่ม') !== false || strpos($h, 'subgroup') !== false)) { $idx['sub']  = $i; }
            if ($idx['cat']  === null && strpos($h, 'catid') !== false)  { $idx['cat']  = $i; }
            if ($idx['char'] === null && strpos($h, 'charid') !== false) { $idx['char'] = $i; }
        }
        if ($idx['code'] !== null && $idx['name'] !== null) { $rows = $sheetRows; $map = $idx; break; }
    }
    if ($rows === null) {
        throw new RuntimeException('ไม่พบตารางที่มีคอลัมน์ "รหัส Mango" กับ "ชื่อวัสดุ" ในไฟล์ — '
            . 'ให้ดาวน์โหลดไฟล์ตั้งต้นจากหน้านี้แล้วกรอกทับ');
    }

    // สถานะปัจจุบันทั้งทะเบียน (รวมรหัส IC ไว้กันชนรหัสกัน)
    $cur = [];
    $q = $pdo->query('SELECT mat_code, code_type, name, unit, subgroup_name, cat_id, char_id FROM materials');
    foreach ($q->fetchAll() as $r) { $cur[(string)$r['mat_code']] = $r; }

    $get = function (array $row, $i) { return $i === null ? '' : trim((string)($row[$i] ?? '')); };

    $plan = ['new' => [], 'change' => [], 'same' => 0, 'errors' => [], 'seen' => 0];
    $used = [];

    foreach ($rows as $i => $row) {
        if ($i === 0) { continue; }
        $code = mangoNormalizeCode($get($row, $map['code']));
        $name = $get($row, $map['name']);
        $unit = $get($row, $map['unit']);
        $sub  = $get($row, $map['sub']);
        $cat  = $get($row, $map['cat']);
        $chr  = $get($row, $map['char']);
        $line = $i + 1;

        if ($code === '' && $name === '' && $unit === '' && $sub === '' && $cat === '' && $chr === '') { continue; }
        $plan['seen']++;

        if ($code === '') {
            $plan['errors'][] = ['line' => $line, 'code' => '', 'msg' => 'ไม่มีรหัส Mango'];
            continue;
        }
        if (isset($used[$code])) {
            $plan['errors'][] = ['line' => $line, 'code' => $code,
                                 'msg' => 'รหัสซ้ำกับบรรทัด ' . $used[$code] . ' — ใช้บรรทัดแรกเท่านั้น'];
            continue;
        }
        if (isset($cur[$code]) && (string)$cur[$code]['code_type'] === 'ic') {
            $plan['errors'][] = ['line' => $line, 'code' => $code,
                                 'msg' => 'รหัสนี้เป็นรหัส IC ที่ระบบออกเอง — ใช้เป็นรหัส Mango ไม่ได้'];
            continue;
        }
        if ($cat !== '' && icNormalizeCat($cat) === null) {
            $plan['errors'][] = ['line' => $line, 'code' => $code,
                                 'msg' => 'CatID "' . $cat . '" ใช้ไม่ได้ (ต้องเป็น ' . implode(' / ', IC_CAT_IDS) . ')'];
            continue;
        }
        if ($chr !== '' && icNormalizeChar($chr) === null) {
            $plan['errors'][] = ['line' => $line, 'code' => $code,
                                 'msg' => 'CharID "' . $chr . '" ใช้ไม่ได้ (ต้องเป็น ' . implode(' / ', IC_CHAR_IDS) . ')'];
            continue;
        }

        $used[$code] = $line;
        $item = ['line' => $line, 'code' => $code, 'name' => $name, 'unit' => $unit,
                 'sub' => $sub, 'cat' => $cat, 'char' => $chr];

        if (!isset($cur[$code])) {
            if ($name === '') {
                $plan['errors'][] = ['line' => $line, 'code' => $code, 'msg' => 'รหัสใหม่ — ต้องใส่ชื่อวัสดุด้วย'];
                unset($used[$code]);
                continue;
            }
            $plan['new'][] = $item;
            continue;
        }

        // มีอยู่แล้ว — เทียบว่าจะเปลี่ยนอะไรบ้าง (ช่องว่าง = ไม่แตะของเดิม)
        $c    = $cur[$code];
        $diff = [];
        if ($name !== '' && $name !== (string)$c['name'])                          { $diff[] = 'ชื่อ'; }
        if ($unit !== '' && $unit !== (string)$c['unit'])                          { $diff[] = 'หน่วย'; }
        if ($sub  !== '' && $sub  !== (string)($c['subgroup_name'] ?? ''))         { $diff[] = 'กลุ่มย่อย'; }
        if ($cat  !== '' && icNormalizeCat($cat)  !== (string)$c['cat_id'])        { $diff[] = 'CatID'; }
        if ($chr  !== '' && icNormalizeChar($chr) !== (string)($c['char_id'] ?? '')) { $diff[] = 'CharID'; }

        if (!$diff) { $plan['same']++; continue; }

        $item['old']  = (string)$c['name'] . ' · ' . (string)$c['unit'];
        $item['diff'] = implode(', ', $diff);
        $plan['change'][] = $item;
    }

    return $plan;
}

function mmStashPath(string $token): ?string {
    $box = $_SESSION['mm_import'] ?? [];
    return isset($box[$token]['path']) && is_file($box[$token]['path']) ? (string)$box[$token]['path'] : null;
}

// ═══════════════════════════════════════════════════════════════════════════
// งานเขียน
// ═══════════════════════════════════════════════════════════════════════════
if ($isPost && !$csrfOk) {
    $notice = ['bad', 'CSRF token ไม่ถูกต้อง — โหลดหน้าใหม่แล้วลองอีกครั้ง'];
    $action = '';
}

// ── เพิ่ม/แก้ทีละตัว ───────────────────────────────────────────────────────
if ($action === 'save_one') {
    $r = mangoUpsert($pdo, [
        'mat_code'      => (string)($_POST['mat_code'] ?? ''),
        'name'          => (string)($_POST['name'] ?? ''),
        'unit'          => (string)($_POST['unit'] ?? ''),
        'subgroup_name' => (string)($_POST['subgroup_name'] ?? ''),
        'cat_id'        => (string)($_POST['cat_id'] ?? ''),
        'char_id'       => (string)($_POST['char_id'] ?? ''),
    ], $user);

    if (!$r['ok']) {
        $notice = ['bad', $r['error']];
    } elseif ($r['action'] === 'insert') {
        $notice = ['ok', 'เพิ่มรหัส ' . $r['code'] . ' เข้าทะเบียนแล้ว'];
    } elseif ($r['action'] === 'update') {
        $notice = ['ok', 'บันทึกรหัส ' . $r['code'] . ' แล้ว'];
    } else {
        $notice = ['warn', 'ค่าเดิมอยู่แล้ว — ไม่มีอะไรเปลี่ยน'];
    }
}

// ── อัปไฟล์ → คำนวณแผน ────────────────────────────────────────────────────
if ($action === 'preview') {
    if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $notice = ['bad', 'ยังไม่ได้เลือกไฟล์ หรืออัปโหลดไม่สำเร็จ'];
    } else {
        $token = bin2hex(random_bytes(8));
        $tmp   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cnx_mango_' . $token . '.xlsx';
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $tmp)) {
            $notice = ['bad', 'ย้ายไฟล์ที่อัปโหลดไม่สำเร็จ'];
        } else {
            try {
                $plan          = mmBuildPlan($pdo, $tmp);
                $plan['token'] = $token;
                $plan['name']  = (string)($_FILES['file']['name'] ?? 'ไฟล์');

                $box = $_SESSION['mm_import'] ?? [];
                foreach ($box as $k => $v) {
                    if ((int)($v['ts'] ?? 0) < time() - 3600) { @unlink((string)$v['path']); unset($box[$k]); }
                }
                $box[$token] = ['path' => $tmp, 'ts' => time()];
                $_SESSION['mm_import'] = $box;
            } catch (Throwable $ex) {
                @unlink($tmp);
                $notice = ['bad', 'อ่านไฟล์ไม่สำเร็จ: ' . $ex->getMessage()];
            }
        }
    }
}

// ── ยืนยันนำเข้า ───────────────────────────────────────────────────────────
if ($action === 'commit') {
    $token = (string)($_POST['token'] ?? '');
    $path  = mmStashPath($token);
    if ($path === null) {
        $notice = ['bad', 'ไม่พบไฟล์ที่ตรวจไว้ — อัปโหลดใหม่อีกครั้ง'];
    } else {
        try {
            $p    = mmBuildPlan($pdo, $path);      // คำนวณใหม่ กัน DB ขยับระหว่างกดยืนยัน
            $todo = array_merge($p['new'], $p['change']);

            $nNew = 0; $nUpd = 0; $bad = [];
            $pdo->beginTransaction();
            foreach ($todo as $t) {
                $r = mangoUpsert($pdo, [
                    'mat_code'      => $t['code'],
                    'name'          => $t['name'],
                    'unit'          => $t['unit'],
                    'subgroup_name' => $t['sub'],
                    'cat_id'        => $t['cat'],
                    'char_id'       => $t['char'],
                ], $user);
                if (!$r['ok'])                       { $bad[] = $t['code'] . ': ' . $r['error']; }
                elseif ($r['action'] === 'insert')   { $nNew++; }
                elseif ($r['action'] === 'update')   { $nUpd++; }
            }
            $pdo->commit();

            $box = $_SESSION['mm_import'] ?? [];
            @unlink($path);
            unset($box[$token]);
            $_SESSION['mm_import'] = $box;

            $notice = [($bad || $p['errors']) ? 'warn' : 'ok',
                'นำเข้าเรียบร้อย — เพิ่มใหม่ ' . number_format($nNew) . ' รหัส · แก้ของเดิม ' . number_format($nUpd) . ' รหัส'
                . ($p['errors'] ? ' · ข้ามบรรทัดที่มีปัญหา ' . count($p['errors']) . ' บรรทัด' : '')
                . ($bad ? ' · ล้มเหลว ' . count($bad) . ' รายการ' : '')];

        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $notice = ['bad', 'นำเข้าไม่สำเร็จ: ' . $ex->getMessage()];
        }
    }
}

if ($action === 'discard') {
    $token = (string)($_POST['token'] ?? '');
    $path  = mmStashPath($token);
    if ($path !== null) { @unlink($path); }
    $box = $_SESSION['mm_import'] ?? [];
    unset($box[$token]);
    $_SESSION['mm_import'] = $box;
    $notice = ['warn', 'ยกเลิกการนำเข้าแล้ว — ไม่มีอะไรถูกบันทึก'];
}

// ═══════════════════════════════════════════════════════════════════════════
// ข้อมูลสำหรับจอ
// ═══════════════════════════════════════════════════════════════════════════
$q     = trim((string)($_GET['q'] ?? ''));
$state = (string)($_GET['state'] ?? '');
$page  = max(1, (int)($_GET['page'] ?? 1));

$filter = ['q' => $q, 'state' => $state];
$found  = mangoCount($pdo, $filter);
$rows   = mangoRows($pdo, $filter + ['limit' => MM_PER_PAGE, 'offset' => ($page - 1) * MM_PER_PAGE]);
$pages  = max(1, (int)ceil($found / MM_PER_PAGE));

$total  = mangoCount($pdo, []);
$noName = mangoCount($pdo, ['state' => 'noname']);
$used   = mangoCount($pdo, ['state' => 'used']);

// รหัสที่ใบสั่งซื้อเคยอ้าง แต่ทะเบียนไม่มี — ตัวเลขนี้คือ "งานค้าง" ของหน้านี้
$orphan = (int)$pdo->query(
    "SELECT COUNT(DISTINCT pl.mat_code) FROM po_lines pl
      LEFT JOIN materials m ON m.mat_code = pl.mat_code
      WHERE pl.mat_code IS NOT NULL AND pl.mat_code <> '' AND m.id IS NULL"
)->fetchColumn();

$catLabels  = icCatLabels();
$charLabels = icCharLabels();

function mmUrl(array $over = []): string {
    $qs = array_merge([
        'q'     => (string)($_GET['q'] ?? ''),
        'state' => (string)($_GET['state'] ?? ''),
        'page'  => (string)($_GET['page'] ?? '1'),
    ], $over);
    $qs = array_filter($qs, function ($v) { return $v !== '' && $v !== null; });
    return uiUrl('mango_master.php' . ($qs ? '?' . http_build_query($qs) : ''));
}

uiHead('ทะเบียนวัสดุ Mango', 'รหัสอ้างอิงสำหรับจับคู่ใบสั่งซื้อ — ไม่ใช่รหัสที่เบิก', $user, '📚', uiIsEmbedded());
uiBackToAdmin('materials');
?>

<?php if ($notice !== null): ?>
  <div class="banner b-<?= $notice[0] === 'ok' ? 'ok' : ($notice[0] === 'warn' ? 'warn' : 'bad') ?>">
    <?= e($notice[1]) ?>
  </div>
<?php endif; ?>


<div class="card">
  <h2><span class="num">1</span> ทะเบียน <span class="sp">รหัส Mango ทั้งหมด <?= number_format($total) ?> รหัส</span></h2>

  <div class="row" style="align-items:center">
    <div class="stat"><b><?= number_format($total) ?></b><span>รหัสในทะเบียน</span></div>
    <div class="stat"><b><?= number_format($used) ?></b><span>เคยอยู่ในใบ PO</span></div>
    <div class="stat <?= $noName > 0 ? 'warn' : '' ?>"><b><?= number_format($noName) ?></b><span>ไม่มีชื่อ/หน่วย</span></div>
    <div class="stat <?= $orphan > 0 ? 'bad' : '' ?>"><b><?= number_format($orphan) ?></b><span>อยู่ในใบ PO แต่ไม่มีในทะเบียน</span></div>
  </div>

  <?php if ($orphan > 0): ?>
    <div class="banner b-warn" style="margin-top:11px">
      มี <b><?= number_format($orphan) ?></b> รหัสที่ใบสั่งซื้อเคยอ้างถึงแต่ยังไม่มีในทะเบียน —
      กรองดูได้ที่ <a href="<?= uiUrl('po.php') ?>">จอใบคุม</a> หรือเพิ่มเองที่หัวข้อ 3 ด้านล่าง
    </div>
  <?php endif; ?>

  <div class="row" style="margin-top:12px">
    <a href="<?= uiUrl('mango_master.php?export=xlsx' . ($q !== '' ? '&q=' . urlencode($q) : '')) ?>" data-cnx-no-embed>
      <button type="button">⤓ ดาวน์โหลด Excel<?= $q !== '' ? ' (เฉพาะผลค้นหา)' : ' (ทั้งหมด ' . number_format($total) . ' รหัส)' ?></button></a>
  </div>
  <p class="small" style="margin-top:8px">
    ในไฟล์เพิ่มรหัสใหม่ได้เลยโดยพิมพ์ต่อท้าย · ช่องที่เว้นว่างจะไม่ล้างค่าเดิม ·
    <b>CatID/CharID ของรหัส Mango ไม่มีผลกับฟอร์มเบิกแล้ว</b> (เบิกได้เฉพาะรหัส IC — มติ 34)
  </p>
</div>


<div class="card">
  <h2><span class="num">2</span> นำเข้าไฟล์ Excel</h2>

  <?php if ($plan === null): ?>
    <form method="post" enctype="multipart/form-data" class="row" style="align-items:flex-end">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
      <input type="hidden" name="action" value="preview">
      <div style="flex:1;min-width:240px">
        <label class="fld">ไฟล์ .xlsx (เพิ่มรหัสใหม่ / แก้ของเดิม)</label>
        <input type="file" name="file" accept=".xlsx" required style="width:100%">
      </div>
      <div><button type="submit">ตรวจไฟล์ก่อนนำเข้า</button></div>
    </form>
    <p class="small" style="margin-top:8px">การนำเข้า <b>ไม่เคยลบอะไรทิ้ง</b> — ลบแถวออกจากไฟล์ไม่ได้แปลว่าลบจากระบบ</p>

  <?php else: $willDo = count($plan['new']) + count($plan['change']); ?>
    <div class="banner b-warn">
      ตรวจไฟล์ <b><?= e($plan['name']) ?></b> แล้ว — <b>ยังไม่บันทึกอะไรทั้งสิ้น</b>
    </div>

    <div class="row" style="margin:11px 0">
      <div class="stat"><b><?= number_format(count($plan['new'])) ?></b><span>รหัสใหม่</span></div>
      <div class="stat <?= $plan['change'] ? 'warn' : '' ?>"><b><?= number_format(count($plan['change'])) ?></b><span>แก้ของเดิม</span></div>
      <div class="stat"><b><?= number_format($plan['same']) ?></b><span>เหมือนเดิม</span></div>
      <div class="stat <?= $plan['errors'] ? 'bad' : '' ?>"><b><?= number_format(count($plan['errors'])) ?></b><span>บรรทัดมีปัญหา</span></div>
    </div>

    <?php if ($plan['errors']): ?>
      <div class="scroll" style="max-height:230px;margin-bottom:11px">
        <table>
          <tr><th>บรรทัด</th><th>รหัส</th><th>ปัญหา</th></tr>
          <?php foreach (array_slice($plan['errors'], 0, MM_ERR_SHOW) as $er): ?>
            <tr><td class="num"><?= (int)$er['line'] ?></td>
                <td class="mono"><?= e((string)$er['code']) ?></td>
                <td class="small"><?= e((string)$er['msg']) ?></td></tr>
          <?php endforeach; ?>
        </table>
      </div>
      <?php if (count($plan['errors']) > MM_ERR_SHOW): ?>
        <p class="small">…และอีก <?= count($plan['errors']) - MM_ERR_SHOW ?> บรรทัด (จะถูกข้าม ไม่นำเข้า)</p>
      <?php endif; ?>
    <?php endif; ?>

    <?php foreach ([['new', 'รหัสใหม่ที่จะเพิ่ม'], ['change', 'แถวที่จะแก้ของเดิม']] as $blk):
        if (!$plan[$blk[0]]) { continue; } ?>
      <h3 class="small" style="margin:12px 0 6px"><?= $blk[1] ?></h3>
      <div class="scroll" style="max-height:240px">
        <table>
          <tr><th>บรรทัด</th><th>รหัส</th><th>ชื่อ</th><th>หน่วย</th><th><?= $blk[0] === 'change' ? 'เปลี่ยน' : 'กลุ่มย่อย' ?></th></tr>
          <?php foreach (array_slice($plan[$blk[0]], 0, 100) as $c): ?>
            <tr><td class="num"><?= (int)$c['line'] ?></td>
                <td class="mono"><?= e((string)$c['code']) ?></td>
                <td class="small"><?= e((string)$c['name']) ?></td>
                <td class="small"><?= e((string)$c['unit']) ?></td>
                <td class="small"><?= e((string)($blk[0] === 'change' ? ($c['diff'] ?? '') : $c['sub'])) ?></td></tr>
          <?php endforeach; ?>
        </table>
      </div>
      <?php if (count($plan[$blk[0]]) > 100): ?>
        <p class="small">…และอีก <?= count($plan[$blk[0]]) - 100 ?> แถว</p>
      <?php endif; ?>
    <?php endforeach; ?>

    <form method="post" class="row" style="margin-top:13px">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
      <input type="hidden" name="token" value="<?= e((string)$plan['token']) ?>">
      <div>
        <button type="submit" name="action" value="commit" <?= $willDo === 0 ? 'disabled' : '' ?>>
          ✓ ยืนยันนำเข้า <?= number_format($willDo) ?> รหัส
          <?= $plan['errors'] ? '(ข้าม ' . count($plan['errors']) . ' บรรทัดที่มีปัญหา)' : '' ?>
        </button>
      </div>
      <div><button type="submit" name="action" value="discard" class="ghost">ยกเลิก</button></div>
    </form>
  <?php endif; ?>
</div>


<div class="card">
  <h2><span class="num">3</span> เพิ่มรหัสใหม่ทีละตัว</h2>
  <form method="post" class="row" style="align-items:flex-end">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="action" value="save_one">
    <div><label class="fld">รหัส Mango *</label>
      <input type="text" name="mat_code" required class="mono" style="width:170px" placeholder="เช่น PM060060200000"></div>
    <div style="flex:1;min-width:220px"><label class="fld">ชื่อวัสดุ *</label>
      <input type="text" name="name" required style="width:100%"></div>
    <div><label class="fld">หน่วย</label>
      <input type="text" name="unit" style="width:90px" placeholder="เส้น / กล่อง"></div>
    <div style="min-width:150px"><label class="fld">กลุ่มย่อย</label>
      <input type="text" name="subgroup_name" style="width:100%"></div>
    <div><label class="fld">CatID</label>
      <select name="cat_id">
        <option value="">— ไม่ระบุ —</option>
        <?php foreach (IC_CAT_IDS as $c): ?><option value="<?= $c ?>"><?= $c ?></option><?php endforeach; ?>
      </select></div>
    <div><label class="fld">CharID</label>
      <select name="char_id">
        <option value="">— ไม่ระบุ —</option>
        <?php foreach (IC_CHAR_IDS as $c): ?><option value="<?= $c ?>"><?= $c ?></option><?php endforeach; ?>
      </select></div>
    <div><button type="submit">＋ เพิ่มเข้าทะเบียน</button></div>
  </form>
  <p class="small" style="margin-top:8px">
    รหัสที่มีอยู่แล้วจะถูกอัปเดตแทนการเพิ่มซ้ำ · รหัสที่ชนกับรหัส IC ของระบบจะถูกปฏิเสธ
  </p>
</div>


<div class="card">
  <h2><span class="num">4</span> รายการ <span class="sp">พบ <?= number_format($found) ?> รหัส · หน้า <?= $page ?>/<?= $pages ?></span></h2>

  <form method="get" class="row">
    <?php if (uiIsEmbedded()): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
    <div style="flex:1;min-width:220px">
      <label class="fld">รหัส / ชื่อ / กลุ่มย่อย</label>
      <input type="text" name="q" value="<?= e($q) ?>" style="width:100%" placeholder="พิมพ์แล้วกด Enter">
    </div>
    <div>
      <label class="fld">กรอง</label>
      <select name="state" onchange="this.form.submit()">
        <option value="">— ทั้งหมด —</option>
        <option value="used"   <?= $state === 'used'   ? 'selected' : '' ?>>เคยอยู่ในใบ PO</option>
        <option value="noname" <?= $state === 'noname' ? 'selected' : '' ?>>ไม่มีชื่อ/หน่วย</option>
      </select>
    </div>
    <div style="align-self:flex-end"><button type="submit">ค้นหา</button></div>
  </form>

  <?php if (empty($rows)): ?>
    <p class="small" style="margin-top:11px">ไม่พบรหัสที่ตรงเงื่อนไข</p>
  <?php else: ?>
    <div class="scroll" style="margin-top:11px">
      <table>
        <tr><th>รหัส</th><th>ชื่อวัสดุ</th><th>หน่วย</th><th>กลุ่มย่อย</th>
            <th>Cat / Char</th><th class="num">ใน PO</th><th></th></tr>
        <?php foreach ($rows as $r): $fid = 'g' . (int)$r['id']; ?>
          <tr>
            <td class="mono"><?= e((string)$r['mat_code']) ?></td>
            <td>
              <form method="post" id="<?= $fid ?>" style="display:none">
                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action" value="save_one">
                <input type="hidden" name="mat_code" value="<?= e((string)$r['mat_code']) ?>">
              </form>
              <input type="text" name="name" form="<?= $fid ?>" value="<?= e((string)$r['name']) ?>" style="width:100%;min-width:200px">
            </td>
            <td><input type="text" name="unit" form="<?= $fid ?>" value="<?= e((string)$r['unit']) ?>" style="width:80px"></td>
            <td><input type="text" name="subgroup_name" form="<?= $fid ?>" value="<?= e((string)($r['subgroup_name'] ?? '')) ?>" style="width:130px"></td>
            <td class="small">
              <select name="cat_id" form="<?= $fid ?>">
                <?php foreach (IC_CAT_IDS as $c): ?>
                  <option value="<?= $c ?>" <?= (string)$r['cat_id'] === $c ? 'selected' : '' ?>><?= $c ?></option>
                <?php endforeach; ?>
              </select>
              <select name="char_id" form="<?= $fid ?>">
                <option value="">—</option>
                <?php foreach (IC_CHAR_IDS as $c): ?>
                  <option value="<?= $c ?>" <?= (string)($r['char_id'] ?? '') === $c ? 'selected' : '' ?>><?= $c ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td class="num"><?= (int)$r['po_uses'] > 0 ? (int)$r['po_uses'] : '—' ?></td>
            <td><button type="submit" form="<?= $fid ?>" class="ghost">บันทึก</button></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>

    <?php if ($pages > 1): ?>
      <div class="row" style="margin-top:11px;align-items:center">
        <?php if ($page > 1): ?>
          <a href="<?= mmUrl(['page' => (string)($page - 1)]) ?>"><button type="button" class="ghost">‹ ก่อนหน้า</button></a>
        <?php endif; ?>
        <span class="small">หน้า <?= $page ?> จาก <?= $pages ?></span>
        <?php if ($page < $pages): ?>
          <a href="<?= mmUrl(['page' => (string)($page + 1)]) ?>"><button type="button" class="ghost">ถัดไป ›</button></a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<style>
.stat{background:#f1f5f9;border-radius:9px;padding:9px 15px;min-width:104px}.stat b{display:block;font-size:1.25rem;line-height:1.2}.stat span{font-size:.75rem;color:#64748b}.stat.warn{background:#fef3c7}.stat.bad{background:#fee2e2}




</style>

<?php
uiFoot('ทะเบียน Mango = ข้อมูลอ้างอิงสำหรับจับคู่ใบสั่งซื้อ · ของที่เบิกได้จริงคือรหัส IC เท่านั้น (มติ 34)');
