<?php
/**
 * CONNEXT — pdf/templates/balance.php : ยอดคงเหลือวัสดุ (A4 แนวนอน)
 * ล้อ .build/template4.html + ตรรกะ generateBalancePDF (Code.js)
 * หัวเรื่อง + หัวตารางอยู่ใน <thead> → ซ้ำทุกหน้า (แทน frozen rows ของ Sheets)
 *
 * vars: $titleTxt, $includeSite (bool — ทุก Site → เพิ่มคอลัมน์ Site), $rows[]
 *   $rows[]['gates'] = ยอดรายประตู "G01 10 · G03 50" (มติ 51 — [PHP port 2026-09-25 per-gate])
 * สีเอกสาร: ขาว-ดำ (monotone) ใช้เฉพาะเฉดเทา — patch 2026-10-08-pdf-monotone
 * พื้นหลังเฉพาะแถบหัวข้อหลัก ที่เหลือแบ่งด้วยเส้น — patch 2026-10-08-pdf-lines
 */
$nCols = $includeSite ? 12 : 11;
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<style>
*{box-sizing:border-box}html{margin:0;padding:0}body{margin:9mm 8mm 12mm 8mm;padding:0;font-family:"sarabun",sans-serif;color:#000000;font-size:9.5px}table{width:100%;border-collapse:collapse;table-layout:fixed}tr.title-row td{background:#000000;color:#ffffff;text-align:center;font-weight:bold;font-size:12px;padding:7px;border:none}th{color:#000000;font-weight:bold;font-size:9.5px;padding:5px 3px;border:1px solid #000000;text-align:center}td{border:1px solid #737373;padding:4px 5px;font-size:9px;vertical-align:middle;word-wrap:break-word}.c{text-align:center}.r{text-align:right}.l{text-align:left}.oh{font-weight:bold}.ok{color:#000000}.out{color:#000000;font-weight:bold}.neg{color:#000000;font-weight:bold}

















</style>
</head>
<body>
  <table>
    <?php /* ความกว้างคอลัมน์เป็น % บนเซลล์แถวหัว — dompdf 2.0.8 (table-layout:fixed)
             ไม่อ่าน width จาก <col> และไม่อ่านหน่วย px จากเซลล์ อ่านเฉพาะ % บนเซลล์
             · สัดส่วนตาม cols ของ GAS [34,(56),116,116,336/286,...] — ขยับเฉพาะ "รหัสวัสดุ"
               116→130 ให้รหัส IC 20 ตัวอักษร (ยุค PHP) จบบรรทัดเดียว โดยเฉือนจาก "ชื่อวัสดุ" */ ?>
    <thead>
      <tr class="title-row"><td colspan="<?php echo $nCols; ?>"><?php echo e($titleTxt); ?></td></tr>
      <?php if ($includeSite): ?>
      <tr>
        <th style="width:3.4%">ลำดับ</th><th style="width:5.6%">Site</th>
        <th style="width:13%">รหัส IC</th><th style="width:10.6%">หมวดวัสดุ</th>
        <th style="width:18.2%">ชื่อวัสดุ</th><th style="width:5.2%">หน่วย</th>
        <th style="width:6.2%">รับเข้า</th><th style="width:6.2%">รอจ่าย</th>
        <th style="width:6.2%">จ่ายออก</th><th style="width:7.4%">คงเหลือ</th>
        <th style="width:11%">รายประตู</th><th style="width:7%">สถานะ</th>
      </tr>
      <?php else: ?>
      <tr>
        <th style="width:3.4%">ลำดับ</th>
        <th style="width:13.1%">รหัส IC</th><th style="width:10.6%">หมวดวัสดุ</th>
        <th style="width:23.5%">ชื่อวัสดุ</th><th style="width:5.2%">หน่วย</th>
        <th style="width:6.2%">รับเข้า</th><th style="width:6.2%">รอจ่าย</th>
        <th style="width:6.2%">จ่ายออก</th><th style="width:7.4%">คงเหลือ</th>
        <th style="width:11.2%">รายประตู</th><th style="width:7%">สถานะ</th>
      </tr>
      <?php endif; ?>
    </thead>
    <tbody>
      <?php foreach ($rows as $i => $r): ?>
      <tr>
        <td class="c"><?php echo $i + 1; ?></td>
        <?php if ($includeSite): ?><td class="c"><?php echo e($r['site']); ?></td><?php endif; ?>
        <td class="l"><?php echo e($r['matCode']); ?></td>
        <td class="l"><?php echo e($r['subgroup']); ?></td>
        <td class="l"><?php echo e($r['name']); ?></td>
        <td class="c"><?php echo e($r['unit']); ?></td>
        <td class="r"><?php echo e(pdfBalNum($r['inv'])); ?></td>
        <td class="r"><?php echo e(pdfBalNum($r['pending'])); ?></td>
        <td class="r"><?php echo e(pdfBalNum($r['out'])); ?></td>
        <td class="r oh<?php echo $r['onhand'] < 0 ? ' neg' : ''; ?>"><?php echo e(pdfBalNum($r['onhand'])); ?></td>
        <td class="l"><?php echo e((string)($r['gates'] ?? '')); ?></td>
        <td class="c"><?php echo $r['onhand'] > 0 ? '<span class="ok">มีของ</span>' : ($r['onhand'] < 0 ? '<span class="neg">ติดลบ</span>' : '<span class="out">หมด</span>'); /* [2026-10-02 · GP-42] */ ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</body>
</html>
