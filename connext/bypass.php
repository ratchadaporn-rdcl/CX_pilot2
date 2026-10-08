<?php
/**
 * CONNEXT — bypass.php : หน้า Bypass — คีย์ใบย้อนหลังจากแบบฟอร์มกระดาษ (ADM เท่านั้น)
 * [PHP port 2026-09-28 · Scenario 05 ⑩]
 *
 * ใช้เมื่อระบบใช้ไม่ได้ทั้งวัน (เว็บ/เน็ตล่ม ตู้ใช้ไม่ได้): หน้างานจ่ายของด้วยแบบฟอร์มกระดาษ แล้ว ADM คีย์ย้อนหลัง
 * ภายใน 1 วันทำการ → ใบ RD / OD / IN สถานะ Completed ที่ G นั้น ตัด/เพิ่มสต๊อกทันทีโดยไม่ผ่านประตู ·
 * ใบติดป้าย bypass + activity_log · ขึ้นในตรวจสอบประจำวัน / Dashboard ตามวันที่จ่ายจริง
 * ตรรกะอยู่ที่ lib/bypass.php (bypassCreate)
 *
 * [2026-09-29] + โหมด "เลือกเลขเอกสาร" (Bypass ประตู): ใบที่ออกในระบบแล้ว (อนุมัติแล้ว รอสแกน) แต่ตู้/ประตูใช้ไม่ได้
 *   bypass ได้แค่ตอนเปิด (แทนแตะบัตร) กับตอนปิด (แทนตู้ปิดประตู) — ถ่ายรูปยืนยันทำตามปกติที่หน้าเดิม · ตรรกะ lib/bypass.php bypassGate*
 *   + ชนิด TD (โอนย้ายข้ามไซต์) ในโหมดคีย์จากแบบฟอร์มกระดาษ
 *
 *   GET  ?project=ID                   ฟอร์ม + ใบรอสแกน + รอบ bypass + ใบ bypass ล่าสุด
 *   GET  ?ajax=mat&project=&gate=&type=&q=   ค้นรหัส IC (+ คงเหลือที่ G) สำหรับช่องรายการ
 *   POST action=create (multipart)     สร้างใบจากแบบฟอร์มกระดาษ
 *   POST action=gate_open              Bypass เปิด — bp_doc[] · bp_gate[<docNo>] (ใบ IN) · bp_note
 *   POST action=gate_close             Bypass ปิด — pk · bp_close_note
 *
 * PHP 7.4-compatible เท่านั้น
 */

require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/bypass.php';

$user = uiGuard();
if (!uiIsAdmin($user)) {
    http_response_code(403);
    uiHead('Bypass', 'เฉพาะผู้ดูแลระบบ', $user, '📝', uiIsEmbedded());
    echo '<div class="alert bad"><i class="fa-solid fa-lock" aria-hidden="true"></i>'
       . '<div>หน้านี้สำหรับผู้ดูแลระบบ (ADM) เท่านั้น</div></div>';
    uiFoot();
    exit;
}

// ── โครงการที่เลือก (ค่าตั้งต้น = ไซต์ใน session) ──
$projects = $pdo->query("SELECT id, code, name FROM projects WHERE status = 'active' ORDER BY code")->fetchAll();
$projectId = (int)($_REQUEST['project'] ?? $_POST['project_id'] ?? 0);
if ($projectId <= 0) { $projectId = (int)($user['projectId'] ?? 0); }
$projOk = false;
foreach ($projects as $p) { if ((int)$p['id'] === $projectId) { $projOk = true; } }
if (!$projOk && $projects) { $projectId = (int)$projects[0]['id']; }

// ═══════════════════════════════════════════════════════════════════════
// AJAX: ค้นรหัส IC
// ═══════════════════════════════════════════════════════════════════════
if (($_GET['ajax'] ?? '') === 'mat') {
    header('Content-Type: application/json; charset=utf-8');
    $q    = trim((string)($_GET['q'] ?? ''));
    $gate = (int)($_GET['gate'] ?? 0);
    $type = strtoupper((string)($_GET['type'] ?? 'RD'));
    if (mb_strlen($q, 'UTF-8') < 2) { echo json_encode(['ok' => true, 'rows' => []]); exit; }
    $like = '%' . likeEscape($q) . '%';
    $w = "m.code_type = 'ic' AND (m.mat_code LIKE ? OR m.name LIKE ?)";
    if ($type === 'RD') { $w .= " AND COALESCE(m.char_id, '') <> 'NAR' AND m.cat_id <> 'NAR'"; }
    if ($type === 'OD') { $w .= " AND (m.char_id = 'NAR' OR m.cat_id = 'NAR')"; }
    $st = $pdo->prepare(
        "SELECT m.mat_code, m.name, m.unit, m.char_id,
                (SELECT gb.on_hand - gb.pending FROM stock_gate_balances gb
                  WHERE gb.project_id = ? AND gb.material_id = m.id AND gb.gate_id = ?) AS avail
           FROM materials m
          WHERE $w
          ORDER BY (avail IS NULL), m.mat_code
          LIMIT 20"
    );
    $st->execute([$projectId, $gate, $like, $like]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[] = ['code' => (string)$r['mat_code'], 'name' => (string)$r['name'], 'unit' => (string)$r['unit'],
                   'char' => (string)($r['char_id'] ?? ''), 'avail' => $r['avail'] === null ? null : (float)$r['avail']];
    }
    echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// POST: Bypass ประตู — เลือกเลขเอกสาร (เปิดแทนแตะบัตร / ปิดแทนตู้)
// ═══════════════════════════════════════════════════════════════════════
$gateNotice = null;
$gateAction = (string)($_POST['action'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && in_array($gateAction, ['gate_open', 'gate_close'], true)) {
    if (!hash_equals(csrfToken(), (string)($_POST['csrf'] ?? ''))) {
        $gateNotice = ['bad', 'CSRF token ไม่ถูกต้อง — โหลดหน้าใหม่แล้วลองอีกครั้ง'];
    } elseif ($gateAction === 'gate_open') {
        $gateFor = [];
        foreach ((array)($_POST['bp_gate'] ?? []) as $dn => $gid) {
            if ((int)$gid > 0) { $gateFor[strtoupper(trim((string)$dn))] = (int)$gid; }
        }
        $r = bypassGateOpen($pdo, $user, $projectId, (array)($_POST['bp_doc'] ?? []), $gateFor, (string)($_POST['bp_note'] ?? ''));
        if ($r['ok']) {
            $parts = [];
            $needClose = false;
            foreach ($r['rounds'] as $rd) {
                $parts[] = 'รอบ ' . $rd['pickingId'] . ' (' . $rd['gate'] . ($rd['scanFlow'] ? ' · ไม่มีตู้' : '') . '): ' . implode(', ', $rd['docs']);
                if (!$rd['scanFlow']) { $needClose = true; }
            }
            $gateNotice = ['ok', 'Bypass เปิดแล้ว — ' . implode(' · ', $parts)
                . "\nขั้นต่อไป: ผู้ขอ/สโตร์ถ่ายรูปยืนยันที่หน้า \"ถ่ายรูปยืนยัน\" ตามปกติ (ใบนับสต๊อก = กรอกผลนับที่หน้าตรวจสอบประจำวัน)"
                . ($needClose ? ' แล้วกลับมากด "Bypass ปิด" ของรอบนั้นด้านล่าง' : ' — ประตูไม่มีตู้ ยืนยันแล้วปิดงานเอง')];
        } else {
            $gateNotice = ['bad', $r['error']];
        }
    } else {
        $r = bypassGateClose($pdo, $user, $projectId, (string)($_POST['pk'] ?? ''), (string)($_POST['bp_close_note'] ?? ''));
        $gateNotice = $r['ok']
            ? ['ok', 'Bypass ปิดรอบ ' . (string)($_POST['pk'] ?? '') . ' แล้ว — ปิดงาน/ตัดหรือเพิ่มสต๊อก: ' . implode(', ', $r['closed'])]
            : ['bad', $r['error']];
    }
}

// ═══════════════════════════════════════════════════════════════════════
// POST: สร้างใบ (คีย์จากแบบฟอร์มกระดาษ)
// ═══════════════════════════════════════════════════════════════════════
$notice = null;
$old = [];
// ข้อมูลเกิน post_max_size → PHP ทิ้ง $_POST ทั้งหมด (เดิมจะขึ้นเป็น CSRF ไม่ถูกต้อง) — บอกให้ชัดว่าไฟล์/รูปใหญ่เกิน
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $notice = ['bad', 'ข้อมูลที่ส่งมาใหญ่เกินที่เซิร์ฟเวอร์รับได้ (' . ini_get('post_max_size') . ') — ลดจำนวนรูปสินค้า/รูปแบบฟอร์มแล้วบันทึกอีกครั้ง'];
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $old = $_POST;
    if (!hash_equals(csrfToken(), (string)($_POST['csrf'] ?? ''))) {
        $notice = ['bad', 'CSRF token ไม่ถูกต้อง — โหลดหน้าใหม่แล้วลองอีกครั้ง'];
    } else {
        $items = [];
        $codes = (array)($_POST['mat'] ?? []);
        $qtys  = (array)($_POST['qty'] ?? []);
        $chg   = (array)($_POST['charge'] ?? []);
        $imgs  = (array)($_POST['item_img'] ?? []);   // รูปสินค้าที่เบิก: item_img[<แถว>][] = data URI (หน้าเว็บย่อแล้ว)
        foreach ($codes as $i => $c) {
            $ph = (isset($imgs[$i]) && is_array($imgs[$i])) ? array_values(array_filter($imgs[$i], 'is_string')) : [];
            $items[] = ['mat_code' => (string)$c, 'qty' => (string)($qtys[$i] ?? ''), 'charge' => !empty($chg[$i]), 'photos' => $ph];
        }
        $receiver = trim((string)($_POST['receiver_text'] ?? ''));
        if ($receiver === '') { $receiver = trim((string)($_POST['receiver'] ?? '')); }
        $photo = [];
        if (!empty($_FILES['form_photo']['tmp_name']) && is_uploaded_file($_FILES['form_photo']['tmp_name'])) {
            $photo['path'] = $_FILES['form_photo']['tmp_name'];
        }
        $r = bypassCreate($pdo, $user, [
            'type'         => (string)($_POST['doc_type'] ?? ''),
            'project_id'   => $projectId,
            'gate_id'      => (int)($_POST['gate_id'] ?? 0),
            'dispensed_at' => (string)($_POST['dispensed_at'] ?? ''),
            'requester'    => (string)($_POST['requester'] ?? ''),
            'receiver'     => $receiver,
            'usage_area'   => (string)($_POST['usage_area'] ?? ''),
            'rs_no'        => (string)($_POST['rs_no'] ?? ''),
            'dest_project_id' => (int)($_POST['dest_project_id'] ?? 0),
            'dest_contact' => (string)($_POST['dest_contact'] ?? ''),
            'note'         => (string)($_POST['note'] ?? ''),
            'force'        => !empty($_POST['force']),
            'items'        => $items,
        ], $photo);
        if ($r['ok']) {
            $notice = ['ok', 'บันทึกใบ ' . $r['docNo'] . ' แล้ว — สถานะ Completed · ตัด/เพิ่มสต๊อกที่ประตูแล้ว'
                           . ($r['warnings'] ? ' · ⚠ ' . implode(' · ', $r['warnings']) : '')];
            $old = ['doc_type' => (string)($_POST['doc_type'] ?? 'RD'), 'gate_id' => (string)($_POST['gate_id'] ?? '')];
        } else {
            $notice = [!empty($r['needForce']) ? 'warn' : 'bad', $r['error']];
        }
    }
}

$gates = $pdo->prepare("SELECT id, gate_code, name FROM gates WHERE project_id = ? AND status = 'active' ORDER BY gate_code");
$gates->execute([$projectId]);
$gates = $gates->fetchAll();
$people = $pdo->prepare(
    "SELECT u.username, u.full_name, r.role_code FROM users u LEFT JOIN roles r ON r.id = u.role_id
      WHERE u.project_id = ? AND u.status = 'active' ORDER BY u.full_name, u.username"
);
$people->execute([$projectId]);
$people = $people->fetchAll();
$subs = $pdo->prepare(
    "SELECT s.sub_code, s.name FROM subcontractors s JOIN sub_projects sp ON sp.sub_id = s.id
      WHERE sp.project_id = ? AND sp.enabled = 1 AND s.status = 'active' ORDER BY s.name"
);
$subs->execute([$projectId]);
$subs = $subs->fetchAll();
$recent = bypassRecent($pdo, $projectId, 30);
$cands = bypassGateCandidates($pdo, $projectId);
$bpRounds = bypassGateRounds($pdo, $projectId);
$bpOpenRounds = array_values(array_filter($bpRounds, function ($x) { return $x['state'] !== 'done'; }));
$bpDoneRounds = array_slice(array_values(array_filter($bpRounds, function ($x) { return $x['state'] === 'done'; })), 0, 10);
$destSites = array_values(array_filter($projects, function ($p) use ($projectId) { return (int)$p['id'] !== $projectId; }));

$v = function (string $k, string $def = '') use ($old) { return isset($old[$k]) ? (string)$old[$k] : $def; };
$type0 = strtoupper($v('doc_type', 'RD'));

uiHead('Bypass — คีย์ใบย้อนหลัง', 'จากแบบฟอร์มกระดาษ วันที่ระบบใช้ไม่ได้ (เอกสาร 05 Scenario ข้อ 10)', $user, '📝', uiIsEmbedded());
uiBackToAdmin('gates');
?>
<style>
.bp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.85rem 1rem}
.bp-grid>div>select,.bp-grid>div>input{width:100%}
.bp-type{display:flex;flex-wrap:wrap;gap:.5rem}
.bp-type label{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem .9rem;border:1px solid var(--border);border-radius:999px;background:#fff;cursor:pointer;font-weight:600;font-size:.88rem}
.bp-type input{margin:0}
.bp-type label:has(input:checked){border-color:var(--primary);background:rgba(59,130,246,.08);color:var(--primary)}
.bp-items{width:100%;min-width:560px}
.bp-items td{vertical-align:middle}
.bp-items input[type=text]{width:100%}
.bp-items input[type=number]{width:110px}
.bp-name{font-size:.8rem;color:var(--text-muted);margin-top:.2rem;min-height:1.1em}
/* รูปสินค้าที่เบิก (รายรายการ · 2026-09-29) */
.bp-ph{min-width:150px}
.bp-ph-add{display:inline-flex;align-items:center;gap:.35rem;cursor:pointer;padding:.35rem .6rem;border:1px dashed var(--border);border-radius:8px;font-size:.8rem;font-weight:600;background:#fff;white-space:nowrap}
.bp-ph-add.need{border-color:#fca5a5;color:#b91c1c;background:#fff7f7}
.bp-ph-add input{display:none}
.bp-ph-list{display:flex;flex-wrap:wrap;gap:.35rem;margin-top:.35rem}
.bp-ph-list span{position:relative;display:inline-block}
.bp-ph-list img{width:44px;height:44px;object-fit:cover;border-radius:6px;border:1px solid var(--border);display:block}
.bp-ph-list button{position:absolute;top:-6px;right:-6px;width:18px;height:18px;min-height:0;min-width:0;padding:0;border-radius:50%;font-size:.7rem;line-height:1;background:#ef4444;color:#fff;border:0;cursor:pointer}
.bp-ph-busy{font-size:.72rem;color:var(--text-muted)}
.bp-avail{font-size:.75rem;font-weight:600}
.bp-avail.low{color:#b91c1c}
.bp-sug{margin-top:.3rem;background:#fff;border:1px solid var(--border);border-radius:8px;box-shadow:var(--shadow-md);max-height:220px;overflow:auto}
.bp-sug button{display:block;width:100%;text-align:left;background:none;color:var(--text-main);border:0;border-bottom:1px solid var(--border);border-radius:0;padding:.45rem .7rem;font-weight:400;font-size:.84rem}
.bp-sug button:hover{background:#eff6ff}
.bp-sug b{font-family:ui-monospace,Consolas,monospace;font-size:.8rem}
.bp-cell{position:relative}
.bp-only-out,.bp-only-in{}
.bp-foot{display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;justify-content:space-between;margin-top:1rem}
@media(max-width:640px){.bp-items input[type=number]{width:80px}}
/* โหมดเลือกเลขเอกสาร (Bypass ประตู · 2026-09-29) */
.bp-steps{display:flex;flex-wrap:wrap;gap:.4rem;margin:.2rem 0 .8rem;font-size:.84rem}
.bp-steps span{padding:.3rem .7rem;border-radius:999px;background:#f1f5f9;border:1px solid var(--border)}
.bp-steps span.by{background:#fff7ed;border-color:#fed7aa;color:#9a3412;font-weight:700}
.bp-tools{display:flex;flex-wrap:wrap;gap:.6rem;align-items:center;margin-bottom:.6rem}
.bp-tools input[type=search]{flex:1;min-width:200px}
.bp-docs td{vertical-align:middle}
.bp-docs tr.bp-hide{display:none}
.bp-docs tr.bp-sel td{background:#eff6ff}
.bp-docs select{min-width:110px}
.bp-type-chip{display:inline-block;font-size:.72rem;font-weight:700;padding:.1rem .5rem;border-radius:999px;background:#e2e8f0;color:#334155;white-space:nowrap}
.bp-round{border:1px solid var(--border);border-radius:12px;padding:.7rem .9rem;margin-bottom:.6rem;background:#fff}
.bp-round.ready{border-color:#fdba74;background:#fffbf5}
.bp-round-h{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;justify-content:space-between}
.bp-round-docs{margin:.4rem 0 0;padding:0;list-style:none;font-size:.84rem}
.bp-round-docs li{padding:.15rem 0}
.bp-round form{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;margin-top:.5rem}
.bp-round form input[type=text]{flex:1;min-width:180px}
</style>

<?php if ($gateNotice !== null): ?>
  <?php $gk = $gateNotice[0] === 'ok' ? 'ok' : 'bad'; ?>
  <div class="alert <?= $gk ?>" role="status">
    <i class="fa-solid <?= $gk === 'ok' ? 'fa-circle-check' : 'fa-circle-xmark' ?>" aria-hidden="true"></i>
    <div style="white-space:pre-line"><?= e($gateNotice[1]) ?></div>
  </div>
<?php endif; ?>

<div class="card" id="bpGateCard">
  <h2><i class="fa-solid fa-door-open" aria-hidden="true"></i> Bypass ประตู — เลือกเลขเอกสาร <span class="sp">ใบที่ออกในระบบแล้ว แต่ตู้/ประตูใช้ไม่ได้</span></h2>
  <div class="bp-steps" aria-label="ขั้นตอน">
    <span class="by">1 · Bypass เปิด (แทนแตะบัตร)</span><span>2 · ถ่ายรูปยืนยันตามปกติ (หยิบจริง ≤ ที่ขอ)</span><span class="by">3 · Bypass ปิด (แทนตู้ปิดประตู) = ตัด/เพิ่มสต๊อก</span>
  </div>
  <p class="small" style="margin:0 0 .8rem">เลือกได้เฉพาะใบที่<b>อนุมัติแล้วและยังไม่แตะบัตร</b> (รอสแกน) · ประตูที่ไม่มีตู้ ยืนยันแล้วปิดงานเอง (ไม่ต้องขั้น 3) ·
    ใบนับสต๊อกเปิดแยกรอบ · ใบรับเข้าเลือกประตูที่รับของเข้าได้ · บันทึกใน Error log ของไซต์ทุกครั้ง</p>

  <form method="post" id="bpGateForm" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="action" value="gate_open">
    <input type="hidden" name="project" value="<?= (int)$projectId ?>">
    <div class="bp-tools">
      <input type="search" id="bpDocSearch" placeholder="ค้นเลขเอกสาร / ผู้ขอ / ผู้รับ" aria-label="ค้นเลขเอกสาร">
      <span class="small"><?= count($cands) ?> ใบรอสแกน</span>
    </div>
    <div class="tbl-wrap">
      <table class="tbl bp-docs">
        <thead><tr><th style="width:40px"></th><th>เลขเอกสาร</th><th>ชนิด</th><th>ประตู</th><th>ผู้ขอ</th><th>ผู้รับ / ปลายทาง / RS</th><th class="num">รายการ</th><th>ออกเมื่อ</th></tr></thead>
        <tbody>
        <?php if (!$cands): ?>
          <tr><td colspan="8" class="empty">ไม่มีใบที่รอสแกนที่ประตูของโครงการนี้</td></tr>
        <?php endif; ?>
        <?php foreach ($cands as $c): ?>
          <?php $q = mb_strtolower($c['docNo'] . ' ' . $c['requester'] . ' ' . $c['who'] . ' ' . $c['typeLabel'], 'UTF-8'); ?>
          <tr data-q="<?= e($q) ?>">
            <td><input type="checkbox" name="bp_doc[]" value="<?= e($c['docNo']) ?>" aria-label="เลือก <?= e($c['docNo']) ?>"></td>
            <td class="mono"><b><?= e($c['docNo']) ?></b></td>
            <td><span class="bp-type-chip"><?= e($c['typeLabel']) ?></span></td>
            <td>
              <?php if ($c['type'] === 'IN' && !$c['isReturn']): ?>
                <select name="bp_gate[<?= e($c['docNo']) ?>]" aria-label="ประตูที่รับเข้า <?= e($c['docNo']) ?>">
                  <?php if ($c['gate'] === ''): ?><option value="">— เลือกประตู —</option><?php endif; ?>
                  <?php foreach ($gates as $g): ?>
                    <option value="<?= (int)$g['id'] ?>" <?= $c['gateId'] === (int)$g['id'] ? 'selected' : '' ?>><?= e((string)$g['gate_code']) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php else: ?>
                <span class="mono"><?= e($c['gate'] !== '' ? $c['gate'] : '-') ?></span><?= $c['scanFlow'] ? ' <span class="small">(ไม่มีตู้)</span>' : '' ?>
              <?php endif; ?>
            </td>
            <td><?= e($c['requester']) ?></td>
            <td><?= e($c['who'] !== '' ? $c['who'] : '-') ?></td>
            <td class="num"><?= (int)$c['items'] ?></td>
            <td class="small"><?= e($c['docTs']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="bp-foot">
      <input type="text" name="bp_note" maxlength="200" placeholder="เหตุผล (เช่น ตู้ G01 ขัดข้อง / ไฟดับ)" style="flex:1;min-width:220px">
      <button type="submit" id="bpOpenBtn" <?= $cands ? '' : 'disabled' ?>><i class="fa-solid fa-door-open" aria-hidden="true"></i> Bypass เปิด (<span id="bpSelCount">0</span> ใบ)</button>
    </div>
  </form>

  <h3 style="font-size:.95rem;margin:1.2rem 0 .5rem"><i class="fa-solid fa-rotate" aria-hidden="true"></i> รอบ bypass ที่ยังไม่ปิด <span class="sp"><?= count($bpOpenRounds) ?> รอบ</span></h3>
  <?php if (!$bpOpenRounds): ?><p class="small">ไม่มีรอบ bypass ค้าง</p><?php endif; ?>
  <?php foreach ($bpOpenRounds as $rd): ?>
    <div class="bp-round <?= $rd['state'] === 'ready' ? 'ready' : '' ?>">
      <div class="bp-round-h">
        <div><b class="mono"><?= e($rd['pickingId']) ?></b> · ประตู <b class="mono"><?= e($rd['gate']) ?></b>
          <span class="small">· เปิดโดย <?= e($rd['openedBy']) ?> <?= e($rd['openedAt']) ?><?= $rd['note'] !== '' ? ' · ' . e($rd['note']) : '' ?></span></div>
        <span class="pill <?= $rd['state'] === 'ready' ? 'p-warn' : '' ?>"><?= $rd['state'] === 'ready' ? 'ยืนยันครบ — รอ Bypass ปิด' : 'รอยืนยัน ' . (int)$rd['pending'] . ' ใบ' ?></span>
      </div>
      <ul class="bp-round-docs">
        <?php foreach ($rd['docs'] as $d): ?>
          <li><span class="mono"><?= e($d['docNo']) ?></span> — <?= e($d['statusThai']) ?><?= $d['requester'] !== '' ? ' <span class="small">(' . e($d['requester']) . ')</span>' : '' ?></li>
        <?php endforeach; ?>
      </ul>
      <?php if (!$rd['scanFlow']): ?>
        <form method="post" class="bp-close-form">
          <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
          <input type="hidden" name="action" value="gate_close">
          <input type="hidden" name="project" value="<?= (int)$projectId ?>">
          <input type="hidden" name="pk" value="<?= e($rd['pickingId']) ?>">
          <input type="text" name="bp_close_note" maxlength="200" placeholder="หมายเหตุตอนปิด (ถ้ามี)">
          <button type="submit" <?= $rd['state'] === 'ready' ? '' : 'disabled' ?> title="<?= $rd['state'] === 'ready' ? '' : 'รอให้ทุกใบในรอบยืนยันก่อน' ?>"><i class="fa-solid fa-lock" aria-hidden="true"></i> Bypass ปิด (ตัด/เพิ่มสต๊อก)</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if ($bpDoneRounds): ?>
    <details style="margin-top:.4rem"><summary class="small">รอบ bypass ที่ปิดแล้ว (<?= count($bpDoneRounds) ?> ล่าสุด)</summary>
      <ul class="bp-round-docs">
        <?php foreach ($bpDoneRounds as $rd): ?>
          <li><span class="mono"><?= e($rd['pickingId']) ?></span> · <?= e($rd['gate']) ?> · เปิด <?= e($rd['openedBy']) ?> <?= e($rd['openedAt']) ?>
            <?= $rd['closedBy'] !== '' ? ' · ปิด ' . e($rd['closedBy']) . ' ' . e($rd['closedAt']) : ($rd['scanFlow'] ? ' · ปิดงานตอนยืนยัน (ไม่มีตู้)' : '') ?>
            · <?= e(implode(', ', array_column($rd['docs'], 'docNo'))) ?></li>
        <?php endforeach; ?>
      </ul>
    </details>
  <?php endif; ?>
</div>

<?php if ($notice !== null): ?>
  <?php $nk = in_array($notice[0], ['ok', 'warn'], true) ? $notice[0] : 'bad'; ?>
  <div class="alert <?= $nk ?>" role="status">
    <i class="fa-solid <?= ['ok' => 'fa-circle-check', 'warn' => 'fa-triangle-exclamation', 'bad' => 'fa-circle-xmark'][$nk] ?>" aria-hidden="true"></i>
    <div style="white-space:pre-line"><?= e($notice[1]) ?></div>
  </div>
<?php endif; ?>

<div class="alert info">
  <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
  <div>
    <b>ใช้เฉพาะวันที่ระบบใช้ไม่ได้ทั้งวัน</b> — หน้างานจ่ายของด้วยแบบฟอร์มกระดาษ (ผู้เบิก · ผู้รับ · วัสดุ · จำนวน · G · ลายเซ็น)
    แล้วคีย์ย้อนหลังที่นี่ <b>ภายใน 1 วันทำการ</b>
    <ul>
      <li>ใช้กับ<b>ใบที่ไม่มีในระบบ</b> (ออกใบในเว็บไม่ได้) — ใบที่ออกในระบบแล้วให้ใช้ "Bypass ประตู — เลือกเลขเอกสาร" ด้านบน</li>
      <li>ระบบออกใบ <b>RD / OD / TD / IN สถานะ Completed</b> ที่ G ที่เลือก และ<b>ตัด (เบิก/โอนออก) หรือเพิ่ม (รับเข้า) สต๊อกทันที</b> โดยไม่ผ่านประตู ·
          TD = ตัดที่ต้นทางอย่างเดียว (ไซต์ปลายทางคีย์ใบรับเข้าเอง)</li>
      <li>เลขใบใช้<b>วันที่จ่ายจริง</b> · ใบติดป้าย <span class="pill p-warn">bypass</span> · ขึ้นในตรวจสอบประจำวัน / Dashboard ตามวันที่จ่ายจริง · ยกเลิกภายหลังไม่ได้</li>
      <li>กติกาเดียวกับใบปกติ: รหัส IC เท่านั้น · RD ไม่มีวัสดุ NAR · OD ใช้กับ NAR เท่านั้น · TD ทุกชนิด (รวม WMS) · ต้องแนบรูปแบบฟอร์ม</li>
      <li><b>ต้องแนบรูปสินค้าที่เบิกอย่างน้อยรายการละ 1 รูป</b> (RD / OD / TD — เหมือนหน้าถ่ายรูปยืนยัน) · ใบรับเข้า (IN) แนบได้ไม่บังคับ ·
          รายการละไม่เกิน <?= BYPASS_ITEM_PHOTO_MAX ?> รูป · หน้าเว็บย่อรูปให้ก่อนส่ง</li>
    </ul>
  </div>
</div>

<div class="card">
  <h2><i class="fa-solid fa-file-pen" aria-hidden="true"></i> คีย์ใบจากแบบฟอร์มกระดาษ <span class="sp">ใบที่ไม่มีในระบบ</span></h2>
  <form method="get" class="row" style="margin-bottom:.9rem">
    <label class="fld" for="bpProj" style="margin:0">โครงการ</label>
    <select id="bpProj" name="project" onchange="this.form.submit()">
      <?php foreach ($projects as $p): ?>
        <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $projectId ? 'selected' : '' ?>><?= e((string)$p['code']) ?> · <?= e((string)$p['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if (!$gates): ?><span class="pill p-bad">โครงการนี้ยังไม่มีประตู</span><?php endif; ?>
  </form>

  <form method="post" enctype="multipart/form-data" id="bpForm" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="project_id" value="<?= (int)$projectId ?>">

    <div class="form-section" style="margin-bottom:1rem">
      <label class="fld">ชนิดใบ</label>
      <div class="bp-type" role="radiogroup" aria-label="ชนิดใบ">
        <?php foreach (['RD' => 'RD เบิกวัสดุหลัก', 'OD' => 'OD เบิกเบ็ดเตล็ด', 'TD' => 'TD โอนย้ายข้ามไซต์', 'IN' => 'IN รับเข้าคลัง'] as $k => $lbl): ?>
          <label><input type="radio" name="doc_type" value="<?= $k ?>" <?= $type0 === $k ? 'checked' : '' ?>> <?= e($lbl) ?></label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="bp-grid">
      <div><label class="fld" for="bpGate">ประตู (G) ที่จ่าย/รับของจริง *</label>
        <select id="bpGate" name="gate_id" required>
          <option value="">— เลือกประตู —</option>
          <?php foreach ($gates as $g): ?>
            <option value="<?= (int)$g['id'] ?>" <?= $v('gate_id') === (string)$g['id'] ? 'selected' : '' ?>><?= e((string)$g['gate_code']) ?> · <?= e((string)$g['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div><label class="fld" for="bpAt">วันเวลาที่จ่ายจริง (ตามแบบฟอร์ม) *</label>
        <input id="bpAt" type="datetime-local" name="dispensed_at" required max="<?= e(date('Y-m-d\TH:i')) ?>"
               value="<?= e($v('dispensed_at')) ?>"></div>
      <div><label class="fld" for="bpReq"><span class="bp-only-out">ผู้เบิก</span><span class="bp-only-in">ผู้รับเข้า</span> (ในแบบฟอร์ม) *</label>
        <select id="bpReq" name="requester" required>
          <option value="">— เลือก —</option>
          <?php foreach ($people as $u): ?>
            <option value="<?= e((string)$u['username']) ?>" <?= $v('requester') === (string)$u['username'] ? 'selected' : '' ?>>
              <?= e(trim((string)$u['full_name']) !== '' ? (string)$u['full_name'] : (string)$u['username']) ?> (<?= e((string)$u['username']) ?><?= $u['role_code'] ? ' · ' . e((string)$u['role_code']) : '' ?>)</option>
          <?php endforeach; ?>
        </select></div>
      <div class="bp-only-td"><label class="fld" for="bpDest">ไซต์ปลายทาง (ตามแบบฟอร์มโอนย้าย) *</label>
        <select id="bpDest" name="dest_project_id">
          <option value="">— เลือกไซต์ปลายทาง —</option>
          <?php foreach ($destSites as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= $v('dest_project_id') === (string)$p['id'] ? 'selected' : '' ?>><?= e((string)$p['code']) ?> · <?= e((string)$p['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="bp-only-td"><label class="fld" for="bpDestC">ผู้รับของที่ไซต์ปลายทาง *</label>
        <input id="bpDestC" type="text" name="dest_contact" maxlength="150" placeholder="ชื่อ / เบอร์โทร" value="<?= e($v('dest_contact')) ?>"></div>
      <div class="bp-only-rdod"><label class="fld" for="bpRecv">ผู้รับ (ชุดผู้รับเหมา) *</label>
        <select id="bpRecv" name="receiver">
          <option value="">— เลือก หรือพิมพ์ช่องถัดไป —</option>
          <?php foreach ($subs as $s): ?>
            <option value="<?= e(fmtSubId((string)$s['sub_code'])) ?>" <?= $v('receiver') === fmtSubId((string)$s['sub_code']) ? 'selected' : '' ?>><?= e((string)$s['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="bp-only-rdod"><label class="fld" for="bpRecvT">หรือพิมพ์ชื่อผู้รับ</label>
        <input id="bpRecvT" type="text" name="receiver_text" maxlength="150" placeholder="เช่น DC:ช่างไฟฟ้า" value="<?= e($v('receiver_text')) ?>"></div>
      <div class="bp-only-rdod"><label class="fld" for="bpUse">พื้นที่ใช้งาน</label>
        <input id="bpUse" type="text" name="usage_area" maxlength="150" value="<?= e($v('usage_area')) ?>"></div>
      <div class="bp-only-in"><label class="fld" for="bpRs">เลขที่ RS / PO</label>
        <input id="bpRs" type="text" name="rs_no" maxlength="50" value="<?= e($v('rs_no')) ?>"></div>
    </div>

    <h3 style="font-size:.95rem;margin:1.1rem 0 .5rem"><i class="fa-solid fa-list-check" aria-hidden="true"></i> รายการตามแบบฟอร์ม</h3>
    <div class="tbl-wrap">
      <table class="tbl bp-items">
        <thead><tr><th style="width:44px">#</th><th>รหัส IC (พิมพ์รหัสหรือชื่อเพื่อค้น)</th><th style="width:140px">จำนวน</th><th style="width:180px"><span class="bp-ph-h">รูปสินค้าที่เบิก *</span></th><th class="bp-only-rdod" style="width:90px">หักเงิน</th><th style="width:44px"></th></tr></thead>
        <tbody id="bpRows"></tbody>
      </table>
    </div>
    <div style="margin-top:.5rem"><button type="button" class="ghost mini" id="bpAdd"><i class="fa-solid fa-plus" aria-hidden="true"></i> เพิ่มรายการ</button></div>

    <div class="bp-grid" style="margin-top:1rem">
      <div><label class="fld" for="bpPhoto">รูปแบบฟอร์มกระดาษ (มีลายเซ็น) *</label>
        <input id="bpPhoto" type="file" name="form_photo" accept="image/*" required></div>
      <div><label class="fld" for="bpNote">หมายเหตุ</label>
        <input id="bpNote" type="text" name="note" maxlength="200" placeholder="เช่น ระบบล่มทั้งวัน 26/09" value="<?= e($v('note')) ?>"></div>
    </div>

    <div class="bp-foot">
      <label class="chk bp-only-out" style="font-size:.85rem"><input type="checkbox" name="force" value="1" <?= !empty($old['force']) ? 'checked' : '' ?>>
        บันทึกตามแบบฟอร์มแม้ยอดในระบบที่ G ไม่พอ (ยอดที่ขาดปัดเป็น 0 + จดใน Error log)</label>
      <button type="submit"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> บันทึกใบ bypass</button>
    </div>
  </form>
</div>

<div class="card">
  <h2><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> ใบ bypass ล่าสุด <span class="sp"><?= count($recent) ?> ใบ</span></h2>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>เลขใบ</th><th>ชนิด</th><th>ประตู</th><th>จ่ายจริง</th><th>ผู้เบิก</th><th>ผู้รับ / RS</th><th class="num">รายการ</th><th>คีย์โดย</th><th>รูป</th></tr></thead>
      <tbody>
      <?php if (!$recent): ?>
        <tr><td colspan="9" class="empty">ยังไม่มีใบ bypass ของโครงการนี้</td></tr>
      <?php endif; ?>
      <?php foreach ($recent as $r): ?>
        <tr>
          <td class="mono"><b><?= e((string)$r['doc_no']) ?></b> <span class="pill p-warn">bypass</span></td>
          <td><?= e((string)$r['doc_type']) ?></td>
          <td class="mono"><?= e((string)($r['gate_code'] ?? '-')) ?></td>
          <td class="small"><?= e(substr((string)$r['doc_ts'], 0, 16)) ?></td>
          <td><?= e((string)$r['requester_username']) ?></td>
          <td><?= e((string)($r['doc_type'] === 'IN' ? ($r['rs_no'] ?? '-') : ($r['receiver_name'] ?? '-'))) ?></td>
          <td class="num"><?= (int)$r['n_items'] ?></td>
          <td class="small"><?= e((string)$r['approved_by']) ?><br><?= e(substr((string)$r['created_at'], 0, 16)) ?></td>
          <td><?php if (!empty($r['photo_url'])): ?><a href="<?= e(APP_BASE . '/' . ltrim((string)$r['photo_url'], '/')) ?>" target="_blank" rel="noopener">เปิดรูป</a><?php else: ?>-<?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(function () {
  'use strict';
  var form = document.getElementById('bpForm');
  var rowsEl = document.getElementById('bpRows');
  var BASE = <?= json_encode(APP_BASE . '/bypass.php') ?>;
  var PROJECT = <?= (int)$projectId ?>;
  var OLD = <?= json_encode([
      'mat' => array_values((array)($old['mat'] ?? [])), 'qty' => array_values((array)($old['qty'] ?? [])),
      'charge' => (array)($old['charge'] ?? []),
      'img' => (object)(array)($old['item_img'] ?? []),   // รูปสินค้าที่ส่งมาแล้วแต่บันทึกไม่ผ่าน — ใส่คืนให้ ไม่ต้องเลือกใหม่
  ], JSON_UNESCAPED_UNICODE) ?>;
  var PH_MAX = <?= (int)BYPASS_ITEM_PHOTO_MAX ?>, PH_TOTAL = <?= (int)BYPASS_PHOTO_TOTAL_MAX ?>;

  function type() { var r = form.querySelector('input[name=doc_type]:checked'); return r ? r.value : 'RD'; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function num(v) { var n = Math.round(Number(v) * 1000) / 1000; return isFinite(n) ? String(n) : '-'; }

  function syncType() {
    var t = type(), isIn = t === 'IN', isTd = t === 'TD', isRdOd = t === 'RD' || t === 'OD';
    document.querySelectorAll('.bp-only-out').forEach(function (el) { el.style.display = isIn ? 'none' : ''; });
    document.querySelectorAll('.bp-only-in').forEach(function (el) { el.style.display = isIn ? '' : 'none'; });
    document.querySelectorAll('.bp-only-rdod').forEach(function (el) { el.style.display = isRdOd ? '' : 'none'; });
    document.querySelectorAll('.bp-only-td').forEach(function (el) { el.style.display = isTd ? '' : 'none'; });
    var d = document.getElementById('bpDest'), dc = document.getElementById('bpDestC');
    if (d) d.required = isTd;
    if (dc) dc.required = isTd;
    var f = form.querySelector('input[name=force]'); if (f && isIn) f.checked = false;
    document.querySelectorAll('.bp-ph-h').forEach(function (el) { el.textContent = isIn ? 'รูปสินค้า (ไม่บังคับ)' : 'รูปสินค้าที่เบิก *'; });
    Array.prototype.forEach.call(rowsEl.children, markNeed);
  }

  // ---- รูปสินค้าที่เบิก: ย่อรูปในเครื่องก่อนส่ง (ค่าเดียวกับหน้าถ่ายรูปยืนยัน: ด้านยาว 1024 px · คุณภาพ 0.6 · WEBP → JPEG) ----
  function compress(file) {
    return new Promise(function (resolve) {
      if (!file || !file.type || file.type.indexOf('image/') !== 0) { resolve(''); return; }
      var reader = new FileReader();
      reader.onerror = function () { resolve(''); };
      reader.onload = function (e) {
        var raw = e.target.result;
        var img = new Image();
        img.onerror = function () { resolve(/^data:image\/(jpe?g|png|webp);/i.test(raw) ? raw : ''); };
        img.onload = function () {
          var w = img.naturalWidth || img.width, h = img.naturalHeight || img.height;
          if (!w || !h) { resolve(raw); return; }
          var r = Math.min(1024 / w, 1024 / h, 1), tw = Math.round(w * r), th = Math.round(h * r);
          try {
            var cv = document.createElement('canvas');
            cv.width = tw; cv.height = th;
            var ctx = cv.getContext('2d');
            ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, tw, th);
            ctx.drawImage(img, 0, 0, tw, th);
            var out = cv.toDataURL('image/webp', 0.6);
            if (out.indexOf('data:image/webp') !== 0) { out = cv.toDataURL('image/jpeg', 0.6); }
            resolve(out.length < raw.length ? out : raw);
          } catch (err) { resolve(raw); }
        };
        img.src = raw;
      };
      reader.readAsDataURL(file);
    });
  }
  function needPhotos() { return type() !== 'IN'; }
  function rowFilled(tr) {
    var c = tr.querySelector('input[name="mat[]"]'), q = tr.querySelector('input[name="qty[]"]');
    return !!((c && c.value.trim()) || (q && q.value.trim()));
  }
  function markNeed(tr) {
    var add = tr.querySelector('.bp-ph-add');
    if (add) add.classList.toggle('need', needPhotos() && rowFilled(tr) && !(tr.__ph || []).length);
  }
  function renderPhotos(tr) {
    var list = tr.querySelector('.bp-ph-list');
    var ph = tr.__ph || [];
    list.innerHTML = ph.map(function (u, k) {
      return '<span><img src="' + esc(u) + '" alt="รูปสินค้า ' + (k + 1) + '"><button type="button" data-k="' + k + '" aria-label="ลบรูป ' + (k + 1) + '">×</button></span>';
    }).join('');
    list.querySelectorAll('button').forEach(function (b) {
      b.addEventListener('click', function () { ph.splice(Number(b.getAttribute('data-k')), 1); renderPhotos(tr); });
    });
    tr.querySelector('.bp-ph-lbl').textContent = ph.length ? ph.length + ' รูป · เพิ่ม' : 'แนบรูป';
    markNeed(tr);
  }
  function wirePhotos(tr, initial) {
    tr.__ph = (initial || []).filter(function (u) { return typeof u === 'string' && u.indexOf('data:image/') === 0; });
    var inp = tr.querySelector('.bp-ph-add input');
    inp.addEventListener('change', function () {
      var files = Array.prototype.slice.call(inp.files || []);
      inp.value = '';
      var room = PH_MAX - tr.__ph.length;
      if (!files.length) return;
      if (room <= 0) { alert('รูปสินค้าได้ไม่เกินรายการละ ' + PH_MAX + ' รูป'); return; }
      if (files.length > room) { alert('รูปสินค้าได้ไม่เกินรายการละ ' + PH_MAX + ' รูป — เพิ่มได้อีก ' + room + ' รูป'); files = files.slice(0, room); }
      var busy = document.createElement('span');
      busy.className = 'bp-ph-busy';
      busy.textContent = 'กำลังย่อรูป…';
      tr.querySelector('.bp-ph-list').appendChild(busy);
      Promise.all(files.map(compress)).then(function (urls) {
        var bad = 0;
        urls.forEach(function (u) { if (u) { tr.__ph.push(u); } else { bad++; } });
        renderPhotos(tr);
        if (bad) alert(bad + ' ไฟล์ไม่ใช่รูปที่ใช้ได้ (JPG / PNG / WEBP)');
      });
    });
    ['mat[]', 'qty[]'].forEach(function (n) {
      var el = tr.querySelector('input[name="' + n + '"]');
      if (el) el.addEventListener('input', function () { markNeed(tr); });
    });
    renderPhotos(tr);
  }

  function renumber() {
    Array.prototype.forEach.call(rowsEl.children, function (tr, i) {
      tr.querySelector('.bp-no').textContent = i + 1;
      var c = tr.querySelector('input[type=checkbox]'); if (c) c.name = 'charge[' + i + ']';
    });
  }

  function addRow(code, qty, charge, photos) {
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td class="bp-no"></td>' +
      '<td class="bp-cell"><input type="text" name="mat[]" maxlength="50" class="mono" placeholder="เช่น ARC03001… หรือชื่อวัสดุ" value="' + esc(code || '') + '">' +
        '<div class="bp-name"></div></td>' +
      '<td><input type="number" name="qty[]" min="0" step="any" value="' + esc(qty || '') + '"></td>' +
      '<td class="bp-ph"><label class="bp-ph-add"><i class="fa-solid fa-camera" aria-hidden="true"></i> <span class="bp-ph-lbl">แนบรูป</span>' +
        '<input type="file" accept="image/*" multiple aria-label="แนบรูปสินค้าที่เบิก"></label><div class="bp-ph-list"></div></td>' +
      '<td class="bp-only-rdod"><input type="checkbox" value="1"' + (charge ? ' checked' : '') + ' aria-label="หักเงิน"></td>' +
      '<td><button type="button" class="ghost mini bp-del" aria-label="ลบรายการ"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></td>';
    rowsEl.appendChild(tr);
    tr.querySelector('.bp-del').addEventListener('click', function () { tr.remove(); if (!rowsEl.children.length) addRow(); renumber(); });
    wireSearch(tr);
    wirePhotos(tr, photos);
    renumber();
    syncType();
  }

  var timer = null;
  function wireSearch(tr) {
    var inp = tr.querySelector('input[name="mat[]"]');
    var nameEl = tr.querySelector('.bp-name');
    var box = null;
    function close() { if (box) { box.remove(); box = null; } }
    inp.addEventListener('input', function () {
      clearTimeout(timer);
      var q = inp.value.trim();
      nameEl.textContent = '';
      if (q.length < 2) { close(); return; }
      timer = setTimeout(function () {
        var gate = (document.getElementById('bpGate') || {}).value || '';
        fetch(BASE + '?ajax=mat&project=' + PROJECT + '&gate=' + encodeURIComponent(gate) + '&type=' + type() + '&q=' + encodeURIComponent(q),
              { credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            close();
            if (!d || !d.rows || !d.rows.length) { nameEl.textContent = 'ไม่พบรหัส IC ที่ตรงกับชนิดใบนี้'; return; }
            box = document.createElement('div');
            box.className = 'bp-sug';
            d.rows.forEach(function (m) {
              var b = document.createElement('button');
              b.type = 'button';
              var av = m.avail == null ? 'ยังไม่มีที่ G นี้' : 'พร้อมเบิก ' + num(m.avail) + ' ' + (m.unit || '');
              b.innerHTML = '<b>' + esc(m.code) + '</b> ' + esc(m.name) + ' <span class="small">· ' + esc(av) + '</span>';
              b.addEventListener('mousedown', function (ev) {
                ev.preventDefault();
                inp.value = m.code;
                nameEl.innerHTML = esc(m.name) + ' <span class="bp-avail' + (m.avail != null && m.avail <= 0 ? ' low' : '') + '">· ' + esc(av) + '</span>';
                close();
              });
              box.appendChild(b);
            });
            inp.parentNode.appendChild(box);
          })
          .catch(function () { nameEl.textContent = 'ค้นหาไม่สำเร็จ'; });
      }, 250);
    });
    inp.addEventListener('blur', function () { setTimeout(close, 150); });
  }

  form.querySelectorAll('input[name=doc_type]').forEach(function (r) { r.addEventListener('change', syncType); });
  document.getElementById('bpAdd').addEventListener('click', function () { addRow(); });
  if (OLD.mat && OLD.mat.length) {
    OLD.mat.forEach(function (c, i) { if (c || OLD.qty[i]) addRow(c, OLD.qty[i], OLD.charge && OLD.charge[i], OLD.img && OLD.img[i]); });
  }
  if (!rowsEl.children.length) { addRow(); addRow(); addRow(); }
  form.addEventListener('submit', function (ev) {
    var n = 0;
    rowsEl.querySelectorAll('input[name="mat[]"]').forEach(function (i) { if (i.value.trim()) n++; });
    if (!n) { ev.preventDefault(); alert('ใส่รายการวัสดุอย่างน้อย 1 รายการ'); return; }
    // รูปสินค้าที่เบิก: RD / OD / TD ต้องมีอย่างน้อยรายการละ 1 รูป (server ตรวจซ้ำ)
    var missing = [], total = 0;
    Array.prototype.forEach.call(rowsEl.children, function (tr, i) {
      if (!rowFilled(tr)) return;
      var k = (tr.__ph || []).length;
      total += k;
      if (needPhotos() && !k) missing.push(i + 1);
    });
    if (missing.length) { ev.preventDefault(); alert('แนบรูปสินค้าที่เบิกอย่างน้อยรายการละ 1 รูป — ยังไม่มีรูป: รายการที่ ' + missing.join(', ')); return; }
    if (total > PH_TOTAL) { ev.preventDefault(); alert('รูปสินค้ารวมได้ไม่เกิน ' + PH_TOTAL + ' รูปต่อใบ (ตอนนี้ ' + total + ' รูป) — แยกเป็นหลายใบ'); return; }
    if (!confirm('บันทึกใบ ' + type() + ' แบบ bypass (' + n + ' รายการ · รูปสินค้า ' + total + ' รูป)?\nสต๊อกจะถูก' + (type() === 'IN' ? 'เพิ่ม' : 'ตัด') + 'ทันทีและยกเลิกภายหลังไม่ได้')) { ev.preventDefault(); return; }
    // รูปที่ย่อแล้ว → ช่องซ่อน item_img[<แถว>][] (ลำดับแถวตรงกับ mat[] / qty[])
    var old = document.getElementById('bpImgHidden');
    if (old) old.remove();
    var box = document.createElement('div');
    box.id = 'bpImgHidden';
    box.hidden = true;
    Array.prototype.forEach.call(rowsEl.children, function (tr, i) {
      (tr.__ph || []).forEach(function (u) {
        var h = document.createElement('input');
        h.type = 'hidden';
        h.name = 'item_img[' + i + '][]';
        h.value = u;
        box.appendChild(h);
      });
    });
    form.appendChild(box);
  });
  syncType();

  // ---- Bypass ประตู: ค้นเลขเอกสาร · นับที่เลือก · ยืนยันก่อนเปิด/ปิด ----
  var gForm = document.getElementById('bpGateForm');
  if (gForm) {
    var search = document.getElementById('bpDocSearch');
    var cnt = document.getElementById('bpSelCount');
    var boxes = gForm.querySelectorAll('input[name="bp_doc[]"]');
    var sync = function () {
      var n = 0;
      boxes.forEach(function (b) { var tr = b.closest('tr'); if (tr) tr.classList.toggle('bp-sel', b.checked); if (b.checked) n++; });
      if (cnt) cnt.textContent = n;
    };
    boxes.forEach(function (b) { b.addEventListener('change', sync); });
    if (search) search.addEventListener('input', function () {
      var q = search.value.trim().toLowerCase();
      gForm.querySelectorAll('tbody tr[data-q]').forEach(function (tr) { tr.classList.toggle('bp-hide', q !== '' && tr.getAttribute('data-q').indexOf(q) === -1); });
    });
    gForm.addEventListener('submit', function (ev) {
      var docs = [];
      boxes.forEach(function (b) { if (b.checked) docs.push(b.value); });
      if (!docs.length) { ev.preventDefault(); alert('เลือกเลขเอกสารที่จะ bypass เปิดอย่างน้อย 1 ใบ'); return; }
      if (!confirm('Bypass เปิด ' + docs.length + ' ใบ แทนการแตะบัตรที่ตู้?\n' + docs.join(', ') + '\n\nจากนั้นให้ผู้ขอ/สโตร์ถ่ายรูปยืนยันตามปกติ แล้วกลับมากด Bypass ปิด')) ev.preventDefault();
    });
    sync();
  }
  document.querySelectorAll('.bp-close-form').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      var pk = (f.querySelector('input[name=pk]') || {}).value || '';
      if (!confirm('Bypass ปิดรอบ ' + pk + ' แทนตู้?\nระบบจะปิดงานและตัด/เพิ่มสต๊อกตามจำนวนที่ยืนยันทันที (ย้อนกลับไม่ได้)')) ev.preventDefault();
    });
  });
})();
</script>
<?php
uiFoot();
