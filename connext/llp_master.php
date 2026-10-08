<?php
/**
 * CONNEXT — llp_master.php : ตั้งค่า CatID / CharID ให้ "ตัวสินค้า" (LLP)
 *
 * มติ 28-32 (db/design_po_ocr_ic_v1.md):
 *   · llp_products = ต้นทางเดียวของ CatID/CharID · IC ทุกตัวใต้ LLP ได้ค่าเดียวกัน
 *   · ic_items/materials เป็นสำเนาที่ระบบเขียนตาม LLP เสมอ (cascade ตอนแก้)
 *   · NULL = ยังไม่ตั้งค่า → ออก IC ใต้ LLP นั้นไม่ได้จนกว่า ADM จะตั้งให้
 *   · ตั้งค่าทีละพัน ๆ แถวผ่าน Excel (.xlsx มี dropdown ในไฟล์) — preview ก่อน commit
 *
 * ADM (R0) เท่านั้น — งานแตะ master ตามมติ 16
 * PHP 7.4-compatible เท่านั้น
 */

require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/ic.php';
require __DIR__ . '/includes/xlsx_lite.php';

$user = uiGuard();
if (!uiIsAdmin($user)) {
    http_response_code(403);
    if (uiIsEmbedded()) { uiEmbedNotice('หน้าตั้งค่าตัวสินค้าสงวนไว้สำหรับผู้ดูแลระบบ (ADM)', false); }
    echo '<meta charset="utf-8"><p style="font-family:sans-serif;padding:24px">'
       . 'หน้าตั้งค่าตัวสินค้าสงวนไว้สำหรับผู้ดูแลระบบ (ADM)</p>';
    exit;
}

const LM_PER_PAGE  = 200;                       // แถวต่อหน้าในตารางล่าง
const LM_ERR_SHOW  = 40;                        // จำนวนบรรทัดผิดที่โชว์ใน preview
const LM_SHEET     = 'ตั้งค่า LLP';
const LM_HEAD      = ['รหัสตัวสินค้า (LLP)', 'กลุ่มใหญ่', 'ชื่อกลุ่มใหญ่', 'หมวด', 'ชื่อหมวด',
                      'ชื่อตัวสินค้า', 'IC ที่ออกแล้ว', 'CatID', 'CharID'];

$isPost  = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$action  = $isPost ? (string)($_POST['action'] ?? '') : '';
$csrfOk  = $isPost && hash_equals(csrfToken(), (string)($_POST['csrf'] ?? ''));
$notice  = null;    // [ok|warn|bad, ข้อความ]
$plan    = null;    // ผล preview ที่จะโชว์

// ═══════════════════════════════════════════════════════════════════════════
// อ่านตาราง LLP พร้อมจำนวน IC ที่ออกไปแล้ว (ใช้ทั้ง export / จอ / import)
// ═══════════════════════════════════════════════════════════════════════════
/** เงื่อนไขกรองของจอนี้ — ใช้ร่วมกันระหว่างตัวอ่านแถวกับตัวนับ */
function lmWhere(array $f): array {
    $w    = ['1=1'];
    $args = [];

    if (!empty($f['q'])) {
        $like = '%' . likeEscape((string)$f['q']) . '%';
        $w[]  = '(p.llp_code LIKE ? OR p.llp_name LIKE ?)';
        array_push($args, $like, $like);
    }
    if (!empty($f['l1'])) { $w[] = 'p.l1_code = ?'; $args[] = (string)$f['l1']; }
    if (($f['state'] ?? '') === 'unset') { $w[] = '(p.cat_id IS NULL OR p.char_id IS NULL)'; }
    if (($f['state'] ?? '') === 'set')   { $w[] = '(p.cat_id IS NOT NULL AND p.char_id IS NOT NULL)'; }

    return [implode(' AND ', $w), $args];
}

function lmRows(PDO $pdo, array $f = []): array {
    list($where, $args) = lmWhere($f);

    $sql = 'SELECT p.llp_code, p.llp_name, p.l1_code, p.l2_code, p.cat_id, p.char_id, p.is_active,
                   g.l1_name, c.l2_name,
                   COALESCE(i.n, 0) AS ic_count
              FROM llp_products p
              JOIN l1_groups g     ON g.l1_code = p.l1_code
              JOIN l2_categories c ON c.l1_code = p.l1_code AND c.l2_code = p.l2_code
              LEFT JOIN (SELECT llp_code, COUNT(*) n FROM ic_items GROUP BY llp_code) i
                     ON i.llp_code = p.llp_code
             WHERE ' . $where . '
             ORDER BY p.l1_code, p.l2_code, p.llp_code';
    if (!empty($f['limit'])) {
        $sql .= ' LIMIT ' . (int)$f['limit'] . ' OFFSET ' . (int)($f['offset'] ?? 0);
    }
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/** นับแถวตามเงื่อนไขเดียวกับ lmRows (ไม่ดึงข้อมูลจริงมานับ) */
function lmCount(PDO $pdo, array $f = []): int {
    list($where, $args) = lmWhere($f);
    $st = $pdo->prepare('SELECT COUNT(*) FROM llp_products p WHERE ' . $where);
    $st->execute($args);
    return (int)$st->fetchColumn();
}

// ═══════════════════════════════════════════════════════════════════════════
// ส่งออก .xlsx — 2 ชีต: ตารางให้กรอก + คำอธิบายค่า
// ═══════════════════════════════════════════════════════════════════════════
if (isset($_GET['export'])) {
    $scope = (string)($_GET['scope'] ?? 'all');
    $rows  = lmRows($pdo, ['state' => $scope === 'unset' ? 'unset' : '']);

    $data = [LM_HEAD];
    foreach ($rows as $r) {
        $data[] = [
            (string)$r['llp_code'],
            (string)$r['l1_code'],
            (string)$r['l1_name'],
            (string)$r['l2_code'],
            (string)$r['l2_name'],
            (string)$r['llp_name'],
            (string)(int)$r['ic_count'],
            (string)($r['cat_id'] ?? ''),
            (string)($r['char_id'] ?? ''),
        ];
    }

    // dropdown ครอบทั้งคอลัมน์ H/I ตั้งแต่แถว 2 ถึงแถวสุดท้าย (+ เผื่อบรรทัดที่คนแทรกเอง)
    $last = max(2, count($data)) + 50;
    $note = [
        ['ความหมายของค่าที่กรอก', ''],
        ['', ''],
        ['CatID = หมวดอนุมัติ (คุมว่าใบเบิกต้องใช้ผู้อนุมัติระดับไหน)', ''],
    ];
    foreach (icCatLabels() as $k => $v) { $note[] = [$k, $v]; }
    $note[] = ['', ''];
    $note[] = ['CharID = ลักษณะวัสดุ (คุมว่าวัสดุโผล่ในฟอร์มไหนของแอป)', ''];
    foreach (icCharLabels() as $k => $v) { $note[] = [$k, $v]; }
    $note[] = ['', ''];
    $note[] = ['กติกาการกรอก', ''];
    $note[] = ['1. ห้ามแก้คอลัมน์ "รหัสตัวสินค้า (LLP)" — ระบบใช้เป็นกุญแจจับคู่', ''];
    $note[] = ['2. เว้นว่างทั้งคู่ = ข้ามแถวนั้น (ค่าเดิมใน DB ไม่ถูกล้าง)', ''];
    $note[] = ['3. กรอกช่องเดียวไม่ได้ ต้องกรอกทั้ง CatID และ CharID', ''];
    $note[] = ['4. แถวที่แก้ค่าเดิมและมี IC ออกไปแล้ว ระบบจะอัปเดต IC/วัสดุใต้มันให้อัตโนมัติ', ''];
    $note[] = ['5. อัปไฟล์กลับที่หน้า "ตั้งค่าตัวสินค้า (LLP)" แล้วดูสรุปก่อนกดยืนยัน', ''];

    $xlsx = xlsxWrite([
        LM_SHEET => [
            'header' => true,
            'widths' => [16, 10, 24, 8, 26, 42, 12, 10, 10],
            'rows'   => $data,
            'validations' => [
                ['sqref' => 'H2:H' . $last, 'list' => IC_CAT_IDS,
                 'errorTitle' => 'CatID ไม่ถูกต้อง',
                 'error'      => 'เลือกได้เฉพาะ ' . implode(' / ', IC_CAT_IDS)],
                ['sqref' => 'I2:I' . $last, 'list' => IC_CHAR_IDS,
                 'errorTitle' => 'CharID ไม่ถูกต้อง',
                 'error'      => 'เลือกได้เฉพาะ ' . implode(' / ', IC_CHAR_IDS)],
            ],
        ],
        'คำอธิบาย' => ['header' => true, 'widths' => [60, 40], 'rows' => $note],
    ]);

    $name = 'CONNEXT-LLP-' . ($scope === 'unset' ? 'ยังไม่ตั้งค่า-' : '') . date('Ymd-Hi') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . strlen($xlsx));
    echo $xlsx;
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// อ่านไฟล์ที่อัปมา → แผนการนำเข้า (ใช้ทั้ง preview และ commit เพื่อกัน DB ขยับ)
// ═══════════════════════════════════════════════════════════════════════════
function lmBuildPlan(PDO $pdo, string $path): array {
    $sheets = xlsxReadSheets($path);

    // หาชีตที่มีหัวตารางของเรา (ไม่ยึดชื่อชีต — คนชอบเปลี่ยนชื่อ)
    $norm = function ($s) {
        return strtolower(preg_replace('/\s+/u', '', (string)$s));
    };
    $rows = null; $map = [];
    foreach ($sheets as $sheetRows) {
        if (!$sheetRows) { continue; }
        $head = $sheetRows[0];
        $idx  = ['llp' => null, 'cat' => null, 'char' => null];
        foreach ($head as $i => $h) {
            $h = $norm($h);
            if ($idx['llp'] === null && (strpos($h, 'llp') !== false || strpos($h, 'รหัสตัวสินค้า') !== false)) { $idx['llp'] = $i; }
            if ($idx['cat'] === null && strpos($h, 'catid') !== false) { $idx['cat'] = $i; }
            if ($idx['char'] === null && strpos($h, 'charid') !== false) { $idx['char'] = $i; }
        }
        if ($idx['llp'] !== null && $idx['cat'] !== null && $idx['char'] !== null) {
            $rows = $sheetRows; $map = $idx; break;
        }
    }
    if ($rows === null) {
        throw new RuntimeException('ไม่พบตารางที่มีคอลัมน์ LLP / CatID / CharID ในไฟล์ — '
            . 'ให้ดาวน์โหลดไฟล์ตั้งต้นจากหน้านี้แล้วกรอกทับ');
    }

    // สถานะปัจจุบันของทุก LLP + จำนวน IC ใต้แต่ละตัว
    $cur = [];
    foreach (lmRows($pdo) as $r) {
        $cur[(string)$r['llp_code']] = [
            'cat'  => icNormalizeCat($r['cat_id']),
            'char' => icNormalizeChar($r['char_id']),
            'name' => (string)$r['llp_name'],
            'ic'   => (int)$r['ic_count'],
        ];
    }

    $plan = [
        'new' => [], 'change' => [], 'same' => 0, 'blank' => 0, 'errors' => [],
        'ic_affected' => 0, 'seen' => 0, 'dup' => 0,
    ];
    $used = [];

    foreach ($rows as $i => $row) {
        if ($i === 0) { continue; }                       // หัวตาราง
        $llp  = strtoupper(trim((string)($row[$map['llp']]  ?? '')));
        $cat  = trim((string)($row[$map['cat']]  ?? ''));
        $char = trim((string)($row[$map['char']] ?? ''));
        $line = $i + 1;                                   // เลขบรรทัดอย่างที่คนเห็นใน Excel

        if ($llp === '' && $cat === '' && $char === '') { continue; }   // แถวว่างล้วน
        $plan['seen']++;

        if ($llp === '') {
            $plan['errors'][] = ['line' => $line, 'llp' => '', 'msg' => 'ไม่มีรหัสตัวสินค้า'];
            continue;
        }
        if (!isset($cur[$llp])) {
            $plan['errors'][] = ['line' => $line, 'llp' => $llp, 'msg' => 'ไม่รู้จักรหัสนี้ในบันได LLP'];
            continue;
        }
        if (isset($used[$llp])) {
            $plan['dup']++;
            $plan['errors'][] = ['line' => $line, 'llp' => $llp,
                                 'msg' => 'รหัสซ้ำกับบรรทัด ' . $used[$llp] . ' — ใช้บรรทัดแรกเท่านั้น'];
            continue;
        }

        if ($cat === '' && $char === '') { $plan['blank']++; continue; }   // ข้าม ไม่ล้างค่าเดิม
        if ($cat === '' || $char === '') {
            $plan['errors'][] = ['line' => $line, 'llp' => $llp, 'msg' => 'ต้องกรอกทั้ง CatID และ CharID'];
            continue;
        }

        $nCat  = icNormalizeCat($cat);
        $nChar = icNormalizeChar($char);
        if ($nCat === null) {
            $plan['errors'][] = ['line' => $line, 'llp' => $llp,
                                 'msg' => 'CatID "' . $cat . '" ใช้ไม่ได้ (ต้องเป็น ' . implode(' / ', IC_CAT_IDS) . ')'];
            continue;
        }
        if ($nChar === null) {
            $plan['errors'][] = ['line' => $line, 'llp' => $llp,
                                 'msg' => 'CharID "' . $char . '" ใช้ไม่ได้ (ต้องเป็น ' . implode(' / ', IC_CHAR_IDS) . ')'];
            continue;
        }

        $used[$llp] = $line;
        $c = $cur[$llp];

        if ($c['cat'] === $nCat && $c['char'] === $nChar) { $plan['same']++; continue; }

        $item = ['line' => $line, 'llp' => $llp, 'name' => $c['name'],
                 'from' => ($c['cat'] ?? '—') . ' / ' . ($c['char'] ?? '—'),
                 'to'   => $nCat . ' / ' . $nChar,
                 'cat'  => $nCat, 'char' => $nChar, 'ic' => $c['ic']];

        if ($c['cat'] === null && $c['char'] === null) {
            $plan['new'][] = $item;
        } else {
            $plan['change'][] = $item;
            $plan['ic_affected'] += $c['ic'];
        }
    }

    return $plan;
}

/** ไฟล์ชั่วคราวของ preview — เก็บ path ไว้ใน session รอ commit */
function lmStashPath(string $token): ?string {
    $box = $_SESSION['lm_import'] ?? [];
    return isset($box[$token]['path']) && is_file($box[$token]['path']) ? (string)$box[$token]['path'] : null;
}

// ═══════════════════════════════════════════════════════════════════════════
// งานเขียน
// ═══════════════════════════════════════════════════════════════════════════
if ($isPost && !$csrfOk) {
    $notice = ['bad', 'CSRF token ไม่ถูกต้อง — โหลดหน้าใหม่แล้วลองอีกครั้ง'];
    $action = '';
}

// ── แก้ทีละตัวจากตาราง ─────────────────────────────────────────────────────
if ($action === 'set_one') {
    $r = icLlpSetCharCat(
        $pdo,
        (string)($_POST['llp'] ?? ''),
        (string)($_POST['cat_id'] ?? ''),
        (string)($_POST['char_id'] ?? ''),
        $user
    );
    if (!$r['ok']) {
        $notice = ['bad', $r['error']];
    } elseif (!$r['changed']) {
        $notice = ['warn', 'ค่าเดิมอยู่แล้ว — ไม่มีอะไรเปลี่ยน'];
    } else {
        $notice = ['ok', 'บันทึก ' . (string)$_POST['llp'] . ' แล้ว'
                        . ($r['ic'] > 0 ? ' · อัปเดต IC ใต้มัน ' . $r['ic'] . ' รหัส (วัสดุ ' . $r['material'] . ' แถว)' : '')];
    }
}

// ── อัปไฟล์ → คำนวณแผน (ยังไม่เขียน DB) ───────────────────────────────────
if ($action === 'preview') {
    if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $notice = ['bad', 'ยังไม่ได้เลือกไฟล์ หรืออัปโหลดไม่สำเร็จ'];
    } else {
        $token = bin2hex(random_bytes(8));
        $tmp   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cnx_llp_' . $token . '.xlsx';
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $tmp)) {
            $notice = ['bad', 'ย้ายไฟล์ที่อัปโหลดไม่สำเร็จ'];
        } else {
            try {
                $plan          = lmBuildPlan($pdo, $tmp);
                $plan['token'] = $token;
                $plan['name']  = (string)($_FILES['file']['name'] ?? 'ไฟล์');

                // เก็บไฟล์ไว้รอ commit + เก็บกวาดของเก่าที่ค้างเกิน 1 ชม.
                $box = $_SESSION['lm_import'] ?? [];
                foreach ($box as $k => $v) {
                    if ((int)($v['ts'] ?? 0) < time() - 3600) { @unlink((string)$v['path']); unset($box[$k]); }
                }
                $box[$token] = ['path' => $tmp, 'ts' => time()];
                $_SESSION['lm_import'] = $box;
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
    $path  = lmStashPath($token);
    if ($path === null) {
        $notice = ['bad', 'ไม่พบไฟล์ที่ตรวจไว้ — อัปโหลดใหม่อีกครั้ง'];
    } else {
        try {
            $p    = lmBuildPlan($pdo, $path);       // คำนวณใหม่ กัน DB ขยับระหว่างกดยืนยัน
            $todo = array_merge($p['new'], $p['change']);

            // จับกลุ่มตามคู่ (cat, char) — ได้อย่างมาก 12 กลุ่ม จึงยิงคำสั่งไม่กี่ตัว
            // แทนที่จะวนทีละแถว (ไฟล์เต็ม 3,300 แถว = หมื่นคำสั่ง เสี่ยงชนเวลาของโฮสต์)
            $groups = [];
            foreach ($todo as $t) { $groups[$t['cat'] . '|' . $t['char']][] = $t['llp']; }

            $nLlp = 0; $nIc = 0; $nMat = 0;
            $pdo->beginTransaction();
            foreach ($groups as $key => $llps) {
                list($gCat, $gChar) = explode('|', $key, 2);
                $r = icLlpSetCharCatBulk($pdo, $llps, $gCat, $gChar, $user);
                $nLlp += $r['llp']; $nIc += $r['ic']; $nMat += $r['material'];
            }
            $pdo->commit();

            $box = $_SESSION['lm_import'] ?? [];
            @unlink($path);
            unset($box[$token]);
            $_SESSION['lm_import'] = $box;

            $msg = 'นำเข้าเรียบร้อย — ตั้งค่า/แก้ไข ' . number_format($nLlp) . ' ตัวสินค้า'
                 . ($nIc > 0 ? ' · อัปเดต IC ' . number_format($nIc) . ' รหัส (วัสดุ ' . number_format($nMat) . ' แถว)' : '')
                 . ($p['errors'] ? ' · ข้ามบรรทัดที่มีปัญหา ' . count($p['errors']) . ' บรรทัด' : '');
            $notice = [$p['errors'] ? 'warn' : 'ok', $msg];

        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $notice = ['bad', 'นำเข้าไม่สำเร็จ: ' . $ex->getMessage()];
        }
    }
}

// ── ทิ้งไฟล์ที่ตรวจไว้ ──────────────────────────────────────────────────────
if ($action === 'discard') {
    $token = (string)($_POST['token'] ?? '');
    $path  = lmStashPath($token);
    if ($path !== null) { @unlink($path); }
    $box = $_SESSION['lm_import'] ?? [];
    unset($box[$token]);
    $_SESSION['lm_import'] = $box;
    $notice = ['warn', 'ยกเลิกการนำเข้าแล้ว — ไม่มีอะไรถูกบันทึก'];
}

// ═══════════════════════════════════════════════════════════════════════════
// ข้อมูลสำหรับจอ
// ═══════════════════════════════════════════════════════════════════════════
$q     = trim((string)($_GET['q'] ?? ''));
$fL1   = trim((string)($_GET['l1'] ?? ''));
$state = (string)($_GET['state'] ?? '');
$page  = max(1, (int)($_GET['page'] ?? 1));

$filter = ['q' => $q, 'l1' => $fL1, 'state' => $state];
$found  = lmCount($pdo, $filter);
$rows   = lmRows($pdo, $filter + ['limit' => LM_PER_PAGE, 'offset' => ($page - 1) * LM_PER_PAGE]);
$pages  = max(1, (int)ceil($found / LM_PER_PAGE));

$sum = $pdo->query(
    'SELECT COUNT(*) total,
            SUM(cat_id IS NOT NULL AND char_id IS NOT NULL) done
       FROM llp_products'
)->fetch();
$total = (int)$sum['total'];
$done  = (int)$sum['done'];
$left  = $total - $done;

$icUnset = (int)$pdo->query(
    'SELECT COUNT(*) FROM ic_items i JOIN llp_products p ON p.llp_code = i.llp_code
      WHERE p.cat_id IS NULL OR p.char_id IS NULL'
)->fetchColumn();

$l1s = icL1List($pdo, false);
$catLabels  = icCatLabels();
$charLabels = icCharLabels();

/** ลิงก์ของจอนี้ที่คงค่ากรองไว้ */
function lmUrl(array $over = []): string {
    $qs = array_merge([
        'q'     => (string)($_GET['q'] ?? ''),
        'l1'    => (string)($_GET['l1'] ?? ''),
        'state' => (string)($_GET['state'] ?? ''),
        'page'  => (string)($_GET['page'] ?? '1'),
    ], $over);
    $qs = array_filter($qs, function ($v) { return $v !== '' && $v !== null; });
    return uiUrl('llp_master.php' . ($qs ? '?' . http_build_query($qs) : ''));
}

uiHead('ตั้งค่าตัวสินค้า (LLP)', 'หมวดอนุมัติ + ลักษณะวัสดุ ที่ IC ทุกตัวใต้มันจะได้ไปด้วย', $user, '🧩', uiIsEmbedded());
uiBackToAdmin('ladder');
?>

<?php if ($notice !== null): ?>
  <div class="banner b-<?= $notice[0] === 'ok' ? 'ok' : ($notice[0] === 'warn' ? 'warn' : 'bad') ?>">
    <?= e($notice[1]) ?>
  </div>
<?php endif; ?>


<div class="card">
  <h2><span class="num">1</span> สถานะการตั้งค่า
    <span class="sp">ตัวสินค้าทั้งหมด <?= number_format($total) ?> ตัว</span>
  </h2>

  <div class="row" style="align-items:center">
    <div class="stat"><b><?= number_format($done) ?></b><span>ตั้งค่าแล้ว</span></div>
    <div class="stat <?= $left > 0 ? 'warn' : '' ?>"><b><?= number_format($left) ?></b><span>ยังไม่ตั้งค่า</span></div>
    <div style="flex:1;min-width:240px" class="small">
      ตัวสินค้าที่ยังไม่ตั้งค่า <b>ออก IC ไม่ได้</b> — คนรับของจะเจอข้อความให้มาแจ้งผู้ดูแล
      <?php if ($icUnset > 0): ?>
        <br><span class="pill p-bad">มี IC <?= $icUnset ?> รหัสที่ออกไปก่อนหน้านี้ใต้ LLP ที่ยังไม่ตั้งค่า</span>
      <?php endif; ?>
    </div>
  </div>

  <div class="row" style="margin-top:12px">
    <a href="<?= uiUrl('llp_master.php?export=xlsx&scope=all') ?>" data-cnx-no-embed>
      <button type="button">⤓ ดาวน์โหลด Excel (ทั้งหมด <?= number_format($total) ?> แถว)</button></a>
    <?php if ($left > 0): ?>
      <a href="<?= uiUrl('llp_master.php?export=xlsx&scope=unset') ?>" data-cnx-no-embed>
        <button type="button" class="ghost">⤓ เฉพาะที่ยังไม่ตั้งค่า (<?= number_format($left) ?>)</button></a>
    <?php endif; ?>
  </div>
  <p class="small" style="margin-top:8px">
    ในไฟล์ ช่อง <b>CatID</b> กับ <b>CharID</b> เป็น dropdown ให้เลือก พิมพ์ค่าอื่นไม่ได้ ·
    ห้ามแก้คอลัมน์รหัส LLP · เว้นว่างทั้งคู่ = ข้ามแถวนั้น
  </p>
</div>


<div class="card">
  <h2><span class="num">2</span> นำเข้าไฟล์ที่กรอกแล้ว</h2>

  <?php if ($plan === null): ?>
    <form method="post" enctype="multipart/form-data" class="row" style="align-items:flex-end">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
      <input type="hidden" name="action" value="preview">
      <div style="flex:1;min-width:240px">
        <label class="fld">ไฟล์ .xlsx ที่กรอก CatID/CharID แล้ว</label>
        <input type="file" name="file" accept=".xlsx" required style="width:100%">
      </div>
      <div><button type="submit">ตรวจไฟล์ก่อนนำเข้า</button></div>
    </form>
    <p class="small" style="margin-top:8px">ระบบจะสรุปให้ดูก่อนว่าจะเปลี่ยนอะไรบ้าง ยังไม่บันทึกจนกว่าจะกดยืนยัน</p>

  <?php else: $willDo = count($plan['new']) + count($plan['change']); ?>
    <div class="banner b-warn">
      ตรวจไฟล์ <b><?= e($plan['name']) ?></b> แล้ว — <b>ยังไม่บันทึกอะไรทั้งสิ้น</b>
    </div>

    <div class="row" style="margin:11px 0">
      <div class="stat"><b><?= number_format(count($plan['new'])) ?></b><span>ตั้งค่าใหม่</span></div>
      <div class="stat <?= $plan['change'] ? 'warn' : '' ?>"><b><?= number_format(count($plan['change'])) ?></b><span>เปลี่ยนค่าเดิม</span></div>
      <div class="stat"><b><?= number_format($plan['ic_affected']) ?></b><span>IC ที่ถูกอัปเดตตาม</span></div>
      <div class="stat"><b><?= number_format($plan['same']) ?></b><span>ค่าเท่าเดิม</span></div>
      <div class="stat"><b><?= number_format($plan['blank']) ?></b><span>เว้นว่าง (ข้าม)</span></div>
      <div class="stat <?= $plan['errors'] ? 'bad' : '' ?>"><b><?= number_format(count($plan['errors'])) ?></b><span>บรรทัดมีปัญหา</span></div>
    </div>

    <?php if ($plan['errors']): ?>
      <div class="scroll" style="max-height:230px;margin-bottom:11px">
        <table>
          <tr><th>บรรทัด</th><th>รหัส</th><th>ปัญหา</th></tr>
          <?php foreach (array_slice($plan['errors'], 0, LM_ERR_SHOW) as $er): ?>
            <tr><td class="num"><?= (int)$er['line'] ?></td>
                <td class="mono"><?= e((string)$er['llp']) ?></td>
                <td class="small"><?= e((string)$er['msg']) ?></td></tr>
          <?php endforeach; ?>
        </table>
      </div>
      <?php if (count($plan['errors']) > LM_ERR_SHOW): ?>
        <p class="small">…และอีก <?= count($plan['errors']) - LM_ERR_SHOW ?> บรรทัด (บรรทัดพวกนี้จะถูกข้าม ไม่นำเข้า)</p>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($plan['change']): ?>
      <h3 class="small" style="margin:12px 0 6px">แถวที่เปลี่ยนค่าเดิม — ของที่ออกไปแล้วจะถูกอัปเดตตาม</h3>
      <div class="scroll" style="max-height:260px">
        <table>
          <tr><th>รหัส LLP</th><th>ชื่อ</th><th>เดิม</th><th>ใหม่</th><th class="num">IC ที่กระทบ</th></tr>
          <?php foreach (array_slice($plan['change'], 0, 100) as $c): ?>
            <tr><td class="mono"><?= e((string)$c['llp']) ?></td>
                <td class="small"><?= e((string)$c['name']) ?></td>
                <td class="mono small"><?= e((string)$c['from']) ?></td>
                <td class="mono small"><b><?= e((string)$c['to']) ?></b></td>
                <td class="num"><?= (int)$c['ic'] ?></td></tr>
          <?php endforeach; ?>
        </table>
      </div>
      <?php if (count($plan['change']) > 100): ?>
        <p class="small">…และอีก <?= count($plan['change']) - 100 ?> แถว</p>
      <?php endif; ?>
    <?php endif; ?>

    <form method="post" class="row" style="margin-top:13px">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
      <input type="hidden" name="token" value="<?= e((string)$plan['token']) ?>">
      <div>
        <button type="submit" name="action" value="commit" <?= $willDo === 0 ? 'disabled' : '' ?>>
          ✓ ยืนยันนำเข้า <?= number_format($willDo) ?> แถว
          <?= $plan['errors'] ? '(ข้าม ' . count($plan['errors']) . ' บรรทัดที่มีปัญหา)' : '' ?>
        </button>
      </div>
      <div><button type="submit" name="action" value="discard" class="ghost">ยกเลิก</button></div>
    </form>
  <?php endif; ?>
</div>


<div class="card">
  <h2><span class="num">3</span> ตัวสินค้า <span class="sp">พบ <?= number_format($found) ?> ตัว · หน้า <?= $page ?>/<?= $pages ?></span></h2>

  <form method="get" class="row">
    <?php if (uiIsEmbedded()): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
    <div style="flex:1;min-width:220px">
      <label class="fld">รหัส / ชื่อตัวสินค้า</label>
      <input type="text" name="q" value="<?= e($q) ?>" style="width:100%" placeholder="พิมพ์แล้วกด Enter">
    </div>
    <div>
      <label class="fld">กลุ่มใหญ่</label>
      <select name="l1" onchange="this.form.submit()">
        <option value="">— ทั้งหมด —</option>
        <?php foreach ($l1s as $r): ?>
          <option value="<?= e((string)$r['l1_code']) ?>" <?= (string)$r['l1_code'] === $fL1 ? 'selected' : '' ?>>
            <?= e((string)$r['l1_code']) ?> · <?= e((string)$r['l1_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="fld">สถานะ</label>
      <select name="state" onchange="this.form.submit()">
        <option value="">— ทั้งหมด —</option>
        <option value="unset" <?= $state === 'unset' ? 'selected' : '' ?>>ยังไม่ตั้งค่า</option>
        <option value="set"   <?= $state === 'set'   ? 'selected' : '' ?>>ตั้งค่าแล้ว</option>
      </select>
    </div>
    <div style="align-self:flex-end"><button type="submit">ค้นหา</button></div>
  </form>

  <?php if (empty($rows)): ?>
    <p class="small" style="margin-top:11px">ไม่พบตัวสินค้าที่ตรงเงื่อนไข</p>
  <?php else: ?>
    <div class="scroll" style="margin-top:11px">
      <table>
        <tr>
          <th>รหัส LLP</th><th>ชื่อตัวสินค้า</th><th>เส้นทางบันได</th>
          <th class="num">IC</th><th>หมวดอนุมัติ</th><th>ลักษณะวัสดุ</th><th></th>
        </tr>
        <?php foreach ($rows as $r):
            $llp  = (string)$r['llp_code'];
            $cCat = icNormalizeCat($r['cat_id']);
            $cChr = icNormalizeChar($r['char_id']);
            $fid  = 'f_' . $llp;
        ?>
          <tr class="<?= $cCat === null || $cChr === null ? 'warnrow' : '' ?>">
            <td class="mono"><?= e($llp) ?></td>
            <td class="small"><?= e((string)$r['llp_name']) ?></td>
            <td class="small"><?= e((string)$r['l1_name']) ?> › <?= e((string)$r['l2_name']) ?></td>
            <td class="num"><?= (int)$r['ic_count'] > 0 ? (int)$r['ic_count'] : '—' ?></td>
            <td>
              <form method="post" id="<?= e($fid) ?>" style="display:none">
                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action" value="set_one">
                <input type="hidden" name="llp" value="<?= e($llp) ?>">
              </form>
              <select name="cat_id" form="<?= e($fid) ?>">
                <option value="">— ยังไม่ตั้ง —</option>
                <?php foreach ($catLabels as $k => $lb): ?>
                  <option value="<?= $k ?>" <?= $cCat === $k ? 'selected' : '' ?>><?= e($lb) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td>
              <select name="char_id" form="<?= e($fid) ?>">
                <option value="">— ยังไม่ตั้ง —</option>
                <?php foreach ($charLabels as $k => $lb): ?>
                  <option value="<?= $k ?>" <?= $cChr === $k ? 'selected' : '' ?>><?= e($lb) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td>
              <button type="submit" form="<?= e($fid) ?>" class="ghost"
                <?= (int)$r['ic_count'] > 0 ? 'onclick="return confirm(\'ตัวสินค้านี้มี IC ออกไปแล้ว ' . (int)$r['ic_count'] . ' รหัส — เปลี่ยนค่าจะอัปเดตทุกตัวใต้มันด้วย ยืนยันไหม?\')"' : '' ?>>
                บันทึก
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>

    <?php if ($pages > 1): ?>
      <div class="row" style="margin-top:11px;align-items:center">
        <?php if ($page > 1): ?>
          <a href="<?= lmUrl(['page' => (string)($page - 1)]) ?>"><button type="button" class="ghost">‹ ก่อนหน้า</button></a>
        <?php endif; ?>
        <span class="small">หน้า <?= $page ?> จาก <?= $pages ?></span>
        <?php if ($page < $pages): ?>
          <a href="<?= lmUrl(['page' => (string)($page + 1)]) ?>"><button type="button" class="ghost">ถัดไป ›</button></a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<style>
.stat{background:#f1f5f9;border-radius:9px;padding:9px 15px;min-width:104px}.stat b{display:block;font-size:1.25rem;line-height:1.2}.stat span{font-size:.75rem;color:#64748b}.stat.warn{background:#fef3c7}.stat.bad{background:#fee2e2}tr.warnrow td{background:#fffbeb}





</style>

<?php
uiFoot('LLP คือต้นทางเดียวของ CatID/CharID — IC และวัสดุใต้มันเป็นสำเนาที่ระบบเขียนตามให้เสมอ (มติ 29)');
