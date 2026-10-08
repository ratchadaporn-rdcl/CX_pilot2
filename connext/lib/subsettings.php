<?php
/**
 * CONNEXT — lib/subsettings.php : หน้า "ตั้งค่าผู้รับเหมา" (port 1:1 จาก Code.js v1.8-1.10)
 *
 * ครอบคลุมฟังก์ชัน GAS: getSubSettingsData, saveSubSettings, addSubcontractor,
 * renameSubcontractor, remapSubcontractor, addSubMangoOption, removeSubMangoOption,
 * setSubMango, getSubMangoHistory, getSubcontractorSignature, saveSubcontractorSignature
 *
 * สิทธิ์ (GAS v1.9.8):
 *   BS หรือ R0 → แก้ได้ทุกอย่าง            (_ssCanBS)
 *   SC         → เห็นรายชื่อ + ตั้งลายเซ็นให้ชุดได้อย่างเดียว (_ssCanView)
 *
 * ต่างจาก GAS ตรงชั้นข้อมูล (ทะเบียนกลาง — ดู CHANGES-FROM-GAS.md ข้อ 17):
 *   Subcontracts:Status         → sub_projects.enabled          (เปิด/ปิดใช้ "ในโครงการนี้")
 *   Subcontracts:MangoVendor*   → sub_projects.mango_vendor_*   (จับคู่รายโครงการ)
 *   SubMangoMap                 → sub_mango_map (คีย์ sub_code — ตัวเลือกของ "ชุด" ใช้ร่วมทุกโครงการ)
 *   SubMangoHistory             → sub_mango_history
 *   RemapLogs                   → remap_logs
 *   การเปลี่ยนชื่อชุด: GAS ต้องไล่เขียนทับชื่อทุกชีต — ที่นี่ประวัติผูกด้วย FK (receiver_sub_id)
 *   จึงตามชื่อใหม่เอง เหลือแค่ปรับ documents.receiver_name ที่เป็นสำเนาไว้แสดงผล
 */

// ---------------------------------------------------------------------------
// สิทธิ์ + ขอบเขตโครงการ
// ---------------------------------------------------------------------------

/** ธง sc/bs ของ role ผู้เรียก (subcontractor = ไม่มีสิทธิ์เสมอ ตาม GAS ที่หาใน Users ไม่เจอ) */
function _ssRoleFlags(PDO $pdo, ?array $user): array {
    $none = ['sc' => false, 'bs' => false, 'level' => 99];
    if (!$user || ($user['accountType'] ?? '') !== 'user') return $none;
    try {
        $stmt = $pdo->prepare(
            "SELECT r.sc, r.bs, r.level FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.id = ? LIMIT 1"
        );
        $stmt->execute([(int)($user['accountId'] ?? 0)]);
        $row = $stmt->fetch();
        if (!$row) return $none;
        return ['sc' => (int)$row['sc'] === 1, 'bs' => (int)$row['bs'] === 1, 'level' => (int)$row['level']];
    } catch (Throwable $e) {
        error_log('_ssRoleFlags: ' . $e->getMessage());
        return $none;
    }
}

/** _userCanBSPage_ : BS หรือ R0 → แก้ได้ทุกอย่างในหน้านี้ */
function _ssCanBS(PDO $pdo, ?array $user): bool {
    $f = _ssRoleFlags($pdo, $user);
    return $f['bs'] || $f['level'] === 0;
}

/** _userCanSubSettingsView_ : BS/R0 หรือ SC → เข้าหน้าได้ (SC แก้ได้เฉพาะลายเซ็น) */
function _ssCanView(PDO $pdo, ?array $user): bool {
    $f = _ssRoleFlags($pdo, $user);
    return $f['bs'] || $f['level'] === 0 || $f['sc'];
}

/**
 * _seResolveSite_ : R0 เลือกไซต์ได้จาก payload · ที่เหลือถูกล็อกไซต์ตัวเองเสมอ
 * คืน ['projectId', 'siteCode', 'siteName', 'isAdmin', 'sites' => [{code,name}]]
 */
function _ssResolveSite(PDO $pdo, ?array $user, array $p): array {
    $sites = [];
    $nameByCode = [];
    foreach ($pdo->query("SELECT code, name FROM projects WHERE status = 'active' ORDER BY name, code") as $r) {
        $c = trim((string)$r['code']);
        if ($c === '') continue;
        $n = trim((string)$r['name']);
        $sites[] = ['code' => $c, 'name' => $n];
        $nameByCode[$c] = $n;
    }
    $flags   = _ssRoleFlags($pdo, $user);
    $isAdmin = $flags['level'] === 0;
    $want    = trim((string)($p['siteCode'] ?? ''));
    $ownCode = trim((string)($user['siteCode'] ?? ''));
    if (!$isAdmin && $ownCode !== '') $want = $ownCode;      // non-R0: บังคับไซต์ตัวเอง
    if ($want === '' && $sites)       $want = $sites[0]['code'];

    $projectId = null;
    if ($want !== '') {
        $stmt = $pdo->prepare("SELECT id FROM projects WHERE code = ? LIMIT 1");
        $stmt->execute([$want]);
        $id = $stmt->fetchColumn();
        if ($id !== false) $projectId = (int)$id;
    }
    return [
        'projectId' => $projectId,
        'siteCode'  => $want,
        'siteName'  => $nameByCode[$want] ?? '',
        'isAdmin'   => $isAdmin,
        'sites'     => $sites,
    ];
}

/** ผู้กระทำ (ลง log) — GAS ใช้ username */
function _ssActor(?array $user): string {
    return trim((string)($user['username'] ?? ''));
}

/** ชื่อผู้ขาย Mango จาก master (กัน code↔name ไม่ตรงกัน) — '' ถ้าไม่รู้จัก */
function _ssVendorName(PDO $pdo, string $code): string {
    if ($code === '') return '';
    $stmt = $pdo->prepare("SELECT vendor_name FROM mango_vendors WHERE vendor_code = ? LIMIT 1");
    $stmt->execute([$code]);
    $n = $stmt->fetchColumn();
    return $n === false ? '' : trim((string)$n);
}

/** แถวชุดในโครงการนี้ (join sub_projects) — null ถ้าไม่ได้ผูกกับโครงการนี้ */
function _ssSubRow(PDO $pdo, int $projectId, string $subCode): ?array {
    $stmt = $pdo->prepare(
        "SELECT s.id, s.sub_code, s.name, s.status, s.signature_path,
                sp.enabled, sp.mango_vendor_code, sp.mango_vendor_name
         FROM subcontractors s
         JOIN sub_projects sp ON sp.sub_id = s.id AND sp.project_id = ?
         WHERE s.sub_code = ? LIMIT 1"
    );
    $stmt->execute([$projectId, $subCode]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** บันทึกประวัติการจับคู่ Mango  [SubMangoHistory] */
function _ssMangoLog(PDO $pdo, int $projectId, int $subId, string $subName, string $action,
                     string $fromCode, string $fromName, string $toCode, string $toName, string $by): void {
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO sub_mango_history
               (sub_id, project_id, sub_name, action, from_code, from_name, to_code, to_name, changed_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $subId, $projectId, $subName, $action,
            $fromCode !== '' ? $fromCode : null, $fromName !== '' ? $fromName : null,
            $toCode !== '' ? $toCode : null,     $toName !== '' ? $toName : null,
            $by !== '' ? $by : null,
        ]);
    } catch (Throwable $e) {
        error_log('_ssMangoLog: ' . $e->getMessage());
    }
}

/** เติมตัวเลือกเข้า pool ให้อัตโนมัติเวลาตั้งค่าจับคู่ (_ensureSubMangoOptions_) */
function _ssEnsureMangoOption(PDO $pdo, string $subCode, string $code, string $name, string $by): void {
    if ($subCode === '' || $code === '') return;
    try {
        $pdo->prepare(
            "INSERT INTO sub_mango_map (sub_code, vendor_code, vendor_name) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE vendor_name = VALUES(vendor_name)"
        )->execute([$subCode, $code, $name]);
    } catch (Throwable $e) {
        error_log('_ssEnsureMangoOption: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// _subPendingRefs_ — งานค้างของชุด (ใช้ตัดสินว่าปิดชุดได้เลยไหม)
//
// GAS นับ 4 อย่าง: ยืมไม่คืน · เบิกติดธงหักเงินที่งวดนั้นยังไม่ออกเอกสาร · สแกนนิ้ว · หักคจช.
//   total      = ทั้ง 4
//   blockTotal = ยืมไม่คืน + เบิกค้างออกเอกสาร + สแกนนิ้วงวดนี้  (ตัวที่ทำให้ปิดตรง ๆ ไม่ได้)
//
// ⚠️ ขอบเขตตอนนี้: สแกนนิ้ว (fsRows) และหักคจช. (seRows) ยังไม่ได้พอร์ต → นับเป็น 0 เสมอ
//    ซึ่ง "ถูกต้องกับสภาพจริง" เพราะยังไม่มีตารางให้เก็บข้อมูลสองอย่างนั้น
//    เมื่อพอร์ตคลัสเตอร์นั้นแล้ว ต้องกลับมาเติมสองตัวนี้ (ไม่งั้นจะปิดชุดทั้งที่ค่าปรับยังไม่ถูกหัก)
// ---------------------------------------------------------------------------
function _ssPendingRefs(PDO $pdo, int $projectId, int $subId, string $subName): array {
    $out = [
        'borrowOpen' => 0, 'chargeOpen' => 0, 'fsRows' => 0, 'seRows' => 0,
        'total' => 0, 'blockTotal' => 0, 'chargePeriods' => [],
    ];

    // 1) ใบยืมที่ยังไม่คืน (documents สถานะ 'Borrowed' + 'Sent Return' — Scenario 05 ③ 2026-09-29:
    //    แจ้งคืนแล้วรอสแกน หรือคืนไม่ครบรอสายสโตร์ตีชำรุด/สูญหาย ของยังค้างอยู่เหมือนกัน)
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM documents
         WHERE project_id = ? AND receiver_sub_id = ? AND status IN ('Borrowed', 'Sent Return')"
    );
    $stmt->execute([$projectId, $subId]);
    $out['borrowOpen'] = (int)$stmt->fetchColumn();

    // 2) เบิก/เบ็ดเตล็ดที่ติ๊กหักเงิน (completed) แต่งวดนั้นยังไม่ออกเลขใบหักเงิน
    //    งวด = ครึ่งเดือน (วันที่ 1-15 = งวด 1 · 16-สิ้นเดือน = งวด 2)
    $stmt = $pdo->prepare(
        "SELECT DATE_FORMAT(d.doc_ts, '%Y-%m') ym,
                IF(DAY(d.doc_ts) <= 15, 1, 2) half,
                COUNT(*) n
         FROM documents d
         JOIN document_items i ON i.document_id = d.id
         WHERE d.project_id = ? AND d.receiver_sub_id = ?
           AND d.doc_type IN ('RD','OD') AND d.status = 'Completed'
           AND i.charge_money = 1
         GROUP BY ym, half ORDER BY ym, half"
    );
    $stmt->execute([$projectId, $subId]);
    $periods = $stmt->fetchAll();

    if ($periods) {
        // ใบหักเงินที่ออกไปแล้วของชุดนี้ · GAS เทียบด้วยชื่อ (ชีตไม่มี id) — ที่นี่ใช้ sub_id
        // เป็นหลักเพื่อไม่ให้ "ชุดที่เคยเปลี่ยนชื่อ" หลุดการนับ แล้วค่อย fallback เป็นชื่อ
        // สำหรับใบเก่าที่ import มาโดยผูก id ไม่ได้
        $stmt = $pdo->prepare(
            "SELECT ym, days_label FROM deduction_docs
             WHERE project_id = ? AND (sub_id = ? OR (sub_id IS NULL AND sub_name = ?))"
        );
        $stmt->execute([$projectId, $subId, $subName]);
        $docs = $stmt->fetchAll();

        foreach ($periods as $pr) {
            $ym   = (string)$pr['ym'];
            $half = (int)$pr['half'];
            $covered = false;
            foreach ($docs as $d) {
                if (trim((string)$d['ym']) !== $ym) continue;
                if (_ssLabelCoversHalf((string)$d['days_label'], $half)) { $covered = true; break; }
            }
            if ($covered) continue;
            $out['chargeOpen'] += (int)$pr['n'];
            $out['chargePeriods'][] = [
                'ym' => $ym, 'half' => $half, 'count' => (int)$pr['n'],
                'label' => _ssPeriodLabel($ym, $half),
            ];
        }
    }

    // 3) สแกนนิ้ว + หักคจช. ของ "งวดปัจจุบัน" — ปิดชุดแล้วชุดหายจากทั้งสองหน้า
    //    ค่าปรับ/ยอดของงวดนี้จะไม่มีทางถูกหัก จึงเป็นตัวบล็อก (fsRows) / เตือน (seRows)
    $today = date('Y-m-d');
    $ymNow = substr($today, 0, 7);
    $halfNow = (int)substr($today, 8, 2) <= 15 ? 1 : 2;
    if (_ssTableExists($pdo, 'finger_scan_logs')) {
        $y = (int)substr($ymNow, 0, 4); $m = (int)substr($ymNow, 5, 2);
        $last = (int)date('t', mktime(0, 0, 0, $m, 1, $y));
        $from = $halfNow === 1 ? sprintf('%04d-%02d-01', $y, $m) : sprintf('%04d-%02d-16', $y, $m);
        $to   = $halfNow === 1 ? sprintf('%04d-%02d-15', $y, $m) : sprintf('%04d-%02d-%02d', $y, $m, $last);
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM finger_scan_logs
              WHERE project_id = ? AND sub_name = ? AND scan_date BETWEEN ? AND ?"
        );
        $st->execute([$projectId, $subName, $from, $to]);
        $out['fsRows'] = (int)$st->fetchColumn();
    }
    if (_ssTableExists($pdo, 'sub_expense_rows')) {
        // seRows ไม่บล็อกการปิด — แถวที่บันทึกไว้แล้วยังแสดง/แก้/พิมพ์ได้แม้ชุดถูกปิด (v1.10.0)
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM sub_expense_rows
              WHERE project_id = ? AND sub_name = ? AND period_key = ?"
        );
        $st->execute([$projectId, $subName, $ymNow . '/H' . $halfNow]);
        $out['seRows'] = (int)$st->fetchColumn();
    }

    $out['total']      = $out['borrowOpen'] + $out['chargeOpen'] + $out['fsRows'] + $out['seRows'];
    $out['blockTotal'] = $out['borrowOpen'] + $out['chargeOpen'] + $out['fsRows'];
    return $out;
}

/** ตารางของคลัสเตอร์ที่อาจยังไม่ได้ migrate — เช็คก่อนใช้ (กันหน้าพังตอน migrate ไม่ครบ) */
function _ssTableExists(PDO $pdo, string $table): bool {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $st->execute([$table]);
    return $cache[$table] = ((int)$st->fetchColumn() > 0);
}

/** _docLabelCoversHalf_ : ป้าย Days ของใบหักเงินครอบคลุมงวดครึ่งเดือนนี้ไหม */
function _ssLabelCoversHalf(string $label, int $half): bool {
    $lb = trim($label);
    if ($lb === '' || $lb === 'ทั้งเดือน') return true;
    if (mb_strpos($lb, 'งวด', 0, 'UTF-8') === 0) {
        return (mb_strpos($lb, '16', 0, 'UTF-8') !== false ? 2 : 1) === $half;
    }
    $nums = [];
    foreach (explode(',', $lb) as $x) {
        $n = (int)trim($x);
        if ($n > 0) $nums[] = $n;
    }
    if (!$nums) return true;
    foreach ($nums as $n) {
        if ($half === 1 ? $n <= 15 : $n >= 16) return true;
    }
    return false;
}

/** _sePeriodLabel_ : ('2026-06', 1) → 'วันที่ 1-15 มิ.ย.69' */
function _ssPeriodLabel(string $ym, int $half): string {
    $y = (int)substr($ym, 0, 4);
    $m = (int)substr($ym, 5, 2);
    $abbr = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
             'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    $mon = $abbr[$m] ?? '';
    $yy  = substr((string)($y + 543), -2);
    if ($half === 1) return 'วันที่ 1-15 ' . $mon . $yy;
    $last = (int)date('t', mktime(0, 0, 0, $m, 1, $y));
    return 'วันที่ 16-' . $last . ' ' . $mon . $yy;
}

// ---------------------------------------------------------------------------
// getSubSettingsData({siteCode, username}) — ข้อมูลตั้งหน้า
// คืน { success, sites, isAdmin, siteCode, siteName, subs[], allVendors[], canEdit }
//   subs[] = { subId, subName, enabled, borrowOpen, mangoCode, mangoName, hasSignature, options[] }
// ---------------------------------------------------------------------------
function rpc_getSubSettingsData(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_ssCanView($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];
        $canEdit = _ssCanBS($pdo, $user);   // false = SC (ตั้งได้เฉพาะลายเซ็น)

        // ชุดของโครงการนี้ + ป้าย "ยืมค้าง N" (v1.10.0 — ขึ้นทั้งชุดที่เปิดและปิดใช้)
        $stmt = $pdo->prepare(
            "SELECT s.id, s.sub_code, s.name, s.signature_path,
                    sp.enabled, sp.mango_vendor_code, sp.mango_vendor_name,
                    (SELECT COUNT(*) FROM documents d
                      WHERE d.project_id = sp.project_id
                        AND d.receiver_sub_id = s.id AND d.status IN ('Borrowed', 'Sent Return')) borrow_open
             FROM subcontractors s
             JOIN sub_projects sp ON sp.sub_id = s.id AND sp.project_id = ?
             ORDER BY s.sub_code"
        );
        $stmt->execute([$site['projectId']]);
        $rows = $stmt->fetchAll();

        // ตัวเลือก Mango ต่อชุด (pool) — อ่านรอบเดียวแล้วแจก
        $optBySub = [];
        foreach ($pdo->query("SELECT sub_code, vendor_code, vendor_name FROM sub_mango_map ORDER BY id") as $o) {
            $optBySub[fmtSubId($o['sub_code'])][] = [
                'code' => trim((string)$o['vendor_code']),
                'name' => trim((string)$o['vendor_name']),
            ];
        }

        $subs = [];
        foreach ($rows as $r) {
            $sid = fmtSubId($r['sub_code']);
            $subs[] = [
                'subId'        => $sid,
                'subName'      => trim((string)$r['name']),
                'enabled'      => (int)$r['enabled'] === 1,
                'borrowOpen'   => (int)$r['borrow_open'],
                'mangoCode'    => trim((string)($r['mango_vendor_code'] ?? '')),
                'mangoName'    => trim((string)($r['mango_vendor_name'] ?? '')),
                'hasSignature' => trim((string)($r['signature_path'] ?? '')) !== '',
                'options'      => $optBySub[$sid] ?? [],
            ];
        }

        // MangoVendors master (picker ค้นหาได้ทุกชุด) — เรียงตามชื่อไทย
        $allVendors = [];
        foreach ($pdo->query("SELECT vendor_code, vendor_name FROM mango_vendors ORDER BY id") as $v) {
            $c = trim((string)$v['vendor_code']);
            if ($c === '') continue;
            $allVendors[] = ['code' => $c, 'name' => trim((string)$v['vendor_name'])];
        }
        $collator = class_exists('Collator') ? new Collator('th_TH') : null;
        usort($allVendors, function ($a, $b) use ($collator) {
            return $collator ? $collator->compare($a['name'], $b['name']) : strcmp($a['name'], $b['name']);
        });

        return [
            'success'    => true,
            'sites'      => $site['sites'],
            'isAdmin'    => $site['isAdmin'],
            'siteCode'   => $site['siteCode'],
            'siteName'   => $site['siteName'],
            'subs'       => $subs,
            'allVendors' => $allVendors,
            'canEdit'    => $canEdit,
        ];
    } catch (Throwable $e) {
        error_log('getSubSettingsData error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// saveSubSettings({siteCode, selections:[{subId, enabled}], username})
// เปิด/ปิดใช้ชุดในโครงการนี้ · ชุดที่สั่งปิดแต่ยังมีงานค้าง → ไม่ปิด แล้วคืนใน blocked[]
// ให้หน้าเว็บเปิดหน้าต่างถามว่าจะ "ปิดเลย" หรือ "โอน+ปิด"
// (การจับคู่ Mango เป็นบันทึกทันทีผ่าน setSubMango — batch นี้ไม่ยุ่ง ตาม GAS v1.9)
// ---------------------------------------------------------------------------
function rpc_saveSubSettings(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_ssCanBS($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];

        $want = [];
        foreach ((is_array($p['selections'] ?? null) ? $p['selections'] : []) as $s) {
            if (!is_array($s)) continue;
            $sid = fmtSubId($s['subId'] ?? '');
            if ($sid === '') continue;
            $want[$sid] = (($s['enabled'] ?? true) !== false);
        }
        if (!$want) return ['success' => false, 'message' => 'ไม่มีรายการให้บันทึก'];

        $updated = 0;
        $blocked = [];
        $upd = $pdo->prepare("UPDATE sub_projects SET enabled = ? WHERE sub_id = ? AND project_id = ?");

        $pdo->beginTransaction();
        try {
            foreach ($want as $sid => $enabled) {
                $sid = (string)$sid;   // PHP แปลง array key เลขล้วนเป็น int ('213' → 213)
                $row = _ssSubRow($pdo, $site['projectId'], $sid);
                if (!$row) continue;                       // ไม่ได้ผูกกับโครงการนี้ — ข้าม
                $wasEnabled = (int)$row['enabled'] === 1;
                if ($wasEnabled === $enabled) continue;    // ไม่เปลี่ยน

                if (!$enabled) {
                    // จะปิด → ตรวจงานค้างก่อน
                    $refs = _ssPendingRefs($pdo, $site['projectId'], (int)$row['id'], (string)$row['name']);
                    if ($refs['blockTotal'] > 0) {
                        $blocked[] = ['subId' => $sid, 'subName' => (string)$row['name'], 'refs' => $refs];
                        continue;                          // คงสถานะเดิม — ยังไม่ปิด
                    }
                }
                $upd->execute([$enabled ? 1 : 0, (int)$row['id'], $site['projectId']]);
                $updated++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['success' => true, 'updated' => $updated, 'blocked' => $blocked];
    } catch (Throwable $e) {
        error_log('saveSubSettings error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// addSubcontractor({siteCode, subName, subId?, password?, mangoCode?, username})
// ทะเบียนกลาง (มติจุดที่ 12): ชื่อซ้ำกับที่มีในทะเบียนแล้ว = ไม่สร้างใหม่ แต่ "ดึงมาเปิดใช้"
// ในโครงการนี้ให้แทน · รหัสใหม่ = max+1 ทั้งระบบ (sub_code ไม่ซ้ำทั้งระบบ)
// ---------------------------------------------------------------------------
function rpc_addSubcontractor(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_ssCanBS($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];

        $subName = trim(preg_replace('/\s+/u', ' ', (string)($p['subName'] ?? '')));
        if ($subName === '') return ['success' => false, 'message' => 'กรุณาระบุชื่อชุด (ผู้รับเหมา)'];
        if (mb_strlen($subName, 'UTF-8') > 60) {
            return ['success' => false, 'message' => 'ชื่อชุดยาวเกินไป (ไม่เกิน 60 ตัวอักษร)'];
        }
        $password  = trim((string)($p['password'] ?? '')) ?: '1234';
        $mangoCode = trim((string)($p['mangoCode'] ?? ''));

        $pdo->beginTransaction();
        try {
            // มีชื่อนี้ในทะเบียนกลางแล้วหรือยัง
            $stmt = $pdo->prepare("SELECT id, sub_code FROM subcontractors WHERE LOWER(name) = LOWER(?) LIMIT 1");
            $stmt->execute([$subName]);
            $exist = $stmt->fetch();

            $attached = false;
            if ($exist) {
                $subId   = (int)$exist['id'];
                $subCode = fmtSubId($exist['sub_code']);
                // อยู่ในโครงการนี้แล้วหรือยัง
                $stmt = $pdo->prepare("SELECT enabled FROM sub_projects WHERE sub_id = ? AND project_id = ?");
                $stmt->execute([$subId, $site['projectId']]);
                $en = $stmt->fetchColumn();
                if ($en !== false && (int)$en === 1) {
                    $pdo->rollBack();
                    return ['success' => false,
                            'message' => 'ไซต์นี้มีชุด "' . $subName . '" อยู่แล้ว (รหัส ' . $subCode . ')'];
                }
                // มีในทะเบียนกลาง แต่ยังไม่เปิดใช้ในโครงการนี้ → ดึงมาเปิดใช้
                $pdo->prepare(
                    "INSERT INTO sub_projects (sub_id, project_id, enabled) VALUES (?, ?, 1)
                     ON DUPLICATE KEY UPDATE enabled = 1"
                )->execute([$subId, $site['projectId']]);
                $attached = true;
            } else {
                // รหัสใหม่: ระบุเองได้ (ต้องไม่ซ้ำ) ไม่งั้นรัน max+1 ทั้งระบบ
                $subCode = fmtSubId($p['subId'] ?? '');
                if ($subCode !== '') {
                    $stmt = $pdo->prepare("SELECT 1 FROM subcontractors WHERE sub_code = ? LIMIT 1");
                    $stmt->execute([$subCode]);
                    if ($stmt->fetchColumn()) {
                        $pdo->rollBack();
                        return ['success' => false,
                                'message' => 'รหัส ' . $subCode . ' ถูกใช้แล้ว — เว้นว่างเพื่อให้ระบบรันเลขอัตโนมัติ'];
                    }
                } else {
                    $max = (int)$pdo->query(
                        "SELECT COALESCE(MAX(CAST(sub_code AS UNSIGNED)), 0) FROM subcontractors"
                    )->fetchColumn();
                    $subCode = str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
                }
                $pdo->prepare(
                    "INSERT INTO subcontractors (sub_code, password, name, status) VALUES (?, ?, ?, 'active')"
                )->execute([$subCode, password_hash($password, PASSWORD_BCRYPT), $subName]);
                $subId = (int)$pdo->lastInsertId();
                $pdo->prepare(
                    "INSERT INTO sub_projects (sub_id, project_id, enabled) VALUES (?, ?, 1)"
                )->execute([$subId, $site['projectId']]);
            }

            // จับคู่ Mango ทันที (ถ้าเลือกมา) — ชื่อ resolve จาก master เสมอ
            $mangoName = '';
            if ($mangoCode !== '') {
                $mangoName = _ssVendorName($pdo, $mangoCode);
                $pdo->prepare(
                    "UPDATE sub_projects SET mango_vendor_code = ?, mango_vendor_name = ?
                     WHERE sub_id = ? AND project_id = ?"
                )->execute([$mangoCode, $mangoName, $subId, $site['projectId']]);
                _ssEnsureMangoOption($pdo, $subCode, $mangoCode, $mangoName, _ssActor($user));
            }
            $pdo->commit();

            if ($mangoCode !== '') {
                _ssMangoLog($pdo, $site['projectId'], $subId, $subName, 'set-payment',
                            '', '', $mangoCode, $mangoName, _ssActor($user));
            }
            return ['success' => true, 'subId' => $subCode, 'subName' => $subName,
                    'mangoCode' => $mangoCode, 'mangoName' => $mangoName,
                    'attached' => $attached,
                    'message' => $attached
                        ? 'ชุด "' . $subName . '" มีอยู่ในทะเบียนกลางแล้ว — เปิดใช้ในไซต์นี้ให้เรียบร้อย (รหัส ' . $subCode . ')'
                        : 'เพิ่มชุด "' . $subName . '" แล้ว (รหัส ' . $subCode . ')'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    } catch (Throwable $e) {
        error_log('addSubcontractor error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// renameSubcontractor({siteCode, subId, newName, username})
// GAS ไล่เขียนทับชื่อทุกชีต — ที่นี่ประวัติผูก FK จึงตามเอง เหลือ documents.receiver_name
// ที่เป็นสำเนาสำหรับแสดงผล · ใบหักเงินที่ออกเลขแล้วคงชื่อเดิม (แช่แข็ง — เป็นหลักฐาน)
// ---------------------------------------------------------------------------
function rpc_renameSubcontractor(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_ssCanBS($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];

        $subCode = fmtSubId($p['subId'] ?? '');
        if ($subCode === '') return ['success' => false, 'message' => 'ไม่ระบุชุดที่จะเปลี่ยนชื่อ'];
        $newName = trim(preg_replace('/\s+/u', ' ', (string)($p['newName'] ?? '')));
        if ($newName === '') return ['success' => false, 'message' => 'กรุณากรอกชื่อชุดใหม่'];
        if (mb_strlen($newName, 'UTF-8') > 60) {
            return ['success' => false, 'message' => 'ชื่อชุดยาวเกินไป (ไม่เกิน 60 ตัวอักษร)'];
        }

        $row = _ssSubRow($pdo, $site['projectId'], $subCode);
        if (!$row) return ['success' => false, 'message' => 'ไม่พบชุด ' . $subCode . ' ในไซต์ ' . $site['siteCode']];
        $oldName = trim((string)$row['name']);
        if ($oldName === $newName) {
            return ['success' => false, 'message' => 'ชื่อใหม่เหมือนชื่อเดิม — ไม่มีอะไรต้องเปลี่ยน'];
        }
        // ทะเบียนกลาง → ชื่อต้องไม่ซ้ำทั้งระบบ (GAS เดิมกันแค่ภายในไซต์ เพราะชีตมีไซต์เดียว)
        $stmt = $pdo->prepare("SELECT sub_code FROM subcontractors WHERE LOWER(name) = LOWER(?) AND id <> ? LIMIT 1");
        $stmt->execute([$newName, (int)$row['id']]);
        if ($dup = $stmt->fetchColumn()) {
            return ['success' => false,
                    'message' => 'มีชุดชื่อ "' . $newName . '" อยู่แล้ว (รหัส ' . fmtSubId($dup) . ') — ตั้งชื่อซ้ำไม่ได้'];
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE subcontractors SET name = ? WHERE id = ?")
                ->execute([$newName, (int)$row['id']]);
            // สำเนาชื่อบนเอกสาร (ใช้แสดงผล/รายงาน) — ตามชื่อใหม่เหมือน GAS
            $st = $pdo->prepare("UPDATE documents SET receiver_name = ? WHERE receiver_sub_id = ?");
            $st->execute([$newName, (int)$row['id']]);
            $docsMoved = $st->rowCount();

            $pdo->prepare(
                "INSERT INTO remap_logs
                   (project_id, from_sub_id, from_sub_name, to_sub_id, to_sub_name,
                    borrow_moved, charge_moved, changed_by)
                 VALUES (?, ?, ?, ?, ?, 0, ?, ?)"
            )->execute([
                $site['projectId'], (int)$row['id'], $oldName, (int)$row['id'], $newName,
                $docsMoved, _ssActor($user) . ' (เปลี่ยนชื่อ)',
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['success' => true, 'subId' => $subCode, 'oldName' => $oldName, 'newName' => $newName,
                'message' => 'เปลี่ยนชื่อ "' . $oldName . '" → "' . $newName . '" แล้ว · '
                           . 'อัปเดตชื่อบนเอกสาร ' . $docsMoved . ' ใบ '
                           . '(ใบหักเงินที่ออกเลขแล้วคงชื่อเดิมไว้เป็นหลักฐาน)'];
    } catch (Throwable $e) {
        error_log('renameSubcontractor error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// remapSubcontractor({siteCode, fromSubId, toSubId, username}) — โอนชุด (merge)
// ยกงานของชุดต้นทางไปเป็นของชุดปลายทาง "ทั้งหมดทุกช่วงเวลา" (กติกาผู้ใช้ v1.8.0)
// แล้วปิดชุดต้นทางในโครงการนี้
//
// ใบหักเงินที่ออกเลขไปแล้ว "ไม่ย้าย" — sub_name บนใบถูกแช่แข็งเป็นหลักฐานว่าตอนนั้นเป็นของใคร
// (ตรงกับ GAS ที่นับ SubExpenseSkipped และไม่แตะ DeductionDocs)
// ---------------------------------------------------------------------------
function rpc_remapSubcontractor(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_ssCanBS($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];

        $fromCode = fmtSubId($p['fromSubId'] ?? '');
        $toCode   = fmtSubId($p['toSubId'] ?? '');
        if ($fromCode === '' || $toCode === '') {
            return ['success' => false, 'message' => 'กรุณาเลือกชุดต้นทางและปลายทาง'];
        }
        if ($fromCode === $toCode) {
            return ['success' => false, 'message' => 'ชุดปลายทางต้องไม่ใช่ชุดเดียวกับที่จะปิด'];
        }
        $from = _ssSubRow($pdo, $site['projectId'], $fromCode);
        $to   = _ssSubRow($pdo, $site['projectId'], $toCode);
        if (!$from) return ['success' => false, 'message' => 'ไม่พบชุดต้นทางในไซต์นี้'];
        if (!$to)   return ['success' => false, 'message' => 'ไม่พบชุดปลายทางในไซต์นี้'];
        if ((int)$to['enabled'] !== 1) {
            return ['success' => false, 'message' => 'ชุดปลายทางถูกปิดใช้งานอยู่ — เปิดใช้งานก่อนจึงจะโอนเข้าได้'];
        }
        $fromName = trim((string)$from['name']);
        $toName   = trim((string)$to['name']);

        $pdo->beginTransaction();
        try {
            // ใบยืม-คืน (BD) — ย้ายผู้รับทุกสถานะ
            $st = $pdo->prepare(
                "UPDATE documents SET receiver_sub_id = ?, receiver_name = ?
                 WHERE project_id = ? AND receiver_sub_id = ? AND doc_type = 'BD'"
            );
            $st->execute([(int)$to['id'], $toName, $site['projectId'], (int)$from['id']]);
            $borrowMoved = $st->rowCount();

            // ใบเบิก/เบ็ดเตล็ด (RD/OD)
            $st = $pdo->prepare(
                "UPDATE documents SET receiver_sub_id = ?, receiver_name = ?
                 WHERE project_id = ? AND receiver_sub_id = ? AND doc_type IN ('RD','OD')"
            );
            $st->execute([(int)$to['id'], $toName, $site['projectId'], (int)$from['id']]);
            $chargeMoved = $st->rowCount();

            // เอกสารชนิดอื่น (เช่น IN) ที่ผูกผู้รับไว้ — ย้ายให้ครบ ไม่ให้เหลือค้างชี้ชุดที่ปิดไปแล้ว
            $st = $pdo->prepare(
                "UPDATE documents SET receiver_sub_id = ?, receiver_name = ?
                 WHERE project_id = ? AND receiver_sub_id = ? AND doc_type NOT IN ('BD','RD','OD')"
            );
            $st->execute([(int)$to['id'], $toName, $site['projectId'], (int)$from['id']]);
            $otherMoved = $st->rowCount();

            // ใบหักเงินที่ออกเลขแล้ว = ไม่ย้าย (นับไว้รายงานให้ผู้ใช้เห็นว่ามีกี่ใบที่คงชื่อเดิม)
            $st = $pdo->prepare(
                "SELECT COUNT(*) FROM deduction_docs WHERE project_id = ? AND (sub_id = ? OR sub_name = ?)"
            );
            $st->execute([$site['projectId'], (int)$from['id'], $fromName]);
            $dedSkipped = (int)$st->fetchColumn();

            // ปิดชุดต้นทางในโครงการนี้
            $pdo->prepare("UPDATE sub_projects SET enabled = 0 WHERE sub_id = ? AND project_id = ?")
                ->execute([(int)$from['id'], $site['projectId']]);

            $pdo->prepare(
                "INSERT INTO remap_logs
                   (project_id, from_sub_id, from_sub_name, to_sub_id, to_sub_name,
                    borrow_moved, charge_moved, se_skipped, changed_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $site['projectId'], (int)$from['id'], $fromName, (int)$to['id'], $toName,
                $borrowMoved, $chargeMoved + $otherMoved, $dedSkipped, _ssActor($user),
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['success' => true,
                'fromSubId' => $fromCode, 'toSubId' => $toCode,
                'moved' => ['borrow' => $borrowMoved, 'charge' => $chargeMoved + $otherMoved],
                'skipped' => ['deductionDocs' => $dedSkipped],
                'message' => 'โอน "' . $fromName . '" → "' . $toName . '" แล้ว · '
                           . 'ยืม-คืน ' . $borrowMoved . ' ใบ · เบิก/อื่น ๆ ' . ($chargeMoved + $otherMoved) . ' ใบ · '
                           . 'ใบหักเงินที่ออกเลขแล้ว ' . $dedSkipped . ' ใบคงชื่อเดิมไว้ · ปิดชุดต้นทางแล้ว'];
    } catch (Throwable $e) {
        error_log('remapSubcontractor error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// จับคู่ Mango — ตัวเลือก (pool) + ค่าที่ใช้จริง
// ---------------------------------------------------------------------------

/** addSubMangoOption({siteCode, subId, code, username}) — เพิ่มตัวเลือกเข้า pool ของชุด */
function rpc_addSubMangoOption(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_ssCanBS($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];
        $subCode = fmtSubId($p['subId'] ?? '');
        $code    = trim((string)($p['code'] ?? ''));
        if ($subCode === '' || $code === '') return ['success' => false, 'message' => 'bad_request'];

        $row = _ssSubRow($pdo, $site['projectId'], $subCode);
        if (!$row) return ['success' => false, 'message' => 'ไม่พบชุดในไซต์นี้'];
        $name = _ssVendorName($pdo, $code);

        $stmt = $pdo->prepare("SELECT 1 FROM sub_mango_map WHERE sub_code = ? AND vendor_code = ? LIMIT 1");
        $stmt->execute([$subCode, $code]);
        if ($stmt->fetchColumn()) {
            return ['success' => true, 'duplicated' => true, 'code' => $code, 'name' => $name];
        }
        $pdo->prepare("INSERT INTO sub_mango_map (sub_code, vendor_code, vendor_name) VALUES (?, ?, ?)")
            ->execute([$subCode, $code, $name]);
        _ssMangoLog($pdo, $site['projectId'], (int)$row['id'], (string)$row['name'],
                    'add-option', '', '', $code, $name, _ssActor($user));
        return ['success' => true, 'code' => $code, 'name' => $name];
    } catch (Throwable $e) {
        error_log('addSubMangoOption error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/** removeSubMangoOption({siteCode, subId, code, username}) — ลบตัวเลือกออกจาก pool */
function rpc_removeSubMangoOption(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_ssCanBS($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];
        $subCode = fmtSubId($p['subId'] ?? '');
        $code    = trim((string)($p['code'] ?? ''));
        if ($subCode === '' || $code === '') return ['success' => false, 'message' => 'bad_request'];

        $stmt = $pdo->prepare("SELECT vendor_name FROM sub_mango_map WHERE sub_code = ? AND vendor_code = ? LIMIT 1");
        $stmt->execute([$subCode, $code]);
        $delName = (string)($stmt->fetchColumn() ?: '');

        $st = $pdo->prepare("DELETE FROM sub_mango_map WHERE sub_code = ? AND vendor_code = ?");
        $st->execute([$subCode, $code]);
        $removed = $st->rowCount();

        if ($removed) {
            $row = _ssSubRow($pdo, $site['projectId'], $subCode);
            _ssMangoLog($pdo, $site['projectId'], (int)($row['id'] ?? 0), (string)($row['name'] ?? $subCode),
                        'remove-option', $code, $delName, '', '', _ssActor($user));
        }
        return ['success' => true, 'removed' => $removed, 'code' => $code];
    } catch (Throwable $e) {
        error_log('removeSubMangoOption error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/** setSubMango({siteCode, subId, code, username}) — ตั้ง/ล้างค่าที่ใช้จริง (code='' = ล้าง) */
function rpc_setSubMango(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_ssCanBS($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];
        $subCode = fmtSubId($p['subId'] ?? '');
        if ($subCode === '') return ['success' => false, 'message' => 'bad_request'];
        $code = trim((string)($p['code'] ?? ''));
        $name = $code !== '' ? _ssVendorName($pdo, $code) : '';

        $row = _ssSubRow($pdo, $site['projectId'], $subCode);
        if (!$row) return ['success' => false, 'message' => 'ไม่พบชุดในไซต์นี้'];
        $oldCode = trim((string)($row['mango_vendor_code'] ?? ''));
        $oldName = trim((string)($row['mango_vendor_name'] ?? ''));
        if ($oldCode === $code) {
            return ['success' => true, 'code' => $code, 'name' => $code !== '' ? $name : '', 'unchanged' => true];
        }
        // code เดิมที่ master ไม่รู้จักแล้ว (ข้อมูลเก่า) → คงชื่อเดิมไว้ ไม่ล้างทิ้ง
        if ($code !== '' && $name === '' && $code === $oldCode) $name = $oldName;

        $pdo->prepare(
            "UPDATE sub_projects SET mango_vendor_code = ?, mango_vendor_name = ?
             WHERE sub_id = ? AND project_id = ?"
        )->execute([$code !== '' ? $code : null, $name !== '' ? $name : null,
                    (int)$row['id'], $site['projectId']]);

        _ssMangoLog($pdo, $site['projectId'], (int)$row['id'], (string)$row['name'],
                    $code !== '' ? 'set-payment' : 'clear-payment',
                    $oldCode, $oldName, $code, $name, _ssActor($user));
        if ($code !== '') _ssEnsureMangoOption($pdo, $subCode, $code, $name, _ssActor($user));

        return ['success' => true, 'code' => $code, 'name' => $name];
    } catch (Throwable $e) {
        error_log('setSubMango error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/** getSubMangoHistory({siteCode, subId, username}) — ล่าสุดก่อน สูงสุด 30 รายการ */
function rpc_getSubMangoHistory(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_ssCanBS($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];
        $subCode = fmtSubId($p['subId'] ?? '');
        if ($subCode === '') return ['success' => false, 'message' => 'bad_request'];

        $stmt = $pdo->prepare(
            "SELECT h.action, h.from_code, h.from_name, h.to_code, h.to_name, h.changed_by,
                    UNIX_TIMESTAMP(h.changed_at) * 1000 at_ms
             FROM sub_mango_history h
             JOIN subcontractors s ON s.id = h.sub_id
             WHERE h.project_id = ? AND s.sub_code = ?
             ORDER BY h.id DESC LIMIT 30"
        );
        $stmt->execute([$site['projectId'], $subCode]);
        $list = [];
        foreach ($stmt->fetchAll() as $r) {
            $list[] = [
                'at'       => $r['at_ms'] !== null ? (int)$r['at_ms'] : null,
                'action'   => (string)$r['action'],
                'fromCode' => (string)($r['from_code'] ?? ''),
                'fromName' => (string)($r['from_name'] ?? ''),
                'toCode'   => (string)($r['to_code'] ?? ''),
                'toName'   => (string)($r['to_name'] ?? ''),
                'by'       => (string)($r['changed_by'] ?? ''),
            ];
        }
        return ['success' => true, 'list' => $list];
    } catch (Throwable $e) {
        error_log('getSubMangoHistory error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// ลายเซ็นประจำชุด (GAS v1.9.8) — SC ตั้งได้ (งานเดียวที่ SC ทำได้ในหน้านี้)
// GAS เก็บ data URL ในเซลล์ · ที่นี่เก็บเป็นไฟล์ใต้ uploads/signatures/ แล้วอ่านกลับเป็น data URL
// ---------------------------------------------------------------------------

/** _validSigDataUrl_ : png/jpeg base64 · ยาวไม่เกิน 45,000 ตัวอักษร (ลิมิตเดิมของ GAS) */
function _ssValidSigDataUrl(string $s): bool {
    if ($s === '' || strlen($s) > 45000) return false;
    return (bool)preg_match('#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=]{50,}$#', $s);
}

function rpc_getSubcontractorSignature(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_ssCanView($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];
        $subCode = fmtSubId($p['subId'] ?? '');
        if ($subCode === '') return ['success' => false, 'message' => 'ไม่ระบุชุด'];

        $row = _ssSubRow($pdo, $site['projectId'], $subCode);
        $dataUrl = '';
        $rel = trim((string)($row['signature_path'] ?? ''));
        if ($rel !== '') {
            $abs = rtrim(str_replace('\\', '/', ROOT_PATH), '/') . '/' . ltrim($rel, '/');
            if (is_file($abs)) {
                $bytes = @file_get_contents($abs);
                if ($bytes !== false) {
                    $mime = (substr($bytes, 0, 8) === "\x89PNG\r\n\x1a\n") ? 'image/png' : 'image/jpeg';
                    $dataUrl = 'data:' . $mime . ';base64,' . base64_encode($bytes);
                }
            }
        }
        return ['success' => true, 'subId' => $subCode, 'dataUrl' => $dataUrl];
    } catch (Throwable $e) {
        error_log('getSubcontractorSignature error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function rpc_saveSubcontractorSignature(PDO $pdo, ?array $user, array $args) {
    try {
        // SC ตั้งลายเซ็นให้ชุดได้ (งานเดียวที่ SC ทำได้ในหน้านี้) · BS/R0 ได้ทุกอย่าง
        if (!_ssCanView($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];
        $subCode = fmtSubId($p['subId'] ?? '');
        if ($subCode === '') return ['success' => false, 'message' => 'ไม่ระบุชุด'];

        $row = _ssSubRow($pdo, $site['projectId'], $subCode);
        if (!$row) return ['success' => false, 'message' => 'ไม่พบชุดในไซต์นี้'];

        $dataUrl  = (string)($p['dataUrl'] ?? '');
        $clearing = ($dataUrl === '');
        if (!$clearing && !_ssValidSigDataUrl($dataUrl)) {
            return ['success' => false,
                    'message' => 'รูปลายเซ็นไม่ถูกต้องหรือใหญ่เกินไป (ลองวาดใหม่ให้เรียบง่ายขึ้น หรือลดขนาดรูป)'];
        }

        $oldRel = trim((string)($row['signature_path'] ?? ''));
        $newRel = null;
        if (!$clearing) {
            preg_match('#^data:image/(png|jpeg);base64,(.+)$#', $dataUrl, $m);
            $ext   = $m[1] === 'png' ? 'png' : 'jpg';
            $bytes = base64_decode($m[2], true);
            if ($bytes === false || strlen($bytes) < 32) {
                return ['success' => false, 'message' => 'รูปลายเซ็นเสียหาย — ลองวาดใหม่'];
            }
            $subDir = 'uploads/signatures';
            $absDir = rtrim(str_replace('\\', '/', ROOT_PATH), '/') . '/' . $subDir;
            if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
                return ['success' => false, 'message' => 'สร้างโฟลเดอร์เก็บลายเซ็นไม่ได้'];
            }
            $name = 'sub_' . $subCode . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            if (@file_put_contents($absDir . '/' . $name, $bytes) === false) {
                return ['success' => false, 'message' => 'บันทึกไฟล์ลายเซ็นไม่สำเร็จ'];
            }
            $newRel = $subDir . '/' . $name;
        }

        $pdo->prepare("UPDATE subcontractors SET signature_path = ? WHERE id = ?")
            ->execute([$newRel, (int)$row['id']]);

        // ลบไฟล์เดิมหลังอัปเดตสำเร็จ (กันไฟล์ค้างสะสม)
        if ($oldRel !== '' && $oldRel !== $newRel) {
            $absOld = rtrim(str_replace('\\', '/', ROOT_PATH), '/') . '/' . ltrim($oldRel, '/');
            if (is_file($absOld)) @unlink($absOld);
        }
        return ['success' => true, 'subId' => $subCode, 'cleared' => $clearing];
    } catch (Throwable $e) {
        error_log('saveSubcontractorSignature error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// closeSubcontractorSettle({siteCode, subId, username}) — "ปิดเลย (ระบบจัดการให้)" v1.10.0
//
// ใช้กับชุดที่ทำงานจบแล้วแต่ยังติดงานค้าง — ต่างจาก "โอนชุด" ตรงที่ประวัติทั้งหมด
// ยังเป็นของชุดเดิม (ไม่เปลี่ยนชื่อไปเป็นชุดปลายทาง)
//
// ลำดับ 4 ขั้น (พังกลางคัน = ไม่ปิดชุด · กดซ้ำได้ปลอดภัย ทุกขั้น idempotent):
//   1. ออกเลขใบหักเงินให้ทุกงวดที่ยังไม่ออก + แช่ราคา (ไม่สร้าง PDF/ลิงก์เซ็น)
//      ⚠ ตรรกะเลขรันเดียวกับหน้าตรวจสอบประจำวัน = ออกให้ "ทุกชุด" ที่มีรายการในงวดนั้น
//   2. ตรึงยอดหักคจช. งวดปัจจุบัน (ค่าปรับสแกนนิ้ว + ค่าวัสดุ) — รวมเป็น 0 = ไม่สร้างแถว
//   3. สตัมป์หมายเหตุใบยืมที่ยังไม่คืน (ไม่แตะสถานะ — คืนทีหลังได้)
//   4. ปิดชุดในโครงการนี้ + บันทึก sub_close_logs
// ---------------------------------------------------------------------------
function rpc_closeSubcontractorSettle(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_ssCanBS($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        require_once __DIR__ . '/signatures.php';
        require_once __DIR__ . '/subexpense.php';

        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];
        $pid  = $site['projectId'];
        $subCode = fmtSubId($p['subId'] ?? '');
        if ($subCode === '') return ['success' => false, 'message' => 'ไม่ระบุชุดที่จะปิด'];

        $row = _ssSubRow($pdo, $pid, $subCode);
        if (!$row) return ['success' => false, 'message' => 'ไม่พบชุดในไซต์นี้'];
        $subName = trim((string)$row['name']);
        $by      = _ssActor($user);

        $refs = _ssPendingRefs($pdo, $pid, (int)$row['id'], $subName);

        // ── ขั้น 1: ออกเลขใบหักเงินทุกงวดที่ค้าง (idempotent — งวดที่ออกแล้วนับเป็น already)
        $docsIssued = 0;
        $docsAlready = 0;
        $periodsIssued = [];
        foreach ($refs['chargePeriods'] as $per) {
            $res = rpc_issueDeductionDocs($pdo, $user, [[
                'siteCode' => $site['siteCode'], 'ym' => $per['ym'], 'half' => $per['half'],
            ]]);
            if (!empty($res['success'])) {
                $docsIssued  += count($res['created'] ?? []);
                $docsAlready += (int)($res['already'] ?? 0);
                if (($res['created'] ?? [])) $periodsIssued[] = $per['ym'] . '/H' . $per['half'];
            } elseif (($res['code'] ?? '') !== 'no_items') {
                // ออกเลขไม่ผ่านด้วยเหตุอื่น = หยุด ไม่ปิดชุด (กดใหม่ได้)
                return ['success' => false,
                        'message' => 'ออกเลขใบหักเงินงวด ' . $per['label'] . ' ไม่สำเร็จ: '
                                   . ($res['message'] ?? 'ไม่ทราบสาเหตุ') . ' — ยังไม่ปิดชุด'];
            }
        }

        // ── ขั้น 2: ตรึงยอดหักคจช. งวดปัจจุบัน (ค่าปรับสแกนนิ้ว + ค่าวัสดุ)
        // สองช่องนี้เป็นค่าดึงสด แต่ PDF พิมพ์จากค่าที่บันทึก → ต้องเขียนลงแถวจริงก่อนปิดชุด
        $today   = date('Y-m-d');
        $ymNow   = substr($today, 0, 7);
        $halfNow = (int)substr($today, 8, 2) <= 15 ? 1 : 2;
        $periodKey = $ymNow . '/H' . $halfNow;
        $faceScanFine = 0.0;
        $materialAmt  = 0.0;
        if (_ssTableExists($pdo, 'sub_expense_rows')) {
            $mat  = _seMaterialAmtBySub($pdo, $pid, $site['siteCode'], $ymNow, $halfNow);
            $fine = _seFaceScanFineBySub($pdo, $pid, $ymNow, $halfNow);
            $materialAmt  = (float)($mat[$subName] ?? 0);
            $faceScanFine = (float)($fine[$subName] ?? 0);
            if ($materialAmt > 0 || $faceScanFine > 0) {
                $st = $pdo->prepare("SELECT * FROM sub_expense_rows
                                      WHERE project_id = ? AND period_key = ? AND sub_name = ?");
                $st->execute([$pid, $periodKey, $subName]);
                $exist = $st->fetch();
                if ($exist) {
                    // แถวเดิม: เติมเฉพาะสองช่องนี้แล้วคำนวณ total ใหม่
                    $vals = [
                        'roomAmt' => $exist['room_amt'], 'elecAmt' => $exist['elec_amt'],
                        'shopAmt' => $exist['shop_amt'], 'shopElecAmt' => $exist['shop_elec_amt'],
                        'materialAmt' => $materialAmt, 'advanceAmt' => $exist['advance_amt'],
                        'safetyFine' => $exist['safety_fine'], 'faceScanFine' => $faceScanFine,
                    ];
                    $pdo->prepare(
                        "UPDATE sub_expense_rows SET material_amt = ?, face_scan_fine = ?, total_amt = ?,
                                updated_by = ?, updated_at = NOW() WHERE id = ?"
                    )->execute([$materialAmt, $faceScanFine, _seRowTotal($vals), $by, (int)$exist['id']]);
                } else {
                    $pdo->prepare(
                        "INSERT INTO sub_expense_rows
                           (project_id, period_key, sub_name, material_amt, face_scan_fine, total_amt,
                            note, updated_by, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
                    )->execute([$pid, $periodKey, $subName, $materialAmt, $faceScanFine,
                                round($materialAmt + $faceScanFine, 2),
                                'ตรึงยอดอัตโนมัติตอนปิดชุด', $by]);
                }
            }
        }

        // ── ขั้น 3: สตัมป์หมายเหตุใบยืมที่ยังไม่คืน (ไม่แตะสถานะ)
        $stamp = 'ปิดชุดเมื่อ ' . date('Y-m-d H:i') . ' โดย ' . $by . ' — ของยังไม่ได้คืน';
        $st = $pdo->prepare(
            "UPDATE documents
                SET notice = CASE WHEN notice IS NULL OR notice = '' THEN ?
                                  ELSE CONCAT(notice, ' | ', ?) END
              WHERE project_id = ? AND receiver_sub_id = ? AND status IN ('Borrowed', 'Sent Return')
                AND (notice IS NULL OR notice NOT LIKE '%ปิดชุดเมื่อ%')"
        );
        $st->execute([$stamp, $stamp, $pid, (int)$row['id']]);
        $borrowLeft = $refs['borrowOpen'];

        // ── ขั้น 4: ปิดชุด + ลงทะเบียน
        $pdo->prepare("UPDATE sub_projects SET enabled = 0 WHERE sub_id = ? AND project_id = ?")
            ->execute([(int)$row['id'], $pid]);
        $pdo->prepare(
            "INSERT INTO sub_close_logs
               (project_id, sub_id, sub_name, docs_issued, docs_already, periods_issued,
                se_period, face_scan_fine, material_amt, borrow_open_left, changed_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([$pid, (int)$row['id'], $subName, $docsIssued, $docsAlready,
                    implode(',', $periodsIssued), $periodKey, $faceScanFine, $materialAmt,
                    $borrowLeft, $by]);

        $parts = [];
        if ($docsIssued)  $parts[] = 'ออกใบหักเงิน ' . $docsIssued . ' ใบ';
        if ($docsAlready) $parts[] = 'มีอยู่แล้ว ' . $docsAlready . ' ใบ';
        if ($materialAmt > 0 || $faceScanFine > 0) {
            $parts[] = 'ตรึงยอดหักคจช. งวด ' . $periodKey
                     . ' (วัสดุ ' . number_format($materialAmt, 2) . ' · ปรับสแกนนิ้ว '
                     . number_format($faceScanFine, 2) . ')';
        }
        if ($borrowLeft)  $parts[] = 'สตัมป์ใบยืมค้าง ' . $borrowLeft . ' ใบ';
        $parts[] = 'ปิดชุดแล้ว';

        return ['success' => true, 'subId' => $subCode, 'subName' => $subName,
                'docsIssued' => $docsIssued, 'docsAlready' => $docsAlready,
                'periodsIssued' => $periodsIssued, 'sePeriod' => $periodKey,
                'faceScanFine' => $faceScanFine, 'materialAmt' => $materialAmt,
                'borrowOpenLeft' => $borrowLeft,
                'message' => 'ปิดชุด "' . $subName . '" เรียบร้อย — ' . implode(' · ', $parts)];
    } catch (Throwable $e) {
        error_log('closeSubcontractorSettle error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
