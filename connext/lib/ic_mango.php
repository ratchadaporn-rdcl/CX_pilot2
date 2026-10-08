<?php
/**
 * CONNEXT — lib/ic_mango.php : ออกรหัส IC แบบ "ตั้งต้นจากรหัส Mango เสมอ" (มติ 47)
 *
 * ผู้ใช้สั่ง 2026-09-23: หน้า "สร้างรหัส IC" ต้องเริ่มจากรหัส Mango ก่อนเสมอ
 * — IC ที่ออกจากจอนั้นต้องมีต้นทางเป็นรหัส Mango และผูกกลับไปหามันทันที ไม่มี IC ลอย
 *
 *   ขั้น 0  เลือกรหัส Mango (ทะเบียน materials.code_type='mango')
 *   ขั้น 1  ตัวสินค้า (LLP) — Mango ผูกไว้แล้ว = ล็อกที่ตัวนั้น (1 Mango = 1 LLP · มติ 45)
 *                             ยังไม่ผูก = ADM เลือก/สร้างในบันได แล้วระบบผูกให้ตอนออกรหัส
 *   ขั้น 2  ขนาด/ยี่ห้อ/หน่วย/คุณสมบัติ — ตั้งต้นจาก IC หลักของ Mango (ถ้ามี) ไม่งั้นหน่วยตาม Mango
 *   ออกรหัส = ผูก LLP (ถ้ายังไม่ผูก) + ตั้ง Cat/Char ที่ LLP (ADM) + icCreate + smAttachIc
 *             ในทรานแซกชันเดียว — พลาดขั้นไหนไม่มีอะไรค้าง (ไม่มี LLP ผูกค้าง / IC ไม่มีต้นทาง)
 *
 * สิทธิ์ (มติ 16): สายคลังออก IC ได้เฉพาะใต้ LLP ที่ Mango ผูกไว้แล้ว ·
 *   เลือกตัวสินค้าให้ Mango ที่ยังไม่ผูก / ตั้ง Cat/Char = ADM
 *
 * ใช้โดย ic_new.php ผ่าน api/ic_api.php (a=mango_find · mango_info · create_for_mango)
 * จอรับของ (rc.php) กับ modal ของ setup_master.php ยังใช้ create_ic เดิม — มีบริบท Mango ของตัวเองอยู่แล้ว
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/setup_master.php';   // smMapLlp / smAttachIc / smMapList / smLlpsOf (+ ic.php, mango.php)

/** ชื่อหน่วยแบบเทียบได้ — ตัดช่องว่าง + ตัวเล็ก (แบบเดียวกับ smUnitSame) */
function icmUnitKey(string $s): string {
    return mb_strtolower(preg_replace('/\s+/u', '', trim($s)), 'UTF-8');
}

/** unit_code ของหน่วยเก็บที่ชื่อตรงกับหน่วยของ Mango — '' ถ้าไม่มีตัวที่ตรง */
function icmUnitCodeFor(PDO $pdo, string $unitName): string {
    $k = icmUnitKey($unitName);
    if ($k === '') { return ''; }
    foreach (icUnitList($pdo) as $u) {
        if (icmUnitKey((string)$u['unit_name']) === $k) { return (string)$u['unit_code']; }
    }
    return '';
}

/**
 * ค้นรหัส Mango สำหรับขั้น 0 — รหัส / ชื่อ / กลุ่มย่อย / รหัส LLP หรือ IC ที่ผูกอยู่
 * รหัสตรงตัวขึ้นก่อน แล้วรหัสที่ขึ้นต้นด้วยคำค้น
 * @return array ['rows' => [mat_code,name,unit,subgroup,llp_code,llp_name,n_llp,n_ic], 'more' => int]
 */
function icmFind(PDO $pdo, string $q, int $limit = 20): array {
    $q = trim($q);
    if ($q === '') { return ['rows' => [], 'more' => 0]; }

    $like  = '%' . likeEscape($q) . '%';
    $code  = mangoNormalizeCode($q);
    $where = "m.code_type = 'mango'
              AND (m.mat_code LIKE ? OR m.name LIKE ? OR m.subgroup_name LIKE ?
                   OR EXISTS (SELECT 1 FROM mango_ic_map x
                               WHERE x.mat_code = m.mat_code AND (x.llp_code LIKE ? OR x.ic_code LIKE ?)))";
    $args  = [$like, $like, $like, $like, $like];

    $st = $pdo->prepare(
        "SELECT m.mat_code, m.name, m.unit, m.subgroup_name,
                COALESCE(h.n_llp, 0) AS n_llp, COALESCE(h.n_ic, 0) AS n_ic,
                COALESCE(h.llp_code, '') AS llp_code, COALESCE(p.llp_name, '') AS llp_name
           FROM materials m
           LEFT JOIN (SELECT mat_code, COUNT(DISTINCT llp_code) AS n_llp, MIN(llp_code) AS llp_code,
                             SUM(ic_code <> '') AS n_ic
                        FROM mango_ic_map GROUP BY mat_code) h ON h.mat_code = m.mat_code
           LEFT JOIN llp_products p ON p.llp_code = h.llp_code
          WHERE $where
          ORDER BY (m.mat_code = ?) DESC, (m.mat_code LIKE ?) DESC, m.mat_code
          LIMIT " . (int)$limit
    );
    $st->execute(array_merge($args, [$code, likeEscape($code) . '%']));
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[] = [
            'mat_code' => (string)$r['mat_code'],
            'name'     => (string)$r['name'],
            'unit'     => (string)$r['unit'],
            'subgroup' => (string)($r['subgroup_name'] ?? ''),
            'llp_code' => (string)$r['llp_code'],
            'llp_name' => (string)$r['llp_name'],
            'n_llp'    => (int)$r['n_llp'],
            'n_ic'     => (int)$r['n_ic'],
        ];
    }

    // บอกว่ายังมีอีกกี่ตัวที่ไม่ได้แสดง — กันเข้าใจผิดว่ามีแค่นี้
    $more = 0;
    if (count($rows) >= $limit) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM materials m WHERE ' . $where);
        $st->execute($args);
        $more = max(0, (int)$st->fetchColumn() - count($rows));
    }
    return ['rows' => $rows, 'more' => $more];
}

/**
 * รหัส Mango หนึ่งตัวพร้อมทุกอย่างที่จอออกรหัสต้องใช้ — คืน null ถ้าไม่มีในทะเบียน
 *   mat      รหัส/ชื่อ/หน่วย/กลุ่มย่อย/cat/char ของ Mango
 *   llps     LLP ที่ผูก (ปกติ 0-1 ตัว) พร้อม IC ใต้มัน — โครงเดียวกับ api/setup_master_api.php
 *   problem  '' = ปกติ · 'multi' ผูกหลาย LLP (ข้อมูลก่อนมติ 45) · 'missing' LLP ไม่มีในทะเบียน ·
 *            'inactive' LLP ถูกปิด — 3 แบบหลังต้องแก้ที่หน้าจัดการรหัสวัสดุก่อน ออก IC ไม่ได้
 *   stock    ยอดที่ยังค้างบนแถว Mango (ยังไม่ได้ยกไป IC)
 *   defaults ค่าตั้งต้นของบันได: llp ที่ล็อก · ขนาด/ยี่ห้อ/หน่วยจาก IC หลัก (ถ้ามี) ไม่งั้นหน่วยที่ชื่อตรงกับ Mango ·
 *            cat/char ของ Mango (ใช้ตั้งต้นให้ LLP ที่ยังไม่ตั้งค่า)
 */
function icmInfo(PDO $pdo, string $matCode): ?array {
    $mat = mangoGet($pdo, $matCode);
    if ($mat === null) { return null; }
    $code = (string)$mat['mat_code'];

    $catL = icCatLabels();
    $chrL = icCharLabels();
    $llps = [];
    foreach (smMapList($pdo, $code) as $b) {
        $ics = [];
        foreach ($b['ics'] as $x) {
            $ics[] = [
                'ic_code'    => (string)$x['ic_code'],
                'ic_name'    => (string)$x['ic_name'],
                'unit'       => (string)$x['unit_name'],
                'is_primary' => (bool)$x['is_primary'],
                'is_active'  => (int)$x['is_active'] === 1,
                'missing'    => (bool)$x['missing'],
                'unit_warn'  => !$x['missing'] && !smUnitSame((string)$mat['unit'], (string)$x['unit_name']),
            ];
        }
        $llps[] = [
            'llp_code'   => (string)$b['llp_code'],
            'llp_name'   => (string)$b['llp_name'],
            'path'       => trim($b['l1_name'] . ' › ' . $b['l2_name'], ' ›'),
            'cat_id'     => (string)($b['cat_id'] ?? ''),
            'char_id'    => (string)($b['char_id'] ?? ''),
            'cat_label'  => $b['cat_id']  !== null ? ($catL[$b['cat_id']]  ?? '') : '',
            'char_label' => $b['char_id'] !== null ? ($chrL[$b['char_id']] ?? '') : '',
            'is_set'     => (bool)$b['is_set'],
            'is_active'  => (int)$b['is_active'] === 1,
            'missing'    => (bool)$b['missing'],
            'ics'        => $ics,
        ];
    }

    $problem = '';
    if (count($llps) > 1)            { $problem = 'multi'; }
    elseif ($llps && $llps[0]['missing'])    { $problem = 'missing'; }
    elseif ($llps && !$llps[0]['is_active']) { $problem = 'inactive'; }

    $st = $pdo->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(on_hand), 0) AS on_hand, COALESCE(SUM(pending), 0) AS pending
                           FROM stock_balances WHERE material_id = ?');
    $st->execute([(int)$mat['id']]);
    $sb = $st->fetch();

    $def = [
        'llp' => '', 'size' => IC_NONE, 'brand' => IC_NONE, 'unit' => '', 'extra' => IC_NONE, 'base_ic' => '',
        'cat_id'  => (string)(icNormalizeCat($mat['cat_id']) ?? ''),
        'char_id' => (string)(icNormalizeChar($mat['char_id']) ?? ''),
    ];
    if ($problem === '' && count($llps) === 1) {
        $def['llp'] = $llps[0]['llp_code'];
        // IC หลัก (ไม่มีก็ตัวแรกที่ใช้งานอยู่) เป็นแม่แบบ — ได้ขนาด/ยี่ห้อ/หน่วยเดิม เหลือเลือกส่วนที่ต่าง
        $base = null;
        foreach ($llps[0]['ics'] as $x) {
            if ($x['is_active'] && !$x['missing'] && ($base === null || $x['is_primary'])) { $base = $x; }
        }
        $parts = $base !== null ? icSplit($base['ic_code']) : null;
        if ($parts !== null && $parts['llp_code'] === $def['llp']) {
            $def['size']    = $parts['size_code'];
            $def['brand']   = $parts['brand_code'];
            $def['unit']    = $parts['unit_code'];
            $def['base_ic'] = $base['ic_code'];
        }
    }
    if ($def['unit'] === '') { $def['unit'] = icmUnitCodeFor($pdo, (string)$mat['unit']); }

    return [
        'mat' => [
            'mat_code' => $code,
            'name'     => (string)$mat['name'],
            'unit'     => (string)$mat['unit'],
            'subgroup' => (string)($mat['subgroup_name'] ?? ''),
        ],
        'llps'     => $llps,
        'n_ic'     => count(smIcsOf($llps)),
        'problem'  => $problem,
        'stock'    => ['rows' => (int)$sb['n'], 'on_hand' => (float)$sb['on_hand'], 'pending' => (float)$sb['pending']],
        'defaults' => $def,
    ];
}

/**
 * ออกรหัส IC ให้รหัส Mango หนึ่งตัว แล้วผูกกลับ — ทุกขั้นในทรานแซกชันเดียว (มติ 47)
 *   Mango ผูก LLP ไว้แล้ว → ออกได้เฉพาะใต้ LLP นั้น (1 Mango = 1 LLP · มติ 45) ตัวอื่น = ปฏิเสธ
 *   ยังไม่ผูก           → ADM: ผูก LLP ที่เลือกให้ (smMapLlp) · สายคลัง: ปฏิเสธ ให้ ADM ผูกก่อน
 *   $cat/$char ไม่ว่าง  → ADM ตั้ง/แก้ Cat/Char ของ LLP ก่อนออก (cascade ทุก IC ใต้มัน · มติ 28-29, 32)
 *   รหัสที่ประกอบได้มีอยู่แล้ว → ไม่สร้างซ้ำ ผูกตัวเดิมให้ (icCreate idempotent)
 * อยู่ในทรานแซกชันของคนเรียกอยู่แล้วก็ได้ — ใช้ SAVEPOINT แทน (พลาดแล้วถอยเฉพาะส่วนของตัวเอง)
 *
 * @param array $p llp_code,size_code,brand_code,unit_code,extra_code,ic_name,has_serial,is_cx
 * @return array ['ok','error','ic_code','created','attached','mapped_llp','charcat'=>['changed','ic']]
 */
function icmCreate(PDO $pdo, string $matCode, array $p, ?array $user, bool $isAdmin,
                   string $cat = '', string $char = ''): array {
    $fail = function (string $m): array {
        return ['ok' => false, 'error' => $m, 'ic_code' => '', 'created' => false, 'attached' => false,
                'mapped_llp' => false, 'charcat' => ['changed' => false, 'ic' => 0]];
    };

    if (!smSchemaReady($pdo)) {
        return $fail('ยังไม่มีตารางผูก Mango → IC (mango_ic_map) — ให้ ADM เปิดหน้าจัดการรหัสวัสดุแล้วกดอัปเดตโครงสร้างก่อน');
    }
    $code = mangoNormalizeCode($matCode);
    if ($code === '') { return $fail('ต้องเริ่มจากรหัส Mango ก่อนเสมอ — เลือกรหัส Mango ในข้อ 0 (มติ 47)'); }
    $mat = mangoGet($pdo, $code);
    if ($mat === null) { return $fail('ไม่พบรหัส Mango ' . $code . ' ในทะเบียน — เพิ่มที่หน้าทะเบียนวัสดุ Mango ก่อน'); }

    $llp = strtoupper(trim((string)($p['llp_code'] ?? '')));
    if (strlen($llp) !== 8) { return $fail('ยังไม่ได้เลือกตัวสินค้า (LLP)'); }
    $p['llp_code'] = $llp;
    $cat  = strtoupper(trim($cat));
    $char = strtoupper(trim($char));

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) { $pdo->beginTransaction(); } else { $pdo->exec('SAVEPOINT icm_create'); }
    $undo = function () use ($pdo, $ownTx): void {
        if ($ownTx) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
        } else {
            try { $pdo->exec('ROLLBACK TO SAVEPOINT icm_create'); } catch (Throwable $e) { error_log('icmCreate undo: ' . $e->getMessage()); }
        }
    };

    try {
        // ล็อกแถวผูกของ Mango นี้ไว้ก่อน — กันสองจอออก/เปลี่ยนตัวสินค้าชนกัน
        $cur = smLlpsOf($pdo, $code, true);
        if (count($cur) > 1) {
            $undo();
            return $fail($code . ' ผูกไว้ ' . count($cur) . ' ตัวสินค้า (' . implode(', ', $cur) . ') ผิดกติกา 1 Mango มีตัวสินค้าได้ตัวเดียว '
                       . '— เลือกให้เหลือตัวเดียวที่หน้าจัดการรหัสวัสดุก่อน');
        }
        $mapped = false;
        if ($cur) {
            if ($cur[0] !== $llp) {
                $undo();
                return $fail($code . ' ผูกตัวสินค้า ' . $cur[0] . ' อยู่ — 1 Mango มีตัวสินค้าได้ตัวเดียว (มติ 45) ออก IC ได้เฉพาะใต้ '
                           . $cur[0] . ' · ถ้าตัวสินค้าผิด ให้เปลี่ยนที่หน้าจัดการรหัสวัสดุก่อน');
            }
        } else {
            if (!$isAdmin) {
                $undo();
                return $fail($code . ' ยังไม่ได้ผูกตัวสินค้า (LLP) — การเลือกตัวสินค้าให้รหัส Mango สงวนให้ผู้ดูแลระบบ (ADM · มติ 16) ให้ ADM ผูกก่อน');
            }
            $m = smMapLlp($pdo, $code, $llp, $user);
            if (!$m['ok']) { $undo(); return $fail($m['error']); }
            $mapped = true;
        }

        $cc = ['changed' => false, 'ic' => 0];
        if ($cat !== '' || $char !== '') {
            if (!$isAdmin) { $undo(); return $fail('ตั้งหมวดอนุมัติ/ลักษณะวัสดุ ได้เฉพาะผู้ดูแลระบบ (ADM) — มติ 16'); }
            $r = icLlpSetCharCat($pdo, $llp, $cat, $char, $user);
            if (!$r['ok']) { $undo(); return $fail($r['error']); }
            $cc = ['changed' => (bool)$r['changed'], 'ic' => (int)$r['ic']];
        }

        $c = icCreate($pdo, $p, $user);
        if (!$c['ok']) { $undo(); return $fail($c['error']); }

        $a = smAttachIc($pdo, $code, (string)$c['ic_code'], $user);
        if (!$a['ok']) { $undo(); return $fail($a['error']); }

        if ($ownTx) { $pdo->commit(); } else { $pdo->exec('RELEASE SAVEPOINT icm_create'); }
    } catch (Throwable $e) {
        $undo();
        error_log('icmCreate ' . $code . ': ' . $e->getMessage());
        return $fail('ออกรหัสไม่สำเร็จ: ' . $e->getMessage());
    }

    return ['ok' => true, 'error' => '', 'ic_code' => (string)$c['ic_code'], 'created' => !empty($c['created']),
            'attached' => !empty($a['changed']), 'mapped_llp' => $mapped, 'charcat' => $cc];
}
