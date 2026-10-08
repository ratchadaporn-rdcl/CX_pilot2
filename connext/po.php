<?php
/**
 * CONNEXT — po.php : ใบคุม (PO) — นำเข้าจาก PDF ด้วย OCR แล้วให้คนตรวจก่อนบันทึก
 *
 * เฟส 2 ของสาย PO → OCR → buffer → IcCode (db/design_po_ocr_ic_v1.md)
 *   มติ 6  — ใบนี้คือ "ยอดคุม" ของการรับ
 *   มติ 18 — บรรทัดที่ไม่ใช่ของ ระบบเดาให้ แต่คนสลับกลับได้ในจอนี้
 *   มติ 19 — ไซต์ตั้งต้นจากเลขที่ PO · ไม่ตรงกับไซต์คนอัป = เตือน แต่เลือกเองได้
 *   มติ 21 — OCR ไม่สร้างใบเอง ต้องผ่านจอตรวจนี้เสมอ
 *
 * PHP 7.4-compatible เท่านั้น
 */

require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/gemini.php';
require __DIR__ . '/lib/po_ocr.php';
require __DIR__ . '/lib/po.php';

$user = uiGuard();
@set_time_limit(300);

$isPost = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
$csrfOk = $isPost && hash_equals(csrfToken(), (string)($_POST['csrf'] ?? ''));
$action = $isPost ? (string)($_POST['action'] ?? '') : '';

$notice = null;
$draft  = null;   // ['po'=>..., 'review'=>..., 'meta'=>...]

if ($isPost && !$csrfOk) {
    $notice = ['bad', 'CSRF token ไม่ถูกต้อง — โหลดหน้าใหม่แล้วลองอีกครั้ง'];
    $action = '';
}

$cfg       = geminiConfig();
$sampleDir = trim((string)$cfg['sample_dir']);
$samples   = [];
if ($sampleDir !== '' && is_dir($sampleDir)) {
    foreach ((array)glob(rtrim($sampleDir, '/\\') . DIRECTORY_SEPARATOR . '*.pdf') as $sp) {
        $samples[] = basename($sp);
    }
    sort($samples);
}

// ── 1) อ่านไฟล์ด้วย OCR → ได้ร่างไว้ให้คนตรวจ ──────────────────────────
if ($action === 'ocr') {
    $bytes = '';
    $label = '';
    $pick  = trim((string)($_POST['sample'] ?? ''));

    if (isset($_FILES['pdf']) && (int)$_FILES['pdf']['error'] === UPLOAD_ERR_OK) {
        $label = (string)$_FILES['pdf']['name'];
        $bytes = (string)file_get_contents($_FILES['pdf']['tmp_name']);
    } elseif ($pick !== '' && in_array(basename($pick), $samples, true)) {
        $label = basename($pick);
        $bytes = (string)file_get_contents(rtrim($sampleDir, '/\\') . DIRECTORY_SEPARATOR . $label);
    }

    if ($bytes === '') {
        $notice = ['bad', 'ยังไม่ได้เลือกไฟล์ PDF'];
    } elseif (!geminiReady()) {
        $notice = ['bad', 'ยังตั้งค่า Gemini ไม่ครบ — ไปที่หน้า "ทดสอบ OCR" เพื่อตั้ง api_key/model ก่อน'];
    } else {
        $path = poSavePdf($bytes, $label);
        $r    = geminiReadPo($bytes);

        $logId = poLogOcr($pdo, [
            'po_no'      => $r['ok'] ? (string)($r['po']['po_no'] ?? '') : null,
            'model'      => (string)$r['model'],
            'status'     => $r['ok'] ? 'parsed' : 'failed',
            'file_name'  => $label,
            'file_path'  => $path,
            'payload'    => $r['ok'] ? json_encode($r['po'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'raw'        => $r['raw'],
            'error_note' => $r['ok'] ? null : $r['error'],
            'ms'         => (int)$r['ms'],
            'tokens'     => (int)($r['usage']['totalTokenCount'] ?? 0),
            'project_id' => (int)($user['projectId'] ?? 0),
            'created_by' => (int)($user['accountId'] ?? 0),
        ]);

        if (!$r['ok']) {
            $notice = ['bad', 'อ่านไฟล์ไม่สำเร็จ: ' . $r['error'] . ' (บันทึกไว้ใน ocr_logs #' . $logId . ' แล้ว)'];
        } else {
            $draft = [
                'po'     => $r['po'],
                'review' => poOcrReview($pdo, $r['po']),
                'meta'   => ['file' => $label, 'path' => $path, 'log_id' => $logId,
                             'model' => (string)$r['model'], 'ms' => (int)$r['ms']],
            ];
            $_SESSION['po_draft'] = $draft;
        }
    }
}

// ── 2) บันทึกเป็นใบคุมจริง (ค่าที่คนแก้ในจอชนะเสมอ) ─────────────────────
if ($action === 'commit') {
    $head = [
        'po_no'           => (string)($_POST['po_no'] ?? ''),
        'po_date'         => (string)($_POST['po_date'] ?? ''),
        'pr_no'           => (string)($_POST['pr_no'] ?? ''),
        'vendor_name'     => (string)($_POST['vendor_name'] ?? ''),
        'vendor_tax_id'   => (string)($_POST['vendor_tax_id'] ?? ''),
        'vendor_address'  => (string)($_POST['vendor_address'] ?? ''),
        'vendor_contact'  => (string)($_POST['vendor_contact'] ?? ''),
        'vendor_phone'    => (string)($_POST['vendor_phone'] ?? ''),
        'quotation_no'    => (string)($_POST['quotation_no'] ?? ''),
        'quotation_date'  => (string)($_POST['quotation_date'] ?? ''),
        'delivery_date'   => (string)($_POST['delivery_date'] ?? ''),
        'payment_terms'   => (string)($_POST['payment_terms'] ?? ''),
        'deposit_text'    => (string)($_POST['deposit_text'] ?? ''),
        'retention_text'  => (string)($_POST['retention_text'] ?? ''),
        'page_count'      => (int)($_POST['page_count'] ?? 0),
        'sum_before'      => (float)($_POST['sum_before'] ?? 0),
        'special_discount'=> (float)($_POST['special_discount'] ?? 0),
        'sum_after'       => (float)($_POST['sum_after'] ?? 0),
        'vat'             => (float)($_POST['vat'] ?? 0),
        'grand_total'     => (float)($_POST['grand_total'] ?? 0),
        'amount_in_words' => (string)($_POST['amount_in_words'] ?? ''),
        'notes'           => (string)($_POST['notes_json'] ?? ''),
        'src_file'        => (string)($_POST['src_file'] ?? ''),
        'ocr_log_id'      => (int)($_POST['ocr_log_id'] ?? 0),
    ];

    $lines   = [];   // เฉพาะที่ติ๊กไว้ — ตัวที่จะบันทึกจริง
    $allRows = [];   // ทุกบรรทัดที่ส่งมา — ไว้วาดจอใหม่ถ้าบันทึกไม่ผ่าน
    $keepMap = [];
    $kindMap = [];
    $addMap  = [];   // มติ 35 — บรรทัดที่ติ๊ก "เพิ่มเข้าทะเบียน Mango"
    $addRows = [];   // รหัส+ชื่อ+หน่วย ที่จะเอาไปสร้างแถวใหม่
    $srcMap  = [];   // เลขบรรทัดใหม่ → เลขที่ OCR อ่านได้ตอนแรก (ไว้โชว์ว่าอะไรถูกย้าย)

    // ผู้ใช้ลากสลับลำดับในจอตรวจได้ — ช่อง ln[i][sort] คือลำดับที่จัดไว้
    // เรียงตามนั้นแล้ว "เดินเลขบรรทัดใหม่ 1..N" เพราะ line_no เป็นคีย์ของ po_lines
    // และเป็นตัวอ้างของ buffer_lines ต่อไป — ต้องต่อเนื่องไม่ซ้ำเสมอ
    // ไม่มี JS = ทุกแถวยังถือ sort ตามลำดับเดิม ผลลัพธ์จึงเหมือนเดิมทุกประการ
    $posted = (array)($_POST['ln'] ?? []);
    uasort($posted, function ($a, $b) {
        $sa = isset($a['sort']) ? (int)$a['sort'] : 0;
        $sb = isset($b['sort']) ? (int)$b['sort'] : 0;
        return $sa <=> $sb;
    });

    $newNo = 0;
    foreach ($posted as $srcNo => $row) {
        $newNo++;
        $srcMap[$newNo] = (int)$srcNo;
        $one = [
            'line_no'      => $newNo,
            'line_kind'    => (string)($row['kind'] ?? 'item'),
            'mat_code'     => (string)($row['mat_code'] ?? ''),
            'mat_name'     => (string)($row['mat_name'] ?? ''),
            'description'  => (string)($row['description'] ?? ''),
            'qty'          => (float)($row['qty'] ?? 0),
            'unit_po_name' => (string)($row['unit'] ?? ''),
            'unit_price'   => (float)($row['unit_price'] ?? 0),
            'discount'     => (float)($row['discount'] ?? 0),
            'amount'       => (float)($row['amount'] ?? 0),
        ];
        $allRows[] = $one;
        $keepMap[$newNo] = !empty($row['keep']);
        $kindMap[$newNo] = $one['line_kind'] === 'adjust' ? 'adjust' : 'item';
        $addMap[$newNo]  = !empty($row['add_master']);
        if (!empty($row['keep'])) { $lines[] = $one; }   // ติ๊กออก = ไม่เอาเข้าใบ

        // เพิ่มเข้าทะเบียนเฉพาะบรรทัดที่ยังอยู่ในใบจริง ๆ (ติ๊กออกแล้วไม่ต้องเก็บรหัส)
        if (!empty($row['add_master']) && !empty($row['keep']) && $one['mat_code'] !== '') {
            $addRows[] = ['mat_code' => $one['mat_code'],
                          'name'     => $one['mat_name'],
                          'unit'     => $one['unit_po_name']];
        }
    }

    $r = poCommit($pdo, $head, $lines, (int)($_POST['project_id'] ?? 0), $user, $addRows);
    if ($r['ok']) {
        unset($_SESSION['po_draft']);
        $extra = !empty($r['mango_added']) ? '&mango=' . (int)$r['mango_added'] : '';
        header('Location: ' . APP_BASE . '/po_view.php?po=' . urlencode($r['po_no']) . '&new=1' . $extra);
        exit;
    }

    // บันทึกไม่ผ่าน — วาดจอตรวจใหม่ด้วย "ค่าที่ผู้ใช้แก้ไว้" ไม่ใช่ค่า OCR เดิม
    $notice = ['bad', $r['error']];
    $old    = $_SESSION['po_draft'] ?? null;
    $poBack = [
        'po_no' => $head['po_no'], 'po_date' => $head['po_date'], 'pr_no' => $head['pr_no'],
        'project_code' => '', 'project_text' => '',
        'vendor_name' => $head['vendor_name'], 'vendor_tax_id' => $head['vendor_tax_id'],
        'vendor_address' => $head['vendor_address'],
        'vendor_contact_person' => $head['vendor_contact'], 'vendor_phone' => $head['vendor_phone'],
        'quotation_no' => $head['quotation_no'], 'quotation_date' => $head['quotation_date'],
        'delivery_date' => $head['delivery_date'], 'payment_terms' => $head['payment_terms'],
        'deposit_text' => $head['deposit_text'], 'retention_text' => $head['retention_text'],
        'page_count' => $head['page_count'], 'amount_in_words' => $head['amount_in_words'],
        'sum_before_special_discount' => $head['sum_before'],
        'special_discount'            => $head['special_discount'],
        'sum_after_special_discount'  => $head['sum_after'],
        'vat' => $head['vat'], 'grand_total' => $head['grand_total'],
        'notes' => json_decode((string)$head['notes'], true) ?: [],
        'lines' => [],
    ];
    foreach ($allRows as $l) {          // ทุกบรรทัด ไม่ใช่เฉพาะที่ติ๊ก — ติ๊กพลาดจะได้ไม่หาย
        $poBack['lines'][] = [
            'line_no' => $l['line_no'], 'mat_code' => $l['mat_code'], 'name' => $l['mat_name'],
            'description' => $l['description'], 'qty' => $l['qty'], 'unit' => $l['unit_po_name'],
            'unit_price' => $l['unit_price'], 'discount' => $l['discount'], 'amount' => $l['amount'],
        ];
    }
    $draft = [
        'po'     => $poBack,
        'review' => poOcrReview($pdo, $poBack),
        'keep'   => $keepMap,
        'kind'   => $kindMap,
        'add'    => $addMap,
        'src'    => $srcMap,
        'meta'   => $old['meta'] ?? ['file' => '', 'path' => $head['src_file'],
                                     'log_id' => $head['ocr_log_id'], 'model' => '', 'ms' => 0],
    ];
    $_SESSION['po_draft'] = $draft;
}

if ($action === 'discard') {
    unset($_SESSION['po_draft']);
    $notice = ['ok', 'ทิ้งร่างแล้ว'];
}
if ($draft === null && $action !== 'ocr' && !empty($_SESSION['po_draft'])) {
    $draft = $_SESSION['po_draft'];      // ร่างค้างอยู่จากครั้งก่อน
}

// ── รายการใบคุมที่มีอยู่ ────────────────────────────────────────────────
$q  = trim((string)($_GET['q'] ?? ''));
$fs = trim((string)($_GET['status'] ?? ''));
$w  = ['1=1']; $ar = [];
if ($q !== '') {
    $like = '%' . likeEscape($q) . '%';
    $w[]  = '(h.po_no LIKE ? OR h.vendor_name LIKE ? OR h.pr_no LIKE ?)';
    array_push($ar, $like, $like, $like);
}
if ($fs !== '') { $w[] = 'h.status = ?'; $ar[] = $fs; }

$st = $pdo->prepare(
    'SELECT h.*, p.code AS proj_code,
            (SELECT COUNT(*) FROM po_lines l WHERE l.po_no = h.po_no AND l.line_kind = "item") AS n_item,
            (SELECT COUNT(*) FROM po_lines l WHERE l.po_no = h.po_no AND l.line_kind = "item" AND l.qty_received >= l.qty) AS n_done
     FROM po_headers h JOIN projects p ON p.id = h.project_id
     WHERE ' . implode(' AND ', $w) . '
     ORDER BY h.created_at DESC LIMIT 200'
);
$st->execute($ar);
$pos = $st->fetchAll();

$projects = $pdo->query("SELECT id, code, name FROM projects WHERE status='active' ORDER BY code")->fetchAll();

$statusLabel = ['open' => 'ยังไม่รับ', 'partial' => 'รับบางส่วน', 'received' => 'รับครบ',
                'closed' => 'ปิดใบ', 'cancelled' => 'ยกเลิก'];
$statusPill  = ['open' => 'p-muted', 'partial' => 'p-warn', 'received' => 'p-ok',
                'closed' => 'p-info', 'cancelled' => 'p-bad'];

uiHead('ใบคุม (PO)', 'นำเข้าจาก PDF ด้วย OCR → คนตรวจ → บันทึกเป็นยอดคุม', $user, '📋');
?>

<?php if ($notice !== null): ?>
  <div class="banner <?= $notice[0] === 'ok' ? 'b-ok' : 'b-bad' ?>">
    <?= $notice[0] === 'ok' ? '✓' : '✕' ?> <?= e($notice[1]) ?>
  </div>
<?php endif; ?>

<?php if ($draft === null): ?>


<div class="card">
  <h2><span class="num">1</span> นำเข้าใบสั่งซื้อจากไฟล์ PDF
    <span class="sp">รุ่นที่ใช้: <?= e((string)$cfg['model'] ?: 'ยังไม่ตั้ง') ?></span>
  </h2>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="action" value="ocr">
    <div id="pdfDrop" class="dropzone">
      <div class="dz-icon">📄</div>
      <div class="dz-main"><b>ลากไฟล์ PDF มาวางตรงนี้</b></div>
      <div class="dz-sub">หรือกดเพื่อเลือกไฟล์จากเครื่อง · รับเฉพาะ .pdf (มติ 14)</div>
      <div class="dz-file" id="dzFile" hidden></div>
      <input type="file" name="pdf" accept="application/pdf" id="pdfInput" style="display:none">
    </div>
    <div class="row">
      <?php if (!empty($samples)): ?>
        <div>
          <label class="fld">หรือไฟล์ตัวอย่างในเครื่อง</label>
          <select name="sample">
            <option value="">— ไม่เลือก —</option>
            <?php foreach ($samples as $s): ?><option value="<?= e($s) ?>"><?= e($s) ?></option><?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>
      <div style="align-self:flex-end">
        <button type="submit" <?= geminiReady() ? '' : 'disabled' ?>>อ่านไฟล์นี้</button>
      </div>
    </div>
    <p class="small" style="margin-top:8px">
      อ่านเสร็จจะพาไปหน้าตรวจก่อน — <b>ระบบไม่สร้างใบคุมเองเด็ดขาด</b> (มติ 21)
    </p>
  </form>
</div>


<div class="card">
  <h2><span class="num">2</span> ใบคุมในระบบ <span class="sp">แสดง <?= count($pos) ?> ใบ</span></h2>
  <form method="get" class="row" style="margin-bottom:11px">
    <div style="flex:1;min-width:200px">
      <label class="fld">เลขที่ / ผู้ขาย / เลขใบขอซื้อ</label>
      <input type="text" name="q" value="<?= e($q) ?>" style="width:100%">
    </div>
    <div>
      <label class="fld">สถานะ</label>
      <select name="status" onchange="this.form.submit()">
        <option value="">— ทั้งหมด —</option>
        <?php foreach ($statusLabel as $k => $v): ?>
          <option value="<?= $k ?>" <?= $fs === $k ? 'selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="align-self:flex-end"><button type="submit">ค้นหา</button></div>
  </form>

  <?php if (empty($pos)): ?>
    <p class="small">ยังไม่มีใบคุมในระบบ — นำเข้าใบแรกจากฟอร์มด้านบน</p>
  <?php else: ?>
    <div class="scroll">
      <table>
        <tr><th>เลขที่</th><th>วันที่</th><th>ผู้ขาย</th><th>ไซต์</th>
            <th class="num">บรรทัด</th><th class="num">ยอดสุทธิ</th><th>สถานะ</th><th></th></tr>
        <?php foreach ($pos as $r): ?>
          <tr>
            <td class="mono"><?= e((string)$r['po_no']) ?></td>
            <td><?= e((string)($r['po_date'] ?? '—')) ?></td>
            <td><?= e((string)$r['vendor_name']) ?></td>
            <td><?= e((string)$r['proj_code']) ?></td>
            <td class="num"><?= (int)$r['n_done'] ?> / <?= (int)$r['n_item'] ?></td>
            <td class="num"><?= fmtM($r['grand_total']) ?></td>
            <td><span class="pill <?= $statusPill[(string)$r['status']] ?? 'p-muted' ?>"><?= e($statusLabel[(string)$r['status']] ?? (string)$r['status']) ?></span></td>
            <td><a href="<?= APP_BASE ?>/po_view.php?po=<?= urlencode((string)$r['po_no']) ?>">เปิด →</a></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php else:
  // ══ จอตรวจก่อนบันทึก ══════════════════════════════════════════════════
  $po  = $draft['po'];
  $rv  = $draft['review'];
  $mt  = $draft['meta'];
  $sm  = $rv['summary'];
  $mst = $rv['master'];

  // ไซต์ตั้งต้นจากเลขที่ PO (มติ 19)
  $defProj = $mst['project'] !== null ? (int)$mst['project']['id'] : (int)($user['projectId'] ?? 0);
  $mismatch = ($mst['project'] !== null && (string)$mst['project']['code'] !== (string)$user['siteCode']);
?>

<form method="post">
<input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
<input type="hidden" name="src_file" value="<?= e((string)$mt['path']) ?>">
<input type="hidden" name="ocr_log_id" value="<?= (int)$mt['log_id'] ?>">
<input type="hidden" name="page_count" value="<?= (int)($po['page_count'] ?? 0) ?>">
<input type="hidden" name="notes_json" value="<?= e(json_encode((array)($po['notes'] ?? []), JSON_UNESCAPED_UNICODE)) ?>">

<div class="banner <?= $sm['pass'] ? 'b-ok' : 'b-warn' ?>">
  <?= $sm['pass'] ? '✓ ผ่านกติกาตรวจตัวเองทุกข้อ' : '⚠ มีข้อไม่ผ่าน — ตรวจให้ดีก่อนบันทึก' ?>
  <span class="small" style="font-weight:400;margin-left:8px">
    <?= e((string)$mt['file']) ?> · <?= (int)($po['page_count'] ?? 0) ?> หน้า ·
    <?= $sm['n_item'] ?> บรรทัดของ · <?= $sm['n_adjust'] ?> รายการเงิน ·
    <span class="mono"><?= e((string)$mt['model']) ?></span> · <?= number_format((int)$mt['ms']) ?> ms
  </span>
</div>

<?php /* มติ 35 — รหัสในใบที่ทะเบียนวัสดุ Mango ยังไม่มี */
      $nMissing = count($mst['missing']); if ($nMissing > 0): ?>
  <div class="banner b-warn">
    ⚠ ใบนี้มี <b><?= $nMissing ?></b> รหัสที่ยังไม่มีในทะเบียนวัสดุ Mango:
    <span class="mono small"><?= e(implode(', ', array_slice($mst['missing'], 0, 12))) ?><?= $nMissing > 12 ? ' …' : '' ?></span>
    <div class="small" style="font-weight:400;margin-top:4px">
      ช่องขวาสุดของตารางติ๊ก <b>“＋ เพิ่มเข้าทะเบียน”</b> ไว้ให้แล้ว — กดบันทึกใบคุมแล้วระบบจะเก็บ
      รหัส + ชื่อ + หน่วย เข้าทะเบียนให้ ใบต่อ ๆ ไปจะจับคู่เจอเอง · ไม่อยากเก็บก็ติ๊กออกได้
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <h2><span class="num">1</span> ตรวจกติกาจากตัวใบ (มติ 21)</h2>
  <div class="scroll">
    <table>
      <tr><th>ข้อ</th><th class="num">ควรเป็น</th><th class="num">ในใบ</th><th>ผล</th></tr>
      <?php foreach ($rv['checks'] as $c): ?>
        <tr>
          <td><?= e($c['label']) ?></td>
          <td class="num"><?= fmtM($c['expect']) ?></td>
          <td class="num"><?= fmtM($c['got']) ?></td>
          <td><span class="pill <?= $c['ok'] ? 'p-ok' : 'p-bad' ?>"><?= $c['ok'] ? 'ผ่าน' : 'ไม่ผ่าน' ?></span></td>
        </tr>
      <?php endforeach; ?>
      <tr>
        <td>เลขลำดับต่อเนื่อง ไม่ตกไม่ซ้ำ
          <?php if (!empty($rv['numbering']['missing'])): ?>
            <span class="small">— ขาด <?= e(implode(', ', $rv['numbering']['missing'])) ?></span>
          <?php endif; ?>
        </td>
        <td class="num">1..<?= (int)$rv['numbering']['max'] ?></td>
        <td class="num"><?= (int)$rv['numbering']['count'] ?></td>
        <td><span class="pill <?= $rv['numbering']['ok'] ? 'p-ok' : 'p-bad' ?>"><?= $rv['numbering']['ok'] ? 'ผ่าน' : 'ไม่ผ่าน' ?></span></td>
      </tr>
    </table>
  </div>
</div>

<div class="card">
  <h2><span class="num">2</span> หัวใบ — แก้ได้ทุกช่อง</h2>

  <?php if ($mst['project'] === null): ?>
    <div class="banner b-warn">อ่านรหัสไซต์จากใบไม่ได้ — เลือกไซต์เองด้านล่าง</div>
  <?php elseif ($mismatch): ?>
    <div class="banner b-warn">
      ใบนี้เป็นของไซต์ <b><?= e((string)$mst['project']['code']) ?></b> (<?= e((string)$mst['project']['name']) ?>)
      แต่คุณอยู่ไซต์ <b><?= e((string)$user['siteCode']) ?></b> — เลือกให้ถูกก่อนบันทึก (มติ 19)
    </div>
  <?php endif; ?>

  <div class="row">
    <div><label class="fld">เลขที่ใบสั่งซื้อ *</label><input type="text" name="po_no" value="<?= e((string)($po['po_no'] ?? '')) ?>" required></div>
    <div><label class="fld">วันที่ (DD/MM/YYYY)</label><input type="text" name="po_date" value="<?= e((string)($po['po_date'] ?? '')) ?>"></div>
    <div><label class="fld">เลขที่ใบขอซื้อ</label><input type="text" name="pr_no" value="<?= e((string)($po['pr_no'] ?? '')) ?>"></div>
    <div>
      <label class="fld">ไซต์เจ้าของใบ *</label>
      <select name="project_id">
        <?php foreach ($projects as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $defProj ? 'selected' : '' ?>>
            <?= e((string)$p['code']) ?> · <?= e((string)$p['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="row" style="margin-top:10px">
    <div style="flex:1;min-width:260px"><label class="fld">ผู้ขาย</label><input type="text" name="vendor_name" value="<?= e((string)($po['vendor_name'] ?? '')) ?>" style="width:100%"></div>
    <div><label class="fld">เลขผู้เสียภาษี</label><input type="text" name="vendor_tax_id" value="<?= e((string)($po['vendor_tax_id'] ?? '')) ?>"></div>
    <div><label class="fld">ผู้ติดต่อ</label><input type="text" name="vendor_contact" value="<?= e((string)($po['vendor_contact_person'] ?? '')) ?>"></div>
    <div><label class="fld">โทร</label><input type="text" name="vendor_phone" value="<?= e((string)($po['vendor_phone'] ?? '')) ?>"></div>
  </div>

  <div class="row" style="margin-top:10px">
    <div style="flex:1;min-width:260px"><label class="fld">ที่อยู่ผู้ขาย</label><input type="text" name="vendor_address" value="<?= e((string)($po['vendor_address'] ?? '')) ?>" style="width:100%"></div>
    <div><label class="fld">ใบเสนอราคา</label><input type="text" name="quotation_no" value="<?= e((string)($po['quotation_no'] ?? '')) ?>"></div>
    <div><label class="fld">วันที่ใบเสนอราคา</label><input type="text" name="quotation_date" value="<?= e((string)($po['quotation_date'] ?? '')) ?>"></div>
    <div><label class="fld">วันที่ส่งมอบ</label><input type="text" name="delivery_date" value="<?= e((string)($po['delivery_date'] ?? '')) ?>"></div>
    <div><label class="fld">เงื่อนไขชำระ</label><input type="text" name="payment_terms" value="<?= e((string)($po['payment_terms'] ?? '')) ?>"></div>
  </div>

  <div class="row" style="margin-top:10px">
    <div><label class="fld">เงินมัดจำ</label><input type="text" name="deposit_text" value="<?= e((string)($po['deposit_text'] ?? '')) ?>"></div>
    <div><label class="fld">เงินประกันผลงาน</label><input type="text" name="retention_text" value="<?= e((string)($po['retention_text'] ?? '')) ?>"></div>
    <div style="flex:1;min-width:200px"><label class="fld">จำนวนเงินตัวอักษร</label><input type="text" name="amount_in_words" value="<?= e((string)($po['amount_in_words'] ?? '')) ?>" style="width:100%"></div>
  </div>

  <div class="row" style="margin-top:10px">
    <div><label class="fld">ยอดก่อนหักส่วนลด</label><input type="text" name="sum_before" value="<?= e((string)($po['sum_before_special_discount'] ?? 0)) ?>" style="width:120px"></div>
    <div><label class="fld">ส่วนลดพิเศษ</label><input type="text" name="special_discount" value="<?= e((string)($po['special_discount'] ?? 0)) ?>" style="width:110px"></div>
    <div><label class="fld">ยอดหลังหัก</label><input type="text" name="sum_after" value="<?= e((string)($po['sum_after_special_discount'] ?? 0)) ?>" style="width:120px"></div>
    <div><label class="fld">VAT</label><input type="text" name="vat" value="<?= e((string)($po['vat'] ?? 0)) ?>" style="width:110px"></div>
    <div><label class="fld">รวมสุทธิ</label><input type="text" name="grand_total" value="<?= e((string)($po['grand_total'] ?? 0)) ?>" style="width:130px"></div>
  </div>
</div>

<div class="card">
  <h2><span class="num">3</span> บรรทัด — ลากสลับลำดับได้ · ติ๊กออกได้ · สลับประเภทได้ (มติ 18)
    <span class="sp">แถวเหลือง = ระบบเดาว่าเป็นรายการเงิน ไม่ใช่ของ</span>
  </h2>
  <p class="small" style="margin-bottom:8px">
    จับที่ <span class="grip">⋮⋮</span> แล้วลากขึ้น-ลงเพื่อจัดลำดับให้ตรงกับใบจริง —
    <b>ตอนบันทึกระบบจะเดินเลขบรรทัดใหม่ 1..N ตามลำดับที่จัดไว้</b>
    (เลขที่ OCR อ่านได้ตอนแรกยังโชว์กำกับไว้ให้เทียบ)
  </p>
  <div class="scroll">
    <table id="lnTable">
      <tr>
        <th style="width:22px"></th>
        <th>เอา</th><th class="num">#</th><th>ประเภท</th><th>รหัสวัสดุ</th><th>ชื่อ / รายละเอียด</th>
        <th class="num">จำนวน</th><th>หน่วยซื้อ</th><th class="num">ราคา/หน่วย</th>
        <th class="num">ส่วนลด</th><th class="num">จำนวนเงิน</th><th>master</th>
      </tr>
      <?php foreach ($rv['lines'] as $ln):
              $i = (int)$ln['line_no'];
              // ถ้ากลับมาจากบันทึกที่ไม่ผ่าน ให้ยึดค่าที่ผู้ใช้เลือกไว้ ไม่ใช่ที่ระบบเดา
              $keep = !isset($draft['keep']) || !empty($draft['keep'][$i]);
              $kind = $draft['kind'][$i] ?? $ln['kind'];
              $addMap = $draft['add'] ?? null;   // null = เข้าจอครั้งแรก ใช้ค่าตั้งต้น
      ?>
        <tr class="ln-row <?= $kind === 'adjust' ? 'adjust' : '' ?>" draggable="true">
          <td class="grip" title="ลากเพื่อสลับลำดับ">⋮⋮</td>
          <td><input type="checkbox" name="ln[<?= $i ?>][keep]" value="1" <?= $keep ? 'checked' : '' ?>></td>
          <td class="num">
            <span class="seq"><?= $i ?></span>
            <input type="hidden" name="ln[<?= $i ?>][line_no]" value="<?= $i ?>">
            <input type="hidden" name="ln[<?= $i ?>][sort]" value="<?= $i ?>" class="sortv">
            <?php $src = $draft['src'][$i] ?? null; if ($src !== null && $src !== $i): ?>
              <div class="small" title="เลขบรรทัดที่ OCR อ่านได้ตอนแรก">เดิม #<?= (int)$src ?></div>
            <?php endif; ?>
          </td>
          <td>
            <select name="ln[<?= $i ?>][kind]">
              <option value="item"   <?= $kind === 'item'   ? 'selected' : '' ?>>ของ</option>
              <option value="adjust" <?= $kind === 'adjust' ? 'selected' : '' ?>>รายการเงิน</option>
            </select>
            <?php if (!empty($ln['reasons'])): ?>
              <div class="small"><?= e(implode(' · ', $ln['reasons'])) ?></div>
            <?php endif; ?>
          </td>
          <td><input type="text" name="ln[<?= $i ?>][mat_code]" value="<?= e($ln['mat_code']) ?>" style="width:130px" class="mono"></td>
          <td>
            <input type="text" name="ln[<?= $i ?>][mat_name]" value="<?= e($ln['name']) ?>" style="width:260px">
            <?php if ($ln['description'] !== ''): ?>
              <textarea name="ln[<?= $i ?>][description]" rows="2" style="width:260px;margin-top:3px" class="desc"><?= e($ln['description']) ?></textarea>
            <?php else: ?>
              <input type="hidden" name="ln[<?= $i ?>][description]" value="">
            <?php endif; ?>
          </td>
          <td class="num"><input type="text" name="ln[<?= $i ?>][qty]" value="<?= e((string)$ln['qty']) ?>" style="width:90px;text-align:right"></td>
          <td><input type="text" name="ln[<?= $i ?>][unit]" value="<?= e($ln['unit']) ?>" style="width:70px"></td>
          <td class="num"><input type="text" name="ln[<?= $i ?>][unit_price]" value="<?= e((string)$ln['unit_price']) ?>" style="width:90px;text-align:right"></td>
          <td class="num"><input type="text" name="ln[<?= $i ?>][discount]" value="<?= e((string)$ln['discount']) ?>" style="width:80px;text-align:right"></td>
          <td class="num"><input type="text" name="ln[<?= $i ?>][amount]" value="<?= e((string)$ln['amount']) ?>" style="width:110px;text-align:right"></td>
          <td>
            <?php if ($ln['master'] !== null): ?>
              <span class="pill p-ok">พบ</span>
              <div class="small">หน่วย <?= e((string)$ln['master']['unit']) ?></div>
            <?php elseif ($ln['mat_code'] === ''): ?>
              <span class="pill p-muted">ไม่มีรหัส</span>
            <?php else: ?>
              <?php /* มติ 35 — รหัสที่ทะเบียนยังไม่มี: ติ๊กไว้ให้ ระบบจะเพิ่มให้ตอนกดบันทึก
                        บรรทัด "รายการเงิน" ไม่ติ๊กให้ เพราะไม่ใช่ของ (ค่าส่งส่วนลด ฯลฯ) */ ?>
              <span class="pill <?= $kind === 'adjust' ? 'p-muted' : 'p-warn' ?>">ไม่พบ</span>
              <label class="addm" title="เพิ่มรหัสนี้เข้าทะเบียนวัสดุ Mango ตอนบันทึกใบคุม">
                <input type="checkbox" name="ln[<?= $i ?>][add_master]" value="1"
                       <?= ($addMap === null ? ($kind !== 'adjust') : !empty($addMap[$i])) ? 'checked' : '' ?>>
                ＋ เพิ่มเข้าทะเบียน
              </label>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <p class="small" style="margin-top:9px">
    รหัสที่ไม่พบใน materials ไม่เป็นไร — ใบคุมเก็บรหัสจากใบไว้เฉย ๆ ตัวที่ใช้จริงคือ IC ที่จะออกตอนนำเข้า gate
  </p>
</div>

<div class="card">
  <div class="row">
    <button type="submit" name="action" value="commit">บันทึกเป็นใบคุม</button>
    <button class="ghost" type="submit" name="action" value="discard" formnovalidate>ทิ้งร่างนี้</button>
    <span class="small">บันทึกแล้วจะเปิดหน้าใบคุมให้ทันที เพื่อไปรับของต่อ</span>
  </div>
</div>

<script>
 
 
 
(function () {
  'use strict';
  var table = document.getElementById('lnTable');
  if (!table) { return; }
  var dragged = null;

  function rows() {
    return Array.prototype.slice.call(table.querySelectorAll('tr.ln-row'));
  }
  function renumber() {
    rows().forEach(function (tr, i) {
      var sv = tr.querySelector('.sortv');
      if (sv) { sv.value = i + 1; }
      var sq = tr.querySelector('.seq');
      if (sq) { sq.textContent = i + 1; }
    });
  }

  table.addEventListener('dragstart', function (ev) {
    var tr = ev.target.closest ? ev.target.closest('tr.ln-row') : null;
    if (!tr) { return; }
    dragged = tr;
    tr.classList.add('dragging');
    ev.dataTransfer.effectAllowed = 'move';
     
    try { ev.dataTransfer.setData('text/plain', 'row'); } catch (e) {}
  });

  table.addEventListener('dragover', function (ev) {
    if (!dragged) { return; }
    var tr = ev.target.closest ? ev.target.closest('tr.ln-row') : null;
    if (!tr || tr === dragged) { return; }
    ev.preventDefault();
    ev.dataTransfer.dropEffect = 'move';
     
    var box = tr.getBoundingClientRect();
    var after = (ev.clientY - box.top) > box.height / 2;
    tr.parentNode.insertBefore(dragged, after ? tr.nextSibling : tr);
  });

  table.addEventListener('drop', function (ev) { ev.preventDefault(); });

  table.addEventListener('dragend', function () {
    if (dragged) { dragged.classList.remove('dragging'); }
    dragged = null;
    renumber();
  });
})();
</script>
</form>

<?php endif; ?>

<style>
.addm{display:block;margin-top:4px;font-size:.72rem;color:#78350f;white-space:nowrap;cursor:pointer}.addm input{vertical-align:-1px;margin-right:2px}.dropzone{border:2px dashed var(--line);border-radius:12px;background:#fbfcfe;padding:22px 16px;text-align:center;cursor:pointer;transition:background .12s,border-color .12s;margin-bottom:11px}.dropzone:hover{border-color:var(--navy-2)}.dropzone.over{border-color:var(--navy-2);background:var(--blue-bg)}.dropzone .dz-icon{font-size:1.9rem;line-height:1}.dropzone .dz-main{font-size:.92rem;margin-top:5px}.dropzone .dz-sub{font-size:.76rem;color:var(--muted);margin-top:3px}.dropzone .dz-file{margin-top:9px;font-size:.8rem;font-weight:700;color:var(--navy);background:var(--green-bg);border:1px solid #86efac;border-radius:8px;padding:6px 11px;display:inline-block}.grip{color:#cbd5e1;font-size:1rem;letter-spacing:-2px;cursor:grab;user-select:none}tr.ln-row:hover .grip{color:var(--navy-2)}tr.ln-row.dragging td{opacity:.35}tr.ln-row{cursor:default}



















</style>

<script>
 
(function () {
  'use strict';
  var dz = document.getElementById('pdfDrop');
  var inp = document.getElementById('pdfInput');
  if (!dz || !inp) { return; }

  function show(name) {
    var el = document.getElementById('dzFile');
    el.hidden = !name;
    el.textContent = name ? ('✓ ' + name) : '';
  }

  dz.addEventListener('click', function () { inp.click(); });
  inp.addEventListener('change', function () {
    show(inp.files && inp.files[0] ? inp.files[0].name : '');
  });

  ['dragenter', 'dragover'].forEach(function (evName) {
    dz.addEventListener(evName, function (ev) {
      ev.preventDefault();
      ev.stopPropagation();
      dz.classList.add('over');
    });
  });
  ['dragleave', 'drop'].forEach(function (evName) {
    dz.addEventListener(evName, function (ev) {
      ev.preventDefault();
      ev.stopPropagation();
      if (evName === 'dragleave' && dz.contains(ev.relatedTarget)) { return; }
      dz.classList.remove('over');
    });
  });

  dz.addEventListener('drop', function (ev) {
    var files = ev.dataTransfer && ev.dataTransfer.files;
    if (!files || !files.length) { return; }
    var f = files[0];
    if (!/\.pdf$/i.test(f.name) && f.type !== 'application/pdf') {
      show('');
      alert('รับเฉพาะไฟล์ PDF เท่านั้น (มติ 14) — ไฟล์ที่วางมาคือ ' + (f.name || f.type));
      return;
    }
     
    try {
      var dt = new DataTransfer();
      dt.items.add(f);
      inp.files = dt.files;
      show(f.name);
    } catch (e) {
      alert('เบราว์เซอร์นี้ยังลากไฟล์มาวางไม่ได้ — กดที่กรอบเพื่อเลือกไฟล์แทน');
    }
  });

   
  ['dragover', 'drop'].forEach(function (evName) {
    window.addEventListener(evName, function (ev) {
      if (!dz.contains(ev.target)) { ev.preventDefault(); }
    });
  });
})();
</script>

<?php
uiFoot('OCR ไม่สร้างใบคุมเอง — ทุกใบผ่านสายตาคนก่อนเสมอ (มติ 21)');
