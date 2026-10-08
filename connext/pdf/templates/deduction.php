<?php
/**
 * CONNEXT — pdf/templates/deduction.php : ตารางหักเงินผู้รับเหมาชุด (A4 แนวนอน)
 * ล้อ generateDeductionPDF ของ GAS v1.10 (runtime ชีต 14 คอลัมน์ + แถบ Mango + หัวประจำงวด
 * + ยอดรวมบรรทัดเดียว "รวมเงินหักทั้งสิ้น" + ลายเซ็น 4 ช่องพร้อมฝังภาพลายเซ็นออนไลน์)
 * — 1 ชุด = 1 หน้า
 *
 * vars: $pages[] each = { subName, docNo, mango:{code,name}, period:{label,text},
 *                         rows[], totalStr, signs[4]: {role,name,pos,img} (img = data URL|'') }
 *       $projectName, $resolvedSite, $siteName, $issueDate, $genStamp
 *       $fragment (บูล ไม่บังคับ) — true = ตัดเปลือก DOCTYPE/body ออก ใช้ต่อท้ายเอกสารรวม
 *                                   (โหมด "พิมพ์รวม" ของหักคจช. — ล้อ hostSS ของ GAS)
 * สีเอกสาร: ขาว-ดำ (monotone) ใช้เฉพาะเฉดเทา — patch 2026-10-08-pdf-monotone
 * พื้นหลังเฉพาะแถบหัวข้อหลัก ที่เหลือแบ่งด้วยเส้น — patch 2026-10-08-pdf-lines
 */
if (!isset($fragment)) { $fragment = false; }
$NAME_DOTS = '( .................................................. )';
$ddAnyImg = false;
foreach ($pages as $__pg) {
    foreach ($__pg['signs'] as $__s) {
        if (!empty($__s['img'])) { $ddAnyImg = true; break 2; }
    }
}
?>
<?php if (!$fragment): ?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<style>
*{box-sizing:border-box}html{margin:0;padding:0}body{margin:9mm 8mm;padding:0;font-family:"sarabun",sans-serif}



</style>
</head>
<body>
<?php endif; ?>
<style>
.dd-root{color:#000000;font-size:10px;line-height:1.3}.dd-root .sheet{page-break-after:always}.dd-root .sheet.last{page-break-after:auto}.dd-root .title-band{background:#000000;color:#fff;text-align:center;font-weight:bold;font-size:12.5px;padding:6px 10px;border:1.4px solid #000000}.dd-root .title-band small{font-weight:normal;font-size:10px;color:#d9d9d9}.dd-root .tname-band{color:#000000;text-align:center;font-weight:bold;font-size:15px;padding:6px 10px;border-left:1.4px solid #000000;border-right:1.4px solid #000000;border-bottom:1px solid #737373}.dd-root .tname-band .lbl{font-size:11px;color:#404040}.dd-root .mango-band{color:#000000;text-align:center;font-weight:bold;font-size:12px;padding:5px 10px;border-left:1.4px solid #000000;border-right:1.4px solid #000000}.dd-root table.meta{width:100%;border-collapse:collapse;border:1.4px solid #000000;table-layout:fixed}.dd-root table.meta td{padding:5px 8px;border:1px solid #bfbfbf;vertical-align:middle}.dd-root table.meta td.k{color:#333333;font-weight:bold;font-size:9.5px;white-space:nowrap}.dd-root table.meta td.v{font-size:10.5px}.dd-root table.meta td.v.b{font-weight:bold;color:#000000}.dd-root table.main{width:100%;border-collapse:collapse;margin-top:7px;table-layout:fixed}.dd-root table.main thead th{color:#000000;font-weight:bold;font-size:9px;padding:5px 3px;border:1px solid #000000;line-height:1.1;vertical-align:middle;text-align:center}.dd-root table.main tbody td{border:1px solid #737373;padding:4px 4px;font-size:9.5px;vertical-align:middle;word-wrap:break-word}.dd-root .t-c{text-align:center}.dd-root .t-r{text-align:right}.dd-root .t-l{text-align:left}.dd-root table.main tbody tr.total td{font-weight:bold;font-size:9.5px;border:1px solid #000000;border-top:1.5px solid #000000;padding:4px 5px}.dd-root table.main tbody tr.total td.lbl{text-align:right}.dd-root table.main tbody tr.total td.void{background:transparent;border:none}.dd-root .note-band{margin-top:8px;border:1px solid #737373;padding:6px 10px;font-size:8.5px;color:#404040}.dd-root .note-band b{color:#000000}.dd-root table.sign{width:100%;margin-top:16px;border-collapse:collapse;page-break-inside:avoid}.dd-root table.sign td{width:25%;text-align:center;vertical-align:bottom;padding:0 11px}.dd-root .sign .role{font-weight:bold;font-size:10.5px;color:#000000}.dd-root .sign .slot{height:<?php echo $ddAnyImg ? 50 : 22; ?>px;vertical-align:bottom}.dd-root .sign .slot img{width:115px;height:46px}.dd-root .sign .line{border-bottom:1px solid #333333;margin:0 6px 11px;height:1px}.dd-root .sign .name{font-size:9.5px;color:#000000;min-height:13px}.dd-root .sign .pos{margin-top:4px;font-size:9px;color:#333333;display:inline-block;padding:1px 9px;border:1px solid #999999}.dd-root .foot{margin-top:14px;border-top:1px solid #999999;padding-top:5px;text-align:center;font-size:8px;color:#808080;font-style:italic}














































</style>
<div class="dd-root">
<?php $nPages = count($pages); ?>
<?php foreach ($pages as $pi => $pg): ?>
<div class="sheet<?php echo $pi === $nPages - 1 ? ' last' : ''; ?>">
  <div class="title-band">CONNEXT &nbsp;&mdash;&nbsp; รายการหักเงิน : ผู้รับเหมาชุด &nbsp;<small>(แนบประกอบการหักเงินค่าวัสดุ)</small></div>
  <div class="tname-band"><span class="lbl">ผู้รับเหมาชุด :</span> &nbsp; <?php echo e($pg['subName']); ?></div>
  <div class="mango-band"><?php
    $mg = $pg['mango'];
    if ($mg['code'] !== '' || $mg['name'] !== '') {
        echo e('Mango Vendor :  ' . ($mg['code'] !== '' ? $mg['code'] : '-') . '   ·   ' . ($mg['name'] !== '' ? $mg['name'] : '-'));
    } else {
        echo 'Mango Vendor :  (ยังไม่ได้จับคู่)';
    }
  ?></div>
  <?php /* ความกว้างเป็น % บนเซลล์แถวแรก — dompdf 2.0.8 ไม่อ่าน <col>/px (ดู balance.php) */ ?>
  <table class="meta">
    <tr>
      <td class="k" style="width:10.3%">Project</td><td class="v" style="width:23%"><?php echo e($projectName); ?></td>
      <td class="k" style="width:12.6%">Site Code</td><td class="v b" style="width:23%"><?php echo e($resolvedSite); ?></td>
      <td class="k" style="width:11.5%"><?php echo e($pg['period']['label']); ?></td><td class="v b" style="width:19.6%"><?php echo e($pg['period']['text']); ?></td>
    </tr>
    <tr>
      <td class="k">Site Name</td><td class="v"><?php echo e($siteName); ?></td>
      <td class="k">วันที่ออกเอกสาร</td><td class="v"><?php echo e($issueDate); ?></td>
      <td class="k">เลขที่เอกสาร</td><td class="v b"><?php echo e($pg['docNo']); ?></td>
    </tr>
  </table>

  <table class="main">
    <?php /* สัดส่วนคอลัมน์ตาม COLW ของ GAS [30,74,86,104,72,150,...] — ขยับเฉพาะ "เลขที่" 86→96
             กับ "รหัสวัสดุ" 104→118 ให้เลขเอกสาร 13 ตัว/รหัส IC 20 ตัว (ยุค PHP) จบบรรทัดเดียว
             โดยเฉือนจาก "รายการ" 150→138 */ ?>
    <thead><tr>
      <th style="width:2.8%">ลำดับ</th><th style="width:7%">ว/ด/ป</th>
      <th style="width:9.1%">เลขที่</th><th style="width:11.2%">รหัสวัสดุ</th>
      <th style="width:6.8%">หมวดวัสดุ</th><th style="width:13%">รายการ</th>
      <th style="width:4%">หน่วย</th><th style="width:4.3%">ปริมาณ</th>
      <th style="width:6%">ราคา/<br>หน่วย</th><th style="width:7.2%">จำนวนเงิน<br>(บาท)</th>
      <th style="width:9.1%">ชื่อผู้เบิก</th><th style="width:9.1%">ชื่อผู้รับ</th>
      <th style="width:4.2%">แผนก</th><th style="width:6.2%">หมายเหตุ</th>
    </tr></thead>
    <tbody>
      <?php foreach ($pg['rows'] as $r): ?>
      <tr>
        <td class="t-c"><?php echo (int)$r['no']; ?></td>
        <td class="t-c"><?php echo e($r['dateTh']); ?></td>
        <td class="t-c"><?php echo e($r['docId']); ?></td>
        <td class="t-c"><?php echo e($r['matCode']); ?></td>
        <td class="t-l"><?php echo e($r['subgroup']); ?></td>
        <td class="t-l"><?php echo e($r['name']); ?></td>
        <td class="t-c"><?php echo e($r['unit']); ?></td>
        <td class="t-c"><?php echo e($r['qty']); ?></td>
        <td class="t-r"><?php echo e($r['price']); ?></td>
        <td class="t-r"><?php echo e($r['amt']); ?></td>
        <td class="t-l"><?php echo e($r['picker']); ?></td>
        <td class="t-l"><?php echo e($r['receiver']); ?></td>
        <td class="t-c"></td>
        <td class="t-l"></td>
      </tr>
      <?php endforeach; ?>
<?php /* GAS v1.9.3: ไม่คิด VAT — ยอดรวมบรรทัดเดียว ป้าย "รวมเงินหักทั้งสิ้น" (renderTotalsNote)
         กรอบแถวรวมคลุมเฉพาะช่องหน่วย..จำนวนเงิน — ช่องอื่นเว้นโปร่งเหมือนชีตต้นฉบับ */ ?>
      <tr class="total">
        <td class="void" colspan="6"></td><td class="lbl" colspan="3">รวมเงินหักทั้งสิ้น</td>
        <td class="t-r"><?php echo e($pg['totalStr']); ?></td><td class="void" colspan="4"></td>
      </tr>
    </tbody>
  </table>

  <div class="note-band"><b>หมายเหตุ:</b>  คอลัมน์ &ldquo;ราคา/หน่วย&rdquo; ดึงจาก Rate Card ของ Site นี้ · รายการที่ยังไม่ได้ตั้งราคาจะเว้นว่าง (ตั้งราคาได้ที่เมนู &ldquo;ตั้งราคาหักเงิน&rdquo; หน้าตรวจสอบประจำวัน)</div>

  <table class="sign"><tr>
    <?php foreach ($pg['signs'] as $sg): ?>
    <td class="sign">
      <div class="role"><?php echo e($sg['role']); ?></div>
      <div class="slot"><?php if (!empty($sg['img'])): ?><img src="<?php echo e($sg['img']); ?>" alt=""><?php endif; ?></div>
      <div class="line"></div>
      <div class="name"><?php echo e($sg['name'] !== '' ? $sg['name'] : $NAME_DOTS); ?></div>
      <?php if ($sg['pos'] !== ''): ?><div class="pos"><b><?php echo e($sg['pos']); ?></b></div><?php endif; ?>
    </td>
    <?php endforeach; ?>
  </tr></table>

  <div class="foot"><?php echo e('สร้างจากระบบ CONNEXT — Inventory Control Module · หน้า “ตรวจสอบประจำวัน”   |   ' . $genStamp); ?></div>
</div>
<?php endforeach; ?>
</div>
<?php if (!$fragment): ?>
</body>
</html>
<?php endif; ?>
