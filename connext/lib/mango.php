<?php
/**
 * CONNEXT — lib/mango.php : ทะเบียนรหัสวัสดุ Mango (materials.code_type='mango')
 *
 * หลังมติ 34 (เบิกได้เฉพาะรหัส IC) รหัส Mango ไม่ใช่ของที่เบิกอีกแล้ว แต่ยัง
 * **จำเป็นอยู่** ในฐานะข้อมูลอ้างอิง 3 ทาง:
 *   1. จับคู่บรรทัดใบสั่งซื้อตอน OCR — ป้าย "พบ/ไม่พบ" + ชื่อ/หน่วยไว้เทียบ (lib/po_ocr.php)
 *   2. `ic_suggest_map` — (ผู้ขาย + MatCode) → IC ที่เคยผูก (มติ 20)
 *   3. `mango_bal.php` — รายงานแปลงรหัส Mango → IC (รับเข้ามาแล้วกลายเป็น IcCode อะไรบ้าง)
 *
 * เดิมทะเบียนนี้ "เพิ่มไม่ได้เลย" — มาจาก import ครั้งเดียวตอนตั้งระบบ 6,814 รหัส
 * ไฟล์นี้เปิดทาง 3 ทาง: หน้า `mango_master.php` (ADM) · Excel นำเข้าเป็นชุด ·
 * และปุ่มเพิ่มตรงจอตรวจใบ PO ตอนเจอรหัสที่ยังไม่มีในทะเบียน (มติ 35)
 *
 * ⚠ `cat_id`/`char_id` ของแถว Mango **ไม่มีผลกับฟอร์มเบิกแล้ว** (วัสดุจะโผล่ในฟอร์ม
 *   ก็ต่อเมื่อมีแถวใน project_materials ซึ่งเกิดเฉพาะกับรหัส IC ตอนของเข้าสต๊อกจริง)
 *   เก็บไว้เพื่อให้ทะเบียนตรงกับระบบ Mango ต้นทางเท่านั้น
 *
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/ic.php';       // IC_CAT_IDS / IC_CHAR_IDS / icNormalize*

/** ทำรหัสให้เป็นมาตรฐาน — ตัดช่องว่าง + ตัวใหญ่ (รหัส Mango เป็นตัวใหญ่ทั้งหมด) */
function mangoNormalizeCode(string $code): string {
    return strtoupper(preg_replace('/\s+/u', '', trim($code)));
}

/** เงื่อนไขกรองของจอทะเบียน — ใช้ร่วมกันระหว่างตัวอ่านแถวกับตัวนับ */
function mangoWhere(array $f): array {
    $w    = ["m.code_type = 'mango'"];
    $args = [];

    if (!empty($f['q'])) {
        $like = '%' . likeEscape((string)$f['q']) . '%';
        $w[]  = '(m.mat_code LIKE ? OR m.name LIKE ? OR m.subgroup_name LIKE ?)';
        array_push($args, $like, $like, $like);
    }
    if (($f['state'] ?? '') === 'used') {
        // เคยโผล่ในใบสั่งซื้อที่นำเข้าแล้ว
        $w[] = 'EXISTS (SELECT 1 FROM po_lines pl WHERE pl.mat_code = m.mat_code)';
    }
    if (($f['state'] ?? '') === 'noname') {
        $w[] = "(TRIM(m.name) = '' OR TRIM(m.unit) = '')";
    }
    return [implode(' AND ', $w), $args];
}

/** แถวทะเบียน + จำนวนครั้งที่ถูกอ้างในใบสั่งซื้อ */
function mangoRows(PDO $pdo, array $f = []): array {
    list($where, $args) = mangoWhere($f);

    $sql = 'SELECT m.id, m.mat_code, m.name, m.unit, m.subgroup_name, m.cat_id, m.char_id,
                   m.created_at,
                   COALESCE(l.n, 0) AS po_uses
              FROM materials m
              LEFT JOIN (SELECT mat_code, COUNT(*) n FROM po_lines
                          WHERE mat_code IS NOT NULL GROUP BY mat_code) l
                     ON l.mat_code = m.mat_code
             WHERE ' . $where . '
             ORDER BY m.mat_code';
    if (!empty($f['limit'])) {
        $sql .= ' LIMIT ' . (int)$f['limit'] . ' OFFSET ' . (int)($f['offset'] ?? 0);
    }
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

function mangoCount(PDO $pdo, array $f = []): int {
    list($where, $args) = mangoWhere($f);
    $st = $pdo->prepare('SELECT COUNT(*) FROM materials m WHERE ' . $where);
    $st->execute($args);
    return (int)$st->fetchColumn();
}

/** หนึ่งแถวตามรหัส — คืน null ถ้าไม่มี (หรือเป็นรหัส IC) */
function mangoGet(PDO $pdo, string $code): ?array {
    $st = $pdo->prepare("SELECT * FROM materials WHERE mat_code = ? AND code_type = 'mango'");
    $st->execute([mangoNormalizeCode($code)]);
    $r = $st->fetch();
    return $r ?: null;
}

/** บันทึกลง activity_log (คู่กับ admLog ของ admin.php แต่เรียกได้จากทุกหน้า) */
function mangoLog(PDO $pdo, ?array $user, $id, string $act, $old, $new): void {
    try {
        $st = $pdo->prepare(
            'INSERT INTO activity_log (entity_type, entity_id, user_name, action, old_value, new_value)
             VALUES (?,?,?,?,?,?)'
        );
        $st->execute([
            'material', (string)$id,
            $user !== null ? (string)($user['username'] ?? '') : '',
            $act,
            $old !== null ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
            $new !== null ? json_encode($new, JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (Throwable $e) {
        error_log('mangoLog: ' . $e->getMessage());   // log พลาดห้ามล้มงานหลัก
    }
}

/**
 * เพิ่ม/แก้รหัส Mango หนึ่งตัว
 *
 * @param array $p mat_code, name, unit, subgroup_name, cat_id, char_id
 * @param bool  $allowUpdate false = มีอยู่แล้วให้ข้าม (ใช้ตอนเพิ่มจากใบ PO — ห้ามทับของเดิม)
 * @return array ['ok','error','action'('insert'|'update'|'same'|'skip'),'id','code']
 */
function mangoUpsert(PDO $pdo, array $p, ?array $user = null, bool $allowUpdate = true): array {
    $fail = function (string $m) {
        return ['ok' => false, 'error' => $m, 'action' => '', 'id' => 0, 'code' => ''];
    };

    $code = mangoNormalizeCode((string)($p['mat_code'] ?? ''));
    $name = trim((string)($p['name'] ?? ''));
    $unit = trim((string)($p['unit'] ?? ''));
    $sub  = trim((string)($p['subgroup_name'] ?? ''));

    if ($code === '')            { return $fail('ต้องมีรหัสวัสดุ'); }
    if (mb_strlen($code) > 50)   { return $fail('รหัส ' . $code . ' ยาวเกิน 50 ตัวอักษร'); }
    if (mb_strlen($name) > 255)  { return $fail('ชื่อวัสดุยาวเกิน 255 ตัวอักษร'); }
    if (mb_strlen($unit) > 50)   { return $fail('หน่วยยาวเกิน 50 ตัวอักษร'); }

    // cat/char เป็นข้อมูลอ้างอิงเฉย ๆ — ว่างได้ แต่ถ้ากรอกมาต้องอยู่ในลิสต์
    $catRaw = trim((string)($p['cat_id'] ?? ''));
    $chrRaw = trim((string)($p['char_id'] ?? ''));
    $cat = $catRaw === '' ? null : icNormalizeCat($catRaw);
    $chr = $chrRaw === '' ? null : icNormalizeChar($chrRaw);
    if ($catRaw !== '' && $cat === null) {
        return $fail('CatID "' . $catRaw . '" ใช้ไม่ได้ (ต้องเป็น ' . implode(' / ', IC_CAT_IDS) . ')');
    }
    if ($chrRaw !== '' && $chr === null) {
        return $fail('CharID "' . $chrRaw . '" ใช้ไม่ได้ (ต้องเป็น ' . implode(' / ', IC_CHAR_IDS) . ')');
    }

    // รหัสนี้เป็นของ IC อยู่แล้วหรือเปล่า — ห้ามชนกันเด็ดขาด
    $st = $pdo->prepare('SELECT * FROM materials WHERE mat_code = ?');
    $st->execute([$code]);
    $cur = $st->fetch();

    if ($cur && (string)$cur['code_type'] === 'ic') {
        return $fail('รหัส ' . $code . ' เป็นรหัส IC ที่ระบบออกเอง — ใช้เป็นรหัส Mango ไม่ได้');
    }

    if ($cur) {
        if (!$allowUpdate) {
            return ['ok' => true, 'error' => '', 'action' => 'skip', 'id' => (int)$cur['id'], 'code' => $code];
        }
        $new = [
            'name'          => $name !== '' ? $name : (string)$cur['name'],
            'unit'          => $unit !== '' ? $unit : (string)$cur['unit'],
            'subgroup_name' => $sub  !== '' ? $sub  : ($cur['subgroup_name'] !== null ? (string)$cur['subgroup_name'] : null),
            'cat_id'        => $cat  !== null ? $cat : (string)$cur['cat_id'],
            'char_id'       => $chr  !== null ? $chr : ($cur['char_id'] !== null ? (string)$cur['char_id'] : null),
        ];
        $same = ((string)$cur['name'] === $new['name'])
             && ((string)$cur['unit'] === $new['unit'])
             && (($cur['subgroup_name'] !== null ? (string)$cur['subgroup_name'] : null) === $new['subgroup_name'])
             && ((string)$cur['cat_id'] === $new['cat_id'])
             && (($cur['char_id'] !== null ? (string)$cur['char_id'] : null) === $new['char_id']);
        if ($same) {
            return ['ok' => true, 'error' => '', 'action' => 'same', 'id' => (int)$cur['id'], 'code' => $code];
        }

        $up = $pdo->prepare(
            'UPDATE materials SET name = ?, unit = ?, subgroup_name = ?, cat_id = ?, char_id = ?
              WHERE id = ?'
        );
        $up->execute([$new['name'], $new['unit'], $new['subgroup_name'], $new['cat_id'], $new['char_id'], (int)$cur['id']]);
        mangoLog($pdo, $user, (int)$cur['id'], 'mango_update', $cur, $new);

        return ['ok' => true, 'error' => '', 'action' => 'update', 'id' => (int)$cur['id'], 'code' => $code];
    }

    if ($name === '') { return $fail('รหัส ' . $code . ' เป็นรหัสใหม่ — ต้องใส่ชื่อวัสดุด้วย'); }

    $ins = $pdo->prepare(
        "INSERT INTO materials (mat_code, code_type, name, unit, cat_id, char_id, subgroup_name)
         VALUES (?, 'mango', ?, ?, ?, ?, ?)"
    );
    $ins->execute([$code, $name, $unit, $cat !== null ? $cat : 'C02', $chr, $sub !== '' ? $sub : null]);
    $id = (int)$pdo->lastInsertId();
    mangoLog($pdo, $user, $id, 'mango_create',
        null, ['mat_code' => $code, 'name' => $name, 'unit' => $unit]);

    return ['ok' => true, 'error' => '', 'action' => 'insert', 'id' => $id, 'code' => $code];
}

/**
 * มติ 35 — เพิ่มรหัสที่ใบสั่งซื้อมีแต่ทะเบียนยังไม่มี
 * ใช้ตอนกดบันทึกใบคุมในจอตรวจ · **ไม่ทับของเดิม** (allowUpdate = false)
 *
 * @param array $rows [['mat_code'=>..,'name'=>..,'unit'=>..], ..]
 * @return array ['added'=>int,'skipped'=>int,'errors'=>string[]]
 */
function mangoAddFromPoLines(PDO $pdo, array $rows, ?array $user = null): array {
    $out  = ['added' => 0, 'skipped' => 0, 'errors' => []];
    $seen = [];

    foreach ($rows as $r) {
        $code = mangoNormalizeCode((string)($r['mat_code'] ?? ''));
        if ($code === '' || isset($seen[$code])) { continue; }
        $seen[$code] = true;

        $res = mangoUpsert($pdo, [
            'mat_code' => $code,
            'name'     => (string)($r['name'] ?? ''),
            'unit'     => (string)($r['unit'] ?? ''),
        ], $user, false);

        if (!$res['ok'])                    { $out['errors'][] = $res['error']; }
        elseif ($res['action'] === 'insert') { $out['added']++; }
        else                                 { $out['skipped']++; }
    }
    return $out;
}
