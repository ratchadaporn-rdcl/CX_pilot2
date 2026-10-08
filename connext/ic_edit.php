<?php
/**
 * CONNEXT — ic_edit.php : แก้ไข IC ที่ออกไปแล้ว (มติ 46) — ใช้ได้ทั้งก่อนและหลังย้ายยอด
 *
 *   ข้อ 2 ข้อมูลทั่วไป     ชื่อ · Serial · CX · เปิด/ปิดใช้งาน — รหัสไม่เปลี่ยน
 *   ข้อ 3 เปลี่ยนสเปก      ตัวสินค้า/ขนาด/ยี่ห้อ/หน่วย/คุณสมบัติ = รหัสใหม่ → ย้ายยอด เอกสาร การผูก Mango ไปทั้งก้อน
 *                          แล้วปิดตัวเดิม (ปลายทางมีอยู่แล้ว = รวม) · logic อยู่ lib/ic_edit.php
 *
 * เข้าจาก: ทะเบียน IC (ic_list.php) ปุ่ม ✏️ · หน้าจัดการรหัสวัสดุ (setup_master.php) ปุ่ม ✏️ ข้าง IC
 * ADM (R0) เท่านั้น — งานแตะ master ตามมติ 16 · PHP 7.4-compatible เท่านั้น
 */

require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/ic_edit.php';

$user = uiGuard();
if (!uiIsAdmin($user)) {
    http_response_code(403);
    if (uiIsEmbedded()) { uiEmbedNotice('หน้าแก้ไข IC สงวนไว้สำหรับผู้ดูแลระบบ (ADM)', false); }
    echo '<meta charset="utf-8"><p style="font-family:sans-serif;padding:24px">หน้าแก้ไข IC สงวนไว้สำหรับผู้ดูแลระบบ (ADM)</p>';
    exit;
}

$ic     = strtoupper(preg_replace('/\s+/u', '', (string)($_REQUEST['ic'] ?? '')));
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$notice = null;
if (isset($_GET['moved'])) {
    $notice = ['ok', 'ย้าย ' . strtoupper((string)$_GET['moved']) . ' มาเป็นรหัสนี้แล้ว — ตัวเดิมถูกปิดใช้งาน'];
}

if ($isPost) {
    if (!hash_equals(csrfToken(), (string)($_POST['csrf'] ?? ''))) {
        $notice = ['bad', 'CSRF token ไม่ถูกต้อง — โหลดหน้าใหม่แล้วลองอีกครั้ง'];
    } elseif (($_POST['action'] ?? '') === 'save_info') {
        $r = iceUpdateInfo($pdo, $ic, [
            'ic_name'    => (string)($_POST['ic_name'] ?? ''),
            'has_serial' => isset($_POST['has_serial']) ? 1 : 0,
            'is_cx'      => isset($_POST['is_cx']) ? 1 : 0,
            'is_active'  => isset($_POST['is_active']) ? 1 : 0,
        ], $user);
        $notice = !$r['ok'] ? ['bad', $r['error']]
                : ($r['changed'] ? ['ok', 'บันทึกแล้ว (' . implode(', ', $r['changed']) . ')'] : ['warn', 'ไม่มีอะไรเปลี่ยน']);
    } elseif (($_POST['action'] ?? '') === 'recode') {
        $r = iceRecode($pdo, $ic, [
            'llp_code'   => (string)($_POST['llp'] ?? ''),
            'size_code'  => (string)($_POST['size'] ?? IC_NONE),
            'brand_code' => (string)($_POST['brand'] ?? IC_NONE),
            'unit_code'  => (string)($_POST['unit'] ?? ''),
            'extra_code' => (string)($_POST['extra'] ?? IC_NONE),
        ], trim((string)($_POST['new_name'] ?? '')), $user);
        if ($r['ok']) {
            header('Location: ' . uiUrl(APP_BASE . '/ic_edit.php?ic=' . urlencode($r['ic_code']) . '&moved=' . urlencode($ic)));
            exit;
        }
        $notice = ['bad', $r['error']];
    }
}

$u = $ic !== '' ? iceUsage($pdo, $ic) : null;
$catLabels  = icCatLabels();
$charLabels = icCharLabels();

uiHead('แก้ไข IC', 'แก้ชื่อ/ธง หรือเปลี่ยนสเปก (ย้ายยอดไปรหัสใหม่ให้)', $user, '✏️', uiIsEmbedded());
?>

<?php if ($notice !== null): ?>
  <div class="banner b-<?= $notice[0] === 'ok' ? 'ok' : ($notice[0] === 'warn' ? 'warn' : 'bad') ?>"><?= e($notice[1]) ?></div>
<?php endif; ?>

<div class="card">
  <form method="get" class="row" style="align-items:flex-end">
    <?php if (uiIsEmbedded()): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
    <div style="flex:1;min-width:230px">
      <label class="fld">รหัส IC (20 หลัก)</label>
      <input type="text" name="ic" value="<?= e($ic) ?>" class="mono" style="width:100%" placeholder="เช่น MRO06029120000026000">
    </div>
    <div><button type="submit">เปิด</button></div>
    <div><a href="<?= uiUrl(APP_BASE . '/ic_list.php') ?>"><button type="button" class="ghost">📒 ทะเบียน IC</button></a></div>
    <div><a href="<?= uiUrl(APP_BASE . '/setup_master.php') ?>"><button type="button" class="ghost">🗂️ จัดการรหัสวัสดุ (Mango → LLP)</button></a></div>
  </form>
</div>

<?php if ($u === null): ?>
  <?php $rn = $ic !== '' ? iceRenumbered($pdo, $ic) : null; ?>
  <?php if ($rn !== null): ?>
    <div class="banner b-warn">รหัส <?= e($ic) ?> ถูกเปลี่ยนเลขเป็น
      <a class="mono" href="<?= uiUrl(APP_BASE . '/ic_edit.php?ic=' . urlencode($rn['ic'])) ?>"><?= e($rn['ic']) ?></a>
      แล้ว (จัดเลขตาม Flow Hub <?= e(substr($rn['at'], 0, 16)) ?>) — ยอดและเอกสารตามไปที่รหัสใหม่ทั้งหมด</div>
  <?php elseif ($ic !== ''): ?><div class="banner b-bad">ไม่พบรหัส IC <?= e($ic) ?></div><?php endif; ?>
<?php uiFoot(); exit; endif;
$r = $u['ic'];
$active = (int)$r['is_active'] === 1;
$mv     = $active ? null : (iceMovedTo($pdo, [$u['material_id']])[$u['material_id']] ?? null);
$cat  = icNormalizeCat($r['cat_id']);
$char = icNormalizeChar($r['char_id']);
?>

<div class="card">
  <h2><span class="num">1</span> <span class="mono"><?= e((string)$r['ic_code']) ?></span>
    <?php if (!$active): ?><span class="pill p-muted">ปิดใช้งาน</span><?php endif; ?>
    <span class="sp"><?= e((string)$r['l1_name']) ?> › <?= e((string)$r['l2_name']) ?></span>
  </h2>
  <div style="font-size:1.05rem;font-weight:600;margin-bottom:8px"><?= e((string)$r['ic_name']) ?></div>
  <?php if ($mv !== null): ?>
    <div class="banner b-warn" style="font-weight:400">
      รหัสนี้ถูกปิดเพราะเปลี่ยนสเปก — ยอด/เอกสาร/การผูกย้ายไปที่
      <a class="mono" href="<?= uiUrl(APP_BASE . '/ic_edit.php?ic=' . urlencode($mv['ic'])) ?>"><b><?= e($mv['ic']) ?></b></a>
      แล้ว (<?= e(substr($mv['at'], 0, 16)) ?>) · เก็บตัวนี้ไว้เป็นประวัติ ไม่ต้องลบ — ระบบไม่เอาไปใช้เบิก/ค้น/ผูกอีก
    </div>
  <?php elseif (!$active): ?>
    <div class="banner b-warn" style="font-weight:400">ปิดใช้งานอยู่ — ไม่ขึ้นในการค้น/ผูก/รับของจาก PO · เปิดกลับได้ที่ข้อ 2</div>
  <?php endif; ?>
  <div class="scroll">
    <table>
      <tr><th>ชิ้นรหัส</th><th>รหัส</th><th>ชื่อ</th></tr>
      <tr><td>ตัวสินค้า (LLP)</td><td class="mono"><?= e((string)$r['llp_code']) ?></td>
          <td><?= e((string)$r['llp_name']) ?> <span class="small">· IC ใต้ตัวสินค้านี้ <?= (int)$u['llp_ic_count'] ?> ตัว</span></td></tr>
      <tr><td>ขนาด</td><td class="mono"><?= e((string)$r['size_code']) ?></td><td><?= (string)$r['size_code'] === IC_NONE ? '—' : e((string)$r['size_name']) ?></td></tr>
      <tr><td>ยี่ห้อ</td><td class="mono"><?= e((string)$r['brand_code']) ?></td><td><?= (string)$r['brand_code'] === IC_NONE ? '—' : e((string)$r['brand_name']) ?></td></tr>
      <tr><td>หน่วยเก็บ</td><td class="mono"><?= e((string)$r['unit_code']) ?></td><td><?= e((string)$r['unit_name']) ?></td></tr>
      <tr><td>คุณสมบัติเพิ่ม</td><td class="mono"><?= e((string)$r['extra_code']) ?></td><td><?= (string)$r['extra_code'] === IC_NONE ? '—' : e($u['extra_name']) ?></td></tr>
      <tr><td>CatID / CharID</td><td colspan="2">
          <?php if ($cat !== null): ?><span class="pill <?= $cat === 'C01' ? 'p-warn' : 'p-muted' ?>"><?= e($cat) ?></span><?php endif; ?>
          <?php if ($char !== null): ?><span class="pill p-muted"><?= e($char) ?></span><?php endif; ?>
          <span class="small">เป็นของตัวสินค้า (มติ 28-29) — แก้ที่
            <a href="<?= uiUrl(APP_BASE . '/llp_master.php?q=' . urlencode((string)$r['llp_code'])) ?>">ตั้งค่าตัวสินค้า (LLP)</a> มีผลกับ IC ทุกตัวใต้มัน</span></td></tr>
    </table>
  </div>

  <div class="row" style="margin-top:11px">
    <div class="stat"><b><?= fmtQ($u['on_hand']) ?></b><span>คงเหลือรวม<?= $u['pending'] > 0 ? '<br>(จอง ' . fmtQ($u['pending']) . ')' : '' ?></span></div>
    <div class="stat"><b><?= number_format($u['n_doc']) ?></b><span>บรรทัดเอกสาร</span></div>
    <div class="stat"><b><?= number_format($u['n_pm']) ?></b><span>อยู่ในวัสดุโครงการ</span></div>
    <div class="stat"><b><?= number_format(count($u['mango'])) ?></b><span>รหัส Mango ที่ผูก</span></div>
    <?php if ($u['n_push'] > 0): ?><div class="stat"><b><?= number_format($u['n_push']) ?></b><span>รับจาก PO</span></div><?php endif; ?>
  </div>
  <?php if ($u['stock']): ?>
    <p class="small" style="margin-top:6px">ยอดรายโครงการ:
      <?php foreach ($u['stock'] as $s): ?>
        <b><?= e((string)$s['code']) ?></b> <?= fmtQ($s['on_hand']) ?> <?= e((string)$r['unit_name']) ?> ·
      <?php endforeach; ?>
    </p>
  <?php endif; ?>
  <?php if ($u['mango']): ?>
    <p class="small">ผูกกับรหัส Mango:
      <?php foreach ($u['mango'] as $m): ?>
        <a class="mono" href="<?= uiUrl(APP_BASE . '/setup_master.php?q=' . urlencode((string)$m['mat_code']) . '&scope=all') ?>"><?= e((string)$m['mat_code']) ?></a>
        <?= e((string)$m['name']) ?> (<?= e((string)$m['unit']) ?>)<?= (int)$m['n_ic'] > 1 ? ' · มี IC ' . (int)$m['n_ic'] . ' ตัว' : '' ?> ·
      <?php endforeach; ?>
    </p>
  <?php endif; ?>
</div>

<div class="card">
  <h2><span class="num">2</span> ข้อมูลทั่วไป <span class="sp">รหัสไม่เปลี่ยน — ยอด/เอกสารอยู่ที่เดิม</span></h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="action" value="save_info">
    <input type="hidden" name="ic" value="<?= e((string)$r['ic_code']) ?>">
    <label class="fld">ชื่อ IC (ชื่อที่เห็นในฟอร์มเบิก/รายงาน)</label>
    <input type="text" name="ic_name" value="<?= e((string)$r['ic_name']) ?>" maxlength="255" style="width:100%" required>
    <div class="row" style="margin-top:10px">
      <label><input type="checkbox" name="has_serial" value="1" <?= (int)($r['has_serial'] ?? 0) === 1 ? 'checked' : '' ?>> มี Serial Number (นับเป็นชิ้น)</label>
      <label><input type="checkbox" name="is_cx" value="1" <?= (int)($r['is_cx'] ?? 1) === 1 ? 'checked' : '' ?>> อยู่ในระบบเบิกจ่าย CX (ไม่ติ๊ก = วัสดุ WC)</label>
      <label><input type="checkbox" name="is_active" value="1" <?= $active ? 'checked' : '' ?>> ใช้งานอยู่</label>
    </div>
    <p class="small" style="margin-top:6px">ปิดใช้งานได้เมื่อไม่มียอด/ยอดจองและไม่อยู่ในวัสดุของโครงการแล้ว ·
      Serial/CX ตอนนี้เก็บค่าอย่างเดียว ยังไม่มีสายงานไหนอ่านไปใช้ (มติ 43)</p>
    <div style="margin-top:10px"><button type="submit">💾 บันทึก</button></div>
  </form>
</div>

<div class="card" id="recode">
  <h2><span class="num">3</span> เปลี่ยนสเปก / หน่วย / ตัวสินค้า
    <span class="sp">ชิ้นรหัสเปลี่ยน = รหัสใหม่ · ย้ายยอด เอกสาร การผูก Mango ไปให้ทั้งก้อน แล้วปิดตัวเดิม</span>
  </h2>
  <form method="post" id="rcForm">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="action" value="recode">
    <input type="hidden" name="ic" value="<?= e((string)$r['ic_code']) ?>">

    <div class="row" style="align-items:flex-end">
      <div><label class="fld">ตัวสินค้า (LLP)</label>
        <input type="text" name="llp" id="rcLlp" value="<?= e((string)$r['llp_code']) ?>" class="mono" style="width:130px" maxlength="8"></div>
      <div style="flex:1;min-width:200px"><label class="fld">ค้นตัวสินค้าอื่น (ถ้าจะย้ายไปตัวสินค้าอื่น)</label>
        <input type="text" id="rcLlpQ" placeholder="พิมพ์ชื่อ แล้วกด Enter" style="width:100%" autocomplete="off"></div>
      <div><button type="button" class="ghost" id="rcLlpFind">ค้นหา</button></div>
    </div>
    <div id="rcLlpRes" class="small" style="margin-top:6px"></div>
    <details style="margin-top:6px">
      <summary class="small">ไม่มีตัวที่ถูก — สร้างตัวสินค้าใหม่</summary>
      <div class="row" style="margin-top:8px;align-items:flex-end">
        <div><label class="fld">กลุ่มใหญ่ (L1)</label><select id="nlL1" style="min-width:150px"></select></div>
        <div><label class="fld">หมวด (L2)</label><select id="nlL2" style="min-width:230px"></select></div>
        <div style="flex:1;min-width:170px"><label class="fld">ชื่อตัวสินค้า</label><input type="text" id="nlName" style="width:100%" maxlength="255"></div>
        <div><button type="button" class="mini" id="nlGo">＋ สร้างแล้วใช้</button></div>
      </div>
    </details>

    <div class="row" style="margin-top:10px;align-items:flex-start">
      <div style="min-width:170px;flex:1"><label class="fld">ขนาด</label><select name="size" id="rcSize" style="width:100%"></select>
        <div class="row" style="gap:5px;margin-top:4px"><input type="text" id="rcSizeNew" placeholder="เพิ่มขนาด…" style="flex:1;min-width:80px"><button type="button" class="mini ghost" data-add="size">+</button></div></div>
      <div style="min-width:170px;flex:1"><label class="fld">ยี่ห้อ</label><select name="brand" id="rcBrand" style="width:100%"></select>
        <div class="row" style="gap:5px;margin-top:4px"><input type="text" id="rcBrandNew" placeholder="เพิ่มยี่ห้อ…" style="flex:1;min-width:80px"><button type="button" class="mini ghost" data-add="brand">+</button></div></div>
      <div style="min-width:150px;flex:1"><label class="fld">หน่วยเก็บ</label><select name="unit" id="rcUnit" style="width:100%"></select>
        <div class="row" style="gap:5px;margin-top:4px"><input type="text" id="rcUnitNew" placeholder="เพิ่มหน่วย…" style="flex:1;min-width:80px" maxlength="50"><button type="button" class="mini ghost" data-add="unit">+</button></div></div>
      <div style="min-width:190px;flex:1"><label class="fld">คุณสมบัติเพิ่ม</label><select name="extra" id="rcExtra" style="width:100%"></select>
        <div class="row" style="gap:5px;margin-top:4px"><input type="text" id="rcExtraNew" placeholder="เพิ่มคุณสมบัติ…" style="flex:1;min-width:80px"><button type="button" class="mini ghost" data-add="extra">+</button></div></div>
    </div>

    <div class="row" style="margin-top:10px;align-items:flex-end">
      <div style="flex:1;min-width:230px"><label class="fld">ชื่อ IC ใหม่ (ถ้ารหัสปลายทางมีอยู่แล้ว ใช้ชื่อของตัวนั้น)</label>
        <input type="text" name="new_name" id="rcName" value="<?= e((string)$r['ic_name']) ?>" maxlength="255" style="width:100%"></div>
      <div><label class="fld">รหัสที่จะได้</label><div class="mono" id="rcCode" style="font-weight:700;font-size:1rem">—</div></div>
    </div>
    <div id="rcInfo" class="small" style="margin-top:8px"></div>
    <div style="margin-top:10px"><button type="submit" id="rcGo" disabled>🔀 ย้ายไปรหัสนี้</button></div>
  </form>
</div>

<style>
.stat{background:#f1f5f9;border-radius:9px;padding:9px 15px;min-width:104px}.stat b{display:block;font-size:1.2rem;line-height:1.2}.stat span{font-size:.72rem;color:#64748b;line-height:1.35;display:block}
.llpr{display:flex;gap:9px;align-items:center;padding:5px 8px;border-bottom:1px solid var(--line);cursor:pointer}.llpr:hover{background:var(--blue-bg)}
.llpr .c{font-family:ui-monospace,Consolas,monospace;font-weight:700;color:var(--navy-2)}
</style>

<script>
(function () {
  'use strict';
  var BASE = <?= json_encode(APP_BASE) ?>;
  var CSRF = <?= json_encode(csrfToken()) ?>;
  var CUR  = <?= json_encode([
      'ic' => (string)$r['ic_code'], 'llp' => (string)$r['llp_code'], 'size' => (string)$r['size_code'],
      'brand' => (string)$r['brand_code'], 'unit' => (string)$r['unit_code'], 'extra' => (string)$r['extra_code'],
      'unit_name' => (string)$r['unit_name'], 'on_hand' => $u['on_hand'], 'n_doc' => $u['n_doc'],
      'n_mango' => count($u['mango']), 'multi' => array_values(array_filter(array_map(function ($m) {
          return (int)$m['n_ic'] > 1 ? (string)$m['mat_code'] : null; }, $u['mango']))),
  ], JSON_UNESCAPED_UNICODE) ?>;
  var L1S = <?= json_encode(icL1List($pdo), JSON_UNESCAPED_UNICODE) ?>;
  var CAT_LABELS  = <?= json_encode($catLabels, JSON_UNESCAPED_UNICODE) ?>;
  var CHAR_LABELS = <?= json_encode($charLabels, JSON_UNESCAPED_UNICODE) ?>;
  var NONE = '000';
  var $ = function (id) { return document.getElementById(id); };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function api(url, opts) {
    return fetch(url, Object.assign({credentials: 'same-origin'}, opts || {}))
      .then(function (r) { return r.json().catch(function () { throw new Error('เซิร์ฟเวอร์ตอบไม่ใช่ JSON (HTTP ' + r.status + ')'); }); })
      .then(function (j) { if (!j.ok) { throw new Error(j.error || 'ผิดพลาด'); } return j; });
  }
  function post(obj) { var b = new URLSearchParams(); b.set('csrf', CSRF); Object.keys(obj).forEach(function (k) { b.set(k, obj[k]); }); return {method: 'POST', body: b}; }
  function info(kind, html) { var d = $('rcInfo'); d.style.color = kind === 'bad' ? 'var(--red)' : ''; d.innerHTML = html; }

  var picked = {llp: CUR.llp, size: CUR.size, brand: CUR.brand, unit: CUR.unit, extra: CUR.extra};
  var last = null, seq = 0;
  function fill(sel, rows, k, n, val) {
    var h = '';
    rows.forEach(function (r) { h += '<option value="' + esc(r[k]) + '"' + (String(r[k]) === String(val) ? ' selected' : '') + '>' + esc(r[k]) + ' · ' + esc(r[n]) + '</option>'; });
    sel.innerHTML = h;
  }
  function load(over) {
    Object.assign(picked, over || {});
    var my = ++seq;
    return api(BASE + '/api/ic_api.php?a=steps&' + new URLSearchParams(picked).toString())
      .then(function (j) {
        if (my !== seq) { return; }
        last = j; picked = {llp: j.picked.llp, size: j.picked.size, brand: j.picked.brand, unit: j.picked.unit, extra: j.picked.extra};
        fill($('rcSize'),  j.size,  'size_code',  'size_name',  picked.size);
        fill($('rcBrand'), j.brand, 'brand_code', 'brand_name', picked.brand);
        fill($('rcUnit'),  j.unit,  'unit_code',  'unit_name',  picked.unit);
        fill($('rcExtra'), [{extra_code: NONE, extra_name: 'ไม่มี'}].concat(j.extra || []), 'extra_code', 'extra_name', picked.extra);
        paint();
      })
      .catch(function (e) { info('bad', esc(e.message)); });
  }
  function paint() {
    var j = last, go = $('rcGo');
    $('rcCode').textContent = j && j.ic_code ? j.ic_code : '—';
    go.disabled = true;
    if (!j || !j.picked.llp) { info('bad', 'ไม่พบตัวสินค้า ' + esc($('rcLlp').value)); return; }
    if (!j.complete) { info('', 'เลือกหน่วยเก็บ'); return; }
    if (j.ic_code === CUR.ic) { info('', 'ยังเป็นรหัสเดิม — เปลี่ยนขนาด/ยี่ห้อ/หน่วย/คุณสมบัติ หรือตัวสินค้า ถึงจะย้ายได้'); return; }
    if (!j.charcat.is_set) {
      // ตัวสินค้าใหม่/ยังไม่ตั้ง — ตั้งได้ในที่ (มติ 32: ไม่ตั้ง = ออก IC ไม่ได้) · ค่าเป็นของ LLP มีผลกับ IC ทุกตัวใต้มัน
      var o1 = '', o2 = '';
      Object.keys(CAT_LABELS).forEach(function (k)  { o1 += '<option value="' + k + '">' + esc(CAT_LABELS[k]) + '</option>'; });
      Object.keys(CHAR_LABELS).forEach(function (k) { o2 += '<option value="' + k + '">' + esc(CHAR_LABELS[k]) + '</option>'; });
      info('bad', 'ตัวสินค้า ' + esc(j.picked.llp) + ' ยังไม่ตั้งหมวดอนุมัติ/ลักษณะวัสดุ — ตั้งก่อนถึงจะย้ายได้'
        + '<div class="row" style="margin-top:6px"><select id="ccCat"><option value="">— CatID —</option>' + o1 + '</select>'
        + '<select id="ccChar"><option value="">— CharID —</option>' + o2 + '</select>'
        + '<button type="button" class="mini" id="ccSave">ตั้งค่าให้ ' + esc(j.picked.llp) + '</button></div>');
      return;
    }
    if (j.picked.llp !== CUR.llp && CUR.multi.length) {
      info('bad', 'เปลี่ยนตัวสินค้าไม่ได้ — ' + esc(CUR.multi.join(', ')) + ' มี IC หลายตัว (1 Mango มีตัวสินค้าได้ตัวเดียว)'); return;
    }
    var h = j.exists ? '<b>รวมเข้า IC ที่มีอยู่แล้ว</b>: ' + esc(j.exists_name) + ' — ยอดบวกรวมกัน ชื่อใช้ของตัวนั้น'
                     : '<b>ออกรหัสใหม่</b> แล้วย้ายทุกอย่างของ ' + esc(CUR.ic) + ' ไป';
    if (picked.unit !== CUR.unit) {
      var un = $('rcUnit').selectedOptions[0] ? $('rcUnit').selectedOptions[0].text.replace(/^\d+ · /, '') : '';
      h += '<br><span style="color:var(--red)">⚠ เปลี่ยนหน่วย ' + esc(CUR.unit_name) + ' → ' + esc(un)
         + ' — จำนวนยกไปตามเดิม ไม่แปลงหน่วย (คงเหลือ ' + CUR.on_hand + ' จะเป็น ' + CUR.on_hand + ' ' + esc(un) + ')</span>';
    }
    if (j.picked.llp !== CUR.llp) { h += '<br>ย้ายไปตัวสินค้า ' + esc(j.picked.llp) + ' — รหัส Mango ที่ผูกจะเปลี่ยนตัวสินค้าตามด้วย'; }
    info('', h);
    go.disabled = false;
  }

  // ตัวสินค้า: พิมพ์รหัสเอง / ค้น / สร้างใหม่
  $('rcLlp').addEventListener('change', function () { load({llp: this.value.trim().toUpperCase(), size: NONE, brand: NONE, extra: NONE}); });
  function findLlp() {
    var q = $('rcLlpQ').value.trim();
    if (!q) { return; }
    $('rcLlpRes').innerHTML = 'กำลังค้น…';
    api(BASE + '/api/setup_master_api.php?a=llp&q=' + encodeURIComponent(q))
      .then(function (j) {
        var h = j.rows.length ? '' : 'ไม่พบ — สร้างตัวสินค้าใหม่ด้านล่าง';
        j.rows.forEach(function (r) {
          h += '<div class="llpr" data-llp="' + esc(r.llp_code) + '"><span class="c">' + esc(r.llp_code) + '</span><span>' + esc(r.llp_name)
             + ' <span class="small">· ' + esc(r.l1_name) + ' › ' + esc(r.l2_name) + (r.is_set ? '' : ' · ⚠ ยังไม่ตั้ง Cat/Char') + '</span></span></div>';
        });
        $('rcLlpRes').innerHTML = h;
      })
      .catch(function (e) { $('rcLlpRes').innerHTML = '<span style="color:var(--red)">' + esc(e.message) + '</span>'; });
  }
  $('rcLlpFind').addEventListener('click', findLlp);
  $('rcLlpQ').addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); findLlp(); } });
  $('rcLlpRes').addEventListener('click', function (ev) {
    var d = ev.target.closest('.llpr');
    if (!d) { return; }
    $('rcLlp').value = d.dataset.llp;
    $('rcLlpRes').innerHTML = '';
    load({llp: d.dataset.llp, size: NONE, brand: NONE, extra: NONE});
  });
  var o = '<option value="">— เลือก —</option>';
  L1S.forEach(function (g) { o += '<option value="' + esc(g.l1_code) + '">' + esc(g.l1_code) + ' · ' + esc(g.l1_name) + '</option>'; });
  $('nlL1').innerHTML = o;
  $('nlL1').value = CUR.llp.substr(0, 3);
  $('nlName').value = <?= json_encode((string)$r['ic_name'], JSON_UNESCAPED_UNICODE) ?>;
  function loadNlL2(want) {
    var l1 = $('nlL1').value;
    if (!l1) { $('nlL2').innerHTML = '<option value="">(เลือก L1 ก่อน)</option>'; return; }
    api(BASE + '/api/ic_api.php?a=steps&l1=' + encodeURIComponent(l1)).then(function (j) {
      var h = '<option value="">— เลือกหมวด —</option>';
      j.l2.forEach(function (r) { h += '<option value="' + esc(r.l2_code) + '"' + (r.l2_code === want ? ' selected' : '') + '>' + esc(r.l2_code) + ' · ' + esc(r.l2_name) + '</option>'; });
      $('nlL2').innerHTML = h;
    }).catch(function (e) { info('bad', esc(e.message)); });
  }
  loadNlL2(CUR.llp.substr(3, 2));
  $('nlL1').addEventListener('change', function () { loadNlL2(''); });
  $('nlGo').addEventListener('click', function () {
    var l1 = $('nlL1').value, l2 = $('nlL2').value, name = $('nlName').value.trim();
    if (!l1 || !l2 || !name) { info('bad', 'เลือกกลุ่มใหญ่ + หมวด แล้วใส่ชื่อตัวสินค้าก่อน'); return; }
    api(BASE + '/api/ic_api.php', post({a: 'add_llp', l1: l1, l2: l2, name: name}))
      .then(function (j) { $('rcLlp').value = j.code; return load({llp: j.code, size: NONE, brand: NONE, extra: NONE}); })
      .then(function () { $('rcLlpRes').innerHTML = '<span class="small">สร้างตัวสินค้า ' + esc($('rcLlp').value) + ' แล้ว</span>'; })
      .catch(function (e) { info('bad', esc(e.message)); });
  });

  // ขนาด/ยี่ห้อ/หน่วย/คุณสมบัติ
  $('rcSize').addEventListener('change',  function () { load({size: this.value}); });
  $('rcBrand').addEventListener('change', function () { load({brand: this.value}); });
  $('rcUnit').addEventListener('change',  function () { load({unit: this.value}); });
  $('rcExtra').addEventListener('change', function () { load({extra: this.value}); });
  document.addEventListener('click', function (ev) {
    if (ev.target.id === 'ccSave') {
      var cat = $('ccCat').value, chr = $('ccChar').value;
      if (!cat || !chr) { return; }
      api(BASE + '/api/ic_api.php', post({a: 'set_charcat', llp: picked.llp, cat_id: cat, char_id: chr}))
        .then(function () { return load({}); })
        .catch(function (e) { info('bad', esc(e.message)); });
      return;
    }
    var b = ev.target.closest('button[data-add]');
    if (!b) { return; }
    var kind = b.dataset.add, inp = $('rc' + kind.charAt(0).toUpperCase() + kind.slice(1) + 'New'), name = inp.value.trim();
    if (!name) { info('bad', 'พิมพ์ชื่อก่อน'); return; }
    var l1 = picked.llp.substr(0, 3), l2 = picked.llp.substr(3, 2);
    api(BASE + '/api/ic_api.php', post({a: 'add_' + kind, name: name, code: '', l1: l1, l2: l2, llp: picked.llp}))
      .then(function (j) {
        inp.value = '';
        var over = {}; over[kind] = j.code;
        return load(over).then(function () { info('', (j.existed ? '"' + esc(name) + '" มีอยู่แล้ว — เลือกให้แล้ว' : 'เพิ่ม ' + esc(j.code) + ' · ' + esc(name) + ' แล้ว')); paint(); });
      })
      .catch(function (e) { info('bad', esc(e.message)); });
  });

  $('rcForm').addEventListener('submit', function (ev) {
    if (!last || !last.ic_code) { ev.preventDefault(); return; }
    var msg = 'ย้าย ' + CUR.ic + ' → ' + last.ic_code + (last.exists ? ' (รวมเข้ากับตัวที่มีอยู่)' : ' (ออกรหัสใหม่)') + '\n\n'
            + 'ยอดคงเหลือ ' + CUR.on_hand + ' · บรรทัดเอกสาร ' + CUR.n_doc + ' · รหัส Mango ที่ผูก ' + CUR.n_mango + ' จะย้ายไปทั้งหมด\n'
            + 'แล้วปิดใช้งาน ' + CUR.ic + (picked.unit !== CUR.unit ? '\n\n⚠ เปลี่ยนหน่วย — จำนวนไม่ถูกแปลง' : '') + '\n\nยืนยันไหม?';
    if (!confirm(msg)) { ev.preventDefault(); }
  });

  load({});
})();
</script>

<?php
uiFoot('แก้ชื่อ/ธงรหัสเดิมไม่เปลี่ยน · เปลี่ยนชิ้นรหัส = รหัสใหม่ ย้ายยอด/เอกสาร/การผูก Mango ไปให้ แล้วปิดตัวเดิม (มติ 46)');
