<?php
/**
 * CONNEXT — lib/llp_import.php : อ่าน/นำเข้าไฟล์ "สร้าง LLP.xlsx" ของฝ่ายจัดซื้อ
 * ใช้ร่วมกันระหว่าง CLI (db/import_llp_master.php) กับปุ่มนำเข้าในหน้า setup_master.php
 *
 * ไฟล์ต้นทางมี 3 ชีตที่ใช้:
 *   · "LL Code"            → l1_groups (L1_ID 3 หลัก) + l2_categories (L2_ID 2 หลัก)
 *   · "Create_LLP_All Mat" → llp_products (LLP 8 หลัก = L1+L2+ลำดับ 3 หลัก) + ผูก Product Code → LLP
 *   · "IC_All Mat"         → หน่วยเก็บ (Unit Name (IC)) เติมเข้าตาราง units ที่ยังไม่มี
 *
 * กติกา (ทั้ง CLI และจอ ใช้ชุดเดียวกัน)
 *   · รับเฉพาะแถวที่ LLP ครบ 8 หลักและ L1/L2 มีในชีต LL Code — แถวที่ยังจัดหมวดไม่เสร็จข้ามพร้อมรายงาน
 *   · ผูกเฉพาะรหัส Mango ที่มีในทะเบียนของระบบนี้ (materials code_type='mango')
 *   · CatID/CharID ของ LLP: ไฟล์ไม่มี — สรุปจากค่าเดิมของ Mango ใต้ LLP นั้น ตรงกันหมดถึงตั้งให้
 *     (ไม่ทับค่าที่ ADM ตั้งเอง) ไม่ตรงกัน = ปล่อย NULL ให้รีวิวที่ llp_master.php (มติ 32)
 *   · รันซ้ำได้: เพิ่มของใหม่ + แก้ชื่อ · ไม่ลบการผูกที่ทำเองในจอ
 *   · 1 Mango มี LLP ได้ตัวเดียว (มติ 45): Product Code เดียวกันมีหลาย LLP ในไฟล์ → ใช้แถวแรก ·
 *     ผูกให้เฉพาะรหัสที่ยังไม่ผูก LLP — รหัสที่ผูกไว้แล้วแต่ไฟล์ให้ LLP อื่นไม่เปลี่ยนให้ (รายงานเป็น
 *     "ไม่ตรงกับที่ผูกไว้" ให้เปลี่ยนเองที่จอข้อ 3 เพราะ IC ใต้ LLP เดิมจะหลุด)
 *
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/ic.php';
require_once __DIR__ . '/setup_master.php';
require_once __DIR__ . '/../includes/xlsx_lite.php';

/** ที่อยู่ไฟล์ต้นฉบับที่เดาให้ (โฟลเดอร์ Data ข้าง ๆ ตัวโปรเจกต์) — ไม่มีก็คืน '' */
function llpDefaultFile(): string {
    $try = [
        dirname(__DIR__, 4) . '/Software/Data/สร้าง LLP.xlsx',
        dirname(__DIR__, 3) . '/Data/สร้าง LLP.xlsx',
        ROOT_PATH . 'db/source_data/สร้าง LLP.xlsx',
    ];
    foreach ($try as $p) { if (is_file($p)) { return $p; } }
    return '';
}

/**
 * อ่านไฟล์ → โครงสร้างพร้อมนำเข้า + สถิติสำหรับหน้า preview (ยังไม่แตะ DB นอกจากอ่านทะเบียนวัสดุ)
 * @return array ['l1','l2','llp','pair','charcat','units','stat']
 * @throws RuntimeException ถ้าไฟล์ไม่มีชีตที่ต้องใช้
 */
function llpImportRead(PDO $pdo, string $path, bool $withCharcat = true): array {
    $sheets = xlsxReadSheets($path);
    $pick = function (string $want) use ($sheets) {
        foreach ($sheets as $name => $rows) {
            if (mb_strtolower(trim($name), 'UTF-8') === mb_strtolower($want, 'UTF-8')) { return $rows; }
        }
        return null;
    };
    $idx = function (array $head): array { $m = []; foreach ($head as $i => $h) { $m[trim((string)$h)] = $i; } return $m; };
    $val = function (array $row, array $m, string $col): string {
        return isset($m[$col]) ? trim((string)($row[$m[$col]] ?? '')) : '';
    };
    $code = function (string $s): string { return strtoupper(preg_replace('/\s+/u', '', trim($s))); };

    // ── LL Code → L1 / L2 ──────────────────────────────────────────────────
    $ll = $pick('LL Code');
    if ($ll === null) { throw new RuntimeException('ไม่พบชีต "LL Code" ในไฟล์ — ไฟล์นี้ไม่ใช่ไฟล์สร้าง LLP'); }
    $m = $idx($ll[0]);
    $L1 = []; $L2 = []; $llBad = 0;
    foreach (array_slice($ll, 1) as $r) {
        $a = $code($val($r, $m, 'L1_ID'));
        $b = $code($val($r, $m, 'L2_ID'));
        if ($a === '') { continue; }
        if (strlen($a) !== 3) { $llBad++; continue; }
        if (!isset($L1[$a])) { $L1[$a] = $val($r, $m, 'L1'); }
        if ($b === '') { continue; }
        if (strlen($b) !== 2) { $llBad++; continue; }
        $L2[$a . $b] = ['l1' => $a, 'l2' => $b, 'name' => $val($r, $m, 'L2')];
    }

    // ── Create_LLP_All Mat → LLP + คู่ (Mango × LLP) ───────────────────────
    $cl = $pick('Create_LLP_All Mat');
    if ($cl === null) { throw new RuntimeException('ไม่พบชีต "Create_LLP_All Mat" ในไฟล์'); }
    $m2 = $idx($cl[0]);
    $LLP = []; $PAIR = [];
    $skip = ['llp_sn' => 0, 'no_ll' => 0, 'no_mat' => 0, 'dup' => 0, 'multi' => 0];
    $skipSample = [];
    foreach (array_slice($cl, 1) as $r) {
        $mat = $code($val($r, $m2, 'Product Code'));
        $llp = $code($val($r, $m2, 'LLP'));
        if ($mat === '' && $llp === '') { continue; }
        if ($llp === '' || strlen($llp) !== 8) {
            $skip['llp_sn']++;
            if (count($skipSample) < 10 && $mat !== '') {
                $skipSample[] = ['mat' => $mat, 'llp' => $llp, 'name' => $val($r, $m2, 'ชื่อในPO')];
            }
            continue;
        }
        $a = substr($llp, 0, 3); $b = substr($llp, 3, 2); $p = substr($llp, 5, 3);
        if (!isset($L2[$a . $b]) || !ctype_digit($p)) { $skip['no_ll']++; continue; }
        if (!isset($LLP[$llp])) {
            $name = $val($r, $m2, 'Product Name');
            if ($name === '') { $name = $val($r, $m2, 'ชื่อในPO'); }
            if ($name === '') { $name = $llp; }
            $LLP[$llp] = ['l1' => $a, 'l2' => $b, 'p' => $p, 'name' => mb_substr($name, 0, 255)];
        }
        if ($mat === '') { $skip['no_mat']++; continue; }
        if (isset($PAIR[$mat])) {                  // 1 Mango = 1 LLP (มติ 45) — แถวแรกชนะ
            if (isset($PAIR[$mat][$llp])) { $skip['dup']++; } else { $skip['multi']++; }
            continue;
        }
        $PAIR[$mat][$llp] = true;
    }

    // ── เทียบกับทะเบียนวัสดุในระบบ ─────────────────────────────────────────
    $known = [];
    foreach ($pdo->query("SELECT mat_code, cat_id, char_id FROM materials WHERE code_type = 'mango'")->fetchAll() as $r) {
        $known[(string)$r['mat_code']] = $r;
    }
    $use = []; $notInDb = 0; $nPair = 0;
    foreach ($PAIR as $mat => $llps) {
        $nPair += count($llps);
        if (!isset($known[$mat])) { $notInDb++; continue; }
        $use[$mat] = array_keys($llps);
    }
    $nUse = 0;
    foreach ($use as $l) { $nUse += count($l); }

    // ── CatID/CharID ที่สรุปได้ ────────────────────────────────────────────
    $charcat = []; $ccMixed = 0; $ccNoMango = 0;
    if ($withCharcat) {
        $vote = [];
        foreach ($use as $mat => $llps) {
            $cat = icNormalizeCat($known[$mat]['cat_id']);
            $chr = icNormalizeChar($known[$mat]['char_id']);
            foreach ($llps as $llp) {
                if ($cat !== null) { $vote[$llp]['cat'][$cat] = true; }
                if ($chr !== null) { $vote[$llp]['char'][$chr] = true; }
            }
        }
        foreach ($vote as $llp => $v) {
            $cat = isset($v['cat']) && count($v['cat']) === 1 ? array_key_first($v['cat']) : null;
            $chr = isset($v['char']) && count($v['char']) === 1 ? array_key_first($v['char']) : null;
            if ($cat !== null && $chr !== null) { $charcat[$llp] = [$cat, $chr]; } else { $ccMixed++; }
        }
        $ccNoMango = count($LLP) - count($vote);
    }

    // ── หน่วยเก็บจาก IC_All Mat ────────────────────────────────────────────
    $newUnits = [];
    $icSheet = $pick('IC_All Mat');
    if ($icSheet !== null) {
        $m3 = $idx($icSheet[0]);
        $have = [];
        foreach ($pdo->query('SELECT unit_name FROM units')->fetchAll(PDO::FETCH_COLUMN) as $u) {
            $have[mb_strtolower(preg_replace('/\s+/u', '', (string)$u), 'UTF-8')] = true;
        }
        foreach (array_slice($icSheet, 1) as $r) {
            $u = $val($r, $m3, 'Unit Name (IC)');
            if ($u === '' || mb_strlen($u) > 50) { continue; }
            $k = mb_strtolower(preg_replace('/\s+/u', '', $u), 'UTF-8');
            if (isset($have[$k])) { continue; }
            $have[$k] = true;
            $newUnits[$k] = $u;
        }
    }

    // ── ของที่มีอยู่แล้วใน DB (บอกว่าจะ "เพิ่ม" เท่าไหร่) ────────────────────
    $hasLlp = array_flip(array_map('strval', $pdo->query('SELECT llp_code FROM llp_products')->fetchAll(PDO::FETCH_COLUMN)));
    $dbLlp = [];
    foreach ($pdo->query('SELECT mat_code, llp_code FROM mango_ic_map')->fetchAll() as $r) {
        $dbLlp[(string)$r['mat_code']][(string)$r['llp_code']] = true;
    }
    $newLlp = 0;
    foreach ($LLP as $c => $v) { if (!isset($hasLlp[$c])) { $newLlp++; } }
    // ผูกได้เฉพาะรหัสที่ยังไม่ผูก · ผูกอยู่แล้วแต่คนละตัว = ไม่ตรงกับที่ผูกไว้ (ไม่เปลี่ยนให้)
    $newPair = 0; $conflict = 0; $conflictSample = [];
    foreach ($use as $mat => $llps) {
        if (!isset($dbLlp[$mat])) { $newPair++; continue; }
        if (isset($dbLlp[$mat][$llps[0]])) { continue; }
        $conflict++;
        if (count($conflictSample) < 10) {
            $conflictSample[] = ['mat' => $mat, 'file' => $llps[0], 'db' => implode(', ', array_keys($dbLlp[$mat]))];
        }
    }

    return [
        'l1' => $L1, 'l2' => $L2, 'llp' => $LLP, 'pair' => $use,
        'charcat' => $charcat, 'units' => $newUnits,
        'stat' => [
            'file'        => $path,
            'l1'          => count($L1),
            'l2'          => count($L2),
            'll_bad'      => $llBad,
            'llp'         => count($LLP),
            'llp_new'     => $newLlp,
            'pair_file'   => $nPair,
            'pair_use'    => $nUse,
            'pair_new'    => $newPair,
            'pair_conflict'   => $conflict,
            'conflict_sample' => $conflictSample,
            'mat_file'    => count($PAIR),
            'mat_use'     => count($use),
            'mat_not_db'  => $notInDb,
            'db_total'    => count($known),
            'db_no_llp'   => count($known) - count($use),
            'skip'        => $skip,
            'skip_sample' => $skipSample,
            'cc_set'      => count($charcat),
            'cc_mixed'    => $ccMixed,
            'cc_no_mango' => $ccNoMango,
            'units_new'   => count($newUnits),
        ],
    ];
}

/**
 * เขียนผลที่อ่านได้ลง DB — ทรานแซกชันเดียว พลาดตรงไหน rollback ทั้งหมด
 * @return array จำนวนที่เขียนจริง
 */
function llpImportApply(PDO $pdo, array $p, ?array $user = null): array {
    $n = ['l1' => 0, 'l2' => 0, 'llp' => 0, 'llp_upd' => 0, 'pair' => 0, 'cc' => 0, 'unit' => 0, 'l1_off' => 0, 'l2_off' => 0];
    $L1 = $p['l1']; $L2 = $p['l2']; $LLP = $p['llp']; $use = $p['pair'];

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) { $pdo->beginTransaction(); }
    try {
        // L1 / L2 — upsert · ของเดิมที่ไม่อยู่ในไฟล์: ไม่มีใครใช้ → ลบ · มีคนใช้ → ปิดใช้งาน
        $insL1 = $pdo->prepare('INSERT INTO l1_groups (l1_code, l1_name, sort_order, is_active) VALUES (?,?,?,1)
                                ON DUPLICATE KEY UPDATE l1_name = VALUES(l1_name), sort_order = VALUES(sort_order), is_active = 1');
        $i = 0;
        foreach ($L1 as $c1 => $name) { $insL1->execute([$c1, $name !== '' ? $name : $c1, ++$i]); $n['l1']++; }

        $insL2 = $pdo->prepare('INSERT INTO l2_categories (l1_code, l2_code, l2_name, is_active) VALUES (?,?,?,1)
                                ON DUPLICATE KEY UPDATE l2_name = VALUES(l2_name), is_active = 1');
        foreach ($L2 as $row) {
            $insL2->execute([$row['l1'], $row['l2'], $row['name'] !== '' ? $row['name'] : $row['l1'] . $row['l2']]);
            $n['l2']++;
        }

        // ทุกหมวดต้องอนุญาตขนาด/ยี่ห้อ '000' (ไม่ระบุ) เสมอ ไม่งั้น icValidateParts ปัดตกตั้งแต่ IC ตัวแรก
        $insSz = $pdo->prepare("INSERT IGNORE INTO l2_sizes (l1_code, l2_code, size_code) VALUES (?,?,'000')");
        $insBr = $pdo->prepare("INSERT IGNORE INTO l2_brands (l1_code, l2_code, brand_code) VALUES (?,?,'000')");
        $pdo->exec("INSERT IGNORE INTO sizes (size_code, size_name) VALUES ('000','ไม่ระบุ')");
        $pdo->exec("INSERT IGNORE INTO brands (brand_code, brand_name) VALUES ('000','ไม่ระบุ')");
        foreach ($L2 as $row) { $insSz->execute([$row['l1'], $row['l2']]); $insBr->execute([$row['l1'], $row['l2']]); }

        $usedL2 = array_flip(array_map('strval',
            $pdo->query('SELECT DISTINCT CONCAT(l1_code, l2_code) FROM llp_products')->fetchAll(PDO::FETCH_COLUMN)));
        foreach ($pdo->query('SELECT l1_code, l2_code FROM l2_categories')->fetchAll() as $row) {
            $k = (string)$row['l1_code'] . (string)$row['l2_code'];
            if (isset($L2[$k])) { continue; }
            if (isset($usedL2[$k])) {
                $pdo->prepare('UPDATE l2_categories SET is_active = 0 WHERE l1_code = ? AND l2_code = ?')
                    ->execute([$row['l1_code'], $row['l2_code']]);
                $n['l2_off']++;
            } else {
                $pdo->prepare('DELETE FROM l2_categories WHERE l1_code = ? AND l2_code = ?')
                    ->execute([$row['l1_code'], $row['l2_code']]);
            }
        }
        foreach ($pdo->query('SELECT l1_code FROM l1_groups')->fetchAll(PDO::FETCH_COLUMN) as $c1) {
            if (isset($L1[$c1])) { continue; }
            $st = $pdo->prepare('SELECT COUNT(*) FROM l2_categories WHERE l1_code = ?');
            $st->execute([$c1]);
            if ((int)$st->fetchColumn() > 0) {
                $pdo->prepare('UPDATE l1_groups SET is_active = 0 WHERE l1_code = ?')->execute([$c1]);
                $n['l1_off']++;
            } else {
                $pdo->prepare('DELETE FROM l1_groups WHERE l1_code = ?')->execute([$c1]);
            }
        }

        // หน่วยเก็บ
        foreach ($p['units'] as $u) {
            $codeU = icNextDictCode($pdo, 'units', 'unit_code');
            if ($codeU === '') { break; }
            $pdo->prepare('INSERT IGNORE INTO units (unit_code, unit_name) VALUES (?,?)')->execute([$codeU, $u]);
            $n['unit']++;
        }

        // LLP
        $insLlp = $pdo->prepare('INSERT INTO llp_products (llp_code, l1_code, l2_code, product_code, llp_name, created_by)
                                 VALUES (?,?,?,?,?,?)');
        $updLlp = $pdo->prepare('UPDATE llp_products SET llp_name = ? WHERE llp_code = ? AND llp_name <> ?');
        $hasLlp = array_flip(array_map('strval', $pdo->query('SELECT llp_code FROM llp_products')->fetchAll(PDO::FETCH_COLUMN)));
        $uid = $user !== null ? ((int)($user['accountId'] ?? 0) ?: null) : null;
        foreach ($LLP as $c => $v) {
            if (isset($hasLlp[$c])) {
                $updLlp->execute([$v['name'], $c, $v['name']]);
                $n['llp_upd'] += $updLlp->rowCount();
            } else {
                $insLlp->execute([$c, $v['l1'], $v['l2'], $v['p'], $v['name'], $uid]);
                $n['llp']++;
            }
        }

        // ผูก Mango → LLP (ขั้น 1) — เฉพาะรหัสที่ยังไม่ผูกตัวไหนเลย (1 Mango = 1 LLP · มติ 45)
        $insPair = $pdo->prepare("INSERT IGNORE INTO mango_ic_map (mat_code, llp_code, ic_code, is_primary, mapped_by) VALUES (?,?,'',1,?)");
        $cntPair = $pdo->prepare('SELECT COUNT(*) FROM mango_ic_map WHERE mat_code = ?');
        foreach ($use as $mat => $llps) {
            $cntPair->execute([$mat]);
            if ((int)$cntPair->fetchColumn() > 0) { continue; }
            $insPair->execute([$mat, (string)$llps[0], $uid]);
            $n['pair'] += $insPair->rowCount();
        }

        // CatID/CharID ที่สรุปได้ — ไม่ทับค่าที่ตั้งเองไว้แล้ว
        $upCc = $pdo->prepare('UPDATE llp_products SET cat_id = ?, char_id = ?, charcat_by = ?, charcat_at = NOW()
                                WHERE llp_code = ? AND (cat_id IS NULL OR char_id IS NULL)');
        foreach ($p['charcat'] as $llp => $cc) {
            $upCc->execute([$cc[0], $cc[1], $uid, $llp]);
            $n['cc'] += $upCc->rowCount();
        }

        if ($ownTx) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return $n;
}
