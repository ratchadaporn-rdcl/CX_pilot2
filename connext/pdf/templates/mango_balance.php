<?php
/**
 * CONNEXT — pdf/templates/mango_balance.php : รหัส Mango → IcCode (A4 แนวนอน)
 *
 * ล้อรูปแบบ pdf/templates/balance.php ของหน้า Dashboard (แถบหัวสีดำ · ตารางเส้นเต็ม ·
 * หัวตารางอยู่ใน <thead> จึงซ้ำทุกหน้าแทน frozen rows ของ Sheets)
 *
 * 1 แถว = (รหัส Mango × หน่วยซื้อ) · **ทุกจำนวนในตารางหลักเป็นหน่วยซื้อ** รวมทั้ง 2 ช่อง
 * ที่คิดกลับมาจากยอดใต้ IC (ตัดเบิกแล้ว/คงเหลือ — มติ 39) ตีกรอบหนาไว้เพราะเป็นตัวเลขที่เอาไปคีย์ ERP
 * คอลัมน์ IC กางว่าแตกเป็นตัวไหนบ้าง พร้อมอัตราแปลง (หน่วยซื้อต่อ 1 หน่วยเก็บ)
 * ไม่มีแถวรวมจำนวนท้ายตารางโดยตั้งใจ (แต่ละแถวคนละหน่วยซื้อได้ บวกกันแล้วไม่มีความหมาย)
 *
 * ตารางเตือนบนสุดโผล่เฉพาะตอนผิดปกติ — รหัส Mango ต้องไม่มียอดเลย (มติ 33/34/38)
 * ตารางท้าย = ของที่เข้ามานอกสายใบสั่งซื้อ (ปรับยอด) แปลงกลับไม่ได้ (มติ 39)
 *
 * vars: $titleTxt, $stamp, $siteTxt, $rows[], $orphan[], $adjust[], $tot[]
 * สีเอกสาร: ขาว-ดำ (monotone) ใช้เฉพาะเฉดเทา — patch 2026-10-08-pdf-monotone
 * พื้นหลังเฉพาะแถบหัวข้อหลัก ที่เหลือแบ่งด้วยเส้น — patch 2026-10-08-pdf-lines
 */

// ตารางเสริม 2 ตัวเป็น optional — ผู้เรียกที่ไม่ส่งมาให้ถือว่าไม่มีแถว ไม่ใช่ error
$orphan = isset($orphan) && is_array($orphan) ? $orphan : [];
$adjust = isset($adjust) && is_array($adjust) ? $adjust : [];
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<style>
*{box-sizing:border-box}html{margin:0;padding:0}body{margin:9mm 8mm 12mm 8mm;padding:0;font-family:"sarabun",sans-serif;color:#000000;font-size:9.5px}table{width:100%;border-collapse:collapse;table-layout:fixed}tr.title-row td{background:#000000;color:#ffffff;text-align:center;font-weight:bold;font-size:12px;padding:7px;border:none}tr.sub-row td{color:#000000;font-size:9px;padding:4px 7px;border:1px solid #737373;text-align:left}tr.alarm-title td{background:#000000;color:#ffffff;text-align:center;font-weight:bold;font-size:11px;padding:6px;border:none}tr.alarm-row td{color:#000000;font-size:9px;padding:5px 7px;border:1px solid #000000;text-align:left;font-weight:bold}th{color:#000000;font-weight:bold;font-size:9.5px;padding:5px 3px;border:1px solid #000000;text-align:center}td{border:1px solid #737373;padding:4px 5px;font-size:9px;vertical-align:top;word-wrap:break-word}tfoot td{font-weight:bold;border-top:1.5px solid #000000}.c{text-align:center}.r{text-align:right}.l{text-align:left}.conv{font-weight:bold}.left{font-weight:bold;color:#000000}.zero{color:#999999}.mono{font-family:"sarabun",sans-serif;letter-spacing:.2px}.ic{font-size:8.5px}.ic .cd{font-weight:bold}.ic .nm{color:#4d4d4d}.tag{color:#000000}.po{font-size:8px;color:#595959}.erp{font-weight:bold;border-left:1.5px solid #000000;border-right:1.5px solid #000000}.rate{color:#333333;font-size:8px}.gap-warn{color:#000000;font-size:7.5px;font-weight:bold}.note{margin-top:9px;font-size:8.5px;color:#4d4d4d;line-height:1.55}.gap{height:14px}


































</style>
</head>
<body>

<?php if ($orphan): ?>

<table>
  <?php /* ความกว้างคอลัมน์เป็น % บนเซลล์แถวหัว — dompdf 2.0.8 (table-layout:fixed)
           ไม่อ่าน width จาก <col>/หน่วย px อ่านเฉพาะ % บนเซลล์ (ดู balance.php) */ ?>
  <thead>
    <tr class="alarm-title"><td colspan="8">
      ⚠ ผิดปกติ — พบรหัส Mango ที่ยังมียอด/ยังผูกกับไซต์อยู่ <?php echo count($orphan); ?> รหัส
    </td></tr>
    <tr class="alarm-row"><td colspan="8">
      ของทุกชิ้นในโครงการต้องอยู่ใต้รหัส IC เท่านั้น (มติ 33/34) แถวด้านล่างจึงไม่ควรมีอยู่เลย
      &nbsp;·&nbsp; มักเกิดจากสคริปต์ข้อมูลจำลองหรือการยิง SQL เข้าฐานตรง ๆ ไม่ใช่การทำงานปกติของระบบ
      &nbsp;·&nbsp; ยอดในรายงานอื่นจะไม่ตรงกับสายใบสั่งซื้อจนกว่าจะเคลียร์ — แจ้งผู้ดูแลระบบ
    </td></tr>
    <tr>
      <th style="width:4.0%">ลำดับ</th><th style="width:15.0%">รหัส Mango</th>
      <th style="width:32.0%">ชื่อวัสดุ</th><th style="width:6.0%">หน่วย</th>
      <th style="width:8.0%">รับเข้า</th><th style="width:8.0%">จ่ายออก</th>
      <th style="width:8.0%">คงเหลือ</th><th style="width:19.0%">อาการ</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($orphan as $i => $r): ?>
      <tr>
        <td class="c"><?php echo $i + 1; ?></td>
        <td class="l mono"><?php echo e($r['mat_code']); ?></td>
        <td class="l"><?php echo e($r['name']); ?></td>
        <td class="c"><?php echo e($r['unit']); ?></td>
        <td class="r"><?php echo fmtQ($r['qty_in']); ?></td>
        <td class="r"><?php echo fmtQ($r['qty_out']); ?></td>
        <td class="r"><?php echo fmtQ($r['on_hand']); ?></td>
        <td class="l"><?php
          $sym = [];
          if ($r['has_balance']) { $sym[] = 'มียอดคงเหลือ'; }
          if ($r['has_form'])    { $sym[] = 'อยู่ในฟอร์มเบิก'; }
          echo e(implode(' + ', $sym));
        ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<div class="gap"></div>
<?php endif; ?>


<table>
  <thead>
    <tr class="title-row"><td colspan="10"><?php echo e($titleTxt); ?></td></tr>
    <tr class="sub-row"><td colspan="10">
      <?php echo e($siteTxt); ?> &nbsp;·&nbsp; ณ <?php echo e($stamp); ?> น.
      &nbsp;·&nbsp; ทุกจำนวนเป็นหน่วยซื้อ · ช่องกรอบหนา = คิดกลับจากยอดใต้ IC ด้วยอัตราถัวเฉลี่ยถ่วงน้ำหนัก — เอาไปคีย์ ERP
      &nbsp;·&nbsp; ค้าง buffer + ตัดเบิกแล้ว + คงเหลือ = รับเข้า ทุกแถว
    </td></tr>
    <tr>
      <th style="width:3.4%">ลำดับ</th><th style="width:12.4%">รหัส Mango</th>
      <th style="width:19.0%">ชื่อวัสดุ</th><th style="width:5.2%">หน่วยซื้อ</th>
      <th style="width:6.6%">รับเข้า</th>
      <th style="width:6.8%">ค้าง buffer</th>
      <th style="width:7.4%">ตัดเบิกแล้ว</th><th style="width:7.4%">คงเหลือ</th>
      <th style="width:22.0%">IC ที่ได้ · อัตราแปลง</th>
      <th style="width:9.8%">ใบสั่งซื้อ</th>
    </tr>
  </thead>
  <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="10" class="c">— ไม่มีรายการ —</td></tr>
    <?php else: foreach ($rows as $i => $r): ?>
      <tr>
        <td class="c"><?php echo $i + 1; ?></td>
        <td class="l mono"><?php echo e($r['mat_code']); ?></td>
        <td class="l"><?php echo e($r['mat_name']); ?></td>
        <td class="c"><?php echo e($r['unit_buy']); ?></td>
        <td class="r conv"><?php echo fmtQ($r['qty_recv']); ?></td>
        <td class="r <?php echo $r['qty_left'] > 0 ? 'left' : 'zero'; ?>"><?php echo fmtQ($r['qty_left']); ?></td>
        <td class="r erp"><?php echo fmtQ($r['qty_issued_buy']); ?></td>
        <td class="r erp"><?php echo fmtQ($r['qty_onhand_buy']); ?>
          <?php if (abs($r['gap']) > 0.0001): ?>
            <div class="gap-warn">ต่าง <?php echo fmtQ($r['gap']); ?></div>
          <?php endif; ?></td>
        <td class="l ic">
          <?php if (!$r['ics']): ?>
            <span class="zero">— <?php echo e(mbStatusText($r)); ?> —</span>
          <?php else: foreach ($r['ics'] as $ic): ?>
            <div>
              <span class="cd"><?php echo e($ic['ic_code']); ?></span>
              <?php if ($ic['cancelled']): ?>
                <span class="tag">(ใบถูกยกเลิก)</span>
              <?php else: ?>
                <span class="nm">— รับ <?php echo fmtQ($ic['qty_store']); ?> <?php echo e($ic['unit_store']); ?></span>
                <?php if ($ic['split']): ?><span class="tag">⚖ แตกชุด</span><?php endif; ?>
                <?php if ($ic['rate'] > 0): ?>
                  <div class="rate">1 <?php echo e($ic['unit_store']); ?> = <?php echo fmtQ($ic['rate']); ?>
                    <?php echo e($r['unit_buy']); ?><?php if ($ic['issued_ic'] > 0): ?>
                    · เบิก <?php echo fmtQ($ic['issued_ic']); ?> <?php echo e($ic['unit_store']); ?>
                    → <?php echo fmtQ($ic['issued_buy']); ?> <?php echo e($r['unit_buy']); ?><?php endif; ?></div>
                <?php endif; ?>
              <?php endif; ?>
              <?php if ($ic['ic_name'] !== ''): ?>
                <div class="nm"><?php echo e($ic['ic_name']); ?></div>
              <?php endif; ?>
            </div>
          <?php endforeach; endif; ?>
        </td>
        <td class="l po"><?php echo e($r['po_nos']); ?></td>
      </tr>
    <?php endforeach; endif; ?>
  </tbody>
  <?php if ($rows): ?>
  <tfoot>
    <?php /* ไม่รวมจำนวนของ — แต่ละแถวคนละหน่วยซื้อได้ บวกกันแล้วไม่มีความหมาย */ ?>
    <tr><td class="l" colspan="10">
      รวม <?php echo number_format($tot['n']); ?> รหัส
      &nbsp;·&nbsp; แปลงแล้ว <?php echo number_format($tot['conv_n']); ?> รหัส
      (ได้ <?php echo number_format($tot['ic']); ?> รหัส IC)
      <?php if ($tot['left_n'] > 0): ?>
        &nbsp;·&nbsp; ยังค้างใน buffer <?php echo number_format($tot['left_n']); ?> รหัส
      <?php endif; ?>
      <?php if ($tot['issued_n'] > 0): ?>
        &nbsp;·&nbsp; มียอดตัดเบิก <?php echo number_format($tot['issued_n']); ?> รหัส
      <?php endif; ?>
      <?php if ($tot['split_n'] > 0): ?>
        &nbsp;·&nbsp; ⚖ เฉลี่ยจากการแตกชุด <?php echo number_format($tot['split_n']); ?> รหัส
      <?php endif; ?>
    </td></tr>
  </tfoot>
  <?php endif; ?>
</table>

<?php if ($adjust): ?>
<div class="gap"></div>


<table>
  <thead>
    <tr class="title-row"><td colspan="8">ปรับยอด — แปลงกลับเป็นรหัส Mango ไม่ได้ (<?php echo count($adjust); ?> รหัส IC)</td></tr>
    <tr class="sub-row"><td colspan="8">
      ของก้อนนี้เข้าคลังโดยไม่ผ่านสายใบสั่งซื้อ (ฟอร์ม "รับเข้าคลัง") จึงไม่มีทั้งรหัส Mango ต้นทางและอัตราแปลง
      &nbsp;·&nbsp; <b>เอาไปคีย์ ERP ไม่ได้</b> และตั้งใจกันออกจากยอดตารางบน เพราะถ้าเหมารวมยอดจะเกินของที่ซื้อจริง
    </td></tr>
    <tr>
      <th style="width:4.0%">ลำดับ</th><th style="width:17.0%">รหัส IC</th>
      <th style="width:29.0%">ชื่อวัสดุ</th><th style="width:6.0%">หน่วยเก็บ</th>
      <th style="width:11.0%">รับเข้าทั้งหมด</th><th style="width:11.0%">มาจากใบสั่งซื้อ</th>
      <th style="width:11.0%">ปรับยอด</th><th style="width:11.0%">ตัดเบิกส่วนนี้</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($adjust as $i => $r): ?>
      <tr>
        <td class="c"><?php echo $i + 1; ?></td>
        <td class="l mono"><?php echo e($r['ic_code']); ?></td>
        <td class="l"><?php echo e($r['name']); ?></td>
        <td class="c"><?php echo e($r['unit']); ?></td>
        <td class="r"><?php echo fmtQ($r['qty_in']); ?></td>
        <td class="r"><?php echo fmtQ($r['base_store']); ?></td>
        <td class="r left"><?php echo fmtQ($r['adjust']); ?></td>
        <td class="r"><?php echo fmtQ($r['issued']); ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<div class="note">
  <b>ช่อง "ตัดเบิกแล้ว" และ "คงเหลือ" คิดกลับจากยอดใต้รหัส IC ด้วยอัตราถัวเฉลี่ยถ่วงน้ำหนัก</b>
  (Σ หน่วยซื้อที่จ่ายไป ÷ ยอดรับเข้าทั้งหมดของ IC ตัวนั้น) เพราะ IC เดียวกันรับมาได้หลายอัตรา
  และรับมาจากหลายรหัส Mango ได้ — หน่วยเก็บเป็นค่าที่คนกรอกมือ ไม่มีตารางตัวคูณตายตัว (มติ 9)
  &nbsp;·&nbsp; ป้าย ⚖ แตกชุด = push เดียวแตกหลาย IC (มติ 10) หน่วยซื้อของตัวนั้นเฉลี่ยตามสัดส่วนหน่วยเก็บ
  ผลรวมยังปิดพอดี แต่ตัวเลขรายตัวเป็นค่าประมาณ
  &nbsp;·&nbsp; ใบที่ถูกยกเลิกยังโชว์คู่รหัสไว้ แต่ของเด้งกลับไปรออยู่ใน buffer แล้ว จำนวนจึงเป็น 0
</div>

</body>
</html>
