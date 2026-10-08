<?php
/**
 * CONNEXT — lib/ic_import.php : ออกรหัส IC ทั้งก้อนจากไฟล์ "สร้าง LLP.xlsx" แล้วผูกกับรหัส Mango ในระบบ (มติ 44)
 * ใช้ร่วมกันระหว่าง CLI (db/import_ic_master.php) กับการ์ด 2b ในหน้า setup_master.php
 *
 * ต่อจาก lib/llp_import.php (ขั้น 1 Mango → LLP) — ไฟล์นี้คือขั้น 2 "เติมสเปกเป็นรหัส IC" ทีเดียวทั้งไฟล์
 *   · "IC_All Mat"          1 แถว = 1 รหัส Mango: SIZE NAME · BRAND NAME · Unit Name (IC) · ชื่อในPO
 *   · "Create_LLP_All Mat"  LLP ของรหัสที่ยังไม่อยู่ในทะเบียนระบบ
 *                           (รหัสที่อยู่ในระบบใช้ LLP ที่ผูกไว้ใน mango_ic_map — ADM อาจแก้ในจอไปแล้ว)
 *   · "All Mango Mat"       Serial No. (Y/N) → ic_items.has_serial (ไม่มีชีตนี้ = 0 ทั้งหมด)
 *
 * ── แตกสเปกเป็นชิ้นรหัส: ic = llp(8) + ขนาด(3) + ยี่ห้อ(3) + หน่วย(3) + คุณสมบัติ(3) ─────────────
 *   ขนาด      พจนานุกรมกลางมีได้ 999 รหัสทั้งระบบ แต่ SIZE NAME ในไฟล์มี ~4,100 แบบ (ส่วนใหญ่คือรุ่น/
 *             เบอร์/สเปกเฉพาะตัว) → รับเข้าพจนานุกรมกลางเฉพาะ "ขนาดมาตรฐาน" = ขึ้นต้นด้วยตัวเลข (หรือ
 *             ยาว/หนา/เบอร์/Size/DB/M… แล้วตามด้วยตัวเลข) และใช้ร่วมกันตั้งแต่ 2 ตัวสินค้าขึ้นไป
 *             (1/2" · 6 มม. · 3 ตัน · 1x2.5 sq.mm. …) หรือมีในพจนานุกรมอยู่แล้ว
 *             ที่เหลือเป็นคุณสมบัติเพิ่มของตัวสินค้านั้น (extra มีได้ 999 ต่อตัวสินค้า — ไฟล์ใช้สูงสุด ~310)
 *   ยี่ห้อ      BRAND NAME → พจนานุกรมกลาง (ชื่อซ้ำไม่สนตัวพิมพ์ = รหัสเดิม)
 *   หน่วย      Unit Name (IC) (ว่าง = Unit Name (PO)) → units
 *   คุณสมบัติ   ขนาดที่ไม่ใช่มาตรฐาน + "ส่วนที่เหลือ" ของชื่อในPO หลังตัดชื่อตัวสินค้า/ขนาด/ยี่ห้อออก
 *             (S/N · ชิ้นส่วนเครน · สี …) — กันไม่ให้รหัส Mango ที่ชื่อต่างกันถูกรวมเป็น IC เดียว
 *   รหัส Mango ที่ได้ชิ้นรหัสเหมือนกันทุกช่อง = IC เดียวกัน (ของซ้ำในทะเบียน Mango)
 *   ชื่อ IC = ชื่อในPO ของรหัสตัวแทน (อยู่ในระบบก่อน แล้วเรียงตามรหัส) — หน้างานเห็นชื่อเดิม
 *
 * ── กติกา ──────────────────────────────────────────────────────────────────
 *   · LLP ที่ยังไม่ตั้ง CatID/CharID ออก IC ไม่ได้ (มติ 32) → รายงานไว้ ตั้งที่ llp_master.php แล้วรันซ้ำ
 *   · ผูก IC ให้เฉพาะรหัส Mango ในทะเบียนระบบที่ผูก LLP ไว้ตัวเดียวและยังไม่มี IC (อัปเกรดแถวขั้น 1)
 *     รหัสที่ผูก IC แล้วไม่แตะ — เพิ่ม IC ตัวที่ 2 (เช่น แบบยาว) ใต้ LLP เดิมทำรายตัวในจอ ·
 *     รหัสนอกทะเบียนได้ IC แต่ไม่มีแถวผูก · 1 Mango มี LLP ได้ตัวเดียว (มติ 45)
 *   · รันซ้ำได้ — ขนาด/ยี่ห้อ/หน่วย/คุณสมบัติ/IC ที่มีอยู่แล้วจับคู่ด้วยชื่อ/รหัส ไม่สร้างซ้ำ
 *     รหัสที่ออกไปแล้วไม่เปลี่ยน (แม้รอบหลังขนาดนั้นจะกลายเป็น "มาตรฐาน")
 *   · ไม่ย้ายยอด — ย้ายที่ข้อ 4 ของ setup_master.php (smMigrate) หลังตรวจผลแล้ว
 *
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/ic.php';
require_once __DIR__ . '/setup_master.php';   // SM_SUGGEST_VENDOR · mangoNormalizeCode · mangoLog
require_once __DIR__ . '/../includes/xlsx_lite.php';

/** ขนาดจะเข้าพจนานุกรมกลางได้ ต้องถูกใช้ร่วมกันอย่างน้อยกี่ตัวสินค้า */
const ICI_STD_MIN_LLP = 2;

/** ความยาวสูงสุดของชื่อ (ตามคอลัมน์ใน DB) */
const ICI_MAX_SIZE  = 100;
const ICI_MAX_BRAND = 100;
const ICI_MAX_EXTRA = 150;
const ICI_MAX_UNIT  = 50;

/** สถานะต่อรหัส Mango — ป้ายในจอและไฟล์ตรวจผล */
function iciStatusLabels(): array {
    return [
        'map'         => 'ผูก IC ให้แล้ว',
        'issued'      => 'ออก IC แล้ว (รหัสนี้ไม่อยู่ในทะเบียนระบบ — ไม่มีแถวผูก)',
        'has_ic'      => 'ผูก IC ไว้ก่อนแล้ว — ไม่แตะ',
        'llp_unset'   => 'ติด: ตัวสินค้ายังไม่ตั้ง CatID/CharID',
        'no_llp'      => 'ติด: ยังไม่ผูกตัวสินค้า (LLP)',
        'multi_llp'   => 'ติด: ผูกไว้หลายตัวสินค้า (ผิดกติกา 1 Mango = 1 LLP) — เลือกให้เหลือตัวเดียวในจอ',
        'llp_missing' => 'ติด: ไม่พบ LLP ในระบบ',
        'llp_off'     => 'ติด: ตัวสินค้าถูกปิดใช้งาน',
        'no_unit'     => 'ติด: ไม่มีหน่วยเก็บ',
        'too_long'    => 'ติด: สเปกยาวเกินช่องคุณสมบัติ (150 ตัวอักษร)',
        'full'        => 'ติด: รหัสในพจนานุกรมเต็ม 999',
        'not_in_file' => 'ติด: ไม่มีในชีต IC_All Mat',
    ];
}

// ── ทำข้อความให้เทียบกันได้ ──────────────────────────────────────────────

/** ตัดช่องว่างซ้ำ + รวมเครื่องหมายคำพูด/คูณแบบต่าง ๆ ให้เป็นแบบเดียว */
function iciNorm(string $s): string {
    $s = strtr($s, [
        "\u{200B}" => '', "\u{FEFF}" => '', "\u{00A0}" => ' ',
        "\u{201C}" => '"', "\u{201D}" => '"', "\u{2033}" => '"',
        "\u{2018}" => "'", "\u{2019}" => "'", "\u{00D7}" => 'x',
    ]);
    return trim((string)preg_replace('/\s+/u', ' ', $s));
}

/** กุญแจเทียบชื่อ — ไม่สนตัวพิมพ์ */
function iciKey(string $s): string {
    return mb_strtolower(iciNorm($s), 'UTF-8');
}

/** กุญแจเทียบหน่วย — แบบเดียวกับ llp_import (ตัดช่องว่างทั้งหมด) */
function iciUnitKey(string $s): string {
    return mb_strtolower((string)preg_replace('/\s+/u', '', $s), 'UTF-8');
}

/** ค่าที่แปลว่า "ไม่มี" */
function iciBlank(string $s): bool {
    return $s === '' || in_array(mb_strtolower($s, 'UTF-8'), ['-', '--', 'ไม่ระบุ', 'n/a'], true);
}

/** ขนาดจากชีต — ตัดคำว่า "ขนาด" นำหน้าออก (ขนาด 1" กับ 1" คือตัวเดียวกัน) */
function iciSizeText(string $s): string {
    $s = trim((string)preg_replace('/^ขนาด\s*/u', '', iciNorm($s)));
    return iciBlank($s) ? '' : $s;
}

/**
 * หน้าตาเป็น "ขนาด" ไหม — ขึ้นต้นด้วยตัวเลข หรือคำบอกขนาด (ยาว/หนา/เบอร์/Size/DB/M/#/@) แล้วตามด้วยตัวเลข
 * กันรุ่นเครื่อง/ยี่ห้อที่บังเอิญใช้ร่วมหลายตัวสินค้า เช่น "(อะไหล่ลูกหมู 4" MAKITA M0910)" ไม่ให้เข้าพจนานุกรมขนาด
 */
function iciLooksLikeSize(string $s): bool {
    return (bool)preg_match('/^(?:ยาว|หนา|กว้าง|สูง|เบอร์|size|no\.?|db|rb|dia\.?|ø|m(?=\d)|#|@)?\s*\d/iu', $s);
}

/** ยี่ห้อจากชีต */
function iciBrandText(string $s): string {
    $s = iciNorm($s);
    return iciBlank($s) ? '' : $s;
}

/** ตัดข้อความ $needle ครั้งแรกออกจาก $hay (ไม่สนตัวพิมพ์) — คืน [ผลลัพธ์, เจอไหม] */
function iciCut(string $hay, string $needle): array {
    if ($needle === '' || $hay === '') { return [$hay, false]; }
    $lh = mb_strtolower($hay, 'UTF-8');
    $ln = mb_strtolower($needle, 'UTF-8');
    // ตัวพิมพ์เล็กบางตัวยาวไม่เท่าตัวใหญ่ — ตำแหน่งจะเพี้ยน ถ้าเจอให้เทียบแบบตรงตัว
    $same = mb_strlen($lh, 'UTF-8') === mb_strlen($hay, 'UTF-8') && mb_strlen($ln, 'UTF-8') === mb_strlen($needle, 'UTF-8');
    $i = $same ? mb_strpos($lh, $ln, 0, 'UTF-8') : mb_strpos($hay, $needle, 0, 'UTF-8');
    if ($i === false) { return [$hay, false]; }
    $cut = mb_substr($hay, 0, $i, 'UTF-8') . ' ' . mb_substr($hay, $i + mb_strlen($needle, 'UTF-8'), null, 'UTF-8');
    return [iciNorm($cut), true];
}

/** เก็บกวาดส่วนที่เหลือของชื่อ — วงเล็บเปล่า · เครื่องหมายค้างหัวท้าย · "ขนาด" ลอย ๆ */
function iciResidue(string $s): string {
    $s = iciNorm((string)preg_replace('/\(\s*\)/u', ' ', $s));
    // ห้ามใช้ trim() กับอักขระหลายไบต์ (–) — ไบต์ท้ายไปชนกับตัวอักษรไทยแล้วตัวหนังสือพัง
    $s = (string)preg_replace('/^[\s,;:\/+&\-–]+|[\s,;:\/+&\-–]+$/u', '', $s);
    return ($s === 'ขนาด' || iciBlank($s)) ? '' : $s;
}

/** รหัส 3 หลักถัดไป — คืน '' ถ้าเกิน 999 ('000' สงวนไว้ = ไม่ระบุ) */
function iciNext(int &$max): string {
    if ($max >= 999) { return ''; }
    $max++;
    return str_pad((string)$max, 3, '0', STR_PAD_LEFT);
}

// ═══════════════════════════════════════════════════════════════════════════
// อ่านไฟล์ → แผน (ยังไม่เขียน DB)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * อ่านไฟล์แล้ววางแผนออก IC + ผูก — เรียกทั้งตอน preview และตอน commit (คำนวณใหม่ กัน DB ขยับ)
 * @return array ['rows' => [mat => แถว], 'ics' => [ic => IC], 'maps' => [[mat, llp, ic]],
 *                'new' => ของใหม่ที่จะเพิ่มในพจนานุกรม, 'dict' => ชื่อขนาด/ยี่ห้อ/หน่วย, 'llp' => LLP, 'stat' => สรุป]
 * @throws RuntimeException ถ้าไฟล์ไม่มีชีต/คอลัมน์ที่ต้องใช้
 */
function icImportRead(PDO $pdo, string $path): array {
    $sheets = xlsxReadSheets($path);
    $pick = function (string $want) use ($sheets) {
        foreach ($sheets as $name => $rows) {
            if (mb_strtolower(trim((string)$name), 'UTF-8') === mb_strtolower($want, 'UTF-8')) { return $rows; }
        }
        return null;
    };
    $idx = function (array $head): array {
        $m = [];
        foreach ($head as $i => $h) {
            $k = trim((string)$h);
            if ($k !== '' && !isset($m[$k])) { $m[$k] = $i; }
        }
        return $m;
    };
    $val = function (array $row, array $m, string $col): string {
        return isset($m[$col]) ? trim((string)($row[$m[$col]] ?? '')) : '';
    };

    $icS = $pick('IC_All Mat');
    if (!$icS) { throw new RuntimeException('ไม่พบชีต "IC_All Mat" ในไฟล์ — ไฟล์นี้ไม่ใช่ไฟล์สร้าง LLP/IC'); }
    $mI = $idx($icS[0]);
    foreach (['Product Code', 'SIZE NAME', 'Unit Name (IC)', 'ชื่อในPO'] as $need) {
        if (!isset($mI[$need])) { throw new RuntimeException('ชีต "IC_All Mat" ไม่มีคอลัมน์ "' . $need . '"'); }
    }
    $clS = $pick('Create_LLP_All Mat');
    if (!$clS) { throw new RuntimeException('ไม่พบชีต "Create_LLP_All Mat" ในไฟล์'); }
    $mC = $idx($clS[0]);
    $amS = $pick('All Mango Mat');
    $mA  = $amS ? $idx($amS[0]) : [];

    // ── LLP จากไฟล์ (ใช้กับรหัสนอกทะเบียน) + ธง Serial ──────────────────────
    $fileLlp = [];
    foreach (array_slice($clS, 1) as $r) {
        $mat = mangoNormalizeCode($val($r, $mC, 'Product Code'));
        $llp = strtoupper((string)preg_replace('/\s+/u', '', $val($r, $mC, 'LLP')));
        if ($mat === '' || strlen($llp) !== 8 || isset($fileLlp[$mat])) { continue; }
        $fileLlp[$mat] = $llp;
    }
    $serial = [];
    if ($amS && isset($mA['Product Code'], $mA['Serial No. (Y/N)'])) {
        foreach (array_slice($amS, 1) as $r) {
            $mat = mangoNormalizeCode($val($r, $mA, 'Product Code'));
            if ($mat !== '' && strtoupper($val($r, $mA, 'Serial No. (Y/N)')) === 'Y') { $serial[$mat] = true; }
        }
    }

    // ── สถานะปัจจุบันใน DB — โหลดทีเดียว ──────────────────────────────────
    $known = [];
    foreach ($pdo->query("SELECT id, mat_code, name, unit, cat_id, char_id FROM materials WHERE code_type = 'mango'")->fetchAll() as $r) {
        $known[(string)$r['mat_code']] = $r;
    }
    $dbMap = [];
    foreach ($pdo->query('SELECT mat_code, llp_code, ic_code FROM mango_ic_map ORDER BY id')->fetchAll() as $r) {
        $dbMap[(string)$r['mat_code']][] = ['llp' => (string)$r['llp_code'], 'ic' => (string)$r['ic_code']];
    }
    $LLP = [];
    foreach ($pdo->query(
        'SELECT p.llp_code, p.l1_code, p.l2_code, p.llp_name, p.cat_id, p.char_id, p.is_active, g.l1_name, c.l2_name
           FROM llp_products p
           LEFT JOIN l1_groups g     ON g.l1_code = p.l1_code
           LEFT JOIN l2_categories c ON c.l1_code = p.l1_code AND c.l2_code = p.l2_code'
    )->fetchAll() as $r) {
        $cat = icNormalizeCat($r['cat_id']);
        $chr = icNormalizeChar($r['char_id']);
        $LLP[(string)$r['llp_code']] = [
            'l1' => (string)$r['l1_code'], 'l2' => (string)$r['l2_code'], 'name' => (string)$r['llp_name'],
            'cat' => $cat, 'char' => $chr, 'set' => $cat !== null && $chr !== null,
            'active' => (int)$r['is_active'] === 1,
            'l1_name' => (string)($r['l1_name'] ?? ''), 'l2_name' => (string)($r['l2_name'] ?? ''),
        ];
    }
    $loadDict = function (string $sql) use ($pdo): array {
        $d = ['by' => [], 'name' => [], 'max' => 0, 'off' => []];
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_NUM) as $r) {
            $code = (string)$r[0];
            $k    = iciKey((string)$r[1]);
            if (!isset($d['by'][$k])) { $d['by'][$k] = $code; }
            $d['name'][$code] = (string)$r[1];
            if (isset($r[2]) && (int)$r[2] !== 1) { $d['off'][$code] = true; }
            if (ctype_digit($code)) { $d['max'] = max($d['max'], (int)$code); }
        }
        return $d;
    };
    $SZ = $loadDict('SELECT size_code, size_name, is_active FROM sizes ORDER BY size_code');
    $BR = $loadDict('SELECT brand_code, brand_name, is_active FROM brands ORDER BY brand_code');
    $UN = ['by' => [], 'name' => [], 'max' => 0, 'off' => []];
    foreach ($pdo->query('SELECT unit_code, unit_name, is_active FROM units ORDER BY unit_code')->fetchAll() as $r) {
        $code = (string)$r['unit_code'];
        $k    = iciUnitKey((string)$r['unit_name']);
        if (!isset($UN['by'][$k])) { $UN['by'][$k] = $code; }
        $UN['name'][$code] = (string)$r['unit_name'];
        if ((int)$r['is_active'] !== 1) { $UN['off'][$code] = true; }
        if (ctype_digit($code)) { $UN['max'] = max($UN['max'], (int)$code); }
    }
    $EX = [];
    foreach ($pdo->query('SELECT llp_code, extra_code, extra_name FROM extra_attrs ORDER BY llp_code, extra_code')->fetchAll() as $r) {
        $l = (string)$r['llp_code'];
        if (!isset($EX[$l])) { $EX[$l] = ['by' => [], 'max' => 0]; }
        $k = iciKey((string)$r['extra_name']);
        if (!isset($EX[$l]['by'][$k])) { $EX[$l]['by'][$k] = (string)$r['extra_code']; }
        if (ctype_digit((string)$r['extra_code'])) { $EX[$l]['max'] = max($EX[$l]['max'], (int)$r['extra_code']); }
    }
    $L2S = []; $L2B = [];
    foreach ($pdo->query('SELECT l1_code, l2_code, size_code FROM l2_sizes')->fetchAll(PDO::FETCH_NUM) as $r) {
        $L2S[$r[0] . $r[1] . '|' . $r[2]] = true;
    }
    foreach ($pdo->query('SELECT l1_code, l2_code, brand_code FROM l2_brands')->fetchAll(PDO::FETCH_NUM) as $r) {
        $L2B[$r[0] . $r[1] . '|' . $r[2]] = true;
    }
    $IC = [];
    foreach ($pdo->query('SELECT ic_code, ic_name FROM ic_items')->fetchAll(PDO::FETCH_NUM) as $r) { $IC[(string)$r[0]] = (string)$r[1]; }
    $MATIC = [];
    foreach ($pdo->query("SELECT mat_code FROM materials WHERE code_type = 'ic'")->fetchAll(PDO::FETCH_COLUMN) as $c) { $MATIC[(string)$c] = true; }

    // ยอดที่ยังค้างบนรหัส Mango — ใช้บอกว่าอะไรถือยอดอยู่ (เหมือน smStats: ยอดคงเหลือ หรือ อยู่ในวัสดุโครงการ)
    $stock = [];
    foreach ($pdo->query(
        "SELECT m.mat_code, SUM(b.on_hand) AS on_hand FROM stock_balances b JOIN materials m ON m.id = b.material_id
          WHERE m.code_type = 'mango' GROUP BY m.mat_code"
    )->fetchAll() as $r) {
        $stock[(string)$r['mat_code']] = (float)$r['on_hand'];
    }
    foreach ($pdo->query(
        "SELECT DISTINCT m.mat_code FROM project_materials x JOIN materials m ON m.id = x.material_id WHERE m.code_type = 'mango'"
    )->fetchAll(PDO::FETCH_COLUMN) as $c) {
        if (!isset($stock[(string)$c])) { $stock[(string)$c] = 0.0; }
    }

    // ── รอบ 1: อ่านแถว + หา LLP ────────────────────────────────────────────
    $rows = []; $dupRows = 0;
    foreach (array_slice($icS, 1) as $r) {
        $mat = mangoNormalizeCode($val($r, $mI, 'Product Code'));
        if ($mat === '') { continue; }
        if (isset($rows[$mat])) { $dupRows++; continue; }

        $unit  = iciNorm($val($r, $mI, 'Unit Name (IC)'));
        if ($unit === '') { $unit = iciNorm($val($r, $mI, 'Unit Name (PO)')); }
        $rawSz = $val($r, $mI, 'SIZE NAME');
        $rawBr = $val($r, $mI, 'BRAND NAME');
        $po    = iciNorm($val($r, $mI, 'ชื่อในPO'));
        if ($po === '') { $po = iciNorm($val($r, $mI, 'PRODUCT NAME') . ' ' . $rawSz . ' ' . $rawBr); }

        $row = [
            'mat' => $mat, 'po' => $po, 'in_sys' => isset($known[$mat]), 'stock' => $stock[$mat] ?? null,
            'size' => iciSizeText($rawSz), 'brand' => iciBrandText($rawBr), 'unit' => $unit,
            'serial' => isset($serial[$mat]), 'llp' => '', 'file_llp' => $fileLlp[$mat] ?? '',
            'status' => '', 'ic' => '', 'old_ic' => [], 'size_std' => '', 'brand_use' => '', 'extra' => '',
        ];
        if ($row['in_sys']) {
            $llps = []; $ics = [];
            foreach ($dbMap[$mat] ?? [] as $x) {
                $llps[$x['llp']] = true;
                if ($x['ic'] !== '') { $ics[] = $x['ic']; }
            }
            if ($ics)                 { $row['status'] = 'has_ic'; $row['old_ic'] = $ics; $row['llp'] = implode(', ', array_keys($llps)); }
            elseif (!$llps)           { $row['status'] = 'no_llp'; }
            elseif (count($llps) > 1) { $row['status'] = 'multi_llp'; $row['llp'] = implode(', ', array_keys($llps)); }
            else                      { $row['llp'] = (string)array_key_first($llps); }
        } else {
            $row['llp'] = $row['file_llp'];
            if ($row['llp'] === '') { $row['status'] = 'no_llp'; }
        }
        if ($row['status'] === '') {
            $L = $LLP[$row['llp']] ?? null;
            if ($L === null)                                                   { $row['status'] = 'llp_missing'; }
            elseif (!$L['active'])                                             { $row['status'] = 'llp_off'; }
            elseif ($unit === '' || mb_strlen($unit, 'UTF-8') > ICI_MAX_UNIT) { $row['status'] = 'no_unit'; }
            elseif (!$L['set'])                                                { $row['status'] = 'llp_unset'; }
            else                                                               { $row['status'] = 'ok'; }
        }
        $rows[$mat] = $row;
    }
    $inFile = count($rows);

    // รหัสในทะเบียนระบบที่ไม่มีในชีต — ใส่ไว้ให้เห็นในรายงาน
    foreach ($known as $mat => $k) {
        if (isset($rows[$mat])) { continue; }
        $ics = []; $llps = [];
        foreach ($dbMap[$mat] ?? [] as $x) { $llps[$x['llp']] = true; if ($x['ic'] !== '') { $ics[] = $x['ic']; } }
        $rows[$mat] = [
            'mat' => $mat, 'po' => iciNorm((string)$k['name']), 'in_sys' => true, 'stock' => $stock[$mat] ?? null,
            'size' => '', 'brand' => '', 'unit' => (string)$k['unit'], 'serial' => false,
            'llp' => implode(', ', array_keys($llps)), 'file_llp' => '',
            'status' => $ics ? 'has_ic' : 'not_in_file', 'ic' => '', 'old_ic' => $ics,
            'size_std' => '', 'brand_use' => '', 'extra' => '',
        ];
    }

    // ── รอบ 2: ขนาดมาตรฐาน = หน้าตาเป็นขนาด + ใช้ร่วมกันตั้งแต่ ICI_STD_MIN_LLP ตัวสินค้า ──────
    $use = [];
    foreach ($rows as $row) {
        if ($row['size'] === '' || !isset($LLP[$row['llp']])) { continue; }
        if (!iciLooksLikeSize($row['size']) || mb_strlen($row['size'], 'UTF-8') > ICI_MAX_SIZE) { continue; }
        $use[iciKey($row['size'])][$row['llp']] = true;
    }
    $std = [];
    foreach ($use as $k => $ls) { if (count($ls) >= ICI_STD_MIN_LLP) { $std[$k] = true; } }

    // ── รอบ 3: แตกสเปก (ทำให้แถวที่ติด Cat/Char ด้วย — ไว้โชว์ในรายงาน) ─────────
    foreach ($rows as $mat => &$row) {
        if ($row['status'] !== 'ok' && $row['status'] !== 'llp_unset') { continue; }
        list($rest) = iciCut($row['po'], iciNorm($LLP[$row['llp']]['name']));
        $sz = $row['size'];
        if ($sz !== '') {
            list($r2, $f) = iciCut($rest, 'ขนาด ' . $sz);
            if (!$f) { list($r2, $f) = iciCut($rest, $sz); }
            if ($f)  { $rest = $r2; }
        }
        if ($row['brand'] !== '') { list($rest) = iciCut($rest, $row['brand']); }
        $res = iciResidue($rest);

        $k     = iciKey($sz);
        $isStd = $sz !== '' && mb_strlen($sz, 'UTF-8') <= ICI_MAX_SIZE && (isset($std[$k]) || isset($SZ['by'][$k]));
        $brand = $row['brand'];
        $bits  = [$isStd ? '' : $sz, $res];
        if (mb_strlen($brand, 'UTF-8') > ICI_MAX_BRAND) { $bits[] = $brand; $brand = ''; }

        $row['size_std']  = $isStd ? $sz : '';
        $row['brand_use'] = $brand;
        $row['extra']     = iciNorm(implode(' ', array_filter($bits, 'strlen')));
        if (mb_strlen($row['extra'], 'UTF-8') > ICI_MAX_EXTRA) { $row['status'] = 'too_long'; }
    }
    unset($row);

    // ── รอบ 4: จัดรหัส + รวมเป็น IC (เรียงตามรหัส Mango — คุณสมบัติได้เลขตามลำดับขนาดของ Mango) ──
    $new = ['size' => [], 'brand' => [], 'unit' => [], 'extra' => [], 'l2s' => [], 'l2b' => [],
            'size_on' => [], 'brand_on' => [], 'unit_on' => []];
    $okMats = [];
    foreach ($rows as $mat => $row) { if ($row['status'] === 'ok') { $okMats[] = (string)$mat; } }
    sort($okMats, SORT_STRING);

    $ICS = [];
    foreach ($okMats as $mat) {
        $row = &$rows[$mat];
        $L   = $LLP[$row['llp']];
        $l12 = $L['l1'] . $L['l2'];

        $sc = IC_NONE;
        if ($row['size_std'] !== '') {
            $k = iciKey($row['size_std']);
            if (!isset($SZ['by'][$k])) {
                $c = iciNext($SZ['max']);
                if ($c === '') { $row['status'] = 'full'; unset($row); continue; }
                $SZ['by'][$k] = $c; $SZ['name'][$c] = $row['size_std']; $new['size'][$c] = $row['size_std'];
            }
            $sc = $SZ['by'][$k];
            if (isset($SZ['off'][$sc])) { $new['size_on'][$sc] = true; }
        }
        $bc = IC_NONE;
        if ($row['brand_use'] !== '') {
            $k = iciKey($row['brand_use']);
            if (!isset($BR['by'][$k])) {
                $c = iciNext($BR['max']);
                if ($c === '') { $row['status'] = 'full'; unset($row); continue; }
                $BR['by'][$k] = $c; $BR['name'][$c] = $row['brand_use']; $new['brand'][$c] = $row['brand_use'];
            }
            $bc = $BR['by'][$k];
            if (isset($BR['off'][$bc])) { $new['brand_on'][$bc] = true; }
        }
        $uk = iciUnitKey($row['unit']);
        if (!isset($UN['by'][$uk])) {
            $c = iciNext($UN['max']);
            if ($c === '') { $row['status'] = 'full'; unset($row); continue; }
            $UN['by'][$uk] = $c; $UN['name'][$c] = $row['unit']; $new['unit'][$c] = $row['unit'];
        }
        $uc = $UN['by'][$uk];
        if (isset($UN['off'][$uc])) { $new['unit_on'][$uc] = true; }

        $ec = IC_NONE;
        if ($row['extra'] !== '') {
            $l = $row['llp'];
            if (!isset($EX[$l])) { $EX[$l] = ['by' => [], 'max' => 0]; }
            $k = iciKey($row['extra']);
            if (!isset($EX[$l]['by'][$k])) {
                $c = iciNext($EX[$l]['max']);
                if ($c === '') { $row['status'] = 'full'; unset($row); continue; }
                $EX[$l]['by'][$k] = $c;
                $new['extra'][] = [$l, $c, $row['extra']];
            }
            $ec = $EX[$l]['by'][$k];
        }

        // ขนาด/ยี่ห้อที่ใช้ต้องผูกกับหมวดนั้น (icValidateParts ตรวจ) — '000' ด้วยเผื่อหมวดเก่า
        foreach ([IC_NONE, $sc] as $c) {
            if (!isset($L2S[$l12 . '|' . $c])) { $L2S[$l12 . '|' . $c] = true; $new['l2s'][] = [$L['l1'], $L['l2'], $c]; }
        }
        foreach ([IC_NONE, $bc] as $c) {
            if (!isset($L2B[$l12 . '|' . $c])) { $L2B[$l12 . '|' . $c] = true; $new['l2b'][] = [$L['l1'], $L['l2'], $c]; }
        }

        $ic = icCompose($row['llp'], $sc, $bc, $uc, $ec);
        $row['ic'] = $ic;
        if (!isset($ICS[$ic])) {
            $ICS[$ic] = [
                'ic' => $ic, 'llp' => $row['llp'], 'l1' => $L['l1'], 'l2' => $L['l2'],
                'l1_name' => $L['l1_name'], 'l2_name' => $L['l2_name'], 'llp_name' => $L['name'],
                'size' => $sc, 'brand' => $bc, 'unit' => $uc, 'extra' => $ec,
                'extra_name' => $row['extra'], 'cat' => $L['cat'], 'char' => $L['char'],
                'serial' => false, 'members' => [], 'exists' => isset($IC[$ic]), 'mat_row' => isset($MATIC[$ic]),
                'name' => '',
            ];
        }
        $ICS[$ic]['members'][] = $mat;
        if ($row['serial']) { $ICS[$ic]['serial'] = true; }
        unset($row);
    }

    // ชื่อ IC + แผนผูก — สมาชิกเรียงตามรหัสอยู่แล้ว (มาจาก $okMats) · ตัวแทน = ตัวแรกที่อยู่ในทะเบียนระบบ
    $maps = [];
    foreach ($ICS as $ic => &$x) {
        $inSys = []; $outSys = [];
        foreach ($x['members'] as $mat) {
            if ($rows[$mat]['in_sys']) { $inSys[] = $mat; } else { $outSys[] = $mat; }
        }
        $x['members'] = array_merge($inSys, $outSys);
        $x['name'] = $x['exists'] ? $IC[$ic] : mb_substr($rows[$x['members'][0]]['po'], 0, 255, 'UTF-8');
        if ($x['name'] === '') { $x['name'] = trim($x['llp_name'] . ' ' . $x['extra_name']); }
        foreach ($x['members'] as $mat) {
            if ($rows[$mat]['in_sys']) {
                $rows[$mat]['status'] = 'map';
                $maps[] = [(string)$mat, $x['llp'], (string)$ic];
            } else {
                $rows[$mat]['status'] = 'issued';
            }
        }
    }
    unset($x);

    // ── สรุป ────────────────────────────────────────────────────────────────
    $by = array_fill_keys(array_keys(iciStatusLabels()), 0);
    $sysBy = $by; $stkBy = $by;
    foreach ($rows as $row) {
        $s = $row['status'];
        $by[$s] = ($by[$s] ?? 0) + 1;
        if ($row['in_sys'])         { $sysBy[$s] = ($sysBy[$s] ?? 0) + 1; }
        if ($row['stock'] !== null) { $stkBy[$s] = ($stkBy[$s] ?? 0) + 1; }
    }
    $icNew = 0; $icMulti = 0; $matNew = 0; $serialN = 0;
    foreach ($ICS as $x) {
        if (!$x['exists']) { $icNew++; }
        if (!$x['mat_row']) { $matNew++; }
        if (count($x['members']) > 1) { $icMulti++; }
        if ($x['serial']) { $serialN++; }
    }
    $blockLlp = [];
    foreach ($rows as $row) {
        if ($row['status'] === 'llp_unset') { $blockLlp[$row['llp']] = true; }
    }

    return [
        'rows' => $rows, 'ics' => $ICS, 'maps' => $maps, 'new' => $new,
        'dict' => ['size' => $SZ['name'], 'brand' => $BR['name'], 'unit' => $UN['name']],
        'llp'  => $LLP, 'known' => $known,
        'stat' => [
            'file'       => $path,
            'file_rows'  => $inFile,
            'dup_rows'   => $dupRows,
            'sys_total'  => count($known),
            'by'         => $by,
            'sys_by'     => $sysBy,
            'stock_by'   => $stkBy,
            'stock_total'=> count($stock),
            'ic_total'   => count($ICS),
            'ic_new'     => $icNew,
            'ic_exist'   => count($ICS) - $icNew,
            'ic_multi'   => $icMulti,
            'ic_serial'  => $serialN,
            'mat_new'    => $matNew,
            'map_new'    => count($maps),
            'std_sizes'  => count($std),
            'size_new'   => count($new['size']),
            'brand_new'  => count($new['brand']),
            'unit_new'   => count($new['unit']),
            'extra_new'  => count($new['extra']),
            'block_llp'  => count($blockLlp),
        ],
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// เขียนลง DB
// ═══════════════════════════════════════════════════════════════════════════

/**
 * เขียนแผนลง DB ในทรานแซกชันเดียว — พลาดตรงไหน rollback ทั้งหมด
 * ออก IC ด้วยคำสั่งชุด (ไม่วน icCreate ทีละตัว — หมื่นแถว = แสนคำสั่ง เสี่ยงชน max_execution_time)
 * แต่ค่าที่เขียนตรงกับ icCreate + icEnsureMaterial ทุกช่อง · ตรวจซ้ำได้ด้วย icImportVerify
 * @return array จำนวนที่เขียนจริง
 */
function icImportApply(PDO $pdo, array $p, ?array $user = null): array {
    $n = ['size' => 0, 'brand' => 0, 'unit' => 0, 'reopen' => 0, 'extra' => 0, 'l2s' => 0, 'l2b' => 0,
          'ic' => 0, 'material' => 0, 'map' => 0, 'map_skip' => 0];
    $uid   = $user !== null ? ((int)($user['accountId'] ?? 0) ?: null) : null;
    // ถามสด ๆ ไม่ใช้ icHasFlagColumns (จำค่าไว้ทั้ง request — ถ้าเพิ่งเพิ่มคอลัมน์ก่อนเรียก ธง Serial จะหายเงียบ)
    $flags = (bool)$pdo->query("SHOW COLUMNS FROM ic_items LIKE 'has_serial'")->fetch();
    $new   = $p['new'];

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) { $pdo->beginTransaction(); }
    try {
        $pdo->exec("INSERT IGNORE INTO sizes (size_code, size_name) VALUES ('000','ไม่ระบุ')");
        $pdo->exec("INSERT IGNORE INTO brands (brand_code, brand_name) VALUES ('000','ไม่ระบุ')");

        $ins = $pdo->prepare('INSERT INTO sizes (size_code, size_name) VALUES (?,?)');
        foreach ($new['size'] as $c => $name) { $ins->execute([(string)$c, $name]); $n['size']++; }
        $ins = $pdo->prepare('INSERT INTO brands (brand_code, brand_name) VALUES (?,?)');
        foreach ($new['brand'] as $c => $name) { $ins->execute([(string)$c, $name]); $n['brand']++; }
        $ins = $pdo->prepare('INSERT INTO units (unit_code, unit_name, is_active) VALUES (?,?,1)');
        foreach ($new['unit'] as $c => $name) { $ins->execute([(string)$c, $name]); $n['unit']++; }

        // ของเดิมที่ถูกปิดไว้แต่ไฟล์ยังใช้ → เปิดกลับ (icValidateParts ไม่รับหน่วยที่ปิด)
        foreach (['unit_on' => 'units SET is_active = 1 WHERE unit_code', 'size_on' => 'sizes SET is_active = 1 WHERE size_code',
                  'brand_on' => 'brands SET is_active = 1 WHERE brand_code'] as $key => $sql) {
            if (empty($new[$key])) { continue; }
            $up = $pdo->prepare('UPDATE ' . $sql . ' = ?');
            foreach (array_keys($new[$key]) as $c) { $up->execute([(string)$c]); $n['reopen'] += $up->rowCount(); }
        }

        $ins = $pdo->prepare('INSERT INTO extra_attrs (llp_code, extra_code, extra_name) VALUES (?,?,?)');
        foreach ($new['extra'] as $x) { $ins->execute($x); $n['extra']++; }

        $ins = $pdo->prepare('INSERT IGNORE INTO l2_sizes (l1_code, l2_code, size_code) VALUES (?,?,?)');
        foreach ($new['l2s'] as $x) { $ins->execute($x); $n['l2s'] += $ins->rowCount(); }
        $ins = $pdo->prepare('INSERT IGNORE INTO l2_brands (l1_code, l2_code, brand_code) VALUES (?,?,?)');
        foreach ($new['l2b'] as $x) { $ins->execute($x); $n['l2b'] += $ins->rowCount(); }

        // IC + แถวคู่ใน materials (มติ 3) — cat/char เป็นสำเนาจาก LLP (มติ 28-29)
        $cols = 'ic_code, llp_code, l1_code, l2_code, size_code, brand_code, unit_code, extra_code,
                 ic_name, cat_id, char_id, created_project_id, created_by' . ($flags ? ', has_serial, is_cx' : '');
        $insIc  = $pdo->prepare('INSERT INTO ic_items (' . $cols . ') VALUES (' . implode(',', array_fill(0, $flags ? 15 : 13, '?')) . ')');
        $insMat = $pdo->prepare('INSERT INTO materials (mat_code, code_type, name, unit, cat_id, char_id, subgroup_name)
                                 VALUES (?,?,?,?,?,?,?)');
        foreach ($p['ics'] as $ic => $x) {
            if (!$x['exists']) {
                $vals = [(string)$ic, $x['llp'], $x['l1'], $x['l2'], $x['size'], $x['brand'], $x['unit'], $x['extra'],
                         $x['name'], $x['cat'], $x['char'], null, $uid];
                if ($flags) { $vals[] = $x['serial'] ? 1 : 0; $vals[] = 1; }
                $insIc->execute($vals);
                $n['ic']++;
            }
            if (!$x['mat_row']) {
                $insMat->execute([(string)$ic, 'ic', $x['name'], (string)($p['dict']['unit'][$x['unit']] ?? ''),
                                  (string)$x['cat'], $x['char'],
                                  mb_substr(trim($x['l1_name'] . ' › ' . $x['l2_name']), 0, 150, 'UTF-8')]);
                $n['material']++;
            }
        }

        // ผูก — อัปเกรดแถวขั้น 1 ของ LLP นั้น (เหมือน smAttachIc) + จำลง ic_suggest_map (เหมือน smRememberSuggest)
        $up   = $pdo->prepare("UPDATE mango_ic_map SET ic_code = ?, mapped_by = ?, mapped_at = NOW()
                                WHERE mat_code = ? AND llp_code = ? AND ic_code = ''");
        $delS = $pdo->prepare('DELETE FROM ic_suggest_map WHERE vendor_key = ? AND mat_code = ?');
        $insS = $pdo->prepare('INSERT INTO ic_suggest_map (vendor_key, mat_code, ic_code) VALUES (?,?,?)
                               ON DUPLICATE KEY UPDATE last_used = CURRENT_TIMESTAMP');
        foreach ($p['maps'] as $m) {
            list($mat, $llp, $ic) = $m;
            $up->execute([$ic, $uid, $mat, $llp]);
            if ($up->rowCount() === 0) { $n['map_skip']++; continue; }   // มีคนผูก/ถอดไประหว่างนั้น
            $n['map']++;
            $delS->execute([SM_SUGGEST_VENDOR, $mat]);
            $insS->execute([SM_SUGGEST_VENDOR, $mat, $ic]);
        }

        mangoLog($pdo, $user, 'ic_import', 'ic_import', null, [
            'file' => basename((string)$p['stat']['file']),
            'ic' => $n['ic'], 'map' => $n['map'], 'size' => $n['size'], 'brand' => $n['brand'],
            'unit' => $n['unit'], 'extra' => $n['extra'],
        ]);

        if ($ownTx) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return $n;
}

/**
 * ตรวจซ้ำหลังเขียน — IC ในแผนทุกตัวต้องผ่าน icValidateParts และมีแถวคู่ใน materials
 * @return array ['checked' => n, 'bad' => [[ic, ข้อความ], ...]]
 */
function icImportVerify(PDO $pdo, array $p): array {
    $bad = []; $checked = 0;
    $hasMat = $pdo->prepare("SELECT 1 FROM materials WHERE mat_code = ? AND code_type = 'ic'");
    $hasIc  = $pdo->prepare('SELECT 1 FROM ic_items WHERE ic_code = ?');
    foreach ($p['ics'] as $ic => $x) {
        $checked++;
        $v = icValidateParts($pdo, ['llp_code' => $x['llp'], 'size_code' => $x['size'], 'brand_code' => $x['brand'],
                                    'unit_code' => $x['unit'], 'extra_code' => $x['extra']]);
        if (!$v['ok']) { $bad[] = [(string)$ic, $v['error']]; continue; }
        $hasIc->execute([(string)$ic]);
        if (!$hasIc->fetchColumn())  { $bad[] = [(string)$ic, 'ไม่มีใน ic_items']; continue; }
        $hasMat->execute([(string)$ic]);
        if (!$hasMat->fetchColumn()) { $bad[] = [(string)$ic, 'ไม่มีแถวคู่ใน materials']; }
    }
    return ['checked' => $checked, 'bad' => $bad];
}

// ═══════════════════════════════════════════════════════════════════════════
// ไฟล์ตรวจผล .xlsx — ให้ฝ่ายจัดซื้อ/ADM ไล่ดู
// ═══════════════════════════════════════════════════════════════════════════

/** ตัวเลขยอดเป็นข้อความ (ตัดศูนย์ท้าย) */
function iciQty($v): string {
    if ($v === null) { return ''; }
    $s = rtrim(rtrim(number_format((float)$v, 3, '.', ''), '0'), '.');
    return $s === '' || $s === '-0' ? '0' : $s;
}

/**
 * สร้างไฟล์ตรวจผล — $applied = ผลจาก icImportApply (null = ยังไม่ได้เขียน แค่ตรวจ)
 * @return string ไบนารี .xlsx
 */
function icImportReportXlsx(array $p, ?array $applied = null): string {
    $st  = $p['stat'];
    $lab = iciStatusLabels();
    $rows = $p['rows'];
    $ics  = $p['ics'];

    // ── สรุป ──
    $sum = [['หัวข้อ', 'ค่า']];
    $sum[] = ['ไฟล์ต้นทาง', basename((string)$st['file'])];
    $sum[] = ['สร้างเมื่อ', date('Y-m-d H:i')];
    $sum[] = ['สถานะ', $applied === null ? 'ตรวจอย่างเดียว — ยังไม่ได้เขียนลงระบบ' : 'เขียนลงระบบแล้ว'];
    $sum[] = ['', ''];
    $sum[] = ['แถวในชีต IC_All Mat (รหัส Mango)', number_format($st['file_rows'])];
    $sum[] = ['รหัส Mango ในทะเบียนระบบ', number_format($st['sys_total'])];
    $sum[] = ['รหัส IC ในแผน (ไม่ซ้ำ)', number_format($st['ic_total']) . '  (ใหม่ ' . number_format($st['ic_new'])
                                         . ' · มีอยู่แล้ว ' . number_format($st['ic_exist']) . ')'];
    $sum[] = ['IC ที่รวมรหัส Mango มากกว่า 1 ตัว', number_format($st['ic_multi'])];
    $sum[] = ['IC ที่ตั้งธง Serial (จาก Mango)', number_format($st['ic_serial'])];
    $sum[] = ['ผูก Mango → IC (รหัสในระบบ)', number_format($st['map_new'])];
    $sum[] = ['ขนาดมาตรฐาน (ใช้ร่วม ≥ ' . ICI_STD_MIN_LLP . ' ตัวสินค้า)', number_format($st['std_sizes'])
                                         . '  (เพิ่มเข้าพจนานุกรมรอบนี้ ' . number_format($st['size_new']) . ')'];
    $sum[] = ['ยี่ห้อใหม่ / หน่วยใหม่ / คุณสมบัติใหม่', $st['brand_new'] . ' / ' . $st['unit_new'] . ' / ' . number_format($st['extra_new'])];
    $sum[] = ['ตัวสินค้าที่ยังไม่ตั้ง CatID/CharID (ทำให้ออก IC ไม่ได้)', number_format($st['block_llp'])];
    $sum[] = ['', ''];
    $sum[] = ['ผลต่อรหัส Mango', 'ทั้งไฟล์ · ในทะเบียนระบบ · ที่ถือยอด'];
    foreach ($lab as $k => $t) {
        if (($st['by'][$k] ?? 0) === 0 && ($st['sys_by'][$k] ?? 0) === 0) { continue; }
        $sum[] = [$t, number_format($st['by'][$k] ?? 0) . ' · ' . number_format($st['sys_by'][$k] ?? 0) . ' · '
                      . number_format($st['stock_by'][$k] ?? 0)];
    }
    if ($applied !== null) {
        $sum[] = ['', ''];
        $sum[] = ['เขียนจริง', 'IC ' . $applied['ic'] . ' · materials ' . $applied['material'] . ' · ผูก ' . $applied['map']
                  . ' · ขนาด ' . $applied['size'] . ' · ยี่ห้อ ' . $applied['brand'] . ' · หน่วย ' . $applied['unit']
                  . ' · คุณสมบัติ ' . $applied['extra']];
    }
    $sum[] = ['', ''];
    $sum[] = ['กติกาแตกสเปก', ''];
    $sum[] = ['1. ic = LLP(8) + ขนาด(3) + ยี่ห้อ(3) + หน่วย(3) + คุณสมบัติ(3)', ''];
    $sum[] = ['2. ขนาดกลางมีได้ 999 ทั้งระบบ — รับเฉพาะค่าที่ขึ้นต้นด้วยตัวเลข (หรือ ยาว/หนา/เบอร์/Size/DB/M…) และใช้ร่วมกันตั้งแต่ 2 ตัวสินค้า', ''];
    $sum[] = ['3. ขนาดอื่น (รุ่น/เบอร์/สเปกเฉพาะ) + ส่วนที่เหลือของชื่อในPO (S/N ฯลฯ) = คุณสมบัติเพิ่มของตัวสินค้านั้น', ''];
    $sum[] = ['4. รหัส Mango ที่สเปกเหมือนกันทุกช่องรวมเป็น IC เดียว — ชื่อต่างกันไม่รวม', ''];
    $sum[] = ['5. ชื่อ IC = ชื่อในPO ของรหัส Mango ตัวแทน · หน่วย = Unit Name (IC) · Serial = Serial No. (Y/N) ของ Mango', ''];
    $sum[] = ['6. ตัวสินค้าที่ยังไม่ตั้ง CatID/CharID ออก IC ไม่ได้ (มติ 32) — ตั้งแล้วนำเข้าซ้ำ ระบบออกส่วนที่เหลือให้', ''];
    $sum[] = ['   ตั้งค่าที่หน้า "ตั้งค่าตัวสินค้า (LLP)" → โหลดไฟล์ "เฉพาะที่ยังไม่ตั้ง" กรอกแล้วอัปโหลด (ชีต "รอตั้ง Cat-Char" ในไฟล์นี้มีค่าเดิมของ Mango ไว้ช่วยตัดสินใจ)', ''];

    // ── ทะเบียน IC ──
    $reg = [['รหัส IC', 'ชื่อ IC', 'LLP', 'ชื่อตัวสินค้า', 'กลุ่มใหญ่ (L1)', 'หมวด (L2)', 'ขนาด', 'ชื่อขนาด',
             'ยี่ห้อ', 'ชื่อยี่ห้อ', 'หน่วย', 'ชื่อหน่วย', 'คุณสมบัติ', 'ชื่อคุณสมบัติ', 'CatID', 'CharID',
             'Serial', 'จำนวน Mango', 'รหัส Mango', 'สถานะ']];
    $icList = $ics;
    ksort($icList, SORT_STRING);
    foreach ($icList as $ic => $x) {
        $reg[] = [(string)$ic, $x['name'], $x['llp'], $x['llp_name'], $x['l1'] . ' ' . $x['l1_name'],
                  $x['l2'] . ' ' . $x['l2_name'],
                  $x['size'], $x['size'] === IC_NONE ? '' : (string)($p['dict']['size'][$x['size']] ?? ''),
                  $x['brand'], $x['brand'] === IC_NONE ? '' : (string)($p['dict']['brand'][$x['brand']] ?? ''),
                  $x['unit'], (string)($p['dict']['unit'][$x['unit']] ?? ''),
                  $x['extra'], $x['extra'] === IC_NONE ? '' : $x['extra_name'],
                  (string)$x['cat'], (string)$x['char'], $x['serial'] ? 'Y' : '',
                  (string)count($x['members']), implode(', ', $x['members']),
                  $x['exists'] ? 'มีอยู่แล้ว' : ($applied === null ? 'จะออกใหม่' : 'ออกใหม่')];
    }

    // ── Mango → IC ──
    $mm = [['รหัส Mango', 'ชื่อในPO / ชื่อในระบบ', 'อยู่ในทะเบียนระบบ', 'ยอดคงเหลือรวม', 'LLP', 'ชื่อตัวสินค้า',
            'ขนาด (ไฟล์)', 'ยี่ห้อ (ไฟล์)', 'หน่วยเก็บ (ไฟล์)', 'คุณสมบัติที่แตกได้', 'รหัส IC', 'ชื่อ IC', 'ผล']];
    $order = array_keys($rows);
    usort($order, function ($a, $b) use ($rows) {
        $ka = ($rows[$a]['stock'] !== null ? 0 : 1) . ($rows[$a]['in_sys'] ? 0 : 1);
        $kb = ($rows[$b]['stock'] !== null ? 0 : 1) . ($rows[$b]['in_sys'] ? 0 : 1);
        return $ka !== $kb ? strcmp($ka, $kb) : strcmp((string)$a, (string)$b);
    });
    foreach ($order as $mat) {
        $r   = $rows[$mat];
        $ic  = $r['ic'] !== '' ? $r['ic'] : implode(', ', $r['old_ic']);
        $icn = $r['ic'] !== '' ? (string)($ics[$r['ic']]['name'] ?? '') : '';
        $llpName = isset($p['llp'][$r['llp']]) ? $p['llp'][$r['llp']]['name'] : '';
        $mm[] = [(string)$mat, $r['po'], $r['in_sys'] ? 'Y' : '', iciQty($r['stock']),
                 $r['llp'] !== '' ? $r['llp'] : ($r['file_llp'] !== '' ? '(ไฟล์: ' . $r['file_llp'] . ')' : ''),
                 $llpName, $r['size'], $r['brand'], $r['unit'], $r['extra'], $ic, $icn,
                 $lab[$r['status']] ?? $r['status']];
    }

    // ── ขนาดมาตรฐาน / ยี่ห้อ ที่ใช้ ──
    $szUse = []; $brUse = [];
    foreach ($ics as $x) {
        if ($x['size'] !== IC_NONE)  { $szUse[$x['size']]['n'] = ($szUse[$x['size']]['n'] ?? 0) + 1; $szUse[$x['size']]['l2'][$x['l1'] . $x['l2']] = true; }
        if ($x['brand'] !== IC_NONE) { $brUse[$x['brand']]['n'] = ($brUse[$x['brand']]['n'] ?? 0) + 1; $brUse[$x['brand']]['l2'][$x['l1'] . $x['l2']] = true; }
    }
    ksort($szUse, SORT_STRING); ksort($brUse, SORT_STRING);
    $szs = [['รหัสขนาด', 'ชื่อขนาด', 'จำนวน IC', 'หมวดที่ใช้', 'ใหม่รอบนี้']];
    foreach ($szUse as $c => $u) {
        $szs[] = [(string)$c, (string)($p['dict']['size'][$c] ?? ''), (string)$u['n'], implode(' ', array_keys($u['l2'])),
                  isset($p['new']['size'][$c]) ? 'Y' : ''];
    }
    $brs = [['รหัสยี่ห้อ', 'ชื่อยี่ห้อ', 'จำนวน IC', 'หมวดที่ใช้', 'ใหม่รอบนี้']];
    foreach ($brUse as $c => $u) {
        $brs[] = [(string)$c, (string)($p['dict']['brand'][$c] ?? ''), (string)$u['n'], implode(' ', array_keys($u['l2'])),
                  isset($p['new']['brand'][$c]) ? 'Y' : ''];
    }

    // ── ตัวสินค้าที่ต้องตั้ง CatID/CharID ──
    $blk = [];
    foreach ($rows as $mat => $r) {
        if ($r['status'] !== 'llp_unset') { continue; }
        $l = $r['llp'];
        if (!isset($blk[$l])) { $blk[$l] = ['sys' => 0, 'stock' => 0, 'out' => 0, 'cat' => [], 'char' => []]; }
        if ($r['in_sys']) {
            $blk[$l]['sys']++;
            $k = $p['known'][$mat] ?? null;
            if ($k !== null) {
                $c = icNormalizeCat($k['cat_id']);  if ($c !== null) { $blk[$l]['cat'][$c]  = ($blk[$l]['cat'][$c] ?? 0) + 1; }
                $h = icNormalizeChar($k['char_id']); if ($h !== null) { $blk[$l]['char'][$h] = ($blk[$l]['char'][$h] ?? 0) + 1; }
            }
        } else {
            $blk[$l]['out']++;
        }
        if ($r['stock'] !== null) { $blk[$l]['stock']++; }
    }
    uasort($blk, function ($a, $b) {
        return [$b['stock'], $b['sys'], $b['out']] <=> [$a['stock'], $a['sys'], $a['out']];
    });
    $fmtVote = function (array $v): string {
        arsort($v);
        $o = [];
        foreach ($v as $k => $c) { $o[] = $k . '×' . $c; }
        return implode(' ', $o);
    };
    // หัวคอลัมน์เลี่ยงคำว่า "CatID/CharID" — ไม่งั้นตัวนำเข้าของ llp_master.php จะหยิบชีตนี้ไปผิดคอลัมน์
    // (ตั้งค่าจริงให้โหลดไฟล์ "เฉพาะที่ยังไม่ตั้ง" จากหน้า llp_master.php แล้วกรอกที่นั่น)
    $bl = [['LLP', 'ชื่อตัวสินค้า', 'กลุ่ม › หมวด', 'Mango ในระบบ', 'ที่ถือยอด', 'Mango นอกระบบ',
            'หมวดอนุมัติเดิมของ Mango', 'ลักษณะวัสดุเดิมของ Mango']];
    foreach ($blk as $l => $b) {
        $L = $p['llp'][$l] ?? ['name' => '', 'l1_name' => '', 'l2_name' => ''];
        $bl[] = [(string)$l, $L['name'], trim($L['l1_name'] . ' › ' . $L['l2_name']),
                 (string)$b['sys'], (string)$b['stock'], (string)$b['out'], $fmtVote($b['cat']), $fmtVote($b['char'])];
    }

    // ── IC ที่รวมหลายรหัส Mango ──
    $mg = [['รหัส IC', 'ชื่อ IC', 'รหัส Mango', 'ชื่อในPO', 'อยู่ในทะเบียนระบบ', 'ยอดคงเหลือรวม']];
    foreach ($icList as $ic => $x) {
        if (count($x['members']) < 2) { continue; }
        foreach ($x['members'] as $mat) {
            $r = $rows[$mat];
            $mg[] = [(string)$ic, $x['name'], (string)$mat, $r['po'], $r['in_sys'] ? 'Y' : '', iciQty($r['stock'])];
        }
    }

    return xlsxWrite([
        'สรุป'            => ['header' => true, 'widths' => [62, 60], 'rows' => $sum],
        'ทะเบียน IC'      => ['header' => true, 'widths' => [23, 50, 10, 30, 22, 30, 6, 16, 6, 16, 6, 9, 9, 34, 7, 7, 7, 8, 34, 12],
                              'rows' => $reg],
        'Mango → IC'      => ['header' => true, 'widths' => [16, 50, 9, 10, 11, 30, 18, 14, 10, 34, 23, 44, 40], 'rows' => $mm],
        'ขนาดมาตรฐาน'     => ['header' => true, 'widths' => [9, 30, 9, 50, 9], 'rows' => $szs],
        'ยี่ห้อ'           => ['header' => true, 'widths' => [9, 34, 9, 50, 9], 'rows' => $brs],
        'รอตั้ง Cat-Char' => ['header' => true, 'widths' => [11, 40, 50, 11, 9, 12, 22, 22], 'rows' => $bl],
        'รวมหลาย Mango'   => ['header' => true, 'widths' => [23, 44, 16, 60, 9, 10], 'rows' => $mg],
    ]);
}
