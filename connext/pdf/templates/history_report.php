<?php
/**
 * CONNEXT — pdf/templates/history_report.php : รายงานการเบิกจ่ายหลายใบพร้อมรูป (A4 แนวตั้ง · 2026-09-30)
 * สร้างจาก lib/history_report.php (หน้า "ประวัติเอกสาร" → Export รายงาน)
 *
 * vars: $siteHeader, $title, $filters [[k, v]...], $notes [string...],
 *       $summary [{code, name, unit, docs, req, eff}],
 *       $docs [{docNo, typeLabel, date, status, meta [[k, v]...],
 *               lines [{no, matCode, name, qty, actual, diff, diffCls, unit, reason}],
 *               photoRows [[{label, src|null, link|null, note|null} ...(สูงสุด 3)]], noPhoto, hiddenLines}],
 *       $footerText
 * dompdf 2.0.8: ความกว้างคอลัมน์เป็น % บนเซลล์หัว (ไม่อ่าน <col>) · รูปเป็น JPEG ย่อแล้ว (pdf/imgcache)
 * สีเอกสาร: ขาว-ดำ (monotone) ใช้เฉพาะเฉดเทา — patch 2026-10-08-pdf-monotone
 * พื้นหลังเฉพาะแถบหัวข้อหลัก ที่เหลือแบ่งด้วยเส้น — patch 2026-10-08-pdf-lines
 * รายละเอียด 1 ใบไม่แยกหน้า (.doc avoid) · หัวหมวดไม่ค้างท้ายหน้า (.sec-title avoid) — patch 2026-10-08-history-keep-doc
 */
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<style>
*{box-sizing:border-box}
html{margin:0;padding:0}
body{margin:12mm 11mm 13mm;padding:0;font-family:"sarabun",sans-serif;color:#000000;font-size:12px;line-height:1.3}
.site-band{text-align:center;font-size:11.5px;padding:5px 8px;border-bottom:1px solid #000000}
.title{text-align:center;font-size:20px;font-weight:bold;margin:8px 0 8px}
table.filters{width:100%;border-collapse:collapse;margin-bottom:6px}
table.filters td{border:1px solid #999999;padding:4px 7px;font-size:11.5px;vertical-align:top}
table.filters td.k{font-weight:bold;width:17%}
.notes{font-size:10.5px;color:#333333;margin:2px 0 6px}
.sec-title{background:#000000;color:#ffffff;font-weight:bold;font-size:12.5px;padding:5px 8px;margin-top:10px;page-break-after:avoid}
table.grid{width:100%;border-collapse:collapse;table-layout:fixed}
table.grid th{font-weight:bold;font-size:11px;padding:4px 4px;border:1px solid #000000}
table.grid td{border:1px solid #737373;padding:3px 5px;font-size:11px;vertical-align:middle;word-wrap:break-word}
.t-c{text-align:center}
.t-r{text-align:right}
.code{font-size:10px}
.doc{margin-top:12px;page-break-inside:avoid}
.doc-head{color:#000000;font-weight:bold;font-size:13px;padding:5px 8px;border:1px solid #000000;border-top:2px solid #000000}
.doc-head .st{float:right;font-size:11.5px}
table.meta{width:100%;border-collapse:collapse}
table.meta td{border:1px solid #999999;padding:3px 6px;font-size:11px;vertical-align:top}
table.meta td.k{font-weight:bold;width:14%}
td.fill{background:#ffffff}
td.diff.up{color:#000000;font-weight:bold}
td.diff.down{color:#000000;font-weight:bold}
.muted{color:#595959;font-size:10px}
table.photos{width:100%;border-collapse:collapse;margin-top:4px}
table.photos tr{page-break-inside:avoid}
table.photos td{width:33.33%;padding:4px;vertical-align:top;text-align:center;border:1px solid #bfbfbf}
.photo-label{font-weight:bold;font-size:10px;margin-bottom:3px;word-wrap:break-word}
.photo-img{max-width:215px;max-height:200px}
.photo-note{font-size:9.5px;color:#333333;word-wrap:break-word;text-align:left}
.photo-note a{color:#000000;text-decoration:underline;word-wrap:break-word}
.no-photo{font-size:10.5px;color:#000000;font-weight:bold;padding:4px 2px}
.foot{margin-top:14px;text-align:center;font-size:9.5px;font-style:italic;color:#808080}
</style>
</head>
<body>

  <div class="site-band"><?php echo e($siteHeader); ?></div>
  <div class="title"><?php echo e($title); ?></div>

  <table class="filters">
    <?php foreach (array_chunk($filters, 2) as $pair): ?>
    <tr>
      <?php foreach ($pair as $f): ?>
      <td class="k"><?php echo e($f[0]); ?></td><td><?php echo e($f[1]); ?></td>
      <?php endforeach; ?>
      <?php if (count($pair) === 1): ?><td class="fill" colspan="2"></td><?php endif; ?>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php foreach ($notes as $n): ?><div class="notes">• <?php echo e($n); ?></div><?php endforeach; ?>

  <div class="sec-title">สรุปตามรหัส IC</div>
  <table class="grid">
    <thead><tr><th style="width:5%">#</th><th style="width:25%">รหัส IC</th><th style="width:36%">รายการ</th>
      <th style="width:8%">ใบ</th><th style="width:9%">ขอรวม</th><th style="width:10%">จ่ายจริงรวม</th><th style="width:7%">หน่วย</th></tr></thead>
    <tbody>
    <?php $i = 0; foreach ($summary as $s): $i++; ?>
      <tr><td class="t-c"><?php echo $i; ?></td><td class="code"><?php echo e($s['code']); ?></td><td><?php echo e($s['name']); ?></td>
        <td class="t-c"><?php echo (int)$s['docs']; ?></td><td class="t-r"><?php echo e($s['req']); ?></td>
        <td class="t-r"><b><?php echo e($s['eff']); ?></b></td><td class="t-c"><?php echo e($s['unit']); ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="muted">จ่ายจริง = จำนวนหยิบจริงที่บันทึกตอนถ่ายรูปยืนยัน (ยังไม่ยืนยัน = จำนวนที่ขอ) — ตรงกับที่ระบบตัดสต๊อก</div>

  <div class="sec-title">รายละเอียดรายใบ พร้อมรูป (<?php echo count($docs); ?> ใบ)</div>
  <?php foreach ($docs as $d): ?>
  <div class="doc">
    <div class="doc-head"><span class="st"><?php echo e($d['status']); ?></span>
      <?php echo e($d['docNo']); ?> · <?php echo e($d['typeLabel']); ?> · <?php echo e($d['date']); ?></div>
    <table class="meta">
      <?php foreach (array_chunk($d['meta'], 3) as $trio): ?>
      <tr>
        <?php foreach ($trio as $m): ?><td class="k"><?php echo e($m[0]); ?></td><td><?php echo e($m[1]); ?></td><?php endforeach; ?>
        <?php for ($x = count($trio); $x < 3; $x++): ?><td class="fill" colspan="2"></td><?php endfor; ?>
      </tr>
      <?php endforeach; ?>
    </table>
    <table class="grid">
      <thead><tr><th style="width:5%">#</th><th style="width:23%">รหัส IC</th><th style="width:28%">รายการ</th>
        <th style="width:7%">ขอ</th><th style="width:8%">หยิบจริง</th><th style="width:7%">ผลต่าง</th><th style="width:7%">หน่วย</th><th style="width:15%">หมายเหตุ</th></tr></thead>
      <tbody>
      <?php foreach ($d['lines'] as $l): ?>
        <tr><td class="t-c"><?php echo (int)$l['no']; ?></td><td class="code"><?php echo e($l['matCode']); ?></td><td><?php echo e($l['name']); ?></td>
          <td class="t-c"><?php echo e($l['qty']); ?></td><td class="t-c"><b><?php echo e($l['actual']); ?></b></td>
          <td class="t-c diff <?php echo e($l['diffCls']); ?>"><?php echo e($l['diff']); ?></td><td class="t-c"><?php echo e($l['unit']); ?></td>
          <td style="font-size:10px"><?php echo e($l['reason']); ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!empty($d['hiddenLines'])): ?><div class="muted">+ อีก <?php echo (int)$d['hiddenLines']; ?> รายการในใบนี้ที่ไม่ตรงกับรหัส IC ที่กรอง (ไม่แสดง)</div><?php endif; ?>
    <?php if (!empty($d['noPhoto'])): ?>
      <div class="no-photo">ไม่มีรูปการเบิกจ่ายของใบนี้<?php echo $d['status'] === 'อนุมัติแล้ว' || $d['status'] === 'รออนุมัติ' ? ' (ยังไม่ได้ผ่านประตู)' : ''; ?></div>
    <?php else: ?>
    <table class="photos">
      <?php foreach ($d['photoRows'] as $row): ?>
      <tr>
        <?php foreach ($row as $c): ?>
        <td>
          <div class="photo-label"><?php echo e($c['label']); ?></div>
          <?php if (!empty($c['src'])): ?>
            <img class="photo-img" src="<?php echo e($c['src']); ?>" alt="">
          <?php elseif (!empty($c['link'])): ?>
            <div class="photo-note"><?php echo e($c['note']); ?><br><a href="<?php echo e($c['link']); ?>"><?php echo e($c['link']); ?></a></div>
          <?php else: ?>
            <div class="photo-note"><?php echo e((string)$c['note']); ?></div>
          <?php endif; ?>
        </td>
        <?php endforeach; ?>
        <?php for ($x = count($row); $x < 3; $x++): ?><td style="border:none"></td><?php endfor; ?>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>

  <div class="foot"><?php echo e($footerText); ?></div>

</body>
</html>
