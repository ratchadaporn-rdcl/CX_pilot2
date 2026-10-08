<?php
/**
 * CONNEXT — lib/subexpense.php : หักค่าใช้จ่ายผู้รับเหมา (port จาก Code.js v1.9.x)
 *
 * 1 แถว = (ไซต์, งวดครึ่งเดือน, ชุด) · ช่องเงิน 8 ช่องรวมเป็น total:
 *   ค่าห้อง + ค่าไฟ + เครื่องใช้(ติ๊ก) + มิเตอร์ไฟร้านค้า + วัสดุฯ + เบิกล่วงหน้า + ปรับความปลอดภัย + ปรับสแกนนิ้ว
 *
 * ⚠ มติจุดที่ 14: "วัสดุฯ" (material_amt) และ "ค่าปรับสแกนนิ้ว" (face_scan_fine)
 *   **server คำนวณเองเสมอ** ทิ้งค่าที่ client ส่งมา — GAS ล็อกแค่ช่องบนหน้าจอ
 *   ซึ่งยิง API ตรงก็ส่งยอดเท่าไหร่ก็ได้ (สองช่องนี้เป็นเงินที่หักจากผู้รับเหมาจริง)
 *
 * สิทธิ์: _userCanSubExpense_ = SC หรือ R0
 */

require_once __DIR__ . '/subsettings.php';   // _ssRoleFlags / _ssResolveSite / _ssPendingRefs
require_once __DIR__ . '/signatures.php';    // _sigCollectGroups / _sigFrozenRates / _sigRateMap / _sigFind
require_once __DIR__ . '/fingerscan.php';    // _fsPeriodKeys / _fsLoadRows / _fsSummarize

/** ค่าเริ่มต้น (SE_CONFIG_DEFAULTS ของ GAS) */
function _seDefaults(): array {
    return [
        'roomRate'      => 160.0,   // ค่าห้องพัก บาท/งวด
        'elecRate'      => 6.0,     // ค่าไฟส่วนที่เกิน บาท/หน่วย
        'elecFreeUnits' => 30.0,    // หน่วยไฟที่ใช้ได้ฟรี/งวด
        'shopElecRate'  => 7.0,     // มิเตอร์ไฟร้านค้า บาท/หน่วย
        'shopItems'     => [
            ['label' => 'ร้านค้า',        'price' => 1000.0],
            ['label' => 'เครื่องซักผ้า', 'price' => 500.0],
            ['label' => 'ตู้กดน้ำ',       'price' => 300.0],
        ],
    ];
}

/** _userCanSubExpense_ : SC หรือ R0 */
function _seCanUse(PDO $pdo, ?array $user): bool {
    $f = _ssRoleFlags($pdo, $user);
    return $f['sc'] || $f['level'] === 0;
}

/** งวดจาก payload — คืน ['ym','half','key'] หรือ null */
function _seParsePeriod(array $p): ?array {
    $key = trim((string)($p['period'] ?? ''));
    if (preg_match('#^(\d{4}-\d{2})/H([12])$#', $key, $m)) {
        return ['ym' => $m[1], 'half' => (int)$m[2], 'key' => $key];
    }
    $ym = trim((string)($p['ym'] ?? ''));
    $half = (int)($p['half'] ?? 0);
    if (preg_match('/^\d{4}-\d{2}$/', $ym) && ($half === 1 || $half === 2)) {
        return ['ym' => $ym, 'half' => $half, 'key' => $ym . '/H' . $half];
    }
    return null;
}

/** ป้ายงวดไทย: ('2026-06',1) → 'วันที่ 1-15 มิ.ย.69' */
function _sePeriodLabel(string $ym, int $half): string {
    return _ssPeriodLabel($ym, $half);
}

/**
 * ตั้งค่าอัตราของไซต์ — ไซต์ที่ "เคยบันทึกแล้ว" จะไม่ fallback ไป default อีก
 * (ตั้ง 0 = ตั้งใจให้เป็น 0 · ไม่ใช่ยังไม่ได้ตั้ง)
 */
function _seConfig(PDO $pdo, int $projectId): array {
    $cfg = _seDefaults();
    $st = $pdo->prepare("SELECT kind, cfg_key, label, value FROM sub_expense_config
                          WHERE project_id = ? ORDER BY kind, cfg_key");
    $st->execute([$projectId]);
    $rows = $st->fetchAll();
    if (!$rows) return $cfg;   // ยังไม่เคยตั้ง → ใช้ค่าเริ่มต้นทั้งชุด

    $shopItems = [];
    $sawShop = false;
    foreach ($rows as $r) {
        if ($r['kind'] === 'rate') {
            $k = (string)$r['cfg_key'];
            if (array_key_exists($k, $cfg) && $k !== 'shopItems') $cfg[$k] = (float)$r['value'];
        } else {
            $sawShop = true;
            $shopItems[] = ['label' => (string)$r['label'], 'price' => (float)$r['value']];
        }
    }
    if ($sawShop) $cfg['shopItems'] = $shopItems;
    return $cfg;
}

/** _seNum_ : '' เมื่อว่าง/ไม่ใช่เลข */
function _seNum($v) { return _fsNum($v); }

/** ตัวเลขหรือ 0 (ใช้ตอนรวมยอด) */
function _seN($v): float { $x = _seNum($v); return $x === '' ? 0.0 : (float)$x; }

/** _seRowTotal_ : รวม 8 ช่อง ปัด 2 ตำแหน่ง */
function _seRowTotal(array $o): float {
    return round((_seN($o['roomAmt'] ?? '') + _seN($o['elecAmt'] ?? '') + _seN($o['shopAmt'] ?? '')
                + _seN($o['shopElecAmt'] ?? '') + _seN($o['materialAmt'] ?? '') + _seN($o['advanceAmt'] ?? '')
                + _seN($o['safetyFine'] ?? '') + _seN($o['faceScanFine'] ?? '')) * 100) / 100;
}

// ---------------------------------------------------------------------------
// ยอดที่ server คำนวณเอง (มติจุดที่ 14)
// ---------------------------------------------------------------------------

/**
 * ค่าวัสดุที่ติดธงหักเงินของ (ชุด, งวด) — ใช้ราคาที่ล็อกไว้กับใบก่อน ถ้ายังไม่ออกใบใช้ Rate Card สด
 * คืน map: subName → ['amt' => ยอดรวม, 'items' => จำนวนรายการ, 'unpriced' => รายการที่ยังไม่มีราคา]
 * (รูปเดียวกับ _seMaterialChargeBySub_ ของ GAS — client ใช้ items/unpriced ทำ tooltip ช่อง "วัสดุฯ")
 */
function _seMaterialChargeBySub(PDO $pdo, int $projectId, string $siteCode, string $ym, int $half): array {
    $keys   = _fsPeriodKeys($ym, $half);
    $daySet = [];
    for ($d = new DateTime($keys['from']), $end = new DateTime($keys['to']); $d <= $end; $d->modify('+1 day')) {
        $daySet[$d->format('Y-m-d')] = true;
    }
    $col = _sigCollectGroups($pdo, $siteCode, $ym, $daySet, null);

    // ราคาที่ล็อกไว้ของใบหักเงินในเดือนนี้ (ต่อชุด) + Rate Card สดเป็น fallback
    $st = $pdo->prepare("SELECT doc_no, sub_name FROM deduction_docs WHERE project_id = ? AND ym = ?");
    $st->execute([$projectId, $ym]);
    $docBySub = [];
    $docNos   = [];
    foreach ($st->fetchAll() as $r) {
        $docBySub[(string)$r['sub_name']][] = (string)$r['doc_no'];
        $docNos[] = (string)$r['doc_no'];
    }
    $frozen = _sigFrozenRates($pdo, $docNos);
    $live   = _sigRateMap($pdo, $projectId);

    $out = [];
    foreach ($col['groups'] as $subName => $items) {
        $rec = ['amt' => 0.0, 'items' => 0, 'unpriced' => 0];
        foreach ($items as $it) {
            $rec['items']++;
            $mc = (string)$it['matCode'];
            $price = null;
            foreach ($docBySub[$subName] ?? [] as $dn) {
                if (isset($frozen[$dn][$mc])) { $price = $frozen[$dn][$mc]; break; }
            }
            if ($price === null && array_key_exists($mc, $live)) $price = $live[$mc];
            $qty = is_numeric($it['qty']) ? (float)$it['qty'] : null;
            if ($price !== null && $qty !== null) $rec['amt'] = round($rec['amt'] + $price * $qty, 2);
            else $rec['unpriced']++;   // ยังไม่ตั้งราคา / จำนวนไม่ใช่ตัวเลข → เตือนใน tooltip
        }
        $out[$subName] = $rec;
    }
    return $out;
}

/**
 * ยอดวัสดุอย่างเดียว (map: subName → ยอดรวม) — path บันทึก/สรุปเรียกตัวนี้
 * มติจุดที่ 14: server คำนวณเองเสมอ ทิ้งค่าที่ client ส่งมา
 */
function _seMaterialAmtBySub(PDO $pdo, int $projectId, string $siteCode, string $ym, int $half): array {
    $out = [];
    foreach (_seMaterialChargeBySub($pdo, $projectId, $siteCode, $ym, $half) as $subName => $rec) {
        $out[$subName] = $rec['amt'];
    }
    return $out;
}

/** ค่าปรับสแกนนิ้วของ (ชุด, งวด) — map: subName → ยอดรวม */
function _seFaceScanFineBySub(PDO $pdo, int $projectId, string $ym, int $half): array {
    $keys = _fsPeriodKeys($ym, $half);
    $sum  = _fsSummarize(_fsLoadRows($pdo, $projectId, $keys['from'], $keys['to']));
    $out  = [];
    foreach ($sum['list'] as $rec) $out[$rec['subName']] = (float)$rec['fine'];
    return $out;
}

/**
 * รายชื่อชุดของโครงการ + ชื่อ/รหัสใช้เบิก Mango — สัญญาเดียวกับ getSubExpenseData ของ GAS
 *   subs              = **ชื่อชุด (string)** เฉพาะที่เปิดใช้งาน → เติมเป็นแถวในตารางได้
 *   paymentByName     = subName → MangoVendorName
 *   paymentCodeByName = subName → MangoVendorCode
 * ⚠ สอง map รวม "ชุดที่ปิดใช้งานแล้ว" ด้วย (ต่างจาก subs) เพราะแถวที่บันทึกไว้ก่อนหน้า
 *   อาจอ้างถึงชุดที่ถูกปิดภายหลัง — ยังต้องโชว์ชื่อใช้เบิกของแถวนั้นได้
 * (อย่าใช้ _fsActiveSubs() ตรงนี้ — ตัวนั้นคืน object ให้หน้า "บันทึกสแกนนิ้ว" คนละสัญญากัน)
 */
function _seSubsAndPayment(PDO $pdo, int $projectId): array {
    $st = $pdo->prepare(
        "SELECT s.name, s.status, sp.enabled, sp.mango_vendor_code, sp.mango_vendor_name
           FROM subcontractors s
           JOIN sub_projects sp ON sp.sub_id = s.id AND sp.project_id = ?
          ORDER BY s.sub_code"
    );
    $st->execute([$projectId]);
    $subs = [];
    $payName = [];
    $payCode = [];
    foreach ($st->fetchAll() as $r) {
        $nm = trim((string)$r['name']);
        if ($nm === '') continue;
        $pn = trim((string)($r['mango_vendor_name'] ?? ''));
        $pc = trim((string)($r['mango_vendor_code'] ?? ''));
        if ($pn !== '' && !isset($payName[$nm])) $payName[$nm] = $pn;
        if ($pc !== '' && !isset($payCode[$nm])) $payCode[$nm] = $pc;
        if ((int)$r['enabled'] !== 1 || (string)$r['status'] !== 'active') continue;
        if (!in_array($nm, $subs, true)) $subs[] = $nm;
    }
    return ['subs' => $subs, 'paymentByName' => $payName, 'paymentCodeByName' => $payCode];
}

/** map ที่ต้องออกเป็น JSON object เสมอ — map ว่างต้องเป็น {} ไม่ใช่ [] (client index ด้วยชื่อชุด) */
function _seJsonMap(array $m) {
    return $m ? $m : new stdClass();
}

// ---------------------------------------------------------------------------
// อ่าน / บันทึก แถวหักค่าใช้จ่าย
// ---------------------------------------------------------------------------

/** แถวของงวด → รูปที่ client ใช้ */
function _seLoadRows(PDO $pdo, int $projectId, string $periodKey): array {
    $st = $pdo->prepare("SELECT * FROM sub_expense_rows WHERE project_id = ? AND period_key = ?
                          ORDER BY sub_name");
    $st->execute([$projectId, $periodKey]);
    $num = function ($v) { return $v === null ? '' : (float)$v; };
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = [
            'subName'       => (string)$r['sub_name'],
            'roomQty'       => $num($r['room_qty']),      'roomRate'  => $num($r['room_rate']),
            'roomAmt'       => $num($r['room_amt']),
            'elecUsed'      => $num($r['elec_used']),     'elecOver'  => $num($r['elec_over']),
            'elecAmt'       => $num($r['elec_amt']),
            'shopQty'       => $num($r['shop_qty']),      'shopRate'  => $num($r['shop_rate']),
            'shopAmt'       => $num($r['shop_amt']),
            'shopElecPrev'  => $num($r['shop_elec_prev']), 'shopElecCurr' => $num($r['shop_elec_curr']),
            'shopElecUnits' => $num($r['shop_elec_units']), 'shopElecAmt' => $num($r['shop_elec_amt']),
            'materialAmt'   => $num($r['material_amt']),  'advanceAmt' => $num($r['advance_amt']),
            'safetyFine'    => $num($r['safety_fine']),   'faceScanFine' => $num($r['face_scan_fine']),
            'totalAmt'      => $num($r['total_amt']),
            'shopItems'     => (string)($r['shop_items'] ?? ''),
            'outsideStay'   => (int)$r['outside_stay'] === 1,
            'note'          => (string)($r['note'] ?? ''),
            'updatedBy'     => (string)($r['updated_by'] ?? ''),
            'updatedAt'     => !empty($r['updated_at']) ? strtotime($r['updated_at']) * 1000 : null,
        ];
    }
    return $out;
}

/** งวดก่อนหน้า (สำหรับ carry-forward) */
function _sePrevPeriod(string $ym, int $half): array {
    if ($half === 2) return ['ym' => $ym, 'half' => 1, 'key' => $ym . '/H1'];
    $y = (int)substr($ym, 0, 4); $m = (int)substr($ym, 5, 2);
    $pm = $m === 1 ? 12 : $m - 1;
    $py = $m === 1 ? $y - 1 : $y;
    $pym = sprintf('%04d-%02d', $py, $pm);
    return ['ym' => $pym, 'half' => 2, 'key' => $pym . '/H2'];
}

/**
 * getSubExpenseData({siteCode, ym, half | period, username})
 * คืนแถวของงวด + รายชื่อชุดที่เติมได้ + ค่าตั้งต้น + ยอดที่ server คำนวณ + ค่าจากงวดก่อน (carry)
 *
 * ⚠ ชื่อ/รูปของ key ต้องตรงกับที่ index.php (พอร์ตจาก GAS) อ่านเป๊ะ ๆ — ห้ามเปลี่ยนชื่อ key:
 *   subs=array ของ "ชื่อชุด" (string) · paymentByName · paymentCodeByName ·
 *   matCharge{amt,items,unpriced} · scanFineByName · prevMeterByName/prevRoomByName/prevShopByName
 */
function rpc_getSubExpenseData(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_seCanUse($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];
        $period = _seParsePeriod($p);
        if (!$period) return ['success' => false, 'message' => 'bad_period'];
        $pid = $site['projectId'];

        $rows = _seLoadRows($pdo, $pid, $period['key']);
        $cfg  = _seConfig($pdo, $pid);

        // ยอดที่ server คำนวณ (มติจุดที่ 14) — ส่งไปให้หน้าจอโชว์ (ช่องล็อก)
        $mat  = _seMaterialChargeBySub($pdo, $pid, $site['siteCode'], $period['ym'], $period['half']);
        $fine = _seFaceScanFineBySub($pdo, $pid, $period['ym'], $period['half']);

        // งวดก่อนหน้า — carry เข้าช่องว่างของงวดนี้ (client เติมให้ที่ _sePrefillDefaults)
        //   prevMeterByName : เลขมิเตอร์ร้านค้า "ปัจจุบัน" ของงวดก่อน → ช่อง "ครั้งก่อน" ของงวดนี้
        //   prevRoomByName  : จำนวนห้องพัก — ข้ามแถวที่งวดก่อนติ๊ก "พักข้างนอก" (ไม่มีค่าห้อง)
        //   prevShopByName  : รายการร้านค้า/เครื่องใช้ที่ติ๊กไว้ (JSON string)
        $prev = _sePrevPeriod($period['ym'], $period['half']);
        $prevMeterByName = [];
        $prevRoomByName  = [];
        $prevShopByName  = [];
        foreach (_seLoadRows($pdo, $pid, $prev['key']) as $r) {
            $nm = (string)$r['subName'];
            if ($nm === '') continue;
            if ($r['shopElecCurr'] !== '' && !isset($prevMeterByName[$nm])) $prevMeterByName[$nm] = $r['shopElecCurr'];
            if ($r['roomQty'] !== '' && !$r['outsideStay'] && !isset($prevRoomByName[$nm])) $prevRoomByName[$nm] = $r['roomQty'];
            if ($r['shopItems'] !== '' && !isset($prevShopByName[$nm])) $prevShopByName[$nm] = $r['shopItems'];
        }

        // ชุดที่ "เติมได้" = ชื่อชุดที่เปิดใช้ในโครงการนี้ (ชุดที่ปิดแล้วยังโชว์แถวเดิมได้ แต่ไม่อยู่ในลิสต์เติม)
        $sp = _seSubsAndPayment($pdo, $pid);

        return [
            'success'   => true,
            'sites'     => $site['sites'], 'isAdmin' => $site['isAdmin'],
            'siteCode'  => $site['siteCode'], 'siteName' => $site['siteName'],
            'ym'        => $period['ym'], 'half' => $period['half'], 'period' => $period['key'],
            'periodLabel' => _sePeriodLabel($period['ym'], $period['half']),
            'config'    => $cfg,
            'rows'      => $rows,
            'subs'      => $sp['subs'],                                   // array ของชื่อชุด (string)
            'paymentByName'     => _seJsonMap($sp['paymentByName']),      // คอลัมน์ "ชื่อใช้เบิก Payment"
            'paymentCodeByName' => _seJsonMap($sp['paymentCodeByName']),
            'matCharge'      => _seJsonMap($mat),   // ช่อง "วัสดุฯ" (ล็อก — server คำนวณ)
            'scanFineByName' => _seJsonMap($fine),  // ช่อง "ค่าปรับสแกนนิ้ว" (ล็อก — server คำนวณ)
            'prevMeterByName' => _seJsonMap($prevMeterByName),
            'prevRoomByName'  => _seJsonMap($prevRoomByName),
            'prevShopByName'  => _seJsonMap($prevShopByName),
            'prevPeriod'      => $prev['key'],
        ];
    } catch (Throwable $e) {
        error_log('getSubExpenseData error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * saveSubExpenseData({siteCode, ym, half|period, rows[], username})
 * แทนที่ทั้ง (ไซต์, งวด) · ตัดแถวเปล่า · material/faceScan คำนวณใหม่ฝั่ง server เสมอ
 */
function rpc_saveSubExpenseData(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_seCanUse($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];
        $period = _seParsePeriod($p);
        if (!$period) return ['success' => false, 'message' => 'bad_period'];
        $pid = $site['projectId'];
        $username = trim((string)($user['username'] ?? ''));

        // ยอดที่ server เป็นเจ้าของ — คำนวณสดทุกครั้ง ไม่สนค่าที่ client ส่ง
        $mat  = _seMaterialAmtBySub($pdo, $pid, $site['siteCode'], $period['ym'], $period['half']);
        $fine = _seFaceScanFineBySub($pdo, $pid, $period['ym'], $period['half']);

        $newRows = [];
        foreach ((is_array($p['rows'] ?? null) ? $p['rows'] : []) as $r) {
            if (!is_array($r)) continue;
            $subName = trim((string)($r['subName'] ?? ''));
            if ($subName === '') continue;

            $v = [];
            foreach (['roomQty','roomRate','roomAmt','elecUsed','elecOver','elecAmt',
                      'shopQty','shopRate','shopAmt','shopElecPrev','shopElecCurr',
                      'shopElecUnits','shopElecAmt','advanceAmt','safetyFine'] as $k) {
                $v[$k] = _seNum($r[$k] ?? '');
            }
            // ⚠ ทิ้งค่าที่ client ส่งมาสำหรับ 2 ช่องนี้ (มติจุดที่ 14)
            $v['materialAmt']  = array_key_exists($subName, $mat)  ? $mat[$subName]  : '';
            $v['faceScanFine'] = array_key_exists($subName, $fine) ? $fine[$subName] : '';

            $note = trim((string)($r['note'] ?? ''));
            $shopItems = '';
            if (!empty($r['shopItems'])) {
                $parsed = json_decode((string)$r['shopItems'], true);
                if (is_array($parsed) && $parsed) $shopItems = json_encode($parsed, JSON_UNESCAPED_UNICODE);
            }
            $outsideStay = (($r['outsideStay'] ?? false) === true);

            // แถวว่างจริง = ไม่มีตัวเลขสักช่อง + ไม่มีหมายเหตุ/ติ๊ก → ไม่บันทึก
            $hasNum = false;
            foreach ($v as $x) { if ($x !== '' && (float)$x != 0.0) { $hasNum = true; break; } }
            if (!$hasNum) {
                foreach ($v as $x) { if ($x !== '') { $hasNum = true; break; } }
            }
            if (!$hasNum && $note === '' && $shopItems === '' && !$outsideStay) continue;

            $v['totalAmt'] = _seRowTotal($v);
            $newRows[] = ['subName' => $subName, 'v' => $v, 'note' => $note,
                          'shopItems' => $shopItems, 'outsideStay' => $outsideStay];
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM sub_expense_rows WHERE project_id = ? AND period_key = ?")
                ->execute([$pid, $period['key']]);
            $ins = $pdo->prepare(
                "INSERT INTO sub_expense_rows
                   (project_id, period_key, sub_name, room_qty, room_rate, room_amt,
                    elec_used, elec_over, elec_amt, shop_qty, shop_rate, shop_amt, shop_items,
                    shop_elec_prev, shop_elec_curr, shop_elec_units, shop_elec_amt,
                    material_amt, face_scan_fine, advance_amt, safety_fine, total_amt,
                    outside_stay, note, updated_by, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())"
            );
            $nn = function ($x) { return $x === '' ? null : (float)$x; };
            foreach ($newRows as $r) {
                $v = $r['v'];
                $ins->execute([
                    $pid, $period['key'], $r['subName'],
                    $nn($v['roomQty']), $nn($v['roomRate']), $nn($v['roomAmt']),
                    $nn($v['elecUsed']), $nn($v['elecOver']), $nn($v['elecAmt']),
                    $nn($v['shopQty']), $nn($v['shopRate']), $nn($v['shopAmt']),
                    $r['shopItems'] !== '' ? $r['shopItems'] : null,
                    $nn($v['shopElecPrev']), $nn($v['shopElecCurr']),
                    $nn($v['shopElecUnits']), $nn($v['shopElecAmt']),
                    $nn($v['materialAmt']), $nn($v['faceScanFine']),
                    $nn($v['advanceAmt']), $nn($v['safetyFine']), $nn($v['totalAmt']),
                    $r['outsideStay'] ? 1 : 0, $r['note'] !== '' ? $r['note'] : null, $username,
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $rows = _seLoadRows($pdo, $pid, $period['key']);
        $grand = 0.0;
        foreach ($rows as $r) { if ($r['totalAmt'] !== '') $grand += (float)$r['totalAmt']; }
        return ['success' => true, 'saved' => count($newRows), 'period' => $period['key'],
                'rows' => $rows, 'grandTotal' => round($grand, 2)];
    } catch (Throwable $e) {
        error_log('saveSubExpenseData error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/** saveSubExpenseConfig({siteCode, rates{}, shopItems[], username}) — BS/R0 ตั้งอัตราของไซต์ */
function rpc_saveSubExpenseConfig(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_ssCanBS($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];
        $pid = $site['projectId'];
        $by  = trim((string)($user['username'] ?? ''));
        $def = _seDefaults();

        $pdo->beginTransaction();
        try {
            $up = $pdo->prepare(
                "INSERT INTO sub_expense_config (project_id, kind, cfg_key, label, value, updated_by, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE label = VALUES(label), value = VALUES(value),
                                         updated_by = VALUES(updated_by), updated_at = NOW()"
            );
            $rates = is_array($p['rates'] ?? null) ? $p['rates'] : [];
            foreach (['roomRate', 'elecRate', 'elecFreeUnits', 'shopElecRate'] as $k) {
                $v = _seNum($rates[$k] ?? '');
                // ไม่ส่งมา = ใช้ค่าเริ่มต้น (บันทึกลงไปเลย เพื่อให้ไซต์นี้ "เคยตั้งแล้ว")
                $up->execute([$pid, 'rate', $k, '', $v === '' ? $def[$k] : (float)$v, $by]);
            }
            if (isset($p['shopItems']) && is_array($p['shopItems'])) {
                $pdo->prepare("DELETE FROM sub_expense_config WHERE project_id = ? AND kind = 'shopItem'")
                    ->execute([$pid]);
                $i = 0;
                foreach ($p['shopItems'] as $it) {
                    if (!is_array($it)) continue;
                    $label = trim((string)($it['label'] ?? ''));
                    if ($label === '') continue;
                    $up->execute([$pid, 'shopItem', str_pad((string)$i, 2, '0', STR_PAD_LEFT),
                                  $label, (float)_seN($it['price'] ?? 0), $by]);
                    $i++;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['success' => true, 'config' => _seConfig($pdo, $pid)];
    } catch (Throwable $e) {
        error_log('saveSubExpenseConfig error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// ลายเซ็นรับทราบใบหักค่าใช้จ่าย (1 ใบ = 1 งวดของทั้งไซต์)
// DocNo = 'SE-{SITE}-{YYYYMM}-G{half}' — ใช้ทะเบียน sign_requests ร่วมกับใบหักเงิน
// ---------------------------------------------------------------------------

function _seSignDocNo(string $siteCode, string $ym, int $half): string {
    return 'SE-' . ($siteCode !== '' ? $siteCode : 'NA') . '-' . str_replace('-', '', $ym) . '-G' . $half;
}

/** getSubExpenseSignData({siteCode, ym, half, username}) — สถานะลงนามของงวด */
function rpc_getSubExpenseSignData(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_seCanUse($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];
        $period = _seParsePeriod($p);
        if (!$period) return ['success' => false, 'message' => 'bad_period'];

        $docNo = _seSignDocNo($site['siteCode'], $period['ym'], $period['half']);
        $rows  = _seLoadRows($pdo, $site['projectId'], $period['key']);
        $grand = 0.0;
        foreach ($rows as $r) { if ($r['totalAmt'] !== '') $grand += (float)$r['totalAmt']; }

        return [
            'success'   => true,
            'docNo'     => $docNo,
            'siteCode'  => $site['siteCode'], 'siteName' => $site['siteName'],
            'ym'        => $period['ym'], 'half' => $period['half'], 'period' => $period['key'],
            'periodLabel' => _sePeriodLabel($period['ym'], $period['half']),
            'rowCount'  => count($rows),
            'grandTotal' => round($grand, 2),
            'inspector' => _sigRowSummary(_sigFind($pdo, $docNo, 'inspector')),
            'approver'  => _sigRowSummary(_sigFind($pdo, $docNo, 'approver')),
            'contractor'=> _sigRowSummary(_sigFind($pdo, $docNo, 'contractor')),
        ];
    } catch (Throwable $e) {
        error_log('getSubExpenseSignData error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/** requestSubExpenseSignature({siteCode, ym, half, role, assignee, assigneePos, username}) */
function rpc_requestSubExpenseSignature(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_seCanUse($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];
        $period = _seParsePeriod($p);
        if (!$period) return ['success' => false, 'message' => 'bad_period'];

        $role = mb_strtolower(trim((string)($p['role'] ?? '')), 'UTF-8');
        if ($role !== 'inspector' && $role !== 'approver') return ['success' => false, 'message' => 'bad_role'];
        $assignee = trim((string)($p['assignee'] ?? ''));
        if ($assignee === '') return ['success' => false, 'message' => 'กรุณาเลือกผู้ลงนาม'];
        $st = $pdo->prepare("SELECT 1 FROM users WHERE username = ? AND status <> 'inactive' LIMIT 1");
        $st->execute([$assignee]);
        if (!$st->fetchColumn()) {
            return ['success' => false, 'message' => 'ไม่พบผู้ใช้ ' . $assignee . ' (หรือถูกระงับการใช้งาน)'];
        }

        $docNo = _seSignDocNo($site['siteCode'], $period['ym'], $period['half']);
        $daysLabel = _sePeriodLabel($period['ym'], $period['half']);
        $exist = _sigFind($pdo, $docNo, $role);
        if ($exist && $exist['status'] === 'pending') {
            return ['success' => false, 'message' => 'มีคำขอค้างอยู่แล้ว'];
        }
        if ($exist && in_array($exist['status'], ['signed', 'auto'], true)) {
            return ['success' => false, 'message' => 'งวดนี้ลงนามแล้ว'];
        }
        $by = trim((string)($user['username'] ?? ''));
        if ($exist) {
            $pdo->prepare(
                "UPDATE sign_requests SET assignee = ?, assignee_pos = ?, status = 'pending',
                        signer_name = NULL, signer_pos = NULL, signature_path = NULL, signed_at = NULL,
                        requested_by = ?, requested_at = NOW(), note = NULL
                  WHERE id = ?"
            )->execute([$assignee, trim((string)($p['assigneePos'] ?? '')), $by, (int)$exist['id']]);
        } else {
            $pdo->prepare(
                "INSERT INTO sign_requests
                   (doc_no, project_id, ym, sub_name, days_label, role, assignee, assignee_pos,
                    status, requested_by, requested_at)
                 VALUES (?, ?, ?, '', ?, ?, ?, ?, 'pending', ?, NOW())"
            )->execute([$docNo, $site['projectId'], $period['ym'], $daysLabel, $role,
                        $assignee, trim((string)($p['assigneePos'] ?? '')), $by]);
        }
        return ['success' => true, 'docNo' => $docNo, 'role' => $role,
                'sign' => _sigRowSummary(_sigFind($pdo, $docNo, $role))];
    } catch (Throwable $e) {
        error_log('requestSubExpenseSignature error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/** cancelSubExpenseSignRequest({siteCode, ym, half, role, username}) */
function rpc_cancelSubExpenseSignRequest(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_seCanUse($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];
        $period = _seParsePeriod($p);
        if (!$period) return ['success' => false, 'message' => 'bad_period'];
        $role = mb_strtolower(trim((string)($p['role'] ?? '')), 'UTF-8');
        $docNo = _seSignDocNo($site['siteCode'], $period['ym'], $period['half']);
        $row = _sigFind($pdo, $docNo, $role);
        if (!$row) return ['success' => false, 'message' => 'ไม่พบคำขอ'];
        if ($row['status'] !== 'pending') {
            return ['success' => false, 'message' => 'ใบนี้มีการลงนามแล้ว — ยกเลิกไม่ได้'];
        }
        $pdo->prepare("UPDATE sign_requests SET status = 'cancelled', token = NULL WHERE id = ?")
            ->execute([(int)$row['id']]);
        return ['success' => true, 'docNo' => $docNo];
    } catch (Throwable $e) {
        error_log('cancelSubExpenseSignRequest error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * generateSubExpensePDF(payload) — แบบฟอร์มรายการหักคจช. ทั้งงวด (ล้อ GAS v1.10)
 * payload = { siteCode, ym, half|period, projectName, preparerName, preparerPos,
 *             useInspector, inspectorName, inspectorPos, signer2Label, signer2Name, signer2Pos,
 *             includeDeduction, username }
 * includeDeduction = โหมด "พิมพ์รวม": ต่อท้ายด้วย สรุปงวดสแกนนิ้ว + ตารางหักเงิน ผรม.
 * ของงวดเดียวกันเป็น PDF ไฟล์เดียว (mirror hostSS ของ GAS — ลำดับหน้า: คจช. → สแกนนิ้ว → หักเงิน)
 */
function rpc_generateSubExpensePDF(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_seCanUse($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        require_once __DIR__ . '/pdf_engine.php';
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];
        $period = _seParsePeriod($p);
        if (!$period) return ['success' => false, 'message' => 'bad_period'];
        $pid   = $site['projectId'];
        $label = _sePeriodLabel($period['ym'], $period['half']);

        $rows = _seLoadRows($pdo, $pid, $period['key']);
        if (!$rows) {
            return ['success' => false,
                    'message' => 'ยังไม่มีข้อมูลหักค่าใช้จ่ายของงวด ' . $label . ' — กรุณากรอกและบันทึกก่อนพิมพ์เอกสาร'];
        }

        // ── หัวช่องจากตั้งค่าของไซต์ + ชื่อใช้เบิก Payment (Mango vendor ของชุด) ──
        $cfg = _seConfig($pdo, $pid);
        $shopHeader = '';
        if (!empty($cfg['shopItems'])) {
            $parts = [];
            foreach ($cfg['shopItems'] as $it) {
                $parts[] = (string)$it['label'] . ' ' . rtrim(rtrim(number_format((float)$it['price'], 2, '.', ''), '0'), '.');
            }
            $shopHeader = implode(', ', $parts);
        }
        if ($shopHeader === '') $shopHeader = 'ร้านค้า / เครื่องใช้';
        $fmtCfg = function ($v) { return rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.'); };
        $elecHeader  = 'ค่ามิเตอร์ไฟส่วนที่ใช้เกิน ' . $fmtCfg($cfg['elecFreeUnits']) . ' หน่วย (' . $fmtCfg($cfg['elecRate']) . 'บ./หน่วย)';
        $meterHeader = 'มิเตอร์ไฟ ร้านค้า (' . $fmtCfg($cfg['shopElecRate']) . ' บาท/หน่วย)';

        $paymentByName = [];   // subName → {name, code}
        $mq = $pdo->prepare(
            'SELECT s.name, sp.mango_vendor_code, sp.mango_vendor_name
               FROM subcontractors s
               JOIN sub_projects sp ON sp.sub_id = s.id AND sp.project_id = ? ORDER BY s.id'
        );
        $mq->execute([$pid]);
        foreach ($mq->fetchAll() as $r) {
            $nm = trim((string)$r['name']);
            if ($nm === '' || isset($paymentByName[$nm])) continue;
            $pay  = trim((string)($r['mango_vendor_name'] ?? ''));
            $code = trim((string)($r['mango_vendor_code'] ?? ''));
            if ($pay !== '' || $code !== '') $paymentByName[$nm] = ['name' => $pay, 'code' => $code];
        }

        // ── แถวข้อมูล: int = #,##0 · เงิน = #,##0.00 (mirror number formats ของชีต) ──
        $fmtInt   = function ($v) { return $v === '' ? '' : number_format(round((float)$v)); };
        $fmtMoney = function ($v) { return $v === '' ? '' : pdfMoney($v); };
        $intCols   = ['roomQty', 'elecUsed', 'elecOver', 'shopQty', 'shopElecPrev', 'shopElecCurr', 'shopElecUnits'];
        $moneyCols = ['roomRate', 'roomAmt', 'elecAmt', 'shopRate', 'shopAmt', 'shopElecAmt',
                      'materialAmt', 'advanceAmt', 'safetyFine', 'faceScanFine', 'totalAmt'];

        $tplRows = [];
        $sums = [];
        foreach (array_merge($intCols, $moneyCols) as $k) $sums[$k] = null;
        foreach ($rows as $i => $r) {
            // หมายเหตุ: ติ๊ก "พักข้างนอก" → ขึ้นต้นด้วยคำนี้เสมอ (กันสีหายตอนถ่ายเอกสารขาวดำ)
            $noteText = ($r['outsideStay'] ? 'พักข้างนอก' : '')
                      . ($r['note'] !== '' ? (($r['outsideStay'] ? ' · ' : '') . $r['note']) : '');
            $pm = $paymentByName[$r['subName']] ?? null;
            $payLines = [];
            if ($pm) {
                if ($pm['name'] !== '') $payLines[] = $pm['name'];
                if ($pm['code'] !== '') $payLines[] = $pm['code'];
            }
            $row = ['no' => $i + 1, 'subName' => (string)$r['subName'], 'payLines' => $payLines,
                    'outside' => (bool)$r['outsideStay'], 'note' => $noteText];
            foreach ($intCols as $k) {
                $row[$k] = $fmtInt($r[$k]);
                if ($r[$k] !== '') $sums[$k] = ($sums[$k] ?? 0) + (float)$r[$k];
            }
            foreach ($moneyCols as $k) {
                $row[$k] = $fmtMoney($r[$k]);
                if ($r[$k] !== '') $sums[$k] = ($sums[$k] ?? 0) + (float)$r[$k];
            }
            $tplRows[] = $row;
        }
        $totals = [];
        foreach ($intCols as $k)   $totals[$k] = $sums[$k] === null ? '' : $fmtInt($sums[$k]);
        foreach ($moneyCols as $k) $totals[$k] = $sums[$k] === null ? '' : $fmtMoney($sums[$k]);

        // ── ช่องเซ็น 2-3 คน + ภาพลายเซ็น (ผู้จัดทำ = คนสร้างเอกสาร · ตรวจสอบ/อนุมัติ = ลายเซ็นออนไลน์) ──
        $username = trim((string)($user['username'] ?? ''));
        $NAME_DOTS = '( .................................................. )';
        $preparerName = trim((string)($p['preparerName'] ?? ''));
        $preparerPos  = trim((string)($p['preparerPos'] ?? ''));
        $signer2Label = trim((string)($p['signer2Label'] ?? '')) !== '' ? (string)$p['signer2Label'] : 'ผู้อนุมัติ';
        $signer2Name  = trim((string)($p['signer2Name'] ?? ''));
        $signer2Pos   = trim((string)($p['signer2Pos'] ?? ''));
        $useInspector  = ($p['useInspector'] ?? false) === true;
        $inspectorName = trim((string)($p['inspectorName'] ?? ''));
        $inspectorPos  = trim((string)($p['inspectorPos'] ?? ''));

        $prepImg = '';
        if ($username !== '') {
            $uq = $pdo->prepare('SELECT signature_path FROM users WHERE username = ? LIMIT 1');
            $uq->execute([$username]);
            $rel = trim((string)$uq->fetchColumn());
            if ($rel !== '') $prepImg = _sigReadDataUrl($rel);
        }
        $seDocNo = _seSignDocNo($site['siteCode'], $period['ym'], $period['half']);
        $seIns = _sigFind($pdo, $seDocNo, 'inspector');
        $seApv = _sigFind($pdo, $seDocNo, 'approver');
        $insSigned = $seIns && $seIns['status'] === 'signed';
        $apvSigned = $seApv && $seApv['status'] === 'signed';
        $insImg = $insSigned ? _sigReadDataUrl((string)($seIns['signature_path'] ?? '')) : '';
        $apvImg = $apvSigned ? _sigReadDataUrl((string)($seApv['signature_path'] ?? '')) : '';

        $insShowName = $insSigned ? ('( ' . trim((string)$seIns['signer_name']) . ' )')
                     : ($inspectorName !== '' ? ('( ' . $inspectorName . ' )') : $NAME_DOTS);
        $insShowPos  = $insSigned ? (trim((string)($seIns['signer_pos'] ?? '')) !== '' ? (string)$seIns['signer_pos'] : $inspectorPos)
                     : $inspectorPos;
        $apvShowName = $apvSigned ? ('( ' . trim((string)$seApv['signer_name']) . ' )')
                     : ($signer2Name !== '' ? ('( ' . $signer2Name . ' )') : $NAME_DOTS);
        $apvShowPos  = $apvSigned ? (trim((string)($seApv['signer_pos'] ?? '')) !== '' ? (string)$seApv['signer_pos'] : $signer2Pos)
                     : $signer2Pos;

        $prepBlock = ['role' => 'ผู้จัดทำ',
                      'name' => $preparerName !== '' ? ('( ' . $preparerName . ' )') : $NAME_DOTS,
                      'pos'  => $preparerPos, 'img' => $prepImg];
        $signs = $useInspector
            ? [$prepBlock,
               ['role' => 'ผู้ตรวจสอบ', 'name' => $insShowName, 'pos' => $insShowPos, 'img' => $insImg],
               ['role' => $signer2Label, 'name' => $apvShowName, 'pos' => $apvShowPos, 'img' => $apvImg]]
            : [$prepBlock,
               ['role' => $signer2Label, 'name' => $apvShowName, 'pos' => $apvShowPos, 'img' => $apvImg]];
        $anySigImg = false;
        foreach ($signs as $sg) { if ($sg['img'] !== '') { $anySigImg = true; break; } }

        $now = nowBkk();
        $seVars = [
            'projectName' => trim((string)($p['projectName'] ?? '')) !== '' ? (string)$p['projectName']
                           : ($site['siteName'] !== '' ? $site['siteName'] : $site['siteCode']),
            'siteCode'    => $site['siteCode'],
            'siteName'    => (string)$site['siteName'],
            'periodLabel' => $label,
            'shopHeader'  => $shopHeader,
            'elecHeader'  => $elecHeader,
            'meterHeader' => $meterHeader,
            'rows'        => $tplRows,
            'totals'      => $totals,
            'signs'       => $signs,
            'anySigImg'   => $anySigImg,
            'genStamp'    => 'สร้างเมื่อ ' . $now->format('d/m/Y H:i') . ' น.' . ($username !== '' ? ' โดย ' . $username : ''),
        ];

        // ── โหมด "พิมพ์รวม": ต่อท้าย สรุปงวดสแกนนิ้ว + ตารางหักเงิน (ไฟล์เดียว — mirror hostSS) ──
        $fsRendered = 0; $fsNote = '';
        $dedRendered = 0; $dedNote = '';
        $extra = '';
        if (($p['includeDeduction'] ?? false) === true) {
            try {
                $fb = _fsBuildPdfDoc($pdo, $user, [
                    'siteCode' => $site['siteCode'], 'ym' => $period['ym'], 'half' => $period['half'],
                    'projectName' => (string)($p['projectName'] ?? ''),
                    'preparerName' => $preparerName, 'preparerPos' => $preparerPos,
                ]);
                if (!empty($fb['ok'])) {
                    $extra .= '<div style="page-break-before:always"></div>'
                            . pdfRenderTemplate('fingerscan_summary.php', $fb['vars'] + ['fragment' => true]);
                    $fsRendered = 1;
                } else {
                    $fsNote = (string)($fb['resp']['message'] ?? 'ไม่มีข้อมูลสแกนนิ้วของงวดนี้');
                }
            } catch (Throwable $e) { $fsNote = $e->getMessage(); }
            try {
                require_once __DIR__ . '/pdf_api.php';
                $db = _pdfBuildDeductionDoc($pdo, $user, [
                    'siteCode' => $site['siteCode'], 'ym' => $period['ym'], 'half' => $period['half'],
                    'showSummarizer' => true, 'summarizerName' => $preparerName, 'summarizerDept' => $preparerPos,
                    'summarizerFromIssuer' => true,   // ช่องผู้สรุปของแต่ละใบ = ผู้ออกใบนั้น
                    'inspectorPos' => ($useInspector && $inspectorPos !== '') ? $inspectorPos : 'PE / SSE',
                    'approverPos'  => $signer2Pos !== '' ? $signer2Pos : 'PM',
                    'projectName'  => (string)($p['projectName'] ?? ''),
                    'confirmOverlap' => true,
                ]);
                if (!empty($db['ok'])) {
                    $extra .= '<div style="page-break-before:always"></div>'
                            . pdfRenderTemplate('deduction.php', $db['vars'] + ['fragment' => true]);
                    $dedRendered = (int)$db['nDocs'];
                } else {
                    $dedNote = (string)($db['resp']['message'] ?? 'ไม่มีรายการหักเงินของงวดนี้');
                }
            } catch (Throwable $e) { $dedNote = $e->getMessage(); }
        }

        if ($extra !== '') {
            // ไฟล์รวม: เปลือกกลาง 1 ชั้น + เอกสารแต่ละใบเป็น fragment (CSS แยก prefix กันชนกัน)
            $html = '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8"><style>'
                  . '*{box-sizing:border-box;} html{margin:0;padding:0;}'
                  . "body{ margin:8mm 7mm 12mm 7mm; padding:0; font-family:'sarabun',sans-serif; }"
                  . '</style></head><body>'
                  . pdfRenderTemplate('subexpense.php', $seVars + ['fragment' => true])
                  . $extra
                  . '</body></html>';
        } else {
            $html = pdfRenderTemplate('subexpense.php', $seVars);
        }
        $bin = renderPdf($html, 'a4', 'landscape');

        $attachTxt = ($fsRendered > 0 ? '+สรุปสแกนนิ้ว' : '') . ($dedRendered > 0 ? '+ตารางหักเงิน' : '');
        $fileName = ($attachTxt !== '' ? ('หักคจช.' . $attachTxt . ' งวด') : 'รายการหักคจช. ผรม งวด')
                  . str_replace('วันที่ ', '', $label) . '_' . $site['siteCode'] . '.pdf';
        return ['success' => true, 'fileName' => $fileName,
                'dataUri' => 'data:application/pdf;base64,' . base64_encode($bin),
                'fsRendered' => $fsRendered, 'fsNote' => $fsNote,
                'dedRendered' => $dedRendered, 'dedNote' => $dedNote];
    } catch (Throwable $e) {
        error_log('generateSubExpensePDF error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
