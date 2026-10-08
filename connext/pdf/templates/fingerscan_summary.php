<?php
/**
 * CONNEXT — pdf/templates/fingerscan_summary.php : สรุปค่าปรับผู้รับเหมาจากการแสกนนิ้ว
 * ล้อ generateFingerScanPDF ของ GAS v1.10 (ชีต 6 คอลัมน์): แถบหัว 4 ชั้น (title/โครงการ/งวด/ยอดรวม)
 * + ตาราง [ลำดับที่ · ชุดผู้รับเหมา · ค่าปรับ · อัตราการแสกนนิ้ว% · ผู้รับเหมาลงนามรับทราบ · หมายเหตุ]
 * + ฝังภาพลายเซ็น (เซ็นจริง/รับทราบโดยปริยาย) + บล็อกผู้จัดทำ (ผู้ดูแลผู้รับเหมา)
 *
 * vars: $projectName, $siteCode, $siteName, $periodLabel (เช่น 'วันที่ 16-31 ส.ค.69'),
 *       $rateTxt, $daysRecorded, $total,
 *       $rows[] = { no, subName, fineStr, fineRed(bool), rateStr, sign:{img,lines[]}, note }
 *       $preparer = { name, pos, img }, $genStamp
 *       $fragment (บูล ไม่บังคับ) — true = fragment ต่อท้ายเอกสารรวมของหักคจช. (ล้อ hostSS)
 * สีเอกสาร: ขาว-ดำ (monotone) ใช้เฉพาะเฉดเทา — patch 2026-10-08-pdf-monotone
 * พื้นหลังเฉพาะแถบหัวข้อหลัก ที่เหลือแบ่งด้วยเส้น — patch 2026-10-08-pdf-lines
 */
if (!isset($fragment)) { $fragment = false; }
$NAME_DOTS = '( .................................................. )';
$fsAnySig = false;
foreach ($rows as $__r) {
    if (!empty($__r['sign']['img'])) { $fsAnySig = true; break; }
}
?>
<?php if (!$fragment): ?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<style>
*{box-sizing:border-box}html{margin:0;padding:0}body{margin:10mm 9mm 12mm 9mm;padding:0;font-family:"sarabun",sans-serif}



</style>
</head>
<body>
<?php endif; ?>
<style>
.fs-root{color:#000000;font-size:9.5px;line-height:1.35}.fs-root .fs-band{padding:5px 10px}.fs-root .fs-title{background:#000000;color:#fff;text-align:center;font-weight:bold;font-size:12.5px;padding:6px 10px}.fs-root .fs-proj{color:#000000;font-weight:bold;font-size:10.5px;border-bottom:1px solid #737373}.fs-root .fs-period{font-size:10px;padding:4px 10px 5px}.fs-root table.fs-tot{width:100%;border-collapse:collapse;table-layout:fixed}.fs-root table.fs-tot td{color:#000000;font-weight:bold;padding:5px 10px;border-top:1px solid #000000;border-bottom:1px solid #000000}.fs-root table.fs-grid{width:100%;border-collapse:collapse;table-layout:fixed;margin-top:6px}.fs-root table.fs-grid th{color:#000000;font-weight:bold;font-size:9px;padding:5px 4px;border:1px solid #000000;text-align:center;line-height:1.15;vertical-align:middle}.fs-root table.fs-grid td{border:1px solid #737373;padding:4px 5px;font-size:9.5px;vertical-align:middle;word-wrap:break-word}.fs-root .c{text-align:center}.fs-root .r{text-align:right}.fs-root .l{text-align:left}.fs-root .fine-red{color:#000000;font-weight:bold}.fs-root td.fs-sign{text-align:center;font-size:8.5px;vertical-align:top}.fs-root td.fs-sign img{width:88px;height:34px;display:block;margin:2px auto 1px}.fs-root table.fs-grid tr.fs-total td{color:#000000;font-weight:bold;font-size:10px;border:1px solid #000000;border-top:1.5px solid #000000}.fs-root table.fs-prep{width:100%;border-collapse:collapse;margin-top:14px;page-break-inside:avoid}.fs-root table.fs-prep td{vertical-align:bottom;text-align:center}.fs-root .fs-prep .role{font-weight:bold;font-size:10px}.fs-root .fs-prep .slot{height:<?php echo !empty($preparer['img']) ? 50 : 28; ?>px}.fs-root .fs-prep .slot img{width:115px;height:46px}.fs-root .fs-prep .line{border-bottom:1px solid #333333;margin:0 24px 5px;height:1px}.fs-root .fs-prep .name{font-size:9.5px}.fs-root .fs-prep .pos{font-size:9px;color:#333333;margin-top:3px}.fs-root .fs-foot{margin-top:10px;text-align:center;font-size:8px;color:#808080;font-style:italic}






























</style>
<div class="fs-root">
  <div class="fs-title">CONNEXT &nbsp; &mdash; &nbsp; สรุปค่าปรับผู้รับเหมาจากการแสกนนิ้ว</div>
  <div class="fs-band fs-proj"><?php
    echo e('โครงการ :  ' . $projectName . '     ·     Site : ' . $siteCode
         . ($siteName !== '' ? ' (' . $siteName . ')' : ''));
  ?></div>
  <div class="fs-period"><?php
    echo e('งวด' . $periodLabel . '     ·     อัตราค่าปรับ ' . $rateTxt . ' บาท/คน/วัน     ·     บันทึกแล้ว '
         . (int)$daysRecorded . ' วัน');
  ?></div>
  <table class="fs-tot"><tr>
    <td style="width:30%">ยอดปรับรวมทั้งงวด</td>
    <td class="r" style="width:14%; font-size:11px;"><?php echo e(number_format(round((float)$total))); ?></td>
    <td style="width:56%">บาท</td>
  </tr></table>

  <?php /* สัดส่วนคอลัมน์ตาม COLW ของ GAS [42,210,92,100,150,130] (แบบกว้าง [61,305,...] สัดส่วนเดียวกัน) */ ?>
  <table class="fs-grid">
    <thead><tr>
      <th style="width:5.8%">ลำดับที่</th>
      <th style="width:29%">ชุดผู้รับเหมา</th>
      <th style="width:12.7%">ค่าปรับไม่แสกนนิ้ว<br>(บาท)</th>
      <th style="width:13.8%">อัตราการแสกนนิ้ว/งวด<br>(%)</th>
      <th style="width:20.7%">ผู้รับเหมาลงนามรับทราบ</th>
      <th style="width:18%">หมายเหตุ</th>
    </tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td class="c"><?php echo (int)$r['no']; ?></td>
        <td class="l"><?php echo e($r['subName']); ?></td>
        <td class="r<?php echo $r['fineRed'] ? ' fine-red' : ''; ?>"><?php echo e($r['fineStr']); ?></td>
        <td class="c"><?php echo e($r['rateStr']); ?></td>
        <td class="fs-sign">
          <?php if (!empty($r['sign']['img'])): ?><img src="<?php echo e($r['sign']['img']); ?>" alt=""><?php endif; ?>
          <?php foreach ($r['sign']['lines'] as $ln): ?><div><?php echo e($ln); ?></div><?php endforeach; ?>
        </td>
        <td class="l"><?php echo e($r['note']); ?></td>
      </tr>
      <?php endforeach; ?>
      <tr class="fs-total">
        <td class="c" colspan="2">รวมทั้งสิ้น</td>
        <td class="r"><?php echo e(number_format(round((float)$total))); ?></td>
        <td colspan="3"></td>
      </tr>
    </tbody>
  </table>

  <table class="fs-prep"><tr>
    <td style="width:61%"></td>
    <td style="width:39%" class="fs-prep">
      <div class="role">ผู้จัดทำ (ผู้ดูแลผู้รับเหมา)</div>
      <div class="slot"><?php if (!empty($preparer['img'])): ?><img src="<?php echo e($preparer['img']); ?>" alt=""><?php endif; ?></div>
      <div class="line"></div>
      <div class="name"><?php echo e($preparer['name'] !== '' ? ('( ' . $preparer['name'] . ' )') : $NAME_DOTS); ?></div>
      <?php if ($preparer['pos'] !== ''): ?><div class="pos"><?php echo e($preparer['pos']); ?></div><?php endif; ?>
    </td>
  </tr></table>

  <div class="fs-foot"><?php echo e('สร้างจากระบบ CONNEXT — เมนู “บันทึกสแกนนิ้ว”   |   ' . $genStamp); ?></div>
</div>
<?php if (!$fragment): ?>
</body>
</html>
<?php endif; ?>
