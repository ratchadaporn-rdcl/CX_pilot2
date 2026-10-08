<?php
/**
 * CONNEXT — rc.php : ของที่นอนใน buffer → กำหนด IC → ออกใบ IN เข้า gate
 *
 * เฟส 3 ของสาย PO → OCR → buffer → IcCode (db/design_po_ocr_ic_v1.md)
 *   มติ 7  — buffer ถือของที่ยังไม่มี IC ได้ · IC บังคับตอนจะเข้า gate
 *   มติ 9  — buffer = หน่วยซื้อ · IC = หน่วยเก็บ · จำนวนหน่วยเก็บกรอกเอง
 *   มติ 10 — 1 บรรทัด buffer แตกได้หลาย IC
 *   มติ 11 — ตัดยอดด้วย "ใช้หน่วยซื้อไปเท่าไหร่" 1 ค่าต่อการ push
 *   มติ 5  — ออกใบ IN ด้วยกลไกเดิม + ธง origin_type/origin_ref
 *   มติ 20 — เสนอ IC จากที่เคยผูกไว้ (ไม่ใช่ AI เดา)
 *   มติ 23 — ใบ IN ไม่ผูกประตู · QR ใช้เข้าประตูไหนก็ได้ (ไม่มีช่องเลือกประตูแล้ว)
 *
 * จอนี้ทำงานฝั่ง client ทั้งหมดผ่าน api/rc_api.php + api/ic_api.php
 * (ลากบรรทัดลงตะกร้า → แตกเป็น IC → กดออกใบครั้งเดียวได้หลายบรรทัด)
 *
 * PHP 7.4-compatible เท่านั้น
 */

require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/po.php';

$user = uiGuard();

$projId = (int)($_GET['proj'] ?? ($user['projectId'] ?? 0));
$q      = trim((string)($_GET['q'] ?? ''));
$preset = (int)($_GET['b'] ?? 0);   // ลิงก์เก่า ?b= → ใส่ลงตะกร้าให้เลย

$projects = $pdo->query("SELECT id, code, name FROM projects WHERE status='active' ORDER BY code")->fetchAll();

uiHead('รับของ / buffer', 'ลากของที่รับเข้ามาลงตะกร้า แล้วกำหนดรหัส IC ก่อนออกใบเข้า gate', $user, '📦');
?>

<style>
.ccbox{margin-top:13px;background:#f8fafc;border:1px solid var(--line);border-radius:10px;padding:10px 12px}.ccrow{display:flex;flex-wrap:wrap;gap:9px;align-items:center}.ccrow+.ccrow{margin-top:9px;padding-top:9px;border-top:1px dashed var(--line)}.cclab{font-size:.78rem;font-weight:600;color:var(--muted)}.ccbox select{min-width:190px}.drag-row{cursor:grab}.drag-row:active{cursor:grabbing}.drag-row.taken td{opacity:.42}.drag-row.dragging td{opacity:.35}.grip{color:#cbd5e1;font-size:1rem;letter-spacing:-2px;user-select:none}.drag-row:hover .grip{color:var(--navy-2)}.basket{min-height:120px;border:2px dashed var(--line);border-radius:12px;padding:12px;background:#fbfcfe;display:flex;flex-direction:column;gap:11px;transition:background .12s,border-color .12s}.basket.over{border-color:var(--navy-2);background:var(--blue-bg)}.basket .empty{margin:auto;text-align:center;color:var(--muted);font-size:.84rem;line-height:1.9;padding:16px 8px}.basket .empty b{display:block;font-size:.95rem;color:var(--navy);margin-bottom:2px}.bcard{border:1px solid var(--line);border-radius:11px;background:#fff;padding:11px 12px}.bcard .hd{display:flex;align-items:flex-start;gap:9px;margin-bottom:9px}.bcard .hd .nm{font-weight:700;font-size:.86rem;line-height:1.35}.bcard .hd .meta{font-size:.72rem;color:var(--muted);margin-top:2px}.bcard .hd .x{margin-left:auto;background:#fff;color:var(--red);border:1px solid var(--line);border-radius:7px;width:26px;height:26px;padding:0;font-size:.9rem;flex:0 0 auto;line-height:1}.bcard .use{display:flex;align-items:center;gap:7px;flex-wrap:wrap;background:var(--gold-soft);border-radius:8px;padding:7px 9px;margin-bottom:9px;font-size:.78rem}.bcard .use input{width:96px;text-align:right}.alloc{display:flex;align-items:center;gap:7px;margin-bottom:6px}.slot{flex:1;min-width:0;border:1.5px dashed var(--line);border-radius:9px;padding:7px 10px;background:#fbfcfe;font-size:.8rem;color:var(--muted);cursor:pointer;text-align:left;font-family:inherit;display:flex;align-items:center;gap:8px;min-height:40px;transition:border-color .13s,background .13s,color .13s}.slot:hover{border-color:var(--navy-2);color:var(--navy);background:#fff}.slot:focus-visible{outline:2px solid var(--navy-2);outline-offset:2px}.slot.filled{border-style:solid;border-color:#bbf7d0;background:var(--green-bg);color:var(--text)}.slot .c{font-family:var(--icp-mono);font-weight:700;font-size:.72rem;color:var(--navy)}.slot .n{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.slot .kb{margin-left:auto;font-size:.72rem;color:var(--muted);flex:0 0 auto}.slot.justset{animation:icp-settle .6s cubic-bezier(.16,1,.3,1)}@keyframes icp-settle{0%{background:var(--gold-soft);border-color:var(--gold)}100%{background:var(--green-bg);border-color:#bbf7d0}}.alloc input.q{width:92px;text-align:right}.alloc .rm{background:#fff;color:var(--muted);border:1px solid var(--line);border-radius:7px;width:26px;height:26px;padding:0;flex:0 0 auto;line-height:1}:root{--icp-mono:ui-monospace,"Cascadia Mono",Consolas,monospace}.icp-scrim{position:fixed;inset:0;z-index:80;display:flex;align-items:center;justify-content:center;padding:22px;background:rgba(15,23,42,.55);opacity:0;visibility:hidden;transition:opacity .18s ease,visibility .18s ease}.icp-scrim.on{opacity:1;visibility:visible}.icp{width:min(1060px,100%);height:min(716px,90dvh);max-height:100%;background:#fff;border-radius:14px;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 26px 64px -14px rgba(15,23,42,.5),0 6px 16px -4px rgba(15,23,42,.22);opacity:0;transform:translateY(16px) scale(.985);transition:opacity .2s ease,transform .3s cubic-bezier(.16,1,.3,1)}.icp-scrim.on .icp{opacity:1;transform:none}.icp-hd{background:var(--navy);color:#fff;padding:14px 18px;display:flex;align-items:flex-start;gap:14px;flex:0 0 auto}.icp-hd h2{margin:0;font-size:1.12rem;font-weight:400;line-height:1.4;color:#a8bad6}.icp-hd h2 b{color:#fff;font-weight:700}.icp-hd .meta{margin-top:4px;font-size:.72rem;color:#93a6c6;font-family:var(--icp-mono);letter-spacing:.01em}.icp-hd .meta .th{font-family:"Sarabun",system-ui,sans-serif}.icp-hd .rt{margin-left:auto;display:flex;align-items:center;gap:9px;flex:0 0 auto}.icp-hd .of{background:rgba(255,255,255,.13);border-radius:999px;padding:4px 11px;font-size:.72rem;color:#dbe4f2;white-space:nowrap}.icp-x{background:transparent;border:1px solid rgba(255,255,255,.24);color:#dbe4f2;border-radius:9px;width:32px;height:32px;display:grid;place-items:center;padding:0}.icp-x:hover{background:rgba(255,255,255,.15);color:#fff}.icp-top{flex:0 0 auto;border-bottom:1px solid var(--line);padding:12px 18px;display:flex;gap:9px;align-items:center;background:#fff}.icp-qwrap{position:relative;flex:1;min-width:0}.icp-qwrap>svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;pointer-events:none}.icp-q{width:100%;height:44px;border:1.5px solid var(--line);border-radius:10px;padding:0 38px;font-size:1rem;background:#fff}.icp-q::placeholder{color:var(--muted)}.icp-q:focus{outline:0;border-color:var(--navy-2);box-shadow:0 0 0 3px rgba(30,58,138,.13)}.icp-clear{position:absolute;right:8px;top:50%;transform:translateY(-50%);border:0;background:#f1f5f9;color:var(--muted);border-radius:7px;width:26px;height:26px;display:none;place-items:center;padding:0}.icp-clear.on{display:grid}.icp-back{background:#fff;color:var(--navy);border:1px solid var(--line);border-radius:9px;padding:10px 13px;font-size:.8rem;font-weight:600;display:none;align-items:center;gap:7px;white-space:nowrap}.icp-back.on{display:inline-flex}.icp-back:hover{border-color:var(--navy-2)}.icp-body{flex:1;min-height:0;overflow:auto}.icp-pane{display:none}.icp-pane.on{display:block;animation:icp-fade .16s ease}@keyframes icp-fade{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:none}}.icp-busy{opacity:.45;pointer-events:none}.icp-sec{padding:13px 18px 5px;font-size:.72rem;font-weight:700;color:var(--muted);display:flex;align-items:center;gap:8px}.icp-sec .dot{width:6px;height:6px;border-radius:50%;background:var(--gold);flex:0 0 auto}.icp-sec .dot.b{background:#a3adbb}.icp-sec .n{color:var(--muted);font-weight:400}.icp-sec.tp{border-top:1px solid var(--line);margin-top:7px}.icp-row{display:flex;align-items:center;gap:11px;padding:9px 18px;cursor:pointer;background:#fff}.icp-row.cur{background:var(--blue-bg)}.icp-row .c{font-family:var(--icp-mono);font-size:.72rem;font-weight:700;color:var(--navy-2);background:#f1f5f9;border-radius:6px;padding:3px 7px;flex:0 0 auto}.icp-row.cur .c{background:#fff}.icp-row .n{flex:1;min-width:0;font-size:.88rem}.icp-row .n>span{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.icp-row .n em{font-style:normal;background:var(--gold-soft);border-radius:3px;padding:0 2px}.icp-row .sub{font-size:.72rem;color:var(--muted);margin-top:1px}.icp-row.cur .sub{color:#3b5a8f}.icp-row .u{flex:0 0 auto;font-size:.72rem;color:var(--muted);background:#f8fafc;border:1px solid var(--line);border-radius:999px;padding:2px 9px}.icp-row .go{flex:0 0 auto;font-size:.72rem;font-weight:600;color:var(--navy-2);display:flex;align-items:center;gap:4px;opacity:0}.icp-row.cur .go,.icp-row:hover .go{opacity:1}.icp-empty{padding:46px 24px;text-align:center;color:var(--muted);font-size:.8rem;line-height:1.8}.icp-empty b{display:block;color:var(--text);font-size:1rem;margin-bottom:5px}.icp-empty .cta{margin-top:16px}.icp-primary{background:var(--navy);color:#fff;border:0;border-radius:9px;padding:10px 17px;font-size:.8rem;font-weight:700;display:inline-flex;align-items:center;gap:8px}.icp-primary:hover{background:#1d3a68}.icp-primary:disabled{background:#cbd5e1;color:#f8fafc;cursor:not-allowed}.icp-make{display:flex;height:100%;min-height:0}.icp-rail{width:248px;flex:0 0 248px;border-right:1px solid var(--line);background:#fbfcfe;padding:9px;overflow:auto}.icp-step{width:100%;text-align:left;background:transparent;color:var(--text);border:1px solid transparent;border-radius:9px;padding:8px 10px;display:flex;gap:9px;align-items:flex-start;margin-bottom:2px;font-family:inherit}.icp-step:hover{background:#fff;border-color:var(--line)}.icp-step.on{background:#fff;border-color:var(--line);box-shadow:0 1px 3px rgba(15,23,42,.07)}.icp-step[disabled]{opacity:.5;cursor:not-allowed}.icp-step .k{width:20px;height:20px;border-radius:6px;background:#e2e8f0;color:var(--muted);font-size:.72rem;font-weight:700;display:grid;place-items:center;flex:0 0 auto;margin-top:1px}.icp-step.done .k{background:var(--green-bg);color:var(--green)}.icp-step.on .k{background:var(--navy);color:#fff}.icp-step .tx{min-width:0;flex:1}.icp-step .t{font-size:.72rem;font-weight:700;color:var(--muted);display:flex;align-items:center;gap:5px}.icp-step .t .req{color:var(--red)}.icp-step .v{font-size:.8rem;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.icp-step .v.none,.icp-step .v.skip{color:var(--muted)}.icp-cc{margin:2px 0 9px 30px;display:flex;flex-wrap:wrap;gap:4px}.icp-cc .pill{font-size:.72rem;background:#eef2f7;color:#475569}.icp-cc .warn{background:var(--red-bg);color:var(--red)}.icp-opt{flex:1;min-width:0;min-height:0;display:flex;flex-direction:column}.icp-opthd{padding:11px 16px;border-bottom:1px solid var(--line);display:flex;gap:9px;align-items:center}.icp-opthd .ttl{font-size:.8rem;font-weight:700;white-space:nowrap}.icp-opthd .cnt{font-size:.72rem;color:var(--muted);white-space:nowrap}.icp-filter{flex:1;min-width:0;height:34px;border:1px solid var(--line);border-radius:8px;padding:0 11px;font-size:.8rem}.icp-filter:focus{outline:0;border-color:var(--navy-2);box-shadow:0 0 0 3px rgba(30,58,138,.12)}.icp-addbtn{border:1px solid var(--line);background:#fff;color:var(--navy-2);border-radius:8px;padding:6px 11px;font-size:.72rem;font-weight:600;display:inline-flex;align-items:center;gap:5px;white-space:nowrap}.icp-addbtn:hover{border-color:var(--navy-2)}.icp-addbtn[disabled]{color:#a3adbb;cursor:not-allowed}.icp-addrow{display:none;gap:6px;padding:9px 16px;background:var(--gold-soft);border-bottom:1px solid #f0d89f}.icp-addrow.on{display:flex}.icp-addrow input{flex:1;min-width:0;height:34px;border:1px solid #e4cd93;border-radius:8px;padding:0 10px;background:#fff}.icp-list{flex:1;min-height:0;overflow:auto;padding:4px 0 10px}.icp-o{display:flex;align-items:center;gap:10px;padding:8px 16px;cursor:pointer}.icp-o.cur{background:var(--blue-bg)}.icp-o .c{font-family:var(--icp-mono);font-size:.72rem;color:var(--navy-2);background:#f1f5f9;border-radius:5px;padding:2px 6px;flex:0 0 auto}.icp-o.cur .c{background:#fff}.icp-o .n{flex:1;min-width:0;font-size:.88rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.icp-o .n em{font-style:normal;background:var(--gold-soft);border-radius:3px;padding:0 2px}.icp-o.pick .n{font-weight:700}.icp-o .tick{flex:0 0 auto;color:var(--green);opacity:0}.icp-o.pick .tick{opacity:1}.icp-o.skip .n{color:var(--muted)}.icp-note{margin:12px 16px;padding:11px 13px;border-radius:9px;font-size:.8rem;line-height:1.65}.icp-note.info{background:#f8fafc;border:1px solid var(--line);color:var(--muted)}.icp-note.warn{background:var(--red-bg);border:1px solid #fca5a5;color:#7f1d1d}.icp-note.good{background:var(--green-bg);border:1px solid #86efac;color:#14532d}.icp-ccset{display:flex;flex-wrap:wrap;gap:7px;margin-top:10px}.icp-ccset select{height:32px;border:1px solid var(--line);border-radius:8px;padding:0 8px;background:#fff;font-size:.8rem}.icp-ft{flex:0 0 auto;border-top:1px solid var(--line);background:#fff}.icp-seg{display:none;padding:11px 18px 0;align-items:flex-end;gap:16px}.icp-seg.on{display:flex}.icp-segs{display:flex;gap:4px;flex:0 0 auto}.icp-sg{border:0;background:transparent;padding:0;text-align:center;font-family:inherit}.icp-sg b{display:block;font-family:var(--icp-mono);font-size:.8rem;font-weight:700;letter-spacing:.07em;color:var(--navy);background:#eef2f7;border:1px solid var(--line);border-radius:7px;padding:5px 8px;min-width:40px}.icp-sg.empty b{color:var(--muted);background:#fff;border-style:dashed}.icp-sg.on b{border-color:var(--navy-2);box-shadow:0 0 0 2px rgba(30,58,138,.14)}.icp-sg .lb{display:block;font-size:.72rem;color:var(--muted);margin-top:4px;letter-spacing:0}.icp-name{flex:1;min-width:0}.icp-name label{display:block;font-size:.72rem;color:var(--muted);margin-bottom:3px}.icp-name input{width:100%;height:36px;border:1px solid var(--line);border-radius:8px;padding:0 10px;font-size:.88rem}.icp-name input:focus{outline:0;border-color:var(--navy-2);box-shadow:0 0 0 3px rgba(30,58,138,.12)}.icp-bar{padding:11px 18px;display:flex;align-items:center;gap:12px}.icp-kb{font-size:.72rem;color:var(--muted);display:flex;gap:11px;flex-wrap:wrap;flex:0 0 auto}.icp-kb kbd{font-family:var(--icp-mono);font-size:.72rem;background:#f1f5f9;border:1px solid var(--line);border-bottom-width:2px;border-radius:5px;padding:1px 5px;color:var(--muted)}.icp-status{font-size:.72rem;color:var(--muted);flex:1;min-width:0}.icp-status.ok{color:#15803d}.icp-status.bad{color:var(--red)}.icp-acts{margin-left:auto;display:flex;gap:8px;align-items:center;flex:0 0 auto}.icp-2nd{background:#fff;color:var(--navy);border:1px solid var(--line);border-radius:9px;padding:9px 14px;font-size:.8rem;font-weight:600}.icp-2nd:hover{border-color:var(--navy-2)}.icp-2nd:disabled{color:#a3adbb;cursor:not-allowed}@media(max-width:820px){.icp-scrim{padding:0;align-items:flex-end}.icp{height:94dvh;max-height:100%;width:100%;border-radius:16px 16px 0 0;transform:translateY(26px)}.icp-make{flex-direction:column}.icp-rail{width:auto;flex:0 0 auto;display:flex;gap:6px;overflow-x:auto;border-right:0;border-bottom:1px solid var(--line);padding:8px}.icp-step{width:auto;flex:0 0 auto;margin:0}.icp-step .v{max-width:112px}.icp-cc{display:none}.icp-seg{flex-wrap:wrap;gap:10px}.icp-segs{overflow-x:auto}.icp-kb{display:none}}@media(prefers-reduced-motion:reduce){.icp,.icp-scrim,.icp-pane,.slot.justset{transition:none!important;animation:none!important;transform:none!important}}.hist{margin-top:9px;border-top:1px solid var(--line);padding-top:8px}.hist summary{font-size:.75rem;color:var(--muted);font-weight:600}.hist table{margin-top:6px}.hist tr.void td{background:#fef2f2}.hist tr.void .q{text-decoration:line-through;opacity:.6}


































































































































































































































</style>

<div id="notice"></div>


<div class="card">
  <h2><span class="num">1</span> ของค้างใน buffer
    <span class="sp" id="cntLines">กำลังโหลด…</span>
  </h2>

  <form method="get" class="row" style="margin-bottom:11px">
    <div>
      <label class="fld">ไซต์</label>
      <select name="proj" onchange="this.form.submit()">
        <?php foreach ($projects as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $projId ? 'selected' : '' ?>>
            <?= e((string)$p['code']) ?> · <?= e((string)$p['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="flex:1;min-width:200px">
      <label class="fld">ค้นหา (ชื่อ / รหัส / เลขที่ PO / ผู้ขาย)</label>
      <input type="text" name="q" value="<?= e($q) ?>" style="width:100%">
    </div>
    <div style="align-self:flex-end"><button type="submit">ค้นหา</button></div>
  </form>

  <div class="scroll">
    <table>
      <thead>
        <tr><th style="width:22px"></th><th>รหัส / ชื่อ (จากใบ PO)</th><th>ใบคุม</th><th>ผู้ขาย</th>
            <th class="num">รับเข้า</th><th class="num">นำออกแล้ว</th><th class="num">คงเหลือ</th><th></th></tr>
      </thead>
      <tbody id="bufBody">
        <tr><td colspan="8" class="small">กำลังโหลด…</td></tr>
      </tbody>
    </table>
  </div>
</div>


<div class="card">
  <h2><span class="num">2</span> ตะกร้าจัดของเข้า gate
    <span class="sp" id="cntBasket">ยังไม่มีของในตะกร้า</span>
  </h2>

  <div id="basket" class="basket">
    <div class="empty">
      <b>ลากบรรทัดจากตารางด้านบนมาวางที่นี่</b>
      หรือกดปุ่ม “＋ ใส่ตะกร้า” ที่ท้ายแถวก็ได้<br>
      ใส่ได้หลายบรรทัด — ระบบจะออก<b>ใบ IN ใบเดียว</b>ให้ทั้งชุด
    </div>
  </div>

  <!-- [2026-10-02 · GP-10 / OP-70] ของจาก supplier / PO ต้องแนบรูปใบส่งของ (บังคับ) -->
  <style>
  .dn-box{margin-top:12px;border:1px solid var(--line);border-radius:11px;padding:10px 12px;background:#fbfcfe}
  .dn-box.miss{border-color:#fca5a5;background:#fef2f2}
  .dn-hd{font-size:.84rem;font-weight:700;color:var(--navy);display:flex;flex-wrap:wrap;gap:4px 8px;align-items:baseline}
  .dn-hd .dn-req{color:var(--red)}
  .dn-hd .small{font-weight:400}
  .dn-list{display:flex;flex-wrap:wrap;gap:8px;margin-top:9px}
  .dn-th{position:relative;width:86px;height:86px;border-radius:9px;overflow:hidden;border:1px solid var(--line);background:#fff}
  .dn-th img{width:100%;height:100%;object-fit:cover;display:block}
  .dn-th button{position:absolute;top:3px;right:3px;width:24px;height:24px;padding:0;border-radius:6px;border:0;background:rgba(15,23,42,.66);color:#fff;font-size:.85rem;line-height:1}
  .dn-add{display:inline-flex;align-items:center;gap:6px;margin-top:9px;padding:8px 13px;border:1.5px dashed var(--navy-2);border-radius:9px;color:var(--navy-2);font-size:.82rem;font-weight:600;cursor:pointer;background:#fff}
  .dn-add.busy{opacity:.55;pointer-events:none}
  </style>
  <div class="dn-box" id="dnBox">
    <div class="dn-hd">รูปใบส่งของ <span class="dn-req">*</span>
      <span class="small">ของจาก supplier / PO ต้องแนบรูปใบส่งของอย่างน้อย 1 รูป ก่อนออกใบ IN (สูงสุด 6 รูป)</span></div>
    <div class="dn-list" id="dnList"></div>
    <label class="dn-add" id="dnAdd"><input type="file" id="dnFile" accept="image/*" multiple hidden>📷 ถ่าย / เลือกรูปใบส่งของ</label>
  </div>

  <div class="row" style="margin-top:12px;align-items:flex-end">
    <div style="flex:1;min-width:220px">
      <label class="fld">หมายเหตุ (ติดไปกับใบ IN ทั้งใบ)</label>
      <input type="text" id="note" style="width:100%" placeholder="เช่น ของเข้าเที่ยวเช้า รถทะเบียน …">
    </div>
    <div><button type="button" id="btnPush" disabled>นำเข้า gate + ออกใบ IN</button></div>
  </div>

  <p class="small" style="margin-top:9px">
    ใบที่ออกเป็น <span class="mono">IN{DDMMYY}{XX}</span> สถานะ <b>Sent Inbound</b> —
    <b>ไม่ผูกประตู</b> เอา QR ไปสแกนที่ตู้ G ไหน<b>ของไซต์นี้</b>ก็ได้ — ยอดจะขึ้นที่ G ที่สแกน หลัง<b>ถ่ายรูปยืนยัน</b>และปิดประตู
  </p>
</div>






<div class="icp-scrim" id="icpScrim">
  <div class="icp" role="dialog" aria-modal="true" aria-labelledby="icpTitle" id="icpBox">

    <div class="icp-hd">
      <div style="min-width:0">
        <h2 id="icpTitle">เลือกรหัส IC ให้ <b id="icpMat">—</b></h2>
        <div class="meta" id="icpMeta">—</div>
      </div>
      <div class="rt">
        <span class="of" id="icpOf">บรรทัดที่ 1 จาก 1</span>
        <button type="button" class="icp-x" id="icpClose" aria-label="ปิด">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>
      </div>
    </div>

    <div class="icp-top">
      <button type="button" class="icp-back" id="icpBack">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        กลับไปค้นหา
      </button>
      <div class="icp-qwrap">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
        <input type="text" class="icp-q" id="icpQ" autocomplete="off" spellcheck="false"
               placeholder="พิมพ์ชื่อวัสดุ หรือรหัส IC — ค้นข้ามหมวดได้เลย">
        <button type="button" class="icp-clear" id="icpClear" aria-label="ล้างคำค้น">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>
      </div>
    </div>

    <div class="icp-body">
      <div class="icp-pane on" id="paneFind"><div id="icpResults"></div></div>
      <div class="icp-pane" id="paneMake" style="height:100%">
        <div class="icp-make">
          <div class="icp-rail" id="icpRail"></div>
          <div class="icp-opt">
            <div class="icp-opthd">
              <span class="ttl" id="optTitle">ตัวสินค้า</span>
              <input type="text" class="icp-filter" id="optFilter" placeholder="กรองในขั้นนี้…" autocomplete="off">
              <span class="cnt" id="optCount"></span>
              <button type="button" class="icp-addbtn" id="optAdd">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                เพิ่มใหม่
              </button>
            </div>
            <div class="icp-addrow" id="optAddRow">
              <input type="text" id="optAddName" placeholder="ชื่อใหม่">
              <button type="button" class="icp-primary" id="optAddGo" style="padding:7px 14px">เพิ่ม</button>
              <button type="button" class="icp-2nd" id="optAddCancel">ยกเลิก</button>
            </div>
            <div class="icp-list" id="optList"></div>
          </div>
        </div>
      </div>
    </div>

    <div class="icp-ft">
      <div class="icp-seg" id="icpSeg">
        <div class="icp-segs" id="icpSegs"></div>
        <div class="icp-name">
          <label for="icpName">ชื่อวัสดุที่จะบันทึก (แก้ได้)</label>
          <input type="text" id="icpName" autocomplete="off">
        </div>
      </div>
      <div class="icp-bar">
        <div class="icp-kb">
          <span><kbd>↑</kbd><kbd>↓</kbd> เลื่อน</span>
          <span><kbd>Enter</kbd> เลือก</span>
          <span><kbd>Esc</kbd> ปิด</span>
        </div>
        <div class="icp-status" id="icpStatus"></div>
        <div class="icp-acts">
          <button type="button" class="icp-2nd" id="icpNext" style="display:none">ใช้ แล้วไปบรรทัดถัดไป</button>
          <button type="button" class="icp-primary" id="icpUse" disabled>
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
            <span id="icpUseTx">ใช้รหัสนี้</span>
          </button>
        </div>
      </div>
    </div>

  </div>
</div>

<script>
(function () {
'use strict';

var CSRF    = <?= json_encode(csrfToken()) ?>;
var BASE    = <?= json_encode(APP_BASE) ?>;
var PROJ    = <?= (int)$projId ?>;
var QSTR    = <?= json_encode($q) ?>;
var PRESET  = <?= (int)$preset ?>;
var IS_ADMIN = <?= uiIsAdmin($user) ? 'true' : 'false' ?>;
var NONE    = '000';

 
var lines  = [];     
var basket = [];     
var M = {            
  open: false, mode: 'find', bufId: 0, idx: 0, opener: null,
  res: null, rows: [], cur: -1,
  step: 0, optRows: [], optCur: -1, llpQ: '', nameTouched: false
};
var steps  = null;   
var seq    = 0;      

var $ = function (id) { return document.getElementById(id); };
function esc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
  });
}
function fmtQ(v) {
  var n = Number(v) || 0;
  var s = n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  return s.slice(-3) === '.00' ? s.slice(0, -3) : s;
}
function banner(kind, html) {
  $('notice').innerHTML = '<div class="banner ' + (kind === 'ok' ? 'b-ok' : kind === 'warn' ? 'b-warn' : 'b-bad') + '">'
    + (kind === 'ok' ? '✓ ' : kind === 'warn' ? '! ' : '✕ ') + html + '</div>';
  window.scrollTo({top: 0, behavior: 'smooth'});
}
function clearBanner() { $('notice').innerHTML = ''; }

function api(url, opts) {
  return fetch(url, Object.assign({credentials: 'same-origin'}, opts || {}))
    .then(function (r) { return r.json().catch(function () {
      throw new Error('เซิร์ฟเวอร์ตอบกลับมาไม่ใช่ JSON (HTTP ' + r.status + ') — ลองโหลดหน้าใหม่');
    }); })
    .then(function (j) { if (!j.ok) { throw new Error(j.error || 'ผิดพลาดไม่ทราบสาเหตุ'); } return j; });
}
function form(obj) {
  var b = new URLSearchParams();
  b.set('csrf', CSRF);
  Object.keys(obj).forEach(function (k) { b.set(k, obj[k]); });
  return {method: 'POST', body: b, headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'}};
}

// [2026-10-02 · GP-10] รูปใบส่งของ — ย่อเป็น JPEG ≤ 1600px (ตัวหนังสือยังอ่านได้) แล้วส่งแบบ multipart
var dnPhotos = [];
var dnBusy   = 0;
function formMulti(obj) {
  var fd = new FormData();
  fd.append('csrf', CSRF);
  Object.keys(obj).forEach(function (k) { fd.append(k, obj[k]); });
  return {method: 'POST', body: fd};
}
function shrinkImage(file) {
  return new Promise(function (resolve) {
    if (!file || !/^image\//.test(file.type || '')) { resolve(null); return; }
    var rd = new FileReader();
    rd.onerror = function () { resolve(null); };
    rd.onload = function (e) {
      var img = new Image();
      img.onerror = function () { resolve(null); };
      img.onload = function () {
        var w = img.naturalWidth || img.width, h = img.naturalHeight || img.height;
        if (!w || !h) { resolve(null); return; }
        var r = Math.min(1600 / w, 1600 / h, 1);
        var cv = document.createElement('canvas');
        cv.width = Math.round(w * r); cv.height = Math.round(h * r);
        var cx = cv.getContext('2d');
        cx.fillStyle = '#fff'; cx.fillRect(0, 0, cv.width, cv.height);
        cx.drawImage(img, 0, 0, cv.width, cv.height);
        try { resolve(cv.toDataURL('image/jpeg', 0.8)); } catch (er) { resolve(null); }
      };
      img.src = e.target.result;
    };
    rd.readAsDataURL(file);
  });
}
function renderDn() {
  $('dnList').innerHTML = dnPhotos.map(function (u, i) {
    return '<div class="dn-th"><img src="' + u + '" alt="รูปใบส่งของ ' + (i + 1) + '">'
         + '<button type="button" data-dn-rm="' + i + '" title="ลบรูปนี้">✕</button></div>';
  }).join('');
  $('dnAdd').classList.toggle('busy', dnBusy > 0);
  if (dnPhotos.length) { $('dnBox').classList.remove('miss'); }
}
$('dnFile').addEventListener('change', function () {
  var files = Array.prototype.slice.call(this.files || []);
  this.value = '';
  var room = 6 - dnPhotos.length;
  if (files.length > room) { banner('warn', 'แนบรูปใบส่งของได้สูงสุด 6 รูป — เพิ่มได้อีก ' + Math.max(room, 0) + ' รูป'); files = files.slice(0, Math.max(room, 0)); }
  if (!files.length) { return; }
  dnBusy++;
  renderDn();
  Promise.all(files.map(shrinkImage)).then(function (res) {
    var bad = 0;
    res.forEach(function (u) { if (u) { dnPhotos.push(u); } else { bad++; } });
    if (bad) { banner('bad', 'อ่านรูปไม่ได้ ' + bad + ' ไฟล์ — ใช้ไฟล์รูป JPEG / PNG / WebP'); }
  }).finally(function () { dnBusy--; renderDn(); });
});
$('dnList').addEventListener('click', function (ev) {
  var b = ev.target.closest('[data-dn-rm]');
  if (!b) { return; }
  dnPhotos.splice(parseInt(b.getAttribute('data-dn-rm'), 10), 1);
  renderDn();
});

 
 
 
function inBasket(id) { return basket.some(function (b) { return b.bufId === id; }); }
function lineById(id) {
  for (var i = 0; i < lines.length; i++) { if (lines[i].id === id) return lines[i]; }
  return null;
}

function renderLines() {
  var tb = $('bufBody');
  $('cntLines').textContent = lines.length + ' บรรทัด';
  if (!lines.length) {
    tb.innerHTML = '<tr><td colspan="8" class="small">ไม่มีของค้างใน buffer ของไซต์นี้ — '
      + 'ไปรับของจาก <a href="' + BASE + '/po.php">ใบคุม</a> ก่อน</td></tr>';
    return;
  }
  tb.innerHTML = lines.map(function (r) {
    var taken = inBasket(r.id);
    return '<tr class="drag-row' + (taken ? ' taken' : '') + '" draggable="' + (taken ? 'false' : 'true') + '" data-id="' + r.id + '">'
      + '<td class="grip">⋮⋮</td>'
      + '<td><span class="mono small">' + esc(r.mat_code || '—') + '</span><br>' + esc(r.mat_name) + '</td>'
      + '<td class="small"><span class="mono">' + esc(r.po_no) + '</span> #' + r.line_no + '</td>'
      + '<td class="small">' + esc(r.vendor) + '</td>'
      + '<td class="num">' + fmtQ(r.received) + ' <span class="small">' + esc(r.unit) + '</span></td>'
      + '<td class="num">' + fmtQ(r.consumed) + '</td>'
      + '<td class="num"><b>' + fmtQ(r.remain) + '</b></td>'
      + '<td>' + (taken
          ? '<span class="pill p-info">อยู่ในตะกร้า</span>'
          : '<button class="ghost mini" type="button" data-add-buf="' + r.id + '">＋ ใส่ตะกร้า</button>')
      + '</td></tr>';
  }).join('');
}

function loadLines() {
  return api(BASE + '/api/rc_api.php?a=lines&proj=' + PROJ + '&q=' + encodeURIComponent(QSTR))
    .then(function (j) { lines = j.rows; renderLines(); })
    .catch(function (e) {
      $('bufBody').innerHTML = '<tr><td colspan="8" class="small">โหลดไม่สำเร็จ: ' + esc(e.message) + '</td></tr>';
    });
}

 
 
 
function addToBasket(id) {
  if (inBasket(id)) { return; }
  var r = lineById(id);
  if (!r) { return; }
  if (r.line_kind === 'adjust') {
    banner('bad', 'บรรทัดนี้เป็นรายการเงิน (ค่าส่ง/ส่วนลด) ไม่ใช่ของ — เอาเข้า gate ไม่ได้ (มติ 18)');
    return;
  }
   
   
  var item = {bufId: id, qty: r.remain, allocs: [{ic: '', name: '', unit: '', qty: r.remain}], history: null};
  basket.push(item);
  clearBanner();
  renderBasket();
  renderLines();

   
   
  api(BASE + '/api/rc_api.php?a=line&b=' + id).then(function (j) {
    item.history = j.line.history || [];
    if (inBasket(id)) { renderBasket(); }
  }).catch(function () { item.history = []; });
}
function removeFromBasket(id) {
  basket = basket.filter(function (b) { return b.bufId !== id; });
  if (M.open && M.bufId === id) { closePicker(); }
  renderBasket();
  renderLines();
}
function basketItem(id) {
  for (var i = 0; i < basket.length; i++) { if (basket[i].bufId === id) return basket[i]; }
  return null;
}

function renderBasket() {
  var el = $('basket');
  $('btnPush').disabled = basket.length === 0;
  $('cntBasket').textContent = basket.length
    ? basket.length + ' บรรทัด → ใบ IN 1 ใบ'
    : 'ยังไม่มีของในตะกร้า';

  if (!basket.length) {
    el.innerHTML = '<div class="empty"><b>ลากบรรทัดจากตารางด้านบนมาวางที่นี่</b>'
      + 'หรือกดปุ่ม “＋ ใส่ตะกร้า” ที่ท้ายแถวก็ได้<br>'
      + 'ใส่ได้หลายบรรทัด — ระบบจะออก<b>ใบ IN ใบเดียว</b>ให้ทั้งชุด</div>';
    return;
  }

  el.innerHTML = basket.map(function (b) {
    var r = lineById(b.bufId) || {mat_name: '(ไม่พบ)', unit: '', remain: 0, po_no: '', line_no: 0, suggest: []};
    var allocs = b.allocs.map(function (a, i) {
      var filled = a.ic !== '';
      return '<div class="alloc">'
        + '<button type="button" class="slot' + (filled ? ' filled' : '') + '" data-slot="' + b.bufId + ':' + i + '">'
        + (filled
            ? '<span class="c">' + esc(a.ic) + '</span><span class="n">' + esc(a.name) + '</span>'
              + '<span class="kb">' + esc(a.unit) + ' · เปลี่ยน</span>'
            : '<span>เลือกรหัส IC ให้บรรทัดนี้</span><span class="kb">คลิก</span>')
        + '</button>'
        + '<input type="text" class="q" data-qty="' + b.bufId + ':' + i + '" value="' + esc(a.qty) + '" placeholder="0">'
        + '<button type="button" class="rm" data-rm="' + b.bufId + ':' + i + '" title="ลบบรรทัดนี้">×</button>'
        + '</div>';
    }).join('');

    return '<div class="bcard" data-card="' + b.bufId + '">'
      + '<div class="hd"><div>'
        + '<div class="nm">' + esc(r.mat_name) + '</div>'
        + '<div class="meta"><span class="mono">' + esc(r.mat_code || '—') + '</span> · '
        + '<span class="mono">' + esc(r.po_no) + '</span> #' + r.line_no + ' · ' + esc(r.vendor || '') + '</div>'
      + '</div><button type="button" class="x" data-del="' + b.bufId + '" title="เอาออกจากตะกร้า">×</button></div>'

      + '<div class="use"><b>ใช้หน่วยซื้อไป</b>'
        + '<input type="text" data-use="' + b.bufId + '" value="' + esc(b.qty) + '">'
        + '<span>' + esc(r.unit) + '</span>'
        + '<span class="small">(คงเหลือใน buffer ' + fmtQ(r.remain) + ')</span></div>'

      + '<p class="small" style="margin-bottom:5px">แตกเป็นรหัส IC ได้หลายบรรทัด (มติ 10) — '
        + 'จำนวนที่ใส่คือ<b>หน่วยเก็บของ IC นั้น</b> ไม่ใช่หน่วยซื้อ (มติ 9)</p>'
      + allocs
      + '<button type="button" class="ghost mini" data-more="' + b.bufId + '">＋ เพิ่มบรรทัด IC</button>'
      + histHtml(b)
      + '</div>';
  }).join('');
}

 
function histHtml(b) {
  if (!b.history || !b.history.length) { return ''; }
  var nVoid = b.history.filter(function (h) { return h.cancelled_at; }).length;
  var rows = b.history.map(function (h) {
    var voided = !!h.cancelled_at;
    return '<tr class="' + (voided ? 'void' : '') + '">'
      + '<td class="small">' + esc(String(h.created_at || '').slice(0, 16)) + '</td>'
      + '<td class="num q">' + fmtQ(h.qty_consumed) + '</td>'
      + '<td class="mono small">' + esc(h.doc_no || '—') + '</td>'
      + '<td class="small">' + esc(h.allocs || '') + '</td>'
      + '<td>' + (voided
          ? '<span class="pill p-bad">ยกเลิก · คืนยอดแล้ว</span>'
          : '<span class="pill p-ok">' + esc(h.doc_status || 'ใช้งาน') + '</span>')
      + '</td></tr>';
  }).join('');

  return '<details class="hist"' + (nVoid ? ' open' : '') + '>'
    + '<summary>ประวัติการนำออกของบรรทัดนี้ ' + b.history.length + ' รอบ'
    + (nVoid ? ' · <b style="color:var(--red)">ยกเลิก ' + nVoid + ' รอบ (ยอดคืนกลับ buffer แล้ว)</b>' : '')
    + '</summary>'
    + '<div class="scroll"><table>'
    + '<tr><th>เมื่อ</th><th class="num">ใช้หน่วยซื้อ</th><th>ใบ IN</th><th>แตกเป็น IC</th><th>สถานะใบ</th></tr>'
    + rows + '</table></div></details>';
}

 
 
 
var dragging = false;    

document.addEventListener('dragstart', function (ev) {
  var row = ev.target.closest ? ev.target.closest('.drag-row') : null;
  if (!row || row.getAttribute('draggable') !== 'true') { return; }
  dragging = true;
  ev.dataTransfer.setData('text/plain', 'buf:' + row.dataset.id);
  ev.dataTransfer.effectAllowed = 'copy';
  row.classList.add('dragging');
});
document.addEventListener('dragend', function () {
  dragging = false;
  Array.prototype.forEach.call(document.querySelectorAll('.dragging'), function (n) { n.classList.remove('dragging'); });
  Array.prototype.forEach.call(document.querySelectorAll('.over'), function (n) { n.classList.remove('over'); });
});

 
$('basket').addEventListener('dragover', function (ev) {
  if (!dragging) { return; }
  ev.preventDefault();
  ev.dataTransfer.dropEffect = 'copy';
  this.classList.add('over');
});
$('basket').addEventListener('dragleave', function (ev) {
  if (ev.target === this) { this.classList.remove('over'); }
});
$('basket').addEventListener('drop', function (ev) {
  this.classList.remove('over');
  var d = ev.dataTransfer.getData('text/plain') || '';
  if (d.indexOf('buf:') !== 0) { return; }
  ev.preventDefault();
  addToBasket(parseInt(d.slice(4), 10));
});

function fillSlot(bufId, idx, ic, name, unit) {
  var b = basketItem(bufId);
  if (!b || !b.allocs[idx]) { return; }
  b.allocs[idx].ic   = ic;
  b.allocs[idx].name = name;
  b.allocs[idx].unit = unit;
  clearBanner();
  renderBasket();
  var node = document.querySelector('[data-slot="' + bufId + ':' + idx + '"]');
  if (node) { node.classList.add('justset'); }
}

 
 
 
document.addEventListener('click', function (ev) {
  var t = ev.target;
  var b;

  if ((b = t.closest('[data-add-buf]'))) { addToBasket(parseInt(b.dataset.addBuf, 10)); return; }
  if ((b = t.closest('[data-del]')))     { removeFromBasket(parseInt(b.dataset.del, 10)); return; }

  if ((b = t.closest('[data-more]'))) {
    var it = basketItem(parseInt(b.dataset.more, 10));
    if (it) { it.allocs.push({ic: '', name: '', unit: '', qty: ''}); renderBasket(); }
    return;
  }
  if ((b = t.closest('[data-rm]'))) {
    var ref = b.dataset.rm.split(':');
    var it2 = basketItem(parseInt(ref[0], 10));
    if (it2 && it2.allocs.length > 1) {
      it2.allocs.splice(parseInt(ref[1], 10), 1);
      if (M.open && M.bufId === it2.bufId) { closePicker(); }
      renderBasket();
    }
    return;
  }
  if (t.closest('.hist')) { return; }    
  if ((b = t.closest('.slot'))) {
    var r2 = b.dataset.slot.split(':');
    openPicker(parseInt(r2[0], 10), parseInt(r2[1], 10), b);
    return;
  }
});

 
document.addEventListener('input', function (ev) {
  var t = ev.target;
  if (t.dataset && t.dataset.use) {
    var it = basketItem(parseInt(t.dataset.use, 10));
    if (it) { it.qty = t.value; }
  } else if (t.dataset && t.dataset.qty) {
    var ref = t.dataset.qty.split(':');
    var it2 = basketItem(parseInt(ref[0], 10));
    if (it2 && it2.allocs[ref[1]]) { it2.allocs[ref[1]].qty = t.value; }
  }
});

 
 
 
 
 
 
 
 
 
 
 
 
 
var STEPS = [
  {key: 'l1',    label: 'กลุ่มหลัก',      req: true,  add: false},
  {key: 'l2',    label: 'หมวด',           req: true,  add: false},
  {key: 'llp',   label: 'ตัวสินค้า',      req: true,  add: true },
  {key: 'size',  label: 'ขนาด',           req: false, add: true },
  {key: 'brand', label: 'ยี่ห้อ',          req: false, add: true },
  {key: 'unit',  label: 'หน่วยเก็บ',      req: true,  add: false},
  {key: 'extra', label: 'คุณสมบัติเพิ่ม', req: false, add: true },
  {key: 'done',  label: 'ตรวจ & ออกรหัส', req: false, add: false}
];
var SEG_LABELS = ['กลุ่มหลัก', 'หมวด', 'ตัวสินค้า', 'ขนาด', 'ยี่ห้อ', 'หน่วยเก็บ', 'คุณสมบัติ'];
var DONE_STEP  = 7;

var SVG_GO   = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>';
var SVG_TICK = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';

 
function hi(s, q) {
  s = String(s == null ? '' : s);
  if (!q) { return esc(s); }
  var i = s.toLowerCase().indexOf(q.toLowerCase());
  if (i < 0) { return esc(s); }
  return esc(s.slice(0, i)) + '<em>' + esc(s.slice(i, i + q.length)) + '</em>' + esc(s.slice(i + q.length));
}
function status(kind, html) {
  var el = $('icpStatus');
  el.className = 'icp-status' + (kind ? ' ' + kind : '');
  el.innerHTML = html || '';
}

 
function openPicker(bufId, idx, opener) {
  var r = lineById(bufId), it = basketItem(bufId);
  if (!r || !it || !it.allocs[idx]) { return; }

  M.open = true; M.bufId = bufId; M.idx = idx; M.opener = opener || null;
  M.mode = 'find'; M.cur = -1; M.res = null; M.step = 0; M.optCur = -1;
  M.llpQ = ''; M.nameTouched = false;
  steps = null;

  $('icpMat').textContent = r.mat_name;
  $('icpMeta').innerHTML = esc(r.mat_code || '—') + ' · ' + esc(r.po_no) + ' #' + r.line_no
    + ' · <span class="th">' + esc(r.vendor || '') + ' · คงเหลือ ' + fmtQ(r.remain) + ' ' + esc(r.unit) + '</span>';
  $('icpOf').textContent = 'บรรทัดที่ ' + (idx + 1) + ' จาก ' + it.allocs.length;
  $('icpQ').value = '';
  $('icpName').value = '';
  $('icpClear').classList.remove('on');

  setMode('find');
  runSearch('');
  $('icpScrim').classList.add('on');
  document.body.style.overflow = 'hidden';
  setTimeout(function () { $('icpQ').focus(); }, 40);
}

function closePicker() {
  if (!M.open) { return; }
  M.open = false;
  $('icpScrim').classList.remove('on');
  document.body.style.overflow = '';
  if (M.opener && document.body.contains(M.opener)) { try { M.opener.focus(); } catch (e) {} }
}

function setMode(mode) {
  M.mode = mode;
  $('paneFind').classList.toggle('on', mode === 'find');
  $('paneMake').classList.toggle('on', mode === 'make');
  $('icpBack').classList.toggle('on', mode === 'make');
  $('icpSeg').classList.toggle('on', mode === 'make');
  $('icpQ').placeholder = mode === 'find'
    ? 'พิมพ์ชื่อวัสดุ หรือรหัส IC — ค้นข้ามหมวดได้เลย'
    : 'พิมพ์เพื่อกลับไปค้นรหัสที่มีอยู่';
  if (mode === 'find') { paintResults(); } else { paintMake(); }
}

 
 
 
var searchTimer = null;
function runSearch(q) {
  var my = ++seq;
  return api(BASE + '/api/ic_api.php?a=search&q=' + encodeURIComponent(q))
    .then(function (j) {
      if (my !== seq || !M.open) { return; }
      M.res = j;
      M.cur = -1;
      paintResults();
    })
    .catch(function (e) {
      if (my !== seq) { return; }
      M.res = {rows: [], llp: [], llp_more: 0, err: e.message};
      paintResults();
    });
}

function buildRows() {
  var q   = $('icpQ').value.trim();
  var ql  = q.toLowerCase();
  var res = M.res || {rows: [], llp: [], llp_more: 0};
  var r   = lineById(M.bufId) || {suggest: []};
  var out = [], seen = {}, i;

   
  var sug = [];
  for (i = 0; i < (r.suggest || []).length; i++) {
    var s = r.suggest[i];
    if (q && String(s.ic_code).toLowerCase().indexOf(ql) < 0
          && String(s.ic_name).toLowerCase().indexOf(ql) < 0) { continue; }
    seen[s.ic_code] = 1;
    sug.push({kind: 'ic', code: s.ic_code, name: s.ic_name, unit: s.unit_name,
      sub: 'เคยใช้กับของชิ้นนี้ ' + s.hit_count + ' ครั้ง'
         + (Number(s.same_vendor) ? ' · ผู้ขายรายนี้' : ' · ผู้ขายรายอื่น')
         + (s.last_used ? ' · ล่าสุด ' + String(s.last_used).slice(0, 10) : '')});
  }
  if (sug.length) {
    out.push({sec: 'เคยผูกกับของชิ้นนี้', gold: true});
    out = out.concat(sug);
  }

   
  var ics = [];
  for (i = 0; i < (res.rows || []).length; i++) {
    var ic = res.rows[i];
    if (seen[ic.ic_code]) { continue; }
    ics.push({kind: 'ic', code: ic.ic_code, name: ic.ic_name, unit: ic.unit_name, sub: ''});
  }
  if (ics.length) {
    out.push({sec: q ? 'รหัส IC ที่มีอยู่' : 'รหัส IC ที่ออกไว้ล่าสุด', n: ics.length, top: out.length > 0});
    out = out.concat(ics);
  }

   
  var llps = [];
  for (i = 0; i < (res.llp || []).length; i++) {
    var p = res.llp[i];
    llps.push({kind: 'llp', code: p.llp_code, name: p.llp_name,
               sub: p.l1_name + ' › ' + p.l2_name, cc: !!(p.cat_id && p.char_id)});
  }
  if (llps.length) {
    out.push({sec: 'ตัวสินค้าในบันได — ยังไม่มีรหัสนี้',
              n: llps.length + (res.llp_more ? ' (ยังมีอีก ' + res.llp_more + ' — พิมพ์ให้แคบลง)' : ''),
              top: out.length > 0});
    out = out.concat(llps);
  }
  return out;
}

function paintResults() {
  var q = $('icpQ').value.trim();
  M.rows = buildRows();
  var html = '', idx = -1, i;

  for (i = 0; i < M.rows.length; i++) {
    var r = M.rows[i];
    if (r.sec) {
      html += '<div class="icp-sec' + (r.top ? ' tp' : '') + '">'
        + '<span class="dot' + (r.gold ? '' : ' b') + '"></span>' + esc(r.sec)
        + (r.n ? ' <span class="n">' + esc(String(r.n)) + '</span>' : '') + '</div>';
      continue;
    }
    idx++;
    r._i = idx;
    if (M.cur < 0) { M.cur = idx; }
    var cur = idx === M.cur ? ' cur' : '';
    html += '<div class="icp-row' + cur + '" data-i="' + idx + '">'
      + '<span class="c">' + hi(r.code, q) + '</span>'
      + '<span class="n"><span>' + hi(r.name, q) + '</span>'
      + (r.kind === 'ic'
          ? (r.sub ? '<span class="sub">' + esc(r.sub) + '</span>' : '')
          : '<span class="sub">' + esc(r.sub) + (r.cc ? '' : ' · <b>ยังไม่ตั้งหมวดอนุมัติ</b>') + '</span>')
      + '</span>'
      + (r.kind === 'ic' ? '<span class="u">' + esc(r.unit) + '</span>' : '')
      + '<span class="go">' + (r.kind === 'ic' ? 'ใช้รหัสนี้ ' : 'ออกรหัสใหม่ ') + SVG_GO + '</span>'
      + '</div>';
  }

  if (idx < 0) {
    html = '<div class="icp-empty"><b>'
      + (M.res && M.res.err ? 'ค้นไม่สำเร็จ: ' + esc(M.res.err)
         : q ? 'ไม่พบรหัสหรือตัวสินค้าที่ตรงกับ “' + esc(q) + '”'
             : 'ยังไม่มีรหัส IC ในระบบ') + '</b>'
      + 'ไล่บันได 7 ขั้นเพื่อออกรหัสใหม่ — ระบบจะประกอบรหัส 20 ตัวอักษรให้เอง'
      + '<div class="cta"><button type="button" class="icp-primary" id="icpToMake">'
      + 'ไล่บันไดออกรหัสใหม่ ' + SVG_GO + '</button></div></div>';
    M.cur = -1;
  }
  $('icpResults').innerHTML = html;
  paintFooter();
}

function curRow() {
  for (var i = 0; i < M.rows.length; i++) {
    if (!M.rows[i].sec && M.rows[i]._i === M.cur) { return M.rows[i]; }
  }
  return null;
}

function moveCur(d) {
  var max = -1, i;
  for (i = 0; i < M.rows.length; i++) { if (!M.rows[i].sec) { max = M.rows[i]._i; } }
  if (max < 0) { return; }
  M.cur = Math.max(0, Math.min(max, M.cur + d));
  Array.prototype.forEach.call($('icpResults').querySelectorAll('.icp-row'), function (n) {
    var on = parseInt(n.dataset.i, 10) === M.cur;
    n.classList.toggle('cur', on);
    if (on) { n.scrollIntoView({block: 'nearest'}); }
  });
  paintFooter();
}

 
 
 
var STEP_KEYS = {
  l1:    ['l1',    'l1_code',    'l1_name'],
  l2:    ['l2',    'l2_code',    'l2_name'],
  llp:   ['llp',   'llp_code',   'llp_name'],
  size:  ['size',  'size_code',  'size_name'],
  brand: ['brand', 'brand_code', 'brand_name'],
  unit:  ['unit',  'unit_code',  'unit_name'],
  extra: ['extra', 'extra_code', 'extra_name']
};

function picked() {
  return (steps && steps.picked) ? steps.picked
       : {l1: '', l2: '', llp: '', size: NONE, brand: NONE, unit: '', extra: NONE};
}
function currentPick() {
  var p = picked();
  return {l1: p.l1 || '', l2: p.l2 || '', llp: p.llp || '',
          size: p.size || NONE, brand: p.brand || NONE,
          unit: p.unit || '', extra: p.extra || NONE, q: M.llpQ || ''};
}

 
function optionsFor(key) {
  if (!steps) { return []; }
  var m = STEP_KEYS[key];
  if (!m) { return []; }
  var rows = steps[m[0]] || [], out = [], i, c;
  for (i = 0; i < rows.length; i++) {
    c = String(rows[i][m[1]]);
    if (c === NONE) { continue; }
    out.push([c, String(rows[i][m[2]])]);
  }
  return out;
}
function nameOf(key, code) {
  var opts = optionsFor(key), i;
  for (i = 0; i < opts.length; i++) { if (opts[i][0] === code) { return opts[i][1]; } }
  return '';
}

function stepReady(i) {
  var p = picked();
  if (i === 0 || i === 5) { return true; }               
  if (i === 1) { return !!p.l1; }
  if (i === 2 || i === 3 || i === 4) { return !!(p.l1 && p.l2); }
  if (i === 6) { return !!p.llp; }
  if (i === DONE_STEP) { return !!(steps && steps.complete); }
  return false;
}

 
function nextStep() {
  var p = picked(), i, s;
  for (i = 0; i < 7; i++) {
    s = STEPS[i];
    if (!stepReady(i)) { continue; }
    if (p[s.key] && p[s.key] !== NONE) { continue; }
    if (!s.req && optionsFor(s.key).length === 0) { continue; }
    return i;
  }
  return DONE_STEP;
}

function loadSteps(over, forceStep) {
  var p = currentPick();
  Object.keys(over || {}).forEach(function (k) { p[k] = over[k]; });
  var my = ++seq;
  $('paneMake').classList.add('icp-busy');
  return api(BASE + '/api/ic_api.php?a=steps&' + new URLSearchParams(p).toString())
    .then(function (j) {
      if (my !== seq) { return; }
      steps = j;
      if (!M.nameTouched) { $('icpName').value = j.ic_name || ''; }
      M.step   = (typeof forceStep === 'number') ? forceStep : nextStep();
      M.optCur = -1;
      $('optAddRow').classList.remove('on');
      if (M.step !== 2) { $('optFilter').value = ''; }
      paintMake();
    })
    .catch(function (e) { status('bad', 'โหลดบันไดไม่สำเร็จ: ' + esc(e.message)); })
    .finally(function () { if (my === seq) { $('paneMake').classList.remove('icp-busy'); } });
}

function setValue(key, code) {
  var over = {};
  over[key] = code;
  if (key === 'l1')  { over.l2 = ''; over.llp = ''; over.size = NONE; over.brand = NONE; over.extra = NONE; }
  if (key === 'l2')  { over.llp = ''; over.size = NONE; over.brand = NONE; over.extra = NONE; }
  if (key === 'llp') { over.extra = NONE; M.nameTouched = false; }
  if (key === 'l1' || key === 'l2' || key === 'llp') { M.llpQ = ''; over.q = ''; }
  loadSteps(over);
}

function goStep(i) {
  if (!stepReady(i)) { return; }
   
  if (i === 2 && M.llpQ !== '') { M.llpQ = ''; $('optFilter').value = ''; loadSteps({q: ''}, 2); return; }
  M.step = i;
  M.optCur = -1;
  $('optAddRow').classList.remove('on');
  if (i !== 2) { $('optFilter').value = ''; }
  paintMake();
  if (i !== DONE_STEP) { setTimeout(function () { try { $('optFilter').focus(); } catch (e) {} }, 20); }
}

function paintMake() {
  var p = picked(), html = '', i;

  for (i = 0; i < STEPS.length; i++) {
    var s = STEPS[i], ready = stepReady(i), val, cls = '';
    var hasVal = !!(p[s.key] && p[s.key] !== NONE);
    var autoSkip = (!s.req && s.key !== 'done' && ready && optionsFor(s.key).length === 0);

    if (s.key === 'done') {
      val = ready ? 'พร้อมออกรหัส' : 'ยังไม่ครบ';
      if (!ready) { cls = 'none'; }
    } else if (hasVal) {
      val = (s.key === 'llp' ? p.llp.slice(5) : p[s.key]) + ' · ' + (nameOf(s.key, p[s.key]) || '—');
    } else if (!ready) {
      val = 'รอชั้นบน';  cls = 'none';
    } else if (autoSkip) {
      val = 'ยังไม่มีตัวเลือก — ข้ามให้แล้ว'; cls = 'skip';
    } else if (!s.req) {
      val = 'ไม่ระบุ'; cls = 'skip';
    } else {
      val = '— เลือก —'; cls = 'none';
    }

    html += '<button type="button" class="icp-step' + (M.step === i ? ' on' : '')
      + ((s.key !== 'done' && (hasVal || autoSkip)) ? ' done' : '') + '"'
      + ' data-step="' + i + '"' + (ready ? '' : ' disabled') + '>'
      + '<span class="k">' + (s.key === 'done' ? SVG_TICK : (i + 1)) + '</span>'
      + '<span class="tx"><span class="t">' + esc(s.label)
      + (s.req && s.key !== 'done' ? ' <span class="req">*</span>' : '') + '</span>'
      + '<span class="v ' + cls + '">' + esc(val) + '</span></span></button>';

     
    if (s.key === 'llp' && p.llp) {
      var cc = (steps && steps.charcat) || {is_set: false};
      html += '<div class="icp-cc">' + (cc.is_set
        ? '<span class="pill">' + esc(cc.cat_label || cc.cat_id) + '</span>'
          + '<span class="pill">' + esc(cc.char_label || cc.char_id) + '</span>'
        : '<span class="pill warn">ยังไม่ตั้งหมวดอนุมัติ — ออกรหัสไม่ได้</span>') + '</div>';
    }
  }
  $('icpRail').innerHTML = html;

  if (M.step === DONE_STEP) { paintSummary(); } else { paintOptions(); }
  paintFooter();
}

function paintOptions() {
  var s = STEPS[M.step], p = picked();
  var all = optionsFor(s.key);
  var q = (s.key === 'llp') ? M.llpQ : $('optFilter').value.trim();
  var ql = q.toLowerCase();
  var rows = [], i;
  for (i = 0; i < all.length; i++) {
     
    if (s.key !== 'llp' && q && all[i][0].toLowerCase().indexOf(ql) < 0
                             && all[i][1].toLowerCase().indexOf(ql) < 0) { continue; }
    rows.push(all[i]);
  }
  M.optRows = rows;

  $('optTitle').textContent = s.label;
  $('optCount').textContent = (q && s.key !== 'llp')
    ? rows.length + ' / ' + all.length : rows.length + ' รายการ';
  $('optFilter').style.display = (all.length > 8 || (s.key === 'llp' && q)) ? '' : 'none';
  $('optFilter').placeholder = s.key === 'llp' ? 'พิมพ์กรองชื่อตัวสินค้า…' : 'กรองในขั้นนี้…';
  $('optAdd').style.display = s.add ? '' : 'none';
  $('optAdd').disabled = !IS_ADMIN;
  $('optAdd').title = IS_ADMIN ? '' : 'เพิ่มชั้นบันไดได้เฉพาะผู้ดูแลระบบ (ADM) — มติ 16';

  var html = '';
  if (!s.req) {
    html += '<div class="icp-o skip' + (p[s.key] === NONE ? ' pick' : '')
      + (M.optCur === -1 ? ' cur' : '') + '" data-code="' + NONE + '">'
      + '<span class="c">' + NONE + '</span><span class="n">ไม่ระบุ — ข้ามขั้นนี้</span>'
      + '<span class="tick">' + SVG_TICK + '</span></div>';
  }
  for (i = 0; i < rows.length; i++) {
    html += '<div class="icp-o' + (p[s.key] === rows[i][0] ? ' pick' : '')
      + (M.optCur === i ? ' cur' : '') + '" data-code="' + esc(rows[i][0]) + '" data-i="' + i + '">'
      + '<span class="c">' + esc(rows[i][0]) + '</span>'
      + '<span class="n">' + hi(rows[i][1], q) + '</span>'
      + '<span class="tick">' + SVG_TICK + '</span></div>';
  }
  if (!rows.length) {
    html += '<div class="icp-note info">'
      + (s.req ? 'ยังไม่มีตัวเลือกในขั้นนี้'
               : 'หมวดนี้ยังไม่ได้ผูก' + esc(s.label) + 'ไว้ — ปล่อยเป็น “ไม่ระบุ” ได้เลย')
      + (s.add ? (IS_ADMIN ? ' — กด “เพิ่มใหม่” ถ้าจำเป็น' : ' · แจ้งผู้ดูแลระบบ (ADM) ให้เพิ่มให้ก่อน') : '')
      + '</div>';
  }
  $('optList').innerHTML = html;
}

function paintSummary() {
  var p = picked();
  var cc = (steps && steps.charcat) || {is_set: false};
  $('optTitle').textContent = 'ตรวจก่อนออกรหัส';
  $('optCount').textContent = '';
  $('optFilter').style.display = 'none';
  $('optAdd').style.display = 'none';

  var html = '<div class="icp-note ' + (steps.exists ? 'good' : 'info') + '">'
    + (steps.exists
        ? '<b>รหัสนี้ออกไว้แล้ว</b> — ชื่อเดิมคือ “' + esc(steps.exists_name) + '” · กดใช้ได้เลย ระบบจะใช้ตัวเดิมไม่สร้างซ้ำ'
        : '<b>ยังไม่มีรหัสนี้ในระบบ</b> — กดปุ่มแล้วจะออกรหัสใหม่ พร้อมลงทะเบียนใน materials ให้อัตโนมัติ (มติ 3)')
    + '</div>';

  if (!cc.is_set) {
    html += '<div class="icp-note warn"><b>ตัวสินค้านี้ยังไม่ได้ตั้งหมวดอนุมัติ / ลักษณะวัสดุ</b><br>'
      + 'ออกรหัสใต้มันไม่ได้จนกว่าจะตั้งค่า (มติ 32)'
      + (IS_ADMIN
          ? '<div class="icp-ccset">'
            + selectHtml('ccCat',  steps.cat_options  || {}, cc.cat_id,  '— หมวดอนุมัติ —')
            + selectHtml('ccChar', steps.char_options || {}, cc.char_id, '— ลักษณะวัสดุ —')
            + '<button type="button" class="icp-primary" id="ccSave" style="padding:6px 13px">บันทึกให้ตัวสินค้า</button>'
            + '</div><div class="small" style="margin-top:7px">ใช้กับ IC ทุกตัวใต้ตัวสินค้านี้</div>'
          : '<br>แจ้งผู้ดูแลระบบ (ADM) ให้ตั้งค่าให้ก่อน')
      + '</div>';
  }

  html += '<div class="icp-note info"><b>' + esc(nameOf('l1', p.l1) || p.l1) + '</b> › '
    + esc(nameOf('l2', p.l2) || p.l2) + ' › ' + esc(nameOf('llp', p.llp) || p.llp) + '<br>'
    + 'ขนาด ' + esc(p.size === NONE ? 'ไม่ระบุ' : (nameOf('size', p.size) || p.size))
    + ' · ยี่ห้อ ' + esc(p.brand === NONE ? 'ไม่ระบุ' : (nameOf('brand', p.brand) || p.brand))
    + ' · หน่วยเก็บ <b>' + esc(nameOf('unit', p.unit) || '—') + '</b>'
    + ' · คุณสมบัติ ' + esc(p.extra === NONE ? 'ไม่มี' : (nameOf('extra', p.extra) || p.extra))
    + '</div>';

  $('optList').innerHTML = html;
}

 
function selectHtml(id, map, pickedVal, blank) {
  var h = '<select id="' + id + '"><option value="">' + esc(blank) + '</option>';
  Object.keys(map).forEach(function (k) {
    h += '<option value="' + esc(k) + '"' + (k === pickedVal ? ' selected' : '') + '>' + esc(map[k]) + '</option>';
  });
  return h + '</select>';
}

 
function paintFooter() {
  var it = basketItem(M.bufId), hasNext = false, i;
  if (it) {
    for (i = 0; i < it.allocs.length; i++) {
      if (i !== M.idx && !it.allocs[i].ic) { hasNext = true; break; }
    }
  }
  $('icpNext').style.display = hasNext ? '' : 'none';

  if (M.mode === 'find') {
    var r = curRow();
    $('icpUse').disabled  = !r;
    $('icpNext').disabled = !r || !hasNext || r.kind === 'llp';
    $('icpUseTx').textContent = (r && r.kind === 'llp') ? 'ไล่บันไดออกรหัส' : 'ใช้รหัสนี้';
    if (!r) { status('', ''); }
    else if (r.kind === 'ic') { status('', 'จะใส่ <span class="mono">' + esc(r.code) + '</span> — ' + esc(r.name)); }
    else { status('', 'ยังไม่มีรหัสของ “' + esc(r.name) + '” — กด Enter เพื่อไล่บันไดต่อ'); }
    return;
  }

  var p = picked();
  var parts = [p.l1, p.l2, p.llp ? p.llp.slice(5) : '', p.size, p.brand, p.unit, p.extra];
  var html = '';
  for (i = 0; i < 7; i++) {
    html += '<button type="button" class="icp-sg' + (parts[i] ? '' : ' empty')
      + (M.step === i ? ' on' : '') + '" data-seg="' + i + '">'
      + '<b>' + esc(parts[i] || (i === 1 ? '··' : '···')) + '</b>'
      + '<span class="lb">' + esc(SEG_LABELS[i]) + '</span></button>';
  }
  $('icpSegs').innerHTML = html;

  var cc = (steps && steps.charcat) || {is_set: false};
  var ok = !!(steps && steps.complete && cc.is_set);
  $('icpUse').disabled  = !ok;
  $('icpNext').disabled = !ok || !hasNext;
  $('icpUseTx').textContent = (steps && steps.exists) ? 'ใช้รหัสเดิมนี้' : 'ออกรหัสนี้ แล้วใส่ในช่อง';

  if (!steps || !steps.complete) {
    status('', 'ต้องเลือกอย่างน้อย <b>ตัวสินค้า</b> และ <b>หน่วยเก็บ</b> — ขนาด/ยี่ห้อ/คุณสมบัติ เว้นไว้ได้');
  } else if (!cc.is_set) {
    status('bad', 'ตัวสินค้านี้ยังไม่ได้ตั้งหมวดอนุมัติ — ออกรหัสไม่ได้ (มติ 32)');
  } else {
    status('ok', 'รหัสที่จะได้: <span class="mono">' + esc(steps.ic_code) + '</span>');
  }
}

 
 
 
 
function takeCurrent(keepOpen) {
  if (M.mode === 'find') {
    var r = curRow();
    if (!r) { return; }
    if (r.kind === 'llp') {
      M.nameTouched = false;
      setMode('make');
      loadSteps({l1: r.code.slice(0, 3), l2: r.code.slice(3, 5), llp: r.code,
                 size: NONE, brand: NONE, unit: '', extra: NONE, q: ''});
      return;
    }
    finish(r.code, r.name, r.unit, keepOpen);
    return;
  }

  var cc = (steps && steps.charcat) || {is_set: false};
  if (!steps || !steps.complete || !cc.is_set) { return; }
  var p = picked(), btn = $('icpUse');
  btn.disabled = true;
  api(BASE + '/api/ic_api.php?a=create_ic', form({
    llp: p.llp, size: p.size, brand: p.brand, unit: p.unit, extra: p.extra,
    ic_name: $('icpName').value.trim()
  })).then(function (j) {
    finish(j.ic_code, j.ic_name, j.unit, keepOpen);
  }).catch(function (e) {
    status('bad', 'ออกรหัสไม่สำเร็จ: ' + esc(e.message));
    btn.disabled = false;
  });
}

function finish(code, name, unit, keepOpen) {
  fillSlot(M.bufId, M.idx, code, name, unit);
  var it = basketItem(M.bufId), nxt = -1, i;
  for (i = 0; i < it.allocs.length; i++) { if (!it.allocs[i].ic) { nxt = i; break; } }

  if (keepOpen && nxt >= 0) {
    M.idx = nxt;
    M.mode = 'find'; M.cur = -1; M.step = 0; M.optCur = -1;
    M.llpQ = ''; M.nameTouched = false;
    steps = null;
    $('icpQ').value = '';
    $('icpName').value = '';
    $('icpClear').classList.remove('on');
    $('icpOf').textContent = 'บรรทัดที่ ' + (nxt + 1) + ' จาก ' + it.allocs.length;
    setMode('find');
    runSearch('').then(function () {
      status('ok', 'ใส่ <span class="mono">' + esc(code) + '</span> ให้บรรทัดก่อนหน้าแล้ว — เลือกของบรรทัดถัดไปต่อได้เลย');
    });
    $('icpQ').focus();
    return;
  }

  closePicker();
   
  var qty = document.querySelector('[data-qty="' + M.bufId + ':' + M.idx + '"]');
  if (qty) { qty.focus(); qty.select(); }
}

 
 
 
$('icpClose').addEventListener('click', closePicker);
$('icpScrim').addEventListener('mousedown', function (ev) { if (ev.target === this) { closePicker(); } });
$('icpUse').addEventListener('click', function () { takeCurrent(false); });
$('icpNext').addEventListener('click', function () { takeCurrent(true); });
$('icpBack').addEventListener('click', function () { setMode('find'); $('icpQ').focus(); });
$('icpClear').addEventListener('click', function () {
  $('icpQ').value = '';
  this.classList.remove('on');
  runSearch('');
  $('icpQ').focus();
});
$('icpName').addEventListener('input', function () { M.nameTouched = true; });

$('icpQ').addEventListener('input', function () {
  var v = this.value;
  $('icpClear').classList.toggle('on', v !== '');
  if (M.mode === 'make' && v !== '') { setMode('find'); }
  clearTimeout(searchTimer);
  searchTimer = setTimeout(function () { runSearch($('icpQ').value.trim()); }, 220);
});

$('icpResults').addEventListener('mousemove', function (ev) {
  var n = ev.target.closest('.icp-row');
  if (!n) { return; }
  var i = parseInt(n.dataset.i, 10);
  if (i === M.cur) { return; }
  M.cur = i;
  Array.prototype.forEach.call(this.querySelectorAll('.icp-row'), function (x) {
    x.classList.toggle('cur', parseInt(x.dataset.i, 10) === M.cur);
  });
  paintFooter();
});
$('icpResults').addEventListener('click', function (ev) {
  if (ev.target.closest('#icpToMake')) {
    M.nameTouched = false;
    setMode('make');
    loadSteps({l1: '', l2: '', llp: '', size: NONE, brand: NONE, unit: '', extra: NONE, q: ''}, 0);
    return;
  }
  var n = ev.target.closest('.icp-row');
  if (!n) { return; }
  M.cur = parseInt(n.dataset.i, 10);
  takeCurrent(false);
});

$('icpRail').addEventListener('click', function (ev) {
  var b = ev.target.closest('[data-step]');
  if (b && !b.disabled) { goStep(parseInt(b.dataset.step, 10)); }
});
$('icpSegs').addEventListener('click', function (ev) {
  var b = ev.target.closest('[data-seg]');
  if (b) { goStep(parseInt(b.dataset.seg, 10)); }
});
$('optList').addEventListener('click', function (ev) {
  if (ev.target.closest('#ccSave')) { saveCharCat(); return; }
  var o = ev.target.closest('.icp-o');
  if (o) { setValue(STEPS[M.step].key, o.dataset.code); }
});

var optTimer = null;
$('optFilter').addEventListener('input', function () {
  var v = this.value.trim();
  if (STEPS[M.step] && STEPS[M.step].key === 'llp') {
    clearTimeout(optTimer);
    optTimer = setTimeout(function () { M.llpQ = v; loadSteps({q: v}, 2); }, 220);
    return;
  }
  M.optCur = -1;
  paintOptions();
});

 
$('optAdd').addEventListener('click', function () {
  if (this.disabled) { return; }
  $('optAddRow').classList.toggle('on');
  if ($('optAddRow').classList.contains('on')) { $('optAddName').focus(); }
});
$('optAddCancel').addEventListener('click', function () { $('optAddRow').classList.remove('on'); });
$('optAddGo').addEventListener('click', addLadder);
$('optAddName').addEventListener('keydown', function (ev) {
  ev.stopPropagation();
  if (ev.key === 'Enter') { ev.preventDefault(); addLadder(); }
});

function addLadder() {
  var s = STEPS[M.step], p = picked();
  var name = $('optAddName').value.trim();
  if (!s.add) { return; }
  if (!name) { status('bad', 'ใส่ชื่อก่อน'); return; }

  var btn = $('optAddGo');
  btn.disabled = true;
  api(BASE + '/api/ic_api.php?a=add_' + s.key, form({name: name, l1: p.l1, l2: p.l2, llp: p.llp}))
    .then(function (j) {
      $('optAddName').value = '';
      $('optAddRow').classList.remove('on');
      status('ok', 'เพิ่ม “' + esc(name) + '” เป็นรหัส <span class="mono">' + esc(j.code) + '</span> แล้ว');
      setValue(s.key, j.code);
    })
    .catch(function (e) { status('bad', 'เพิ่มไม่สำเร็จ: ' + esc(e.message)); })
    .finally(function () { btn.disabled = false; });
}

 
function saveCharCat() {
  var p = picked();
  var cat = $('ccCat').value, chr = $('ccChar').value;
  if (!cat || !chr) { status('bad', 'ต้องเลือกทั้งหมวดอนุมัติและลักษณะวัสดุ'); return; }
  var btn = $('ccSave');
  btn.disabled = true;
  api(BASE + '/api/ic_api.php?a=set_charcat', form({llp: p.llp, cat_id: cat, char_id: chr}))
    .then(function (j) {
      status('ok', 'ตั้งค่าให้ <span class="mono">' + esc(p.llp) + '</span> แล้ว'
        + (j.ic ? ' · อัปเดต IC ใต้มัน ' + j.ic + ' รหัส' : ''));
      return loadSteps({}, DONE_STEP);
    })
    .catch(function (e) { status('bad', 'ตั้งค่าไม่สำเร็จ: ' + esc(e.message)); btn.disabled = false; });
}

 
document.addEventListener('keydown', function (ev) {
  if (!M.open) { return; }

  if (ev.key === 'Escape') {
    ev.preventDefault();
    if ($('optAddRow').classList.contains('on')) { $('optAddRow').classList.remove('on'); return; }
    if (M.mode === 'make') { setMode('find'); $('icpQ').focus(); return; }
    closePicker();
    return;
  }

  if (ev.key === 'Tab') {                        
    var vis = [];
    Array.prototype.forEach.call(
      $('icpBox').querySelectorAll('button:not([disabled]),input,select,[tabindex]:not([tabindex="-1"])'),
      function (n) { if (n.offsetParent !== null) { vis.push(n); } });
    if (!vis.length) { return; }
    var first = vis[0], last = vis[vis.length - 1];
    if (!ev.shiftKey && document.activeElement === last)  { ev.preventDefault(); first.focus(); }
    if (ev.shiftKey  && document.activeElement === first) { ev.preventDefault(); last.focus(); }
    return;
  }

  if (M.mode === 'find') {
    if (ev.key === 'ArrowDown')    { ev.preventDefault(); moveCur(1); }
    else if (ev.key === 'ArrowUp') { ev.preventDefault(); moveCur(-1); }
    else if (ev.key === 'Enter')   { ev.preventDefault(); takeCurrent(ev.ctrlKey || ev.metaKey); }
    return;
  }

  if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
    ev.preventDefault();
    if (M.step === DONE_STEP) { return; }
    var min = (STEPS[M.step] && !STEPS[M.step].req) ? -1 : 0;
    M.optCur = Math.max(min, Math.min(M.optRows.length - 1, M.optCur + (ev.key === 'ArrowDown' ? 1 : -1)));
    paintOptions();
    var cur = $('optList').querySelector('.icp-o.cur');
    if (cur) { cur.scrollIntoView({block: 'nearest'}); }
  } else if (ev.key === 'Enter') {
    ev.preventDefault();
    if ((ev.ctrlKey || ev.metaKey) || M.step === DONE_STEP) { takeCurrent(false); return; }
    if (M.optCur === -1 && STEPS[M.step] && !STEPS[M.step].req) { setValue(STEPS[M.step].key, NONE); return; }
    if (M.optRows[M.optCur]) { setValue(STEPS[M.step].key, M.optRows[M.optCur][0]); }
  } else if (ev.key === 'ArrowLeft' && document.activeElement !== $('optFilter')
                                    && document.activeElement !== $('icpName')) {
    ev.preventDefault();
    for (var i = M.step - 1; i >= 0; i--) { if (stepReady(i)) { goStep(i); break; } }
  }
});

 
 
 
$('btnPush').addEventListener('click', function () {
  var jobs = basket.map(function (b) {
    return {
      buffer_id: b.bufId,
      qty_consumed: parseFloat(b.qty) || 0,
      allocs: b.allocs.filter(function (a) { return a.ic || a.qty; })
                      .map(function (a) { return {ic_code: a.ic, qty: parseFloat(a.qty) || 0}; })
    };
  });
  var n = jobs.length;
  if (dnBusy) { banner('warn', 'กำลังเตรียมรูปใบส่งของ — รอสักครู่แล้วกดอีกครั้ง'); return; }
  if (!dnPhotos.length) {   // [2026-10-02 · GP-10]
    $('dnBox').classList.add('miss');
    banner('bad', 'ต้องแนบรูปใบส่งของอย่างน้อย 1 รูปก่อนออกใบ IN (ของจาก supplier / PO)');
    return;
  }
  if (!confirm('ยืนยันออกใบ IN 1 ใบ สำหรับของ ' + n + ' บรรทัด?\n\n'
             + 'ใบนี้ไม่ผูกประตู — เอา QR ไปสแกนที่ตู้ G ไหนของไซต์นี้ก็ได้ '
             + 'ยอดจะขึ้นที่ G ที่สแกน หลังถ่ายรูปยืนยันและปิดประตู')) { return; }

  var btn = this;
  btn.disabled = true;
  btn.textContent = 'กำลังออกใบ…';
  api(BASE + '/api/rc_api.php?a=push_batch', formMulti({
    jobs: JSON.stringify(jobs),
    note: $('note').value,
    photos: JSON.stringify(dnPhotos)
  })).then(function (j) {
    banner('ok', 'ออกใบ <span class="mono">' + esc(j.doc_no) + '</span> แล้ว ('
      + j.n_lines + ' บรรทัด) สถานะ <b>Sent Inbound</b><br>'
      + '<span style="font-weight:400">QR ของใบนี้สแกนได้ที่<b>ตู้ G ไหนของไซต์นี้ก็ได้</b> — '   // [2026-10-06]
      + 'เปิดดูได้ที่หน้า “QR เอกสาร” ในแอปหลัก · ยอดขึ้นที่ G ที่สแกน หลัง<b>ถ่ายรูปยืนยัน</b>และปิดประตู</span>');
    basket = [];
    $('note').value = '';
    dnPhotos = [];
    renderDn();
    renderBasket();
    return loadLines();
  }).catch(function (e) {
    banner('bad', esc(e.message));
  }).finally(function () {
    btn.disabled = basket.length === 0;
    btn.textContent = 'นำเข้า gate + ออกใบ IN';
  });
});

 
loadLines().then(function () {
  if (PRESET > 0) { addToBasket(PRESET); }    
});

})();
</script>

<?php
uiFoot('buffer ถือหน่วยซื้อ · IC ถือหน่วยเก็บ · จุดแปลงคือตอนออกใบ IN (มติ 9) · ใบ IN ไม่ผูกประตู (มติ 23)');
