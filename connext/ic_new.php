<?php
/**
 * CONNEXT — ic_new.php : สร้างรหัส IC 20 ตัวอักษร — ตั้งต้นจากรหัส Mango เสมอ (มติ 47) + แก้บันไดได้ครบในจอเดียว (มติ 43)
 *
 *   ic_code (20) = llp(8) + ขนาด(3) + ยี่ห้อ(3) + หน่วย(3) + คุณสมบัติ(3)
 *   llp_code (8) = L1(3) + L2(2) + ตัวสินค้า(3)
 *
 * ── มติ 47 (2026-09-23): เริ่มจากรหัส Mango ก่อนเสมอ ──────────────────────────
 * ข้อ 0 ของจอคือเลือกรหัส Mango — ยังไม่เลือก ส่วนที่เหลือทั้งจอกดไม่ได้ · เปิดตรงด้วย ?mat=รหัส ก็ได้
 *   · Mango ผูกตัวสินค้า (LLP) ไว้แล้ว → ล็อก L1/L2/ตัวสินค้าไว้ที่ตัวนั้น (1 Mango = 1 LLP · มติ 45)
 *     มี IC อยู่แล้ว = ตั้งต้นขนาด/ยี่ห้อ/หน่วยจาก IC หลัก — ออกได้เฉพาะ "IC เพิ่ม" ที่ต่างจากเดิม
 *   · ยังไม่ผูก → ADM เลือก/สร้างตัวสินค้าในบันได (ช่องค้นข้ามหมวดตั้งต้นจากชื่อ Mango) · สายคลังต้องให้ ADM ผูกก่อน
 *   · หน่วยตั้งต้นตามหน่วยของ Mango · เลือกหน่วยอื่น = เตือน (ยกยอดย้ายจำนวนตรง ๆ ไม่แปลงหน่วย)
 *   · กดสร้าง = ผูก LLP + ตั้ง Cat/Char + ออก IC + ผูกกลับ Mango ในทรานแซกชันเดียว
 *     (api/ic_api.php a=create_for_mango → lib/ic_mango.php icmCreate) — ไม่มี IC ที่ไม่มีต้นทางออกจากจอนี้อีก
 *
 * ── มติ 43 (รุ่นก่อน) ──────────────────────────────────────────────────────
 * เพิ่ม/แก้ได้ทุกชั้นในจอเดียว (ปุ่ม + ข้างทุกช่อง) และระบุรหัสเองได้ทั้งขนาด/ยี่ห้อ/หน่วย
 * (เว้นว่าง = ระบบไล่เลขให้) · ทั้งจอทำงานผ่าน api/ic_api.php ไม่ reload
 *
 * กติกาที่ยังเหมือนเดิม:
 *   มติ 12 — คนเลือกบันไดเองทั้งหมด ไม่มี AI ในขั้นออกรหัส
 *   มติ 3  — ออกรหัสแล้วสร้างแถวคู่ใน materials (code_type='ic') ให้อัตโนมัติ
 *   มติ 16 — สายคลังออกรหัสได้ · แก้บันได/ผูกตัวสินค้าให้ Mango สงวนให้ ADM
 *   มติ 28-29 — CatID/CharID เป็นของตัวสินค้า (LLP) · แก้ในฟอร์มนี้ = บันทึกลง LLP แล้ว
 *               cascade ไปทุก IC ใต้มัน ไม่ใช่ค่าเฉพาะ IC ตัวเดียว
 *   มติ 32 — LLP ที่ยังไม่ตั้ง CatID/CharID ออก IC ไม่ได้ (ADM ตั้งได้ในจอนี้เลย)
 *
 * ธง has_serial / is_cx (มติ 43) — เก็บค่าอย่างเดียว ยังไม่มีสายรับ/เบิก/ประตูไหนอ่านไปใช้
 * PHP 7.4-compatible เท่านั้น
 */

require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/ic.php';
require_once __DIR__ . '/lib/ic_mango.php';   // smSchemaReady / mangoNormalizeCode (มติ 47) — หลัง ic.php เสมอ

$user    = uiGuard();
$isAdmin = uiIsAdmin($user);

// ธง has_serial/is_cx เพิ่มทีหลัง — ให้ ADM กดเติมคอลัมน์ได้จากจอนี้เลย
$notice = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && (string)($_POST['action'] ?? '') === 'add_flags'
    && hash_equals(csrfToken(), (string)($_POST['csrf'] ?? ''))) {
    if (!$isAdmin) {
        $notice = ['bad', 'เพิ่มคอลัมน์ได้เฉพาะผู้ดูแลระบบ (ADM)'];
    } else {
        try {
            $done   = icEnsureFlagColumns($pdo);
            $notice = ['ok', 'เพิ่มคอลัมน์ has_serial / is_cx แล้ว (' . count($done) . ' คำสั่ง)'];
        } catch (Throwable $ex) {
            $notice = ['bad', 'เพิ่มคอลัมน์ไม่สำเร็จ: ' . $ex->getMessage()];
        }
    }
}
$hasFlags = icHasFlagColumns($pdo);

// จอนี้ต้องเริ่มจากรหัส Mango (มติ 47) — ไม่มีตารางผูก Mango → IC ก็ออกรหัสไม่ได้
$schemaOk = smSchemaReady($pdo);
$initMat  = $schemaOk ? mangoNormalizeCode((string)($_GET['mat'] ?? '')) : '';

$catLabels  = icCatLabels();
$charLabels = icCharLabels();

uiHead('สร้างรหัส IC', 'เริ่มจากรหัส Mango เสมอ → ตัวสินค้า (LLP) → ขนาด/ยี่ห้อ/หน่วย — ออกแล้วผูกกลับ Mango ให้อัตโนมัติ', $user, '🏷️', uiIsEmbedded());
uiBackToAdmin('ladder');
?>

<?php if ($notice !== null): ?>
  <div class="banner b-<?= $notice[0] === 'ok' ? 'ok' : 'bad' ?>"><?= e($notice[1]) ?></div>
<?php endif; ?>

<div class="icn-head">
  <h1>สร้างรหัส IC</h1>
  <p>เริ่มจาก <b>รหัส Mango</b> ก่อนเสมอ — ตัวสินค้า (LLP) ตามที่ Mango ผูกไว้ · หน่วยตั้งต้นตาม Mango ·
     ออกรหัสแล้วระบบผูก IC กลับเข้ารหัส Mango ให้ในคำสั่งเดียว (มติ 47)<br>
     ตัวเลือกขนาด/ยี่ห้อถูกกรองตามหมวดด้วยตาราง junction (<span class="mono">l2_sizes</span> / <span class="mono">l2_brands</span>)
     — ชุดเดียวกับที่ FK บังคับใน DB · ต้องใช้ค่านอกลิสต์ = กด <b>+</b> เปิดคู่ใหม่เข้าหมวดนั้นก่อนเสมอ</p>
</div>

<?php if (!$schemaOk): ?>
  <div class="banner b-bad">
    ยังไม่มีตารางผูก Mango → IC (<span class="mono">mango_ic_map</span>) — จอนี้ต้องเริ่มจากรหัส Mango จึงยังออกรหัสไม่ได้ ·
    <?php if ($isAdmin): ?>
      <a href="<?= uiUrl(APP_BASE . '/setup_master.php') ?>">เปิดหน้าจัดการรหัสวัสดุแล้วกดอัปเดตโครงสร้างก่อน ›</a>
    <?php else: ?>
      แจ้งผู้ดูแลระบบ (ADM) ให้อัปเดตโครงสร้างที่หน้าจัดการรหัสวัสดุ
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if (!$hasFlags): ?>
  <div class="banner b-warn" style="font-weight:400">
    ตาราง <span class="mono">ic_items</span> ยังไม่มีคอลัมน์ <span class="mono">has_serial</span> /
    <span class="mono">is_cx</span> — สองสวิตช์ในข้อ 3 จะยังบันทึกไม่ได้
    <?php if ($isAdmin): ?>
      <form method="post" style="display:inline;margin-left:8px">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="add_flags">
        <button type="submit" class="mini">เพิ่มคอลัมน์ให้เลย</button>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="icn-wrap">
  <!-- ═════════ ฟอร์ม ═════════ -->
  <div class="card icn-form">

    <h2><span class="num">0</span> รหัส Mango ตั้งต้น <span class="pill p-info">บังคับ — เริ่มที่นี่เสมอ</span></h2>
    <div id="mgPick">
      <div class="withadd">
        <input type="search" id="mgQ" placeholder="พิมพ์รหัส Mango หรือชื่อวัสดุ (รหัส LLP/IC ที่ผูกอยู่ก็ค้นได้)…"
               autocomplete="off" enterkeyhint="search" <?= $schemaOk ? '' : 'disabled' ?>>
        <button type="button" id="mgGo" <?= $schemaOk ? '' : 'disabled' ?>>ค้นหา</button>
      </div>
      <div id="mgList" class="pick-list" aria-live="polite"></div>
      <p class="small" style="margin-top:6px">
        ยังไม่มีในทะเบียน?
        <?php if ($isAdmin): ?>
          เพิ่มรหัสที่ <a href="<?= uiUrl(APP_BASE . '/mango_master.php') ?>">📚 ทะเบียนวัสดุ Mango</a> ก่อน แล้วกลับมาออกรหัสที่นี่
        <?php else: ?>
          ให้ผู้ดูแลระบบ (ADM) เพิ่มรหัสเข้าทะเบียน Mango ก่อน
        <?php endif; ?>
      </p>
    </div>
    <div id="mgCard" class="mg-card" hidden></div>

    <hr class="sep">

    <fieldset id="gate" class="gate off" disabled>
      <div class="gate-note" id="gateNote">🔒 เลือกรหัส Mango ในข้อ 0 ก่อน — ตัวสินค้าและหน่วยจะตั้งต้นตาม Mango ให้เอง</div>

      <h2><span class="num">1</span> หมวดหมู่และสินค้า</h2>
      <div id="lockNote" class="ccnote lock" hidden></div>
      <div id="llpFind" class="llp-find" hidden>
        <label class="fld" for="llpFindQ">ค้นตัวสินค้าจากทุกหมวด
          <span class="small">(ตั้งต้นจากชื่อ Mango — แก้คำค้นได้ · กดรายการเพื่อเติม L1/L2/สินค้าให้)</span></label>
        <div class="withadd">
          <input type="search" id="llpFindQ" autocomplete="off" enterkeyhint="search">
          <button type="button" class="ghost" id="llpFindGo">ค้น</button>
        </div>
        <div id="llpFindList" class="pick-list" aria-live="polite"></div>
      </div>
      <div class="grid2">
        <div>
          <label class="fld">กลุ่มหลัก L1</label>
          <div class="withadd">
            <select id="fL1"><option value="">— เลือก —</option></select>
            <?php if ($isAdmin): ?><button type="button" class="addbtn" data-add="L1" title="เพิ่มกลุ่มหลักใหม่">+</button><?php endif; ?>
          </div>
          <div class="addrow" id="addL1" hidden>
            <input type="text" id="newL1Code" placeholder="รหัส 3 ตัว เช่น STR" maxlength="3" style="width:150px" class="mono">
            <input type="text" id="newL1Name" placeholder="ชื่อกลุ่มหลัก" style="flex:1;min-width:140px">
            <button type="button" class="mini" data-go="l1">เพิ่ม</button>
            <button type="button" class="mini ghost" data-cancel="L1">ยกเลิก</button>
          </div>
        </div>
        <div>
          <label class="fld">หมวดการใช้งาน L2</label>
          <div class="withadd">
            <select id="fL2" disabled><option value="">— เลือก L1 ก่อน —</option></select>
            <?php if ($isAdmin): ?><button type="button" class="addbtn" data-add="L2" title="เพิ่มหมวดใหม่ใต้ L1 นี้">+</button><?php endif; ?>
          </div>
          <div class="addrow" id="addL2" hidden>
            <input type="text" id="newL2Code" placeholder="รหัส 2 หลัก (ว่าง = ไล่เลขให้)" maxlength="2" style="width:190px" class="mono">
            <input type="text" id="newL2Name" placeholder="ชื่อหมวด" style="flex:1;min-width:140px">
            <button type="button" class="mini" data-go="l2">เพิ่ม</button>
            <button type="button" class="mini ghost" data-cancel="L2">ยกเลิก</button>
          </div>
        </div>
      </div>

      <div style="margin-top:12px">
        <label class="fld">สินค้า (LLP)</label>
        <div class="withadd">
          <select id="fLlp" disabled><option value="">— เลือก L2 ก่อน —</option></select>
          <?php if ($isAdmin): ?><button type="button" class="addbtn" data-add="Llp" title="เพิ่มตัวสินค้าใหม่ในหมวดนี้">+</button><?php endif; ?>
        </div>
        <div class="addrow" id="addLlp" hidden>
          <input type="text" id="newLlpName" placeholder="ชื่อตัวสินค้าใหม่ (รหัสไล่ให้อัตโนมัติ)" style="flex:1;min-width:200px">
          <button type="button" class="mini" data-go="llp">เพิ่ม</button>
          <button type="button" class="mini ghost" data-cancel="Llp">ยกเลิก</button>
        </div>
        <input type="text" id="fLlpQ" placeholder="พิมพ์กรองชื่อตัวสินค้าในหมวดนี้…" style="width:100%;margin-top:6px">
      </div>

      <hr class="sep">

      <h2><span class="num">2</span> คุณสมบัติ <span class="pill p-muted">ไม่บังคับ — ว่าง = 000 "ไม่ระบุ"</span></h2>
      <div class="grid2">
        <div>
          <label class="fld">ขนาด</label>
          <select id="fSize" disabled><option value="000">000 · ไม่ระบุ</option></select>
          <?php if ($isAdmin): ?>
            <div class="addrow on">
              <input type="text" id="newSizeCode" placeholder="รหัส" maxlength="3" style="width:72px" class="mono">
              <input type="text" id="newSizeName" placeholder="ชื่อขนาดใหม่ เช่น 1/2&quot;" style="flex:1;min-width:110px">
              <button type="button" class="mini ghost" data-go="size">+ เพิ่ม</button>
            </div>
          <?php endif; ?>
        </div>
        <div>
          <label class="fld">ยี่ห้อ</label>
          <select id="fBrand" disabled><option value="000">000 · ไม่ระบุ</option></select>
          <?php if ($isAdmin): ?>
            <div class="addrow on">
              <input type="text" id="newBrandCode" placeholder="รหัส" maxlength="3" style="width:72px" class="mono">
              <input type="text" id="newBrandName" placeholder="ชื่อยี่ห้อใหม่" style="flex:1;min-width:110px">
              <button type="button" class="mini ghost" data-go="brand">+ เพิ่ม</button>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div style="margin-top:12px">
        <label class="fld">Extra <span class="small">(variant พิเศษของสินค้า เช่น Type / สี / เกรด / "แบบยาว")</span></label>
        <div class="grid2">
          <select id="fExtra" disabled><option value="000">— ไม่มี (000) —</option></select>
          <?php if ($isAdmin): ?>
            <div class="addrow on">
              <input type="text" id="newExtraName" placeholder="…หรือพิมพ์ extra ใหม่" style="flex:1;min-width:150px">
              <button type="button" class="mini ghost" data-go="extra">+ เพิ่ม</button>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <hr class="sep">

      <h2><span class="num">3</span> หน่วยนับและประเภท</h2>
      <div class="grid2">
        <div>
          <label class="fld">หน่วยสต็อก <span style="color:var(--red)">*</span></label>
          <select id="fUnit"><option value="">— เลือก —</option></select>
          <div id="unitWarn" class="warnline" hidden></div>
          <?php if ($isAdmin): ?>
            <div class="addrow on">
              <input type="text" id="newUnitCode" placeholder="รหัส" maxlength="3" style="width:72px" class="mono">
              <input type="text" id="newUnitName" placeholder="…หรือพิมพ์หน่วยใหม่" style="flex:1;min-width:110px">
              <button type="button" class="mini ghost" data-go="unit">+ เพิ่ม</button>
            </div>
          <?php endif; ?>
        </div>
        <div>
          <label class="fld">&nbsp;</label>
          <label class="sw"><input type="checkbox" id="fSerial" <?= $hasFlags ? '' : 'disabled' ?>><span class="track"></span>
            <span><b>ตามรายชิ้น (มี Serial Number)</b>
              <span class="small">เช่น ตู้ MDB, เครื่องจักร — เคลื่อนไหวครั้งละ 1 ชิ้นต่อ S/N</span></span></label>
        </div>
      </div>

      <div class="grid2" style="margin-top:12px">
        <div>
          <label class="fld">หมวดอนุมัติ CX (cat_id)</label>
          <div class="radios" id="fCat">
            <?php foreach ($catLabels as $k => $lb): ?>
              <label><input type="radio" name="cat" value="<?= e($k) ?>" <?= $k === 'C02' ? 'checked' : '' ?>>
                <b><?= e($k) ?></b> <span class="small"><?= e(trim(preg_replace('/^' . preg_quote($k, '/') . '\s*·\s*/u', '', $lb))) ?></span></label>
            <?php endforeach; ?>
          </div>
        </div>
        <div>
          <label class="fld">ลักษณะวัสดุ (char_id)</label>
          <select id="fChar">
            <?php foreach ($charLabels as $k => $lb): ?>
              <option value="<?= e($k) ?>" <?= $k === 'CSB' ? 'selected' : '' ?>><?= e($lb) ?></option>
            <?php endforeach; ?>
          </select>
          <label class="sw" style="margin-top:9px"><input type="checkbox" id="fCx" checked <?= $hasFlags ? '' : 'disabled' ?>><span class="track"></span>
            <span><b>วัสดุระบบเบิกจ่าย CX (is_cx)</b>
              <span class="small">ปิด = วัสดุ WC — คลังกลางจ่ายแล้วจบ ไซต์ไม่รับเข้า</span></span></label>
        </div>
      </div>

      <div id="ccNote" class="ccnote" hidden></div>

      <div style="margin-top:12px">
        <div class="name-hd">
          <label class="fld" for="fName">ชื่อวัสดุที่จะบันทึก <span class="small">(แก้ได้ — เว้นว่าง = ระบบตั้งให้จากชื่อชั้นต่าง ๆ)</span></label>
          <button type="button" class="mini ghost" id="useMgName" title="ใช้ชื่อเดียวกับรหัส Mango (ชื่อในใบ PO)">ใช้ชื่อตาม Mango</button>
        </div>
        <input type="text" id="fName" style="width:100%" autocomplete="off">
      </div>
    </fieldset>

    <div class="icn-foot">
      <button type="button" id="goCreate" disabled>✓ สร้างรหัส IC แล้วผูกกับ Mango</button>
      <button type="button" class="ghost" id="goReset" title="ตั้งบันไดกลับไปค่าตั้งต้นของรหัส Mango ที่เลือก">ล้างฟอร์ม</button>
      <span class="small" id="needHint">ยังขาด: รหัส Mango (ข้อ 0)</span>
    </div>
  </div>

  <!-- ═════════ แผงขวา ═════════ -->
  <div class="icn-side">
    <div class="card">
      <h2>👁 รหัสที่จะได้ <span class="small">(live)</span></h2>
      <div class="pv-mango" id="pvMango">ยังไม่ได้เลือกรหัส Mango</div>
      <div class="segs">
        <div class="seg" data-k="l1"><b>···</b><span>L1</span></div>
        <div class="seg" data-k="l2"><b>··</b><span>L2</span></div>
        <div class="seg" data-k="llp"><b>···</b><span>สินค้า</span></div>
        <div class="seg" data-k="size"><b>000</b><span>ขนาด</span></div>
        <div class="seg" data-k="brand"><b>000</b><span>ยี่ห้อ</span></div>
        <div class="seg" data-k="unit"><b>···</b><span>หน่วย</span></div>
        <div class="seg" data-k="extra"><b>000</b><span>พิเศษ</span></div>
      </div>
      <div class="fullcode mono" id="fullCode">— เลือกรหัส Mango แล้วไล่บันได รหัสจะขึ้นที่นี่ —</div>
      <div class="small" id="liveName" style="text-align:center;margin-top:5px"></div>
      <div class="small" id="existsNote" style="margin-top:6px"></div>
    </div>

    <div class="card">
      <h2>กติกาของจอนี้</h2>
      <p class="small">
        <b>ต้องเริ่มจากรหัส Mango เสมอ</b> (มติ 47) — ยอด/เอกสาร/ใบ PO เดิมคีย์ด้วยรหัส Mango ทั้งหมด
        IC ที่ออกโดยไม่มีต้นทางจะยกยอดไปหาไม่ได้ · ออกแล้วระบบผูก IC กลับเข้ารหัส Mango ให้ทันที
      </p>
      <p class="small" style="margin-top:8px">
        <b>1 Mango มีตัวสินค้า (LLP) ได้ตัวเดียว</b> (มติ 45) — ผูกไว้แล้วบันไดข้อ 1 ถูกล็อก ·
        ของเดิมรหัสเดียวคลุมหลายสเปก = ออก <b>IC เพิ่ม</b> ใต้ตัวสินค้าเดิม (ต่างที่ขนาด/ยี่ห้อ/คุณสมบัติ)
      </p>
      <p class="small" style="margin-top:8px">
        <b>ทำไมต้องกด + ก่อนถึงใช้ค่าใหม่ได้</b> — DB ผูก FK แบบ 3 คอลัมน์ (<span class="mono">l1, l2, brand</span>)
        ต่อให้ยิง SQL ตรง ๆ ใส่ "ยี่ห้อ TOA" ให้หมวดที่ยังไม่มีคู่นั้นก็ไม่ได้ · ปุ่ม <b>+</b> คือประตูทางการบานเดียวที่เปิดคู่ให้ถูกต้อง
        (สร้างในพจนานุกรมกลาง + ผูกเข้า <span class="mono">l2_sizes</span>/<span class="mono">l2_brands</span> ในคำสั่งเดียว)
      </p>
      <p class="small" style="margin-top:8px">
        <b>CatID/CharID</b> เป็นค่าของ <b>ตัวสินค้า (LLP)</b> ไม่ใช่ของ IC เดี่ยว ๆ (มติ 28-29) —
        แก้ตรงนี้แล้วกดสร้าง ระบบจะบันทึกลง LLP และ IC ทุกตัวใต้มันได้ค่าเดียวกัน
      </p>
      <p class="small" style="margin-top:8px">
        ทะเบียนที่ออกไปแล้ว: <a href="<?= uiUrl(APP_BASE . '/ic_list.php') ?>">📒 ทะเบียน IC</a>
        <?php if ($isAdmin): ?>
          · เปลี่ยนตัวสินค้า / ยกยอด Mango → IC: <a href="<?= uiUrl(APP_BASE . '/setup_master.php') ?>">🗂️ จัดการรหัสวัสดุ</a>
        <?php endif; ?>
      </p>
    </div>

    <div class="card" id="resultCard" hidden>
      <h2>✓ ผลลัพธ์</h2>
      <div id="resultBody"></div>
    </div>
  </div>
</div>

<style>
.icn-head{margin-bottom:14px}.icn-head h1{font-size:1.5rem;font-weight:800;color:var(--navy)}.icn-head p{font-size:.78rem;color:var(--muted);margin-top:3px;line-height:1.75}
.icn-wrap{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(280px,1fr);gap:14px;align-items:start}
@media(max-width:900px){.icn-wrap{grid-template-columns:1fr}}
.icn-form h2{margin-top:0}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}@media(max-width:620px){.grid2{grid-template-columns:1fr}}
.icn-form select,.icn-form input[type=text],.icn-form input[type=search]{width:100%}
.withadd{display:flex;gap:6px}.withadd select,.withadd input{flex:1;min-width:0}
.addbtn{width:34px;flex:0 0 auto;padding:0;background:#fff;color:var(--navy);border:1px solid var(--line);font-size:1.05rem;line-height:1}
.addbtn:hover{border-color:var(--navy-2);background:var(--blue-bg)}
.addrow{display:none;gap:6px;margin-top:6px;align-items:center;flex-wrap:wrap}
.addrow.on{display:flex}.addrow:not([hidden]){display:flex}
.addrow input{width:auto}
.sep{border:0;border-top:1px solid var(--line);margin:16px 0 13px}
.sw{display:flex;align-items:flex-start;gap:9px;cursor:pointer;font-size:.82rem;line-height:1.45;position:relative}
.sw input{position:absolute;opacity:0;width:0;height:0}
.sw .track{width:38px;height:21px;border-radius:999px;background:#cbd5e1;position:relative;flex:0 0 auto;transition:background .15s;margin-top:1px}
.sw .track::after{content:"";position:absolute;top:2px;left:2px;width:17px;height:17px;border-radius:50%;background:#fff;transition:transform .15s}
.sw input:checked + .track{background:var(--navy-2)}.sw input:checked + .track::after{transform:translateX(17px)}
.sw input:disabled + .track{opacity:.4}.sw .small{display:block;color:var(--muted)}
.radios{display:flex;gap:16px;flex-wrap:wrap;padding-top:6px}.radios label{display:flex;align-items:center;gap:5px;font-size:.84rem;cursor:pointer}
.radios input{width:auto}
.ccnote{margin-top:12px;border-radius:9px;padding:9px 12px;font-size:.8rem;line-height:1.6}
.ccnote.same{background:var(--green-bg);border:1px solid #86efac;color:#14532d}
.ccnote.diff{background:var(--amber-bg);border:1px solid #fcd34d;color:#78350f}
.ccnote.lock{margin:0 0 12px;background:#eef2f7;border:1px solid #c7d4e8;color:#33415c}
.icn-foot{display:flex;gap:9px;align-items:center;flex-wrap:wrap;border-top:1px solid var(--line);margin-top:15px;padding-top:13px}
.segs{display:flex;gap:6px;justify-content:center;flex-wrap:wrap;margin:8px 0 11px}
.seg{text-align:center}
.seg b{display:block;font-family:ui-monospace,Consolas,monospace;font-size:.86rem;font-weight:700;padding:6px 7px;border-radius:7px;background:#f1f5f9;border:1px solid var(--line);color:var(--muted);min-width:42px}
.seg span{display:block;font-size:.66rem;color:var(--muted);margin-top:3px}
.seg.on b{color:var(--navy);border-color:var(--navy-2);box-shadow:0 0 0 2px rgba(30,58,138,.12)}
.seg[data-k=size].on b{background:#fef3c7;border-color:#fcd34d}.seg[data-k=brand].on b{background:#fde8e8;border-color:#fca5a5}
.seg[data-k=unit].on b{background:#dcfce7;border-color:#86efac}.seg[data-k=extra].on b{background:#e0e7ff;border-color:#a5b4fc}
.fullcode{text-align:center;font-size:.8rem;color:var(--muted);padding:9px;background:#f8fafc;border-radius:8px;word-break:break-all}
.fullcode.ready{background:var(--navy);color:var(--gold);font-weight:700;font-size:1rem;letter-spacing:1px}
.icn-form.busy .gate,.icn-form.busy .icn-foot{opacity:.55;pointer-events:none}
.res-line{display:flex;gap:8px;padding:5px 0;border-bottom:1px dashed var(--line);font-size:.82rem}.res-line b{min-width:80px;color:var(--muted);font-weight:600}
/* มติ 47 — ข้อ 0 รหัส Mango + ประตูกั้นข้อ 1-3 */
.gate{border:0;margin:0;padding:0;min-width:0}
.gate.off > :not(.gate-note){opacity:.45}
.gate-note{display:none;margin:0 0 12px;padding:9px 12px;border-radius:9px;background:#f1f5f9;border:1px dashed #cbd5e1;font-size:.84rem;color:var(--muted)}
.gate.off .gate-note{display:block}
.pick-list{margin-top:6px;max-height:330px;overflow:auto;border:1px solid var(--line);border-radius:10px;background:#fff}
.pick-list:empty{display:none}
.pick-list .pad{padding:8px 10px}
.pick-sec{font-size:.72rem;font-weight:700;color:var(--muted);padding:6px 10px;background:#f8fafc;border-bottom:1px solid var(--line)}
.pk{display:flex;align-items:center;gap:10px;padding:7px 10px;border-bottom:1px solid var(--line);cursor:pointer}
.pk:last-child{border-bottom:0}
.pk:hover,.pk:focus{background:var(--blue-bg);outline:none}
.pk .c{font-family:ui-monospace,Consolas,monospace;font-size:.74rem;font-weight:700;color:var(--navy-2);background:#f1f5f9;border-radius:6px;padding:3px 7px;flex:0 0 auto}
.pk .n{flex:1;min-width:0;font-size:.85rem;line-height:1.45}
.pk .u{font-size:.72rem;color:var(--muted);background:#f8fafc;border:1px solid var(--line);border-radius:999px;padding:2px 9px;flex:0 0 auto;white-space:nowrap}
.pk .u.warn{background:var(--amber-bg);border-color:#fcd34d;color:#92400e}
.pk .u.bad{background:var(--red-bg);border-color:#fca5a5;color:#b91c1c}
.pk .go{font-size:.74rem;font-weight:700;color:var(--navy-2);flex:0 0 auto}
@media(max-width:620px){.pk{flex-wrap:wrap}.pk .n{flex-basis:100%;order:3}}
.mg-card{border:1px solid #bfdbfe;background:#f8fbff;border-radius:12px;padding:11px 13px}
.mg-top{display:flex;gap:10px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap}
.mg-id{display:flex;gap:10px;align-items:flex-start;min-width:0}
.mg-code{font-size:.92rem;font-weight:700;color:var(--gold);background:var(--navy);border-radius:8px;padding:4px 9px;flex:0 0 auto}
.mg-row{display:flex;gap:10px;margin-top:9px;font-size:.84rem;line-height:1.55}
.mg-k{min-width:74px;flex:0 0 auto;color:var(--muted);font-weight:600;font-size:.78rem;padding-top:2px}
.mg-ic{padding:2px 0}
@media(max-width:620px){.mg-row{flex-direction:column;gap:2px}}
.llp-find{margin:0 0 12px;padding:10px 12px;border-radius:10px;background:#f8fafc;border:1px dashed #cbd5e1}
.warnline{margin-top:6px;padding:6px 9px;border-radius:8px;background:var(--amber-bg);border:1px solid #fcd34d;color:#78350f;font-size:.78rem;line-height:1.55}
.name-hd{display:flex;align-items:flex-end;justify-content:space-between;gap:8px;flex-wrap:wrap}
.name-hd .fld{margin-bottom:.3rem}.name-hd button{margin-bottom:.3rem}
.pv-mango{text-align:center;font-size:.76rem;color:var(--muted);margin:-2px 0 8px;line-height:1.5}
.pv-mango b{color:var(--navy)}
</style>

<script>
(function () {
  'use strict';
  var BASE = <?= json_encode(APP_BASE) ?>;
  var CSRF = <?= json_encode(csrfToken()) ?>;
  var IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
  var SCHEMA_OK = <?= $schemaOk ? 'true' : 'false' ?>;
  var INIT_MAT = <?= json_encode($initMat) ?>;
  var EMBED = <?= uiIsEmbedded() ? 'true' : 'false' ?>;
  var NONE = '000';

  var $ = function (id) { return document.getElementById(id); };
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }
  function api(url, opts) {
    return fetch(url, Object.assign({credentials: 'same-origin'}, opts || {}))
      .then(function (r) { return r.json().catch(function () { throw new Error('เซิร์ฟเวอร์ตอบไม่ใช่ JSON (HTTP ' + r.status + ')'); }); })
      .then(function (j) { if (!j.ok) { throw new Error(j.error || 'ผิดพลาดไม่ทราบสาเหตุ'); } return j; });
  }
  function form(obj) {
    var b = new URLSearchParams();
    b.set('csrf', CSRF);
    Object.keys(obj).forEach(function (k) { b.set(k, obj[k]); });
    return {method: 'POST', body: b};
  }
  var flashT = null;
  function flash(html, kind) {
    var old = $('icFlash');
    if (old) { old.parentNode.removeChild(old); }
    var d = document.createElement('div');
    d.id = 'icFlash';
    d.className = 'banner b-' + (kind || 'ok');
    d.innerHTML = html;
    var wrap = document.querySelector('.wrap');
    wrap.insertBefore(d, wrap.firstChild);
    clearTimeout(flashT);
    flashT = setTimeout(function () { if (d.parentNode) { d.parentNode.removeChild(d); } }, 7000);
  }
  function fmtQ(v) { return Number(v || 0).toLocaleString('th-TH', {maximumFractionDigits: 3}); }
  function smLink(mat) { return BASE + '/setup_master.php?q=' + encodeURIComponent(mat) + '&scope=all'; }

  var BLANK = {l1: '', l2: '', llp: '', size: NONE, brand: NONE, unit: '', extra: NONE};
  var picked = Object.assign({}, BLANK);
  var steps = null, nameTouched = false, ccTouched = false, seq = 0;

  // ── รหัส Mango ตั้งต้น (มติ 47) — null = ยังไม่เลือก ข้อ 1-3 ทั้งหมดกดไม่ได้ ──────────
  var MG = null;
  // 1 Mango มีตัวสินค้าได้ตัวเดียว (มติ 45) — ผูกไว้แล้ว = บันไดชั้น 1-3 ล็อกที่ตัวนั้น
  function lockedLlp() { return MG && !MG.problem && MG.defaults.llp ? MG.defaults.llp : ''; }
  // ยังไม่ผูกตัวสินค้า: ADM เลือกให้ได้ (ผูกตอนกดสร้าง) · สายคลังต้องรอ ADM (มติ 16)
  function canLadder() { return !!MG && !MG.problem && (lockedLlp() !== '' || IS_ADMIN); }
  function mgIcs() {
    var out = [];
    if (MG) { MG.llps.forEach(function (b) { b.ics.forEach(function (x) { out.push(x); }); }); }
    return out;
  }
  function attachedHere(code) { return !!code && mgIcs().some(function (x) { return x.ic_code === code; }); }
  function unitKey(s) { return String(s == null ? '' : s).replace(/\s+/g, '').toLowerCase(); }
  function unitName(code) {
    var n = '';
    ((steps && steps.unit) || []).forEach(function (u) { if (u.unit_code === code) { n = u.unit_name; } });
    return n;
  }
  // หน่วยเก็บที่เลือกต่างจากหน่วยของ Mango — ยกยอดย้ายจำนวนตรง ๆ ไม่แปลงหน่วย
  function unitDiffers() {
    if (!MG || !picked.unit) { return false; }
    var a = unitKey(MG.mat.unit), b = unitKey(unitName(picked.unit));
    return a !== '' && b !== '' && a !== b;
  }

  function fill(sel, rows, codeKey, nameKey, val, blank) {
    var h = blank !== undefined ? '<option value="">' + esc(blank) + '</option>' : '';
    rows.forEach(function (r) {
      h += '<option value="' + esc(r[codeKey]) + '"' + (String(r[codeKey]) === String(val) ? ' selected' : '') + '>'
         + esc(r[codeKey]) + ' · ' + esc(r[nameKey]) + '</option>';
    });
    sel.innerHTML = h;
  }

  function load(over) {
    var p = Object.assign({}, picked, over || {});
    var lk = lockedLlp();
    if (lk) { p.l1 = lk.substr(0, 3); p.l2 = lk.substr(3, 2); p.llp = lk; }   // ห้ามหลุดจากตัวสินค้าของ Mango
    var my = ++seq;
    document.querySelector('.icn-form').classList.add('busy');
    return api(BASE + '/api/ic_api.php?a=steps&' + new URLSearchParams(Object.assign({q: $('fLlpQ').value.trim()}, p)).toString())
      .then(function (j) {
        if (my !== seq) { return; }
        steps = j; picked = j.picked; paint();
      })
      .catch(function (e) { flash('โหลดบันไดไม่สำเร็จ: ' + esc(e.message), 'bad'); })
      .then(function () { if (my === seq) { document.querySelector('.icn-form').classList.remove('busy'); } });
  }

  function paint() {
    var j = steps;
    fill($('fL1'),    j.l1,    'l1_code',    'l1_name',    picked.l1, '— เลือก —');
    fill($('fL2'),    j.l2,    'l2_code',    'l2_name',    picked.l2, picked.l1 ? '— เลือก —' : '— เลือก L1 ก่อน —');
    fill($('fLlp'),   j.llp,   'llp_code',   'llp_name',   picked.llp, picked.l2 ? '— เลือก —' : '— เลือก L2 ก่อน —');
    fill($('fSize'),  j.size,  'size_code',  'size_name',  picked.size);
    fill($('fBrand'), j.brand, 'brand_code', 'brand_name', picked.brand);
    fill($('fUnit'),  j.unit,  'unit_code',  'unit_name',  picked.unit, '— เลือก —');
    fill($('fExtra'), [{extra_code: NONE, extra_name: 'ไม่มี (000)'}].concat(j.extra || []), 'extra_code', 'extra_name', picked.extra);

    // ตัวสินค้าล็อกตาม Mango (มติ 45) — L1/L2/สินค้า และปุ่ม + ของสามชั้นนี้ใช้ไม่ได้
    var lk = lockedLlp();
    $('fL1').disabled    = !!lk;
    $('fL2').disabled    = !!lk || !picked.l1;
    $('fLlp').disabled   = !!lk || !picked.l2;
    $('fLlpQ').disabled  = !!lk;
    $('fSize').disabled  = !picked.l2;
    $('fBrand').disabled = !picked.l2;
    $('fExtra').disabled = !picked.llp;
    ['L1', 'L2', 'Llp'].forEach(function (k) {
      var b = document.querySelector('[data-add="' + k + '"]');
      if (b) { b.disabled = !!lk; }
      if (lk) { toggleAdd(k, false); }
    });

    // CatID/CharID เป็นของ LLP (มติ 28) — เติมค่าปัจจุบันลงฟอร์มถ้าคนยังไม่ได้แตะเอง
    var cc = j.charcat || {};
    var note = $('ccNote');
    if (!picked.llp) {
      note.hidden = true;
    } else if (cc.is_set) {
      if (!ccTouched) { setCat(cc.cat_id); $('fChar').value = cc.char_id; }
      note.hidden = false;
      note.className = 'ccnote same';
      note.innerHTML = 'ตัวสินค้า <b>' + esc(picked.llp) + '</b> ตั้งค่าไว้แล้ว: <b>' + esc(cc.cat_id) + ' / ' + esc(cc.char_id) + '</b>'
        + (IS_ADMIN ? ' — แก้ค่าด้านบนแล้วกดสร้าง ระบบจะบันทึกทับที่ตัวสินค้า และ IC ทุกตัวใต้มันเปลี่ยนตาม' : '');
    } else {
      // ยังไม่ตั้ง — ตั้งต้นจากค่าในทะเบียน Mango (ถ้ามี) ให้ ADM ยืนยัน
      var fromMg = MG && MG.defaults.cat_id && MG.defaults.char_id;
      if (!ccTouched) {
        setCat(fromMg ? MG.defaults.cat_id : 'C02');
        $('fChar').value = fromMg ? MG.defaults.char_id : 'CSB';
      }
      note.hidden = false;
      note.className = 'ccnote diff';
      note.innerHTML = 'ตัวสินค้า <b>' + esc(picked.llp) + '</b> <b>ยังไม่ได้ตั้ง</b> หมวดอนุมัติ/ลักษณะวัสดุ — '
        + (IS_ADMIN ? 'ค่าที่เลือกด้านบน' + (fromMg ? ' (ตั้งต้นตามทะเบียน Mango)' : '') + ' จะถูกบันทึกลงตัวสินค้าตอนกดสร้าง (มติ 32)'
                    : 'ต้องให้ผู้ดูแลระบบ (ADM) ตั้งก่อนถึงจะออกรหัสได้ (มติ 32)');
    }

    if (!nameTouched) { $('fName').value = j.ic_name || ''; }
    paintCode();
  }

  function segSet(k, txt, on) {
    var el = document.querySelector('.seg[data-k="' + k + '"]');
    el.querySelector('b').textContent = txt;
    el.classList.toggle('on', !!on);
  }
  function paintCode() {
    segSet('l1',    picked.l1    || '···', !!picked.l1);
    segSet('l2',    picked.l2    || '··',  !!picked.l2);
    segSet('llp',   picked.llp ? picked.llp.substr(5, 3) : '···', !!picked.llp);
    segSet('size',  picked.size  || NONE, picked.size  !== NONE);
    segSet('brand', picked.brand || NONE, picked.brand !== NONE);
    segSet('unit',  picked.unit  || '···', !!picked.unit);
    segSet('extra', picked.extra || NONE, picked.extra !== NONE);

    var mat = MG ? MG.mat.mat_code : '';
    $('pvMango').innerHTML = MG
      ? 'ตั้งต้นจาก Mango <b class="mono">' + esc(mat) + '</b> · ' + esc(MG.mat.name || '')
      : 'ยังไม่ได้เลือกรหัส Mango';

    var ready = !!(MG && canLadder() && steps && steps.complete);
    var full = $('fullCode');
    full.textContent = ready ? steps.ic_code : '— เลือกรหัส Mango แล้วไล่บันได รหัสจะขึ้นที่นี่ —';
    full.classList.toggle('ready', ready);
    $('liveName').textContent = ready && steps.ic_name ? steps.ic_name : '';

    var already = ready && attachedHere(steps.ic_code);                    // ผูกกับ Mango นี้อยู่แล้ว
    var dead    = ready && !!steps.exists && steps.exists_active === false; // ปิดใช้งานแล้ว (เปลี่ยนสเปกไปแล้ว · มติ 46)
    var ex = '';
    if (ready && steps.exists) {
      if (already)   { ex = '<span class="pill p-ok">ผูกกับ ' + esc(mat) + ' อยู่แล้ว</span> ' + esc(steps.exists_name); }
      else if (dead) { ex = '<span class="pill p-bad">ปิดใช้งานแล้ว</span> ' + esc(steps.exists_name) + ' — ใช้ต่อไม่ได้'; }
      else           { ex = '<span class="pill p-info">รหัสนี้ออกไว้แล้ว</span> ' + esc(steps.exists_name) + ' — กดปุ่มจะผูกตัวเดิมกับ ' + esc(mat) + ' (ไม่สร้างซ้ำ)'; }
    }
    $('existsNote').innerHTML = ex;

    var uw = $('unitWarn');
    if (ready && unitDiffers()) {
      uw.hidden = false;
      uw.innerHTML = '⚠ หน่วย <b>' + esc(unitName(picked.unit)) + '</b> ต่างจากหน่วยของรหัส Mango (<b>' + esc(MG.mat.unit) + '</b>) — '
        + 'ตอนยกยอด จำนวนย้ายไปตรง ๆ ไม่แปลงหน่วย';
    } else {
      uw.hidden = true;
    }

    var need = [];
    if (!MG)                 { need.push('รหัส Mango (ข้อ 0)'); }
    else if (MG.problem)     { need.push('แก้การผูกตัวสินค้าของ ' + mat + ' ที่หน้าจัดการรหัสวัสดุ'); }
    else if (!canLadder())   { need.push('ให้ ADM ผูกตัวสินค้า (LLP) ให้ ' + mat); }
    else {
      if (!picked.l1)   { need.push('กลุ่ม L1'); }
      if (!picked.l2)   { need.push('หมวด L2'); }
      if (!picked.llp)  { need.push('สินค้า'); }
      if (!picked.unit) { need.push('หน่วยสต็อก'); }
      if (picked.llp && !IS_ADMIN && !(steps && steps.charcat && steps.charcat.is_set)) {
        need.push('ให้ ADM ตั้ง CatID/CharID ของตัวสินค้า');
      }
    }
    var hint = need.length ? 'ยังขาด: ' + need.join(' · ')
             : !ready ? 'กำลังโหลดบันได…'
             : already ? 'รหัสนี้ผูกกับ ' + mat + ' อยู่แล้ว — เปลี่ยนขนาด/ยี่ห้อ/คุณสมบัติ เพื่อออก IC ตัวใหม่ของรหัสนี้'
             : dead ? 'รหัสนี้ถูกปิดใช้งานแล้ว — เลือกส่วนผสมอื่น (หรือเปิดกลับที่ ✏️ แก้ไข IC)'
             : 'พร้อมแล้ว — ' + (steps.exists ? 'ผูกรหัสเดิม' : 'ออกรหัสใหม่') + 'ให้ ' + mat;
    $('needHint').textContent = hint;
    $('goCreate').disabled = !ready || need.length > 0 || already || dead;
    $('goCreate').textContent = ready && steps.exists && !already ? '🔗 ผูกรหัสนี้กับ Mango' : '✓ สร้างรหัส IC แล้วผูกกับ Mango';
  }

  function setCat(v) {
    Array.prototype.forEach.call(document.querySelectorAll('#fCat input'), function (r) { r.checked = r.value === v; });
  }
  function getCat() {
    var r = document.querySelector('#fCat input:checked');
    return r ? r.value : '';
  }

  // ── ข้อ 0: ค้น/เลือกรหัส Mango ─────────────────────────────────────────────
  var mgSeq = 0, mgRows = [];
  function mgSearch(pickExact) {
    var q = $('mgQ').value.trim(), box = $('mgList');
    if (!q) { box.innerHTML = ''; mgRows = []; return; }
    var my = ++mgSeq;
    box.innerHTML = '<div class="small pad">กำลังค้น…</div>';
    api(BASE + '/api/ic_api.php?a=mango_find&q=' + encodeURIComponent(q))
      .then(function (j) {
        if (my !== mgSeq) { return; }
        mgRows = j.rows;
        var exact = q.replace(/\s+/g, '').toUpperCase();
        if (pickExact && j.rows.length && j.rows[0].mat_code === exact) { mgPick(exact); return; }
        if (!j.rows.length) { box.innerHTML = '<div class="small pad">ไม่พบรหัส Mango ที่ตรง "' + esc(q) + '"</div>'; return; }
        var h = '';
        j.rows.forEach(function (r) {
          var st = r.n_llp > 1  ? '<span class="u bad">⚠ ผูกหลายตัวสินค้า</span>'
                 : r.n_llp === 1 ? '<span class="u">' + esc(r.llp_code) + ' · IC ' + r.n_ic + '</span>'
                 : '<span class="u warn">ยังไม่ผูกตัวสินค้า</span>';
          h += '<div class="pk" data-mat="' + esc(r.mat_code) + '" role="button" tabindex="0">'
             + '<span class="c">' + esc(r.mat_code) + '</span>'
             + '<span class="n">' + esc(r.name || '(ไม่มีชื่อ)') + ' <span class="small">· ' + esc(r.unit || '—')
             + (r.llp_name ? ' · ' + esc(r.llp_name) : '') + '</span></span>'
             + st + '<span class="go">เลือก ›</span></div>';
        });
        if (j.more > 0) { h += '<div class="small pad">…และอีก ' + j.more + ' รหัส — พิมพ์ให้เจาะจงขึ้น</div>'; }
        box.innerHTML = h;
      })
      .catch(function (e) { if (my === mgSeq) { box.innerHTML = '<div class="pad" style="color:var(--red)">' + esc(e.message) + '</div>'; } });
  }

  function mgPick(code) {
    ++mgSeq;                                  // ผลค้นที่ยังค้างอยู่ห้ามมาทับ
    $('mgList').innerHTML = '<div class="small pad">กำลังเปิด ' + esc(code) + '…</div>';
    return api(BASE + '/api/ic_api.php?a=mango_info&mat=' + encodeURIComponent(code))
      .then(function (j) {
        MG = j.mango;
        steps = null;                         // พรีวิวของ Mango ตัวก่อนห้ามค้างระหว่างรอบันไดใหม่
        setUrlMat(MG.mat.mat_code);
        $('mgList').innerHTML = '';
        $('resultCard').hidden = true;
        paintMango();
        return applyDefaults();
      })
      .catch(function (e) {
        $('mgList').innerHTML = '<div class="pad" style="color:var(--red)">' + esc(e.message) + '</div>';
      });
  }

  function mgClear() {
    MG = null;
    steps = null;
    setUrlMat('');
    picked = Object.assign({}, BLANK);
    nameTouched = false; ccTouched = false;
    setCat('C02'); $('fChar').value = 'CSB';
    $('fName').value = ''; $('fLlpQ').value = '';
    $('llpFindQ').value = ''; $('llpFindList').innerHTML = '';
    $('resultCard').hidden = true;
    paintMango();
    load(BLANK);
    try { $('mgQ').focus(); $('mgQ').select(); } catch (e) {}
    if ($('mgQ').value.trim()) { mgSearch(false); }
  }

  // ตั้งบันไดกลับไปค่าตั้งต้นของ Mango — ตัวสินค้าที่ผูก · ขนาด/ยี่ห้อ/หน่วยจาก IC หลัก หรือหน่วยตาม Mango
  function applyDefaults() {
    var d = MG.defaults, p = Object.assign({}, BLANK, {size: d.size || NONE, brand: d.brand || NONE, unit: d.unit || ''});
    if (d.llp) { p.l1 = d.llp.substr(0, 3); p.l2 = d.llp.substr(3, 2); p.llp = d.llp; }
    picked = p;
    nameTouched = false; ccTouched = false;
    setCat(d.cat_id || 'C02'); $('fChar').value = d.char_id || 'CSB';   // ค่าของ Mango ตัวก่อนห้ามค้าง
    $('fLlpQ').value = '';
    $('llpFindList').innerHTML = '';
    if (!lockedLlp() && canLadder()) {
      $('llpFindQ').value = llpSeed(MG.mat.name);
      llpFind();
    }
    return load(p);
  }

  function setUrlMat(code) {
    try {
      var u = new URL(location.href);
      if (code) { u.searchParams.set('mat', code); } else { u.searchParams.delete('mat'); }
      history.replaceState(null, '', u.pathname + u.search + u.hash);
    } catch (e) {}
  }

  function paintMango() {
    var card = $('mgCard'), pick = $('mgPick');
    if (!MG) { card.hidden = true; pick.hidden = false; paintGate(); return; }
    pick.hidden = true; card.hidden = false;
    var m = MG.mat, h = '';
    h += '<div class="mg-top"><div class="mg-id"><span class="mono mg-code">' + esc(m.mat_code) + '</span>'
       + '<div><b>' + esc(m.name || '(ไม่มีชื่อ)') + '</b><div class="small">หน่วย ' + esc(m.unit || '—')
       + (m.subgroup ? ' · ' + esc(m.subgroup) : '') + '</div></div></div>'
       + '<button type="button" class="mini ghost" id="mgChange">เปลี่ยนรหัส Mango</button></div>';

    if (!MG.llps.length) {
      h += '<div class="mg-row"><span class="mg-k">ตัวสินค้า</span><span>'
         + (IS_ADMIN ? '<span class="pill p-warn">ยังไม่ผูก</span> เลือกในข้อ 1 — ระบบผูกให้ตอนกดสร้าง (1 Mango มีตัวสินค้าได้ตัวเดียว)'
                     : '<span class="pill p-bad">ยังไม่ผูก</span> ให้ผู้ดูแลระบบ (ADM) ผูกตัวสินค้าให้รหัสนี้ก่อน (มติ 16)')
         + '</span></div>';
    }
    MG.llps.forEach(function (b) {
      h += '<div class="mg-row"><span class="mg-k">ตัวสินค้า</span><span><span class="mono"><b>' + esc(b.llp_code) + '</b></span> ' + esc(b.llp_name)
         + (b.missing ? ' <span class="pill p-bad">ไม่พบในทะเบียน LLP</span>'
            : (!b.is_active ? ' <span class="pill p-bad">ปิดใช้งานแล้ว</span>' : '')
              + (b.is_set ? ' <span class="pill ' + (b.cat_id === 'C01' ? 'p-warn' : 'p-muted') + '">' + esc(b.cat_id) + '</span>'
                            + ' <span class="pill p-muted">' + esc(b.char_id) + '</span>'
                          : ' <span class="pill p-warn">ยังไม่ตั้ง Cat/Char</span>'))
         + (b.path ? '<div class="small">' + esc(b.path) + '</div>' : '') + '</span></div>';
    });

    var ics = mgIcs();
    h += '<div class="mg-row"><span class="mg-k">IC ที่ผูก</span><span>';
    if (!ics.length) { h += '<span class="small">ยังไม่มี — ตัวที่ออกจากจอนี้จะเป็น IC หลักของรหัสนี้</span>'; }
    ics.forEach(function (x) {
      var usable = !x.missing && x.is_active && !MG.problem;
      h += '<div class="mg-ic"><span class="mono"><b>' + esc(x.ic_code) + '</b></span>'
         + (x.is_primary ? ' <span class="pill p-info">หลัก</span>' : '')
         + (x.missing ? ' <span class="pill p-bad">ไม่พบใน ic_items</span>' : ' ' + esc(x.ic_name) + ' <span class="small">· ' + esc(x.unit) + '</span>')
         + (!x.missing && !x.is_active ? ' <span class="pill p-bad">ปิดใช้งาน</span>' : '')
         + (x.unit_warn ? ' <span class="pill p-warn">หน่วยต่างจาก Mango</span>' : '')
         + (usable ? ' <button type="button" class="mini ghost" data-base="' + esc(x.ic_code) + '" title="ตั้งต้นขนาด/ยี่ห้อ/หน่วยจาก IC ตัวนี้">ใช้เป็นแม่แบบ</button>' : '')
         + (IS_ADMIN && !x.missing ? ' <a href="' + esc(BASE + '/ic_edit.php?ic=' + encodeURIComponent(x.ic_code)) + '" title="แก้ไข IC (ชื่อ/สเปก/หน่วย)">✏️</a>' : '')
         + '</div>';
    });
    if (ics.length) {
      h += '<div class="small" style="margin-top:3px">ออกเพิ่มได้ — ต้องต่างจากเดิมที่ขนาด/ยี่ห้อ/หน่วย/คุณสมบัติ (เช่น "แบบยาว") · '
         + 'มีหลาย IC = ตอนยกยอดต้องกรอกยอดแยกต่อ IC</div>';
    }
    h += '</span></div>';

    if (MG.problem) {
      var why = {multi: 'ผูกไว้หลายตัวสินค้า — ผิดกติกา 1 Mango มีตัวสินค้าได้ตัวเดียว (มติ 45)',
                 missing: 'ตัวสินค้าที่ผูกไม่มีในทะเบียน LLP แล้ว',
                 inactive: 'ตัวสินค้าที่ผูกถูกปิดใช้งานแล้ว'}[MG.problem] || MG.problem;
      h += '<div class="banner b-bad" style="margin:10px 0 0">' + esc(why) + ' — ออก IC ให้รหัสนี้ไม่ได้จนกว่าจะแก้ · '
         + (IS_ADMIN ? '<a href="' + esc(smLink(m.mat_code)) + '">แก้ที่หน้าจัดการรหัสวัสดุ ›</a>' : 'แจ้งผู้ดูแลระบบ (ADM)') + '</div>';
    }
    if (MG.stock.on_hand > 0 || MG.stock.pending > 0) {
      h += '<div class="banner b-warn" style="margin:10px 0 0;font-weight:400">ยอดยังค้างบนรหัส Mango: <b>'
         + fmtQ(MG.stock.on_hand) + ' ' + esc(m.unit) + '</b>' + (MG.stock.pending > 0 ? ' (จอง ' + fmtQ(MG.stock.pending) + ')' : '')
         + ' — มี IC แล้วต้องยกยอดไป IC ' + (IS_ADMIN ? 'ที่ <a href="' + esc(smLink(m.mat_code)) + '">หน้าจัดการรหัสวัสดุ ›</a>' : '(แจ้ง ADM)') + '</div>';
    }
    card.innerHTML = h;
    paintGate();
  }

  // ประตูข้อ 1-3: เปิดเมื่อมีรหัส Mango และออก IC ให้มันได้จริง
  function paintGate() {
    var on = SCHEMA_OK && canLadder(), g = $('gate');
    g.disabled = !on;
    g.classList.toggle('off', !on);
    var mat = MG ? esc(MG.mat.mat_code) : '';
    $('gateNote').innerHTML = !MG ? '🔒 เลือกรหัส Mango ในข้อ 0 ก่อน — ตัวสินค้าและหน่วยจะตั้งต้นตาม Mango ให้เอง'
      : MG.problem ? '🔒 ต้องแก้การผูกตัวสินค้าของ ' + mat + ' ก่อน (ดูข้อ 0)'
      : '🔒 ' + mat + ' ยังไม่ได้ผูกตัวสินค้า (LLP) — ให้ผู้ดูแลระบบ (ADM) ผูกก่อน (มติ 16)';

    var lk = lockedLlp(), ln = $('lockNote');
    ln.hidden = !lk;
    if (lk) {
      var n = mgIcs().length;
      ln.innerHTML = '🔒 ตัวสินค้าล็อกไว้ที่ <b>' + esc(lk) + '</b> · ' + esc(MG.llps[0].llp_name) + ' ตามที่ ' + mat + ' ผูกไว้ '
        + '— 1 Mango มีตัวสินค้าได้ตัวเดียว (มติ 45)'
        + (n ? ' · มี IC อยู่แล้ว ' + n + ' ตัว ตัวที่ออกจากตรงนี้จะเป็น <b>IC เพิ่ม</b> ของรหัสนี้' : '')
        + (IS_ADMIN ? ' · ตัวสินค้าผิด? <a href="' + esc(smLink(MG.mat.mat_code)) + '">เปลี่ยนที่หน้าจัดการรหัสวัสดุ ›</a>' : '');
    }
    $('llpFind').hidden = !(on && !lk);
    paintCode();
  }

  // ── ข้อ 1 (Mango ยังไม่ผูกตัวสินค้า): ค้นตัวสินค้าข้ามหมวดจากชื่อ Mango ─────────────
  // ตัดป้ายนำหน้าเช่น "(BPI)" แล้วใช้คำแรก — ชื่อ LLP เป็นชื่อกลาง ๆ ไม่มีขนาด/รุ่นติดมา
  function llpSeed(name) {
    var s = String(name || '').replace(/^\s*\([^)]*\)\s*/, '').trim();
    var w = s.split(/\s+/);
    return (w[0] || '').length >= 2 ? w[0] : w.slice(0, 2).join(' ');
  }
  var lfSeq = 0;
  function llpFind() {
    var q = $('llpFindQ').value.trim(), box = $('llpFindList');
    if (!q) { box.innerHTML = ''; return; }
    var my = ++lfSeq;
    box.innerHTML = '<div class="small pad">กำลังค้น…</div>';
    api(BASE + '/api/ic_api.php?a=search&q=' + encodeURIComponent(q))
      .then(function (j) {
        if (my !== lfSeq) { return; }
        var h = '';
        if ((j.llp || []).length) {
          h += '<div class="pick-sec">ตัวสินค้า (LLP) ที่ตรง "' + esc(q) + '"</div>';
          j.llp.forEach(function (r) {
            var set = !!(r.cat_id && r.char_id);
            h += '<div class="pk" data-llp="' + esc(r.llp_code) + '" role="button" tabindex="0"><span class="c">' + esc(r.llp_code) + '</span>'
               + '<span class="n">' + esc(r.llp_name) + ' <span class="small">· ' + esc(r.l1_name) + ' › ' + esc(r.l2_name) + '</span></span>'
               + '<span class="u' + (set ? '' : ' warn') + '">' + (set ? esc(r.cat_id + '/' + r.char_id) : 'ยังไม่ตั้ง Cat/Char') + '</span>'
               + '<span class="go">ใช้ ›</span></div>';
          });
          if (j.llp_more > 0) { h += '<div class="small pad">…และอีก ' + j.llp_more + ' ตัว — พิมพ์ให้เจาะจงขึ้น</div>'; }
        }
        if ((j.rows || []).length) {
          // IC ที่ออกไว้แล้ว — ของชิ้นเดียวกันอาจมีรหัสอยู่แล้วจาก Mango ตัวอื่น ผูกตัวเดิมดีกว่าออกซ้ำ
          h += '<div class="pick-sec">IC ที่ออกไว้แล้ว — กดเพื่อตั้งบันไดตามรหัสนั้น (ผูกตัวเดิม ไม่ออกซ้ำ)</div>';
          j.rows.forEach(function (r) {
            h += '<div class="pk" data-ic="' + esc(r.ic_code) + '" role="button" tabindex="0"><span class="c">' + esc(r.ic_code) + '</span>'
               + '<span class="n">' + esc(r.ic_name) + '</span><span class="u">' + esc(r.unit_name) + '</span><span class="go">ใช้ ›</span></div>';
          });
        }
        box.innerHTML = h || '<div class="small pad">ไม่พบตัวสินค้าที่ตรง "' + esc(q) + '" — ลองคำสั้นลง หรือไล่ L1 → L2 ด้านล่าง / กด + เพิ่มตัวสินค้าใหม่</div>';
      })
      .catch(function (e) { if (my === lfSeq) { box.innerHTML = '<div class="pad" style="color:var(--red)">' + esc(e.message) + '</div>'; } });
  }
  function partsOf(ic) {
    return {l1: ic.substr(0, 3), l2: ic.substr(3, 2), llp: ic.substr(0, 8),
            size: ic.substr(8, 3), brand: ic.substr(11, 3), unit: ic.substr(14, 3), extra: ic.substr(17, 3)};
  }

  // ── เพิ่มชั้นบันได ────────────────────────────────────────────────────────
  function toggleAdd(box, on) {
    var el = $('add' + box);
    if (!el) { return; }
    el.hidden = on === undefined ? !el.hidden : !on;
    if (!el.hidden) { var i = el.querySelector('input'); if (i) { i.focus(); } }
  }
  var CLEAR = ['newL1Code','newL1Name','newL2Code','newL2Name','newLlpName','newSizeCode','newSizeName',
               'newBrandCode','newBrandName','newUnitCode','newUnitName','newExtraName'];
  function addNode(kind) {
    var body;
    if (kind === 'l1') {
      body = {a: 'add_l1', code: $('newL1Code').value, name: $('newL1Name').value};
    } else if (kind === 'l2') {
      if (!picked.l1) { flash('เลือกกลุ่มหลัก L1 ก่อน', 'bad'); return; }
      body = {a: 'add_l2', l1: picked.l1, code: $('newL2Code').value, name: $('newL2Name').value};
    } else if (kind === 'llp') {
      if (!picked.l2) { flash('เลือกหมวด L2 ก่อน', 'bad'); return; }
      body = {a: 'add_llp', l1: picked.l1, l2: picked.l2, name: $('newLlpName').value};
    } else if (kind === 'size' || kind === 'brand') {
      if (!picked.l2) { flash('เลือกหมวด L2 ก่อน — ขนาด/ยี่ห้อผูกกับหมวด', 'bad'); return; }
      var pre = kind === 'size' ? 'Size' : 'Brand';
      body = {a: 'add_' + kind, l1: picked.l1, l2: picked.l2,
              code: $('new' + pre + 'Code').value, name: $('new' + pre + 'Name').value};
    } else if (kind === 'unit') {
      body = {a: 'add_unit', code: $('newUnitCode').value, name: $('newUnitName').value};
    } else if (kind === 'extra') {
      if (!picked.llp) { flash('เลือกตัวสินค้าก่อน — คุณสมบัติเพิ่มผูกกับตัวสินค้า', 'bad'); return; }
      body = {a: 'add_extra', llp: picked.llp, name: $('newExtraName').value};
    } else { return; }

    api(BASE + '/api/ic_api.php', form(body))
      .then(function (j) {
        CLEAR.forEach(function (id) { var el = $(id); if (el) { el.value = ''; } });
        toggleAdd(kind.charAt(0).toUpperCase() + kind.slice(1), false);
        var after = {};
        if (kind === 'l1')    { after = {l1: j.code, l2: '', llp: '', extra: NONE}; }
        if (kind === 'l2')    { after = {l2: j.code, llp: '', extra: NONE}; }
        if (kind === 'llp')   { after = {llp: j.code, extra: NONE}; nameTouched = false; }
        if (kind === 'size')  { after = {size: j.code}; nameTouched = false; }
        if (kind === 'brand') { after = {brand: j.code}; nameTouched = false; }
        if (kind === 'unit')  { after = {unit: j.code}; }
        if (kind === 'extra') { after = {extra: j.code}; nameTouched = false; }
        flash((j.existed ? 'มีอยู่แล้ว — ใช้ตัวเดิม ' : 'เพิ่ม ') + esc(j.code) + ' · ' + esc(j.name), 'ok');
        return load(after);
      })
      .catch(function (e) { flash(esc(e.message), 'bad'); });
  }

  // ── สร้างรหัส + ผูกกลับ Mango (ทรานแซกชันเดียวฝั่งเซิร์ฟเวอร์ · มติ 47) ─────────────
  function create() {
    if (!MG || !canLadder() || !steps || !steps.complete) { return; }
    var cc = steps.charcat || {};
    var cat = getCat(), chr = $('fChar').value;
    var needSet = IS_ADMIN && (!cc.is_set || cc.cat_id !== cat || cc.char_id !== chr);
    var mat = MG.mat.mat_code, n = mgIcs().length, warn = [];

    // ถามก่อนเฉพาะเรื่องที่มีผลข้างเคียง — กรณีปกติ (ผูกตัวสินค้าแล้ว · IC แรก · หน่วยตรง) กดแล้วออกเลย
    if (!lockedLlp()) { warn.push('ผูก ' + mat + ' เข้ากับตัวสินค้า ' + picked.llp + ' — 1 Mango มีตัวสินค้าได้ตัวเดียว (เปลี่ยนภายหลังที่หน้าจัดการรหัสวัสดุ)'); }
    if (needSet && cc.is_set) { warn.push('แก้ CatID/CharID ของ ' + picked.llp + ' เป็น ' + cat + '/' + chr + ' — IC ทุกตัวใต้มันเปลี่ยนตาม'); }
    if (n > 0) { warn.push('จะเป็น IC ตัวที่ ' + (n + 1) + ' ของ ' + mat + ' — ตอนยกยอดต้องกรอกยอดแยกต่อ IC'); }
    if (unitDiffers()) { warn.push('หน่วย ' + unitName(picked.unit) + ' ต่างจากหน่วยของ Mango (' + MG.mat.unit + ') — ยกยอดจะย้ายจำนวนตรง ๆ ไม่แปลงหน่วย'); }
    if (warn.length && !confirm((steps.exists ? 'ผูกรหัส ' : 'ออกรหัส ') + steps.ic_code + ' ให้ ' + mat
                                + '\n\n• ' + warn.join('\n• ') + '\n\nยืนยันไหม?')) { return; }

    $('goCreate').disabled = true;
    var llp = picked.llp;
    api(BASE + '/api/ic_api.php', form({
      a: 'create_for_mango', mat: mat, llp: llp, size: picked.size, brand: picked.brand,
      unit: picked.unit, extra: picked.extra, ic_name: $('fName').value,
      has_serial: $('fSerial').checked ? 1 : 0, is_cx: $('fCx').checked ? 1 : 0,
      cat_id: needSet ? cat : '', char_id: needSet ? chr : ''
    }))
    .then(function (j) {
      if (j.mango) { MG = j.mango; paintMango(); }
      showResult(j);
      var msg = (j.created ? '✓ สร้างรหัส ' : '🔗 ใช้รหัสเดิม ') + esc(j.ic_code) + ' · ' + esc(j.ic_name) + ' → ผูกกับ ' + esc(mat) + ' แล้ว';
      if (j.mapped_llp) { msg += ' · ผูกตัวสินค้า ' + esc(llp) + ' ให้ด้วย'; }
      if (j.charcat && j.charcat.changed && j.charcat.ic > 1) {
        msg += ' · Cat/Char ของ ' + esc(llp) + ' เปลี่ยน — IC ใต้มัน ' + j.charcat.ic + ' รหัสเปลี่ยนตาม';
      }
      flash(msg, 'ok');
      ccTouched = false;
      return load({});
    })
    .catch(function (e) { flash(esc(e.message), 'bad'); paintCode(); });
  }

  function showResult(j) {
    var c = $('resultCard'), m = MG ? MG.mat : {mat_code: '', name: ''}, n = mgIcs().length;
    c.hidden = false;
    $('resultBody').innerHTML =
        '<div class="fullcode ready mono" style="margin-bottom:9px">' + esc(j.ic_code) + '</div>'
      + '<div class="res-line"><b>ชื่อ</b><span>' + esc(j.ic_name) + '</span></div>'
      + '<div class="res-line"><b>หน่วยเก็บ</b><span>' + esc(j.unit) + '</span></div>'
      + '<div class="res-line"><b>สถานะ</b><span>' + (j.created ? 'สร้างใหม่' : 'มีอยู่แล้ว — ใช้ตัวเดิม ไม่สร้างซ้ำ') + '</span></div>'
      + '<div class="res-line"><b>Mango</b><span><span class="mono">' + esc(m.mat_code) + '</span> ' + esc(m.name)
      + ' — ผูกแล้ว' + (n > 1 ? ' (IC ตัวที่ ' + n + ' ของรหัสนี้)' : ' (IC หลัก)')
      + (j.mapped_llp ? ' · ผูกตัวสินค้าให้ด้วย' : '') + '</span></div>'
      + '<p class="small" style="margin-top:9px">สร้างแถวคู่ใน <span class="mono">materials</span> ให้แล้ว (มติ 3) — '
      + 'ใช้รับเข้า/เบิก/QR/หักเงินได้ทันที'
      + (MG && MG.stock.on_hand > 0
          ? ' · ยอดเดิมยังอยู่บนรหัส Mango — ' + (IS_ADMIN ? '<a href="' + esc(smLink(m.mat_code)) + '">ยกยอดที่หน้าจัดการรหัสวัสดุ ›</a>' : 'แจ้ง ADM ยกยอด')
          : '')
      + '</p>';
    try { c.scrollIntoView({behavior: 'smooth', block: 'nearest'}); } catch (e) {}
  }

  // ── events ────────────────────────────────────────────────────────────────
  $('fL1').addEventListener('change',    function () { ccTouched = false; load({l1: this.value, l2: '', llp: '', extra: NONE}); });
  $('fL2').addEventListener('change',    function () { ccTouched = false; load({l2: this.value, llp: '', extra: NONE}); });
  $('fLlp').addEventListener('change',   function () { ccTouched = false; nameTouched = false; load({llp: this.value, extra: NONE}); });
  $('fSize').addEventListener('change',  function () { nameTouched = false; load({size: this.value || NONE}); });
  $('fBrand').addEventListener('change', function () { nameTouched = false; load({brand: this.value || NONE}); });
  $('fUnit').addEventListener('change',  function () { load({unit: this.value}); });
  $('fExtra').addEventListener('change', function () { nameTouched = false; load({extra: this.value || NONE}); });
  $('fName').addEventListener('input',   function () { nameTouched = true; });
  $('fChar').addEventListener('change',  function () { ccTouched = true; });
  document.querySelectorAll('#fCat input').forEach(function (r) { r.addEventListener('change', function () { ccTouched = true; }); });
  var qT = null;
  $('fLlpQ').addEventListener('input', function () { clearTimeout(qT); qT = setTimeout(function () { load({}); }, 250); });

  var mgT = null;
  $('mgQ').addEventListener('input', function () { clearTimeout(mgT); mgT = setTimeout(function () { mgSearch(false); }, 300); });
  $('mgGo').addEventListener('click', function () { clearTimeout(mgT); mgSearch(true); });
  $('llpFindGo').addEventListener('click', llpFind);
  $('useMgName').addEventListener('click', function () {
    if (!MG) { return; }
    $('fName').value = MG.mat.name || '';
    nameTouched = true;
  });

  function pickRow(el) {
    if (el.dataset.mat) { mgPick(el.dataset.mat); return; }
    if (el.dataset.llp) {
      var c = el.dataset.llp;
      ccTouched = false; nameTouched = false;
      load({l1: c.substr(0, 3), l2: c.substr(3, 2), llp: c, extra: NONE});
      return;
    }
    if (el.dataset.ic) { ccTouched = false; nameTouched = false; load(partsOf(el.dataset.ic)); }
  }

  document.addEventListener('click', function (ev) {
    var pk = ev.target.closest('.pk');
    if (pk) { pickRow(pk); return; }
    if (ev.target.closest('#mgChange')) { mgClear(); return; }
    var bs = ev.target.closest('[data-base]');
    if (bs) {
      // IC ของ Mango นี้เป็นแม่แบบ — ได้ขนาด/ยี่ห้อ/หน่วยเดิม เหลือเลือกคุณสมบัติที่ต่าง (เช่น "แบบยาว")
      var p = partsOf(bs.dataset.base);
      nameTouched = false;
      load({size: p.size, brand: p.brand, unit: p.unit, extra: NONE});
      return;
    }
    var a = ev.target.closest('[data-add]');
    if (a) {
      toggleAdd(a.dataset.add);
      // ตัวสินค้าใหม่ของ Mango ที่ยังไม่ผูก — ตั้งต้นชื่อจาก Mango (แก้ให้เป็นชื่อกลาง ๆ ไม่มีขนาด/รุ่นได้)
      if (a.dataset.add === 'Llp' && MG && !$('addLlp').hidden && !$('newLlpName').value) { $('newLlpName').value = MG.mat.name || ''; }
      return;
    }
    var c = ev.target.closest('[data-cancel]');
    if (c) { toggleAdd(c.dataset.cancel, false); return; }
    var g = ev.target.closest('[data-go]');
    if (g) { addNode(g.dataset.go); return; }
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Enter' && ev.keyCode !== 13 && ev.key !== ' ') { return; }
    var t = ev.target;
    if (t.classList && t.classList.contains('pk')) { ev.preventDefault(); pickRow(t); return; }
    if (ev.key === ' ') { return; }
    if (t.id === 'mgQ')      { ev.preventDefault(); clearTimeout(mgT); mgSearch(true); return; }
    if (t.id === 'llpFindQ') { ev.preventDefault(); llpFind(); return; }
    var box = t.closest ? t.closest('.addrow') : null;
    if (!box) { return; }
    ev.preventDefault();
    var btn = box.querySelector('[data-go]');
    if (btn) { addNode(btn.dataset.go); }
  });
  $('goCreate').addEventListener('click', create);
  $('goReset').addEventListener('click', function () {
    nameTouched = false; ccTouched = false;
    $('fName').value = ''; $('fLlpQ').value = '';
    $('fSerial').checked = false; $('fCx').checked = true;
    setCat('C02'); $('fChar').value = 'CSB';
    $('resultCard').hidden = true;
    if (MG) { applyDefaults(); } else { picked = Object.assign({}, BLANK); load(BLANK); }
  });

  paintMango();
  if (SCHEMA_OK && INIT_MAT) {
    $('mgQ').value = INIT_MAT;
    mgPick(INIT_MAT).then(function () { if (!MG) { mgSearch(false); load({}); } });
  } else {
    load({});
    if (SCHEMA_OK && !EMBED) { try { $('mgQ').focus(); } catch (e) {} }
  }
})();
</script>

<?php
uiFoot('ต้องเริ่มจากรหัส Mango เสมอ — ออกแล้วผูกกลับ Mango ให้อัตโนมัติ (มติ 47) · 1 Mango มีตัวสินค้าได้ตัวเดียว (มติ 45) · '
     . 'ขนาด/ยี่ห้อกรองด้วย l2_sizes/l2_brands (FK บังคับใน DB) · CatID/CharID เป็นของตัวสินค้า (LLP) — มติ 28-29 · '
     . 'ธง has_serial/is_cx เก็บค่าไว้ก่อน ยังไม่มีสายงานไหนอ่านไปใช้');
