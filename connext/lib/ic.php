<?php
/**
 * CONNEXT — lib/ic.php : ประกอบ/ตรวจ/ออกรหัส IC 20 ตัวอักษร
 *
 * เฟส 1 ของสาย PO → OCR → buffer → IcCode (มติ: db/design_po_ocr_ic_v1.md)
 *
 *   ic_code (20) = llp_code(8) + size(3) + brand(3) + unit(3) + extra(3)
 *   llp_code (8) = l1(3) + l2(2) + product(3)
 *
 * กติกาที่บังคับที่ชั้น PHP เสมอ (CHECK ใน DB บังคับจริงเฉพาะ MariaDB 10.2+):
 *   · llp ต้องมีอยู่และยัง active
 *   · size ต้องอยู่ใน l2_sizes ของ (l1,l2) นั้น · brand ต้องอยู่ใน l2_brands
 *   · unit ต้องมีในตาราง units
 *   · extra = '000' (ไม่มี) หรือต้องมีใน extra_attrs ของ llp นั้น
 *
 * มติ 3: IC ที่ออกแล้วต้องมีแถวคู่ใน materials (code_type='ic') เพื่อให้เข้า
 * stock_balances / เบิก / QR / หักเงิน ด้วยกลไกเดิมทั้งหมด
 *
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/../helpers.php';

const IC_NONE = '000';   // size/brand/extra ที่แปลว่า "ไม่ระบุ / ไม่มี"

/**
 * ── CatID / CharID — ลิสต์กลางของทั้งระบบ (มติ 31) ────────────────────────
 * เดิมลิสต์นี้กระจายอยู่ 3 ที่ (ic_new.php เป็น dropdown · admin.php เป็นช่องพิมพ์
 * อิสระ · index.php เทียบสตริงตรง ๆ) — ย้ายมารวมที่เดียวเพื่อให้แก้ที่เดียวจบ
 *
 * CharID คุมว่าวัสดุโผล่ในฟอร์มไหนของ SPA (index.php กรองแบบตรงตัว ไม่ยืดหยุ่น):
 *   CSB → ฟอร์มเบิกวัสดุหลัก · NAR → เบ็ดเตล็ด · BRB → ยืม-คืน
 *   WMS → ไม่โผล่ในฟอร์มเบิกใด ๆ ดูยอดได้จากการ์ด "วัสดุ WMS" บน Dashboard
 * ⚠ เพิ่มค่าใหม่ตรงนี้เฉย ๆ ไม่พอ — index.php ถูก generate จาก GAS ต้องแก้ที่
 *   tools/build_index.py ด้วย ไม่งั้นค่าใหม่จะไม่มีฟอร์มไหนรองรับ
 */
const IC_CAT_IDS  = ['C01', 'C02', 'NAR'];
const IC_CHAR_IDS = ['CSB', 'BRB', 'NAR', 'WMS'];

/** ป้ายกำกับสำหรับ dropdown — คีย์ต้องตรงกับ IC_CAT_IDS เป๊ะ */
function icCatLabels(): array {
    return [
        'C01' => 'C01 · วัสดุควบคุม (อนุมัติ R6+)',
        'C02' => 'C02 · วัสดุทั่วไป (อนุมัติ R4+)',
        'NAR' => 'NAR · ไม่เข้าเส้นทางอนุมัติ',
    ];
}

/** ป้ายกำกับสำหรับ dropdown — คีย์ต้องตรงกับ IC_CHAR_IDS เป๊ะ */
function icCharLabels(): array {
    return [
        'CSB' => 'CSB · เบิกวัสดุหลัก',
        'BRB' => 'BRB · ยืม-คืน',
        'NAR' => 'NAR · เบ็ดเตล็ด',
        'WMS' => 'WMS · ไม่เข้าฟอร์มเบิก (ดูยอดอย่างเดียว)',
    ];
}

/** ทำให้เป็นค่ามาตรฐาน — คืน null ถ้าไม่อยู่ในลิสต์ (มติ 30: ไม่ fallback เงียบ) */
function icNormalizeCat(?string $v): ?string {
    $v = strtoupper(trim((string)$v));
    return in_array($v, IC_CAT_IDS, true) ? $v : null;
}

/** ทำให้เป็นค่ามาตรฐาน — คืน null ถ้าไม่อยู่ในลิสต์ */
function icNormalizeChar(?string $v): ?string {
    $v = strtoupper(trim((string)$v));
    return in_array($v, IC_CHAR_IDS, true) ? $v : null;
}

/** ประกอบรหัส 20 ตัวอักษรจากชิ้นส่วน */
function icCompose(string $llp, string $size, string $brand, string $unit, string $extra = IC_NONE): string {
    return strtoupper($llp) . $size . $brand . $unit . ($extra === '' ? IC_NONE : $extra);
}

/** แยกรหัส 20 ตัวอักษรกลับเป็นชิ้นส่วน — คืน null ถ้าความยาวไม่ใช่ 20 */
function icSplit(string $ic): ?array {
    if (strlen($ic) !== 20) { return null; }
    return [
        'ic_code'      => $ic,
        'llp_code'     => substr($ic, 0, 8),
        'l1_code'      => substr($ic, 0, 3),
        'l2_code'      => substr($ic, 3, 2),
        'product_code' => substr($ic, 5, 3),
        'size_code'    => substr($ic, 8, 3),
        'brand_code'   => substr($ic, 11, 3),
        'unit_code'    => substr($ic, 14, 3),
        'extra_code'   => substr($ic, 17, 3),
    ];
}

/** แยก llp_code(8) เป็นชิ้นส่วน */
function icSplitLlp(string $llp): ?array {
    if (strlen($llp) !== 8) { return null; }
    return [
        'l1_code'      => substr($llp, 0, 3),
        'l2_code'      => substr($llp, 3, 2),
        'product_code' => substr($llp, 5, 3),
    ];
}

// ── ตัวโหลดรายการสำหรับจอไล่บันได ────────────────────────────────────────

function icL1List(PDO $pdo, bool $activeOnly = true): array {
    $w = $activeOnly ? 'WHERE is_active = 1' : '';
    return $pdo->query("SELECT * FROM l1_groups $w ORDER BY sort_order, l1_code")->fetchAll();
}

function icL2List(PDO $pdo, string $l1, bool $activeOnly = true): array {
    $w = $activeOnly ? 'AND is_active = 1' : '';
    $st = $pdo->prepare("SELECT * FROM l2_categories WHERE l1_code = ? $w ORDER BY l2_code");
    $st->execute([$l1]);
    return $st->fetchAll();
}

/** ตัวสินค้าใต้ (l1,l2) — $q = คำค้นในชื่อหรือรหัส */
function icLlpList(PDO $pdo, string $l1, string $l2, string $q = '', int $limit = 400): array {
    $sql  = 'SELECT * FROM llp_products WHERE l1_code = ? AND l2_code = ? AND is_active = 1';
    $args = [$l1, $l2];
    if ($q !== '') {
        $sql   .= ' AND (llp_name LIKE ? OR llp_code LIKE ?)';
        $like   = '%' . likeEscape($q) . '%';
        $args[] = $like;
        $args[] = $like;
    }
    $sql .= ' ORDER BY llp_code LIMIT ' . (int)$limit;
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/** ขนาดที่อนุญาตของ (l1,l2) */
function icSizeList(PDO $pdo, string $l1, string $l2): array {
    $st = $pdo->prepare(
        'SELECT s.size_code, s.size_name FROM l2_sizes ls
         JOIN sizes s ON s.size_code = ls.size_code
         WHERE ls.l1_code = ? AND ls.l2_code = ? AND s.is_active = 1
         ORDER BY (s.size_code = ?) DESC, s.size_name'
    );
    $st->execute([$l1, $l2, IC_NONE]);
    return $st->fetchAll();
}

/** ยี่ห้อที่อนุญาตของ (l1,l2) */
function icBrandList(PDO $pdo, string $l1, string $l2): array {
    $st = $pdo->prepare(
        'SELECT b.brand_code, b.brand_name FROM l2_brands lb
         JOIN brands b ON b.brand_code = lb.brand_code
         WHERE lb.l1_code = ? AND lb.l2_code = ? AND b.is_active = 1
         ORDER BY (b.brand_code = ?) DESC, b.brand_name'
    );
    $st->execute([$l1, $l2, IC_NONE]);
    return $st->fetchAll();
}

function icUnitList(PDO $pdo): array {
    return $pdo->query('SELECT unit_code, unit_name FROM units WHERE is_active = 1 ORDER BY unit_name')->fetchAll();
}

/** คุณสมบัติเพิ่มของตัวสินค้านั้น (ไม่รวม '000') */
function icExtraList(PDO $pdo, string $llp): array {
    $st = $pdo->prepare('SELECT extra_code, extra_name FROM extra_attrs WHERE llp_code = ? ORDER BY extra_code');
    $st->execute([$llp]);
    return $st->fetchAll();
}

// ── ตรวจความถูกต้องของชิ้นส่วน ───────────────────────────────────────────

/**
 * ตรวจว่าชิ้นส่วนประกอบเป็น IC ได้จริงไหม
 * @return array ['ok'=>bool, 'error'=>string, 'ctx'=>array ข้อมูลที่ดึงมาระหว่างตรวจ]
 */
function icValidateParts(PDO $pdo, array $p): array {
    $llp   = trim((string)($p['llp_code'] ?? ''));
    $size  = trim((string)($p['size_code'] ?? ''));
    $brand = trim((string)($p['brand_code'] ?? ''));
    $unit  = trim((string)($p['unit_code'] ?? ''));
    $extra = trim((string)($p['extra_code'] ?? IC_NONE));
    if ($extra === '') { $extra = IC_NONE; }

    $bad = function ($msg) { return ['ok' => false, 'error' => $msg, 'ctx' => []]; };

    foreach ([['llp_code', $llp, 8], ['size_code', $size, 3], ['brand_code', $brand, 3],
              ['unit_code', $unit, 3], ['extra_code', $extra, 3]] as $f) {
        if (strlen($f[1]) !== $f[2]) {
            return $bad('ความยาว ' . $f[0] . ' ต้องเป็น ' . $f[2] . ' ตัวอักษร');
        }
    }

    $st = $pdo->prepare(
        'SELECT p.*, c.l2_name, g.l1_name
         FROM llp_products p
         JOIN l2_categories c ON c.l1_code = p.l1_code AND c.l2_code = p.l2_code
         JOIN l1_groups g     ON g.l1_code = p.l1_code
         WHERE p.llp_code = ?'
    );
    $st->execute([$llp]);
    $row = $st->fetch();
    if (!$row)                     { return $bad('ไม่พบตัวสินค้า (LLP) รหัส ' . $llp); }
    if ((int)$row['is_active'] !== 1) { return $bad('ตัวสินค้า ' . $llp . ' ถูกปิดใช้งานแล้ว'); }

    $l1 = (string)$row['l1_code'];
    $l2 = (string)$row['l2_code'];

    $st = $pdo->prepare('SELECT 1 FROM l2_sizes WHERE l1_code=? AND l2_code=? AND size_code=?');
    $st->execute([$l1, $l2, $size]);
    if (!$st->fetchColumn()) { return $bad('ขนาด ' . $size . ' ใช้กับหมวด ' . $l1 . $l2 . ' ไม่ได้'); }

    $st = $pdo->prepare('SELECT 1 FROM l2_brands WHERE l1_code=? AND l2_code=? AND brand_code=?');
    $st->execute([$l1, $l2, $brand]);
    if (!$st->fetchColumn()) { return $bad('ยี่ห้อ ' . $brand . ' ใช้กับหมวด ' . $l1 . $l2 . ' ไม่ได้'); }

    $st = $pdo->prepare('SELECT unit_name FROM units WHERE unit_code=? AND is_active=1');
    $st->execute([$unit]);
    $unitName = $st->fetchColumn();
    if ($unitName === false) { return $bad('ไม่พบหน่วยรหัส ' . $unit); }

    if ($extra !== IC_NONE) {
        $st = $pdo->prepare('SELECT 1 FROM extra_attrs WHERE llp_code=? AND extra_code=?');
        $st->execute([$llp, $extra]);
        if (!$st->fetchColumn()) { return $bad('คุณสมบัติเพิ่ม ' . $extra . ' ไม่ได้ผูกกับ ' . $llp); }
    }

    return ['ok' => true, 'error' => '', 'ctx' => [
        'llp'       => $row,
        'l1_code'   => $l1,
        'l2_code'   => $l2,
        'unit_name' => (string)$unitName,
    ]];
}

/** ชื่อ IC ที่ระบบเสนอให้ = ชื่อตัวสินค้า + ขนาด + ยี่ห้อ + คุณสมบัติเพิ่ม */
function icSuggestName(PDO $pdo, array $p): string {
    $llp   = (string)($p['llp_code'] ?? '');
    $size  = (string)($p['size_code'] ?? IC_NONE);
    $brand = (string)($p['brand_code'] ?? IC_NONE);
    $extra = (string)($p['extra_code'] ?? IC_NONE);

    $st = $pdo->prepare('SELECT llp_name FROM llp_products WHERE llp_code = ?');
    $st->execute([$llp]);
    $name = (string)$st->fetchColumn();
    $bits = [$name];

    if ($size !== IC_NONE) {
        $st = $pdo->prepare('SELECT size_name FROM sizes WHERE size_code = ?');
        $st->execute([$size]);
        $v = (string)$st->fetchColumn();
        if ($v !== '') { $bits[] = $v; }
    }
    if ($brand !== IC_NONE) {
        $st = $pdo->prepare('SELECT brand_name FROM brands WHERE brand_code = ?');
        $st->execute([$brand]);
        $v = (string)$st->fetchColumn();
        if ($v !== '') { $bits[] = $v; }
    }
    if ($extra !== IC_NONE) {
        $st = $pdo->prepare('SELECT extra_name FROM extra_attrs WHERE llp_code = ? AND extra_code = ?');
        $st->execute([$llp, $extra]);
        $v = (string)$st->fetchColumn();
        if ($v !== '') { $bits[] = $v; }
    }

    return trim(implode(' ', array_filter($bits, function ($x) { return trim((string)$x) !== ''; })));
}

/** ดึง IC พร้อมชื่อชั้นต่าง ๆ */
function icGet(PDO $pdo, string $ic): ?array {
    $st = $pdo->prepare(
        'SELECT i.*, p.llp_name, c.l2_name, g.l1_name, u.unit_name,
                s.size_name, b.brand_name
         FROM ic_items i
         JOIN llp_products p  ON p.llp_code  = i.llp_code
         JOIN l2_categories c ON c.l1_code   = i.l1_code AND c.l2_code = i.l2_code
         JOIN l1_groups g     ON g.l1_code   = i.l1_code
         JOIN units u         ON u.unit_code = i.unit_code
         LEFT JOIN sizes s    ON s.size_code = i.size_code
         LEFT JOIN brands b   ON b.brand_code = i.brand_code
         WHERE i.ic_code = ?'
    );
    $st->execute([$ic]);
    $r = $st->fetch();
    return $r ?: null;
}

// ── CatID/CharID ที่ระดับ LLP (มติ 28-29) ────────────────────────────────

/**
 * ค่าที่ตั้งไว้ของ LLP — คืน null ถ้าไม่พบตัวสินค้า
 * ['cat_id' => ?string, 'char_id' => ?string, 'llp_name' => string, 'is_set' => bool]
 */
function icLlpCharCat(PDO $pdo, string $llp): ?array {
    $st = $pdo->prepare('SELECT llp_code, llp_name, cat_id, char_id FROM llp_products WHERE llp_code = ?');
    $st->execute([$llp]);
    $r = $st->fetch();
    if (!$r) { return null; }

    $cat  = icNormalizeCat($r['cat_id']);
    $char = icNormalizeChar($r['char_id']);
    return [
        'llp_code' => (string)$r['llp_code'],
        'llp_name' => (string)$r['llp_name'],
        'cat_id'   => $cat,
        'char_id'  => $char,
        'is_set'   => $cat !== null && $char !== null,
    ];
}

/**
 * มติ 29 — LLP คือต้นทางเดียว · ic_items/materials เป็นสำเนา
 * เขียนค่าของ LLP ลง IC ทุกตัวใต้มัน + แถวคู่ใน materials
 * เรียกได้ทั้งในและนอกทรานแซกชัน · คืนจำนวนแถวที่แตะ
 */
function icCascadeLlp(PDO $pdo, string $llp, string $cat, string $char): array {
    $st = $pdo->prepare('UPDATE ic_items SET cat_id = ?, char_id = ? WHERE llp_code = ?');
    $st->execute([$cat, $char, $llp]);
    $nIc = $st->rowCount();

    // materials ผูกกับ ic_items ด้วย mat_code = ic_code (มติ 3) — แตะเฉพาะแถว code_type='ic'
    $st = $pdo->prepare(
        "UPDATE materials m
           JOIN ic_items i ON i.ic_code = m.mat_code
            SET m.cat_id = ?, m.char_id = ?
          WHERE m.code_type = 'ic' AND i.llp_code = ?"
    );
    $st->execute([$cat, $char, $llp]);

    return ['ic' => $nIc, 'material' => $st->rowCount()];
}

/**
 * ตั้งค่าให้ LLP หลายตัวที่ใช้ค่าเดียวกัน — ใช้ตอน import Excel ทีละพัน ๆ แถว
 * ยิงคำสั่งละกลุ่ม (llp/ic/materials อย่างละครั้ง) แทนการวนทีละแถว
 * ไม่งั้นไฟล์ 3,300 แถว = หมื่นคำสั่ง เสี่ยงชน max_execution_time ของโฮสต์
 *
 * @param string[] $llps รหัสตัวสินค้าที่จะตั้งค่าเป็น (cat, char) ชุดนี้
 * @return array ['llp' => n, 'ic' => n, 'material' => n]
 */
function icLlpSetCharCatBulk(PDO $pdo, array $llps, string $cat, string $char, ?array $user = null): array {
    $llps = array_values(array_unique(array_filter($llps)));
    if (!$llps) { return ['llp' => 0, 'ic' => 0, 'material' => 0]; }

    $ph  = implode(',', array_fill(0, count($llps), '?'));
    $uid = $user !== null ? ((int)($user['accountId'] ?? 0) ?: null) : null;

    $st = $pdo->prepare(
        "UPDATE llp_products SET cat_id = ?, char_id = ?, charcat_by = ?, charcat_at = NOW()
          WHERE llp_code IN ($ph)"
    );
    $st->execute(array_merge([$cat, $char, $uid], $llps));
    $nLlp = $st->rowCount();

    $st = $pdo->prepare("UPDATE ic_items SET cat_id = ?, char_id = ? WHERE llp_code IN ($ph)");
    $st->execute(array_merge([$cat, $char], $llps));
    $nIc = $st->rowCount();

    $st = $pdo->prepare(
        "UPDATE materials m
           JOIN ic_items i ON i.ic_code = m.mat_code
            SET m.cat_id = ?, m.char_id = ?
          WHERE m.code_type = 'ic' AND i.llp_code IN ($ph)"
    );
    $st->execute(array_merge([$cat, $char], $llps));

    return ['llp' => $nLlp, 'ic' => $nIc, 'material' => $st->rowCount()];
}

/**
 * ตั้ง/แก้ค่าของ LLP หนึ่งตัว แล้ว cascade ลงของที่ออกไปแล้วในทรานแซกชันเดียว
 * @return array ['ok','error','changed'(bool),'ic','material']
 */
function icLlpSetCharCat(PDO $pdo, string $llp, ?string $cat, ?string $char, ?array $user = null): array {
    $fail = function (string $msg) {
        return ['ok' => false, 'error' => $msg, 'changed' => false, 'ic' => 0, 'material' => 0];
    };

    $llp  = strtoupper(trim($llp));
    $cat  = icNormalizeCat($cat);
    $char = icNormalizeChar($char);
    if ($cat === null)  { return $fail('หมวดอนุมัติ (CatID) ต้องเป็น ' . implode(' / ', IC_CAT_IDS)); }
    if ($char === null) { return $fail('ลักษณะวัสดุ (CharID) ต้องเป็น ' . implode(' / ', IC_CHAR_IDS)); }

    $cur = icLlpCharCat($pdo, $llp);
    if ($cur === null) { return $fail('ไม่พบตัวสินค้า (LLP) รหัส ' . $llp); }
    if ($cur['cat_id'] === $cat && $cur['char_id'] === $char) {
        return ['ok' => true, 'error' => '', 'changed' => false, 'ic' => 0, 'material' => 0];
    }

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) { $pdo->beginTransaction(); }
    try {
        $st = $pdo->prepare(
            'UPDATE llp_products
                SET cat_id = ?, char_id = ?, charcat_by = ?, charcat_at = NOW()
              WHERE llp_code = ?'
        );
        $st->execute([$cat, $char, $user !== null ? ((int)($user['accountId'] ?? 0) ?: null) : null, $llp]);

        $n = icCascadeLlp($pdo, $llp, $cat, $char);

        if ($ownTx) { $pdo->commit(); }
        return ['ok' => true, 'error' => '', 'changed' => true, 'ic' => $n['ic'], 'material' => $n['material']];

    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('icLlpSetCharCat ' . $llp . ': ' . $e->getMessage());
        return $fail('บันทึกค่าของตัวสินค้าไม่สำเร็จ: ' . $e->getMessage());
    }
}

// ── ออกรหัสจริง ──────────────────────────────────────────────────────────

/**
 * สร้าง IC ใหม่ (ถ้ามีรหัสนี้อยู่แล้วคืนตัวเดิม ไม่ error — idempotent)
 * ทำสองอย่างในทรานแซกชันเดียว: ic_items + materials (มติ 3)
 *
 * cat_id/char_id ไม่รับจากฟอร์มอีกแล้ว (มติ 28) — อ่านจาก LLP เสมอ
 * LLP ที่ยังไม่ตั้งค่า = ออก IC ไม่ได้ ต้องให้ ADM ตั้งก่อน (มติ 32)
 *
 * @param array $p llp_code,size_code,brand_code,unit_code,extra_code,ic_name
 * @return array ['ok','error','ic_code','material_id','created'(bool)]
 */
function icCreate(PDO $pdo, array $p, ?array $user = null): array {
    $v = icValidateParts($pdo, $p);
    if (!$v['ok']) {
        return ['ok' => false, 'error' => $v['error'], 'ic_code' => '', 'material_id' => 0, 'created' => false];
    }

    $cc = icLlpCharCat($pdo, (string)$p['llp_code']);
    if ($cc === null || !$cc['is_set']) {
        return ['ok' => false, 'ic_code' => '', 'material_id' => 0, 'created' => false,
                'error' => 'ตัวสินค้า ' . (string)$p['llp_code'] . ' ยังไม่ได้ตั้งหมวดอนุมัติ/ลักษณะวัสดุ '
                         . '— ให้ผู้ดูแลระบบตั้งค่าที่หน้า "ตั้งค่าตัวสินค้า (LLP)" ก่อนออกรหัส'];
    }

    $extra = trim((string)($p['extra_code'] ?? IC_NONE));
    if ($extra === '') { $extra = IC_NONE; }

    $ic = icCompose(
        (string)$p['llp_code'], (string)$p['size_code'],
        (string)$p['brand_code'], (string)$p['unit_code'], $extra
    );

    $name = trim((string)($p['ic_name'] ?? ''));
    if ($name === '') { $name = icSuggestName($pdo, $p); }
    if ($name === '') { $name = $ic; }

    $catId  = $cc['cat_id'];    // จาก LLP เท่านั้น — ฟอร์มส่งอะไรมาก็ไม่รับ
    $charId = $cc['char_id'];

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) { $pdo->beginTransaction(); }

    try {
        $st = $pdo->prepare('SELECT ic_code FROM ic_items WHERE ic_code = ? FOR UPDATE');
        $st->execute([$ic]);
        $exists = (bool)$st->fetchColumn();

        if (!$exists) {
            $parts = icSplitLlp((string)$p['llp_code']);
            $cols = 'ic_code, llp_code, l1_code, l2_code, size_code, brand_code, unit_code,
                     extra_code, ic_name, cat_id, char_id, created_project_id, created_by';
            $vals = [
                $ic, (string)$p['llp_code'], $parts['l1_code'], $parts['l2_code'],
                (string)$p['size_code'], (string)$p['brand_code'], (string)$p['unit_code'],
                $extra, $name, $catId, $charId,
                $user !== null ? (int)($user['projectId'] ?? 0) ?: null : null,
                $user !== null ? ((int)($user['accountId'] ?? 0) ?: null) : null,
            ];
            // ธงเสริม (มติ 43) — เก็บไว้ก่อน ยังไม่มีสายงานไหนอ่านไปใช้
            if (icHasFlagColumns($pdo)) {
                $cols  .= ', has_serial, is_cx';
                $vals[] = isTrueFlag($p['has_serial'] ?? 0) ? 1 : 0;
                $vals[] = array_key_exists('is_cx', $p) ? (isTrueFlag($p['is_cx']) ? 1 : 0) : 1;
            }
            $ph  = implode(',', array_fill(0, count($vals), '?'));
            $ins = $pdo->prepare("INSERT INTO ic_items ($cols) VALUES ($ph)");
            $ins->execute($vals);
        } else {
            // ออกซ้ำ = ไม่สร้างใหม่ แต่ถือโอกาสดึงสำเนาให้ตรง LLP ปัจจุบัน (มติ 29)
            $st = $pdo->prepare('UPDATE ic_items SET cat_id = ?, char_id = ? WHERE ic_code = ?');
            $st->execute([$catId, $charId, $ic]);
            $st = $pdo->prepare("UPDATE materials SET cat_id = ?, char_id = ? WHERE mat_code = ? AND code_type = 'ic'");
            $st->execute([$catId, $charId, $ic]);
        }

        $matId = icEnsureMaterial($pdo, $ic);

        if ($ownTx) { $pdo->commit(); }
        return ['ok' => true, 'error' => '', 'ic_code' => $ic, 'material_id' => $matId, 'created' => !$exists];

    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('icCreate ' . $ic . ': ' . $e->getMessage());
        return ['ok' => false, 'error' => 'บันทึกรหัสไม่สำเร็จ: ' . $e->getMessage(),
                'ic_code' => $ic, 'material_id' => 0, 'created' => false];
    }
}

/**
 * มติ 3 — ทำให้ IC มีแถวคู่ใน materials (mat_code = ic_code, code_type='ic')
 * มีอยู่แล้วก็คืน id เดิม · คืน 0 ถ้าไม่พบ IC
 */
function icEnsureMaterial(PDO $pdo, string $ic): int {
    $st = $pdo->prepare('SELECT id FROM materials WHERE mat_code = ?');
    $st->execute([$ic]);
    $id = $st->fetchColumn();
    if ($id !== false) { return (int)$id; }

    $row = icGet($pdo, $ic);
    if ($row === null) { return 0; }

    $ins = $pdo->prepare(
        'INSERT INTO materials (mat_code, code_type, name, unit, cat_id, char_id, subgroup_name)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $ins->execute([
        $ic,
        'ic',
        (string)$row['ic_name'],
        (string)$row['unit_name'],
        (string)$row['cat_id'],
        $row['char_id'] !== null && $row['char_id'] !== '' ? (string)$row['char_id'] : null,
        trim((string)$row['l1_name'] . ' › ' . (string)$row['l2_name']),
    ]);

    return (int)$pdo->lastInsertId();
}

// ── จัดการบันได (ADM เท่านั้น — มติ 16) ──────────────────────────────────

/** รหัสตัวสินค้าถัดไปใต้ (l1,l2) เช่น '004' — คืน '' ถ้าเต็ม 999 */
function icNextProductCode(PDO $pdo, string $l1, string $l2): string {
    $st = $pdo->prepare('SELECT MAX(product_code) FROM llp_products WHERE l1_code = ? AND l2_code = ?');
    $st->execute([$l1, $l2]);
    $max = (int)$st->fetchColumn();
    $next = $max + 1;
    return $next > 999 ? '' : str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

/** รหัสถัดไปของพจนานุกรมกลาง (sizes/brands/units) — ข้าม '000' */
function icNextDictCode(PDO $pdo, string $table, string $col): string {
    $allowed = ['sizes' => 'size_code', 'brands' => 'brand_code', 'units' => 'unit_code'];
    if (!isset($allowed[$table]) || $allowed[$table] !== $col) { return ''; }
    $max  = (int)$pdo->query("SELECT MAX($col) FROM $table")->fetchColumn();
    $next = max($max + 1, 1);
    return $next > 999 ? '' : str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

/** เพิ่มตัวสินค้าใหม่ใต้หมวดที่มีอยู่ — คืน ['ok','error','llp_code'] */
function icAddLlp(PDO $pdo, string $l1, string $l2, string $name, ?array $user = null): array {
    $name = trim($name);
    if ($name === '') { return ['ok' => false, 'error' => 'ต้องใส่ชื่อตัวสินค้า', 'llp_code' => '']; }

    $st = $pdo->prepare('SELECT 1 FROM l2_categories WHERE l1_code = ? AND l2_code = ?');
    $st->execute([$l1, $l2]);
    if (!$st->fetchColumn()) { return ['ok' => false, 'error' => 'ไม่พบหมวด ' . $l1 . $l2, 'llp_code' => '']; }

    $prod = icNextProductCode($pdo, $l1, $l2);
    if ($prod === '') { return ['ok' => false, 'error' => 'หมวดนี้มีตัวสินค้าครบ 999 แล้ว', 'llp_code' => '']; }

    $llp = $l1 . $l2 . $prod;
    $ins = $pdo->prepare(
        'INSERT INTO llp_products (llp_code, l1_code, l2_code, product_code, llp_name, created_by)
         VALUES (?,?,?,?,?,?)'
    );
    $ins->execute([$llp, $l1, $l2, $prod, $name, $user !== null ? ((int)($user['accountId'] ?? 0) ?: null) : null]);

    return ['ok' => true, 'error' => '', 'llp_code' => $llp];
}

/**
 * เพิ่มขนาด/ยี่ห้อให้หมวดหนึ่ง
 * $code ว่าง = สร้างรายการใหม่ในพจนานุกรมกลางด้วยชื่อ $name แล้วผูกให้
 * $code มีค่า = ผูกรายการที่มีอยู่แล้วเข้ากับหมวดนี้
 */
function icAttachToL2(PDO $pdo, string $kind, string $l1, string $l2, string $code, string $name): array {
    $map = [
        'size'  => ['sizes',  'size_code',  'size_name',  'l2_sizes'],
        'brand' => ['brands', 'brand_code', 'brand_name', 'l2_brands'],
    ];
    if (!isset($map[$kind])) { return ['ok' => false, 'error' => 'ชนิดไม่ถูกต้อง', 'code' => '']; }
    list($dict, $codeCol, $nameCol, $link) = $map[$kind];

    $code = trim($code);
    $name = trim($name);

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) { $pdo->beginTransaction(); }
    try {
        if ($code === '') {
            if ($name === '') { throw new RuntimeException('ต้องใส่ชื่อ'); }
            $st = $pdo->prepare("SELECT $codeCol FROM $dict WHERE $nameCol = ?");
            $st->execute([$name]);
            $found = $st->fetchColumn();
            if ($found !== false) {
                $code = (string)$found;          // ชื่อซ้ำ = ใช้ตัวเดิม ไม่สร้างซ้ำ
            } else {
                $code = icNextDictCode($pdo, $dict, $codeCol);
                if ($code === '') { throw new RuntimeException('รหัสในพจนานุกรมเต็มแล้ว'); }
                $ins = $pdo->prepare("INSERT INTO $dict ($codeCol, $nameCol) VALUES (?,?)");
                $ins->execute([$code, $name]);
            }
        } else {
            if (!preg_match('/^[0-9]{3}$/', $code)) { throw new RuntimeException('รหัสต้องเป็นตัวเลข 3 หลัก (ได้ "' . $code . '")'); }
            $st = $pdo->prepare("SELECT $nameCol FROM $dict WHERE $codeCol = ?");
            $st->execute([$code]);
            $cur = $st->fetchColumn();
            if ($cur === false) {
                // ระบุรหัสเองแล้วยังไม่มีในพจนานุกรม = สร้างใหม่ด้วยรหัสนั้น (มติ 43 — จอออกรหัสแก้ master ได้ในที่)
                if ($name === '') { throw new RuntimeException('รหัส ' . $code . ' ยังไม่มีในพจนานุกรม — ต้องใส่ชื่อด้วยถ้าจะสร้างใหม่'); }
                $ins = $pdo->prepare("INSERT INTO $dict ($codeCol, $nameCol) VALUES (?,?)");
                $ins->execute([$code, $name]);
            } elseif ($name !== '' && (string)$cur !== $name) {
                $up = $pdo->prepare("UPDATE $dict SET $nameCol = ? WHERE $codeCol = ?");
                $up->execute([$name, $code]);
            }
        }

        $ins = $pdo->prepare("INSERT IGNORE INTO $link (l1_code, l2_code, $codeCol) VALUES (?,?,?)");
        $ins->execute([$l1, $l2, $code]);

        if ($ownTx) { $pdo->commit(); }
        return ['ok' => true, 'error' => '', 'code' => $code];

    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
        return ['ok' => false, 'error' => $e->getMessage(), 'code' => ''];
    }
}

/** เพิ่มคุณสมบัติเพิ่มให้ตัวสินค้า — รหัสไล่ 001,002,... ต่อ llp */
function icAddExtra(PDO $pdo, string $llp, string $name): array {
    $name = trim($name);
    if ($name === '') { return ['ok' => false, 'error' => 'ต้องใส่ชื่อคุณสมบัติ', 'code' => '']; }

    $st = $pdo->prepare('SELECT 1 FROM llp_products WHERE llp_code = ?');
    $st->execute([$llp]);
    if (!$st->fetchColumn()) { return ['ok' => false, 'error' => 'ไม่พบตัวสินค้า ' . $llp, 'code' => '']; }

    $st = $pdo->prepare('SELECT MAX(extra_code) FROM extra_attrs WHERE llp_code = ?');
    $st->execute([$llp]);
    $next = (int)$st->fetchColumn() + 1;
    if ($next > 999) { return ['ok' => false, 'error' => 'ตัวสินค้านี้มีคุณสมบัติครบ 999 แล้ว', 'code' => '']; }

    $code = str_pad((string)$next, 3, '0', STR_PAD_LEFT);
    $ins  = $pdo->prepare('INSERT INTO extra_attrs (llp_code, extra_code, extra_name) VALUES (?,?,?)');
    $ins->execute([$llp, $code, $name]);

    return ['ok' => true, 'error' => '', 'code' => $code];
}

/**
 * ── จัดการบันไดชั้นบน + ธงเสริม (มติ 43) ─────────────────────────────────
 * เดิมเพิ่มได้แค่ตัวสินค้า/ขนาด/ยี่ห้อ/คุณสมบัติ — กลุ่มใหญ่ (L1) หมวด (L2) และหน่วยเก็บ
 * ต้องไปยิง SQL เอง จอ "สร้างรหัส IC" ใหม่ต้องเพิ่มได้ครบทุกชั้นในที่เดียว
 */

/** ตาราง ic_items มีคอลัมน์ธง has_serial/is_cx แล้วหรือยัง (ถามครั้งเดียวต่อ request) */
function icHasFlagColumns(PDO $pdo): bool {
    static $has = null;
    if ($has === null) {
        $has = (bool)$pdo->query("SHOW COLUMNS FROM ic_items LIKE 'has_serial'")->fetch();
    }
    return $has;
}

/**
 * เพิ่มคอลัมน์ธงให้ ic_items — รันซ้ำได้ · คืนคำสั่งที่รันไป
 *   has_serial = ของนับเป็นชิ้นและมี Serial Number (ตู้ MDB / เครื่องจักร)
 *   is_cx      = อยู่ในระบบเบิกจ่าย CX · ปิด = วัสดุ WC (คลังกลางจ่ายแล้วจบ ไซต์ไม่รับเข้า)
 * ⚠ เก็บค่าอย่างเดียว — ยังไม่มีสายรับ/เบิก/ประตูไหนอ่านไปใช้ (ตกลงกันไว้ 2026-09-23)
 */
function icEnsureFlagColumns(PDO $pdo): array {
    $done = [];
    if (!$pdo->query("SHOW COLUMNS FROM ic_items LIKE 'has_serial'")->fetch()) {
        $pdo->exec("ALTER TABLE ic_items ADD COLUMN has_serial TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active");
        $done[] = 'ALTER TABLE ic_items ADD has_serial';
    }
    if (!$pdo->query("SHOW COLUMNS FROM ic_items LIKE 'is_cx'")->fetch()) {
        $pdo->exec("ALTER TABLE ic_items ADD COLUMN is_cx TINYINT(1) NOT NULL DEFAULT 1 AFTER has_serial");
        $done[] = 'ALTER TABLE ic_items ADD is_cx';
    }
    return $done;
}

/** เพิ่มกลุ่มใหญ่ (L1) — รหัส 3 ตัวอักษร (ตัวใหญ่) · คืน ['ok','error','code'] */
function icAddL1(PDO $pdo, string $code, string $name): array {
    $code = strtoupper(preg_replace('/\s+/u', '', trim($code)));
    $name = trim($name);
    if (!preg_match('/^[A-Z0-9]{3}$/', $code)) { return ['ok' => false, 'error' => 'รหัสกลุ่มใหญ่ต้องเป็นตัวอักษร/ตัวเลข 3 ตัว เช่น STR', 'code' => '']; }
    if ($name === '')                          { return ['ok' => false, 'error' => 'ต้องใส่ชื่อกลุ่มใหญ่', 'code' => '']; }

    $st = $pdo->prepare('SELECT l1_name FROM l1_groups WHERE l1_code = ?');
    $st->execute([$code]);
    $cur = $st->fetchColumn();
    if ($cur !== false) {
        // มีอยู่แล้ว = เปิดใช้งาน + แก้ชื่อให้ตรง (ไม่ error — คนมักกดซ้ำ)
        $pdo->prepare('UPDATE l1_groups SET l1_name = ?, is_active = 1 WHERE l1_code = ?')->execute([$name, $code]);
        return ['ok' => true, 'error' => '', 'code' => $code, 'existed' => true];
    }
    $next = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM l1_groups')->fetchColumn();
    $pdo->prepare('INSERT INTO l1_groups (l1_code, l1_name, sort_order, is_active) VALUES (?,?,?,1)')
        ->execute([$code, $name, $next]);
    return ['ok' => true, 'error' => '', 'code' => $code, 'existed' => false];
}

/** หมวดถัดไปใต้ L1 เช่น '06' — คืน '' ถ้าเต็ม 99 */
function icNextL2Code(PDO $pdo, string $l1): string {
    $st = $pdo->prepare('SELECT MAX(l2_code) FROM l2_categories WHERE l1_code = ?');
    $st->execute([$l1]);
    $next = (int)$st->fetchColumn() + 1;
    return $next > 99 ? '' : str_pad((string)$next, 2, '0', STR_PAD_LEFT);
}

/**
 * เพิ่มหมวด (L2) ใต้กลุ่มใหญ่ — $code ว่าง = ไล่เลขให้เอง
 * ผูกขนาด/ยี่ห้อ '000' (ไม่ระบุ) ให้อัตโนมัติ ไม่งั้น icValidateParts ปัดตกตั้งแต่ IC ตัวแรก
 */
function icAddL2(PDO $pdo, string $l1, string $code, string $name): array {
    $l1   = strtoupper(preg_replace('/\s+/u', '', trim($l1)));
    $code = preg_replace('/\s+/u', '', trim($code));
    $name = trim($name);
    if ($name === '') { return ['ok' => false, 'error' => 'ต้องใส่ชื่อหมวด', 'code' => '']; }

    $st = $pdo->prepare('SELECT 1 FROM l1_groups WHERE l1_code = ?');
    $st->execute([$l1]);
    if (!$st->fetchColumn()) { return ['ok' => false, 'error' => 'ไม่พบกลุ่มใหญ่ ' . $l1, 'code' => '']; }

    if ($code === '') {
        $code = icNextL2Code($pdo, $l1);
        if ($code === '') { return ['ok' => false, 'error' => 'กลุ่มนี้มีหมวดครบ 99 แล้ว', 'code' => '']; }
    }
    if (!preg_match('/^[0-9]{2}$/', $code)) { return ['ok' => false, 'error' => 'รหัสหมวดต้องเป็นตัวเลข 2 หลัก', 'code' => '']; }

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) { $pdo->beginTransaction(); }
    try {
        $st = $pdo->prepare('SELECT l2_name FROM l2_categories WHERE l1_code = ? AND l2_code = ?');
        $st->execute([$l1, $code]);
        $existed = $st->fetchColumn() !== false;
        if ($existed) {
            $pdo->prepare('UPDATE l2_categories SET l2_name = ?, is_active = 1 WHERE l1_code = ? AND l2_code = ?')
                ->execute([$name, $l1, $code]);
        } else {
            $pdo->prepare('INSERT INTO l2_categories (l1_code, l2_code, l2_name, is_active) VALUES (?,?,?,1)')
                ->execute([$l1, $code, $name]);
        }
        $pdo->exec("INSERT IGNORE INTO sizes (size_code, size_name) VALUES ('000','ไม่ระบุ')");
        $pdo->exec("INSERT IGNORE INTO brands (brand_code, brand_name) VALUES ('000','ไม่ระบุ')");
        $pdo->prepare("INSERT IGNORE INTO l2_sizes (l1_code, l2_code, size_code) VALUES (?,?,'000')")->execute([$l1, $code]);
        $pdo->prepare("INSERT IGNORE INTO l2_brands (l1_code, l2_code, brand_code) VALUES (?,?,'000')")->execute([$l1, $code]);
        if ($ownTx) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
        return ['ok' => false, 'error' => 'เพิ่มหมวดไม่สำเร็จ: ' . $e->getMessage(), 'code' => ''];
    }
    return ['ok' => true, 'error' => '', 'code' => $code, 'existed' => $existed];
}

/** เพิ่มหน่วยเก็บ — $code ว่าง = ไล่เลขให้เอง · ชื่อซ้ำ = คืนรหัสเดิม ไม่สร้างซ้ำ */
function icAddUnit(PDO $pdo, string $code, string $name): array {
    $code = preg_replace('/\s+/u', '', trim($code));
    $name = trim($name);
    if ($name === '')          { return ['ok' => false, 'error' => 'ต้องใส่ชื่อหน่วย', 'code' => '']; }
    if (mb_strlen($name) > 50) { return ['ok' => false, 'error' => 'ชื่อหน่วยยาวเกิน 50 ตัวอักษร', 'code' => '']; }

    if ($code === '') {
        $st = $pdo->prepare('SELECT unit_code FROM units WHERE unit_name = ?');
        $st->execute([$name]);
        $found = $st->fetchColumn();
        if ($found !== false) {
            $pdo->prepare('UPDATE units SET is_active = 1 WHERE unit_code = ?')->execute([(string)$found]);
            return ['ok' => true, 'error' => '', 'code' => (string)$found, 'existed' => true];
        }
        $code = icNextDictCode($pdo, 'units', 'unit_code');
        if ($code === '') { return ['ok' => false, 'error' => 'รหัสหน่วยเต็มแล้ว', 'code' => '']; }
    }
    if (!preg_match('/^[0-9]{3}$/', $code)) { return ['ok' => false, 'error' => 'รหัสหน่วยต้องเป็นตัวเลข 3 หลัก', 'code' => '']; }

    $st = $pdo->prepare('SELECT unit_name FROM units WHERE unit_code = ?');
    $st->execute([$code]);
    $existed = $st->fetchColumn() !== false;
    if ($existed) {
        $pdo->prepare('UPDATE units SET unit_name = ?, is_active = 1 WHERE unit_code = ?')->execute([$name, $code]);
    } else {
        $pdo->prepare('INSERT INTO units (unit_code, unit_name, is_active) VALUES (?,?,1)')->execute([$code, $name]);
    }
    return ['ok' => true, 'error' => '', 'code' => $code, 'existed' => $existed];
}

/** แก้ชื่อชั้นบันได — $kind: l1 | l2 | llp | size | brand | unit | extra */
function icRenameNode(PDO $pdo, string $kind, array $key, string $name): array {
    $name = trim($name);
    if ($name === '') { return ['ok' => false, 'error' => 'ต้องใส่ชื่อใหม่']; }
    try {
        switch ($kind) {
            case 'l1':
                $st = $pdo->prepare('UPDATE l1_groups SET l1_name = ? WHERE l1_code = ?');
                $st->execute([$name, (string)($key['l1'] ?? '')]);
                break;
            case 'l2':
                $st = $pdo->prepare('UPDATE l2_categories SET l2_name = ? WHERE l1_code = ? AND l2_code = ?');
                $st->execute([$name, (string)($key['l1'] ?? ''), (string)($key['l2'] ?? '')]);
                break;
            case 'llp':
                $st = $pdo->prepare('UPDATE llp_products SET llp_name = ? WHERE llp_code = ?');
                $st->execute([$name, (string)($key['llp'] ?? '')]);
                break;
            case 'size':
                $st = $pdo->prepare('UPDATE sizes SET size_name = ? WHERE size_code = ?');
                $st->execute([$name, (string)($key['code'] ?? '')]);
                break;
            case 'brand':
                $st = $pdo->prepare('UPDATE brands SET brand_name = ? WHERE brand_code = ?');
                $st->execute([$name, (string)($key['code'] ?? '')]);
                break;
            case 'unit':
                $st = $pdo->prepare('UPDATE units SET unit_name = ? WHERE unit_code = ?');
                $st->execute([$name, (string)($key['code'] ?? '')]);
                break;
            case 'extra':
                $st = $pdo->prepare('UPDATE extra_attrs SET extra_name = ? WHERE llp_code = ? AND extra_code = ?');
                $st->execute([$name, (string)($key['llp'] ?? ''), (string)($key['code'] ?? '')]);
                break;
            default:
                return ['ok' => false, 'error' => 'ชนิดไม่ถูกต้อง'];
        }
        return ['ok' => true, 'error' => '', 'changed' => $st->rowCount() > 0];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'แก้ชื่อไม่สำเร็จ: ' . $e->getMessage()];
    }
}
