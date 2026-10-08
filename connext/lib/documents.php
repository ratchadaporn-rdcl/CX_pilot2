<?php
/**
 * CONNEXT — lib/documents.php : ส่งเอกสารเบิก/ยืม/รับเข้า + วงจรยืม-คืน
 *
 * Port 1:1 จาก GAS Code.js (ล้อพฤติกรรมเดิมเป๊ะ ทั้งค่าที่คืนและข้อความ error):
 *   processRequisitionSubmission / processOddsSubmission /
 *   processBorrowSubmission (single legacy) / processBorrowBatch /
 *   processInboundBatch / getUnreturnedItems / returnBorrowItem /
 *   registerReturnGateLog / confirmReturnAtGate
 *
 * กติกาเลขเอกสาร (mirror GAS):
 *   - เลขรัน (XX) เพิ่มทีละ 1 "ต่อผู้รับหนึ่งราย" — ทุก gate group ของผู้รับ
 *     รายเดียวกันใช้ base เดียวกัน ต่างกันแค่ suffix Gxx
 *     (RD/OD/BD batch: running += 1 ต่อ receiver; IN/BD เดี่ยว: ครั้งเดียวทั้งใบ)
 *   - จึงเรียก _docCounterNext ตรง ๆ ต่อผู้รับ แทน nextDocNo ต่อเอกสาร
 *     (nextDocNo จะเพิ่มเลขรันต่อ "เอกสาร" ซึ่งไม่ตรงกับ GAS เมื่อผู้รับรายเดียว
 *     แตกหลาย gate)
 *
 * ตัวตน SERVER-AUTHORITATIVE: role level ของผู้ส่งดึงจาก session + DB
 * (mirror getUserRoleLevel_) — ไม่เชื่อ role/AutoApprove ที่ client ส่งมา
 *
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/docnum.php';
require_once __DIR__ . '/borrow.php';   // [Scenario 05 · 2026-09-29] กำหนดวันคืน · ยอดค้างคืน · ตีชำรุด/สูญหาย

// =========================================================================
// helpers ภายใน
// =========================================================================

/**
 * ระดับ role ของผู้ใช้ใน session (mirror getUserRoleLevel_ ของ GAS):
 * user → level จาก roles · subcontractor → 1 · ไม่พบ → 99
 */
function _docsRoleLevel(PDO $pdo, ?array $user): int {
    if (!$user) {
        return 99;
    }
    if (($user['accountType'] ?? '') === 'subcontractor') {
        return 1;
    }
    $st = $pdo->prepare('SELECT r.level FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
    $st->execute([(int)($user['accountId'] ?? 0)]);
    $lvl = $st->fetchColumn();
    if ($lvl === false) {
        // สำรอง: หาโดย username (พฤติกรรม getUserRoleLevel_ ที่คีย์ด้วย username)
        $st = $pdo->prepare('SELECT r.level FROM users u JOIN roles r ON r.id = u.role_id WHERE u.username = ? LIMIT 1');
        $st->execute([trim((string)($user['username'] ?? ''))]);
        $lvl = $st->fetchColumn();
    }
    return $lvl === false ? 99 : (int)$lvl;
}

/** ชื่อที่ใช้เก็บ/กรองแทนตัวผู้ใช้ (mirror getEffectiveUserName ฝั่ง client:
 *  SUB ใช้ SubName (fullName), อื่นๆ ใช้ username) */
function _docsEffectiveUsername(?array $user): string {
    if (!$user) {
        return '';
    }
    if (($user['accountType'] ?? '') === 'subcontractor') {
        $n = (string)($user['fullName'] ?? '');
        return $n !== '' ? $n : (string)($user['username'] ?? '');
    }
    return (string)($user['username'] ?? '');
}

/**
 * แผนที่ผู้รับเหมาทั้งหมด (mirror getSubNameById_: ทุกแถว ไม่กรอง status,
 * แถวหลังทับแถวก่อน) → [byCode: sub_code→{id,name}, byName: lower(name)→id]
 */
function _docsSubMaps(PDO $pdo): array {
    $byCode = [];
    $byName = [];
    $st = $pdo->query('SELECT id, sub_code, name FROM subcontractors ORDER BY id');
    while ($r = $st->fetch()) {
        $code = fmtSubId((string)$r['sub_code']);
        $name = trim((string)$r['name']);
        if ($code !== '') {
            $byCode[$code] = ['id' => (int)$r['id'], 'name' => ($name !== '' ? $name : $code)];
        }
        if ($name !== '') {
            $byName[mb_strtolower($name, 'UTF-8')] = (int)$r['id'];
        }
    }
    return [$byCode, $byName];
}

/**
 * แปลงค่า Receiver ดิบ → [ชื่อที่เก็บ, receiver_sub_id|null]
 * (mirror _receiverToName_: 'DC:' คงเดิม · SubID ที่รู้จัก → SubName ·
 *  อื่นๆ คงค่าเดิม — เพิ่มการ link id เมื่อค่าดิบตรงกับชื่อผู้รับเหมาอยู่แล้ว)
 */
function _docsResolveReceiver(string $raw, array $subMaps): array {
    $s = trim($raw);
    if ($s === '') {
        return ['', null];
    }
    if (str_starts_with($s, 'DC:')) {
        return [$s, null];
    }
    list($byCode, $byName) = $subMaps;
    $code = fmtSubId($s);
    if (isset($byCode[$code])) {
        return [$byCode[$code]['name'], $byCode[$code]['id']];
    }
    $k = mb_strtolower($s, 'UTF-8');
    if (isset($byName[$k])) {
        return [$s, $byName[$k]]; // เป็นชื่อผู้รับเหมาอยู่แล้ว — คงค่าเดิม + link id
    }
    return [$s, null];
}

/** master วัสดุตามชุด mat_code → matCode(trimmed) → {id,name,unit,cat,type} */
function _docsMaterialsMap(PDO $pdo, array $codes): array {
    $clean = [];
    foreach ($codes as $c) {
        $c = trim((string)$c);
        if ($c !== '') {
            $clean[$c] = true;
        }
    }
    if (!$clean) {
        return [];
    }
    $codesList = array_keys($clean);
    $ph = implode(',', array_fill(0, count($codesList), '?'));
    $st = $pdo->prepare("SELECT id, mat_code, name, unit, cat_id, char_id, code_type FROM materials WHERE mat_code IN ($ph)");
    $st->execute($codesList);
    $map = [];
    while ($r = $st->fetch()) {
        $map[trim((string)$r['mat_code'])] = [
            'id'   => (int)$r['id'],
            'name' => (string)$r['name'],
            'unit' => (string)$r['unit'],
            'cat'  => strtoupper(trim((string)$r['cat_id'])),
            'char' => strtoupper(trim((string)($r['char_id'] ?? ''))),
            'type' => (string)$r['code_type'],
        ];
    }
    return $map;
}

/**
 * [Scenario 05 · 2026-09-28] วัสดุ NAR (เบ็ดเตล็ด) เบิกได้ทางใบ OD เท่านั้น — ใบ RD/BD ไม่มีวัสดุ NAR
 * และใบ OD ใช้กับวัสดุ NAR เท่านั้น (ไม่มีขั้นอนุมัติ — วัสดุหลักต้องไปทางใบ RD ที่ผ่าน R6+)
 * "NAR" = ลักษณะ (char_id) ตามตัวกรองดรอปดาวน์ของฟอร์ม · หรือหมวด (cat_id) NAR
 * ปฏิเสธทั้งใบก่อนเขียนอะไร · null = ผ่าน
 */
function _docsNarRuleError(array $items, string $type, array $matMap): ?string {
    $bad = [];
    foreach ($items as $it) {
        $mc = trim((string)(is_array($it) ? ($it['MatCode'] ?? '') : ''));
        if ($mc === '' || !isset($matMap[$mc])) { continue; }
        $isNar = $matMap[$mc]['char'] === 'NAR' || $matMap[$mc]['cat'] === 'NAR';
        if (($type === Doc::TYPE_RD || $type === Doc::TYPE_BD) && $isNar) { $bad[$mc] = true; }
        if ($type === Doc::TYPE_OD && !$isNar) { $bad[$mc] = true; }
    }
    if (!$bad) { return null; }
    if ($type === Doc::TYPE_OD) {
        return 'ใบเบิกเบ็ดเตล็ด (OD) ใช้กับวัสดุ NAR เท่านั้น — ' . implode(', ', array_keys($bad))
             . ' เป็นวัสดุหลัก ให้เบิกทางแท็บ "เบิกวัสดุหลัก" (ต้องผ่านผู้อนุมัติ R6 ขึ้นไป)';
    }
    return 'วัสดุ NAR (เบ็ดเตล็ด) เบิกได้ทางใบ OD เท่านั้น — ' . implode(', ', array_keys($bad))
         . ' ให้เบิกทางแท็บ "เบิกวัสดุเบ็ดเตล็ด"';
}

/**
 * เอกสารใหม่ทุกชนิด (RD/OD/BD/IN) รับเฉพาะรหัส IC — มติ 34 บังคับทั้งแอปตั้งแต่ 2026-09-23
 *
 * ดรอปดาวน์ในแอปหลักคืนเฉพาะรหัส IC แล้ว (directory.php) ตรงนี้กันอีกชั้นสำหรับตะกร้าที่ค้าง
 * ในเครื่อง / แคชรุ่นก่อน / ยิง RPC ตรง — ล้อมติ 30 "error ชัดเจน ไม่ fallback เงียบ"
 * ปฏิเสธทั้งใบก่อนเขียนอะไร: รหัส Mango (ยังไม่ผูก IC/ย้ายยอด) · รหัสที่ไม่มีในทะเบียน · ช่องว่าง
 * ใบยืมเดิมที่ยังค้างรหัส Mango คืนของได้ตามปกติ — ทางคืนไม่ผ่านฟังก์ชันนี้
 *
 * @param array|null $matMap ส่ง map ที่มีอยู่แล้วมาได้ (ไม่งั้นดึงใหม่)
 * @return string|null ข้อความ error ภาษาไทย · null = ผ่าน
 */
function _docsIcOnlyError(PDO $pdo, array $items, ?array $matMap = null): ?string {
    $codes = [];
    foreach ($items as $it) {
        $codes[] = trim((string)(is_array($it) ? ($it['MatCode'] ?? '') : ''));
    }
    if ($matMap === null) {
        $matMap = _docsMaterialsMap($pdo, $codes);
    }
    $mango   = [];
    $unknown = [];
    foreach ($codes as $mc) {
        if ($mc === '' || !isset($matMap[$mc])) {
            $unknown[$mc === '' ? '(ว่าง)' : $mc] = true;
        } elseif ($matMap[$mc]['type'] !== 'ic') {
            $mango[$mc] = true;
        }
    }
    if ($mango) {
        return 'รหัส ' . implode(', ', array_keys($mango)) . ' ยังเป็นรหัส Mango — ระบบเบิก/รับเข้าได้เฉพาะรหัส IC '
             . 'ให้ผู้ดูแลระบบผูกรหัส IC แล้วย้ายยอดที่หน้า "จัดการรหัสวัสดุ" ก่อน '
             . '(เอารายการนี้ออกจากตะกร้าแล้วโหลดหน้าใหม่)';
    }
    if ($unknown) {
        return 'ไม่พบรหัสวัสดุในทะเบียน: ' . implode(', ', array_keys($unknown))
             . ' — โหลดหน้าใหม่แล้วเลือกวัสดุอีกครั้ง';
    }
    return null;
}

/**
 * แผนที่ GateID/SiteCode ต่อ MatCode (mirror lookup ชีต SiteMaterials —
 * ในสคีมาใหม่จำกัดที่โครงการของผู้ใช้ แทนการ scan ทุก site ของ GAS)
 * → [gateMap: matCode→gateCode(''), siteMap: matCode→projectCode]
 */
function _docsGateSiteMaps(PDO $pdo, int $projectId, string $projectCode): array {
    $gateMap = [];
    $siteMap = [];
    if ($projectId <= 0) {
        return [$gateMap, $siteMap];
    }
    $st = $pdo->prepare(
        'SELECT m.mat_code, g.gate_code
           FROM project_materials pm
           JOIN materials m ON m.id = pm.material_id
           LEFT JOIN gates g ON g.id = pm.gate_id
          WHERE pm.project_id = ?'
    );
    $st->execute([$projectId]);
    while ($r = $st->fetch()) {
        $code = trim((string)$r['mat_code']);
        if ($code === '') {
            continue;
        }
        $gateMap[$code] = trim((string)($r['gate_code'] ?? ''));
        $siteMap[$code] = $projectCode;
    }
    return [$gateMap, $siteMap];
}

/** projects.code → id (fallback = โครงการใน session เมื่อว่าง/ไม่พบ) */
function _docsResolveProject(PDO $pdo, string $siteCode, int $fallbackProjectId): int {
    static $cache = [];
    $siteCode = trim($siteCode);
    if ($siteCode !== '') {
        if (!array_key_exists($siteCode, $cache)) {
            $st = $pdo->prepare('SELECT id FROM projects WHERE code = ?');
            $st->execute([$siteCode]);
            $id = $st->fetchColumn();
            $cache[$siteCode] = ($id === false) ? null : (int)$id;
        }
        if ($cache[$siteCode] !== null) {
            return $cache[$siteCode];
        }
    }
    return $fallbackProjectId;
}

/** gates.gate_code + project → id (null เมื่อไม่พบ — เลขเอกสารยังคง suffix ตามเดิม) */
function _docsGateIdByCode(PDO $pdo, int $projectId, string $gateCode): ?int {
    static $cache = [];
    $gateCode = trim($gateCode);
    if ($gateCode === '' || $projectId <= 0) {
        return null;
    }
    $key = $projectId . '|' . $gateCode;
    if (!array_key_exists($key, $cache)) {
        $st = $pdo->prepare('SELECT id FROM gates WHERE gate_code = ? AND project_id = ?');
        $st->execute([$gateCode, $projectId]);
        $id = $st->fetchColumn();
        $cache[$key] = ($id === false) ? null : (int)$id;
    }
    return $cache[$key];
}

/** สร้างแถว gate_logs 'Awaiting' (mirror _ensureGateLogAwaiting_ — idempotent ด้วย unique doc_no) */
function _docsEnsureGateLogAwaiting(PDO $pdo, int $projectId, string $docNo, ?int $documentId, ?int $gateId): void {
    $st = $pdo->prepare(
        "INSERT IGNORE INTO gate_logs (project_id, doc_no, document_id, leg, gate_id, status)
         VALUES (?, ?, ?, 'out', ?, ?)"
    );
    $st->execute([$projectId, trim($docNo), $documentId, $gateId, Gate::ST_AWAITING]);
}

/** insert หัวเอกสาร → document id */
function _docsInsertDocument(PDO $pdo, array $d): int {
    $st = $pdo->prepare(
        'INSERT INTO documents
            (doc_no, doc_type, project_id, requester_username, receiver_name, receiver_sub_id,
             gate_id, usage_area, notice, approver_username, status, rs_no, doc_ts)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([
        $d['doc_no'], $d['doc_type'], $d['project_id'], $d['requester'],
        $d['receiver_name'], $d['receiver_sub_id'], $d['gate_id'],
        $d['usage_area'], $d['notice'], $d['approver'], $d['status'],
        $d['rs_no'], $d['doc_ts'],
    ]);
    return (int)$pdo->lastInsertId();
}

/** insert รายการวัสดุของเอกสาร */
function _docsInsertItem(PDO $pdo, int $documentId, array $it): void {
    $st = $pdo->prepare(
        'INSERT INTO document_items
            (document_id, material_id, mat_code, mat_name, unit, qty,
             usage_area, notice, rs_no, charge_money, stock_deducted)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)'
    );
    $st->execute([
        $documentId, $it['material_id'], $it['mat_code'], $it['mat_name'], $it['unit'],
        $it['qty'], $it['usage_area'], $it['notice'], $it['rs_no'], $it['charge'],
    ]);
}

/**
 * [Scenario 05 ③ · 2026-09-29] ใบยืมทุกใบต้องระบุกำหนดวันคืน (item.DueDate · ตั้งแต่วันนี้เป็นต้นไป)
 * ตรวจทุกรายการก่อนเขียนอะไร → ข้อความ error ภาษาไทย · null = ผ่าน
 */
function _docsBorrowDueError(array $items): ?string {
    foreach ($items as $it) {
        list(, $err) = borrowCheckDueDate(is_array($it) ? ($it['DueDate'] ?? '') : '');
        if ($err !== null) {
            return $err . ' — หน้าจอรุ่นเก่าไม่มีช่องกำหนดวันคืน ให้รีเฟรชหน้าแล้วเลือกวันคืนก่อนกดยืนยัน';
        }
    }
    return null;
}

/** กำหนดวันคืนของใบ (กลุ่มรายการที่แตกเป็นใบเดียว) = วันที่เร็วสุดของรายการ · ไม่มี → null */
function _docsGroupDueDate(array $items): ?string {
    $min = null;
    foreach ($items as $it) {
        $d = borrowParseDate(is_array($it) ? ($it['DueDate'] ?? '') : '');
        if ($d !== null && ($min === null || $d < $min)) { $min = $d; }
    }
    return $min;
}

/** จัดกลุ่มตาม Receiver ดิบ (trim) — รักษาลำดับพบครั้งแรก (mirror receiverOrder ของ GAS) */
function _docsGroupByReceiver(array $items): array {
    $order = [];
    $groups = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            $item = [];
        }
        $rk = trim((string)($item['Receiver'] ?? ''));
        if (!array_key_exists($rk, $groups)) {
            $groups[$rk] = [];
            $order[] = $rk;
        }
        $groups[$rk][] = $item;
    }
    return [$order, $groups];
}

/** เลข JSON แบบเดียวกับที่ชีตส่ง (5 ไม่ใช่ 5.0) */
function _docsNum($v) {
    $f = (float)$v;
    if (floor($f) == $f && abs($f) <= PHP_INT_MAX) {
        return (int)$f;
    }
    return $f;
}

/** ทำ argument รายการ items จาก client ให้เป็น array ของ array */
function _docsItemsArg(array $args): array {
    $items = (isset($args[0]) && is_array($args[0])) ? array_values($args[0]) : [];
    foreach ($items as $i => $it) {
        if (!is_array($it)) {
            $items[$i] = [];
        }
    }
    return $items;
}

// =========================================================================
// ประตู/ไซต์ของรายการ + ตรวจของที่ประตู — [PHP port 2026-09-25 per-gate · มติ 51]
// =========================================================================

/**
 * ประตูและไซต์ของรายการหนึ่ง → [gateCode, siteCode]
 *   item.GateID (ผู้ใช้เลือกในฟอร์ม) ก่อน แล้วค่อย SiteMaterials (ประตูตั้งต้นของวัสดุ)
 *   OD: เดิม gate มาจาก SiteMaterials เท่านั้น — ตอนนี้วัสดุตัวเดียวกันอยู่ได้หลายประตู
 *   ฟอร์มเบ็ดเตล็ดจึงเลือกประตูเองได้เหมือน RD/BD (ต่างจาก GAS — จดไว้ใน CHANGES-FROM-GAS.md)
 *   · SiteMaterials ยังเป็นค่าสำรองเมื่อผู้ใช้ไม่ได้เลือก · ลำดับ siteCode ต่างกันตามชนิด (mirror GAS)
 */
function _docsItemGateSite(array $item, string $type, array $gateMap, array $siteMap): array {
    $mc = (string)($item['MatCode'] ?? '');
    $g  = (string)($item['GateID'] ?? '');
    if ($g === '') {
        $g = (string)($gateMap[$mc] ?? '');
    }
    $g = trim($g);
    if ($type === Doc::TYPE_OD) {
        $sc = (string)($siteMap[$mc] ?? '');
        if ($sc === '') {
            $sc = (string)($item['SiteCode'] ?? '');
        }
    } else {
        $sc = (string)($item['SiteCode'] ?? '');
        if ($sc === '') {
            $sc = (string)($siteMap[$mc] ?? '');
        }
        $sc = trim($sc);
    }
    return [$g, $sc];
}

/**
 * ตรวจว่าของ "ที่ประตูที่เลือก" พอสำหรับทั้งตะกร้าหรือไม่ (RD/OD/BD — มติ 51)
 *   รวมความต้องการต่อ (โครงการ, ประตู, วัสดุ) แล้วเทียบ on_hand − pending ของประตูนั้น
 *   (pending = ยอดจองของใบก่อนหน้าที่ยังไม่ได้รับของ — กติกาเดียวกับที่ฟอร์มใช้ตัดสิน)
 *   รายการที่ไม่รู้ประตู ('' — ไม่ได้เลือกและวัสดุไม่มีประตูตั้งต้น) ไม่ตรวจ (พฤติกรรมเดิม)
 *   วัสดุที่ยังไม่มีแถวรายประตูเลย (ข้อมูลยุคก่อนแยกประตู) → เทียบยอดรวมไซต์แทน
 *   ล็อกแถวยอด (FOR UPDATE) — เรียกภายในทรานแซกชันของผู้ส่งใบ แถวถูกล็อกจนออกใบเสร็จ
 * @return string|null ข้อความสำหรับผู้ใช้เมื่อของไม่พอ (ยังไม่มีอะไรถูกเขียน) · null = ผ่าน
 */
function _docsGateStockGuard(PDO $pdo, array $items, string $type, array $gateMap, array $siteMap, array $matMap, int $sessionProjectId): ?string {
    // + TD เบิกโอนย้ายข้ามไซต์ (2026-09-29 · lib/transfer.php) ตรวจของที่ประตูต้นทางเหมือนใบเบิก
    if ($type !== Doc::TYPE_RD && $type !== Doc::TYPE_OD && $type !== Doc::TYPE_BD && $type !== 'TD') { return null; }
    $need = []; // "projectId|GATE|matCode" → qty รวม
    foreach ($items as $item) {
        if (!is_array($item)) { continue; }
        $mc  = trim((string)($item['MatCode'] ?? ''));
        $qty = (float)($item['Qty'] ?? 0);
        if ($mc === '' || $qty <= 0 || !isset($matMap[$mc])) { continue; }
        list($g, $sc) = _docsItemGateSite($item, $type, $gateMap, $siteMap);
        if ($g === '') { continue; }
        $pid = _docsResolveProject($pdo, trim($sc), $sessionProjectId);
        $k   = $pid . '|' . strtoupper($g) . '|' . $mc;
        $need[$k] = ($need[$k] ?? 0.0) + $qty;
    }
    if (!$need) { return null; }

    $short = [];
    foreach ($need as $k => $q) {
        list($pid, $g, $mc) = explode('|', $k, 3);
        $mid = (int)$matMap[$mc]['id'];
        $ga  = stockGateAvail($pdo, (int)$pid, $mid, $g, true);
        if (!$ga['any']) {
            $bs = $pdo->prepare('SELECT on_hand, pending FROM stock_balances WHERE project_id = ? AND material_id = ? FOR UPDATE');
            $bs->execute([(int)$pid, $mid]);
            $b = $bs->fetch();
            $avail = $b ? (float)$b['on_hand'] - (float)$b['pending'] : 0.0;
            if ($q > $avail + 0.0005) {
                $short[] = $mc . ' (ต้องการ ' . stockNumStr($q) . ' พร้อมเบิก ' . stockNumStr(max(0, $avail)) . ')';
            }
            continue;
        }
        if ($q > $ga['avail'] + 0.0005) {
            $short[] = $mc . ' (ต้องการ ' . stockNumStr($q) . ' พร้อมเบิกที่ประตู ' . $g . ' ' . stockNumStr(max(0, $ga['avail']))
                     . ($ga['pending'] > 0 ? ' — ในคลัง ' . stockNumStr($ga['on_hand']) . ' จองไว้ ' . stockNumStr($ga['pending']) : '')
                     // [2026-10-02 · GP-42] ยอดไม่พอ: ใบที่อนุมัติแล้วจองเกินของที่มี / ยอดติดลบ — บอกให้ชัด (หน้าจอแสดงสีแดง)
                     . ($ga['on_hand'] < -0.0005 ? ' · ยอดติดลบ ' . stockNumStr($ga['on_hand'])
                        : ($ga['avail'] < -0.0005 ? ' · จองเกินของที่มี ' . stockNumStr(-$ga['avail']) : ''))
                     . ')';
        }
    }
    if ($short) {
        return 'ของที่ประตูไม่พอ — ' . implode(', ', $short)
             . "\nเลือกประตูอื่นที่มีของ หรือลดจำนวน แล้วส่งใหม่";
    }
    return null;
}

// =========================================================================
// แกนกลางส่งเอกสารแบบแตกใบตามผู้รับ → gate (RD / OD / BD batch)
// =========================================================================

/**
 * mirror โครง processRequisitionSubmission / processOddsSubmission /
 * processBorrowBatch: แตกใบต่อผู้รับ (เลขรันต่อผู้รับ) แล้วต่อ GateID
 * คืน ['base' => เลข base ใบแรก, 'docs' => [เลขเอกสารทั้งหมด]]
 */
/** ชื่อผู้ส่งจาก session (2026-10-02): ผู้รับเหมา = ชื่อผู้รับเหมา (fullName) · ผู้ใช้ = username */
function _docsSessionName(?array $user): string {
    if (!$user) { return ''; }
    if (($user['accountType'] ?? '') === 'subcontractor') { return trim((string)($user['fullName'] ?? '')); }
    return trim((string)($user['username'] ?? ''));
}

/** ผู้ส่งเป็น PM (role PM · active) ของไซต์ของใบ — อนุมัติตัวเองได้ (GP-16) */
function _docsIsSitePm(PDO $pdo, ?array $user, int $projectId): bool {
    if (!$user || ($user['accountType'] ?? '') === 'subcontractor') { return false; }
    return docExtIsProjectPm($pdo, trim((string)($user['username'] ?? '')), $projectId);
}

/**
 * ผู้อนุมัติที่เลือกต้องเป็นคนอื่น ของไซต์เดียวกัน ยังใช้งาน และระดับถึงเกณฑ์ (GP-16 · 2026-10-02)
 * — เดิม server เก็บค่าที่หน้าจอส่งมาเลย ส่งตรงด้วยชื่อตัวเอง/ว่าง/ชื่อที่ไม่มีอยู่ได้
 */
function _docsApproverError(PDO $pdo, string $approver, string $submitter, int $projectId, int $minLevel): ?string {
    if ($approver === '') {
        return 'ต้องเลือกผู้อนุมัติ (R' . $minLevel . ' ขึ้นไป)';   // [2026-10-06] RD ของ R6 ขึ้นไป / PM อนุมัติอัตโนมัติ — มาถึงตรงนี้ = ต้องเลือก
    }
    if ($submitter !== '' && eqUser($approver, $submitter)) {
        return 'เลือกตัวเองเป็นผู้อนุมัติไม่ได้ — ต้องให้คนอื่นอนุมัติ';
    }
    $st = $pdo->prepare("SELECT r.level FROM users u JOIN roles r ON r.id = u.role_id
                          WHERE u.username = ? AND u.project_id = ? AND u.status <> 'inactive' LIMIT 1");
    $st->execute([$approver, $projectId]);
    $lvl = $st->fetchColumn();
    if ($lvl === false) {
        return 'ไม่พบผู้อนุมัติ "' . $approver . '" ในไซต์นี้ — เลือกผู้อนุมัติใหม่';
    }
    if ((int)$lvl < $minLevel || (int)$lvl >= 99) {
        return 'ผู้อนุมัติ "' . $approver . '" มีสิทธิ์ไม่ถึง R' . $minLevel . ' — เลือกผู้อนุมัติใหม่';
    }
    return null;
}

function _docsSubmitReceiverSplit(PDO $pdo, ?array $user, array $items, string $type): array {
    $sessionProjectId   = (int)($user['projectId'] ?? 0);
    $sessionProjectCode = (string)($user['siteCode'] ?? '');

    // [2026-10-02 · GP-16/GP-10] ผู้ส่ง = บัญชีที่ล็อกอิน (ไม่เชื่อ UserName ที่หน้าจอส่งมา)
    $submitter     = _docsSessionName($user);
    if ($submitter === '') { $submitter = (string)(isset($items[0]['UserName']) ? $items[0]['UserName'] : ''); }
    $submitterRole = _docsRoleLevel($pdo, $user);   // server-authoritative

    $allCodes = [];
    foreach ($items as $it) {
        $allCodes[] = (string)($it['MatCode'] ?? '');
    }
    $matMap  = _docsMaterialsMap($pdo, $allCodes);
    list($gateMap, $siteMap) = _docsGateSiteMaps($pdo, $sessionProjectId, $sessionProjectCode);
    $subMaps = _docsSubMaps($pdo);

    // กติกาทั้งตะกร้า: มี C01 ที่ใดก็ตาม → ทุกใบที่แตกออกมาต้อง R6+ (RD/BD)
    $submissionHasC01 = false;
    foreach ($items as $it) {
        $mc = (string)($it['MatCode'] ?? '');
        if (isset($matMap[$mc]) && $matMap[$mc]['cat'] === 'C01') {
            $submissionHasC01 = true;
            break;
        }
    }

    list($order, $groups) = _docsGroupByReceiver($items);

    $now     = nowBkk()->format('Y-m-d H:i:s');   // timestamp เดียวทั้ง submission (mirror GAS)
    $dateKey = docDateKey();

    $createdDocs = [];   // {docNo, docId, projectId, gateId, isAuto}
    $baseFirst   = null;
    $projectsTouched = [];

    // NAR เบิกได้ทางใบ OD เท่านั้น / OD ใช้กับ NAR เท่านั้น (Scenario 05) — ก่อนเขียนอะไร
    $narErr = _docsNarRuleError($items, $type, $matMap);
    if ($narErr !== null) {
        return ['base' => '', 'docs' => [], 'error' => $narErr];
    }

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) { $pdo->beginTransaction(); }
    try {
        // ของที่ประตูที่เลือกพอไหม (มติ 51) — ตรวจก่อนออกใบ แถวยอดถูกล็อกไว้จนคอมมิต
        // ไม่พอ → คืน error (ไม่โยน exception) ผู้เรียกที่ซ้อนทรานแซกชันอยู่จึงไม่ถูก rollback ทั้งก้อน
        $gateErr = _docsGateStockGuard($pdo, $items, $type, $gateMap, $siteMap, $matMap, $sessionProjectId);
        if ($gateErr !== null) {
            if ($ownTx) { $pdo->rollBack(); }
            return ['base' => '', 'docs' => [], 'error' => $gateErr];
        }

        foreach ($order as $receiverKey) {
            // เลขรันหนึ่งชุดต่อผู้รับหนึ่งราย (mirror running += 1 ต่อ receiver)
            $n    = _docCounterNext($pdo, $type, $dateKey);
            $base = $type . $dateKey . _docRunningStr($n);
            if ($baseFirst === null) {
                $baseFirst = $base;
            }

            // แตกตาม GateID — รักษาลำดับพบครั้งแรก, siteCode ของ group = ของ item แรก
            $gateOrder  = [];
            $gateGroups = [];
            foreach ($groups[$receiverKey] as $item) {
                // ประตู: item.GateID (ผู้ใช้เลือก) ก่อน แล้วค่อย SiteMaterials — ดู _docsItemGateSite
                list($g, $sc) = _docsItemGateSite($item, $type, $gateMap, $siteMap);
                if (!array_key_exists($g, $gateGroups)) {
                    $gateGroups[$g] = ['siteCode' => $sc, 'items' => []];
                    $gateOrder[] = $g;
                }
                $gateGroups[$g]['items'][] = $item;
            }

            foreach ($gateOrder as $g) {
                $grp    = $gateGroups[$g];
                $gItems = $grp['items'];
                $docNo  = ($g !== '') ? $base . $g : $base;

                $projectId = _docsResolveProject($pdo, (string)$grp['siteCode'], $sessionProjectId);
                $projectsTouched[$projectId] = true;
                $gateId = ($g !== '') ? _docsGateIdByCode($pdo, $projectId, $g) : null;

                // ---- เส้นทางอนุมัติ (server-side ล้วน — ไม่ใช้ธง client) ----
                if ($type === Doc::TYPE_OD) {
                    // OD ไม่ต้องขออนุมัติ — auto เสมอ
                    $isAuto   = true;
                    $status   = Doc::ST_APPROVED;
                    $approver = $submitter;
                } else {
                    $allNAR = true;
                    $hasC01 = $submissionHasC01;
                    foreach ($gItems as $gi) {
                        $mc  = (string)($gi['MatCode'] ?? '');
                        $cat = isset($matMap[$mc]) ? $matMap[$mc]['cat'] : '';
                        if ($cat !== 'NAR') { $allNAR = false; }
                        if ($cat === 'C01') { $hasC01 = true; }
                    }
                    // [PHP port 2026-09-25 · มติ 52] เบิกวัสดุหลัก (RD) ต้องผ่านอนุมัติ R6 ขึ้นไปทุกใบ — SE1/SE2 (R4–R5)
                    // และต่ำกว่า auto ไม่ได้ (เดิม C01→6 · อื่น→4 ทำให้ SE เบิก C02 ผ่านทันที) · ยืม (BD) คงเกณฑ์เดิม
                    $minRole  = ($type === Doc::TYPE_RD) ? 6 : ($hasC01 ? 6 : 4);
                    $isAuto   = ($submitterRole >= $minRole && $submitterRole < 99) || $allNAR;
                    if ($type === Doc::TYPE_RD) {
                        // [2026-10-06] ผู้ใช้สั่ง: ผู้ส่ง R6 ขึ้นไป (และ PM ของไซต์) อนุมัติอัตโนมัติ — แทนกติกา 2 ต.ค. (GP-16) ที่ให้เฉพาะ PM
                        //   ต่ำกว่า R6 (รวม R0) ยังต้องเลือกผู้อนุมัติ R6 ขึ้นไป (มติ 52) · ยอดที่ประตูตรวจตอนส่งเหมือนเดิม → อนุมัติแล้ว = จองทันที
                        $isAuto = ($submitterRole >= 6 && $submitterRole < 99) || _docsIsSitePm($pdo, $user, $projectId);
                    }
                    $assigned = trim((string)($gItems[0]['Approver'] ?? ''));
                    if (!$isAuto) {
                        $apErr = _docsApproverError($pdo, $assigned, $submitter, $projectId, $minRole);
                        if ($apErr !== null) {
                            if ($ownTx) { $pdo->rollBack(); }
                            return ['base' => '', 'docs' => [], 'error' => $apErr];
                        }
                    }
                    if ($type === Doc::TYPE_RD) {
                        $status   = $isAuto ? Doc::ST_APPROVED : Doc::ST_AWAITING;
                        $approver = $isAuto ? $submitter : $assigned;
                    } else { // BD batch
                        $status   = $isAuto ? Doc::ST_SENT_BORROW : Doc::ST_AWAITING;
                        $approver = $isAuto ? $submitter : $assigned;
                    }
                }

                list($receiverName, $receiverSubId) = _docsResolveReceiver($receiverKey, $subMaps);

                $docId = _docsInsertDocument($pdo, [
                    'doc_no'          => $docNo,
                    'doc_type'        => $type,
                    'project_id'      => $projectId,
                    'requester'       => $submitter,   // [2026-10-02] จาก session
                    'receiver_name'   => $receiverName,
                    'receiver_sub_id' => $receiverSubId,
                    'gate_id'         => $gateId,
                    'usage_area'      => (string)($gItems[0]['UsageArea'] ?? ''),
                    'notice'          => (string)($gItems[0]['Notice'] ?? ''),
                    'approver'        => $approver,
                    'status'          => $status,
                    'rs_no'           => null,
                    'doc_ts'          => $now,
                ]);
                if ($type === Doc::TYPE_BD) {
                    // [Scenario 05 ③] กำหนดวันคืน (ตรวจแล้วใน rpc_processBorrowBatch)
                    $due = _docsGroupDueDate($gItems);
                    if ($due !== null) {
                        $pdo->prepare('UPDATE documents SET due_date = ? WHERE id = ?')->execute([$due, $docId]);
                    }
                }

                foreach ($gItems as $it2) {
                    $mc = (string)($it2['MatCode'] ?? '');
                    $mi = isset($matMap[$mc]) ? $matMap[$mc] : null;
                    if ($type === Doc::TYPE_OD) {
                        // mirror GAS: matNameMap[code] || code
                        $matName = ($mi && $mi['name'] !== '') ? $mi['name'] : $mc;
                    } else {
                        $matName = $mi ? $mi['name'] : '';
                    }
                    _docsInsertItem($pdo, $docId, [
                        'material_id' => $mi ? $mi['id'] : null,
                        'mat_code'    => $mc,
                        'mat_name'    => $matName,
                        'unit'        => $mi ? $mi['unit'] : '',
                        'qty'         => (float)($it2['Qty'] ?? 0),
                        'usage_area'  => (string)($it2['UsageArea'] ?? ''),
                        'notice'      => (string)($it2['Notice'] ?? ''),
                        'rs_no'       => null,
                        // BD ไม่มีช่องหักเงิน (mirror ชีต Borrow_Return ไม่มี ChargeMoney)
                        'charge'      => ($type === Doc::TYPE_BD) ? 0 : (isTrueFlag($it2['Charge'] ?? false) ? 1 : 0),
                    ]);
                }

                $createdDocs[] = [
                    'docNo' => $docNo, 'docId' => $docId, 'projectId' => $projectId,
                    'gateId' => $gateId, 'isAuto' => $isAuto,
                ];
            }
        }

        // GateLogs 'Awaiting' — RD/BD เฉพาะใบ auto-approve (ใบรออนุมัติสร้างตอนอนุมัติ),
        // OD ทุกใบ (isAuto = true เสมอ) — mirror GAS
        foreach ($createdDocs as $cd) {
            if ($cd['isAuto']) {
                _docsEnsureGateLogAwaiting($pdo, $cd['projectId'], $cd['docNo'], $cd['docId'], $cd['gateId']);
            }
        }

        foreach (array_keys($projectsTouched) as $pid) {
            recalcPending($pdo, (int)$pid);
        }

        if ($ownTx) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }

    $docNos = [];
    foreach ($createdDocs as $cd) {
        $docNos[] = $cd['docNo'];
    }
    return ['base' => (string)$baseFirst, 'docs' => $docNos];
}

// =========================================================================
// RPC: processRequisitionSubmission(items)
// =========================================================================
function rpc_processRequisitionSubmission(PDO $pdo, ?array $user, array $args) {
    $items = _docsItemsArg($args);
    if (!$items) {
        return ['success' => false, 'message' => 'ไม่มีข้อมูลรายการขอเบิก'];
    }
    try {
        $icErr = _docsIcOnlyError($pdo, $items);
        if ($icErr !== null) {
            return ['success' => false, 'message' => $icErr];
        }
        $r = _docsSubmitReceiverSplit($pdo, $user, $items, Doc::TYPE_RD);
        if (!empty($r['error'])) {
            return ['success' => false, 'message' => $r['error']];   // ของที่ประตูไม่พอ (มติ 51) — ใบยังไม่ถูกเขียน
        }
        return ['success' => true, 'requisId' => $r['base'], 'docs' => $r['docs']];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('processRequisitionSubmission Error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Server error: ' . $e->getMessage()];
    }
}

// =========================================================================
// RPC: processOddsSubmission(items)
// =========================================================================
function rpc_processOddsSubmission(PDO $pdo, ?array $user, array $args) {
    $items = _docsItemsArg($args);
    if (!$items) {
        return ['success' => false, 'message' => 'ไม่มีข้อมูลรายการขอเบิก'];
    }
    try {
        $icErr = _docsIcOnlyError($pdo, $items);
        if ($icErr !== null) {
            return ['success' => false, 'message' => $icErr];
        }
        $r = _docsSubmitReceiverSplit($pdo, $user, $items, Doc::TYPE_OD);
        if (!empty($r['error'])) {
            return ['success' => false, 'message' => $r['error']];   // ของที่ประตูไม่พอ (มติ 51)
        }
        return ['success' => true, 'oddsId' => $r['base'], 'docs' => $r['docs']];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('processOddsSubmission Error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Server error: ' . $e->getMessage()];
    }
}

// =========================================================================
// RPC: processBorrowBatch(items)
// =========================================================================
function rpc_processBorrowBatch(PDO $pdo, ?array $user, array $args) {
    $items = _docsItemsArg($args);
    if (!$items) {
        return ['success' => false, 'message' => 'ไม่มีรายการ'];
    }
    try {
        $icErr = _docsIcOnlyError($pdo, $items);
        if ($icErr !== null) {
            return ['success' => false, 'message' => $icErr];
        }
        $dueErr = _docsBorrowDueError($items);   // Scenario 05 ③: กำหนดวันคืนบังคับ
        if ($dueErr !== null) {
            return ['success' => false, 'message' => $dueErr];
        }
        $r = _docsSubmitReceiverSplit($pdo, $user, $items, Doc::TYPE_BD);
        if (!empty($r['error'])) {
            return ['success' => false, 'message' => $r['error']];   // ของที่ประตูไม่พอ (มติ 51)
        }
        return [
            'success'   => true,
            'count'     => count($items),
            'borrowId'  => $r['base'],
            'borrowIds' => $r['docs'],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('processBorrowBatch Error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Server error: ' . $e->getMessage()];
    }
}

// =========================================================================
// RPC: processBorrowSubmission(item) — legacy รายการเดี่ยว
// หมายเหตุ: กติกา auto-approve ต่างจาก batch โดยเจตนา (R5+ หรือ NAR) —
// คงความไม่สม่ำเสมอไว้ตาม GAS เดิม (client ปัจจุบันใช้ batch)
// =========================================================================
function rpc_processBorrowSubmission(PDO $pdo, ?array $user, array $args) {
    try {
        $item = (isset($args[0]) && is_array($args[0])) ? $args[0] : [];

        $sessionProjectId   = (int)($user['projectId'] ?? 0);
        $sessionProjectCode = (string)($user['siteCode'] ?? '');

        $matCodeRaw = (string)($item['MatCode'] ?? '');
        $gate       = (string)($item['GateID'] ?? '');
        $siteCode   = (string)($item['SiteCode'] ?? '');

        // SiteMaterials override: แถวที่ MatCode ตรง "ทับ" ค่า item เสมอ
        // (mirror GAS: gateId = smVal แม้ smVal เป็นค่าว่าง)
        list($gateMap, $siteMap) = _docsGateSiteMaps($pdo, $sessionProjectId, $sessionProjectCode);
        if (array_key_exists($matCodeRaw, $gateMap)) {
            $gate     = (string)$gateMap[$matCodeRaw];
            $siteCode = (string)$siteMap[$matCodeRaw];
        }
        $gate = trim($gate);

        $matMap = _docsMaterialsMap($pdo, [$matCodeRaw]);
        $icErr  = _docsIcOnlyError($pdo, [$item], $matMap);
        if ($icErr !== null) {
            return ['success' => false, 'message' => $icErr];
        }
        $mi     = isset($matMap[$matCodeRaw]) ? $matMap[$matCodeRaw] : null;
        $isNAR  = ($mi && $mi['cat'] === 'NAR');
        $narErr = _docsNarRuleError([$item], Doc::TYPE_BD, $matMap);   // Scenario 05: ใบยืมไม่มีวัสดุ NAR
        if ($narErr !== null) {
            return ['success' => false, 'message' => $narErr];
        }
        $dueErr = _docsBorrowDueError([$item]);                         // Scenario 05 ③: กำหนดวันคืนบังคับ
        if ($dueErr !== null) {
            return ['success' => false, 'message' => $dueErr];
        }

        $submitterRole = _docsRoleLevel($pdo, $user);   // server-authoritative
        // [Scenario 05 · 2026-09-28] เกณฑ์เดียวกับใบยืมแบบตะกร้า: มี C01 → R6 ขึ้นไป · อื่น → R4 ขึ้นไป (เดิม R5+)
        $minRole = ($mi && $mi['cat'] === 'C01') ? 6 : 4;
        $isAuto = ($submitterRole >= $minRole && $submitterRole < 99) || $isNAR;
        $status = $isAuto ? Doc::ST_SENT_BORROW : Doc::ST_AWAITING;

        $subMaps = _docsSubMaps($pdo);
        list($receiverName, $receiverSubId) = _docsResolveReceiver((string)($item['Receiver'] ?? ''), $subMaps);

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) { $pdo->beginTransaction(); }
        try {
            $dateKey = docDateKey();
            $n       = _docCounterNext($pdo, Doc::TYPE_BD, $dateKey);
            $base    = Doc::TYPE_BD . $dateKey . _docRunningStr($n);
            $docNo   = ($gate !== '') ? $base . $gate : $base;

            $projectId = _docsResolveProject($pdo, trim($siteCode), $sessionProjectId);
            $gateId    = ($gate !== '') ? _docsGateIdByCode($pdo, $projectId, $gate) : null;

            $docId = _docsInsertDocument($pdo, [
                'doc_no'          => $docNo,
                'doc_type'        => Doc::TYPE_BD,
                'project_id'      => $projectId,
                'requester'       => (string)($item['UserName'] ?? ''),
                'receiver_name'   => $receiverName,
                'receiver_sub_id' => $receiverSubId,
                'gate_id'         => $gateId,
                'usage_area'      => (string)($item['UsageArea'] ?? ''),
                'notice'          => (string)($item['Notice'] ?? ''),
                'approver'        => '',    // legacy: ชีตเดิมไม่มีคอลัมน์ Approver
                'status'          => $status,
                'rs_no'           => null,
                'doc_ts'          => nowBkk()->format('Y-m-d H:i:s'),
            ]);
            $pdo->prepare('UPDATE documents SET due_date = ? WHERE id = ?')->execute([_docsGroupDueDate([$item]), $docId]);
            _docsInsertItem($pdo, $docId, [
                'material_id' => $mi ? $mi['id'] : null,
                'mat_code'    => $matCodeRaw,
                'mat_name'    => $mi ? $mi['name'] : '',
                'unit'        => $mi ? $mi['unit'] : '',
                'qty'         => (float)($item['Qty'] ?? 0),
                'usage_area'  => (string)($item['UsageArea'] ?? ''),
                'notice'      => (string)($item['Notice'] ?? ''),
                'rs_no'       => null,
                'charge'      => 0,
            ]);

            if ($isAuto) {
                _docsEnsureGateLogAwaiting($pdo, $projectId, $docNo, $docId, $gateId);
            }
            recalcPending($pdo, $projectId);
            if ($ownTx) { $pdo->commit(); }
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }

        return ['success' => true, 'borrowId' => $docNo];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('processBorrowSubmission Error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Server error: ' . $e->getMessage()];
    }
}

// =========================================================================
// RPC: processInboundBatch(items) — รับเข้าคลัง (auto-approve เสมอ)
//   [2026-10-06] ใบเดียวต่อครั้ง ไม่ผูกประตู เลข IN + วันที่ + เลขรัน (ไม่มี Gxx) — ยอดขึ้นที่ G ที่สแกนตอนรับเข้า
// =========================================================================
function rpc_processInboundBatch(PDO $pdo, ?array $user, array $args) {
    // [2026-10-02 · GP-10 / OP-70] ออกใบ IN ได้เฉพาะสายสโตร์ของไซต์ (ADM ทุกไซต์) · ชื่อ/ไซต์จาก session ·
    //   args[1] = ที่มาของของ {source: supplier|nonote, rs, ref, reason, photos[]} — lib/inbound_ctl.php
    require_once __DIR__ . '/inbound_ctl.php';
    $inAcc = inCtlAccess($pdo, $user);
    if (!$inAcc['ok']) {
        return ['success' => false, 'message' => $inAcc['message']];
    }
    $items = _docsItemsArg($args);
    if (!$items) {
        return ['success' => false, 'message' => 'ไม่มีรายการ'];
    }
    try {
        // ── ตรวจทุก MatCode กับ master ก่อนเขียนอะไรทั้งสิ้น (mirror GAS) ──
        $allCodes = [];
        foreach ($items as $it) {
            $allCodes[] = (string)($it['MatCode'] ?? '');
        }
        $matMap = _docsMaterialsMap($pdo, $allCodes);
        $badCodes = [];
        foreach ($items as $it) {
            $mc = trim((string)($it['MatCode'] ?? ''));
            if ($mc === '' || !isset($matMap[$mc])) {
                $badCodes[] = ($mc === '') ? '(ว่าง)' : $mc;
            }
        }
        if ($badCodes) {
            return [
                'success' => false,
                'message' => 'MatCode ไม่ถูกต้อง (ไม่มีใน MaterialsMain): ' . implode(', ', array_values(array_unique($badCodes))),
            ];
        }
        // รับเข้าได้เฉพาะรหัส IC (มติ 34) — เดิม ensureProjectMaterial กันไว้เฉพาะกลุ่มที่มี siteCode
        $icErr = _docsIcOnlyError($pdo, $items, $matMap);
        if ($icErr !== null) {
            return ['success' => false, 'message' => $icErr];
        }

        $sessionProjectId   = (int)($user['projectId'] ?? 0);
        $sessionProjectCode = (string)($user['siteCode'] ?? '');
        list($gateMap, $siteMap) = _docsGateSiteMaps($pdo, $sessionProjectId, $sessionProjectCode);

        // [2026-10-02 · GP-10] ชื่อผู้ออกใบ = บัญชีที่ล็อกอิน (เดิมใช้ UserName ที่หน้าจอส่งมา)
        $submitter = _docsSessionName($user);
        if ($submitter === '') { $submitter = (string)(isset($items[0]['UserName']) ? $items[0]['UserName'] : ''); }
        $now       = nowBkk()->format('Y-m-d H:i:s');

        // [2026-10-06] ใบ IN ไม่ผูกประตู: ออกใบเดียวต่อครั้ง เลขไม่มีรหัส G ต่อท้าย (IN + วันที่ + เลขรัน) ·
        //   GateID / NoGate ที่ส่งมาไม่มีผลแล้ว — สแกนที่ตู้ G ไหนของไซต์ของใบ = ยอดขึ้นที่ G นั้น (api/gate.php gateAcceptDocs)
        //   เดิม: แตกใบตาม G ที่เลือก (IN…G01 · IN…G02) และเติมประตูตั้งต้นของวัสดุให้เมื่อไม่ได้เลือก
        $gateOrder  = [''];
        $gateGroups = ['' => ['siteCode' => '', 'items' => []]];
        foreach ($items as $item) {
            $mc = (string)($item['MatCode'] ?? '');
            $sc = (string)($item['SiteCode'] ?? '');
            if ($sc === '') {
                $sc = (string)($siteMap[$mc] ?? '');
            }
            $sc = trim($sc);
            if (!$inAcc['admin']) {
                $sc = $sessionProjectCode;   // [2026-10-02 · GP-10] สายสโตร์ออกใบได้เฉพาะไซต์ของตัวเอง — SiteCode ที่ส่งมาไม่มีผล
            }
            if ($gateGroups['']['items'] && strcasecmp($sc, (string)$gateGroups['']['siteCode']) !== 0) {
                return ['success' => false, 'message' => 'ใบรับเข้าหนึ่งใบต้องเป็นของไซต์เดียว — แยกออกใบทีละไซต์'];
            }
            if (!$gateGroups['']['items']) {
                $gateGroups['']['siteCode'] = $sc;
            }
            $gateGroups['']['items'][] = $item;
        }

        // [2026-10-02 · GP-10] ที่มาของของ: มีใบส่งของ (เลขที่ + รูปใบส่งของ) / ไม่มีใบส่งของ (เลข TD/BD หรือเหตุผล + รูปของ)
        $inProjectId = $sessionProjectId;
        if ($inAcc['admin'] && $gateOrder) {
            $inProjectId = _docsResolveProject($pdo, (string)$gateGroups[$gateOrder[0]]['siteCode'], $sessionProjectId);
        }
        $rsItems = '';
        foreach ($items as $itRs) {
            $v = trim((string)($itRs['RS'] ?? ''));
            if ($v !== '') { $rsItems = $v; break; }
        }
        $inMeta = inCtlCheckMeta($pdo, (isset($args[1]) && is_array($args[1])) ? $args[1] : [], $rsItems, $inProjectId);
        if ($inMeta['error'] !== null) {
            return ['success' => false, 'message' => $inMeta['error']];
        }
        $inSaved = inCtlSavePhotos($inMeta['photos'], Doc::TYPE_IN . docDateKey());
        if ($inSaved['error'] !== null) {
            return ['success' => false, 'message' => $inSaved['error']];
        }
        $inPhotoPaths = $inSaved['paths'];

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) { $pdo->beginTransaction(); }
        $createdDocs = [];
        try {
            $dateKey = docDateKey();
            $n       = _docCounterNext($pdo, Doc::TYPE_IN, $dateKey);
            $base    = Doc::TYPE_IN . $dateKey . _docRunningStr($n);

            $projectsTouched = [];
            foreach ($gateOrder as $g) {
                $grp    = $gateGroups[$g];
                $gItems = $grp['items'];
                $docNo  = ($g !== '') ? $base . $g : $base;

                $projectId = _docsResolveProject($pdo, (string)$grp['siteCode'], $sessionProjectId);
                $projectsTouched[$projectId] = true;
                $gateId = ($g !== '') ? _docsGateIdByCode($pdo, $projectId, $g) : null;

                // mirror _ensureSiteMaterialsAndBalance_: ลงทะเบียนวัสดุใหม่ของ site
                // เฉพาะเมื่อ group มี siteCode (GAS ข้ามเมื่อ siteCode ว่าง)
                if (trim((string)$grp['siteCode']) !== '') {
                    foreach ($gItems as $gi) {
                        $mc = trim((string)($gi['MatCode'] ?? ''));
                        if ($mc !== '') {
                            ensureProjectMaterial($pdo, $projectId, $mc, ($g !== '' ? $g : null), false);
                        }
                    }
                }

                // รับเข้าคลังไม่ต้องขออนุมัติ — Sent Inbound + QR ทันที
                $docId = _docsInsertDocument($pdo, [
                    'doc_no'          => $docNo,
                    'doc_type'        => Doc::TYPE_IN,
                    'project_id'      => $projectId,
                    'requester'       => $submitter,   // [2026-10-02 · GP-10] จาก session
                    'receiver_name'   => null,
                    'receiver_sub_id' => null,
                    'gate_id'         => $gateId,
                    'usage_area'      => null,
                    'notice'          => (string)($gItems[0]['Notice'] ?? ''),
                    'approver'        => $submitter,
                    'status'          => Doc::ST_SENT_INBOUND,
                    'rs_no'           => (string)($gItems[0]['RS'] ?? ''),
                    'doc_ts'          => $now,
                ]);
                // [2026-10-02 · GP-10] ที่มาของของ + รูปใบส่งของ / รูปของ (เลขที่ RS ว่าง → ใช้เลขที่จากช่องที่มาของของ)
                $pdo->prepare("UPDATE documents SET in_source = ?, in_ref = ?, in_reason = ?, in_photo_url = ?,
                                      rs_no = CASE WHEN rs_no IS NULL OR rs_no = '' THEN ? ELSE rs_no END
                                WHERE id = ?")
                    ->execute([$inMeta['source'], $inMeta['ref'] !== '' ? $inMeta['ref'] : null,
                               $inMeta['reason'] !== '' ? $inMeta['reason'] : null,
                               $inPhotoPaths ? implode(', ', $inPhotoPaths) : null,
                               $inMeta['rs'] !== '' ? $inMeta['rs'] : null, $docId]);

                foreach ($gItems as $it2) {
                    $mc = (string)($it2['MatCode'] ?? '');
                    $mi = isset($matMap[trim($mc)]) ? $matMap[trim($mc)] : null;
                    _docsInsertItem($pdo, $docId, [
                        'material_id' => $mi ? $mi['id'] : null,
                        'mat_code'    => $mc,
                        'mat_name'    => $mi ? $mi['name'] : '',
                        'unit'        => $mi ? $mi['unit'] : '',
                        'qty'         => (float)($it2['Qty'] ?? 0),
                        'usage_area'  => null,
                        'notice'      => (string)($it2['Notice'] ?? ''),
                        'rs_no'       => (string)($it2['RS'] ?? ''),
                        'charge'      => 0,
                    ]);
                }

                $createdDocs[] = [
                    'docNo' => $docNo, 'docId' => $docId, 'projectId' => $projectId,
                    'gateId' => $gateId,
                ];
            }

            // GateLogs — IN auto-approve เสมอ → ทุกใบ
            foreach ($createdDocs as $cd) {
                _docsEnsureGateLogAwaiting($pdo, $cd['projectId'], $cd['docNo'], $cd['docId'], $cd['gateId']);
            }
            foreach (array_keys($projectsTouched) as $pid) {
                recalcPending($pdo, (int)$pid);
            }
            if ($ownTx) { $pdo->commit(); }
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
            inCtlDeleteFiles($inPhotoPaths);   // [2026-10-02] ใบไม่ถูกเขียน → ไม่เก็บรูป
            throw $e;
        }

        $docNos = [];
        foreach ($createdDocs as $cd) {
            $docNos[] = $cd['docNo'];
        }
        return [
            'success'    => true,
            'count'      => count($items),
            'inboundId'  => $base,
            'inboundIds' => $docNos,
            'inPhotos'   => $inPhotoPaths,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('processInboundBatch Error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Server error: ' . $e->getMessage()];
    }
}

// =========================================================================
// RPC: getUnreturnedItems(userRoleLevel, username, siteCode, roleName)
// สิทธิ์การเห็น: R0/R4/R6/R8+ หรือสายสโตร์ → เห็นทั้ง site,
// อื่นๆ เห็นเฉพาะของตัวเอง (role/ชื่อ ดึงจาก session — args ใช้เป็น filter เท่านั้น)
//
// [Scenario 05 ③ · 2026-09-29] ใบที่ยังปิดไม่ได้ทั้งหมด: Borrowed + Sent Return (แจ้งคืนแล้วรอสแกน · กำลังคืน ·
// คืนแล้วแต่ไม่ครบ = รอตีชำรุด/สูญหาย) — หนึ่งแถวต่อรายการ (คีย์เดิมครบ · qty = ยอดค้างคืน) + ข้อมูลใหม่:
// itemId · unit · borrowed · returned · writtenOff · outstanding · dueDate · overdueDays · state · rtStatus ·
// gate · borrowerName · hasWriteoff · canReturn · canWriteoff
// =========================================================================
function rpc_getUnreturnedItems(PDO $pdo, ?array $user, array $args) {
    try {
        // args ตามลายเซ็น GAS: [roleLevel, username, siteCode, roleName]
        $siteArg = trim((string)($args[2] ?? ''));

        // ---- สิทธิ์จาก session (server-authoritative) ----
        $roleNum = borrowRoleNum($user);
        $seeAll  = borrowSeesSite($pdo, $user);
        $isStore = s05UserIsStore($pdo, $user);

        // site filter: R0 ใช้ค่าที่ client ส่ง (R0 ส่ง '' = ทุก site — พฤติกรรม
        // getEffectiveSiteCode) · role อื่นบังคับ site ของ session
        $site = ($roleNum === 0) ? $siteArg : trim((string)($user['siteCode'] ?? ''));
        $ownName = trim(_docsEffectiveUsername($user));

        $sql = "SELECT d.id, d.doc_no, d.doc_ts, d.status, d.return_ts, d.due_date, d.writeoff_flag,
                       d.requester_username, d.receiver_name, g.gate_code, gl.status AS rt_status
                  FROM documents d
                  JOIN projects p ON p.id = d.project_id
                  LEFT JOIN gates g ON g.id = d.gate_id
                  LEFT JOIN gate_logs gl ON gl.doc_no = CONCAT(d.doc_no, 'RT')
                 WHERE d.doc_type = 'BD' AND LOWER(TRIM(d.status)) IN ('borrowed', 'sent return')";
        $params = [];
        if ($site !== '') {
            $sql .= ' AND p.code = ?';
            $params[] = $site;
        }
        $sql .= ' ORDER BY d.id';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $docs = $st->fetchAll();

        $subMaps = _docsSubMaps($pdo);
        $byCode  = $subMaps[0];
        $names   = borrowFullNames($pdo, array_column($docs, 'requester_username'));
        $today   = borrowToday();
        $soon    = (new DateTime($today))->modify('+' . BORROW_DUE_SOON_DAYS . ' days')->format('Y-m-d');

        $out = [];
        foreach ($docs as $r) {
            $borrower = (string)$r['requester_username'];
            $isOwn = trim($borrower) === $ownName;
            if (!$seeAll && !$isOwn) {
                continue;   // เห็นเฉพาะของตัวเอง (mirror เทียบตรงแบบ GAS)
            }
            $dateStr = '';
            $dt = parseGasTimestamp((string)$r['doc_ts']) ?: (DateTime::createFromFormat('Y-m-d H:i:s', (string)$r['doc_ts']) ?: null);
            if (!$dt) {
                try { $dt = new DateTime((string)$r['doc_ts']); } catch (Throwable $e) { $dt = null; }
            }
            if ($dt) {
                $dateStr = $dt->format('d/m/Y');   // mirror padStart(2,'0') dd/mm/yyyy
            }
            $itemSubId  = fmtSubId((string)($r['receiver_name'] ?? ''));
            $subDisplay = isset($byCode[$itemSubId]) ? $byCode[$itemSubId]['name'] : $itemSubId;

            $rtStatus = (string)($r['rt_status'] ?? '');
            $state    = borrowDocState((string)$r['status'], $r['return_ts'], $rtStatus);
            $due      = (string)($r['due_date'] ?? '');
            $items    = borrowDocItems($pdo, (int)$r['id']);
            // แจ้งคืนได้: ใบ Borrowed (หรือแจ้งคืนแล้วแต่ QR ขาคืนหาย — ข้อมูลเก่า) · ผู้ยืม หรือกลุ่มที่เห็นทั้งไซต์ (ตรงกับ registerReturnGateLog)
            // [2026-10-08] ทยอยคืน: ใบที่คืนไปบางส่วน (Borrowed) / ข้อมูลเก่าที่ขาคืนจบแล้วแต่ยังค้าง (writeoff_pending) แจ้งคืนรอบใหม่ได้
            $canReturn = ($state === 'borrowed' || $state === 'writeoff_pending' || ($state === 'return_pending' && $rtStatus === ''))
                      && ($isOwn || $seeAll) && borrowRemainingTotal($items) > 0.0005;
            $canWriteoff = $isStore && in_array($state, ['borrowed', 'return_pending', 'writeoff_pending'], true)
                        && !$isOwn;   // [2026-10-02 · GP-16] สายสโตร์ตีชำรุดใบยืมของตัวเองไม่ได้
            foreach ($items as $it) {
                $out[] = [
                    'borrowId'     => (string)$r['doc_no'],
                    'date'         => $dateStr,
                    'borrower'     => $borrower,
                    'matCode'      => $it['matCode'],
                    'matName'      => $it['matName'],
                    'qty'          => _docsNum($it['outstanding']),
                    'subId'        => $subDisplay,
                    'itemId'       => $it['id'],
                    'unit'         => $it['unit'],
                    'borrowed'     => _docsNum($it['borrowed']),
                    'returned'     => $it['returned'] === null ? null : _docsNum($it['returned']),
                    'writtenOff'   => _docsNum($it['writtenOff']),
                    'outstanding'  => _docsNum($it['outstanding']),
                    'returnReason' => $it['returnReason'],
                    'dueDate'      => $due,
                    'overdueDays'  => borrowOverdueDays($due, $today),
                    'dueSoon'      => $due !== '' && $due >= $today && $due <= $soon,
                    'state'        => $state,
                    'rtStatus'     => $rtStatus,
                    'gate'         => (string)($r['gate_code'] ?? ''),
                    'borrowerName' => $names[mb_strtolower(trim($borrower), 'UTF-8')] ?? $borrower,
                    'hasWriteoff'  => isTrueFlag($r['writeoff_flag']),
                    'canReturn'    => $canReturn,
                    'canWriteoff'  => $canWriteoff,
                ];
            }
        }
        return $out;
    } catch (Throwable $e) {
        error_log('getUnreturnedItems Error: ' . $e->getMessage());
        return [];   // mirror GAS: error → []
    }
}

// =========================================================================
// RPC: returnBorrowItem(borrowId) — ธงคืน: Borrowed/Sent Borrow → 'Sent Return'
// =========================================================================
function rpc_returnBorrowItem(PDO $pdo, ?array $user, array $args) {
    // [Scenario 05 · 2026-09-28] แจ้งคืน = สร้าง QR ขาคืน (…RT) ที่ G เดิมเสมอ — เดิมธงเฉย ๆ ไม่มีแถวประตู
    // (ใบค้าง Sent Return ถาวร) และรับใบ Sent Borrow ที่ยังไม่ได้ยืมออกด้วย → ใช้เส้นทางเดียวกับ registerReturnGateLog
    $r = rpc_registerReturnGateLog($pdo, $user, $args);
    if (!empty($r['success'])) {
        return ['success' => true, 'rtId' => $r['rtId'] ?? ''];
    }
    return ['success' => false, 'message' => ($r['message'] ?? '') !== '' ? $r['message'] : 'Item not found'];
}

// =========================================================================
// RPC: registerReturnGateLog(borrowId)
// สร้างแถว gate_logs '<docNo>RT' (leg=return, Awaiting) + ธง BD → 'Sent Return'
// (ข้ามใบที่ returned / sent return / rejected แล้ว — mirror GAS)
// =========================================================================
function rpc_registerReturnGateLog(PDO $pdo, ?array $user, array $args) {
    $borrowId = trim((string)($args[0] ?? ''));
    if ($borrowId === '') {
        return ['success' => false, 'message' => 'missing borrowId'];
    }
    $rtId = $borrowId . 'RT';
    try {
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) { $pdo->beginTransaction(); }
        try {
            $st = $pdo->prepare("SELECT id, project_id, gate_id, status, requester_username, return_ts FROM documents WHERE doc_no = ? AND doc_type = 'BD' FOR UPDATE");
            $st->execute([$borrowId]);
            $doc = $st->fetch();

            // [Scenario 05 · 2026-09-28] เดิม (mirror GAS) เดินหน้าแม้หาใบยืมไม่พบ และธงใบทุกสถานะที่ยังไม่คืน
            // (รวม Sent Borrow ที่ยังไม่ได้ยืมออก / รออนุมัติ / ยกเลิก) → ตอนนี้แจ้งคืนได้เฉพาะใบที่ยืมออกไปแล้ว (Borrowed)
            // โดยผู้ยืม หรือ R4 / R6 / R8 ขึ้นไป / R0 / สายสโตร์ (ชุดเดียวกับที่เห็นรายการค้างคืนทั้งไซต์ — getUnreturnedItems)
            if (!$doc) {
                if ($ownTx) { $pdo->rollBack(); }
                return ['success' => false, 'message' => 'ไม่พบใบยืม ' . $borrowId];
            }
            $roleNum = preg_match('/\d+/', (string)($user['roleLevel'] ?? ''), $rm) ? (int)$rm[0] : 0;
            $seeAll  = ($roleNum === 0 || $roleNum === 4 || $roleNum === 6 || $roleNum >= 8)
                    || strpos(mb_strtolower((string)($user['role'] ?? ''), 'UTF-8'), 'store') !== false
                    || !empty($user['canReq']);
            $owner   = trim((string)$doc['requester_username']);
            if (!$seeAll && !eqUser($owner, _docsEffectiveUsername($user))) {
                if ($ownTx) { $pdo->rollBack(); }
                return ['success' => false, 'message' => 'แจ้งคืนได้เฉพาะใบยืมของตัวเอง'];
            }
            $projectId  = (int)$doc['project_id'];
            $documentId = (int)$doc['id'];
            $gateId     = $doc['gate_id'] !== null ? (int)$doc['gate_id'] : null;   // ขาคืนต้องคืนที่ G เดิม

            $cur = mb_strtolower(trim((string)$doc['status']), 'UTF-8');
            if (strpos($cur, 'returned') !== false) {
                if ($ownTx) { $pdo->rollBack(); }
                return ['success' => false, 'message' => 'ใบยืม ' . $borrowId . ' คืนแล้ว'];
            }
            // [2026-10-08] ทยอยคืนได้หลายรอบ (ผู้ใช้สั่ง — เดิม 2026-09-29: ขาคืนทำครั้งเดียวต่อใบ คืนไม่ครบ = รอตีชำรุด/สูญหายเท่านั้น)
            //   ใบที่คืนไปบางส่วนแล้ว (Borrowed) หรือข้อมูลเก่า (Sent Return ที่ขาคืนจบแล้ว — มี return_ts) แจ้งคืนรอบใหม่ได้
            //   ใช้แถวประตู …RT เดิม (ตั้งกลับเป็น Awaiting) · QR เลขเดิม · คืนที่ G เดิม · ต้องยังมีของค้าง
            $legEnded   = strpos($cur, 'sent return') !== false && trim((string)($doc['return_ts'] ?? '')) !== '';
            $startRound = ($cur === 'borrowed' || $legEnded);
            if ($startRound) {
                require_once __DIR__ . '/borrow.php';
                if (borrowRemainingTotal(borrowDocItems($pdo, $documentId, true)) <= 0.0005) {
                    if ($ownTx) { $pdo->rollBack(); }
                    return ['success' => false, 'message' => 'ใบยืม ' . $borrowId . ' ไม่มีของค้างคืนแล้ว'];
                }
                // ธง → 'Sent Return' · ล้างเวลาคืนรอบก่อน (ตั้งใหม่ตอนปิดประตูรอบนี้) · ล้าง "คืนรอบนี้" ที่อาจค้าง
                $pdo->prepare('UPDATE documents SET status = ?, return_ts = NULL WHERE id = ?')
                    ->execute([Doc::ST_SENT_RETURN, $documentId]);
                $pdo->prepare('UPDATE document_items SET qty_return_round = NULL WHERE document_id = ?')->execute([$documentId]);
                recalcPending($pdo, $projectId);
            } elseif (strpos($cur, 'sent return') === false) {
                if ($ownTx) { $pdo->rollBack(); }
                return ['success' => false, 'message' => 'แจ้งคืนได้เฉพาะใบที่ยืมออกไปแล้ว (Borrowed) — สถานะปัจจุบัน: ' . $doc['status']];
            }

            // แถว RT มีอยู่แล้ว: รอบก่อนจบแล้ว (Closed / Cancelled) + เริ่มรอบใหม่ → ตั้งกลับเป็น Awaiting ·
            // ยังรอสแกน → คืนสถานะเดิม (mirror existed:true) · กำลังคืนที่ประตู → ไม่แตะ
            $g  = $pdo->prepare('SELECT status FROM gate_logs WHERE doc_no = ?');
            $gx = $pdo->prepare('SELECT id, status FROM gate_logs WHERE doc_no = ? FOR UPDATE');
            $gx->execute([$rtId]);
            $exRow = $gx->fetch();
            if ($exRow !== false) {
                $exSt = strtolower(trim((string)$exRow['status']));
                if ($startRound && ($exSt === 'closed' || $exSt === 'cancelled')) {
                    $pdo->prepare('UPDATE gate_logs SET status = ?, picking_id = NULL, card_id = NULL, scanned_at = NULL, gate_id = ? WHERE id = ?')
                        ->execute([Gate::ST_AWAITING, $gateId, (int)$exRow['id']]);
                    $pdo->prepare('INSERT INTO activity_log (entity_type, entity_id, user_name, action, old_value, new_value) VALUES (?, ?, ?, ?, ?, ?)')
                        ->execute(['document', $borrowId, _docsEffectiveUsername($user), 'return_round', (string)$doc['status'],
                                   json_encode(['rt' => $rtId, 'prevRt' => (string)$exRow['status']], JSON_UNESCAPED_UNICODE)]);
                    if ($ownTx) { $pdo->commit(); }
                    return ['success' => true, 'rtId' => $rtId, 'status' => Gate::ST_AWAITING, 'existed' => false, 'round' => true];
                }
                if ($startRound && $exSt !== 'awaiting') {
                    if ($ownTx) { $pdo->rollBack(); }
                    return ['success' => false, 'message' => 'ขาคืนรอบก่อนของใบ ' . $borrowId . ' ยังไม่ปิดที่ประตู (' . (string)$exRow['status'] . ')'];
                }
                if ($ownTx) { $pdo->commit(); }
                return ['success' => true, 'rtId' => $rtId, 'status' => (string)$exRow['status'], 'existed' => true];
            }

            $ins = $pdo->prepare(
                "INSERT IGNORE INTO gate_logs (project_id, doc_no, document_id, leg, gate_id, status)
                 VALUES (?, ?, ?, 'return', ?, ?)"
            );
            $ins->execute([$projectId, $rtId, $documentId, $gateId, Gate::ST_AWAITING]);
            if ($ins->rowCount() === 0) {
                // แพ้ race — อ่านสถานะที่มีอยู่แทน
                $g->execute([$rtId]);
                $existing = $g->fetchColumn();
                if ($ownTx) { $pdo->commit(); }
                return ['success' => true, 'rtId' => $rtId, 'status' => (string)$existing, 'existed' => true];
            }

            if ($ownTx) { $pdo->commit(); }
            return ['success' => true, 'rtId' => $rtId, 'status' => Gate::ST_AWAITING, 'existed' => false];
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    } catch (Throwable $e) {
        error_log('registerReturnGateLog error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// =========================================================================
// RPC: confirmReturnAtGate(borrowId)
// เมื่อ gate_logs '<docNo>RT' = Opened/Scanned → BD 'Returned' + คืนสต๊อก
// (ผ่าน finalizeGateDoc) แล้วปิดแถว RT เป็น 'Closed' (mirror GAS ตั้ง Closed เสมอ)
// =========================================================================
function rpc_confirmReturnAtGate(PDO $pdo, ?array $user, array $args) {
    $borrowId = trim((string)($args[0] ?? ''));
    if ($borrowId === '') {
        return ['success' => false, 'message' => 'missing borrowId'];
    }
    // [Scenario 05 · 2026-09-28] ปิดเส้นทางนี้: คืนของต้องถ่ายรูปยืนยันคืน (รายการละ ≥ 1 รูป) แล้วปิดประตูที่ตู้ของ G เดิม
    // (Opened → Confirmed → Closed → Returned) — ฟังก์ชันนี้ข้ามทั้งรูปและการปิดประตู (หน้าเว็บปัจจุบันไม่ได้เรียก)
    // เปิดกลับได้ด้วย settings/config.php → 'allow_direct_return' => true
    global $APP_SETTINGS;
    if (!isset($APP_SETTINGS['allow_direct_return']) || !isTrueFlag($APP_SETTINGS['allow_direct_return'])) {
        return ['success' => false, 'status' => 'Disabled',
                'message' => 'คืนอุปกรณ์ผ่านหน้า "ถ่ายรูปยืนยัน" แล้วปิดประตูที่ตู้เท่านั้น (' . $borrowId . 'RT)'];
    }
    $rtId = $borrowId . 'RT';
    try {
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) { $pdo->beginTransaction(); }
        try {
            // 1) แถว RT ต้องมี และสถานะต้องเป็น Opened/Scanned
            $g = $pdo->prepare('SELECT id, status FROM gate_logs WHERE doc_no = ? FOR UPDATE');
            $g->execute([$rtId]);
            $gl = $g->fetch();
            if (!$gl) {
                if ($ownTx) { $pdo->commit(); }
                return ['success' => false, 'message' => 'RT row not in GateLogs', 'status' => 'None'];
            }
            $glStatus = trim((string)$gl['status']);
            $glLow    = mb_strtolower($glStatus, 'UTF-8');
            if ($glLow !== 'opened' && $glLow !== 'scanned') {
                if ($ownTx) { $pdo->commit(); }
                return ['success' => false, 'message' => 'Not opened yet', 'status' => $glStatus];
            }

            // 2) ใบยืมต้องมีและยังไม่คืน (mirror updated === 0 → fail)
            $d = $pdo->prepare("SELECT id, status FROM documents WHERE doc_no = ? AND doc_type = 'BD' FOR UPDATE");
            $d->execute([$borrowId]);
            $doc = $d->fetch();
            if (!$doc || strpos(mb_strtolower(trim((string)$doc['status']), 'UTF-8'), 'returned') !== false) {
                if ($ownTx) { $pdo->commit(); }
                return ['success' => false, 'message' => 'No matching Borrow_Return rows'];
            }
            $c = $pdo->prepare('SELECT COUNT(*) FROM document_items WHERE document_id = ?');
            $c->execute([(int)$doc['id']]);
            $updated = (int)$c->fetchColumn();   // mirror จำนวนแถวชีตที่ถูกธง

            // 3) Returned + return_ts + คืนสต๊อก + recalcPending (engine RT path)
            //    [2026-10-08] ทางลัดนี้คืนครบตามยอดค้าง — จด "คืนรอบนี้" = ยอดค้างทุกรายการก่อนปิดงาน (finalize คืนตามรอบ)
            require_once __DIR__ . '/borrow.php';
            $rr = $pdo->prepare('UPDATE document_items SET qty_return_round = ? WHERE id = ?');
            foreach (borrowDocItems($pdo, (int)$doc['id'], true) as $bi) { $rr->execute([round($bi['outstanding'], 3), $bi['id']]); }
            finalizeGateDoc($pdo, $rtId);

            // 4) แถว RT → 'Closed' เสมอ (GAS ตั้ง Closed ตรง ๆ ไม่สน scan-flow)
            $pdo->prepare('UPDATE gate_logs SET status = ? WHERE id = ?')
                ->execute([Gate::ST_CLOSED, (int)$gl['id']]);

            if ($ownTx) { $pdo->commit(); }
            return ['success' => true, 'rtId' => $rtId, 'updated' => $updated];
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    } catch (Throwable $e) {
        error_log('confirmReturnAtGate error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// =========================================================================
// getDocsCloseState(docIds) — GAS v1.10.0 (Code.js)
// หน้า "ถ่ายรูปยืนยัน" poll ถามว่าใบที่เพิ่งยืนยันไป "ปิดงานครบ" หรือยัง
// เพื่อปลดล็อกให้ถ่ายชุดถัดไป · คืน { DocID: {gate, doc, done} }
//   gate = สถานะประตูล่าสุดของ DocID นั้น (แถวหลังชนะ) · 'None' = ไม่มีแถว
//   doc  = สถานะเอกสารต้นทาง (DocID ลงท้าย 'RT' → ใช้สถานะของใบยืมฐาน)
//   done = ประตูปิด + เอกสารปิดงาน · ยกเลิก/ไม่พบประตู = ถือว่าเลิกรอ (กัน UI ค้าง)
// =========================================================================

/** สถานะเอกสารที่ถือว่า "ปิดงานแล้ว" — ตรงกับ _isFinalizedDocStatus_ ของ GAS */
function _docIsFinalizedStatus($status): bool {
    $s = mb_strtolower(trim((string)$status), 'UTF-8');
    return strpos($s, 'completed') !== false
        || strpos($s, 'borrowed') !== false
        || strpos($s, 'returned') !== false;
}

function rpc_getDocsCloseState(PDO $pdo, ?array $user, array $args) {
    $out = [];
    try {
        $ids = is_array($args[0] ?? null) ? $args[0] : [];
        foreach ($ids as $id) {
            $k = trim((string)$id);
            if ($k === '') continue;
            $out[$k] = ['gate' => 'None', 'doc' => '', 'done' => false];
        }
        if (!$out) return $out;

        $keys = array_keys($out);
        $ph   = implode(',', array_fill(0, count($keys), '?'));

        // 1) ประตู — เรียงตาม id เพื่อให้ "แถวหลังชนะ" เหมือนลำดับ append ของชีต
        $stmt = $pdo->prepare(
            "SELECT doc_no, status FROM gate_logs WHERE doc_no IN ($ph) ORDER BY id"
        );
        $stmt->execute($keys);
        foreach ($stmt->fetchAll() as $row) {
            $d = trim((string)$row['doc_no']);
            $s = trim((string)$row['status']);
            if ($s !== '' && isset($out[$d])) $out[$d]['gate'] = $s;
        }

        // 2) เอกสารต้นทาง — 'xxxRT' (ใบคืน) ไม่มีแถวของตัวเองใน documents
        //    ต้องอ่านสถานะของใบยืมฐาน (ตัด 'RT' ท้ายออก)
        $baseMap = [];   // doc_no ฐาน → [DocID ที่ต้องเติมค่า, ...]
        foreach ($keys as $k) {
            $b = preg_match('/RT$/i', $k) ? substr($k, 0, -2) : $k;
            $baseMap[$b][] = $k;
        }
        $bKeys = array_keys($baseMap);
        $ph2   = implode(',', array_fill(0, count($bKeys), '?'));
        $stmt  = $pdo->prepare("SELECT doc_no, status FROM documents WHERE doc_no IN ($ph2)");
        $stmt->execute($bKeys);
        foreach ($stmt->fetchAll() as $row) {
            $b = trim((string)$row['doc_no']);
            $s = trim((string)$row['status']);
            foreach ($baseMap[$b] ?? [] as $target) {
                // สถานะปิดงานชนะเสมอ (กันค่าอื่นมาทับ — mirror พฤติกรรม GAS)
                if (_docIsFinalizedStatus($s) || $out[$target]['doc'] === '') {
                    $out[$target]['doc'] = $s;
                }
            }
        }

        // 3) done
        foreach ($out as $id => $v) {
            $g = mb_strtolower(trim((string)$v['gate']), 'UTF-8');
            if ($g === 'cancelled' || $g === 'none' || $g === '') {
                $out[$id]['done'] = true;   // ยกเลิก/ไม่มีคิวประตูแล้ว = เลิกรอ
                continue;
            }
            $out[$id]['done'] = ($g === 'closed') && _docIsFinalizedStatus($v['doc']);
        }
        return $out;
    } catch (Throwable $e) {
        error_log('getDocsCloseState error: ' . $e->getMessage());
        return $out;
    }
}
