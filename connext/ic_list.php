<?php
/**
 * CONNEXT — ic_list.php : ทะเบียนรหัส IC ที่ออกไปแล้ว
 *
 * เฟส 1 ของสาย PO → OCR → buffer → IcCode (db/design_po_ocr_ic_v1.md)
 * ใช้ตรวจว่ารหัสที่ออกไปผูกกับ materials/สต๊อกถูกต้องไหม · ADM กด ✏️ ไปแก้ที่ ic_edit.php (มติ 46)
 *
 * PHP 7.4-compatible เท่านั้น
 */

require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/ic.php';
require __DIR__ . '/lib/ic_edit.php';   // iceMovedTo

$user = uiGuard();

$q  = trim((string)($_GET['q'] ?? ''));
$f1 = trim((string)($_GET['l1'] ?? ''));
// สถานะ — ค่าเริ่มต้นเห็นเฉพาะที่ใช้งาน · ตัวที่ปิด (ส่วนใหญ่ถูก "เปลี่ยนสเปก" ย้ายไปรหัสใหม่) เก็บไว้เป็นประวัติ ดูได้ที่ตัวกรองนี้
$fs = (string)($_GET['st'] ?? 'active');
if (!in_array($fs, ['active', 'off', 'all'], true)) { $fs = 'active'; }

$w    = ['1=1'];
$args = [];
if ($fs === 'active') { $w[] = 'i.is_active = 1'; }
if ($fs === 'off')    { $w[] = 'i.is_active = 0'; }
if ($q !== '') {
    $like = '%' . likeEscape($q) . '%';
    $w[]  = '(i.ic_code LIKE ? OR i.ic_name LIKE ? OR p.llp_name LIKE ?)';
    array_push($args, $like, $like, $like);
}
if ($f1 !== '') {
    $w[]    = 'i.l1_code = ?';
    $args[] = $f1;
}
$where = implode(' AND ', $w);

$sql = "SELECT i.*, p.llp_name, c.l2_name, g.l1_name, u.unit_name,
               s.size_name, b.brand_name, x.extra_name,
               m.id AS material_id, m.code_type,
               (SELECT COALESCE(SUM(sb.on_hand),0) FROM stock_balances sb WHERE sb.material_id = m.id) AS on_hand,
               pr.code AS proj_code
        FROM ic_items i
        JOIN llp_products p   ON p.llp_code   = i.llp_code
        JOIN l2_categories c  ON c.l1_code    = i.l1_code AND c.l2_code = i.l2_code
        JOIN l1_groups g      ON g.l1_code    = i.l1_code
        JOIN units u          ON u.unit_code  = i.unit_code
        LEFT JOIN sizes s     ON s.size_code  = i.size_code
        LEFT JOIN brands b    ON b.brand_code = i.brand_code
        LEFT JOIN extra_attrs x ON x.llp_code = i.llp_code AND x.extra_code = i.extra_code
        LEFT JOIN materials m ON m.mat_code   = i.ic_code
        LEFT JOIN projects pr ON pr.id        = i.created_project_id
        WHERE $where
        ORDER BY i.created_at DESC, i.ic_code
        LIMIT 500";
$st = $pdo->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

$total   = (int)$pdo->query('SELECT COUNT(*) FROM ic_items')->fetchColumn();
$nOff    = (int)$pdo->query('SELECT COUNT(*) FROM ic_items WHERE is_active = 0')->fetchColumn();
$moved   = iceMovedTo($pdo, array_column(array_filter($rows, function ($r) { return (int)$r['is_active'] !== 1; }), 'material_id'));
$noMat   = (int)$pdo->query(
    'SELECT COUNT(*) FROM ic_items i LEFT JOIN materials m ON m.mat_code = i.ic_code WHERE m.id IS NULL'
)->fetchColumn();
$l1s = icL1List($pdo, false);
$canEdit = uiIsAdmin($user);

uiHead('ทะเบียน IC', 'รหัสที่ออกไปแล้วทั้งหมด' . ($canEdit ? ' — กด ✏️ เพื่อแก้ไข' : ''), $user, '📒', uiIsEmbedded());
uiBackToAdmin('ladder');
?>

<div class="card">
  <h2><span class="num">1</span> ค้นหา
    <span class="sp">ทั้งหมด <?= number_format($total) ?> รหัส<?= $noMat > 0 ? ' · ไม่มีแถวใน materials ' . $noMat . ' รหัส' : '' ?></span>
  </h2>
  <form method="get" class="row">
    <div style="flex:1;min-width:220px">
      <label class="fld">รหัส / ชื่อวัสดุ / ชื่อตัวสินค้า</label>
      <input type="text" name="q" value="<?= e($q) ?>" style="width:100%" placeholder="พิมพ์แล้วกด Enter">
    </div>
    <div>
      <label class="fld">สถานะ</label>
      <select name="st" onchange="this.form.submit()">
        <option value="active" <?= $fs === 'active' ? 'selected' : '' ?>>ใช้งานอยู่ (<?= number_format($total - $nOff) ?>)</option>
        <option value="off"    <?= $fs === 'off'    ? 'selected' : '' ?>>ปิดใช้งาน (<?= number_format($nOff) ?>)</option>
        <option value="all"    <?= $fs === 'all'    ? 'selected' : '' ?>>ทั้งหมด (<?= number_format($total) ?>)</option>
      </select>
    </div>
    <div>
      <label class="fld">กลุ่มใหญ่</label>
      <select name="l1" onchange="this.form.submit()">
        <option value="">— ทั้งหมด —</option>
        <?php foreach ($l1s as $r): ?>
          <option value="<?= e((string)$r['l1_code']) ?>" <?= (string)$r['l1_code'] === $f1 ? 'selected' : '' ?>>
            <?= e((string)$r['l1_code']) ?> · <?= e((string)$r['l1_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="align-self:flex-end"><button type="submit">ค้นหา</button></div>
    <div style="align-self:flex-end"><a href="<?= APP_BASE ?>/ic_new.php"><button class="ghost" type="button">＋ ออกรหัสใหม่</button></a></div>
    <?php if ($canEdit): ?>
      <div style="align-self:flex-end"><a href="<?= APP_BASE ?>/setup_master.php"><button class="ghost" type="button">🗂️ จัดการรหัสวัสดุ (Mango → LLP)</button></a></div>
    <?php endif; ?>
  </form>
</div>

<?php if ($noMat > 0): ?>
  <div class="banner b-bad">
    มี <?= $noMat ?> รหัสที่ไม่มีแถวคู่ใน materials — ผิดมติ 3 ของออกแบบ (IC ต้องเบิกจ่ายด้วยกลไกเดิมได้)
  </div>
<?php endif; ?>

<div class="card">
  <h2><span class="num">2</span> รายการ <span class="sp">แสดง <?= count($rows) ?> แถว (สูงสุด 500)</span></h2>

  <?php if (empty($rows)): ?>
    <p class="small">
      <?= $total === 0
          ? 'ยังไม่มีรหัส IC สักตัว — ตารางนี้ตั้งใจให้เริ่มจากศูนย์ (มติ 2) รหัสจะเกิดตอนรับของเข้าเท่านั้น'
          : 'ไม่พบรายการที่ตรงกับเงื่อนไข' ?>
    </p>
  <?php else: ?>
    <div class="scroll">
      <table>
        <tr>
          <th>รหัส IC</th><th>ชื่อวัสดุ</th><th>เส้นทางบันได</th>
          <th>ขนาด / ยี่ห้อ</th><th>หน่วย</th><th>หมวด</th>
          <th class="num">สต๊อกรวม</th><th>materials</th><th>ออกเมื่อ</th>
        </tr>
        <?php foreach ($rows as $r): ?>
          <tr class="<?= (int)$r['is_active'] === 1 ? '' : 'dim' ?>">
            <td class="mono"><?= e((string)$r['ic_code']) ?>
              <?php if ($canEdit): ?>
                <a href="<?= uiUrl(APP_BASE . '/ic_edit.php?ic=' . urlencode((string)$r['ic_code'])) ?>" title="แก้ไข IC นี้">✏️</a>
              <?php endif; ?></td>
            <td>
              <?= e((string)$r['ic_name']) ?>
              <?php if ((int)$r['is_active'] !== 1): ?><span class="pill p-muted">ปิดใช้งาน</span>
                <?php $mv = $moved[(int)$r['material_id']] ?? null; if ($mv !== null): ?>
                  <div class="small">ย้ายไป <a class="mono" href="<?= uiUrl(APP_BASE . '/ic_list.php?st=all&q=' . urlencode($mv['ic'])) ?>"><?= e($mv['ic']) ?></a>
                    เมื่อ <?= e(substr($mv['at'], 0, 16)) ?></div>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td class="small">
              <?= e((string)$r['l1_name']) ?> › <?= e((string)$r['l2_name']) ?><br>
              <span class="mono"><?= e((string)$r['llp_code']) ?></span> <?= e((string)$r['llp_name']) ?>
            </td>
            <td class="small">
              <?= (string)$r['size_code'] === IC_NONE ? '—' : e((string)$r['size_name']) ?><br>
              <?= (string)$r['brand_code'] === IC_NONE ? '—' : e((string)$r['brand_name']) ?>
              <?php if ((string)$r['extra_code'] !== IC_NONE): ?><br><span title="คุณสมบัติเพิ่ม">+ <?= e((string)$r['extra_name']) ?></span><?php endif; ?>
            </td>
            <td><?= e((string)$r['unit_name']) ?></td>
            <td>
              <span class="pill <?= (string)$r['cat_id'] === 'C01' ? 'p-warn' : 'p-muted' ?>"><?= e((string)$r['cat_id']) ?></span>
              <?php if ($r['char_id'] !== null && $r['char_id'] !== ''): ?>
                <span class="pill p-muted"><?= e((string)$r['char_id']) ?></span>
              <?php endif; ?>
            </td>
            <td class="num"><?= fmtQ($r['on_hand']) ?></td>
            <td>
              <?php if ($r['material_id'] !== null): ?>
                <span class="pill p-ok">id <?= (int)$r['material_id'] ?></span>
              <?php else: ?>
                <span class="pill p-bad">ไม่มี</span>
              <?php endif; ?>
            </td>
            <td class="small">
              <?= e(substr((string)$r['created_at'], 0, 16)) ?>
              <?php if ($r['proj_code'] !== null): ?><br><?= e((string)$r['proj_code']) ?><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php
uiFoot('รหัสเกิดจากคนกดออกเอง หรือออกจากไฟล์ฝ่ายจัดซื้อ (มติ 44) · แก้ชื่อ/สเปกได้ที่ ✏️ (มติ 46)');
