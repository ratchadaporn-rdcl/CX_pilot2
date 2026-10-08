<?php
/**
 * CONNEXT — api/ic_api.php : endpoint JSON ของตัวเลือก IC แบบไล่บันได
 *
 * ใช้โดยแผง "ไล่บันได" ในหน้า rc.php (และหน้าอื่นที่ต้องเลือก IC ในอนาคต)
 * ทุก action คืนโครงเดียวกัน: {ok:bool, error:string, ...ข้อมูล}
 *
 *   GET  ?a=steps&l1=&l2=&llp=&size=&brand=&unit=&extra=&q=
 *        → คืนตัวเลือกของ "ทุกขั้น" ที่เป็นไปได้จากสถานะปัจจุบันในครั้งเดียว
 *          (ยิงครั้งเดียวต่อการเลือกหนึ่งครั้ง ไม่ต้องไล่ยิงทีละชั้น)
 *   GET  ?a=search&q=            → ค้นทีเดียวได้ทั้ง IC ที่ออกแล้ว (rows)
 *                                  และตัวสินค้าในบันไดข้ามหมวด (llp)
 *        &llp=                   → จำกัดเฉพาะ IC ใต้ตัวสินค้านั้น (q ว่างได้ = ทั้งหมดใต้มัน) — ใช้ตอนเพิ่ม IC
 *                                  ให้ Mango ที่ผูก LLP ไว้แล้ว (1 Mango = 1 LLP · มติ 45)
 *   POST  a=create_ic            → ออกรหัส IC (icCreate) — CatID/CharID มาจาก LLP ไม่ใช่ฟอร์ม
 *   POST  a=set_charcat          → ตั้ง CatID/CharID ให้ตัวสินค้า (ADM เท่านั้น มติ 32)
 *   POST  a=add_llp|add_size|add_brand|add_extra  → เพิ่มชั้นบันได (ADM เท่านั้น มติ 16)
 *   POST  a=add_l1|add_l2|add_unit                → เพิ่มกลุ่มใหญ่/หมวด/หน่วยเก็บ (มติ 43 — ADM)
 *   POST  a=rename                                → แก้ชื่อชั้นบันได (ADM)
 *
 *   ── จอ "สร้างรหัส IC" ตั้งต้นจากรหัส Mango เสมอ (มติ 47 · lib/ic_mango.php) ──
 *   GET  ?a=mango_find&q=        → ค้นรหัส Mango (รหัส/ชื่อ/กลุ่มย่อย/LLP หรือ IC ที่ผูก) + สถานะการผูก
 *   GET  ?a=mango_info&mat=      → Mango หนึ่งตัว + LLP/IC ที่ผูก + ยอดค้าง + ค่าตั้งต้นของบันได
 *   POST  a=create_for_mango mat= llp= size= brand= unit= extra= ic_name= has_serial= is_cx= [cat_id= char_id=]
 *                                → ผูก LLP (ADM · ถ้ายังไม่ผูก) + ตั้ง Cat/Char (ADM) + ออก IC + ผูกกลับ Mango
 *                                  ในทรานแซกชันเดียว · Mango ผูก LLP อื่นอยู่ = ปฏิเสธ (มติ 45)
 *
 * สิทธิ์: uiApiGuard() = CanReq หรือ ADM · งานแตะบันได = ADM (R0) เท่านั้น
 * PHP 7.4-compatible เท่านั้น
 */

require __DIR__ . '/../config.php';
require __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/ui.php';
require_once __DIR__ . '/../lib/ic.php';

$user    = uiApiGuard();
$isAdmin = uiIsAdmin($user);
$a       = (string)($_REQUEST['a'] ?? '');

/** ค่าจาก request แบบ trim + ตัวใหญ่ (รหัสทั้งหมดเป็นตัวใหญ่) */
$S = function (string $k, string $def = '') {
    $v = strtoupper(trim((string)($_REQUEST[$k] ?? '')));
    return $v === '' ? $def : $v;
};

// ═══════════════════════════════════════════════════════════════════════
// GET ?a=steps — ตัวเลือกของทุกขั้นในครั้งเดียว
// ═══════════════════════════════════════════════════════════════════════
if ($a === 'steps') {
    $l1    = $S('l1');
    $l2    = $S('l2');
    $llp   = $S('llp');
    $size  = $S('size', IC_NONE);
    $brand = $S('brand', IC_NONE);
    $unit  = $S('unit');
    $extra = $S('extra', IC_NONE);
    $q     = trim((string)($_REQUEST['q'] ?? ''));

    // ── ทำสถานะให้สอดคล้องกันก่อนเสมอ: เปลี่ยนชั้นบน = ชั้นล่างที่ไม่เข้าพวกต้องหลุด ──
    if ($llp !== '' && strlen($llp) === 8) {
        // เลือกตัวสินค้ามาแล้วให้ l1/l2 เดินตามรหัสนั้น (กันสถานะค้างจากการกดสลับไปมา)
        $parts = icSplitLlp($llp);
        if ($parts !== null) { $l1 = $parts['l1_code']; $l2 = $parts['l2_code']; }
    }

    $out = [
        'ok'    => true,
        'error' => '',
        'l1'    => icL1List($pdo),
        'l2'    => $l1 !== '' ? icL2List($pdo, $l1) : [],
        'llp'   => ($l1 !== '' && $l2 !== '') ? icLlpList($pdo, $l1, $l2, $q, 400) : [],
        'size'  => ($l1 !== '' && $l2 !== '') ? icSizeList($pdo, $l1, $l2) : [],
        'brand' => ($l1 !== '' && $l2 !== '') ? icBrandList($pdo, $l1, $l2) : [],
        'unit'  => icUnitList($pdo),
        'extra' => $llp !== '' ? icExtraList($pdo, $llp) : [],
    ];

    // ตัวที่เลือกไว้หลุดจากรายการที่อนุญาตแล้ว → คืนค่าเป็น "ไม่ระบุ" ให้ฝั่งหน้าเว็บรู้
    $has = function (array $rows, string $col, string $val) {
        foreach ($rows as $r) { if ((string)$r[$col] === $val) { return true; } }
        return false;
    };
    if ($llp !== '' && !$has($out['llp'], 'llp_code', $llp) && $q === '') {
        // คำค้นทำให้รายการแคบลงได้ จึงไม่ล้างตอนมี q — ล้างเฉพาะตอนไม่ได้ค้น
        $st = $pdo->prepare('SELECT 1 FROM llp_products WHERE llp_code = ? AND is_active = 1');
        $st->execute([$llp]);
        if (!$st->fetchColumn()) { $llp = ''; $extra = IC_NONE; }
    }
    if ($size  !== IC_NONE && !$has($out['size'],  'size_code',  $size))  { $size  = IC_NONE; }
    if ($brand !== IC_NONE && !$has($out['brand'], 'brand_code', $brand)) { $brand = IC_NONE; }
    if ($extra !== IC_NONE && !$has($out['extra'], 'extra_code', $extra)) { $extra = IC_NONE; }
    if ($unit !== '' && !$has($out['unit'], 'unit_code', $unit))          { $unit  = ''; }

    $out['picked'] = [
        'l1' => $l1, 'l2' => $l2, 'llp' => $llp,
        'size' => $size, 'brand' => $brand, 'unit' => $unit, 'extra' => $extra,
    ];

    // รหัสเต็ม + ชื่อที่ระบบเสนอ — ครบทุกชั้นบังคับเมื่อไหร่ถึงประกอบได้
    $complete = ($llp !== '' && $unit !== '');
    $out['complete'] = $complete;
    $out['ic_code']  = $complete ? icCompose($llp, $size, $brand, $unit, $extra) : '';
    $out['ic_name']  = $llp !== ''
        ? icSuggestName($pdo, ['llp_code' => $llp, 'size_code' => $size,
                               'brand_code' => $brand, 'extra_code' => $extra])
        : '';

    // รหัสนี้ออกไว้แล้วหรือยัง — ออกซ้ำได้ (icCreate idempotent) แต่ควรบอกให้รู้
    $out['exists'] = false;
    if ($out['ic_code'] !== '') {
        $ex = icGet($pdo, $out['ic_code']);
        if ($ex !== null) {
            $out['exists']   = true;
            $out['exists_name'] = (string)$ex['ic_name'];
            // ตัวที่ปิดใช้งาน (เปลี่ยนสเปกไปแล้ว · มติ 46) ผูก/ใช้ต่อไม่ได้ — จอต้องบอกก่อนกด
            $out['exists_active'] = (int)$ex['is_active'] === 1;
        }
    }
    // CatID/CharID ของตัวสินค้า (มติ 28) — ยังไม่ตั้ง = ออกรหัสไม่ได้ (มติ 32)
    $out['charcat'] = ['is_set' => false, 'cat_id' => '', 'char_id' => '',
                       'cat_label' => '', 'char_label' => ''];
    if ($llp !== '') {
        $cc = icLlpCharCat($pdo, $llp);
        if ($cc !== null) {
            $catL  = icCatLabels();
            $charL = icCharLabels();
            $out['charcat'] = [
                'is_set'     => $cc['is_set'],
                'cat_id'     => (string)$cc['cat_id'],
                'char_id'    => (string)$cc['char_id'],
                'cat_label'  => $cc['cat_id']  !== null ? $catL[$cc['cat_id']]   : '',
                'char_label' => $cc['char_id'] !== null ? $charL[$cc['char_id']] : '',
            ];
        }
    }
    $out['cat_options']  = icCatLabels();
    $out['char_options'] = icCharLabels();

    $out['is_admin'] = $isAdmin;
    uiApiOut($out);
}

// ═══════════════════════════════════════════════════════════════════════
// GET ?a=search — ช่องค้นเดียวของ modal เลือกรหัส IC
//
//   rows → IC ที่ออกไว้แล้ว (ใช้ได้เลย)
//   llp  → ตัวสินค้าในบันได "ข้ามหมวด" ที่ชื่อตรงคำค้น — ยังไม่มีรหัสก็เจอ
//          คนรับของรู้ชื่อวัสดุ แต่ไม่จำเป็นต้องรู้ว่ามันอยู่ใต้ L1/L2 ไหน
//          กดแล้วจอจะพาไปไล่บันไดต่อโดยเติมชั้น 1-3 ให้แล้ว
// ═══════════════════════════════════════════════════════════════════════
if ($a === 'search') {
    $q    = trim((string)($_REQUEST['q'] ?? ''));
    $only = $S('llp');
    if (strlen($only) !== 8) { $only = ''; }
    $sql = 'SELECT i.ic_code, i.ic_name, u.unit_name
              FROM ic_items i JOIN units u ON u.unit_code = i.unit_code
             WHERE i.is_active = 1';
    $ar  = [];
    if ($only !== '') {
        $sql .= ' AND i.llp_code = ?';
        $ar[] = $only;
    }
    if ($q !== '') {
        $like = '%' . likeEscape($q) . '%';
        $sql .= ' AND (i.ic_code LIKE ? OR i.ic_name LIKE ?)';
        array_push($ar, $like, $like);
    }
    $sql .= $only !== '' ? ' ORDER BY i.ic_code LIMIT 200'
                         : ' ORDER BY i.created_at DESC LIMIT ' . ($q === '' ? 12 : 20);
    $st = $pdo->prepare($sql);
    $st->execute($ar);
    $rows = $st->fetchAll();

    $llp  = [];
    $more = 0;
    if ($q !== '' && $only === '') {
        $like  = '%' . likeEscape($q) . '%';
        $head  = likeEscape($q) . '%';
        $limit = 16;
        $st = $pdo->prepare(
            'SELECT p.llp_code, p.llp_name, p.cat_id, p.char_id, g.l1_name, c.l2_name
               FROM llp_products p
               JOIN l1_groups     g ON g.l1_code = p.l1_code
               JOIN l2_categories c ON c.l1_code = p.l1_code AND c.l2_code = p.l2_code
              WHERE p.is_active = 1 AND (p.llp_name LIKE ? OR p.llp_code LIKE ?)
              ORDER BY (p.llp_name LIKE ?) DESC, p.llp_code
              LIMIT ' . $limit
        );
        $st->execute([$like, $like, $head]);
        $llp = $st->fetchAll();

        // บอกให้รู้ว่ายังมีอีกกี่ตัวที่ไม่ได้แสดง — กันเข้าใจผิดว่ามีแค่นี้
        if (count($llp) >= $limit) {
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM llp_products
                  WHERE is_active = 1 AND (llp_name LIKE ? OR llp_code LIKE ?)'
            );
            $st->execute([$like, $like]);
            $more = max(0, (int)$st->fetchColumn() - count($llp));
        }
    }
    uiApiOut(['ok' => true, 'error' => '', 'rows' => $rows, 'llp' => $llp, 'llp_more' => $more]);
}

// ═══════════════════════════════════════════════════════════════════════
// จอ "สร้างรหัส IC" — ตั้งต้นจากรหัส Mango เสมอ (มติ 47)
// โหลด lib/ic_mango.php (+ setup_master/stock) เฉพาะ action ชุดนี้ — แผงไล่บันไดของ rc.php ไม่ต้องแบกไปด้วย
// ═══════════════════════════════════════════════════════════════════════
if (in_array($a, ['mango_find', 'mango_info', 'create_for_mango'], true)) {
    require_once __DIR__ . '/../lib/ic_mango.php';
    if (!smSchemaReady($pdo)) {
        uiApiFail('ยังไม่มีตารางผูก Mango → IC (mango_ic_map) — ให้ ADM เปิดหน้าจัดการรหัสวัสดุแล้วกดอัปเดตโครงสร้างก่อน', 409);
    }

    if ($a === 'mango_find') {
        $r = icmFind($pdo, (string)($_REQUEST['q'] ?? ''), 20);
        uiApiOut(['ok' => true, 'error' => '', 'rows' => $r['rows'], 'more' => $r['more']]);
    }

    $mat = mangoNormalizeCode((string)($_REQUEST['mat'] ?? ''));
    if ($mat === '') { uiApiFail('ต้องเริ่มจากรหัส Mango ก่อนเสมอ — เลือกรหัส Mango ในข้อ 0 (มติ 47)'); }

    if ($a === 'mango_info') {
        $info = icmInfo($pdo, $mat);
        if ($info === null) { uiApiFail('ไม่พบรหัส Mango ' . $mat . ' ในทะเบียน', 404); }
        uiApiOut(['ok' => true, 'error' => '', 'mango' => $info]);
    }

    // create_for_mango
    uiApiRequirePost();
    $r = icmCreate($pdo, $mat, [
        'llp_code'   => $S('llp'),
        'size_code'  => $S('size', IC_NONE),
        'brand_code' => $S('brand', IC_NONE),
        'unit_code'  => $S('unit'),
        'extra_code' => $S('extra', IC_NONE),
        'ic_name'    => trim((string)($_POST['ic_name'] ?? '')),
        'has_serial' => (string)($_POST['has_serial'] ?? '0'),
        'is_cx'      => (string)($_POST['is_cx'] ?? '1'),
    ], $user, $isAdmin, $S('cat_id'), $S('char_id'));
    if (!$r['ok']) { uiApiFail($r['error']); }

    $ic = icGet($pdo, $r['ic_code']);
    uiApiOut([
        'ok'         => true,
        'error'      => '',
        'ic_code'    => $r['ic_code'],
        'created'    => $r['created'],
        'attached'   => $r['attached'],
        'mapped_llp' => $r['mapped_llp'],
        'charcat'    => $r['charcat'],
        'ic_name'    => $ic !== null ? (string)$ic['ic_name']   : '',
        'unit'       => $ic !== null ? (string)$ic['unit_name'] : '',
        'mango'      => icmInfo($pdo, $mat),
    ]);
}

// ═══════════════════════════════════════════════════════════════════════
// POST — งานเขียน
// ═══════════════════════════════════════════════════════════════════════
if ($a === 'create_ic') {
    uiApiRequirePost();
    $r = icCreate($pdo, [
        'llp_code'   => $S('llp'),
        'size_code'  => $S('size', IC_NONE),
        'brand_code' => $S('brand', IC_NONE),
        'unit_code'  => $S('unit'),
        'extra_code' => $S('extra', IC_NONE),
        'ic_name'    => trim((string)($_POST['ic_name'] ?? '')),
        // cat_id/char_id ไม่รับจากฟอร์มแล้ว (มติ 28) — icCreate อ่านจาก LLP เอง
        'has_serial' => (string)($_POST['has_serial'] ?? '0'),
        'is_cx'      => (string)($_POST['is_cx'] ?? '1'),
    ], $user);
    if (!$r['ok']) { uiApiFail($r['error']); }

    $ic = icGet($pdo, $r['ic_code']);
    uiApiOut([
        'ok'      => true,
        'error'   => '',
        'ic_code' => $r['ic_code'],
        'created' => !empty($r['created']),
        'ic_name' => $ic !== null ? (string)$ic['ic_name']   : '',
        'unit'    => $ic !== null ? (string)$ic['unit_name'] : '',
    ]);
}

// ── ตั้ง CatID/CharID ให้ตัวสินค้าโดยไม่ต้องออกจากจอ — ADM เท่านั้น (มติ 32) ──
if ($a === 'set_charcat') {
    uiApiRequirePost();
    if (!$isAdmin) {
        uiApiFail('ตั้งหมวดอนุมัติ/ลักษณะวัสดุ ได้เฉพาะผู้ดูแลระบบ (ADM) — มติ 16', 403);
    }
    $prevCc = icLlpCharCat($pdo, $S('llp'));   // [2026-10-02 · GP-46] ค่าเดิม (จด + แจ้งเตือน)
    $r = icLlpSetCharCat($pdo, $S('llp'), $S('cat_id'), $S('char_id'), $user);
    if (!$r['ok']) { uiApiFail($r['error']); }
    if (!empty($r['changed'])) {
        // [2026-10-02 · GP-46] เปลี่ยนหมวดอนุมัติ/ลักษณะวัสดุ (เช่น C01 → NAR = เบิกไม่ต้องอนุมัติ) — จด activity_log + แจ้งผู้ดูแลระบบทุกครั้ง
        require_once __DIR__ . '/../lib/notify.php';
        $ccOld = $prevCc ? ($prevCc['cat_id'] . '/' . $prevCc['char_id']) : '-';
        $ccNew = $S('cat_id') . '/' . $S('char_id');
        try {
            $pdo->prepare('INSERT INTO activity_log (entity_type, entity_id, user_name, action, old_value, new_value) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute(['llp_product', $S('llp'), (string)($user['username'] ?? ''), 'charcat_change', $ccOld, $ccNew]);
        } catch (Throwable $e) {
            error_log('set_charcat log: ' . $e->getMessage());
        }
        cnxNotifyAdmins($pdo, 'material_category', 'เปลี่ยนหมวดตัวสินค้า ' . $S('llp'),
                        $ccOld . ' → ' . $ccNew . ' · รหัส IC ที่เปลี่ยนตาม ' . (int)$r['ic'] . ' รหัส', (string)($user['username'] ?? ''));
    }

    $catL  = icCatLabels();
    $charL = icCharLabels();
    $cat   = $S('cat_id');
    $char  = $S('char_id');
    uiApiOut([
        'ok'         => true,
        'error'      => '',
        'changed'    => !empty($r['changed']),
        'ic'         => (int)$r['ic'],
        'material'   => (int)$r['material'],
        'cat_id'     => $cat,
        'char_id'    => $char,
        'cat_label'  => $catL[$cat]  ?? '',
        'char_label' => $charL[$char] ?? '',
    ]);
}

// ── เพิ่มชั้นบนของบันได + หน่วยเก็บ + แก้ชื่อ (มติ 43) — ADM เท่านั้น ─────
$topActions = ['add_l1', 'add_l2', 'add_unit', 'rename'];
if (in_array($a, $topActions, true)) {
    uiApiRequirePost();
    if (!$isAdmin) {
        uiApiFail('เพิ่ม/แก้ชั้นบันได ได้เฉพาะผู้ดูแลระบบ (ADM) — มติ 16', 403);
    }
    $name = trim((string)($_POST['name'] ?? ''));

    if ($a === 'add_l1') {
        $r = icAddL1($pdo, $S('code'), $name);
        if (!$r['ok']) { uiApiFail($r['error']); }
        uiApiOut(['ok' => true, 'error' => '', 'code' => $r['code'], 'name' => $name, 'existed' => !empty($r['existed'])]);
    }
    if ($a === 'add_l2') {
        $r = icAddL2($pdo, $S('l1'), $S('code'), $name);
        if (!$r['ok']) { uiApiFail($r['error']); }
        uiApiOut(['ok' => true, 'error' => '', 'code' => $r['code'], 'name' => $name, 'existed' => !empty($r['existed'])]);
    }
    if ($a === 'add_unit') {
        $r = icAddUnit($pdo, $S('code'), $name);
        if (!$r['ok']) { uiApiFail($r['error']); }
        uiApiOut(['ok' => true, 'error' => '', 'code' => $r['code'], 'name' => $name, 'existed' => !empty($r['existed'])]);
    }
    // rename
    $r = icRenameNode($pdo, strtolower(trim((string)($_POST['kind'] ?? ''))),
                      ['l1' => $S('l1'), 'l2' => $S('l2'), 'llp' => $S('llp'), 'code' => $S('code')], $name);
    if (!$r['ok']) { uiApiFail($r['error']); }
    uiApiOut(['ok' => true, 'error' => '', 'changed' => !empty($r['changed']), 'name' => $name]);
}

// ── งานแตะบันได — ADM เท่านั้น (มติ 16) ────────────────────────────────
$ladderActions = ['add_llp', 'add_size', 'add_brand', 'add_extra'];
if (in_array($a, $ladderActions, true)) {
    uiApiRequirePost();
    if (!$isAdmin) {
        uiApiFail('เพิ่มตัวสินค้า/ขนาด/ยี่ห้อ/คุณสมบัติ ได้เฉพาะผู้ดูแลระบบ (ADM) — มติ 16', 403);
    }
    $name = trim((string)($_POST['name'] ?? ''));

    if ($a === 'add_llp') {
        $r = icAddLlp($pdo, $S('l1'), $S('l2'), $name, $user);
        if (!$r['ok']) { uiApiFail($r['error']); }
        uiApiOut(['ok' => true, 'error' => '', 'code' => $r['llp_code'], 'name' => $name]);
    }

    if ($a === 'add_size' || $a === 'add_brand') {
        // $code ว่าง = ไล่เลขให้ · ระบุเอง = ใช้รหัสนั้น (ยังไม่มีในพจนานุกรมก็สร้างให้ — มติ 43)
        $kind = $a === 'add_size' ? 'size' : 'brand';
        $r = icAttachToL2($pdo, $kind, $S('l1'), $S('l2'), $S('code'), $name);
        if (!$r['ok']) { uiApiFail($r['error']); }
        uiApiOut(['ok' => true, 'error' => '', 'code' => $r['code'], 'name' => $name]);
    }

    // add_extra
    $r = icAddExtra($pdo, $S('llp'), $name);
    if (!$r['ok']) { uiApiFail($r['error']); }
    uiApiOut(['ok' => true, 'error' => '', 'code' => $r['code'], 'name' => $name]);
}

uiApiFail('ไม่รู้จัก action "' . $a . '"', 404);
