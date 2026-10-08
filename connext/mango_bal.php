<?php
/**
 * CONNEXT — mango_bal.php : รายงานแปลงรหัส Mango → IC + ส่งออก PDF/CSV
 *
 * ตอบ 2 คำถามที่ใช้คู่กันเวลากระทบยอดกับ ERP เดิมที่ยังถือรหัส Mango
 *   1. รหัส Mango ที่รับเข้ามา กลายเป็น IcCode ตัวไหนไปบ้าง เท่าไหร่
 *   2. ยอดที่ตัดเบิก/คงเหลือใต้ IcCode คิดกลับเป็นหน่วยซื้อของรหัส Mango ได้เท่าไหร่ (มติ 39)
 *
 * เหตุผลทั้งหมด (ทำไมเลิกเป็นหน้า "ยอดคงเหลือ" · ทำไมหน่วยซื้อเป็นส่วนหนึ่งของคีย์ ·
 * ทำไมฝั่ง IC ห้าม SUM qty_consumed · สูตรถัวเฉลี่ยถ่วงน้ำหนักและตัวหารที่ต้องเป็น qty_in)
 * — อยู่ในหัวไฟล์ lib/mango_bal.php
 *
 * ชื่อไฟล์ยังเป็น mango_bal.php ตามเดิมเพื่อไม่ให้ลิงก์/บุ๊กมาร์กเดิมพัง
 *
 * สิทธิ์: uiGuard() = CanReq หรือ ADM · คนที่ไม่ใช่ R0 เห็นเฉพาะไซต์ตัวเอง
 * PHP 7.4-compatible เท่านั้น
 */

require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/mango_bal.php';

$user = uiGuard();

$isAdmin  = uiIsAdmin($user);
$projId   = mbResolveProject($user, $_GET['proj'] ?? 0);
$onlyLeft = (string)($_GET['lp'] ?? '') === '1';
$q        = trim((string)($_GET['q'] ?? ''));

$pj = $pdo->prepare('SELECT code, name FROM projects WHERE id = ?');
$pj->execute([$projId]);
$pjRow    = $pj->fetch() ?: ['code' => '', 'name' => ''];
$projCode = (string)$pjRow['code'];
$projName = (string)$pjRow['name'];

$rows   = mbRows($pdo, $projId, $onlyLeft, $q);
$orphan = mbOrphanMango($pdo, $projId);   // ต้องว่างเสมอ — มติ 38
$adjust = mbAdjustRows($pdo, $projId);    // ของที่เข้านอกสาย PO = ปรับยอด — มติ 39

$export   = (string)($_GET['export'] ?? '');
$baseName = 'mango-ic-' . ($projCode !== '' ? $projCode . '-' : '') . date('Ymd-Hi');

// ── ส่งออก CSV (เอาไป pivot/VLOOKUP ต่อใน Excel) ─────────────────────────
// ทำฝั่ง server เพราะ BOM + ภาษาไทยผ่าน Blob ฝั่ง client เพี้ยนได้บนบางเบราว์เซอร์
if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $baseName . '.csv"');
    $fh = fopen('php://output', 'w');
    mbWriteCsv($fh, $rows, $orphan, $adjust);
    fclose($fh);
    exit;
}

// ── ส่งออกแบบฟอร์ม PDF (เอกสารสำหรับส่งต่อ/เก็บเข้าแฟ้ม) ─────────────────
if ($export === 'pdf') {
    require_once __DIR__ . '/lib/pdf_engine.php';
    $t    = mbTotals($rows);
    $html = pdfRenderTemplate('mango_balance.php', [
        'titleTxt' => 'CONNEXT  —  รหัส Mango ที่รับเข้ามา แปลงเป็น IcCode อะไรไปบ้าง'
                    . '   ·   ' . number_format($t['n']) . ' รหัส'
                    . '   ·   ' . number_format($t['ic']) . ' IC',
        'siteTxt'  => 'Site: ' . ($projCode !== '' ? $projCode : '-')
                    . ($projName !== '' ? ' · ' . $projName : '')
                    . ($q !== '' ? '  ·  กรองคำค้น "' . $q . '"' : '')
                    . ($onlyLeft ? '  ·  เฉพาะที่ยังค้างใน buffer' : ''),
        'stamp'    => date('d/m/Y H:i'),
        'rows'     => $rows,
        'orphan'   => $orphan,
        'adjust'   => $adjust,
        'tot'      => $t,
    ]);
    $bin = renderPdf($html, 'a4', 'landscape', ['pageNumbers' => true]);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $baseName . '.pdf"');
    header('Content-Length: ' . strlen($bin));
    echo $bin;
    exit;
}

$projects = $isAdmin
    ? $pdo->query("SELECT id, code, name FROM projects WHERE status='active' ORDER BY code")->fetchAll()
    : [];
$tot = mbTotals($rows);

/** ลิงก์กลับมาหน้านี้พร้อมตัวกรองเดิม */
function mbUrl(array $over = []): string {
    $qs = array_merge([
        'proj' => (string)($_GET['proj'] ?? ''), 'lp' => (string)($_GET['lp'] ?? ''),
        'q'    => (string)($_GET['q'] ?? ''),
    ], $over);
    $qs = array_filter($qs, function ($v) { return (string)$v !== ''; });
    return uiUrl(APP_BASE . '/mango_bal.php' . ($qs ? '?' . http_build_query($qs) : ''));
}

uiHead('Mango → IC', 'รหัส Mango ที่รับเข้ามากลายเป็น IcCode อะไร และยอดใต้ IC คิดกลับเป็นหน่วยซื้อได้เท่าไหร่', $user, '🔗');
?>

<style>
.mb-filter{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end}.mb-filter input[name=q]{width:230px}.mb-chk{display:flex;align-items:center;gap:6px;font-size:.82rem;padding-bottom:8px}.mb-sum{display:flex;flex-wrap:wrap;gap:9px;margin-bottom:13px}.mb-chip{background:#fff;border:1px solid var(--line);border-radius:10px;padding:8px 13px;font-size:.8rem}.mb-chip b{font-size:1.02rem;display:block;font-variant-numeric:tabular-nums}.mb-chip.go{border-color:#bbf7d0;background:var(--green-bg)}.mb-chip.stop{border-color:#fcd34d;background:var(--gold-soft)}.mb-chip.bad{border-color:#fca5a5;background:#fee2e2;color:#7f1d1d}.mb-dl{display:flex;flex-wrap:wrap;gap:10px;align-items:center;background:var(--navy);color:#fff;border-radius:11px;padding:12px 15px;margin-bottom:14px}.mb-dl .t{font-weight:700;font-size:.9rem}.mb-dl .s{font-size:.76rem;opacity:.8}.mb-dlbtn{margin-left:auto;display:flex;gap:8px;flex-wrap:wrap}.mb-dlbtn a{text-decoration:none}.mb-dl button{background:var(--gold);color:#3b2f05;font-weight:800;padding:10px 20px}.mb-dl button.alt{background:rgba(255,255,255,.14);color:#fff;font-weight:700}.mb-alarm{background:#fee2e2;border:2px solid #dc2626;border-radius:11px;padding:13px 16px;margin-bottom:14px;color:#7f1d1d;font-size:.82rem;line-height:1.7}.mb-alarm h3{margin:0 0 7px;font-size:.95rem;color:#991b1b}.mb-alarm table{margin-top:9px;background:#fff}.mb-alarm .sym{font-weight:700;color:#b91c1c}.mb-warn{background:var(--gold-soft);border:1px solid #fcd34d;border-radius:10px;padding:10px 13px;font-size:.79rem;color:#78350f;line-height:1.65;margin-bottom:12px}.mb-code{font-family:ui-monospace,Consolas,monospace;font-weight:700}.mb-ic{font-size:.75rem;line-height:1.6}.mb-ic .cd{font-family:ui-monospace,Consolas,monospace;font-weight:700}.mb-ic .nm{color:var(--muted)}.mb-ic .tag{color:var(--amber);font-weight:700}.mb-ic .none{color:var(--muted)}.mb-icrow+.mb-icrow{margin-top:7px;padding-top:7px;border-top:1px dashed var(--line)}.mb-rate{color:#1d4ed8;font-size:.72rem}.mb-left{font-weight:800;color:#b45309}.mb-erp{background:#eff6ff;font-weight:700}.mb-gap{font-size:.7rem;font-weight:700;color:#b91c1c}.mb-po{font-size:.72rem;color:var(--muted)}tfoot td{background:#f8fafc;font-weight:700;border-top:2px solid var(--line)}.mb-empty{text-align:center;color:var(--muted);padding:30px 12px;font-size:.88rem}.mb-note{font-size:.78rem;color:var(--muted);line-height:1.7;margin-top:10px}












































</style>

<?php if ($orphan): ?>
  <div class="mb-alarm">
    <h3>⚠ ผิดปกติ — พบรหัส Mango ที่ยังมียอด/ยังผูกกับไซต์อยู่ <?= count($orphan) ?> รหัส</h3>
    ตั้งแต่ล้างข้อมูลเริ่มนับหนึ่ง (มติ 33) และตัดรหัส Mango ออกจากฟอร์มเบิก (มติ 34)
    ของทุกชิ้นในโครงการต้องอยู่ใต้ <b>รหัส IC เท่านั้น</b> — แถวด้านล่างจึงไม่ควรมีอยู่เลย
    มักเกิดจากสคริปต์ข้อมูลจำลองหรือการยิง SQL เข้าฐานตรง ๆ ไม่ใช่จากการทำงานปกติของระบบ
    <b>ยอดในหน้าอื่นจะไม่ตรงกับสาย PO จนกว่าจะเคลียร์</b> · แจ้งผู้ดูแลระบบ
    <div class="scroll">
      <table>
        <thead><tr>
          <th style="width:150px">รหัส Mango</th><th>ชื่อวัสดุ</th>
          <th style="width:64px">หน่วย</th>
          <th class="num" style="width:84px">รับเข้า</th>
          <th class="num" style="width:84px">จ่ายออก</th>
          <th class="num" style="width:88px">คงเหลือ</th>
          <th style="width:210px">อาการ</th>
        </tr></thead>
        <tbody>
          <?php foreach ($orphan as $r): ?>
            <tr>
              <td class="mb-code"><?= e($r['mat_code']) ?></td>
              <td><?= e($r['name']) ?></td>
              <td><?= e($r['unit']) ?></td>
              <td class="num"><?= fmtQ($r['qty_in']) ?></td>
              <td class="num"><?= fmtQ($r['qty_out']) ?></td>
              <td class="num"><?= fmtQ($r['on_hand']) ?></td>
              <td class="sym"><?php
                $sym = [];
                if ($r['has_balance']) { $sym[] = 'มียอดคงเหลือ'; }
                if ($r['has_form'])    { $sym[] = 'อยู่ในฟอร์มเบิก'; }
                echo e(implode(' + ', $sym));
              ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <h2>🔗 รหัส Mango ที่รับเข้ามา → แปลงเป็น IcCode
    <span class="sp">ไซต์ <?= e($projCode) ?> · ณ <?= e(thaiDateFull(nowBkk())) ?> <?= e(date('H:i')) ?> น.</span></h2>

  <form method="get" class="mb-filter">
    <?php if (uiIsEmbedded()): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
    <?php if ($isAdmin): ?>
      <div>
        <label class="fld">ไซต์</label>
        <select name="proj" onchange="this.form.submit()">
          <?php foreach ($projects as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $projId ? 'selected' : '' ?>>
              <?= e($p['code'] . ' — ' . $p['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div>
      <label class="fld">ค้นรหัส Mango หรือชื่อวัสดุ</label>
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="เช่น ใบตัด หรือ PG1400">
    </div>
    <label class="mb-chk">
      <input type="checkbox" name="lp" value="1" <?= $onlyLeft ? 'checked' : '' ?> onchange="this.form.submit()">
      เฉพาะที่ยังค้างใน buffer
    </label>
    <button type="submit">ค้นหา</button>
    <?php if ($q !== '' || $onlyLeft): ?>
      <a href="<?= mbUrl(['q' => '', 'lp' => '']) ?>"><button type="button" class="ghost">ล้างตัวกรอง</button></a>
    <?php endif; ?>
  </form>
</div>

<div class="mb-dl">
  <div>
    <div class="t">⤓ ดาวน์โหลดไฟล์ไปคีย์ปรับยอดใน ERP</div>
    <div class="s">CSV = 1 แถวต่อคู่ (รหัส Mango × IC) พร้อมอัตราแปลงและยอดทั้งสองหน่วย · ทศนิยม 4 ตำแหน่งเต็มความละเอียด
      <?= ($q !== '' || $onlyLeft) ? ' · ไฟล์จะได้เฉพาะแถวที่กรองไว้ตอนนี้' : '' ?>
      <br>แบบฟอร์ม PDF = เอกสารสำหรับส่งต่อ/เก็บเข้าแฟ้ม ไล่ตามรหัส Mango เหมือนหน้าจอ</div>
  </div>
  <div class="mb-dlbtn">
    <a href="<?= mbUrl(['export' => 'pdf']) ?>" data-cnx-no-embed><button type="button">📄 แบบฟอร์ม PDF</button></a>
    <a href="<?= mbUrl(['export' => 'csv']) ?>" data-cnx-no-embed><button type="button" class="alt">⤓ CSV</button></a>
  </div>
</div>

<div class="mb-sum">
  <div class="mb-chip go">รหัส Mango ที่รับเข้ามา<b><?= number_format($tot['n']) ?> รหัส</b></div>
  <div class="mb-chip">แปลงเป็น IC แล้ว<b><?= number_format($tot['ic']) ?> รหัส IC</b></div>
  <?php if ($tot['issued_n'] > 0): ?>
    <div class="mb-chip">มียอดตัดเบิก<b><?= number_format($tot['issued_n']) ?> รหัส</b></div>
  <?php endif; ?>
  <?php if ($tot['left_n'] > 0): ?>
    <div class="mb-chip stop">ยังค้างใน buffer<b><?= number_format($tot['left_n']) ?> รหัส</b></div>
  <?php endif; ?>
  <?php if ($adjust): ?>
    <div class="mb-chip stop">ปรับยอด — แปลงกลับไม่ได้<b><?= number_format(count($adjust)) ?> รหัส IC</b></div>
  <?php endif; ?>
  <?php if ($orphan): ?>
    <div class="mb-chip bad">⚠ Mango ที่ยังมียอด<b><?= number_format(count($orphan)) ?> รหัส</b></div>
  <?php endif; ?>
</div>

<div class="card">
  <h2><span class="num">1</span> ไล่ตามรหัส Mango
    <span class="sp">ทุกจำนวนในตารางเป็น<b>หน่วยซื้อ</b> · ช่องสีฟ้า = ตัวเลขที่เอาไปคีย์ ERP</span></h2>

  <?php if (!$rows): ?>
    <div class="mb-empty">
      <?= ($q !== '' || $onlyLeft)
            ? 'ไม่พบรายการตามตัวกรองที่เลือก'
            : 'ยังไม่มีการรับของตามใบ PO ในไซต์นี้ — รายงานจะขึ้นเองเมื่อรับของเข้า buffer' ?>
    </div>
  <?php else: ?>
    <div class="scroll">
      <table>
        <thead>
          <tr>
            <th rowspan="2" style="width:146px">รหัส Mango</th>
            <th rowspan="2">ชื่อวัสดุ</th>
            <th rowspan="2" style="width:62px">หน่วยซื้อ</th>
            <th class="num" rowspan="2" style="width:78px">รับเข้า</th>
            <th class="num" colspan="3" style="width:250px">ตอนนี้ของอยู่ไหน (หน่วยซื้อ)</th>
            <th rowspan="2" style="width:330px">IC ที่ได้</th>
          </tr>
          <tr>
            <th class="num" style="width:84px">ค้าง buffer</th>
            <th class="num" style="width:84px">ตัดเบิกแล้ว</th>
            <th class="num" style="width:84px">คงเหลือในคลัง</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="mb-code"><?= e($r['mat_code']) ?></td>
              <td><?= e($r['mat_name']) ?>
                <?php if ($r['po_nos'] !== ''): ?>
                  <div class="mb-po"><?= e($r['po_nos']) ?></div>
                <?php endif; ?></td>
              <td><?= e($r['unit_buy']) ?></td>
              <td class="num" style="font-weight:700"><?= fmtQ($r['qty_recv']) ?></td>
              <td class="num <?= $r['qty_left'] > 0 ? 'mb-left' : '' ?>"><?= fmtQ($r['qty_left']) ?></td>
              <td class="num mb-erp"><?= fmtQ($r['qty_issued_buy']) ?></td>
              <td class="num mb-erp"><?= fmtQ($r['qty_onhand_buy']) ?>
                <?php if (abs($r['gap']) > 0.0001): ?>
                  <div class="mb-gap" title="ค้าง buffer + ตัดเบิก + คงเหลือ ไม่เท่ารับเข้า">
                    ต่าง <?= fmtQ($r['gap']) ?></div>
                <?php endif; ?></td>
              <td class="mb-ic">
                <?php if (!$r['ics']): ?>
                  <span class="none">— <?= e(mbStatusText($r)) ?> —</span>
                <?php else: foreach ($r['ics'] as $ic): ?>
                  <div class="mb-icrow">
                    <span class="cd"><?= e($ic['ic_code']) ?></span>
                    <?php if ($ic['cancelled']): ?>
                      <span class="tag">(ใบถูกยกเลิก — ของเด้งกลับ buffer)</span>
                    <?php else: ?>
                      <span class="nm">— รับ <?= fmtQ($ic['qty_store']) ?> <?= e($ic['unit_store']) ?></span>
                      <?php if ($ic['split']): ?>
                        <span class="tag" title="push เดียวแตกหลาย IC — หน่วยซื้อเป็นค่าเฉลี่ยจากการแบ่ง ไม่ใช่ของที่วัดมา">⚖ แตกชุด</span>
                      <?php endif; ?>
                      <?php if ($ic['rate'] > 0): ?>
                        <div class="mb-rate">1 <?= e($ic['unit_store']) ?> =
                          <?= fmtQ($ic['rate']) ?> <?= e($r['unit_buy']) ?>
                          <?php if ($ic['issued_ic'] > 0): ?>
                            · เบิก <?= fmtQ($ic['issued_ic']) ?> <?= e($ic['unit_store']) ?>
                            → <b><?= fmtQ($ic['issued_buy']) ?> <?= e($r['unit_buy']) ?></b>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($ic['ic_name'] !== ''): ?>
                      <div class="nm"><?= e($ic['ic_name']) ?></div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot><tr>
          <?php /* ไม่รวมจำนวนของท้ายตารางโดยตั้งใจ — แต่ละแถวคนละหน่วยซื้อได้ บวกกันแล้วไม่มีความหมาย */ ?>
          <td colspan="8">รวม <?= number_format($tot['n']) ?> รหัส
            · แปลงแล้ว <?= number_format($tot['conv_n']) ?> รหัส (ได้ <?= number_format($tot['ic']) ?> รหัส IC)
            <?= $tot['left_n'] > 0 ? ' · ยังค้างใน buffer ' . number_format($tot['left_n']) . ' รหัส' : '' ?>
            <?= $tot['issued_n'] > 0 ? ' · มียอดตัดเบิก ' . number_format($tot['issued_n']) . ' รหัส' : '' ?>
            <?= $tot['split_n'] > 0 ? ' · ⚖ เฉลี่ยจากการแตกชุด ' . number_format($tot['split_n']) . ' รหัส' : '' ?>
          </td>
        </tr></tfoot>
      </table>
    </div>

    <div class="mb-note">
      <b>2 ช่องสีฟ้าคือตัวเลขที่เอาไปคีย์ใน ERP</b> — คิดกลับจากยอดใต้รหัส IC มาเป็นหน่วยซื้อ
      ด้วย<b>อัตราถัวเฉลี่ยถ่วงน้ำหนัก</b> (Σ หน่วยซื้อที่จ่ายไป ÷ ยอดรับเข้าทั้งหมดของ IC ตัวนั้น)
      เพราะ IC เดียวกันรับมาได้หลายอัตรา และรับมาจากหลายรหัส Mango ได้
      <br>ตรวจง่าย ๆ: <b>ค้าง buffer + ตัดเบิกแล้ว + คงเหลือในคลัง = รับเข้า</b> ทุกแถว
      ถ้าไม่ตรงจะขึ้นตัวเลข "ต่าง" สีแดงกำกับไว้
      <br>ป้าย <span class="tag" style="color:var(--amber);font-weight:700">⚖ แตกชุด</span>
      = push เดียวแตกหลาย IC (มติ 10) หน่วยซื้อของ IC นั้นเป็นค่าที่<b>เฉลี่ยตามสัดส่วนหน่วยเก็บ</b>
      ไม่ใช่ของที่วัดมาจริง — ผลรวมยังปิดพอดี แต่ตัวเลขรายตัวเป็นค่าประมาณ
    </div>
  <?php endif; ?>
</div>

<?php if ($adjust): ?>
  <div class="card">
    <h2><span class="num">2</span> ปรับยอด — แปลงกลับเป็นรหัส Mango ไม่ได้
      <span class="sp"><?= count($adjust) ?> รหัส IC · ไม่ได้รวมอยู่ในตารางบน</span></h2>

    <div class="mb-warn">
      ของก้อนนี้เข้าคลังโดย<b>ไม่ผ่านสายใบสั่งซื้อ</b> (ฟอร์ม "รับเข้าคลัง" ในแอปหลัก) จึงไม่มีทั้ง
      รหัส Mango ต้นทางและอัตราแปลง — <b>เอาไปคีย์ ERP ไม่ได้</b> และตั้งใจกันออกจากยอดข้างบน
      เพราะถ้าเหมารวม ยอดที่ป้อนจะเกินของที่ซื้อจริง
      <br>ถ้าของก้อนนี้ควรผูกกับใบสั่งซื้อ ให้รับผ่านหน้า <b>รับของ/buffer</b> แทน แล้วยอดจะไปโผล่ในตารางบนเอง
    </div>

    <div class="scroll">
      <table>
        <thead><tr>
          <th style="width:180px">รหัส IC</th>
          <th>ชื่อวัสดุ</th>
          <th style="width:66px">หน่วยเก็บ</th>
          <th class="num" style="width:92px">รับเข้าทั้งหมด</th>
          <th class="num" style="width:104px">มาจากใบสั่งซื้อ</th>
          <th class="num" style="width:92px">ปรับยอด</th>
          <th class="num" style="width:100px">ตัดเบิกส่วนนี้</th>
          <th class="num" style="width:100px">คงเหลือส่วนนี้</th>
        </tr></thead>
        <tbody>
          <?php foreach ($adjust as $r): ?>
            <tr>
              <td class="mb-code"><?= e($r['ic_code']) ?></td>
              <td><?= e($r['name']) ?></td>
              <td><?= e($r['unit']) ?></td>
              <td class="num"><?= fmtQ($r['qty_in']) ?></td>
              <td class="num"><?= fmtQ($r['base_store']) ?></td>
              <td class="num mb-left"><?= fmtQ($r['adjust']) ?></td>
              <td class="num"><?= fmtQ($r['issued']) ?></td>
              <td class="num"><?= fmtQ($r['onhand']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php uiFoot('ข้อมูล ณ เวลาที่เปิดหน้า — กดดาวน์โหลดใหม่ทุกครั้งที่จะเอาไปกระทบยอด เพื่อไม่ให้ใช้ไฟล์เก่า'); ?>
