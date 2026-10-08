<?php
/**
 * CONNEXT — lib/llp_align.php : จัดเลขตัวสินค้า (LLP) ในระบบให้ตรงไฟล์ LLP-Flowhub.xlsx (มติ 48)
 * ใช้โดย CLI db/align_flowhub.php
 *
 * ── ที่มา ─────────────────────────────────────────────────────────────────
 * เลข LLP ในระบบมาจาก "สร้าง LLP.xlsx" (มติ 42) แต่ฝ่ายจัดซื้อถือ LLP-Flowhub.xlsx เป็นเลขที่ใช้จริงแล้ว
 * และงาน "รันเลขใหม่ทั้งหมดยกเว้น Flow Hub" ยังไม่เสร็จ — สองไฟล์เลยให้รหัสเดียวกันกับสินค้าคนละตัว
 * (เลื่อนกันเป็นทอด ๆ เช่น MRO06013 แปรงลวดถ้วย ↔ แปรงสลัดน้ำ) · ผู้ใช้สั่ง 2026-09-23: ยึด LLP-Flowhub.xlsx เป็นหลัก
 *
 * ── กติกา ─────────────────────────────────────────────────────────────────
 *   · รหัส Mango ในไฟล์ → ตัวสินค้าตามไฟล์ · ชื่อ LLP = PRODUCT NAME CLEAN · 1 Mango = 1 LLP (มติ 45)
 *     ไฟล์ให้ Mango เดียวหลาย LLP → ใช้ตัวที่ชื่อตรงของเดิม ไม่งั้นรหัสน้อยสุด (ที่เหลือเป็นตัวสินค้าเปล่า) + รายงาน
 *   · รหัส Mango ที่ไม่อยู่ในไฟล์ + IC ที่ไม่มี Mango ผูก → ตามตัวสินค้าเดิมของมันไป
 *   · ตัวสินค้าในระบบที่นั่งรหัสของ Flow Hub อยู่ แต่ไม่มี Mango ในไฟล์เลย → หลบไปเลขถัดไปในหมวดเดียวกัน
 *     (เลขชั่วคราวจนกว่าฝ่ายจัดซื้อจะรันเลขส่วนที่เหลือ)
 *   · หลายตัวสินค้ารวมเป็นตัวเดียวตามไฟล์ (เช่น ลวดเชื่อม 2.6/3.2 มม. → ลวดเชื่อม) → ส่วนต่างของชื่อเดิม
 *     ลงสเปกของ IC — หน้าตาเป็นขนาดและ IC ยังไม่มีขนาด = ช่องขนาด ไม่งั้นช่องคุณสมบัติ · กัน IC คนละตัวได้รหัสเดียวกัน
 *   · Mango กับ IC ที่ผูกกันเป็นกลุ่มย้ายไปด้วยกันทั้งกลุ่ม — ไฟล์ให้คนละที่ = ไม่ย้ายกลุ่มนั้น + รายงาน
 *
 * ── ทำอย่างไร (ไม่ต้องย้ายยอด) ─────────────────────────────────────────────
 *   IC "เปลี่ยนเลขในที่" — แถว materials (id) เดิม ยอด/วัสดุโครงการ/ราคา/บรรทัดเอกสารผูกกับ id อยู่แล้วไม่ขยับ
 *   เปลี่ยนแค่ข้อความรหัสทุกที่ที่เก็บรหัสไว้: ic_items · materials · document_items · po_lines · deduction_doc_rates ·
 *   mango_ic_map · ic_suggest_map · push_allocs — ผ่านรหัสพัก (Z…) ก่อนกันชนกันเอง
 *   ทรานแซกชันเดียว + ตรวจความถูกต้องก่อน commit · activity_log 'ic_renumber' เก็บรหัสเก่า → ใหม่ต่อ IC
 *
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/ic.php';
require_once __DIR__ . '/ic_import.php';     // iciNorm · iciKey · iciCut · iciResidue · iciLooksLikeSize · iciSizeText · iciNext
require_once __DIR__ . '/setup_master.php';  // mangoNormalizeCode · smLog · smFixPrimary
require_once __DIR__ . '/../includes/xlsx_lite.php';

/** ตารางที่เก็บรหัส IC เป็นข้อความ [ตาราง, คอลัมน์, เงื่อนไขเพิ่ม] — เปลี่ยนเลขต้องตามทุกตัว */
const LAL_REFS = [
    ['ic_items', 'ic_code', ''],
    ['materials', 'mat_code', " AND t.code_type = 'ic'"],
    ['document_items', 'mat_code', ''],
    ['po_lines', 'mat_code', ''],
    ['deduction_doc_rates', 'mat_code', ''],
    ['mango_ic_map', 'ic_code', ''],
    ['ic_suggest_map', 'ic_code', ''],
    ['push_allocs', 'ic_code', ''],
    // [2026-10-02 · llp-flowhub-apply] ตารางที่เพิ่มหลัง 23 ก.ย. ที่เก็บรหัสเป็นข้อความ (ตีชำรุด/สูญหาย · ปรับยอดจากใบนับ)
    ['borrow_writeoffs', 'mat_code', ''],
    ['stock_adjustments', 'mat_code', ''],
];

/** [2026-10-02] LAL_REFS เฉพาะตารางที่มีในฐานนี้ (ฐานทดสอบเก่าอาจยังไม่มีตารางใหม่) */
function lalRefs(PDO $pdo): array {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $out = [];
    foreach (LAL_REFS as $ref) { $st->execute([$ref[0]]); if ((int)$st->fetchColumn() > 0) { $out[] = $ref; } }
    return $out;
}

/** กุญแจเทียบชื่อ — ไม่สนตัวพิมพ์/ช่องว่าง ("แปรงลวด ถ้วย" = "แปรงลวดถ้วย") */
function lalKey(string $s): string {
    return (string)preg_replace('/\s+/u', '', iciKey($s));
}

/** รหัส Mango ในไฟล์ — ตัวเลขล้วน (เลขแถวของ Flow Hub ที่ยังไม่มีรหัส Mango) / ว่าง = แถวนี้ไม่มี Mango [2026-10-02] */
function lalMangoCode(string $v): string {
    $c = mangoNormalizeCode($v);
    return preg_match('/^[A-Z][A-Z0-9]{5,}$/', $c) ? $c : '';
}

/**
 * อ่านไฟล์ Flow Hub → [['mat','llp','name','row'], ...] (แถวที่มี LLP 8 หลัก)
 *   แบบมีหัวตาราง: คอลัมน์ Material Code · LLP · PRODUCT NAME CLEAN
 *   แบบไม่มีหัวตาราง [2026-10-02]: A = รหัส Mango · C = L1 · E = หมวด L2 · I = LLP · L = ชื่อ LLP —
 *   รับเมื่อแถวส่วนใหญ่มี I เป็นรหัส LLP ที่ขึ้นต้นด้วยค่าใน E และ C (กันอ่านไฟล์อื่นผิดคอลัมน์)
 */
function lalReadFlowhub(string $path): array {
    $sheets = xlsxReadSheets($path);
    foreach ($sheets as $rows) {
        if (!$rows) { continue; }
        $m = [];
        foreach ($rows[0] as $i => $h) { $k = trim((string)$h); if ($k !== '' && !isset($m[$k])) { $m[$k] = $i; } }
        if (!isset($m['LLP'], $m['PRODUCT NAME CLEAN'])) { continue; }
        $out = [];
        foreach ($rows as $n => $r) {
            if ($n === 0) { continue; }
            $llp = strtoupper((string)preg_replace('/\s+/u', '', (string)($r[$m['LLP']] ?? '')));
            if (!preg_match('/^[A-Z0-9]{8}$/', $llp)) { continue; }
            $out[] = [
                'mat'  => isset($m['Material Code']) ? lalMangoCode((string)($r[$m['Material Code']] ?? '')) : '',
                'llp'  => $llp,
                'name' => iciNorm((string)($r[$m['PRODUCT NAME CLEAN']] ?? '')),
                'row'  => $n + 1,
            ];
        }
        return $out;
    }
    foreach ($sheets as $rows) {
        $out = []; $seen = 0;
        foreach ($rows as $n => $r) {
            if (implode('', array_map('strval', $r)) === '') { continue; }
            $seen++;
            $llp = strtoupper((string)preg_replace('/\s+/u', '', (string)($r[8] ?? '')));
            if (!preg_match('/^[A-Z]{3}[0-9]{5}$/', $llp)) { continue; }
            if (strtoupper(trim((string)($r[4] ?? ''))) !== substr($llp, 0, 5) || strtoupper(trim((string)($r[2] ?? ''))) !== substr($llp, 0, 3)) { continue; }
            $out[] = ['mat' => lalMangoCode((string)($r[0] ?? '')), 'llp' => $llp, 'name' => iciNorm((string)($r[11] ?? '')), 'row' => $n + 1];
        }
        if ($out && count($out) >= 0.8 * $seen) { return $out; }
    }
    throw new RuntimeException('ไม่พบตาราง Flow Hub ในไฟล์ — ต้องมีหัวคอลัมน์ LLP และ PRODUCT NAME CLEAN'
        . ' หรือเป็นไฟล์ไม่มีหัวตาราง (A = รหัส Mango · E = หมวด L2 · I = LLP · L = ชื่อ LLP)');
}

/** ส่วนต่างของชื่อเดิมเทียบชื่อใหม่ ("ลวดเชื่อม ขนาด 2.6 มม." vs "ลวดเชื่อม" → "ขนาด 2.6 มม.") — ชื่อไม่เกี่ยวกัน = '' */
function lalResidue(string $old, string $new): string {
    if (lalKey($old) === lalKey($new)) { return ''; }
    list($rest, $found) = iciCut(iciNorm($old), iciNorm($new));
    return $found ? iciResidue($rest) : '';
}

/**
 * ลายนิ้วมือของตารางที่แผนอ่าน — แผนคิดจากภาพหนึ่ง ถ้ามีคนแก้ระหว่างวางแผนกับเขียน (ผู้ใช้ทำงานบนฐานจริงพร้อมกัน)
 * ต้องไม่เขียนทับ · $lock = อ่านแบบล็อก (LOCK IN SHARE MODE) ในทรานแซกชันเขียน กันแก้แทรกจนกว่าจะ commit
 */
function lalFingerprint(PDO $pdo, bool $lock = false): string {
    $l = $lock ? ' LOCK IN SHARE MODE' : '';
    $q = [
        "SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS('|', ic_code, llp_code, size_code, brand_code, unit_code, extra_code))),0) FROM ic_items$l",
        "SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS('|', mat_code, llp_code, ic_code))),0) FROM mango_ic_map$l",
        "SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS('|', llp_code, llp_name, IFNULL(cat_id,''), IFNULL(char_id,'')))),0) FROM llp_products$l",
        "SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS('|', llp_code, extra_code, extra_name))),0) FROM extra_attrs$l",
        "SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS('|', size_code, size_name))),0) FROM sizes$l",
    ];
    $parts = [];
    foreach ($q as $sql) { $parts[] = implode(':', $pdo->query($sql)->fetch(PDO::FETCH_NUM)); }
    return md5(implode('/', $parts));
}

/** ตัดชื่อตัวสินค้าออกจากคุณสมบัติ เมื่อเป็นคำทั้งก้อนหัว/ท้าย ("300 มล. น้ำยา SONAX" − "น้ำยา SONAX" → "300 มล.") */
function lalStrip(string $extra, string $name): string {
    $e = iciNorm($extra); $n = iciNorm($name);
    if ($e === '' || $n === '') { return $e; }
    if (lalKey($e) === lalKey($n)) { return ''; }
    $le = mb_strtolower($e, 'UTF-8'); $ln = mb_strtolower($n, 'UTF-8');
    if (mb_strlen($le, 'UTF-8') !== mb_strlen($e, 'UTF-8') || mb_strlen($ln, 'UTF-8') !== mb_strlen($n, 'UTF-8')) { return $e; }
    $len = mb_strlen($ln, 'UTF-8');
    if (mb_substr($le, 0, $len + 1, 'UTF-8') === $ln . ' ') { return iciResidue(mb_substr($e, $len, null, 'UTF-8')); }
    if (mb_substr($le, -($len + 1), null, 'UTF-8') === ' ' . $ln) { return iciResidue(mb_substr($e, 0, -$len, 'UTF-8')); }
    return $e;
}

/**
 * วางแผน (ยังไม่เขียน DB)
 * @return array ['llp'=>[code=>final row], 'ic'=>[old=>move], 'map'=>[mat=>move], 'map_new'=>[mat=>llp],
 *                'delete'=>[codes], 'sizes'=>[code=>name], 'extras'=>[llp=>[code=>name]], 'issues'=>[...], 'stat'=>[...]]
 */
function lalPlan(PDO $pdo, string $path): array {
    $fh = lalReadFlowhub($path);
    $fp = lalFingerprint($pdo);
    $issues = [];
    $issue = function (string $type, string $mat, string $llp, string $msg) use (&$issues) {
        $issues[] = ['type' => $type, 'mat' => $mat, 'llp' => $llp, 'msg' => $msg];
    };

    // ── ไฟล์: ชื่อต่อรหัส (ชื่อที่ใช้บ่อยสุด) + รหัสของแต่ละ Mango ───────────────
    $cnt = []; $cand = [];
    foreach ($fh as $r) {
        if ($r['name'] !== '') { $cnt[$r['llp']][$r['name']] = ($cnt[$r['llp']][$r['name']] ?? 0) + 1; }
        if ($r['mat'] !== '') { $cand[$r['mat']][$r['llp']] = $r['name']; }
    }
    $fhName = [];
    foreach ($cnt as $c => $names) {
        arsort($names);
        $fhName[$c] = (string)array_key_first($names);
        if (count($names) > 1) { $issue('fh_names', '', $c, $c . ' ในไฟล์มีหลายชื่อ ' . implode(' / ', array_keys($names)) . ' — ใช้ "' . $fhName[$c] . '"'); }
    }

    // ── DB ─────────────────────────────────────────────────────────────────
    $L2 = [];
    foreach ($pdo->query('SELECT l1_code, l2_code FROM l2_categories')->fetchAll() as $r) { $L2[$r['l1_code'] . $r['l2_code']] = true; }
    $LLP = [];
    foreach ($pdo->query('SELECT llp_code, llp_name, cat_id, char_id, is_active FROM llp_products')->fetchAll() as $r) { $LLP[(string)$r['llp_code']] = $r; }
    $MAT = []; $MAT_ID = [];
    foreach ($pdo->query("SELECT id, mat_code, name FROM materials WHERE code_type = 'mango'")->fetchAll() as $r) {
        $MAT[(string)$r['mat_code']] = (string)$r['name']; $MAT_ID[(string)$r['mat_code']] = (int)$r['id'];
    }
    $MAP = []; $MANGO_LLPS = [];
    foreach ($pdo->query('SELECT mat_code, llp_code, ic_code FROM mango_ic_map ORDER BY id')->fetchAll() as $r) {
        $MAP[(string)$r['mat_code']][] = ['llp' => (string)$r['llp_code'], 'ic' => (string)$r['ic_code']];
        $MANGO_LLPS[(string)$r['mat_code']][(string)$r['llp_code']] = true;
    }
    $IC = [];
    foreach ($pdo->query("SELECT i.ic_code, i.llp_code, i.size_code, i.brand_code, i.unit_code, i.extra_code, i.ic_name, i.is_active,
                                 m.id AS material_id,
                                 (SELECT COALESCE(SUM(b.on_hand),0) FROM stock_balances b WHERE b.material_id = m.id) AS on_hand
                            FROM ic_items i LEFT JOIN materials m ON m.mat_code = i.ic_code AND m.code_type = 'ic'")->fetchAll() as $r) {
        $IC[(string)$r['ic_code']] = $r;
    }
    $EX = [];
    foreach ($pdo->query('SELECT llp_code, extra_code, extra_name FROM extra_attrs')->fetchAll() as $r) {
        $EX[(string)$r['llp_code']][(string)$r['extra_code']] = (string)$r['extra_name'];
    }
    $BR = [];
    foreach ($pdo->query("SELECT brand_code, brand_name FROM brands WHERE is_active = 1 AND brand_code <> '000'")->fetchAll() as $r) {
        $BR[iciKey((string)$r['brand_name'])] = (string)$r['brand_code'];
    }
    $SZ = []; $szMax = 0;
    foreach ($pdo->query('SELECT size_code, size_name FROM sizes ORDER BY size_code')->fetchAll() as $r) {
        $k = iciKey((string)$r['size_name']);
        if (!isset($SZ[$k])) { $SZ[$k] = (string)$r['size_code']; }
        if (ctype_digit((string)$r['size_code'])) { $szMax = max($szMax, (int)$r['size_code']); }
    }

    // ── เป้าหมายของ Mango ตามไฟล์ ────────────────────────────────────────────
    $target = []; $fhMissing = [];
    foreach ($cand as $mat => $codes) {
        foreach (array_keys($codes) as $c) {
            if (!isset($L2[substr($c, 0, 5)])) {
                $issue('no_l2', $mat, $c, 'หมวด ' . substr($c, 0, 5) . ' ยังไม่มีในระบบ — ไม่ย้าย ' . $mat . ' (' . ($MAT[$mat] ?? '?') . ') ไป ' . $c);
                unset($codes[$c]);
            }
        }
        if (!isset($MAT[$mat])) { $fhMissing[$mat] = true; continue; }
        if (!$codes) { continue; }
        if (count($codes) === 1) { $target[$mat] = (string)array_key_first($codes); continue; }
        $cur = isset($MANGO_LLPS[$mat]) && count($MANGO_LLPS[$mat]) === 1 ? (string)array_key_first($MANGO_LLPS[$mat]) : '';
        $pick = '';
        foreach ($codes as $c => $nm) {
            if (lalKey($nm) === lalKey($MAT[$mat]) || ($cur !== '' && lalKey($nm) === lalKey((string)($LLP[$cur]['llp_name'] ?? '')))) { $pick = $c; break; }
        }
        if ($pick === '') { $k = array_keys($codes); sort($k); $pick = $k[0]; }
        $target[$mat] = $pick;
        $issue('fh_multi', $mat, $pick, 'ไฟล์ให้ ' . $mat . ' (' . $MAT[$mat] . ') ' . count($codes) . ' ตัวสินค้า ' . implode(' / ', array_keys($codes))
            . ' — ผูกกับ ' . $pick . ' ตัวเดียว (1 Mango = 1 LLP)');
    }

    // ── Mango + IC ที่ผูกกัน = กลุ่มเดียว ย้ายไปด้วยกัน (union-find) ──────────────
    $par = [];
    $find = function (string $a) use (&$par, &$find): string {
        if (!isset($par[$a])) { $par[$a] = $a; }
        if ($par[$a] !== $a) { $par[$a] = $find($par[$a]); }
        return $par[$a];
    };
    foreach ($MANGO_LLPS as $mat => $ls) { $find('m:' . $mat); }
    foreach ($IC as $code => $r) { $find('i:' . $code); }
    foreach ($MAP as $mat => $rows) {
        foreach ($rows as $x) {
            if ($x['ic'] === '' || !isset($IC[$x['ic']])) { continue; }
            $a = $find('m:' . $mat); $b = $find('i:' . $x['ic']);
            if ($a !== $b) { $par[$a] = $b; }
        }
    }
    $groups = [];
    foreach (array_keys($par) as $node) { $groups[$find((string)$node)][] = (string)$node; }
    // ปลายทางตามไฟล์ของแต่ละกลุ่ม: Mango ในไฟล์ของกลุ่มชี้ที่เดียว = ทั้งกลุ่มไปที่นั่น (Mango นอกไฟล์ที่ใช้ IC ร่วมตามไป)
    $frozen = []; $gTarget = []; $gOf = [];
    foreach ($groups as $g => $nodes) {
        $ds = []; $mats = []; $ics = [];
        foreach ($nodes as $nd) {
            $gOf[$nd] = $g;
            if ($nd[0] === 'm') { $m = substr($nd, 2); $mats[] = $m; if (isset($target[$m])) { $ds[$target[$m]] = true; } }
            else { $ics[] = substr($nd, 2); }
        }
        $multi = false;
        foreach ($mats as $m) { if (count($MANGO_LLPS[$m]) !== 1) { $multi = true; } }
        if ($multi) {
            foreach ($mats as $m) { $frozen[$m] = true; }
            $issue('multi_llp', implode(',', $mats), '', 'Mango ' . implode(', ', $mats) . ' ผูกหลายตัวสินค้าอยู่แล้ว (ผิดมติ 45) — ไม่แตะกลุ่มนี้ แก้ที่หน้าจัดการรหัสก่อน');
            $gTarget[$g] = null;
        } elseif (count($ds) > 1) {
            foreach ($mats as $m) { $frozen[$m] = true; }
            $issue('split', implode(',', $mats), implode(',', array_keys($ds)),
                'Mango ' . implode(', ', $mats) . ' ใช้ IC ร่วมกัน (' . implode(', ', $ics) . ') แต่ไฟล์ให้ไปคนละตัวสินค้า '
                . implode(' / ', array_keys($ds)) . ' — ไม่ย้ายกลุ่มนี้');
            $gTarget[$g] = null;
        } else {
            $gTarget[$g] = $ds ? (string)array_key_first($ds) : '';     // '' = ไม่มี Mango ในไฟล์ → ตามตัวสินค้าเดิม
        }
    }

    // ── ตัวสินค้าในระบบ: "ตัวมันเอง" (ชื่อ) ไปรหัสไหนตามไฟล์ ─────────────────────
    //   1) ไฟล์ให้ Mango ของมันอยู่รหัสเดิม หรือชื่อในไฟล์ของรหัสเดิมตรงกัน → อยู่ที่เดิม
    //   2) ปลายทางของ Mango ในไฟล์ที่ชื่อเป็นสินค้าเดียวกัน/กว้างกว่า/แคบกว่า (ลวดเชื่อม 2.6 มม. → ลวดเชื่อม)
    //   3) ชื่อตรงกับรหัสใดในไฟล์เป๊ะ (ชื่อไม่ซ้ำในไฟล์) → รวมเข้ารหัสนั้น
    //   ไม่เข้าข้อไหน = Mango ในไฟล์ที่ถูกผูกผิดตัวในระบบย้ายออกไปเอง ตัวสินค้าอยู่ที่เดิม
    $fhByName = [];
    foreach ($fhName as $c => $nm) { $k = lalKey($nm); $fhByName[$k] = isset($fhByName[$k]) ? '' : (string)$c; }
    $related = function (string $a, string $b): bool {
        $a = lalKey($a); $b = lalKey($b);
        return $a !== '' && $b !== '' && ($a === $b || mb_strpos($a, $b) === 0 || mb_strpos($b, $a) === 0);
    };
    $matsOf = [];
    foreach ($MANGO_LLPS as $mat => $ls) { foreach (array_keys($ls) as $X) { $matsOf[(string)$X][(string)$mat] = true; } }
    $icsOf = [];
    foreach ($IC as $code => $r) { $icsOf[(string)$r['llp_code']][] = (string)$code; }
    $prodMain = [];
    foreach (array_keys($LLP) as $X) {
        $X = (string)$X; $nm = (string)$LLP[$X]['llp_name'];
        $fx = [];
        foreach (array_keys($matsOf[$X] ?? []) as $m) { if (isset($target[$m])) { $fx[$target[$m]] = ($fx[$target[$m]] ?? 0) + 1; } }
        if (isset($fx[$X]) || (isset($fhName[$X]) && lalKey($fhName[$X]) === lalKey($nm))) { $prodMain[$X] = $X; continue; }
        $best = ''; $score = -1;
        foreach ($fx as $F => $c) {
            $F = (string)$F;
            if (!$related($nm, $fhName[$F] ?? '')) { continue; }
            $s = (lalKey($fhName[$F]) === lalKey($nm) ? 100000 : 0) + $c;
            if ($s > $score) { $best = $F; $score = $s; }
        }
        if ($best === '' && ($fhByName[lalKey($nm)] ?? '') !== '') {
            $best = $fhByName[lalKey($nm)];
            $issue('name_merge', '', $best, $X . ' ' . $nm . ' ชื่อตรงกับ ' . $best . ' ในไฟล์ — รวมเข้ารหัสนั้น');
        }
        if ($best !== '') { $prodMain[$X] = $best; }
    }
    // ของที่ตาม "ตัวมันเอง" ไป = Mango/IC ในกลุ่มที่ไม่มี Mango ในไฟล์
    $residual = [];
    foreach ($matsOf as $X => $ms) { foreach (array_keys($ms) as $m) { if (($gTarget[$gOf['m:' . $m]] ?? null) === '') { $residual[$X] = true; } } }
    foreach ($icsOf as $X => $cs) { foreach ($cs as $i) { if (($gTarget[$gOf['i:' . $i]] ?? null) === '') { $residual[$X] = true; } } }
    // ตัวที่นั่งรหัสของ Flow Hub (สินค้าคนละตัว) และยังมีของตามอยู่ → เลขถัดไปในหมวด (กันทั้งเลขในระบบและในไฟล์)
    $maxIn = [];
    foreach (array_merge(array_keys($LLP), array_keys($fhName)) as $c) {
        $c = (string)$c; $l = substr($c, 0, 5); $maxIn[$l] = max($maxIn[$l] ?? 0, (int)substr($c, 5, 3));
    }
    $displaced = [];
    $dk = array_keys($LLP); sort($dk, SORT_STRING);
    foreach ($dk as $X) {
        $X = (string)$X;
        if (isset($prodMain[$X]) || !isset($fhName[$X]) || empty($residual[$X])) { continue; }
        $l = substr($X, 0, 5);
        if (($maxIn[$l] ?? 0) >= 999) { throw new RuntimeException('หมวด ' . $l . ' ไม่มีเลขตัวสินค้าว่างเหลือ'); }
        $maxIn[$l] = ($maxIn[$l] ?? 0) + 1;
        $displaced[$X] = $l . str_pad((string)$maxIn[$l], 3, '0', STR_PAD_LEFT);
    }
    $destOf = function (string $X) use ($prodMain, $displaced): string {
        return $prodMain[$X] ?? ($displaced[$X] ?? $X);
    };

    // ── ปลายทางของ Mango/IC ─────────────────────────────────────────────────
    $mDest = [];
    foreach ($MANGO_LLPS as $mat => $ls) {
        $mat = (string)$mat;
        if (isset($frozen[$mat])) { continue; }
        $X = (string)array_key_first($ls);
        $t = $gTarget[$gOf['m:' . $mat]];
        $mDest[$mat] = ['from' => $X, 'to' => $t !== '' ? $t : $destOf($X)];
    }
    $icDest = [];
    foreach ($IC as $code => $r) {
        $code = (string)$code; $X = (string)$r['llp_code'];
        $t = $gTarget[$gOf['i:' . $code]];
        if ($t === null) { $icDest[$code] = $X; continue; }                // กลุ่มที่ไม่ย้าย
        if ($t !== '') { $icDest[$code] = $t; continue; }
        // กลุ่มไม่มี Mango ในไฟล์: Mango (ถ้ามี) ตามตัวสินค้าของมัน → IC ตาม Mango · ไม่มี Mango = ตามตัวสินค้าของ IC
        $d = '';
        foreach ($groups[$gOf['i:' . $code]] as $nd) { if ($nd[0] === 'm' && isset($mDest[substr($nd, 2)])) { $d = $mDest[substr($nd, 2)]['to']; break; } }
        $icDest[$code] = $d !== '' ? $d : $destOf($X);
    }

    // ── ชื่อสุดท้ายของรหัสปลายทาง ────────────────────────────────────────────
    $dispSrc = array_flip($displaced);
    $finalName = function (string $F) use ($fhName, $dispSrc, $LLP): string {
        if (isset($fhName[$F])) { return $fhName[$F]; }
        if (isset($dispSrc[$F])) { return (string)$LLP[$dispSrc[$F]]['llp_name']; }
        return isset($LLP[$F]) ? (string)$LLP[$F]['llp_name'] : $F;
    };

    // ── IC: แผนเปลี่ยนเลข ────────────────────────────────────────────────────
    $moves = []; $keep = []; $stay = [];
    foreach ($IC as $code => $r) {
        $code = (string)$code;
        $X = (string)$r['llp_code']; $F = $icDest[$code];
        $res = lalResidue((string)($LLP[$X]['llp_name'] ?? ''), $finalName($F));
        if ($F === $X && $res === '') {
            $stay[$code] = true;
            if ((string)$r['extra_code'] !== IC_NONE) { $keep[$X][(string)$r['extra_code']] = (string)($EX[$X][(string)$r['extra_code']] ?? ''); }
            continue;
        }
        $size = (string)$r['size_code']; $brand = (string)$r['brand_code'];
        $extraTxt = (string)$r['extra_code'] !== IC_NONE ? (string)($EX[$X][(string)$r['extra_code']] ?? '') : '';
        // IC ที่เคยถูกผูกผิดตัวสินค้าใส่ชื่อตัวเองไว้ในคุณสมบัติกันชน — ถึงบ้านที่ถูกแล้วตัดชื่อบ้านออก
        $extraTxt = lalStrip($extraTxt, $finalName($F));
        $sizeTxt = '';
        if ($res !== '') {
            $rs = iciSizeText($res);
            if ($brand === IC_NONE && isset($BR[iciKey($res)])) { $brand = $BR[iciKey($res)]; }       // "สีน้ำมัน TOA" → ยี่ห้อ TOA
            elseif ($size === IC_NONE && $rs !== '' && iciLooksLikeSize($rs) && mb_strlen($rs, 'UTF-8') <= 100) { $sizeTxt = $rs; }
            else { $extraTxt = iciNorm($res . ' ' . $extraTxt); }
        }
        $moves[$code] = [
            'old' => $code, 'from' => $X, 'llp' => $F, 'size' => $size, 'size_txt' => $sizeTxt,
            'brand' => $brand, 'unit' => (string)$r['unit_code'],
            'old_extra' => (string)$r['extra_code'], 'extra_txt' => mb_substr($extraTxt, 0, 150, 'UTF-8'), 'extra' => IC_NONE,
            'name' => (string)$r['ic_name'], 'material_id' => (int)$r['material_id'], 'on_hand' => (float)$r['on_hand'],
            'active' => (int)$r['is_active'], 'residue' => $res, 'new' => '',
        ];
        if (mb_strlen($extraTxt, 'UTF-8') > 150) { $issue('extra_cut', '', $F, 'IC ' . $code . ' คุณสมบัติยาวเกิน 150 ตัวอักษร — ตัดท้าย'); }
    }
    ksort($moves, SORT_STRING);

    // ขนาดใหม่ในพจนานุกรมกลาง
    $newSizes = [];
    foreach ($moves as &$mv) {
        if ($mv['size_txt'] === '') { continue; }
        $k = iciKey($mv['size_txt']);
        if (!isset($SZ[$k])) {
            $c = iciNext($szMax);
            if ($c === '') { throw new RuntimeException('พจนานุกรมขนาดเต็ม 999'); }
            $SZ[$k] = $c; $newSizes[$c] = $mv['size_txt'];
        }
        $mv['size'] = $SZ[$k];
    }
    unset($mv);

    // คุณสมบัติต่อรหัสปลายทาง: ตัวสินค้าเดิม = คงทุกตัว · เปลี่ยนตัวสินค้า = คงเฉพาะที่ IC ที่อยู่ต่อใช้
    $sameProduct = function (string $F) use ($LLP, $finalName): bool {
        return isset($LLP[$F]) && lalKey((string)$LLP[$F]['llp_name']) === lalKey($finalName($F));
    };
    $extras = [];
    $touchEx = [];
    foreach ($moves as $mv) { $touchEx[$mv['llp']] = true; if (!$sameProduct($mv['from'])) { $touchEx[$mv['from']] = true; } }
    foreach (array_keys($touchEx) as $F) {
        $F = (string)$F;
        $extras[$F] = $sameProduct($F) ? ($EX[$F] ?? []) : ($keep[$F] ?? []);
    }
    foreach ($moves as &$mv) {
        if ($mv['extra_txt'] === '') { continue; }
        $F = $mv['llp'];
        $hit = '';
        foreach ($extras[$F] as $c => $nm) { if (iciKey((string)$nm) === iciKey($mv['extra_txt'])) { $hit = (string)$c; break; } }
        if ($hit === '') {
            $hit = $mv['old_extra'];
            if ($hit === IC_NONE || isset($extras[$F][$hit])) {
                $max = 0;
                foreach (array_keys($extras[$F]) as $x) { $max = max($max, (int)$x); }
                $hit = iciNext($max);
                if ($hit === '') { throw new RuntimeException('คุณสมบัติของ ' . $F . ' เต็ม 999'); }
            }
            $extras[$F][$hit] = $mv['extra_txt'];
        }
        $mv['extra'] = $hit;
    }
    unset($mv);

    // รหัสใหม่ + ตรวจชนกัน (กับตัวที่ย้ายด้วยกัน และกับ IC ที่อยู่ที่เดิม)
    $seen = []; $collide = [];
    foreach ($moves as $code => &$mv) {
        $mv['new'] = icCompose($mv['llp'], $mv['size'], $mv['brand'], $mv['unit'], $mv['extra']);
        if (isset($stay[$mv['new']]) || isset($seen[$mv['new']])) {
            $other = $seen[$mv['new']] ?? $mv['new'];
            $collide[$code] = $other;
            $issue('collision', '', $mv['llp'], 'IC ' . $code . ' (' . $mv['name'] . ') กับ ' . $other . ' จะได้รหัสเดียวกัน ' . $mv['new']
                . ' — ของซ้ำกันจริง ต้องรวมยอดที่หน้าแก้ IC ก่อน');
        }
        $seen[$mv['new']] = $code;
    }
    unset($mv);
    foreach ($moves as $code => $mv) { if ($mv['new'] === $code) { unset($moves[$code]); } }

    // ── Mango ที่ไฟล์มีแต่ยังไม่ผูกในระบบ → ผูกขั้น 1 ─────────────────────────────
    $mapNew = [];
    foreach ($target as $m => $F) { if (!isset($MANGO_LLPS[$m])) { $mapNew[$m] = $F; } }

    // ── แถวตัวสินค้าปลายทาง ──────────────────────────────────────────────────
    $mapMoves = [];
    foreach ($mDest as $m => $d) { if ($d['to'] !== $d['from']) { $mapMoves[$m] = $d; } }
    $touch = [];
    foreach ($moves as $mv) { $touch[$mv['llp']] = true; $touch[$mv['from']] = true; }
    foreach ($mapMoves as $d) { $touch[$d['to']] = true; $touch[$d['from']] = true; }
    foreach ($mapNew as $F) { $touch[$F] = true; }
    foreach (array_keys($fhName) as $F) { if (isset($L2[substr((string)$F, 0, 5)])) { $touch[(string)$F] = true; } }

    // Cat/Char: ตัวสินค้าต้นทางของ IC/Mango ที่ลงรหัสนี้ (เสียงข้างมาก)
    $votes = [];
    $vote = function (string $F, string $src, int $w) use (&$votes, $LLP) {
        $c = icNormalizeCat($LLP[$src]['cat_id'] ?? null); $h = icNormalizeChar($LLP[$src]['char_id'] ?? null);
        if ($c !== null && $h !== null) { $votes[$F][$c . '/' . $h] = ($votes[$F][$c . '/' . $h] ?? 0) + $w; }
    };
    foreach ($IC as $code => $r) { $vote($icDest[$code], (string)$r['llp_code'], 1); }
    foreach ($mDest as $d) { $vote($d['to'], $d['from'], 1); }
    $finalLlp = [];
    foreach (array_keys($touch) as $F) {
        $F = (string)$F;
        $name = mb_substr($finalName($F), 0, 255, 'UTF-8');
        $cat = null; $char = null;
        if (!empty($votes[$F])) {
            $v = $votes[$F]; arsort($v);
            list($cat, $char) = explode('/', (string)array_key_first($v));
            if (count($v) > 1) { $issue('charcat', '', $F, $F . ' ' . $name . ': ต้นทางตั้ง Cat/Char ต่างกัน ' . implode(' · ', array_keys($v)) . ' — ใช้ ' . $cat . '/' . $char); }
        } elseif ($sameProduct($F)) {
            $cat = icNormalizeCat($LLP[$F]['cat_id']); $char = icNormalizeChar($LLP[$F]['char_id']);
        }
        $old = $LLP[$F] ?? null;
        $chg = $old === null ? 'new'
             : ((string)$old['llp_name'] !== $name ? 'rename'
             : ((icNormalizeCat($old['cat_id']) !== $cat || icNormalizeChar($old['char_id']) !== $char) ? 'charcat' : ''));
        $finalLlp[$F] = [
            'code' => $F, 'l1' => substr($F, 0, 3), 'l2' => substr($F, 3, 2), 'p' => substr($F, 5, 3),
            'name' => $name, 'cat' => $cat, 'char' => $char, 'change' => $chg,
            'old_name' => $old !== null ? (string)$old['llp_name'] : '', 'same' => $sameProduct($F),
            'fh' => isset($fhName[$F]), 'displaced_from' => $dispSrc[$F] ?? '',
        ];
    }

    // ตัวสินค้าที่ว่างหลังย้าย (ไม่ใช่รหัสในไฟล์) → ลบ
    $inUse = [];
    foreach ($IC as $code => $r) { $inUse[isset($moves[$code]) ? $moves[$code]['llp'] : (string)$r['llp_code']] = true; }
    foreach ($MANGO_LLPS as $m => $ls) {
        if (isset($mDest[$m])) { $inUse[$mDest[$m]['to']] = true; } else { foreach (array_keys($ls) as $X) { $inUse[(string)$X] = true; } }
    }
    foreach ($mapNew as $F) { $inUse[$F] = true; }
    $delete = [];
    foreach (array_keys($touch) as $X) {
        $X = (string)$X;
        if (isset($LLP[$X]) && !isset($fhName[$X]) && !isset($inUse[$X])) { $delete[] = $X; unset($finalLlp[$X]); }
    }
    sort($delete);

    // ── สรุป ────────────────────────────────────────────────────────────────
    $ok = 0; $inSys = 0;
    foreach ($target as $m => $F) {
        $inSys++;
        $final = isset($mDest[$m]) ? $mDest[$m]['to'] : ($mapNew[$m] ?? '');
        if ($final === $F) { $ok++; }
    }
    $stock = 0.0; foreach ($moves as $mv) { $stock += $mv['on_hand']; }
    $c = ['new' => 0, 'rename' => 0, 'charcat' => 0, '' => 0];
    foreach ($finalLlp as $f) { $c[$f['change']]++; }

    return [
        'fp' => $fp, 'fh' => $fhName, 'target' => $target, 'displaced' => $displaced,
        'llp' => $finalLlp, 'ic' => $moves, 'map' => $mapMoves, 'map_new' => $mapNew, 'delete' => $delete,
        'sizes' => $newSizes, 'extras' => $extras, 'issues' => $issues, 'collide' => $collide, 'frozen' => array_keys($frozen),
        'db_llp' => $LLP, 'mat_names' => $MAT, 'mat_ids' => $MAT_ID, 'fh_missing' => array_keys($fhMissing),
        'stat' => [
            'file' => $path, 'fh_rows' => count($fh), 'fh_codes' => count($fhName), 'fh_mango' => count($cand),
            'fh_no_mango' => count(array_filter($fh, function ($r) { return $r['mat'] === ''; })),   // [2026-10-02]
            'fh_mango_missing' => count($fhMissing), 'fh_mango_target' => $inSys, 'fh_mango_aligned' => $ok,
            'ic_moves' => count($moves), 'ic_stock' => $stock, 'mango_moves' => count($mapMoves), 'mango_new' => count($mapNew),
            'llp_new' => $c['new'], 'llp_rename' => $c['rename'], 'llp_charcat' => $c['charcat'], 'llp_delete' => count($delete),
            'displaced' => count($displaced), 'sizes_new' => count($newSizes), 'collisions' => count($collide), 'issues' => count($issues),
        ],
    ];
}

/**
 * เขียนแผนลง DB — ทรานแซกชันเดียว · ตรวจความถูกต้องก่อน commit (พังข้อไหน rollback ทั้งหมด)
 * @return array จำนวนที่เปลี่ยนจริง
 */
function lalApply(PDO $pdo, array $p, ?array $user = null): array {
    if (!empty($p['collide'])) { throw new RuntimeException('มี IC ที่จะได้รหัสซ้ำกัน ' . count($p['collide']) . ' ตัว — รวมยอดก่อน แล้ววางแผนใหม่'); }
    $n = ['ic' => 0, 'refs' => 0, 'map' => 0, 'map_new' => 0, 'llp_new' => 0, 'llp_upd' => 0, 'llp_del' => 0, 'sizes' => 0, 'extras' => 0];
    $uid = $user !== null ? ((int)($user['accountId'] ?? 0) ?: null) : null;
    $moves = $p['ic'];

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) { $pdo->beginTransaction(); }
    try {
        // ล็อกตารางที่แผนอ่าน แล้วเทียบกับตอนวางแผน — มีคนแก้แทรก = ไม่เขียน ให้วางแผนใหม่
        if (lalFingerprint($pdo, true) !== $p['fp']) {
            throw new RuntimeException('มีการแก้รหัส/การผูกระหว่างวางแผนกับเขียน — ไม่ได้เขียนอะไร รันใหม่อีกครั้ง');
        }

        // ตารางพักแผนเปลี่ยนเลข (TEMPORARY ไม่ commit ทรานแซกชันให้)
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS lal_ic');
        $pdo->exec('CREATE TEMPORARY TABLE lal_ic (
                        old_code CHAR(20) NOT NULL PRIMARY KEY, tmp_code CHAR(20) NOT NULL UNIQUE, new_code CHAR(20) NOT NULL UNIQUE,
                        llp_code CHAR(8) NOT NULL, l1_code CHAR(3) NOT NULL, l2_code CHAR(2) NOT NULL,
                        size_code CHAR(3) NOT NULL, brand_code CHAR(3) NOT NULL, extra_code CHAR(3) NOT NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $i = 0; $vals = [];
        $flush = function () use ($pdo, &$vals) {
            if (!$vals) { return; }
            $pdo->prepare('INSERT INTO lal_ic VALUES ' . implode(',', array_fill(0, count($vals) / 9, '(?,?,?,?,?,?,?,?,?)')))->execute($vals);
            $vals = [];
        };
        foreach ($moves as $old => $mv) {
            array_push($vals, (string)$old, 'Z' . str_pad((string)(++$i), 19, '0', STR_PAD_LEFT), $mv['new'], $mv['llp'],
                substr($mv['llp'], 0, 3), substr($mv['llp'], 3, 2), $mv['size'], $mv['brand'], $mv['extra']);
            if (count($vals) >= 900) { $flush(); }
        }
        $flush();

        // 1) รหัสเดิม → รหัสพัก ทุกตาราง (ที่มีในฐานนี้ — 2026-10-02)
        $refs = lalRefs($pdo);
        foreach ($refs as $ref) {
            list($t, $col, $cond) = $ref;
            $n['refs'] += $pdo->exec("UPDATE $t t JOIN lal_ic m ON t.$col = m.old_code SET t.$col = m.tmp_code WHERE 1=1$cond");
        }

        // 2) ตัวสินค้าปลายทาง — สร้าง/เปลี่ยนชื่อ/ตั้ง Cat-Char (เปลี่ยนตัวสินค้าในรหัสเดิม = เปิดใช้)
        $ins = $pdo->prepare('INSERT INTO llp_products (llp_code, l1_code, l2_code, product_code, llp_name, cat_id, char_id, charcat_by, charcat_at, created_by)
                              VALUES (?,?,?,?,?,?,?,?,IF(? IS NULL, NULL, NOW()),?)');
        $upd = $pdo->prepare('UPDATE llp_products
                                 SET charcat_at = IF(cat_id <=> ? AND char_id <=> ?, charcat_at, IF(? IS NULL, NULL, NOW())),
                                     charcat_by = IF(cat_id <=> ? AND char_id <=> ?, charcat_by, ?),
                                     llp_name = ?, cat_id = ?, char_id = ?, is_active = IF(?, 1, is_active)
                               WHERE llp_code = ?');
        foreach ($p['llp'] as $F => $f) {
            if ($f['change'] === 'new') {
                $ins->execute([$F, $f['l1'], $f['l2'], $f['p'], $f['name'], $f['cat'], $f['char'], $uid, $f['cat'], $uid]);
                $n['llp_new']++;
            } elseif ($f['change'] !== '') {
                $upd->execute([$f['cat'], $f['char'], $f['cat'], $f['cat'], $f['char'], $uid,
                               $f['name'], $f['cat'], $f['char'], $f['change'] === 'rename' ? 1 : 0, $F]);
                $n['llp_upd'] += $upd->rowCount();
            }
        }

        // 3) ขนาดใหม่ + ผูกขนาด/ยี่ห้อกับหมวดปลายทาง
        $ins = $pdo->prepare('INSERT INTO sizes (size_code, size_name) VALUES (?,?)');
        foreach ($p['sizes'] as $c => $nm) { $ins->execute([(string)$c, $nm]); $n['sizes']++; }
        $pdo->exec('INSERT IGNORE INTO l2_sizes (l1_code, l2_code, size_code) SELECT DISTINCT l1_code, l2_code, size_code FROM lal_ic');
        $pdo->exec("INSERT IGNORE INTO l2_sizes (l1_code, l2_code, size_code) SELECT DISTINCT l1_code, l2_code, '000' FROM lal_ic");
        $pdo->exec('INSERT IGNORE INTO l2_brands (l1_code, l2_code, brand_code) SELECT DISTINCT l1_code, l2_code, brand_code FROM lal_ic');
        $pdo->exec("INSERT IGNORE INTO l2_brands (l1_code, l2_code, brand_code) SELECT DISTINCT l1_code, l2_code, '000' FROM lal_ic");

        // 4) ตารางคุณสมบัติของรหัสที่แตะ = ชุดใหม่ทั้งชุด
        $del = $pdo->prepare('DELETE FROM extra_attrs WHERE llp_code = ?');
        $ins = $pdo->prepare('INSERT INTO extra_attrs (llp_code, extra_code, extra_name) VALUES (?,?,?)');
        foreach ($p['extras'] as $F => $rows) {
            if (in_array($F, $p['delete'], true)) { continue; }
            $del->execute([$F]);
            foreach ($rows as $c => $nm) { $ins->execute([$F, (string)$c, mb_substr((string)$nm, 0, 150, 'UTF-8')]); $n['extras']++; }
        }

        // 5) รหัสพัก → รหัสใหม่ (ic_items ได้ชิ้นรหัสใหม่ · materials ได้ path หมวดใหม่ · mango_ic_map ได้ LLP ใหม่)
        $n['ic'] = $pdo->exec('UPDATE ic_items t JOIN lal_ic m ON t.ic_code = m.tmp_code
                                  SET t.ic_code = m.new_code, t.llp_code = m.llp_code, t.l1_code = m.l1_code, t.l2_code = m.l2_code,
                                      t.size_code = m.size_code, t.brand_code = m.brand_code, t.extra_code = m.extra_code');
        $pdo->exec("UPDATE materials t JOIN lal_ic m ON t.mat_code = m.tmp_code
                      LEFT JOIN l1_groups g ON g.l1_code = m.l1_code
                      LEFT JOIN l2_categories c ON c.l1_code = m.l1_code AND c.l2_code = m.l2_code
                       SET t.mat_code = m.new_code,
                           t.subgroup_name = COALESCE(LEFT(CONCAT(g.l1_name, ' › ', c.l2_name), 150), t.subgroup_name)
                     WHERE t.code_type = 'ic'");
        $pdo->exec('UPDATE mango_ic_map t JOIN lal_ic m ON t.ic_code = m.tmp_code SET t.ic_code = m.new_code, t.llp_code = m.llp_code');
        foreach ($refs as $ref) {
            list($t, $col, $cond) = $ref;
            if (in_array($t, ['ic_items', 'materials', 'mango_ic_map'], true)) { continue; }
            $pdo->exec("UPDATE $t t JOIN lal_ic m ON t.$col = m.tmp_code SET t.$col = m.new_code");
        }

        // 6) การผูก Mango → ตัวสินค้าปลายทาง (ทุกแถวของ Mango นั้น รวมแถวขั้น 1) + ผูกขั้น 1 ให้ Mango ในไฟล์ที่ยังไม่ผูก
        $mm = $pdo->prepare('UPDATE mango_ic_map SET llp_code = ? WHERE mat_code = ?');
        foreach ($p['map'] as $m => $d) { $mm->execute([$d['to'], (string)$m]); $n['map']++; }
        $mi = $pdo->prepare("INSERT IGNORE INTO mango_ic_map (mat_code, llp_code, ic_code, is_primary, mapped_by) VALUES (?,?,'',1,?)");
        foreach ($p['map_new'] as $m => $F) { $mi->execute([(string)$m, $F, $uid]); $n['map_new'] += $mi->rowCount(); }

        // 7) Cat/Char ของ LLP เป็นต้นทางเดียว (มติ 29) — เขียนตามลง IC + materials ของรหัสที่แตะ
        $dest = [];
        foreach ($moves as $mv) { $dest[$mv['llp']] = true; }
        foreach ($p['llp'] as $F => $f) {
            if ($f['cat'] !== null && $f['char'] !== null && ($f['change'] !== '' || isset($dest[$F]))) { icCascadeLlp($pdo, (string)$F, $f['cat'], $f['char']); }
        }

        // 8) ตัวสินค้าที่ว่างแล้วและไม่ใช่รหัสในไฟล์ → ลบ (คุณสมบัติลบตามด้วย FK)
        $chk = $pdo->prepare('SELECT (SELECT COUNT(*) FROM ic_items WHERE llp_code = ?) + (SELECT COUNT(*) FROM mango_ic_map WHERE llp_code = ?)');
        $dl  = $pdo->prepare('DELETE FROM llp_products WHERE llp_code = ?');
        foreach ($p['delete'] as $X) {
            $chk->execute([$X, $X]);
            if ((int)$chk->fetchColumn() > 0) { throw new RuntimeException('ลบ ' . $X . ' ไม่ได้ — ยังมี IC/การผูกอ้างถึง'); }
            $dl->execute([$X]);
            $n['llp_del'] += $dl->rowCount();
        }

        // 9) ตรวจก่อน commit
        $bad = lalVerify($pdo, $p);
        if ($bad) { throw new RuntimeException('ตรวจไม่ผ่าน: ' . implode(' | ', array_slice($bad, 0, 8))); }

        // log — สรุป 1 แถว · ต่อ IC (รหัสเก่า → ใหม่ บน material id เดิม) · ต่อ Mango ที่ย้ายตัวสินค้า
        smLog($pdo, $user, 'llp_align', 'llp_align', null, ['file' => basename((string)$p['stat']['file']), 'n' => $n, 'delete' => $p['delete']]);
        foreach ($moves as $old => $mv) {
            smLog($pdo, $user, $mv['material_id'] ?: (string)$old, 'ic_renumber', ['ic_code' => (string)$old, 'llp' => $mv['from']],
                  ['ic_code' => $mv['new'], 'llp' => $mv['llp']]);
        }
        foreach ($p['map'] as $m => $d) {
            smLog($pdo, $user, $p['mat_ids'][$m] ?? (string)$m, 'llp_change', ['mat_code' => (string)$m, 'llp_code' => $d['from']],
                  ['mat_code' => (string)$m, 'llp_code' => $d['to'], 'llp_name' => (string)($p['llp'][$d['to']]['name'] ?? ''), 'by' => 'llp_align']);
        }
        foreach ($p['map_new'] as $m => $F) {
            smLog($pdo, $user, $p['mat_ids'][$m] ?? (string)$m, 'llp_map', null,
                  ['mat_code' => (string)$m, 'llp_code' => $F, 'llp_name' => (string)($p['llp'][$F]['name'] ?? ''), 'by' => 'llp_align']);
        }
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS lal_ic');
        if ($ownTx) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
        try { $pdo->exec('DROP TEMPORARY TABLE IF EXISTS lal_ic'); } catch (Throwable $e2) { /* ปล่อย */ }
        throw $e;
    }
    return $n;
}

/** ตรวจความสอดคล้องหลังเขียน — คืนรายการปัญหา (ว่าง = ผ่าน) */
function lalVerify(PDO $pdo, array $p): array {
    $bad = [];
    $chk = [
        'ค้างรหัสพัก IC'            => "SELECT COUNT(*) FROM ic_items WHERE ic_code LIKE 'Z%'",
        'ค้างรหัสพัก materials'     => "SELECT COUNT(*) FROM materials WHERE code_type = 'ic' AND mat_code LIKE 'Z%'",
        'IC ไม่มีแถว materials'      => "SELECT COUNT(*) FROM ic_items i LEFT JOIN materials m ON m.mat_code = i.ic_code AND m.code_type = 'ic' WHERE m.id IS NULL",
        'materials(ic) ไม่มี IC'     => "SELECT COUNT(*) FROM materials m LEFT JOIN ic_items i ON i.ic_code = m.mat_code WHERE m.code_type = 'ic' AND i.ic_code IS NULL",
        'รหัสไม่ตรงชิ้นรหัส'          => 'SELECT COUNT(*) FROM ic_items WHERE CONCAT(llp_code, size_code, brand_code, unit_code, extra_code) <> ic_code
                                           OR llp_code <> CONCAT(l1_code, l2_code, SUBSTRING(llp_code, 6, 3))',
        'IC อ้างคุณสมบัติที่ไม่มี'     => "SELECT COUNT(*) FROM ic_items i LEFT JOIN extra_attrs e ON e.llp_code = i.llp_code AND e.extra_code = i.extra_code
                                           WHERE i.extra_code <> '000' AND e.extra_code IS NULL",
        'IC อ้างขนาดที่ไม่มี'         => 'SELECT COUNT(*) FROM ic_items i LEFT JOIN sizes s ON s.size_code = i.size_code WHERE s.size_code IS NULL',
        'บรรทัดเอกสารรหัสไม่ตรง'      => 'SELECT COUNT(*) FROM document_items di JOIN materials m ON m.id = di.material_id WHERE di.mat_code <> m.mat_code',
        'Mango หลาย LLP'            => 'SELECT COUNT(*) FROM (SELECT mat_code FROM mango_ic_map GROUP BY mat_code HAVING COUNT(DISTINCT llp_code) > 1) t',
        'IC ไม่อยู่ใต้ LLP ของ Mango' => "SELECT COUNT(*) FROM mango_ic_map x JOIN ic_items i ON i.ic_code = x.ic_code WHERE x.ic_code <> '' AND i.llp_code <> x.llp_code",
        'การผูกอ้าง IC ที่ไม่มี'       => "SELECT COUNT(*) FROM mango_ic_map x LEFT JOIN ic_items i ON i.ic_code = x.ic_code WHERE x.ic_code <> '' AND i.ic_code IS NULL",
        'การผูกอ้าง LLP ที่ไม่มี'      => "SELECT COUNT(*) FROM mango_ic_map x LEFT JOIN llp_products l ON l.llp_code = x.llp_code WHERE x.llp_code <> '' AND l.llp_code IS NULL",
    ];
    // [2026-10-02] ตารางที่เก็บรหัสซ้ำกับแถวต้นทาง — ต้องตรงกันหลังเปลี่ยนเลข (เฉพาะตารางที่มีในฐานนี้)
    $have = array_column(lalRefs($pdo), 0);
    if (in_array('stock_adjustments', $have, true)) {
        $chk['ปรับยอดจากใบนับ รหัสไม่ตรง'] = 'SELECT COUNT(*) FROM stock_adjustments a JOIN materials m ON m.id = a.material_id WHERE a.mat_code <> m.mat_code';
    }
    if (in_array('borrow_writeoffs', $have, true)) {
        $chk['ตีชำรุด/สูญหาย รหัสไม่ตรง'] = 'SELECT COUNT(*) FROM borrow_writeoffs w JOIN document_items di ON di.id = w.item_id WHERE w.mat_code <> di.mat_code';
    }
    if (in_array('push_allocs', $have, true)) {
        $chk['จัดสรร buffer รหัสไม่ตรง'] = 'SELECT COUNT(*) FROM push_allocs a JOIN materials m ON m.id = a.material_id WHERE a.ic_code <> m.mat_code';
    }
    foreach ($chk as $label => $sql) { $x = (int)$pdo->query($sql)->fetchColumn(); if ($x) { $bad[] = "$label $x"; } }
    // Mango ในไฟล์ต้องอยู่ที่ตัวสินค้าตามไฟล์ (ยกเว้นกลุ่มที่รายงานว่าไม่ย้าย)
    $skip = array_flip($p['frozen']);
    $st = $pdo->prepare('SELECT DISTINCT llp_code FROM mango_ic_map WHERE mat_code = ?');
    $miss = 0;
    foreach ($p['target'] as $m => $F) {
        if (isset($skip[$m])) { continue; }
        $st->execute([(string)$m]);
        $l = $st->fetchAll(PDO::FETCH_COLUMN);
        if ($l !== [$F]) { $miss++; }
    }
    if ($miss) { $bad[] = "Mango ยังไม่ตรงไฟล์ $miss"; }
    // ชื่อรหัสในไฟล์ต้องตรงไฟล์
    $st = $pdo->prepare('SELECT llp_name FROM llp_products WHERE llp_code = ?');
    $nm = 0;
    foreach ($p['llp'] as $F => $f) { if ($f['fh']) { $st->execute([$F]); if ((string)$st->fetchColumn() !== $f['name']) { $nm++; } } }
    if ($nm) { $bad[] = "ชื่อ LLP ไม่ตรงไฟล์ $nm"; }
    return $bad;
}

/** รายงาน .xlsx — แผน/ผล: สรุป · ตัวสินค้า · IC เปลี่ยนเลข · Mango ย้าย · ประเด็น */
function lalReportXlsx(array $p, ?array $n = null): string {
    $s = $p['stat'];
    $sum = [['หัวข้อ', 'ค่า'],
        ['ไฟล์อ้างอิง', basename((string)$s['file'])],
        ['สถานะ', $n === null ? 'แผน (ยังไม่เขียนลงระบบ)' : 'เขียนลงระบบแล้ว ' . date('Y-m-d H:i')],
        ['แถวในไฟล์ที่มี LLP / รหัส LLP ในไฟล์', $s['fh_rows'] . ' / ' . $s['fh_codes']],
        ['รหัส Mango ในไฟล์ / ไม่มีในทะเบียนระบบ', $s['fh_mango'] . ' / ' . $s['fh_mango_missing']],
        ['แถวที่ไม่มีรหัส Mango (ใช้แค่ชื่อ LLP)', (string)($s['fh_no_mango'] ?? 0)],
        ['Mango ที่ตรงไฟล์หลังจัด / ที่ต้องตรง', $s['fh_mango_aligned'] . ' / ' . $s['fh_mango_target']],
        ['IC ที่เปลี่ยนเลข (ยอด/เอกสารตามไปเอง ไม่ย้ายยอด)', (string)$s['ic_moves']],
        ['ยอดคงเหลือรวมบน IC ที่เปลี่ยนเลข', (string)$s['ic_stock']],
        ['Mango ที่ย้ายตัวสินค้า / ผูกใหม่', $s['mango_moves'] . ' / ' . $s['mango_new']],
        ['ตัวสินค้า: สร้างใหม่ / เปลี่ยนชื่อ / แก้ Cat-Char / ลบ (ว่างแล้ว)', $s['llp_new'] . ' / ' . $s['llp_rename'] . ' / ' . $s['llp_charcat'] . ' / ' . $s['llp_delete']],
        ['ตัวสินค้าที่หลบไปเลขถัดไป (นั่งรหัส Flow Hub อยู่)', (string)$s['displaced']],
        ['ขนาดใหม่ในพจนานุกรม', (string)$s['sizes_new']],
        ['IC ที่จะได้รหัสซ้ำกัน (ต้องรวมยอดก่อน)', (string)$s['collisions']],
        ['ประเด็นที่ต้องดู', (string)$s['issues']]];
    if ($n !== null) { $sum[] = ['เขียนจริง', json_encode($n)]; }

    $chgTxt = ['new' => 'สร้างใหม่', 'rename' => 'เปลี่ยนชื่อ', 'charcat' => 'แก้ Cat/Char', '' => '-'];
    $llp = [['รหัส', 'ชื่อเดิมในระบบ', 'ชื่อใหม่', 'CatID', 'CharID', 'เปลี่ยน', 'ที่มา']];
    ksort($p['llp'], SORT_STRING);
    foreach ($p['llp'] as $F => $f) {
        if ($f['change'] === '') { continue; }
        $why = $f['displaced_from'] !== '' ? 'ย้ายมาจาก ' . $f['displaced_from'] . ' (เลขชั่วคราว)' : ($f['fh'] ? 'Flow Hub' : '');
        $llp[] = [(string)$F, $f['old_name'], $f['name'], (string)$f['cat'], (string)$f['char'], $chgTxt[$f['change']], $why];
    }
    foreach ($p['delete'] as $X) { $llp[] = [$X, (string)($p['db_llp'][$X]['llp_name'] ?? ''), '', '', '', 'ลบ (ว่างแล้ว)', '']; }

    $ic = [['รหัส IC เดิม', 'รหัส IC ใหม่', 'ชื่อ IC', 'LLP เดิม', 'LLP ใหม่', 'ส่วนต่างชื่อที่ลงสเปก', 'คุณสมบัติ', 'คงเหลือ', 'ใช้งาน']];
    foreach ($p['ic'] as $old => $mv) {
        $ic[] = [(string)$old, $mv['new'], $mv['name'], $mv['from'], $mv['llp'], $mv['residue'], $mv['extra_txt'],
                 $mv['on_hand'] != 0 ? (string)$mv['on_hand'] : '', $mv['active'] ? 'Y' : 'N'];
    }
    $mg = [['รหัส Mango', 'ชื่อ', 'LLP เดิม', 'ชื่อ LLP เดิม', 'LLP ใหม่', 'ชื่อ LLP ใหม่']];
    foreach ($p['map'] as $m => $d) {
        $mg[] = [(string)$m, (string)($p['mat_names'][$m] ?? ''), $d['from'], (string)($p['db_llp'][$d['from']]['llp_name'] ?? ''),
                 $d['to'], (string)($p['llp'][$d['to']]['name'] ?? '')];
    }
    foreach ($p['map_new'] as $m => $F) {
        $mg[] = [(string)$m, (string)($p['mat_names'][$m] ?? ''), '(ยังไม่ผูก)', '', $F, (string)($p['llp'][$F]['name'] ?? '')];
    }
    $is = [['ประเภท', 'รหัส Mango', 'LLP', 'รายละเอียด']];
    foreach ($p['issues'] as $x) { $is[] = [$x['type'], (string)$x['mat'], (string)$x['llp'], $x['msg']]; }

    return xlsxWrite([
        'สรุป'         => ['header' => true, 'widths' => [56, 60], 'rows' => $sum],
        'ตัวสินค้า'     => ['header' => true, 'widths' => [11, 36, 36, 7, 7, 13, 30], 'rows' => $llp],
        'IC เปลี่ยนเลข' => ['header' => true, 'widths' => [23, 23, 48, 10, 10, 22, 36, 9, 7], 'rows' => $ic],
        'Mango ย้าย'   => ['header' => true, 'widths' => [16, 40, 11, 32, 10, 32], 'rows' => $mg],
        'ประเด็น'      => ['header' => true, 'widths' => [11, 18, 22, 100], 'rows' => $is],
    ]);
}
