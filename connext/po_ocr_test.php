<?php
/**
 * CONNEXT — po_ocr_test.php : หน้าทดสอบ OCR ใบสั่งซื้อ (เฟส 0 — spike)
 *
 * จุดประสงค์เดียว: พิสูจน์ว่า Gemini อ่านใบ PO จริงของบริษัทได้แม่นพอ
 * ก่อนจะลงทุนสร้าง IC master 10 ตาราง + จอบันได LLP (db/design_po_ocr_ic_v1.md)
 *
 * หน้านี้ "ไม่เขียนอะไรลงฐานข้อมูลเลย" — อ่าน materials/projects อย่างเดียว
 *
 * PHP 7.4-compatible เท่านั้น
 */

require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/gemini.php';
require __DIR__ . '/lib/po_ocr.php';

$user    = uiGuard();          // มติ 16 — สายคลัง (can_req) หรือ ADM
$isAdmin = uiIsAdmin($user);

@set_time_limit(300);

// ── สลับรุ่นจากหน้าเว็บ — ต้องจัดการก่อนอ่าน config ที่เหลือ ────────────────
//    เลือกในหน้า = ทับเฉพาะ session ของคนนั้น · "ตั้งถาวร" = เขียนลงไฟล์ตั้งค่า (ADM เท่านั้น)
$isPost = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
$csrfOk = $isPost && hash_equals(csrfToken(), (string)($_POST['csrf'] ?? ''));
$action = $isPost ? (string)($_POST['action'] ?? '') : '';
$notice = null;   // [ok|bad, ข้อความ]

if ($isPost && $csrfOk && in_array($action, ['usemodel', 'savemodel', 'clearmodel'], true)) {
    if ($action === 'clearmodel') {
        unset($_SESSION['gemini_model']);
        $notice = ['ok', 'กลับไปใช้รุ่นตามไฟล์ตั้งค่าแล้ว'];
    } else {
        $pick = trim((string)($_POST['model'] ?? ''));
        if (!geminiValidModelName($pick)) {
            $notice = ['bad', 'ชื่อรุ่นไม่ถูกต้อง'];
        } elseif ($action === 'usemodel') {
            $_SESSION['gemini_model'] = $pick;
            $notice = ['ok', 'เปลี่ยนมาใช้ ' . $pick . ' ชั่วคราว (เฉพาะการเข้าใช้งานครั้งนี้)'];
        } elseif (!$isAdmin) {
            $notice = ['bad', 'ตั้งเป็นค่าเริ่มต้นถาวรได้เฉพาะ ADM — ใช้ปุ่ม "ใช้รุ่นนี้" แทน'];
        } else {
            $w = geminiWriteModelToSettings($pick);
            if ($w['ok']) {
                unset($_SESSION['gemini_model']);   // ไฟล์เป็นค่าจริงแล้ว ไม่ต้องทับ
                geminiSetModel($pick);
                $notice = ['ok', 'บันทึก ' . $pick . ' ลง settings/gemini.php แล้ว'];
            } else {
                $notice = ['bad', $w['error']];
            }
        }
    }
}

$sessionModel = isset($_SESSION['gemini_model']) ? (string)$_SESSION['gemini_model'] : '';
if ($sessionModel !== '') { geminiSetModel($sessionModel); }

$cfg      = geminiConfig();
$hasCurl  = function_exists('curl_init');
$sampleDir = trim((string)$cfg['sample_dir']);
$samples  = [];
if ($sampleDir !== '' && is_dir($sampleDir)) {
    foreach ((array)glob(rtrim($sampleDir, '/\\') . DIRECTORY_SEPARATOR . '*.pdf') as $p) {
        $samples[] = basename($p);
    }
    sort($samples);
}

$err      = '';
$result   = null;   // ผลจาก geminiReadPo
$review   = null;   // ผลตรวจจาก poOcrReview
$srcLabel = '';

// รายชื่อรุ่นเก็บไว้ใน session ให้ dropdown มีตัวเลือกค้างอยู่ ไม่ต้องกดถาม API ใหม่ทุกครั้ง
$models = (isset($_SESSION['gemini_models']) && is_array($_SESSION['gemini_models']))
    ? $_SESSION['gemini_models'] : [];

if ($isPost && !$csrfOk) {
    $err = 'CSRF token ไม่ถูกต้อง — โหลดหน้าใหม่แล้วลองอีกครั้ง';
} elseif ($isPost) {
        if ($action === 'models') {
            $ml = geminiListModels();
            if ($ml['ok']) {
                $models = $ml['models'];
                $_SESSION['gemini_models'] = $models;
            } else {
                $err = $ml['error'];
            }

        } elseif ($action === 'run') {
            $bytes = '';
            $pick  = trim((string)($_POST['sample'] ?? ''));

            if (isset($_FILES['pdf']) && (int)$_FILES['pdf']['error'] === UPLOAD_ERR_OK) {
                $srcLabel = (string)$_FILES['pdf']['name'];
                $bytes    = (string)file_get_contents($_FILES['pdf']['tmp_name']);

            } elseif ($pick !== '') {
                // เฉพาะไฟล์ .pdf ที่อยู่ในโฟลเดอร์ตัวอย่างที่ตั้งไว้เท่านั้น
                $base = basename($pick);
                if ($sampleDir === '' || !in_array($base, $samples, true)) {
                    $err = 'ไฟล์ตัวอย่างไม่ถูกต้อง';
                } else {
                    $full     = rtrim($sampleDir, '/\\') . DIRECTORY_SEPARATOR . $base;
                    $srcLabel = $base;
                    $bytes    = (string)file_get_contents($full);
                }

            } elseif (isset($_FILES['pdf']) && (int)$_FILES['pdf']['error'] !== UPLOAD_ERR_NO_FILE) {
                $err = 'อัปโหลดไม่สำเร็จ (error code ' . (int)$_FILES['pdf']['error'] . ')';
            } else {
                $err = 'ยังไม่ได้เลือกไฟล์';
            }

            if ($err === '' && $bytes !== '') {
                $result = geminiReadPo($bytes);
                if (!$result['ok']) {
                    $err = $result['error'];
                } else {
                    $review = poOcrReview($pdo, $result['po']);
                }
            }
        }
}

uiHead('ทดสอบ / ตั้งค่า OCR', 'เลือกรุ่น Gemini + ลองอ่านใบสั่งซื้อ PDF · ไม่เขียนข้อมูลลงฐานใด ๆ', $user, '🔬', uiIsEmbedded());
uiBackToAdmin();
?>

  
  <div class="card">
    <h2><span class="num">1</span> สถานะการตั้งค่า</h2>
    <div class="row" style="gap:14px">
      <span class="pill <?= $hasCurl ? 'p-ok' : 'p-bad' ?>">ext-curl <?= $hasCurl ? 'พร้อม' : 'ไม่มี' ?></span>
      <span class="pill <?= $cfg['api_key'] !== '' ? 'p-ok' : 'p-bad' ?>">api_key <?= $cfg['api_key'] !== '' ? 'ตั้งแล้ว' : 'ยังไม่ตั้ง' ?></span>
      <span class="pill <?= $cfg['model'] !== '' ? 'p-ok' : 'p-bad' ?>">model <?= $cfg['model'] !== '' ? e((string)$cfg['model']) : 'ยังไม่ตั้ง' ?></span>
      <span class="pill <?= $sessionModel !== '' ? 'p-info' : 'p-muted' ?>"><?= $sessionModel !== '' ? 'เลือกเองชั่วคราว' : 'จากไฟล์ตั้งค่า' ?></span>
      <span class="pill p-muted">sample_dir <?= $sampleDir !== '' ? e($sampleDir) . ' (' . count($samples) . ' ไฟล์)' : 'ปิด' ?></span>
    </div>
    <?php if ($notice !== null): ?>
      <p class="small" style="margin-top:9px;font-weight:700;color:<?= $notice[0] === 'ok' ? 'var(--green)' : 'var(--red)' ?>">
        <?= $notice[0] === 'ok' ? '✓' : '✕' ?> <?= e($notice[1]) ?>
      </p>
    <?php endif; ?>

    <?php if ($cfg['api_key'] === ''): ?>
      <p class="small" style="margin-top:9px">
        เติม <span class="mono">api_key</span> ใน <span class="mono">settings/gemini.php</span> ก่อน
        แล้วรุ่นค่อยเลือกจากในหน้านี้ได้เลย
      </p>
    <?php endif; ?>

    
    <div class="row" style="margin-top:12px;align-items:flex-end;gap:8px">
      <form method="post" class="row" style="align-items:flex-end;gap:8px">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <div>
          <label class="fld">รุ่นที่ใช้อ่านใบ</label>
          <?php if (!empty($models)): ?>
            <select name="model" style="min-width:320px">
              <?php foreach ($models as $mm):
                    $short = preg_replace('#^models/#', '', (string)$mm['name']); ?>
                <option value="<?= e($short) ?>" <?= $short === (string)$cfg['model'] ? 'selected' : '' ?>>
                  <?= e($short) ?><?= $mm['display'] !== '' ? ' — ' . e($mm['display']) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          <?php else: ?>
            <input type="text" name="model" style="min-width:320px"
                   value="<?= e((string)$cfg['model']) ?>" placeholder="กด &quot;ดึงรายชื่อรุ่น&quot; เพื่อเลือกจากลิสต์">
          <?php endif; ?>
        </div>
        <button type="submit" name="action" value="usemodel">ใช้รุ่นนี้</button>
        <?php if ($isAdmin): ?>
          <button class="ghost" type="submit" name="action" value="savemodel"
                  title="เขียนทับค่า model ใน settings/gemini.php (คอมเมนต์และค่าอื่นคงเดิม)">ตั้งเป็นค่าเริ่มต้นถาวร</button>
        <?php endif; ?>
        <?php if ($sessionModel !== ''): ?>
          <button class="ghost" type="submit" name="action" value="clearmodel">กลับไปใช้ค่าในไฟล์</button>
        <?php endif; ?>
      </form>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <button class="ghost" type="submit" name="action" value="models"><?= empty($models) ? 'ดึงรายชื่อรุ่น' : 'รีเฟรชรายชื่อรุ่น' ?></button>
      </form>
    </div>
    <p class="small" style="margin-top:7px">
      "ใช้รุ่นนี้" = เปลี่ยนเฉพาะคุณและเฉพาะการเข้าใช้งานครั้งนี้ ไม่กระทบคนอื่น ·
      <?= $isAdmin ? '"ตั้งเป็นค่าเริ่มต้นถาวร" = เขียนลงไฟล์ตั้งค่า (ADM เท่านั้น)' : 'ตั้งค่าถาวรต้องเป็น ADM' ?>
    </p>

    <?php if (!empty($models)): ?>
      <details>
        <summary>รายชื่อรุ่นที่คีย์นี้เรียกได้ (<?= count($models) ?> รุ่น)</summary>
        <div class="scroll" style="margin-top:9px">
          <table>
            <tr><th>ชื่อรุ่น</th><th>ชื่อที่แสดง</th><th class="num">input tokens</th><th class="num">output tokens</th></tr>
            <?php foreach ($models as $mm): ?>
              <tr>
                <td class="mono"><?= e((string)$mm['name']) ?></td>
                <td><?= e((string)$mm['display']) ?></td>
                <td class="num"><?= number_format((int)$mm['in_tokens']) ?></td>
                <td class="num"><?= number_format((int)$mm['out_tokens']) ?></td>
              </tr>
            <?php endforeach; ?>
          </table>
        </div>
      </details>
    <?php endif; ?>
  </div>

  
  <div class="card">
    <h2><span class="num">2</span> เลือกใบสั่งซื้อ (PDF)</h2>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
      <input type="hidden" name="action" value="run">
      <div class="row">
        <div>
          <label class="fld">อัปโหลดไฟล์</label>
          <input type="file" name="pdf" accept="application/pdf">
        </div>
        <?php if (!empty($samples)): ?>
        <div>
          <label class="fld">หรือเลือกไฟล์ตัวอย่างในเครื่อง</label>
          <select name="sample">
            <option value="">— ไม่เลือก —</option>
            <?php foreach ($samples as $s): ?>
              <option value="<?= e($s) ?>"><?= e($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div style="align-self:flex-end">
          <button type="submit" <?= geminiReady() ? '' : 'disabled style="opacity:.45;cursor:not-allowed"' ?>>อ่านใบนี้</button>
        </div>
      </div>
      <p class="small" style="margin-top:8px">ส่งไฟล์ PDF ทั้งไฟล์ให้โมเดล (ไม่ได้ extract text เอง) — ใบหลายหน้าจะใช้เวลาสักครู่</p>
    </form>
  </div>

  <?php if ($err !== ''): ?>
    <div class="banner b-bad">ผิดพลาด: <?= e($err) ?></div>
  <?php endif; ?>

  <?php if ($result !== null && $result['ok'] && $review !== null):
      $po  = $result['po'];
      $sum = $review['summary'];
      $u   = $result['usage'];
  ?>

  
  <div class="banner <?= $sum['pass'] ? 'b-ok' : 'b-warn' ?>">
    <?= $sum['pass'] ? '✓ ผ่านกติกาตรวจตัวเองทุกข้อ' : '⚠ มีข้อที่ไม่ผ่าน — ต้องให้คนดูก่อนสร้างใบคุม' ?>
    <span class="small" style="font-weight:400;margin-left:8px">
      <?= e($srcLabel) ?> · <?= (int)($po['page_count'] ?? 0) ?> หน้า ·
      <?= $sum['n_item'] ?> บรรทัดของ · <?= $sum['n_adjust'] ?> บรรทัดรายการเงิน ·
      <span class="mono"><?= e((string)$result['model']) ?></span> ·
      <?= number_format($result['ms']) ?> ms ·
      <?= number_format((int)($result['usage']['totalTokenCount'] ?? 0)) ?> tokens
    </span>
  </div>

  <div class="card">
    <h2><span class="num">3</span> กติกาตรวจตัวเอง (มติ 21)</h2>
    <div class="scroll">
      <table>
        <tr><th>ข้อ</th><th class="num">ควรเป็น</th><th class="num">ในใบ</th><th class="num">ต่าง</th><th>ผล</th></tr>
        <?php foreach ($review['checks'] as $c): ?>
          <tr>
            <td><?= e($c['label']) ?><?= $c['note'] !== '' ? ' <span class="small">(' . e($c['note']) . ')</span>' : '' ?></td>
            <td class="num"><?= fmtM($c['expect']) ?></td>
            <td class="num"><?= fmtM($c['got']) ?></td>
            <td class="num"><?= fmtM($c['got'] - $c['expect']) ?></td>
            <td><span class="pill <?= $c['ok'] ? 'p-ok' : 'p-bad' ?>"><?= $c['ok'] ? 'ผ่าน' : 'ไม่ผ่าน' ?></span></td>
          </tr>
        <?php endforeach; ?>
        <tr>
          <td>เลขลำดับต่อเนื่อง ไม่ตกไม่ซ้ำ
            <?php if (!empty($review['numbering']['missing'])): ?>
              <span class="small">— ขาด: <?= e(implode(', ', $review['numbering']['missing'])) ?></span>
            <?php endif; ?>
            <?php if (!empty($review['numbering']['dup'])): ?>
              <span class="small">— ซ้ำ: <?= e(implode(', ', $review['numbering']['dup'])) ?></span>
            <?php endif; ?>
          </td>
          <td class="num">1..<?= (int)$review['numbering']['max'] ?></td>
          <td class="num"><?= (int)$review['numbering']['count'] ?> บรรทัด</td>
          <td class="num">—</td>
          <td><span class="pill <?= $review['numbering']['ok'] ? 'p-ok' : 'p-bad' ?>"><?= $review['numbering']['ok'] ? 'ผ่าน' : 'ไม่ผ่าน' ?></span></td>
        </tr>
        <tr>
          <td>เลขคณิตรายบรรทัด (qty × ราคา − ส่วนลด = จำนวนเงิน)</td>
          <td class="num">—</td>
          <td class="num"><?= $sum['n_bad_math'] ?> บรรทัดไม่ตรง</td>
          <td class="num">—</td>
          <td><span class="pill <?= $sum['n_bad_math'] === 0 ? 'p-ok' : 'p-bad' ?>"><?= $sum['n_bad_math'] === 0 ? 'ผ่าน' : 'ไม่ผ่าน' ?></span></td>
        </tr>
      </table>
    </div>
  </div>

  
  <div class="card">
    <h2><span class="num">4</span> หัวใบ และการจับคู่ไซต์ (มติ 19)</h2>
    <?php $m = $review['master']; ?>
    <?php if ($m['project'] === null): ?>
      <div class="banner b-warn" style="margin-bottom:11px">
        รหัสไซต์ <span class="mono"><?= e($m['project_code'] !== '' ? $m['project_code'] : '(อ่านไม่ได้)') ?></span>
        ไม่มีในตาราง projects
      </div>
    <?php else: ?>
      <p style="margin-bottom:11px">
        <span class="pill p-info">ไซต์ในใบ</span>
        <span class="mono"><?= e((string)$m['project']['code']) ?></span> — <?= e((string)$m['project']['name']) ?>
        <?php if ((string)$m['project']['code'] !== (string)$user['siteCode']): ?>
          <span class="pill p-warn" style="margin-left:8px">ไม่ตรงกับไซต์ของคุณ (<?= e((string)$user['siteCode']) ?>) — ตอนใช้งานจริงจะเตือนแล้วให้เลือกเอง</span>
        <?php endif; ?>
      </p>
    <?php endif; ?>
    <?php if ($m['project_conflict']): ?>
      <div class="banner b-warn" style="margin-bottom:11px">
        เลขที่ PO บอก <span class="mono"><?= e($m['project_from_no']) ?></span>
        แต่ช่องโครงการบอก <span class="mono"><?= e($m['project_from_text']) ?></span> — ใช้เลขที่ PO เป็นค่าตั้งต้น
      </div>
    <?php endif; ?>

    <dl class="kv">
      <dt>เลขที่ใบสั่งซื้อ</dt><dd class="mono"><?= e((string)($po['po_no'] ?? '')) ?></dd>
      <dt>วันที่</dt><dd><?= e((string)($po['po_date'] ?? '')) ?></dd>
      <dt>เลขที่ใบขอซื้อ</dt><dd class="mono"><?= e((string)($po['pr_no'] ?? '')) ?></dd>
      <dt>ผู้ขาย</dt><dd><?= e((string)($po['vendor_name'] ?? '')) ?></dd>
      <dt>เลขผู้เสียภาษี</dt><dd class="mono"><?= e((string)($po['vendor_tax_id'] ?? '')) ?></dd>
      <dt>ที่อยู่ผู้ขาย</dt><dd><?= e((string)($po['vendor_address'] ?? '')) ?></dd>
      <dt>ผู้ติดต่อ / โทร</dt><dd><?= e((string)($po['vendor_contact_person'] ?? '')) ?> · <?= e((string)($po['vendor_phone'] ?? '')) ?></dd>
      <dt>โครงการ (ข้อความ)</dt><dd><?= e((string)($po['project_text'] ?? '')) ?></dd>
      <dt>ใบเสนอราคา</dt><dd><?= e((string)($po['quotation_no'] ?? '')) ?> · <?= e((string)($po['quotation_date'] ?? '')) ?></dd>
      <dt>วันที่ส่งมอบ</dt><dd><?= e((string)($po['delivery_date'] ?? '')) ?></dd>
      <dt>เงื่อนไขชำระเงิน</dt><dd><?= e((string)($po['payment_terms'] ?? '')) ?></dd>
      <dt>เงินมัดจำ</dt><dd><?= e((string)($po['deposit_text'] ?? '')) ?></dd>
      <dt>เงินประกันผลงาน</dt><dd><?= e((string)($po['retention_text'] ?? '')) ?></dd>
      <dt>จำนวนเงินตัวอักษร</dt><dd><?= e((string)($po['amount_in_words'] ?? '')) ?></dd>
    </dl>
  </div>

  
  <div class="card">
    <h2><span class="num">5</span> บรรทัดในใบ — จัดประเภทอัตโนมัติ (มติ 18) + เทียบ materials (มติ 20)</h2>
    <p class="small" style="margin-bottom:9px">
      แถวพื้นเหลือง = ระบบตัดสินว่าเป็น <b>รายการเงิน ไม่ใช่ของ</b> (ไม่เข้ายอดคุม ไม่ต้องรับ ไม่ต้องมี IC) —
      ตอนใช้งานจริงคนสลับกลับเป็น "ของจริง" ได้
    </p>
    <div class="scroll">
      <table>
        <tr>
          <th class="num">#</th><th>ประเภท</th><th>รหัสวัสดุ</th><th>รายการ</th>
          <th class="num">จำนวน</th><th>หน่วย</th><th class="num">ราคา/หน่วย</th>
          <th class="num">ส่วนลด</th><th class="num">จำนวนเงิน</th><th>เลขคณิต</th><th>ใน materials</th>
        </tr>
        <?php foreach ($review['lines'] as $ln): ?>
          <tr class="<?= $ln['kind'] === 'adjust' ? 'adjust' : '' ?>">
            <td class="num"><?= (int)$ln['line_no'] ?></td>
            <td>
              <?php if ($ln['kind'] === 'adjust'): ?>
                <span class="pill p-warn">รายการเงิน</span>
                <div class="small"><?= e(implode(' · ', $ln['reasons'])) ?></div>
              <?php else: ?>
                <span class="pill p-info">ของ</span>
              <?php endif; ?>
            </td>
            <td class="mono"><?= e($ln['mat_code']) ?></td>
            <td>
              <?= e($ln['name']) ?>
              <?php if ($ln['description'] !== ''): ?>
                <div class="desc"><?= e($ln['description']) ?></div>
              <?php endif; ?>
            </td>
            <td class="num"><?= fmtQ($ln['qty']) ?></td>
            <td><?= e($ln['unit']) ?></td>
            <td class="num"><?= fmtM($ln['unit_price']) ?></td>
            <td class="num"><?= fmtM($ln['discount']) ?></td>
            <td class="num"><?= fmtM($ln['amount']) ?></td>
            <td>
              <span class="pill <?= $ln['math']['ok'] ? 'p-ok' : 'p-bad' ?>"><?= $ln['math']['ok'] ? 'ตรง' : 'ไม่ตรง' ?></span>
              <div class="small"><?= e($ln['math']['ok'] ? $ln['math']['formula'] : 'ควรได้ ' . fmtM($ln['math']['expect'])) ?></div>
            </td>
            <td>
              <?php if ($ln['master'] !== null): ?>
                <span class="pill p-ok">พบ</span>
                <div class="small">หน่วย <?= e((string)$ln['master']['unit']) ?>
                  <?php if (trim((string)$ln['master']['unit']) !== trim($ln['unit'])): ?>
                    <span class="pill p-warn">ต่างจากใบ</span>
                  <?php endif; ?>
                  · <?= e((string)$ln['master']['cat_id']) ?>
                </div>
              <?php elseif ($ln['mat_code'] === ''): ?>
                <span class="pill p-muted">ไม่มีรหัส</span>
              <?php else: ?>
                <span class="pill <?= $ln['kind'] === 'adjust' ? 'p-muted' : 'p-bad' ?>">ไม่พบ</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <tr>
          <td colspan="8" style="text-align:right;font-weight:700">Σ จำนวนเงินทุกบรรทัด</td>
          <td class="num" style="font-weight:700">
            <?php $t = 0.0; foreach ($review['lines'] as $ln) { $t += $ln['amount']; } echo fmtM($t); ?>
          </td>
          <td colspan="2"></td>
        </tr>
      </table>
    </div>

    <?php if (!empty($m['missing'])): ?>
      <p class="small" style="margin-top:9px">
        รหัสที่ไม่มีใน materials: <span class="mono"><?= e(implode(', ', $m['missing'])) ?></span>
      </p>
    <?php endif; ?>
  </div>

  
  <?php $notes = isset($po['notes']) && is_array($po['notes']) ? $po['notes'] : []; ?>
  <div class="card">
    <h2><span class="num">6</span> ข้อความที่แยกออกจากตาราง (notes) — <?= count($notes) ?> ก้อน</h2>
    <?php if (empty($notes)): ?>
      <p class="small">ไม่มี</p>
    <?php else: ?>
      <ul style="padding-left:20px">
        <?php foreach ($notes as $n): ?>
          <li style="margin-bottom:5px;font-size:.82rem"><?= e((string)$n) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  
  <div class="card">
    <h2><span class="num">7</span> ข้อมูลดิบ</h2>
    <p class="small">
      รุ่น <span class="mono"><?= e((string)$result['model']) ?></span> ·
      finishReason <span class="mono"><?= e($result['finish'] !== '' ? $result['finish'] : '-') ?></span> ·
      token: prompt <?= number_format((int)($u['promptTokenCount'] ?? 0)) ?> ·
      output <?= number_format((int)($u['candidatesTokenCount'] ?? 0)) ?> ·
      รวม <?= number_format((int)($u['totalTokenCount'] ?? 0)) ?>
    </p>
    <details>
      <summary>JSON ที่ถอดได้ (ตัวที่เฟสถัดไปจะเอาไปสร้างใบคุม)</summary>
      <pre><?= e(json_encode($po, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
    </details>
    <details>
      <summary>response ดิบจาก API (ตัวที่จะเก็บลง ocr_logs)</summary>
      <pre><?= e($result['raw']) ?></pre>
    </details>
  </div>

  <?php endif; ?>

<?php
uiFoot('เฟส 0 — หน้านี้ไม่เขียนข้อมูลลงฐาน · มติทั้งหมดอยู่ใน <span class="mono">db/design_po_ocr_ic_v1.md</span>');
