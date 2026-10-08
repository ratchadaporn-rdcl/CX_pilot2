<?php
/**
 * CONNEXT — po_view.php : ใบคุมหนึ่งใบ + รับของเข้า buffer
 *
 * เฟส 2 ของสาย PO → OCR → buffer → IcCode (db/design_po_ocr_ic_v1.md)
 *   มติ 6  — ห้ามรับเกินยอดคุม (บล็อกที่ lib/po.php ก่อนเขียนอะไรทั้งสิ้น)
 *   มติ 7  — รับแล้วของไปนอนใน buffer โดยยังไม่ต้องมี IC
 *   มติ 8  — รับได้หลายรอบ
 *   มติ 18 — บรรทัดรายการเงินรับไม่ได้
 *
 * PHP 7.4-compatible เท่านั้น
 */

require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/po.php';

$user = uiGuard();

$poNo   = trim((string)($_GET['po'] ?? ($_POST['po_no'] ?? '')));
$isPost = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
$csrfOk = $isPost && hash_equals(csrfToken(), (string)($_POST['csrf'] ?? ''));
$action = $isPost ? (string)($_POST['action'] ?? '') : '';

$notice = null;
if (!empty($_GET['new'])) {
    $nMango = (int)($_GET['mango'] ?? 0);
    $notice = ['ok', 'บันทึกใบคุมเรียบร้อย — รับของได้เลย'
        . ($nMango > 0 ? ' · เพิ่มรหัสใหม่เข้าทะเบียนวัสดุ Mango ' . $nMango . ' รหัส' : '')];
}

if ($isPost && !$csrfOk) {
    $notice = ['bad', 'CSRF token ไม่ถูกต้อง — โหลดหน้าใหม่แล้วลองอีกครั้ง'];
    $action = '';
}

// ── รับของหนึ่งรอบ ─────────────────────────────────────────────────────
if ($action === 'receive') {
    $qty = [];
    foreach ((array)($_POST['q'] ?? []) as $no => $v) {
        $v = trim((string)$v);
        if ($v !== '' && (float)$v > 0) { $qty[(int)$no] = (float)$v; }
    }
    $r = poReceive($pdo, $poNo, $qty,
                   (string)($_POST['rcv_date'] ?? ''), (string)($_POST['note'] ?? ''), $user);
    $notice = $r['ok']
        ? ['ok', 'รับของแล้ว ' . $r['n_lines'] . ' บรรทัด — เลขที่รอบรับ ' . $r['rcv_no'] . ' · ของเข้า buffer แล้ว']
        : ['bad', $r['error']];
}

// ── ยกเลิก/ปิดใบ ───────────────────────────────────────────────────────
if ($action === 'setstatus') {
    $to = (string)($_POST['to'] ?? '');
    if (in_array($to, ['cancelled', 'closed', 'open'], true)) {
        $st = $pdo->prepare('UPDATE po_headers SET status = ? WHERE po_no = ?');
        $st->execute([$to, $poNo]);
        if ($to === 'open') { poRefreshStatus($pdo, $poNo); }
        $notice = ['ok', 'เปลี่ยนสถานะใบเป็น ' . $to . ' แล้ว'];
    }
}

$po = poGet($pdo, $poNo);
if ($po === null) {
    uiHead('ใบคุม', 'ไม่พบใบที่ต้องการ', $user, '📋');
    echo '<div class="banner b-bad">ไม่พบใบเลขที่ ' . e($poNo) . '</div>';
    echo '<p><a href="' . APP_BASE . '/po.php">← กลับรายการใบคุม</a></p>';
    uiFoot();
    exit;
}

$statusLabel = ['open' => 'ยังไม่รับ', 'partial' => 'รับบางส่วน', 'received' => 'รับครบ',
                'closed' => 'ปิดใบ', 'cancelled' => 'ยกเลิก'];
$statusPill  = ['open' => 'p-muted', 'partial' => 'p-warn', 'received' => 'p-ok',
                'closed' => 'p-info', 'cancelled' => 'p-bad'];

$canReceive = !in_array((string)$po['status'], ['cancelled', 'closed'], true);
$today      = nowBkk()->format('Y-m-d');

$nItem = 0; $nRemain = 0;
foreach ($po['lines'] as $l) {
    if ((string)$l['line_kind'] !== 'item') { continue; }
    $nItem++;
    if ((float)$l['qty_remain'] > 0.00005) { $nRemain++; }
}

uiHead('ใบคุม ' . $po['po_no'], (string)$po['vendor_name'], $user, '📋');
?>

<?php if ($notice !== null): ?>
  <div class="banner <?= $notice[0] === 'ok' ? 'b-ok' : 'b-bad' ?>">
    <?= $notice[0] === 'ok' ? '✓' : '✕' ?> <?= e($notice[1]) ?>
  </div>
<?php endif; ?>

<div class="card">
  <h2><span class="num">1</span> หัวใบ
    <span class="sp">
      <span class="pill <?= $statusPill[(string)$po['status']] ?? 'p-muted' ?>"><?= e($statusLabel[(string)$po['status']] ?? '') ?></span>
      · เหลือรับ <?= $nRemain ?> จาก <?= $nItem ?> บรรทัด
    </span>
  </h2>
  <div class="row" style="align-items:flex-start;gap:26px">
    <dl class="kv" style="flex:1;min-width:290px">
      <dt>เลขที่ใบสั่งซื้อ</dt><dd class="mono"><b><?= e((string)$po['po_no']) ?></b></dd>
      <dt>วันที่</dt><dd><?= e((string)($po['po_date'] ?? '—')) ?></dd>
      <dt>เลขที่ใบขอซื้อ</dt><dd class="mono"><?= e((string)($po['pr_no'] ?? '—')) ?></dd>
      <dt>ไซต์</dt><dd><?= e((string)$po['proj_code']) ?> · <?= e((string)$po['proj_name']) ?></dd>
      <dt>ผู้ขาย</dt><dd><?= e((string)$po['vendor_name']) ?></dd>
      <dt>ผู้ติดต่อ</dt><dd><?= e((string)($po['vendor_contact'] ?? '—')) ?> <?= e((string)($po['vendor_phone'] ?? '')) ?></dd>
    </dl>
    <dl class="kv" style="flex:1;min-width:290px">
      <dt>ยอดก่อนหักส่วนลด</dt><dd><?= fmtM($po['sum_before']) ?></dd>
      <dt>หักส่วนลดพิเศษ</dt><dd><?= fmtM($po['special_discount']) ?></dd>
      <dt>ยอดหลังหัก</dt><dd><?= fmtM($po['sum_after']) ?></dd>
      <dt>VAT</dt><dd><?= fmtM($po['vat']) ?></dd>
      <dt>รวมสุทธิ</dt><dd><b><?= fmtM($po['grand_total']) ?></b></dd>
      <dt>เงื่อนไขชำระ</dt><dd><?= e((string)($po['payment_terms'] ?? '—')) ?></dd>
    </dl>
  </div>
  <div class="row" style="margin-top:11px">
    <?php if ($po['src_file'] !== null && $po['src_file'] !== ''): ?>
      <a href="<?= APP_BASE ?>/<?= e((string)$po['src_file']) ?>" target="_blank"><button class="ghost mini" type="button">เปิด PDF ต้นฉบับ</button></a>
    <?php endif; ?>
    <a href="<?= APP_BASE ?>/rc.php?po=<?= urlencode((string)$po['po_no']) ?>"><button class="ghost mini" type="button">ดูของใน buffer →</button></a>
    <?php if ((string)$po['status'] !== 'cancelled'): ?>
      <form method="post" style="display:inline" onsubmit="return confirm('ยกเลิกใบนี้? ของที่รับเข้ามาแล้วยังอยู่ใน buffer เหมือนเดิม')">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="po_no" value="<?= e((string)$po['po_no']) ?>">
        <input type="hidden" name="to" value="cancelled">
        <button class="ghost mini" type="submit" name="action" value="setstatus">ยกเลิกใบ</button>
      </form>
    <?php else: ?>
      <form method="post" style="display:inline">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="po_no" value="<?= e((string)$po['po_no']) ?>">
        <input type="hidden" name="to" value="open">
        <button class="ghost mini" type="submit" name="action" value="setstatus">เปิดใบกลับ</button>
      </form>
    <?php endif; ?>
  </div>
</div>


<form method="post">
<input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
<input type="hidden" name="po_no" value="<?= e((string)$po['po_no']) ?>">

<div class="card">
  <h2><span class="num">2</span> บรรทัดและยอดคุม
    <span class="sp">ช่อง "รับรอบนี้" ใส่ได้ไม่เกินคอลัมน์ "เหลือรับ" (มติ 6)</span>
  </h2>
  <div class="scroll">
    <table>
      <tr>
        <th class="num">#</th><th>รหัส / ชื่อ</th><th>หน่วยซื้อ</th>
        <th class="num">ยอดคุม</th><th class="num">รับแล้ว</th><th class="num">เหลือรับ</th>
        <th class="num">ใน buffer</th><th class="num">รับรอบนี้</th>
      </tr>
      <?php foreach ($po['lines'] as $l):
            $isItem  = ((string)$l['line_kind'] === 'item');
            $remain  = (float)$l['qty_remain'];
            $bufLeft = ($l['buffer_id'] !== null) ? (float)$l['buf_in'] - (float)$l['buf_out'] : 0.0;
      ?>
        <tr class="<?= $isItem ? '' : 'adjust' ?>">
          <td class="num"><?= (int)$l['line_no'] ?></td>
          <td>
            <span class="mono small"><?= e((string)($l['mat_code'] ?? '—')) ?></span><br>
            <?= e((string)$l['mat_name']) ?>
            <?php if (!$isItem): ?><span class="pill p-warn">รายการเงิน</span><?php endif; ?>
            <?php if ($l['description'] !== null && $l['description'] !== ''): ?>
              <div class="desc"><?= e((string)$l['description']) ?></div>
            <?php endif; ?>
          </td>
          <td><?= e((string)$l['unit_po_name']) ?></td>
          <td class="num"><?= $isItem ? fmtQ($l['qty']) : '—' ?></td>
          <td class="num"><?= $isItem ? fmtQ($l['qty_received']) : '—' ?></td>
          <td class="num"><?= $isItem ? fmtQ($remain) : '—' ?></td>
          <td class="num"><?= $bufLeft > 0 ? fmtQ($bufLeft) : '—' ?></td>
          <td class="num">
            <?php if ($isItem && $canReceive && $remain > 0.00005): ?>
              <input type="text" name="q[<?= (int)$l['line_no'] ?>]" style="width:88px;text-align:right"
                     placeholder="0" data-max="<?= e((string)$remain) ?>">
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <?php if ($canReceive && $nRemain > 0): ?>
    <div class="row" style="margin-top:13px;align-items:flex-end">
      <div><label class="fld">วันที่รับ</label><input type="text" name="rcv_date" value="<?= e($today) ?>" style="width:130px"></div>
      <div style="flex:1;min-width:200px"><label class="fld">หมายเหตุ</label><input type="text" name="note" style="width:100%" placeholder="เช่น เลขที่ใบส่งของของผู้ขาย"></div>
      <div>
        <button type="button" class="ghost" onclick="fillAll()">ใส่ยอดที่เหลือทุกบรรทัด</button>
      </div>
      <div><button type="submit" name="action" value="receive">บันทึกการรับ</button></div>
    </div>
    <p class="small" style="margin-top:8px">รับแล้วของจะไปนอนใน buffer ทันที โดยยังไม่ต้องมีรหัส IC (มติ 7)</p>
  <?php elseif (!$canReceive): ?>
    <p class="small" style="margin-top:11px">ใบนี้สถานะ <?= e($statusLabel[(string)$po['status']] ?? '') ?> — รับของเพิ่มไม่ได้</p>
  <?php else: ?>
    <p class="small" style="margin-top:11px">รับครบทุกบรรทัดแล้ว</p>
  <?php endif; ?>
</div>
</form>


<div class="card">
  <h2><span class="num">3</span> รอบรับของ <span class="sp"><?= count($po['receipts']) ?> รอบ</span></h2>
  <?php if (empty($po['receipts'])): ?>
    <p class="small">ยังไม่มีการรับ</p>
  <?php else: ?>
    <div class="scroll">
      <table>
        <tr><th>เลขที่รอบรับ</th><th>วันที่</th><th>หมายเหตุ</th><th>รายการ</th></tr>
        <?php foreach ($po['receipts'] as $rc):
              $st = $pdo->prepare(
                  'SELECT rl.line_no, rl.qty, l.mat_name, l.unit_po_name
                   FROM po_receipt_lines rl JOIN po_lines l ON l.po_no = ? AND l.line_no = rl.line_no
                   WHERE rl.rcv_no = ? ORDER BY rl.line_no');
              $st->execute([(string)$po['po_no'], (string)$rc['rcv_no']]);
              $rls = $st->fetchAll();
        ?>
          <tr>
            <td class="mono"><?= e((string)$rc['rcv_no']) ?></td>
            <td><?= e((string)$rc['rcv_date']) ?></td>
            <td><?= e((string)($rc['note'] ?? '—')) ?></td>
            <td class="small">
              <?php foreach ($rls as $rl): ?>
                #<?= (int)$rl['line_no'] ?> <?= e((string)$rl['mat_name']) ?>
                <b><?= fmtQ($rl['qty']) ?></b> <?= e((string)$rl['unit_po_name']) ?><br>
              <?php endforeach; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endif; ?>
</div>

<script>
function fillAll(){
  document.querySelectorAll('input[data-max]').forEach(function(el){ el.value = el.dataset.max; });
}
</script>

<?php
uiFoot('ยอดคุมบล็อกการรับเกินที่ชั้น PHP ก่อนเขียนฐานเสมอ (มติ 6)');
