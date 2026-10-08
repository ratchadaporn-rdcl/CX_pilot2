<?php
/**
 * CONNEXT — lib/ic_edit.php : แก้ไข IC ที่ออกไปแล้ว — ใช้ได้แม้ย้ายยอดมาอยู่บน IC แล้ว (มติ 46)
 * (หน้า ic_edit.php · ADM เท่านั้น)
 *
 * แก้ได้ 2 แบบ
 *   1. ข้อมูลที่ไม่ใช่รหัส (iceUpdateInfo) — ชื่อ IC · Serial · CX · เปิด/ปิดใช้งาน
 *      ชื่อเขียนตามไปที่แถวคู่ใน materials (มติ 3) · CatID/CharID เป็นของ LLP (มติ 28-29) แก้ที่ llp_master.php
 *      ปิดใช้งานได้เฉพาะ IC ที่ไม่มียอด/ยอดจอง/แถววัสดุในโครงการเหลือ — ไม่งั้นของค้างในฟอร์มเบิก
 *   2. สเปกที่เป็นส่วนหนึ่งของรหัส (iceRecode) — ตัวสินค้า/ขนาด/ยี่ห้อ/หน่วย/คุณสมบัติ
 *      รหัสเปลี่ยน = ออก/ใช้ IC ปลายทาง แล้วย้ายทุกอย่างของตัวเดิมไปทั้งก้อนในทรานแซกชันเดียว:
 *      ยอด + ยอดรายประตู + วัสดุในโครงการ + ราคาหักเงิน (smMoveWhole — ปลายทางมีอยู่แล้ว = บวกรวม) ·
 *      บรรทัดเอกสาร · ราคาที่ล็อกในใบหักเงิน · push จาก PO · การผูก Mango (+ LLP ถ้าเปลี่ยนตัวสินค้า) ·
 *      ความจำ ic_suggest_map · แล้วปิดใช้งานตัวเดิม + คิดยอดจองใหม่
 *      ⚠ จำนวนยกไปตามเดิม ไม่แปลงหน่วย (เปลี่ยน "ไม่ระบุ" → "อัน" ได้ · "ชุด" → "แกลลอน" ตัวเลขจะผิดความหมาย)
 *      เปลี่ยนตัวสินค้าได้เมื่อ Mango ที่ผูกกับ IC นี้ไม่มี IC อื่นอีก (1 Mango = 1 LLP · มติ 45)
 *
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/ic.php';
require_once __DIR__ . '/setup_master.php';   // smMoveWhole · smFixPrimary · smRememberSuggest · smLog
require_once __DIR__ . '/stock.php';          // recalcPending

/**
 * IC ที่ถูกปิดเพราะ "เปลี่ยนสเปก" ย้ายไปรหัสไหน — อ่านจาก log ic_recode (ตัวล่าสุดชนะ)
 * @param int[] $materialIds id ของแถว materials คู่ของ IC ตัวเดิม
 * @return array [material_id => ['ic' => รหัสปลายทาง, 'at' => เวลา]]
 */
function iceMovedTo(PDO $pdo, array $materialIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $materialIds))));
    if (!$ids) { return []; }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT entity_id, new_value, created_at FROM activity_log
                          WHERE entity_type = 'material' AND action = 'ic_recode' AND entity_id IN ($ph) ORDER BY id");
    $st->execute(array_map('strval', $ids));
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $v = json_decode((string)$r['new_value'], true);
        if (is_array($v) && !empty($v['ic_code'])) {
            $out[(int)$r['entity_id']] = ['ic' => (string)$v['ic_code'], 'at' => (string)$r['created_at']];
        }
    }
    return $out;
}

/**
 * รหัสเก่าที่ถูกเปลี่ยนเลขในที่ (จัดเลขตาม Flow Hub มติ 48 — activity_log 'ic_renumber') → รหัสปัจจุบัน
 * เปลี่ยนเลขในที่ = แถว materials เดิม → รหัสปัจจุบันคือ mat_code ของแถวนั้น (เปลี่ยนกี่รอบก็ตามถูก)
 * @return array|null ['ic' => รหัสปัจจุบัน, 'at' => เวลาที่เปลี่ยน] · null = ไม่เคยเปลี่ยนเลข
 */
function iceRenumbered(PDO $pdo, string $old): ?array {
    if (!preg_match('/^[A-Z0-9]{8,20}$/', $old)) { return null; }
    $st = $pdo->prepare("SELECT entity_id, created_at FROM activity_log
                          WHERE entity_type = 'material' AND action = 'ic_renumber' AND old_value LIKE ? ORDER BY id DESC LIMIT 1");
    $st->execute(['%"ic_code":"' . $old . '"%']);
    $r = $st->fetch();
    if (!$r || !ctype_digit((string)$r['entity_id'])) { return null; }
    $m = $pdo->prepare("SELECT mat_code FROM materials WHERE id = ? AND code_type = 'ic'");
    $m->execute([(int)$r['entity_id']]);
    $cur = (string)$m->fetchColumn();
    return ($cur !== '' && $cur !== $old) ? ['ic' => $cur, 'at' => (string)$r['created_at']] : null;
}

/** แถว materials คู่ของ IC — คืน id หรือ 0 */
function iceMaterialId(PDO $pdo, string $ic): int {
    $st = $pdo->prepare("SELECT id FROM materials WHERE mat_code = ? AND code_type = 'ic'");
    $st->execute([$ic]);
    return (int)$st->fetchColumn();
}

/**
 * ข้อมูลประกอบหน้าจอ — IC + ชื่อชิ้นรหัส + การใช้งานทั้งหมด
 * @return array|null ['ic'=>แถว, 'extra_name', 'material_id', 'stock'=>[ต่อโครงการ], 'on_hand', 'pending',
 *                     'n_pm', 'n_doc', 'n_rate', 'n_push', 'mango'=>[...], 'llp_ic_count']
 */
function iceUsage(PDO $pdo, string $ic): ?array {
    $ic  = strtoupper(trim($ic));
    $row = icGet($pdo, $ic);
    if ($row === null) { return null; }
    $st = $pdo->prepare('SELECT extra_name FROM extra_attrs WHERE llp_code = ? AND extra_code = ?');
    $st->execute([(string)$row['llp_code'], (string)$row['extra_code']]);
    $extraName = (string)$st->fetchColumn();

    $mid = iceMaterialId($pdo, $ic);
    $stock = []; $onHand = 0.0; $pending = 0.0; $nPm = 0; $nDoc = 0; $nRate = 0;
    if ($mid > 0) {
        $st = $pdo->prepare('SELECT b.project_id, p.code, p.name, b.qty_in, b.qty_out, b.on_hand, b.pending
                               FROM stock_balances b JOIN projects p ON p.id = b.project_id
                              WHERE b.material_id = ? ORDER BY p.code');
        $st->execute([$mid]);
        $stock = $st->fetchAll();
        foreach ($stock as $s) { $onHand += (float)$s['on_hand']; $pending += (float)$s['pending']; }
        $c = function (string $sql) use ($pdo, $mid) { $s = $pdo->prepare($sql); $s->execute([$mid]); return (int)$s->fetchColumn(); };
        $nPm   = $c('SELECT COUNT(*) FROM project_materials WHERE material_id = ?');
        $nDoc  = $c('SELECT COUNT(*) FROM document_items WHERE material_id = ?');
        $nRate = $c('SELECT COUNT(*) FROM rate_cards WHERE material_id = ?');
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM push_allocs WHERE ic_code = ?');
    $st->execute([$ic]);
    $nPush = (int)$st->fetchColumn();

    $st = $pdo->prepare('SELECT x.mat_code, x.llp_code, x.is_primary, m.name, m.unit,
                                (SELECT COUNT(*) FROM mango_ic_map y WHERE y.mat_code = x.mat_code AND y.ic_code <> \'\') AS n_ic
                           FROM mango_ic_map x LEFT JOIN materials m ON m.mat_code = x.mat_code AND m.code_type = \'mango\'
                          WHERE x.ic_code = ? ORDER BY x.mat_code');
    $st->execute([$ic]);
    $mango = $st->fetchAll();

    $st = $pdo->prepare('SELECT COUNT(*) FROM ic_items WHERE llp_code = ?');
    $st->execute([(string)$row['llp_code']]);

    return [
        'ic' => $row, 'extra_name' => $extraName, 'material_id' => $mid,
        'stock' => $stock, 'on_hand' => $onHand, 'pending' => $pending,
        'n_pm' => $nPm, 'n_doc' => $nDoc, 'n_rate' => $nRate, 'n_push' => $nPush,
        'mango' => $mango, 'llp_ic_count' => (int)$st->fetchColumn(),
    ];
}

/**
 * แก้ข้อมูลที่ไม่ใช่รหัส — $p: ic_name, has_serial, is_cx, is_active (ไม่ส่งมา = คงเดิม)
 * @return array ['ok','error','changed'=>[ช่องที่เปลี่ยน]]
 */
function iceUpdateInfo(PDO $pdo, string $ic, array $p, ?array $user = null): array {
    $fail = function (string $m) { return ['ok' => false, 'error' => $m, 'changed' => []]; };
    $ic   = strtoupper(trim($ic));
    $u    = iceUsage($pdo, $ic);
    if ($u === null) { return $fail('ไม่พบรหัส IC ' . $ic); }
    $cur  = $u['ic'];
    $flags = icHasFlagColumns($pdo);

    $new = [];
    if (array_key_exists('ic_name', $p)) {
        $name = trim(preg_replace('/\s+/u', ' ', (string)$p['ic_name']));
        if ($name === '')                        { return $fail('ชื่อ IC ต้องไม่ว่าง'); }
        if (mb_strlen($name, 'UTF-8') > 255)     { return $fail('ชื่อ IC ยาวเกิน 255 ตัวอักษร'); }
        if ($name !== (string)$cur['ic_name'])   { $new['ic_name'] = $name; }
    }
    foreach (['has_serial', 'is_cx'] as $k) {
        if ($flags && array_key_exists($k, $p)) {
            $v = isTrueFlag($p[$k]) ? 1 : 0;
            if ($v !== (int)($cur[$k] ?? 0)) { $new[$k] = $v; }
        }
    }
    if (array_key_exists('is_active', $p)) {
        $v = isTrueFlag($p['is_active']) ? 1 : 0;
        if ($v !== (int)$cur['is_active']) {
            if ($v === 0 && (abs($u['on_hand']) > 0.0005 || abs($u['pending']) > 0.0005 || $u['n_pm'] > 0)) {
                return $fail('ปิดใช้งานไม่ได้ — ยังมียอด/ยอดจอง หรืออยู่ในรายการวัสดุของโครงการ '
                           . '(ย้ายไปรหัสอื่นด้วย "เปลี่ยนสเปก" ก่อน ระบบจะปิดตัวเดิมให้เอง)');
            }
            $new['is_active'] = $v;
        }
    }
    if (!$new) { return ['ok' => true, 'error' => '', 'changed' => []]; }

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) { $pdo->beginTransaction(); }
    try {
        $set = []; $args = [];
        foreach ($new as $k => $v) { $set[] = $k . ' = ?'; $args[] = $v; }
        $args[] = $ic;
        $pdo->prepare('UPDATE ic_items SET ' . implode(', ', $set) . ' WHERE ic_code = ?')->execute($args);
        if (isset($new['ic_name'])) {
            $pdo->prepare("UPDATE materials SET name = ? WHERE mat_code = ? AND code_type = 'ic'")->execute([$new['ic_name'], $ic]);
        }
        $old = [];
        foreach (array_keys($new) as $k) { $old[$k] = $cur[$k] ?? null; }
        smLog($pdo, $user, $u['material_id'] ?: $ic, 'ic_edit', ['ic_code' => $ic] + $old, ['ic_code' => $ic] + $new);
        if ($ownTx) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
        return $fail('บันทึกไม่สำเร็จ: ' . $e->getMessage());
    }
    return ['ok' => true, 'error' => '', 'changed' => array_keys($new)];
}

/**
 * เปลี่ยนสเปก = ย้าย IC เดิมไปรหัสใหม่ (หรือรวมเข้า IC ที่มีอยู่แล้ว) พร้อมทุกอย่างที่อ้างถึง
 * $parts: llp_code, size_code, brand_code, unit_code, extra_code · $name: ชื่อ IC ปลายทาง (ว่าง = ใช้ชื่อเดิม;
 * ปลายทางมีอยู่แล้วชื่อของมันชนะ)
 * @return array ['ok','error','ic_code'=>ปลายทาง,'created'=>bool,'merged'=>bool,'n'=>จำนวนที่ย้าย]
 */
function iceRecode(PDO $pdo, string $ic, array $parts, string $name = '', ?array $user = null): array {
    $fail = function (string $m) { return ['ok' => false, 'error' => $m, 'ic_code' => '', 'created' => false, 'merged' => false, 'n' => []]; };
    $ic = strtoupper(trim($ic));
    $u  = iceUsage($pdo, $ic);
    if ($u === null) { return $fail('ไม่พบรหัส IC ' . $ic); }
    $src = $u['ic'];

    $p = [
        'llp_code'   => strtoupper(trim((string)($parts['llp_code'] ?? $src['llp_code']))),
        'size_code'  => trim((string)($parts['size_code'] ?? IC_NONE)),
        'brand_code' => trim((string)($parts['brand_code'] ?? IC_NONE)),
        'unit_code'  => trim((string)($parts['unit_code'] ?? '')),
        'extra_code' => trim((string)($parts['extra_code'] ?? IC_NONE)),
    ];
    $v = icValidateParts($pdo, $p);
    if (!$v['ok']) { return $fail($v['error']); }
    $to = icCompose($p['llp_code'], $p['size_code'], $p['brand_code'], $p['unit_code'], $p['extra_code']);
    if ($to === $ic) { return $fail('สเปกที่เลือกได้รหัสเดิม (' . $ic . ') — ไม่มีอะไรต้องย้าย ถ้าจะแก้ชื่อใช้ช่อง "ข้อมูลทั่วไป"'); }

    $newLlp = $p['llp_code'] !== (string)$src['llp_code'];
    if ($newLlp) {
        // 1 Mango = 1 LLP (มติ 45) — Mango ที่มี IC อื่นด้วยจะมีตัวสินค้า 2 ตัวถ้าย้ายตัวนี้ไป
        foreach ($u['mango'] as $m) {
            if ((int)$m['n_ic'] > 1) {
                return $fail('เปลี่ยนตัวสินค้าไม่ได้ — ' . $m['mat_code'] . ' ผูก IC ไว้ ' . (int)$m['n_ic'] . ' ตัวใต้ '
                           . $src['llp_code'] . ' (1 Mango มีตัวสินค้าได้ตัวเดียว) · ถอด IC อื่นของรหัสนั้นก่อน หรือเปลี่ยนแค่ขนาด/หน่วย/คุณสมบัติ');
            }
        }
    }
    $srcMid = $u['material_id'];
    $by     = (string)($user['username'] ?? '');
    $n = ['bal' => 0, 'gate' => 0, 'pm' => 0, 'rate' => 0, 'doc' => 0, 'ddr' => 0, 'push' => 0, 'po' => 0, 'map' => 0, 'suggest' => 0];

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) { $pdo->beginTransaction(); }
    try {
        // ปลายทาง — มีอยู่แล้ว = รวม (ชื่อ/ธงของมันชนะ) · ไม่มี = ออกใหม่ด้วยชื่อ/ธงของตัวเดิม
        $exists = icGet($pdo, $to) !== null;
        $c = icCreate($pdo, $p + [
            'ic_name'    => $exists ? '' : ($name !== '' ? $name : (string)$src['ic_name']),
            'has_serial' => (int)($src['has_serial'] ?? 0),
            'is_cx'      => (int)($src['is_cx'] ?? 1),
        ], $user);
        if (!$c['ok']) { throw new RuntimeException($c['error']); }
        $dstMid = (int)$c['material_id'];
        if ($exists) {
            $pdo->prepare('UPDATE ic_items SET is_active = 1 WHERE ic_code = ?')->execute([$to]);
        }

        if ($srcMid > 0 && $dstMid > 0) {
            // ยอด / ยอดรายประตู / วัสดุในโครงการ / ราคาหักเงิน — ต่อโครงการ ด้วยกลไกเดียวกับย้าย Mango → IC
            $st = $pdo->prepare('SELECT project_id FROM stock_balances WHERE material_id = ?
                                 UNION SELECT project_id FROM stock_gate_balances WHERE material_id = ?
                                 UNION SELECT project_id FROM project_materials WHERE material_id = ?
                                 UNION SELECT project_id FROM rate_cards WHERE material_id = ?');
            $st->execute([$srcMid, $srcMid, $srcMid, $srcMid]);
            $projects = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            foreach ($projects as $P) {
                $m = smMoveWhole($pdo, $P, $srcMid, $dstMid, $by);
                foreach ($m as $k => $x) { $n[$k] += $x; }
            }
            // บรรทัดเอกสาร — ชื่อ/หน่วยที่บันทึกไว้ในใบคงเดิม
            $st = $pdo->prepare('UPDATE document_items SET material_id = ?, mat_code = ?
                                  WHERE material_id = ? OR (material_id IS NULL AND mat_code = ?)');
            $st->execute([$dstMid, $to, $srcMid, $ic]);
            $n['doc'] = $st->rowCount();
            $st = $pdo->prepare('UPDATE po_lines SET material_id = ? WHERE material_id = ?');
            $st->execute([$dstMid, $srcMid]);
            $n['po'] = $st->rowCount();
            $touched = $projects;
            $st = $pdo->prepare('SELECT DISTINCT d.project_id FROM document_items di JOIN documents d ON d.id = di.document_id WHERE di.material_id = ?');
            $st->execute([$dstMid]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $P) { $touched[] = (int)$P; }
        } else {
            $touched = [];
        }
        // ราคาที่ล็อกในใบหักเงิน — คีย์ (doc, mat_code) ชนได้ถ้าใบเดียวมีทั้งสองรหัส (เหมือน smMigrate)
        $st = $pdo->prepare('UPDATE IGNORE deduction_doc_rates SET mat_code = ? WHERE mat_code = ?');
        $st->execute([$to, $ic]);
        $n['ddr'] = $st->rowCount();
        $pdo->prepare('DELETE FROM deduction_doc_rates WHERE mat_code = ?')->execute([$ic]);
        $st = $pdo->prepare('UPDATE push_allocs SET ic_code = ?, material_id = ? WHERE ic_code = ?');
        $st->execute([$to, $dstMid ?: null, $ic]);
        $n['push'] = $st->rowCount();

        // การผูก Mango — ย้ายแถวไปรหัสใหม่ (ตัวสินค้าเปลี่ยนก็เปลี่ยนตาม) · มีแถวปลายทางอยู่แล้ว = รวมแถว
        foreach ($u['mango'] as $m) {
            $mat = (string)$m['mat_code'];
            $ex = $pdo->prepare('SELECT id FROM mango_ic_map WHERE mat_code = ? AND ic_code = ?');
            $ex->execute([$mat, $to]);
            $dstRow = $ex->fetchColumn();
            if ($dstRow !== false) {
                if ((int)$m['is_primary'] === 1) {
                    $pdo->prepare('UPDATE mango_ic_map SET is_primary = 1 WHERE id = ?')->execute([(int)$dstRow]);
                }
                $pdo->prepare('DELETE FROM mango_ic_map WHERE mat_code = ? AND ic_code = ?')->execute([$mat, $ic]);
            } else {
                $pdo->prepare('UPDATE mango_ic_map SET ic_code = ?, llp_code = ? WHERE mat_code = ? AND ic_code = ?')
                    ->execute([$to, $p['llp_code'], $mat, $ic]);
            }
            if ($newLlp) {   // แถวขั้น 1 ของ LLP เดิม (ถ้าค้าง) ต้องหายไปด้วย — 1 Mango = 1 LLP
                $pdo->prepare("DELETE FROM mango_ic_map WHERE mat_code = ? AND llp_code <> ?")->execute([$mat, $p['llp_code']]);
            }
            smFixPrimary($pdo, $mat);
            smRememberSuggest($pdo, $mat);
            $n['map']++;
        }
        $st = $pdo->prepare('UPDATE IGNORE ic_suggest_map SET ic_code = ? WHERE ic_code = ?');
        $st->execute([$to, $ic]);
        $n['suggest'] = $st->rowCount();
        $pdo->prepare('DELETE FROM ic_suggest_map WHERE ic_code = ?')->execute([$ic]);

        // ตัวเดิมปิดใช้งาน (เก็บไว้เป็นประวัติ — รหัสนี้ไม่ถูกนำกลับมาใช้)
        $pdo->prepare('UPDATE ic_items SET is_active = 0 WHERE ic_code = ?')->execute([$ic]);

        foreach (array_unique($touched) as $P) { recalcPending($pdo, (int)$P); }

        smLog($pdo, $user, $srcMid ?: $ic, 'ic_recode',
            ['ic_code' => $ic, 'ic_name' => (string)$src['ic_name'], 'unit' => (string)$src['unit_name']],
            ['ic_code' => $to, 'created' => !$exists, 'merged' => $exists, 'n' => $n]);
        if ($ownTx) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('iceRecode ' . $ic . ' → ' . $to . ': ' . $e->getMessage());
        return $fail('ย้ายไม่สำเร็จ — ไม่มีอะไรถูกเปลี่ยน: ' . $e->getMessage());
    }
    return ['ok' => true, 'error' => '', 'ic_code' => $to, 'created' => !$exists, 'merged' => $exists, 'n' => $n];
}
