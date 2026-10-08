<?php
/**
 * CONNEXT — pdf/templates/doc_report.php : รายงานเอกสารรายใบ (A4 แนวตั้ง)
 * ล้อ layout ชีตชั่วคราวของ generateDocReportPDF (Code.js)
 *
 * vars: $siteHeader, $docLabel, $docId, $meta[[k,v]...],
 *       $items[{no,matCode,name,qty(ขอ),actual(หยิบจริง),diff,diffCls,unit,reason}], $noticeText,
 *       (ไม่บังคับ · 2026-09-29) $itemsTitle · $colQty · $colActual — ใบนับสต๊อก (SC) ใช้ "ในระบบ / นับได้"
 *       $photoRows [[{label,src|null,note|null}, ...(สูงสุด 2)]...], $footerText
 * สีเอกสาร: ขาว-ดำ (monotone) ใช้เฉพาะเฉดเทา — patch 2026-10-08-pdf-monotone
 * พื้นหลังเฉพาะแถบหัวข้อหลัก ที่เหลือแบ่งด้วยเส้น — patch 2026-10-08-pdf-lines
 */
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<style>
*{box-sizing:border-box}html{margin:0;padding:0}body{margin:12mm 12mm;padding:0;font-family:"sarabun",sans-serif;color:#000000;font-size:13px;line-height:1.35}.site-band{text-align:center;font-size:12px;padding:6px 8px;border-bottom:1px solid #000000}.doc-label{text-align:center;font-size:22px;font-weight:bold;margin-top:8px}.doc-no{text-align:center;font-size:22px;font-weight:bold;margin-bottom:12px}table.meta{width:100%;border-collapse:collapse;margin-bottom:14px}table.meta td{border:1px solid #999999;padding:6px 9px;font-size:13.5px;vertical-align:middle}table.meta td.k{font-weight:bold;width:130px}.items-title{background:#f0f0f0;text-align:center;font-weight:bold;font-size:13.5px;padding:7px 8px;border:1px solid #737373;border-bottom:none}table.items{width:100%;border-collapse:collapse;table-layout:fixed}table.items th{color:#000000;font-weight:bold;font-size:13px;padding:6px 5px;border:1px solid #000000}table.items td{border:1px solid #737373;padding:5px 6px;font-size:13px;vertical-align:middle;word-wrap:break-word}.t-c{text-align:center}.t-l{text-align:left}.notice-title{background:#f0f0f0;font-weight:bold;font-size:13.5px;padding:6px 9px;margin-top:14px;border:1px solid #999999;border-bottom:none}.notice-body{border:1px solid #999999;padding:8px 9px;font-size:13px;min-height:44px}table.photos{width:100%;border-collapse:collapse;margin-top:16px}table.photos th.photos-title{background:#f0f0f0;text-align:center;font-weight:bold;font-size:13.5px;padding:6px 8px;border:none}table.photos td{width:50%;padding:6px;vertical-align:top;text-align:center}.photo-label{font-weight:bold;font-size:12px;margin-bottom:5px}.photo-img{max-width:300px;max-height:300px}.photo-note{font-size:10px;font-style:italic;color:#333333;word-wrap:break-word;text-align:left}.foot{margin-top:18px;text-align:center;font-size:10px;font-style:italic;color:#808080}table.items td.code{font-size:11.5px}table.items td.reason{font-size:11px}table.items td.diff.up{color:#000000;font-weight:bold}table.items td.diff.down{color:#000000;font-weight:bold}






































</style>
</head>
<body>

  <div class="site-band"><?php echo e($siteHeader); ?></div>
  <div class="doc-label"><?php echo e($docLabel); ?></div>
  <div class="doc-no"><?php echo e($docId); ?></div>

  <table class="meta">
    <?php foreach ($meta as $pair): ?>
    <tr>
      <td class="k"><?php echo e($pair[0]); ?></td>
      <td><?php echo e($pair[1]); ?></td>
    </tr>
    <?php endforeach; ?>
  </table>

  <div class="items-title"><?php echo e(isset($itemsTitle) ? $itemsTitle : 'รายการวัสดุ'); ?></div>
  <table class="items">
    <?php /* ความกว้างเป็น % บนเซลล์หัว (dompdf 2.0.8 ไม่อ่าน <col>/px — ดู balance.php)
             รหัสวัสดุกว้างพอรหัส IC 20 ตัวอักษรบรรทัดเดียว (ฟอนต์ 11.5px ในคอลัมน์รหัส)
             Scenario 05 ⑦: จำนวนที่ขอ · หยิบจริง · ผลต่าง ทุกรายการ + เหตุผลของรายการที่ลด (คอลัมน์หมายเหตุ) */ ?>
    <thead>
      <tr><th style="width:5%">#</th><th style="width:22%">รหัสวัสดุ</th>
          <th style="width:29%">รายการ</th><th style="width:8%"><?php echo e(isset($colQty) ? $colQty : 'ขอ'); ?></th>
          <th style="width:9%"><?php echo e(isset($colActual) ? $colActual : 'หยิบจริง'); ?></th><th style="width:8%">ผลต่าง</th>
          <th style="width:7%">หน่วย</th><th style="width:12%">หมายเหตุ</th></tr>
    </thead>
    <tbody>
      <?php foreach ($items as $it): ?>
      <tr>
        <td class="t-c"><?php echo (int)$it['no']; ?></td>
        <td class="t-l code"><?php echo e($it['matCode']); ?></td>
        <td class="t-l"><?php echo e($it['name']); ?></td>
        <td class="t-c"><?php echo e($it['qty']); ?></td>
        <td class="t-c"><b><?php echo e($it['actual'] ?? '-'); ?></b></td>
        <td class="t-c diff <?php echo e($it['diffCls'] ?? ''); ?>"><?php echo e($it['diff'] ?? '-'); ?></td>
        <td class="t-c"><?php echo e($it['unit']); ?></td>
        <td class="t-l reason"><?php echo e($it['reason'] ?? ''); ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="notice-title">หมายเหตุ</div>
  <div class="notice-body"><?php echo nl2br(e($noticeText)); ?></div>

  <?php if (!empty($photoRows)): ?>
  <table class="photos">
    <thead><tr><th class="photos-title" colspan="2">รูปภาพยืนยัน</th></tr></thead>
    <?php foreach ($photoRows as $row): ?>
    <tr>
      <?php foreach ($row as $cell): ?>
      <td>
        <div class="photo-label"><?php echo e($cell['label']); ?></div>
        <?php if (!empty($cell['src'])): ?>
          <img class="photo-img" src="<?php echo e($cell['src']); ?>" alt="">
        <?php else: ?>
          <div class="photo-note"><?php echo nl2br(e((string)$cell['note'])); ?></div>
        <?php endif; ?>
      </td>
      <?php endforeach; ?>
      <?php if (count($row) === 1): ?><td></td><?php endif; ?>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>

  <div class="foot"><?php echo e($footerText); ?></div>

</body>
</html>
