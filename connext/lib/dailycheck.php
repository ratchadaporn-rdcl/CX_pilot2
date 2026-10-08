<?php
/**
 * CONNEXT — lib/dailycheck.php
 * ตรวจสอบประจำวัน / RateCard / จับคู่ Mango vendor
 * Port 1:1 จาก GAS Code.js:
 *   getDailyCheckData, setDailyCheckCharge, confirmDailyCheck,
 *   getRateCardData, saveRateCard, getSubMangoVendorData, saveSubMangoVendor
 *   (+ userCanDailyCheck_ → _dcCanDaily)
 *
 * หมายเหตุ rowIndex: GAS ชี้แถวชีตด้วย rowIndex (0-based ของ getDataRange)
 * พอร์ตนี้ใช้ document_items.id เป็นค่า rowIndex แทน — client ส่งกลับมา
 * ใน setDailyCheckCharge โดยไม่ต้องแก้โค้ดฝั่ง client (ค่า >= 1 เสมอ
 * จึงผ่านเงื่อนไข rowIndex < 1 → bad_request แบบเดิม)
 */

require_once __DIR__ . '/s05.php';   // s05EffQty — จำนวนหยิบจริง (Scenario 05 ⑦)

// =========================================================================
// helpers ภายในไฟล์
// =========================================================================

/**
 * สิทธิ์ CanDailyCheck — mirror userCanDailyCheck_ ของ GAS
 * server-authoritative: ใช้บัญชีจาก session แล้ว re-query DB เอา flag ล่าสุด
 * (GAS re-derive จากชีตทุกครั้ง — เปลี่ยนสิทธิ์แล้วมีผลโดยไม่ต้อง login ใหม่)
 */
function _dcCanDaily(PDO $pdo, ?array $user): bool {
    if (!$user) return false;
    if (($user['accountType'] ?? '') !== 'user') return false; // subcontractor ไม่มีสิทธิ์ (GAS: ไม่พบใน Users → false)
    try {
        $stmt = $pdo->prepare(
            "SELECT r.can_daily_check
             FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.id = ? LIMIT 1"
        );
        $stmt->execute([(int)($user['accountId'] ?? 0)]);
        $v = $stmt->fetchColumn();
        return $v !== false && (int)$v === 1;
    } catch (Throwable $e) {
        error_log('_dcCanDaily: ' . $e->getMessage());
        return false;
    }
}

/** project id จากรหัสโครงการ (SiteCode เดิม) — null ถ้าไม่พบ */
function _dcProjectIdByCode(PDO $pdo, string $code): ?int {
    if ($code === '') return null;
    $stmt = $pdo->prepare("SELECT id FROM projects WHERE code = ? LIMIT 1");
    $stmt->execute([$code]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
}

/**
 * รายชื่อ Site ทั้งหมด (ให้ R0 เลือก Site ได้) — mirror การอ่านชีต Sites ทั้งชีต
 * (GAS ไม่กรองสถานะ) เรียงตามลำดับแถวเดิม = ORDER BY id
 * คืน [sitesArray, siteNameMap]
 */
function _dcSitesList(PDO $pdo): array {
    $sites = [];
    $siteNameMap = [];
    $stmt = $pdo->query("SELECT code, name FROM projects ORDER BY id");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $c = trim((string)$row['code']);
        if ($c === '') continue;
        $nm = trim((string)($row['name'] ?? ''));
        $sites[] = ['code' => $c, 'name' => $nm];
        $siteNameMap[$c] = $nm;
    }
    return [$sites, $siteNameMap];
}

/** materials master ตามชุด mat_code → map code => {id,name,unit,subgroup} */
function _dcMaterialsByCodes(PDO $pdo, array $codes): array {
    $map = [];
    $codes = array_values(array_unique(array_filter(array_map('strval', $codes), function ($c) { return $c !== ''; })));
    if (!$codes) return $map;
    foreach (array_chunk($codes, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, mat_code, name, unit, subgroup_name FROM materials WHERE mat_code IN ($ph)"
        );
        $stmt->execute($chunk);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[trim((string)$r['mat_code'])] = [
                'id'       => (int)$r['id'],
                'name'     => trim((string)($r['name'] ?? '')),
                'unit'     => trim((string)($r['unit'] ?? '')),
                'subgroup' => trim((string)($r['subgroup_name'] ?? '')),
            ];
        }
    }
    return $map;
}

/** เทียบสตริงแบบ localeCompare(..., 'th') — ใช้ intl Collator ถ้ามี */
function _dcThaiCompare(string $a, string $b): int {
    static $coll = null, $tried = false;
    if (!$tried) {
        $tried = true;
        if (class_exists('Collator')) {
            try { $coll = new Collator('th_TH'); } catch (Throwable $e) { $coll = null; }
        }
    }
    if ($coll !== null) {
        $r = $coll->compare($a, $b);
        if ($r !== false) return (int)$r;
    }
    return strcmp($a, $b); // fallback: ไบต์ UTF-8 (อักษรไทยเรียงตาม codepoint ใกล้เคียงพจนานุกรม)
}

/** ฟอร์แมตวันเวลาแบบ _fmtDateTime_ ของ GAS: 'yyyy-MM-dd HH:mm' */
function _dcFmtDateTime($v): string {
    $s = trim((string)$v);
    if ($s === '') return '';
    $ts = strtotime($s);
    if ($ts === false) return $s;
    return date('Y-m-d H:i', $ts);
}

/**
 * สร้างข้อมูล days ของหน้าตรวจสอบประจำวัน — mirror getDailyCheckData ใน GAS
 * $wantSite = '' → ไม่กรอง Site (พฤติกรรมเดิม: เงื่อนไข `wantSite && ...`)
 * คืน array ของ {day, items, itemCount, chargedCount, confirmed, confirmedBy, confirmedAt}
 */
function _dcBuildDays(PDO $pdo, string $wantSite): array {
    // รายการ RD+OD สถานะ Completed (เทียบ case-insensitive แบบ GAS)
    // เรียง RD ก่อน OD แล้วตามลำดับแถวเดิม (GAS ไล่ RequisitionLogs ก่อน OddsLogs)
    // [Scenario 05] จำนวน = หยิบจริง (qty_actual) · แสดงจำนวนที่ขอเมื่อต่างกัน (qtyReq) · ใบ bypass ติดธง
    $sql = "SELECT di.id AS item_id, di.mat_code, di.mat_name, di.qty, di.qty_actual, di.actual_reason, di.charge_money,
                   d.doc_no, d.doc_type, d.receiver_name, d.doc_ts, d.origin_type,
                   m.name AS master_name, m.subgroup_name AS master_subgroup
            FROM document_items di
            JOIN documents d ON d.id = di.document_id
            JOIN projects p  ON p.id = d.project_id
            LEFT JOIN materials m ON m.mat_code = di.mat_code
            WHERE d.doc_type IN ('RD','OD')
              AND LOWER(d.status) = 'completed'";
    $params = [];
    if ($wantSite !== '') {
        $sql .= " AND p.code = ?";
        $params[] = $wantSite;
    }
    $sql .= " ORDER BY FIELD(d.doc_type,'RD','OD'), d.id, di.id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $dayMap = []; // day => ['day'=>..., 'items'=>[]]
    while (($r = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
        $docTs = (string)$r['doc_ts'];
        if ($docTs === '') continue;
        $day = substr($docTs, 0, 10); // DATETIME → 'yyyy-MM-dd'
        $matCode = trim((string)$r['mat_code']);
        $snapName = trim((string)($r['mat_name'] ?? ''));
        $masterName = trim((string)($r['master_name'] ?? ''));
        // ชื่อ: GAS — OddsLogs มีคอลัมน์ Name (ใช้ค่าในแถวก่อน), RequisitionLogs ไม่มี
        // (ใช้ชื่อจาก MaterialsMain) → พอร์ต: OD ใช้ snapshot ก่อน, RD ใช้ master ก่อน
        if ($r['doc_type'] === 'OD') {
            $nm = $snapName !== '' ? $snapName : ($masterName !== '' ? $masterName : $matCode);
        } else {
            $nm = $masterName !== '' ? $masterName : ($snapName !== '' ? $snapName : $matCode);
        }
        $recv = (string)($r['receiver_name'] ?? '');
        if (!isset($dayMap[$day])) $dayMap[$day] = ['day' => $day, 'items' => []];
        $effQty = s05EffQty($r);
        $row = [
            'type'     => (string)$r['doc_type'],
            'docId'    => trim((string)$r['doc_no']),
            'rowIndex' => (int)$r['item_id'],           // แทน rowIndex ของชีต — ส่งกลับใน setDailyCheckCharge
            'matCode'  => $matCode,
            'name'     => $nm,
            'subName'  => $recv !== '' ? $recv : '-',
            'subgroup' => trim((string)($r['master_subgroup'] ?? '')),
            'qty'      => $effQty,
            'charge'   => ((int)$r['charge_money'] === 1),
        ];
        if (abs($effQty - (float)$r['qty']) > 0.0005) {
            $row['qtyReq']       = (float)$r['qty'];                 // จำนวนที่ขอ (แสดงเมื่อหยิบจริงต่างจากที่ขอ ⑦)
            $row['actualReason'] = (string)($r['actual_reason'] ?? '');
        }
        if (strtolower(trim((string)($r['origin_type'] ?? ''))) === 'bypass') {
            $row['bypass'] = true;                                    // คีย์ย้อนหลังจากแบบฟอร์มกระดาษ (⑩)
        }
        $dayMap[$day]['items'][] = $row;
    }

    // วันที่ยืนยันแล้ว (DailyCheck เดิม) — คีย์ siteCode|day
    $confirmedMap = [];
    $stmt = $pdo->query(
        "SELECT p.code AS site_code, dc.check_date, dc.confirmed_by, dc.confirmed_at
         FROM daily_check_confirms dc JOIN projects p ON p.id = dc.project_id"
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rd = trim((string)$r['check_date']);
        if ($rd === '') continue;
        $rsc = trim((string)$r['site_code']);
        $confirmedMap[$rsc . '|' . $rd] = [
            'confirmedBy' => (string)($r['confirmed_by'] ?? ''),
            'confirmedAt' => _dcFmtDateTime($r['confirmed_at'] ?? ''),
        ];
    }

    $days = [];
    foreach ($dayMap as $day => $grp) {
        $conf = $confirmedMap[$wantSite . '|' . $day] ?? null;
        $chargedCount = 0;
        foreach ($grp['items'] as $it) { if ($it['charge']) $chargedCount++; }
        $days[] = [
            'day'          => $day,
            'items'        => $grp['items'],
            'itemCount'    => count($grp['items']),
            'chargedCount' => $chargedCount,
            'confirmed'    => $conf !== null,
            'confirmedBy'  => $conf ? $conf['confirmedBy'] : '',
            'confirmedAt'  => $conf ? $conf['confirmedAt'] : '',
        ];
    }
    // ยังไม่ยืนยันขึ้นก่อน, ในแต่ละกลุ่มเรียงวันล่าสุดก่อน
    usort($days, function ($a, $b) {
        if ($a['confirmed'] !== $b['confirmed']) return $a['confirmed'] ? 1 : -1;
        return strcmp($b['day'], $a['day']);
    });
    return array_values($days);
}

// =========================================================================
// getDailyCheckData(siteCode, username, roleName)
// =========================================================================
function rpc_getDailyCheckData(PDO $pdo, ?array $user, array $args) {
    if (!_dcCanDaily($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
    try {
        $wantSite = trim((string)($args[0] ?? '')); // roleName (args[2]) — GAS ก็ไม่ใช้
        $days = _dcBuildDays($pdo, $wantSite);
        return ['success' => true, 'days' => $days, 'siteCode' => $wantSite];
    } catch (Throwable $err) {
        error_log('getDailyCheckData Error: ' . $err->getMessage());
        return ['success' => false, 'message' => $err->getMessage()];
    }
}

// =========================================================================
// setDailyCheckCharge({type, docId, rowIndex, charge, username})
// rowIndex = document_items.id (ค่าที่ getDailyCheckData ส่งไป — client ส่งกลับตรงๆ)
// =========================================================================
function rpc_setDailyCheckCharge(PDO $pdo, ?array $user, array $args) {
    if (!_dcCanDaily($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
    try {
        $p = is_array($args[0] ?? null) ? $args[0] : [];
        $type  = strtoupper(trim((string)($p['type'] ?? '')));
        $docId = trim((string)($p['docId'] ?? ''));
        $ri    = $p['rowIndex'] ?? null;
        $charge = isTrueFlag($p['charge'] ?? false);
        if ($type !== 'RD' && $type !== 'OD') return ['success' => false, 'message' => 'bad_request'];
        if (!is_numeric($ri)) return ['success' => false, 'message' => 'bad_request'];
        $rowIndex = (int)$ri;
        if ($rowIndex < 1) return ['success' => false, 'message' => 'bad_request'];

        $stmt = $pdo->prepare(
            "SELECT di.id, d.doc_no, d.doc_type
             FROM document_items di JOIN documents d ON d.id = di.document_id
             WHERE di.id = ? LIMIT 1"
        );
        $stmt->execute([$rowIndex]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['success' => false, 'message' => 'row_not_found'];
        if ((string)$row['doc_type'] !== $type || trim((string)$row['doc_no']) !== $docId) {
            return ['success' => false, 'message' => 'row_mismatch'];
        }

        $upd = $pdo->prepare("UPDATE document_items SET charge_money = ? WHERE id = ?");
        $upd->execute([$charge ? 1 : 0, $rowIndex]);
        return ['success' => true, 'charge' => $charge];
    } catch (Throwable $err) {
        error_log('setDailyCheckCharge Error: ' . $err->getMessage());
        return ['success' => false, 'message' => $err->getMessage()];
    }
}

// =========================================================================
// confirmDailyCheck(siteCode, date, username) — upsert daily_check_confirms
// =========================================================================
function rpc_confirmDailyCheck(PDO $pdo, ?array $user, array $args) {
    if (!_dcCanDaily($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
    try {
        $site = trim((string)($args[0] ?? ''));
        $day  = trim((string)($args[1] ?? ''));
        if ($day === '') return ['success' => false, 'message' => 'bad_request'];
        // GAS เขียนสตริงวันลงชีตตรงๆ — DATE ของ MySQL ต้องเป็นรูปแบบถูกต้อง
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            return ['success' => false, 'message' => 'bad_request'];
        }
        $projectId = _dcProjectIdByCode($pdo, $site);
        if ($projectId === null) return ['success' => false, 'message' => 'ไม่พบ Site'];

        // นับจำนวนรายการ Completed ของวันนั้น (server-authoritative — เหมือน GAS
        // ที่เรียก getDailyCheckData ภายใน)
        $itemCount = 0;
        foreach (_dcBuildDays($pdo, $site) as $d) {
            if ($d['day'] === $day) { $itemCount = $d['itemCount']; break; }
        }

        $callerName = (string)($user['username'] ?? '');
        $stmt = $pdo->prepare(
            "INSERT INTO daily_check_confirms (project_id, check_date, confirmed_by, confirmed_at, item_count, notes)
             VALUES (?, ?, ?, NOW(), ?, '')
             ON DUPLICATE KEY UPDATE
                confirmed_by = VALUES(confirmed_by),
                confirmed_at = VALUES(confirmed_at),
                item_count   = VALUES(item_count)"
        );
        $stmt->execute([$projectId, $day, $callerName, $itemCount]);
        return ['success' => true, 'day' => $day, 'itemCount' => $itemCount];
    } catch (Throwable $err) {
        error_log('confirmDailyCheck Error: ' . $err->getMessage());
        return ['success' => false, 'message' => $err->getMessage()];
    }
}

// =========================================================================
// getRateCardData(siteCode, username)
// วัสดุ = union(เคยถูกหักเงินใน Site (RD+OD ทุกสถานะ — GAS ไม่กรองสถานะ),
//               มีราคาบันทึกไว้ใน RateCard) · ราคา NULL → '' (ไม่ตั้งราคา ≠ 0)
// =========================================================================
function rpc_getRateCardData(PDO $pdo, ?array $user, array $args) {
    if (!_dcCanDaily($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
    try {
        list($sites, $siteNameMap) = _dcSitesList($pdo);

        $wantSite = trim((string)($args[0] ?? ''));
        if ($wantSite === '' && count($sites)) $wantSite = $sites[0]['code']; // R0 ยังไม่เลือก → Site แรก
        if ($wantSite === '') return ['success' => false, 'message' => 'ไม่พบ Site'];

        $projectId = _dcProjectIdByCode($pdo, $wantSite);

        // วัสดุที่เคยถูกหักเงิน — นับครั้ง + เก็บชื่อจากแถวแรกที่เจอ
        // (ลำดับเดิม: RequisitionLogs ก่อน OddsLogs; RD ไม่มีคอลัมน์ Name)
        $charged = []; // matCode => ['count'=>n, 'name'=>..., 'nameFinal'=>bool]
        if ($projectId !== null) {
            $stmt = $pdo->prepare(
                "SELECT di.mat_code, di.mat_name, d.doc_type
                 FROM document_items di JOIN documents d ON d.id = di.document_id
                 WHERE d.project_id = ? AND d.doc_type IN ('RD','OD') AND di.charge_money = 1
                 ORDER BY FIELD(d.doc_type,'RD','OD'), d.id, di.id"
            );
            $stmt->execute([$projectId]);
            while (($r = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $c = trim((string)$r['mat_code']);
                if ($c === '') continue;
                if (!isset($charged[$c])) $charged[$c] = ['count' => 0, 'name' => ''];
                $charged[$c]['count']++;
                if ($charged[$c]['name'] === '') {
                    // GAS: rowName เฉพาะ OddsLogs (มีคอลัมน์ Name); fallback ชื่อ master เติมทีหลัง
                    $rowName = ($r['doc_type'] === 'OD') ? trim((string)($r['mat_name'] ?? '')) : '';
                    if ($rowName !== '') $charged[$c]['name'] = $rowName;
                }
            }
        }

        // ราคาที่บันทึกไว้ของ Site นี้
        $savedMap = []; // matCode => ['price'=>float|'', 'updatedAt'=>string]
        if ($projectId !== null) {
            $stmt = $pdo->prepare(
                "SELECT m.mat_code, rc.unit_price, rc.updated_at
                 FROM rate_cards rc JOIN materials m ON m.id = rc.material_id
                 WHERE rc.project_id = ?"
            );
            $stmt->execute([$projectId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $c = trim((string)$r['mat_code']);
                if ($c === '') continue;
                $savedMap[$c] = [
                    'price'     => ($r['unit_price'] === null || $r['unit_price'] === '') ? '' : (float)$r['unit_price'],
                    'updatedAt' => _dcFmtDateTime($r['updated_at'] ?? ''),
                ];
            }
        }

        $allCodes = array_values(array_unique(array_merge(array_keys($charged), array_keys($savedMap))));
        $matMap = _dcMaterialsByCodes($pdo, $allCodes);

        $rates = [];
        foreach ($allCodes as $c) {
            $m = $matMap[$c] ?? ['name' => '', 'unit' => '', 'subgroup' => ''];
            $chName = isset($charged[$c]) ? $charged[$c]['name'] : '';
            $rates[] = [
                'matCode'      => $c,
                'name'         => $chName !== '' ? $chName : ($m['name'] !== '' ? $m['name'] : $c),
                'unit'         => $m['unit'],
                'subgroup'     => $m['subgroup'],
                'price'        => isset($savedMap[$c]) ? $savedMap[$c]['price'] : '',
                'chargedCount' => isset($charged[$c]) ? $charged[$c]['count'] : 0,
                'updatedAt'    => isset($savedMap[$c]) ? $savedMap[$c]['updatedAt'] : '',
            ];
        }
        usort($rates, function ($a, $b) {
            $ac = $a['chargedCount'] > 0; $bc = $b['chargedCount'] > 0;
            if ($ac !== $bc) return $ac ? -1 : 1;                       // มีหักเงินขึ้นก่อน
            return _dcThaiCompare((string)$a['name'], (string)$b['name']);
        });

        return [
            'success'  => true,
            'siteCode' => $wantSite,
            'siteName' => $siteNameMap[$wantSite] ?? '',
            'sites'    => $sites,
            'rates'    => array_values($rates),
        ];
    } catch (Throwable $err) {
        error_log('getRateCardData Error: ' . $err->getMessage());
        return ['success' => false, 'message' => $err->getMessage()];
    }
}

// =========================================================================
// saveRateCard({siteCode, username, rates:[{matCode, price}]}) — batch upsert
// ราคาว่าง/parse ไม่ได้/ติดลบ → NULL (ล้างราคา — พฤติกรรม priceVal='' ของ GAS)
// =========================================================================
function rpc_saveRateCard(PDO $pdo, ?array $user, array $args) {
    if (!_dcCanDaily($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
    try {
        $p = is_array($args[0] ?? null) ? $args[0] : [];
        $siteCode = trim((string)($p['siteCode'] ?? ''));
        $incoming = (isset($p['rates']) && is_array($p['rates'])) ? $p['rates'] : [];
        if ($siteCode === '') return ['success' => false, 'message' => 'ไม่ระบุ Site'];
        if (!count($incoming)) return ['success' => true, 'saved' => 0];

        $projectId = _dcProjectIdByCode($pdo, $siteCode);
        if ($projectId === null) return ['success' => false, 'message' => 'ไม่พบ Site'];

        // mat_code → material_id (GAS เขียนแถวได้แม้ไม่พบใน master — DB มี FK จึงต้องพบ)
        $codes = [];
        foreach ($incoming as $rt) {
            $mc = trim((string)(is_array($rt) ? ($rt['matCode'] ?? '') : ''));
            if ($mc !== '') $codes[] = $mc;
        }
        $matMap = _dcMaterialsByCodes($pdo, $codes);

        $username = (string)($user['username'] ?? ''); // server-authoritative (GAS ใช้ payload.username)
        $saved = 0;
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO rate_cards (project_id, material_id, unit_price, updated_by)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    unit_price = VALUES(unit_price),
                    updated_by = VALUES(updated_by),
                    updated_at = NOW()"
            );
            foreach ($incoming as $rt) {
                if (!is_array($rt)) continue;
                $mc = trim((string)($rt['matCode'] ?? ''));
                if ($mc === '') continue;
                if (!isset($matMap[$mc])) continue; // ไม่พบใน materials master — ข้าม (ดู REPORT)
                $priceVal = null; // NULL = ยังไม่ตั้งราคา/ล้างราคา
                $pr = $rt['price'] ?? '';
                if ($pr !== '' && $pr !== null) {
                    if (is_numeric($pr)) {
                        $f = (float)$pr;
                        if ($f >= 0) $priceVal = $f;
                    }
                }
                $stmt->execute([$projectId, $matMap[$mc]['id'], $priceVal, $username]);
                $saved++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['success' => true, 'saved' => $saved];
    } catch (Throwable $err) {
        error_log('saveRateCard Error: ' . $err->getMessage());
        return ['success' => false, 'message' => $err->getMessage()];
    }
}

// =========================================================================
// getSubMangoVendorData(siteCode, username)
// ชุดที่ถูกหักเงินใน Site (RD+OD ทุกสถานะ) → ตัวเลือกจาก sub_mango_map (จับด้วย
// sub_code) + fallback รายการ mango_vendors ทั้งหมดเมื่อบางชุดไม่มีตัวเลือก
// =========================================================================
function rpc_getSubMangoVendorData(PDO $pdo, ?array $user, array $args) {
    if (!_dcCanDaily($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
    try {
        list($sites, $siteNameMap) = _dcSitesList($pdo);

        $wantSite = trim((string)($args[0] ?? ''));
        if ($wantSite === '' && count($sites)) $wantSite = $sites[0]['code'];
        if ($wantSite === '') return ['success' => false, 'message' => 'ไม่พบ Site'];

        $projectId = _dcProjectIdByCode($pdo, $wantSite);

        // Subcontracts ทั้งหมด (ทุก Site — เหมือน GAS): SubID → ข้อมูล (แถวหลังทับแถวก่อน)
        $subById = [];      // sid => ['subName'=>, 'selCode'=>, 'selName'=>]
        $subIdByName = [];  // lower(SubName) => sid
        // ทะเบียนกลาง: ยังคืน "ทุกชุด" แบบ GAS แต่ค่าจับคู่ Mango อ่านจาก sub_projects
        // ของโครงการนี้ (LEFT JOIN — ชุดที่ยังไม่ผูกโครงการนี้ = ยังไม่จับคู่)
        $stmt = $pdo->prepare(
            "SELECT s.sub_code, s.name, sp.mango_vendor_code, sp.mango_vendor_name
             FROM subcontractors s
             LEFT JOIN sub_projects sp ON sp.sub_id = s.id AND sp.project_id = ?
             ORDER BY s.id"
        );
        $stmt->execute([$projectId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $sid = fmtSubId($r['sub_code']);
            if ($sid === '') continue;
            $nm = trim((string)($r['name'] ?? ''));
            $subById[$sid] = [
                'subName' => $nm,
                'selCode' => trim((string)($r['mango_vendor_code'] ?? '')),
                'selName' => trim((string)($r['mango_vendor_name'] ?? '')),
            ];
            if ($nm !== '') $subIdByName[mb_strtolower($nm, 'UTF-8')] = $sid;
        }

        // sub_mango_map: SubID → [{code, name}] (ตัวเลือกที่ให้เลือก)
        $optById = [];
        $stmt = $pdo->query("SELECT sub_code, vendor_code, vendor_name FROM sub_mango_map ORDER BY id");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $sid = fmtSubId($r['sub_code']);
            if ($sid === '') continue;
            $code = trim((string)($r['vendor_code'] ?? ''));
            $name = trim((string)($r['vendor_name'] ?? ''));
            if ($code === '' && $name === '') continue;
            if (!isset($optById[$sid])) $optById[$sid] = [];
            $optById[$sid][] = ['code' => $code, 'name' => $name];
        }

        // ชุดที่ถูกหักเงิน (ChargeMoney=TRUE) ใน Site นี้ — Receiver เป็น SubName
        // → resolve เป็น SubID; ข้าม DC: และค่าว่าง (นับต่อแถวรายการเหมือนชีตเดิม)
        $chargedSub = []; // sid => count
        if ($projectId !== null) {
            $stmt = $pdo->prepare(
                "SELECT d.receiver_name
                 FROM document_items di JOIN documents d ON d.id = di.document_id
                 WHERE d.project_id = ? AND d.doc_type IN ('RD','OD') AND di.charge_money = 1
                 ORDER BY FIELD(d.doc_type,'RD','OD'), d.id, di.id"
            );
            $stmt->execute([$projectId]);
            while (($r = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $recv = trim((string)($r['receiver_name'] ?? ''));
                if ($recv === '' || strpos($recv, 'DC:') === 0) continue;
                $sid = $subIdByName[mb_strtolower($recv, 'UTF-8')] ?? null;   // SubName → SubID
                if ($sid === null) {
                    $f = fmtSubId($recv);                                     // เผื่อแถวเก่าที่เก็บ SubID
                    if (isset($subById[$f])) $sid = $f;
                }
                if ($sid === null) continue;
                $chargedSub[$sid] = ($chargedSub[$sid] ?? 0) + 1;
            }
        }

        $subs = [];
        foreach ($chargedSub as $sid => $cnt) {
            $info = $subById[$sid] ?? ['subName' => $sid, 'selCode' => '', 'selName' => ''];
            $subs[] = [
                'subId'        => (string)$sid,
                'subName'      => $info['subName'] !== '' ? $info['subName'] : (string)$sid,
                'options'      => $optById[$sid] ?? [],
                'selectedCode' => $info['selCode'],
                'selectedName' => $info['selName'],
                'chargedCount' => $cnt,
            ];
        }
        usort($subs, function ($a, $b) {
            return _dcThaiCompare((string)$a['subName'], (string)$b['subName']);
        });

        // ตัวเลือกสำรอง: MangoVendors ทั้งหมด — เฉพาะเมื่อมีชุดที่ไม่มีตัวเลือก
        $allVendors = [];
        $needAll = false;
        foreach ($subs as $s) {
            if (!count($s['options'])) { $needAll = true; break; }
        }
        if ($needAll) {
            $stmt = $pdo->query("SELECT vendor_code, vendor_name FROM mango_vendors ORDER BY id");
            $seen = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $code = trim((string)($r['vendor_code'] ?? ''));
                if ($code === '' || isset($seen[$code])) continue;
                $seen[$code] = true;
                $allVendors[] = ['code' => $code, 'name' => trim((string)($r['vendor_name'] ?? ''))];
            }
            usort($allVendors, function ($a, $b) {
                return _dcThaiCompare((string)$a['name'], (string)$b['name']);
            });
        }

        return [
            'success'    => true,
            'siteCode'   => $wantSite,
            'siteName'   => $siteNameMap[$wantSite] ?? '',
            'sites'      => $sites,
            'subs'       => array_values($subs),
            'allVendors' => array_values($allVendors),
        ];
    } catch (Throwable $err) {
        error_log('getSubMangoVendorData Error: ' . $err->getMessage());
        return ['success' => false, 'message' => $err->getMessage()];
    }
}

// =========================================================================
// saveSubMangoVendor({siteCode, username, selections:[{subId, code}]})
// เขียนกลับ sub_projects.mango_vendor_code/name (รายโครงการ) จับด้วย sub_code
// code='' = ล้างค่า · ชื่อดึงจาก mango_vendors master (กันชื่อไม่ตรง code)
// =========================================================================
function rpc_saveSubMangoVendor(PDO $pdo, ?array $user, array $args) {
    if (!_dcCanDaily($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
    try {
        $p = is_array($args[0] ?? null) ? $args[0] : [];
        $selections = (isset($p['selections']) && is_array($p['selections'])) ? $p['selections'] : [];
        if (!count($selections)) return ['success' => true, 'saved' => 0];

        // ทะเบียนกลาง: การจับคู่ Mango เป็นรายโครงการ → ต้องรู้ว่าโครงการไหน
        $siteCode  = trim((string)($p['siteCode'] ?? ''));
        $projectId = $siteCode !== '' ? _dcProjectIdByCode($pdo, $siteCode) : ($user ? (int)$user['projectId'] : 0);
        if (!$projectId) return ['success' => false, 'message' => 'ไม่ระบุ Site'];

        // MangoVendors master: code → name (เฉพาะ code ที่ถูกส่งมา)
        $codes = [];
        foreach ($selections as $sel) {
            $c = trim((string)(is_array($sel) ? ($sel['code'] ?? '') : ''));
            if ($c !== '') $codes[] = $c;
        }
        $nameByCode = [];
        $codes = array_values(array_unique($codes));
        if ($codes) {
            foreach (array_chunk($codes, 500) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $stmt = $pdo->prepare("SELECT vendor_code, vendor_name FROM mango_vendors WHERE vendor_code IN ($ph)");
                $stmt->execute($chunk);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $nameByCode[trim((string)$r['vendor_code'])] = trim((string)($r['vendor_name'] ?? ''));
                }
            }
        }

        $saved = 0;
        $pdo->beginTransaction();
        try {
            $chk = $pdo->prepare("SELECT id FROM subcontractors WHERE sub_code = ?");
            // upsert ลง sub_projects — ชุดที่ยังไม่ผูกโครงการนี้ ให้ผูกให้เลย (enabled ตามค่าเริ่มต้น)
            $upd = $pdo->prepare(
                "INSERT INTO sub_projects (sub_id, project_id, mango_vendor_code, mango_vendor_name)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE mango_vendor_code = VALUES(mango_vendor_code),
                                         mango_vendor_name = VALUES(mango_vendor_name)"
            );
            foreach ($selections as $sel) {
                if (!is_array($sel)) continue;
                $sid = fmtSubId($sel['subId'] ?? '');
                if ($sid === '') continue;
                $chk->execute([$sid]);
                $subRowId = $chk->fetchColumn();
                if ($subRowId === false) continue; // ไม่พบชุดนี้ — ข้าม (rowBySid เดิม)
                $code = trim((string)($sel['code'] ?? ''));
                $name = $code !== '' ? ($nameByCode[$code] ?? '') : '';
                $upd->execute([
                    (int)$subRowId,
                    $projectId,
                    $code !== '' ? $code : null,
                    $name !== '' ? $name : null,
                ]);
                $saved++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['success' => true, 'saved' => $saved];
    } catch (Throwable $err) {
        error_log('saveSubMangoVendor Error: ' . $err->getMessage());
        return ['success' => false, 'message' => $err->getMessage()];
    }
}
