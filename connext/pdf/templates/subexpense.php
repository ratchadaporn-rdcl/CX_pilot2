<?php
/**
 * CONNEXT — pdf/templates/subexpense.php : รายการหักค่าใช้จ่ายผู้รับเหมา คจช. (A4 แนวนอน)
 * ล้อ generateSubExpensePDF ของ GAS v1.10 (ชีต 22 คอลัมน์):
 *   แถบหัว 3 ชั้น (title / Project / Subject) + ตารางหัว 2 ชั้น (ห้องพัก·ค่าไฟเกิน·ร้านค้า·
 *   มิเตอร์ไฟร้านค้า แตกช่องย่อย + ราคา/อัตราจากตั้งค่าของไซต์บนหัวช่อง) + คอลัมน์
 *   "ชื่อใช้เบิก Payment" (Mango vendor ของชุด) + แถวรวมทั้งสิ้นบวกทุกคอลัมน์ +
 *   ช่องเซ็น 2-3 คน (ผู้จัดทำ [+ผู้ตรวจสอบ] +ผู้อนุมัติ) พร้อมฝังภาพลายเซ็น
 *
 * vars: $projectName, $siteCode, $siteName, $periodLabel, $shopHeader, $elecHeader, $meterHeader,
 *       $rows[] = { no, subName, payLines[], outside(bool), note,
 *                   roomQty..totalAmt (สตริงจัดรูปแล้ว '' = ว่าง) }
 *       $totals = แถวรวม (คีย์เดียวกับ rows ส่วนตัวเลข), $signs[] = {role,name,pos,img},
 *       $anySigImg(bool), $genStamp
 *       $fragment (บูล ไม่บังคับ) — true = fragment (โหมด "พิมพ์รวม" ต่อท้ายด้วยเอกสารแนบ)
 * สีเอกสาร: ขาว-ดำ (monotone) ใช้เฉพาะเฉดเทา — patch 2026-10-08-pdf-monotone
 * พื้นหลังเฉพาะแถบหัวข้อหลัก ที่เหลือแบ่งด้วยเส้น — patch 2026-10-08-pdf-lines
 */
if (!isset($fragment)) { $fragment = false; }
$NAME_DOTS = '( .................................................. )';
$NUMCOLS = ['roomQty','roomRate','roomAmt','elecUsed','elecOver','elecAmt','shopQty','shopRate','shopAmt',
            'shopElecPrev','shopElecCurr','shopElecUnits','shopElecAmt',
            'materialAmt','advanceAmt','safetyFine','faceScanFine','totalAmt'];
?>
<?php if (!$fragment): ?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<style>
*{box-sizing:border-box}html{margin:0;padding:0}body{margin:8mm 7mm 12mm 7mm;padding:0;font-family:"sarabun",sans-serif}



</style>
</head>
<body>
<?php endif; ?>
<style>
.se-root{color:#000000;font-size:9px;line-height:1.3}.se-root .se-title{background:#000000;color:#fff;text-align:center;font-weight:bold;font-size:12.5px;padding:6px 10px}.se-root .se-proj{color:#000000;font-weight:bold;font-size:11px;padding:5px 10px;border-bottom:1px solid #737373}.se-root .se-subject{font-weight:bold;font-size:10.5px;padding:4px 10px 6px}.se-root table.se-grid{width:100%;border-collapse:collapse;table-layout:fixed}.se-root table.se-grid tr.wcal td{border:none;padding:0;height:0;line-height:0;font-size:0;background:none}.se-root table.se-grid th{color:#000000;font-weight:bold;font-size:8px;padding:3px 2px;border:1px solid #000000;text-align:center;line-height:1.15;vertical-align:middle;word-wrap:break-word}.se-root table.se-grid td{border:1px solid #737373;padding:3px 3px;font-size:9px;vertical-align:middle;word-wrap:break-word}.se-root .c{text-align:center}.se-root .r{text-align:right}.se-root .l{text-align:left}.se-root .se-pay{font-size:8.5px}.se-root table.se-grid tr.se-total td{color:#000000;font-weight:bold;font-size:9px;border:1px solid #000000;border-top:1.5px solid #000000}.se-root table.se-sign{width:100%;border-collapse:collapse;margin-top:14px;page-break-inside:avoid}.se-root table.se-sign td{text-align:center;vertical-align:bottom;padding:0 18px}.se-root .se-sign .role{font-weight:bold;font-size:10px}.se-root .se-sign .slot{height:<?php echo !empty($anySigImg) ? 50 : 28; ?>px}.se-root .se-sign .slot img{width:115px;height:46px}.se-root .se-sign .line{border-bottom:1px solid #333333;margin:0 12px 5px;height:1px}.se-root .se-sign .name{font-size:9.5px}.se-root .se-sign .pos{font-size:9px;color:#333333;margin-top:3px}.se-root .se-foot{margin-top:10px;text-align:center;font-size:8px;color:#808080;font-style:italic}




























</style>
<div class="se-root">
  <div class="se-title">CONNEXT &nbsp; &mdash; &nbsp; รายการหักค่าใช้จ่ายผู้รับเหมา (คจช.)</div>
  <div class="se-proj"><?php
    echo e('Project :  ' . $projectName . '     ·     Site : ' . $siteCode
         . ($siteName !== '' ? ' (' . $siteName . ')' : ''));
  ?></div>
  <div class="se-subject"><?php echo e('Subject :  รายการหักค่าห้อง พักร้านค้า และวัสดุอื่น ๆ  ผู้รับเหมางวด' . $periodLabel); ?></div>

  <?php /* สัดส่วนคอลัมน์ = COLW ของ GAS [28,120,92,38,44,52,...]/1192 — หัวเป็น rowspan/colspan
           จึงคุมความกว้างด้วย "แถววัดคอลัมน์" สูง 0 (dompdf ไม่อ่าน <col>/px — ดู stats_report) */ ?>
  <table class="se-grid">
    <thead>
      <tr class="wcal"><?php
        foreach ([2.3,10.1,7.7,3.2,3.7,4.4,3.7,3.7,4.4,3.2,3.7,4.4,3.7,3.7,3.5,4.4,4.2,4.2,4.9,4.9,5.0,7.0] as $w) {
            echo '<td style="width:' . $w . '%"></td>';
        }
      ?></tr>
      <tr>
        <th rowspan="2">ลำดับ</th>
        <th rowspan="2">ผู้รับเหมาชุด</th>
        <th rowspan="2">ชื่อใช้เบิก Payment</th>
        <th colspan="3">ห้องพัก</th>
        <th colspan="3"><?php echo e($elecHeader); ?></th>
        <th colspan="3"><?php echo e($shopHeader); ?></th>
        <th colspan="4"><?php echo e($meterHeader); ?></th>
        <th rowspan="2">วัสดุฯ</th>
        <th rowspan="2">Advance</th>
        <th rowspan="2">หักผิดกฏระเบียบความปลอดภัย</th>
        <th rowspan="2">หักผิดกฏระเบียบ ไม่สแกนนิ้ว/หน้า</th>
        <th rowspan="2">รวม</th>
        <th rowspan="2">หมายเหตุ</th>
      </tr>
      <tr>
        <th>จำนวน</th><th>ราคา/งวด</th><th>จำนวนเงิน</th>
        <th>จำนวนที่ใช้</th><th>จำนวนที่เกิน</th><th>จำนวนเงิน</th>
        <th>จำนวน</th><th>ราคา/งวด</th><th>จำนวนเงิน</th>
        <th>ครั้งก่อน</th><th>ปัจจุบัน</th><th>รวมหน่วย</th><th>จำนวนเงิน</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr<?php echo $r['outside'] ? ' class="se-outside"' : ''; ?>>
        <td class="c"><?php echo (int)$r['no']; ?></td>
        <td class="l"><?php echo e($r['subName']); ?></td>
        <td class="l se-pay"><?php foreach ($r['payLines'] as $pl) { echo '<div>' . e($pl) . '</div>'; } ?></td>
        <?php foreach ($NUMCOLS as $k): ?><td class="r"><?php echo e($r[$k]); ?></td><?php endforeach; ?>
        <td class="l"><?php echo e($r['note']); ?></td>
      </tr>
      <?php endforeach; ?>
      <tr class="se-total">
        <td class="c" colspan="3">รวมทั้งสิ้น</td>
        <?php foreach ($NUMCOLS as $k): ?><td class="r"><?php echo e($totals[$k]); ?></td><?php endforeach; ?>
        <td></td>
      </tr>
    </tbody>
  </table>

  <table class="se-sign"><tr>
    <?php $w = count($signs) === 3 ? '33.3%' : '50%'; ?>
    <?php foreach ($signs as $sg): ?>
    <td class="se-sign" style="width:<?php echo $w; ?>">
      <div class="role"><?php echo e($sg['role']); ?></div>
      <div class="slot"><?php if (!empty($sg['img'])): ?><img src="<?php echo e($sg['img']); ?>" alt=""><?php endif; ?></div>
      <div class="line"></div>
      <div class="name"><?php echo e($sg['name'] !== '' ? $sg['name'] : $NAME_DOTS); ?></div>
      <?php if ($sg['pos'] !== ''): ?><div class="pos"><?php echo e($sg['pos']); ?></div><?php endif; ?>
    </td>
    <?php endforeach; ?>
  </tr></table>

  <div class="se-foot"><?php echo e('สร้างจากระบบ CONNEXT — เมนู “หักค่าใช้จ่ายผู้รับเหมา”   |   ' . $genStamp); ?></div>
</div>
<?php if (!$fragment): ?>
</body>
</html>
<?php endif; ?>
