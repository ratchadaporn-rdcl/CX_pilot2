<?php
/**
 * CONNEXT — pdf/templates/borrow_loss.php : รายงานอุปกรณ์ชำรุด/สูญหาย ต่อใบยืม (A4 แนวตั้ง)
 * [PHP port 2026-09-29 · Scenario 05 ③ ขั้น 6] — rpc_generateBorrowLossPDF (lib/borrow.php)
 * ระบบออกรายงานเท่านั้น ไม่สร้างรายการหักเงิน — ผู้เกี่ยวข้องตัดสินใจว่าจะหักผู้เบิก หักผู้รับเหมา หรือลงงบโครงการ
 *
 * vars: $siteHeader, $docNo, $meta[[k,v]...],
 *       $sumRows[{no,matCode,name,unit,borrowed,returned,damaged,lost,left,reason}],
 *       $logRows[{at,matCode,name,qty,kind,price,src,value,reason,by}], $totalText,
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
*{box-sizing:border-box}html{margin:0;padding:0}body{margin:12mm 12mm;padding:0;font-family:"sarabun",sans-serif;color:#000000;font-size:13px;line-height:1.35}
.site-band{text-align:center;font-size:12px;padding:6px 8px;border-bottom:1px solid #000000}
.doc-label{text-align:center;font-size:21px;font-weight:bold;margin-top:8px}
.doc-no{text-align:center;font-size:18px;font-weight:bold;margin-bottom:10px;color:#000000}
table.meta{width:100%;border-collapse:collapse;margin-bottom:12px}
table.meta td{border:1px solid #999999;padding:5px 8px;font-size:13px;vertical-align:middle}
table.meta td.k{font-weight:bold;width:140px}
.sec-title{background:#f0f0f0;text-align:center;font-weight:bold;font-size:13.5px;padding:6px 8px;border:1px solid #737373;border-bottom:none;margin-top:10px}
table.grid{width:100%;border-collapse:collapse;table-layout:fixed}
table.grid th{color:#000000;font-weight:bold;font-size:12px;padding:5px 4px;border:1px solid #000000}
table.grid td{border:1px solid #737373;padding:4px 5px;font-size:12px;vertical-align:middle;word-wrap:break-word}
.t-c{text-align:center}.t-l{text-align:left}.t-r{text-align:right}
td.code{font-size:10.5px}td.small{font-size:10.5px}
td.bad{color:#000000;font-weight:bold}
.total{margin-top:6px;text-align:right;font-size:14px;font-weight:bold;color:#000000}
.note{margin-top:12px;border:1px solid #000000;color:#000000;padding:8px 10px;font-size:12.5px;line-height:1.5}
table.decide{width:100%;border-collapse:collapse;margin-top:12px}
table.decide td{padding:6px 4px;font-size:13px;vertical-align:top}
.box{display:inline-block;width:12px;height:12px;border:1.3px solid #000000;margin-right:6px;vertical-align:-1px}
table.sign{width:100%;border-collapse:collapse;margin-top:26px}
table.sign td{width:33%;text-align:center;font-size:12.5px;padding:0 6px;vertical-align:top}
.sign-line{border-top:1px dotted #000000;margin:0 14px 4px;height:1px}
table.photos{width:100%;border-collapse:collapse;margin-top:14px}
table.photos th.photos-title{background:#f0f0f0;text-align:center;font-weight:bold;font-size:13px;padding:6px 8px;border:none}
table.photos td{width:50%;padding:6px;vertical-align:top;text-align:center}
.photo-label{font-weight:bold;font-size:11.5px;margin-bottom:5px}.photo-img{max-width:300px;max-height:280px}
.photo-note{font-size:10px;font-style:italic;color:#333333;word-wrap:break-word;text-align:left}
.foot{margin-top:16px;text-align:center;font-size:10px;font-style:italic;color:#808080}
</style>
</head>
<body>

  <div class="site-band"><?php echo e($siteHeader); ?></div>
  <div class="doc-label">รายงานอุปกรณ์ชำรุด/สูญหาย</div>
  <div class="doc-no">ใบยืม <?php echo e($docNo); ?></div>

  <table class="meta">
    <?php foreach ($meta as $pair): ?>
    <tr><td class="k"><?php echo e($pair[0]); ?></td><td><?php echo e($pair[1]); ?></td></tr>
    <?php endforeach; ?>
  </table>

  <div class="sec-title">สรุปรายการของใบ (ยืมจริง · คืน · ชำรุด · สูญหาย · ค้าง)</div>
  <table class="grid">
    <thead>
      <tr><th style="width:4%">#</th><th style="width:23%">รหัส</th><th style="width:21%">รายการ</th>
          <th style="width:6%">หน่วย</th><th style="width:6%">ยืม</th><th style="width:6%">คืน</th>
          <th style="width:7%">ชำรุด</th><th style="width:8%">สูญหาย</th><th style="width:6%">ค้าง</th><th style="width:13%">เหตุผลตอนคืน</th></tr>
    </thead>
    <tbody>
      <?php foreach ($sumRows as $r): ?>
      <tr>
        <td class="t-c"><?php echo (int)$r['no']; ?></td>
        <td class="t-l code"><?php echo e($r['matCode']); ?></td>
        <td class="t-l"><?php echo e($r['name']); ?></td>
        <td class="t-c"><?php echo e($r['unit']); ?></td>
        <td class="t-c"><?php echo e($r['borrowed']); ?></td>
        <td class="t-c"><?php echo e($r['returned']); ?></td>
        <td class="t-c<?php echo $r['damaged'] !== '-' ? ' bad' : ''; ?>"><?php echo e($r['damaged']); ?></td>
        <td class="t-c<?php echo $r['lost'] !== '-' ? ' bad' : ''; ?>"><?php echo e($r['lost']); ?></td>
        <td class="t-c"><?php echo e($r['left']); ?></td>
        <td class="t-l small"><?php echo e($r['reason']); ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="sec-title">รายการที่ตีเป็นชำรุด/สูญหาย (มูลค่าอ้างอิง)</div>
  <table class="grid">
    <thead>
      <tr><th style="width:12%">วันเวลา</th><th style="width:24%">รายการ</th><th style="width:8%">จำนวน</th>
          <th style="width:8%">ประเภท</th><th style="width:11%">มูลค่า/หน่วย</th><th style="width:10%">มูลค่ารวม</th>
          <th style="width:13%">เหตุผล</th><th style="width:14%">ผู้ตี</th></tr>
    </thead>
    <tbody>
      <?php foreach ($logRows as $r): ?>
      <tr>
        <td class="t-c small"><?php echo e($r['at']); ?></td>
        <td class="t-l"><span class="code" style="font-size:10.5px"><?php echo e($r['matCode']); ?></span><br><?php echo e($r['name']); ?></td>
        <td class="t-c"><?php echo e($r['qty']); ?></td>
        <td class="t-c bad"><?php echo e($r['kind']); ?></td>
        <td class="t-r"><?php echo e($r['price']); ?><?php if ($r['src'] !== ''): ?><br><span style="font-size:9.5px;color:#595959">(<?php echo e($r['src']); ?>)</span><?php endif; ?></td>
        <td class="t-r"><?php echo e($r['value']); ?></td>
        <td class="t-l small"><?php echo e($r['reason']); ?></td>
        <td class="t-l small"><?php echo e($r['by']); ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="total">มูลค่าอ้างอิงรวม <?php echo e($totalText); ?></div>

  <div class="note">
    <b>ระบบไม่สร้างรายการหักเงินอัตโนมัติ</b> — ผู้เกี่ยวข้องต้องตัดสินใจก่อนว่าจะหักผู้เบิก หักผู้รับเหมา หรือลงงบโครงการ ·
    ถ้าตัดสินใจหักผู้รับเหมา ธุรการนำยอดจากรายงานนี้ไปใส่ในแบบฟอร์มหักคจช. ของงวดนั้นเอง ·
    มูลค่าอ้างอิงมาจาก rate card ของไซต์ ณ วันที่ตี (ถ้ายังไม่ตั้งราคา สายสโตร์กรอกตอนตี หรือยังไม่มีราคา)
  </div>

  <table class="decide">
    <tr>
      <td><span class="box"></span>หักผู้เบิก</td>
      <td><span class="box"></span>หักผู้รับเหมา (ใส่ในแบบฟอร์มหักคจช.)</td>
      <td><span class="box"></span>ลงงบโครงการ</td>
      <td><span class="box"></span>อื่น ๆ ...............................</td>
    </tr>
  </table>

  <table class="sign">
    <tr>
      <td><div class="sign-line"></div>ผู้ตีชำรุด/สูญหาย (สายสโตร์)</td>
      <td><div class="sign-line"></div>ผู้เบิก (ผู้ยืม)</td>
      <td><div class="sign-line"></div>ผู้จัดการโครงการ</td>
    </tr>
  </table>

  <?php if (!empty($photoRows)): ?>
  <table class="photos">
    <thead><tr><th class="photos-title" colspan="2">รูปประกอบ</th></tr></thead>
    <?php foreach ($photoRows as $row): ?>
    <tr>
      <?php foreach ($row as $cell): ?>
      <td>
        <div class="photo-label"><?php echo e($cell['label']); ?></div>
        <?php if (!empty($cell['src'])): ?>
          <img class="photo-img" src="<?php echo e($cell['src']); ?>" alt="">
        <?php else: ?>
          <div class="photo-note"><?php echo e((string)$cell['note']); ?></div>
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
