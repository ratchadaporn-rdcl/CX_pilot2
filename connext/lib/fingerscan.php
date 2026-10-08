<?php
/**
 * CONNEXT — lib/fingerscan.php : บันทึกสแกนนิ้วรายวัน (port จาก Code.js v1.9.0-1.10.0)
 *
 * 4 แท็บในหน้าเดียว: บันทึกรายวัน · สรุปงวด · ชี้แจงแสกนเกิน · แดชบอร์ด
 * สิทธิ์: SC (คีย์ข้อมูล) · BS (ตรวจ+ลงนามรายวัน, ตั้งอัตราค่าปรับ) · R0 (ทั้งหมด)
 *
 * ⚠ ค่าปรับคำนวณฝั่ง server เท่านั้น — ไม่รับตัวเลขค่าปรับจาก client เลย:
 *     ไม่แจ้งยอด → max(0, แรงงานในระบบ) × rate
 *     ปกติ       → max(0, ลงงาน − แสกน) × rate   (ว่างข้างใดข้างหนึ่ง = คิดไม่ได้)
 *
 * v1.10.0 (แก้บั๊กข้อมูลหาย): การบันทึกคือ "แทนที่ทั้งวัน" แต่หน้าเว็บสร้างตารางจากชุด active
 *   เท่านั้น → แถวของชุดที่ปิดใช้งานไปแล้วจะหายทุกครั้งที่กดบันทึกซ้ำ
 *   จึงคงแถวของชุด inactive ที่ไม่ได้ถูกส่งมาไว้เสมอ (keptClosed) + ส่งกลับให้โชว์อ่านอย่างเดียว
 */

// registry โหลดไฟล์เดียวต่อ 1 RPC — ไฟล์นี้ใช้ helper ของอีกสองคลัสเตอร์ จึงต้องดึงเอง
require_once __DIR__ . '/subsettings.php';   // _ssRoleFlags / _ssCanBS / _ssResolveSite
require_once __DIR__ . '/signatures.php';    // _sigSaveDataUrl / _sigReadDataUrl / _sigUnlink / _sigValidDataUrl

const FS_DEFAULT_RATE = 100;   // บาท/คน/วัน

// ---------------------------------------------------------------------------
// สิทธิ์
// ---------------------------------------------------------------------------

/** _userCanFingerScan_ : SC หรือ BS หรือ R0 */
function _fsCanUse(PDO $pdo, ?array $user): bool {
    $f = _ssRoleFlags($pdo, $user);
    return $f['sc'] || $f['bs'] || $f['level'] === 0;
}

/** _userCanBSPage_ : BS หรือ R0 (ตรวจ/ลงนาม + ตั้งอัตราค่าปรับ) */
function _fsCanBS(PDO $pdo, ?array $user): bool {
    return _ssCanBS($pdo, $user);
}

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------

/** วันนี้ (เวลาไทย — config ตั้ง tz แล้ว) */
function _fsToday(): string { return date('Y-m-d'); }

/** _seNum_ : ตัวเลขจาก client — '' เมื่อว่าง/ไม่ใช่เลข (ช่องว่างต้องไม่กลายเป็น 0) */
function _fsNum($v) {
    if ($v === null || $v === '' || $v === false) return '';
    $s = str_replace(',', '', (string)$v);
    return is_numeric($s) ? (float)$s : '';
}

/** อัตราค่าปรับของไซต์ (ไม่มีแถว = ค่าเริ่มต้น 100) */
function _fsRate(PDO $pdo, int $projectId): float {
    $st = $pdo->prepare("SELECT cfg_value FROM finger_scan_config WHERE project_id = ? AND cfg_key = 'rate'");
    $st->execute([$projectId]);
    $v = $st->fetchColumn();
    if ($v === false || !is_numeric($v)) return (float)FS_DEFAULT_RATE;
    return (float)$v;
}

/** _fsComputeFine_ : สูตรค่าปรับ (คืน float · 0 เมื่อข้อมูลไม่พอ) */
function _fsComputeFine($worker, $scan, bool $noReport, $systemCount, float $rate): float {
    $w = _fsNum($worker); $s = _fsNum($scan); $sys = _fsNum($systemCount);
    if ($noReport) return ($sys === '' ? 0.0 : max(0.0, (float)$sys)) * $rate;
    if ($w === '' || $s === '') return 0.0;
    return max(0.0, (float)$w - (float)$s) * $rate;
}

/** _fsRowIncomplete_ : แถว "กรอกไม่ครบ" — บันทึกได้ แต่ค่าปรับเว้นว่างและ BS เซ็นไม่ได้ */
function _fsRowIncomplete(array $r): bool {
    if (!empty($r['no_report'])) {
        $sys = _fsNum($r['system_worker_count'] ?? '');
        return $sys === '' || (float)$sys <= 0;
    }
    $w = _fsNum($r['worker_count'] ?? '') !== '';
    $s = _fsNum($r['scan_count'] ?? '') !== '';
    return ($w && !$s) || (!$w && $s);
}

/** แถวสแกนนิ้วของช่วงวัน → รูปที่ client ใช้ */
function _fsLoadRows(PDO $pdo, int $projectId, string $from, string $to): array {
    $st = $pdo->prepare(
        "SELECT l.*, s.sub_code
         FROM finger_scan_logs l
         LEFT JOIN subcontractors s ON s.id = l.sub_id
         WHERE l.project_id = ? AND l.scan_date BETWEEN ? AND ?
         ORDER BY l.scan_date, s.sub_code, l.sub_name"
    );
    $st->execute([$projectId, $from, $to]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = [
            'date'              => (string)$r['scan_date'],
            'subId'             => fmtSubId($r['sub_code'] ?? ''),
            'subName'           => (string)$r['sub_name'],
            'workerCount'       => $r['worker_count'] !== null ? (float)$r['worker_count'] : '',
            'scanCount'         => $r['scan_count'] !== null ? (float)$r['scan_count'] : '',
            'noReport'          => (int)$r['no_report'] === 1,
            'systemWorkerCount' => $r['system_worker_count'] !== null ? (float)$r['system_worker_count'] : '',
            'fineAmt'           => $r['fine_amt'] !== null ? (float)$r['fine_amt'] : '',
            'note'              => (string)($r['note'] ?? ''),
            'scanExceedNote'    => (string)($r['scan_exceed_note'] ?? ''),
            'scanExceedBy'      => (string)($r['scan_exceed_by'] ?? ''),
            'scanExceedAt'      => !empty($r['scan_exceed_at']) ? strtotime($r['scan_exceed_at']) * 1000 : null,
            'recordedBy'        => (string)($r['recorded_by'] ?? ''),
            'recordedAt'        => !empty($r['recorded_at']) ? strtotime($r['recorded_at']) * 1000 : null,
        ];
    }
    return $out;
}

/** ชุดที่ "เปิดใช้" ในโครงการนี้ (ตารางกรอกสร้างจากชุดนี้) */
function _fsActiveSubs(PDO $pdo, int $projectId): array {
    $st = $pdo->prepare(
        "SELECT s.sub_code, s.name, sp.mango_vendor_code, sp.mango_vendor_name
         FROM subcontractors s
         JOIN sub_projects sp ON sp.sub_id = s.id AND sp.project_id = ?
         WHERE sp.enabled = 1 AND s.status = 'active'
         ORDER BY s.sub_code"
    );
    $st->execute([$projectId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = [
            'subId'     => fmtSubId($r['sub_code']),
            'subName'   => (string)$r['name'],
            'mangoCode' => (string)($r['mango_vendor_code'] ?? ''),
            'mangoName' => (string)($r['mango_vendor_name'] ?? ''),
        ];
    }
    return $out;
}

/** ชื่อชุดที่ "ปิดใช้" ในโครงการนี้ (แถวเก่ายังอยู่แต่แก้ไม่ได้) */
function _fsInactiveSubNames(PDO $pdo, int $projectId): array {
    $st = $pdo->prepare(
        "SELECT s.name FROM subcontractors s
         JOIN sub_projects sp ON sp.sub_id = s.id AND sp.project_id = ?
         WHERE sp.enabled = 0 OR s.status <> 'active'"
    );
    $st->execute([$projectId]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(string)$r['name']] = true;
    return $out;
}

/** จำนวนคนลงงานของ "วันล่าสุดก่อนหน้า" ทั้งไซต์ — ใช้ prefill พื้นเหลือง (ข้ามวัน NoReport) */
function _fsPrevDayWorkers(PDO $pdo, int $projectId, string $date): array {
    $st = $pdo->prepare(
        "SELECT MAX(scan_date) FROM finger_scan_logs
         WHERE project_id = ? AND scan_date < ? AND no_report = 0 AND worker_count IS NOT NULL"
    );
    $st->execute([$projectId, $date]);
    $prev = $st->fetchColumn();
    if (!$prev) return ['date' => '', 'workers' => []];

    $st = $pdo->prepare(
        "SELECT s.sub_code, l.worker_count
         FROM finger_scan_logs l LEFT JOIN subcontractors s ON s.id = l.sub_id
         WHERE l.project_id = ? AND l.scan_date = ? AND l.no_report = 0 AND l.worker_count IS NOT NULL"
    );
    $st->execute([$projectId, $prev]);
    $w = [];
    foreach ($st->fetchAll() as $r) {
        $sid = fmtSubId($r['sub_code'] ?? '');
        if ($sid !== '') $w[$sid] = (float)$r['worker_count'];
    }
    return ['date' => (string)$prev, 'workers' => $w];
}

/** แถวยืนยันของวัน (BS ตรวจแล้ว) */
function _fsVerifyOf(PDO $pdo, int $projectId, string $date): ?array {
    $st = $pdo->prepare("SELECT * FROM finger_scan_verify WHERE project_id = ? AND scan_date = ?");
    $st->execute([$projectId, $date]);
    $r = $st->fetch();
    if (!$r) return null;
    return [
        'by'     => (string)($r['verified_by'] ?? ''),
        'at'     => !empty($r['verified_at']) ? strtotime($r['verified_at']) * 1000 : null,
        'hasSig' => trim((string)($r['signature_path'] ?? '')) !== '',
        'note'   => (string)($r['note'] ?? ''),
    ];
}

/** ล้างการยืนยันของวัน (ข้อมูลเปลี่ยน → BS ต้องตรวจ+เซ็นใหม่) */
function _fsClearVerify(PDO $pdo, int $projectId, string $date): void {
    $st = $pdo->prepare("SELECT signature_path FROM finger_scan_verify WHERE project_id = ? AND scan_date = ?");
    $st->execute([$projectId, $date]);
    $old = $st->fetchColumn();
    $pdo->prepare("DELETE FROM finger_scan_verify WHERE project_id = ? AND scan_date = ?")
        ->execute([$projectId, $date]);
    if ($old) _sigUnlink((string)$old);
}

/**
 * สถานะรายวันของชุดวันที่ที่ให้มา — รูปเดียวกับ GAS (_fsPeriodStatus_ / _fsMonthDays_)
 *   [{ d:'YYYY-MM-DD', status:'verified'|'recorded'|'none', isToday, isFuture, crews }]
 * ⚠ client (_fsRenderDayStrip ใน index.php) อ่าน x.d / x.status / x.crews ตรง ๆ
 *   — ห้ามคืนเป็น array ของ string เด็ดขาด (จะพังที่ x.d.slice())
 */
function _fsDayStatusList(PDO $pdo, int $projectId, array $dayKeys): array {
    if (!$dayKeys) return [];
    $ph = implode(',', array_fill(0, count($dayKeys), '?'));

    $st = $pdo->prepare("SELECT scan_date, COUNT(*) AS crews FROM finger_scan_logs
                          WHERE project_id = ? AND scan_date IN ($ph) GROUP BY scan_date");
    $st->execute(array_merge([$projectId], $dayKeys));
    $crews = [];
    foreach ($st->fetchAll() as $r) $crews[(string)$r['scan_date']] = (int)$r['crews'];

    $st = $pdo->prepare("SELECT scan_date FROM finger_scan_verify
                          WHERE project_id = ? AND scan_date IN ($ph)");
    $st->execute(array_merge([$projectId], $dayKeys));
    $verified = [];
    foreach ($st->fetchAll() as $r) $verified[(string)$r['scan_date']] = true;

    $today = _fsToday();
    $out   = [];
    foreach ($dayKeys as $dk) {
        $has = isset($crews[$dk]);
        $out[] = [
            'd'        => $dk,
            'status'   => isset($verified[$dk]) ? 'verified' : ($has ? 'recorded' : 'none'),
            'isToday'  => $dk === $today,
            'isFuture' => $dk > $today,
            'crews'    => $has ? $crews[$dk] : 0,
        ];
    }
    return $out;
}

/**
 * วันในงวดครึ่งเดือนของ $date + สถานะ
 * ⚠ notRecorded / unverified = "จำนวนวัน" (int) ตาม GAS — client เอาไปทำ badge ตรง ๆ
 *   วันที่ยังไม่บันทึก นับเป็น "ยังไม่ตรวจ" ด้วย (ล้อกติกา _fsPeriodStatus_ ของ GAS)
 */
function _fsPeriodStatus(PDO $pdo, int $projectId, string $date): array {
    $y = (int)substr($date, 0, 4); $m = (int)substr($date, 5, 2); $d = (int)substr($date, 8, 2);
    $last  = (int)date('t', mktime(0, 0, 0, $m, 1, $y));
    $range = $d <= 15 ? range(1, 15) : range(16, $last);
    $keys  = [];
    foreach ($range as $dd) $keys[] = sprintf('%04d-%02d-%02d', $y, $m, $dd);

    $days = _fsDayStatusList($pdo, $projectId, $keys);
    $notRecorded = 0;
    $unverified  = 0;
    foreach ($days as $x) {
        if ($x['isFuture']) continue;            // วันอนาคตไม่นับ (แต่ยังอยู่ใน days ให้แถบวันแสดงได้)
        if ($x['status'] === 'none')         { $notRecorded++; $unverified++; }
        elseif ($x['status'] === 'recorded') { $unverified++; }
    }
    return ['days' => $days, 'notRecorded' => $notRecorded, 'unverified' => $unverified];
}

/** สถานะทุกวันของทั้งเดือน — แถบเลือกวันบนแท็บ "บันทึกรายวัน" (กดได้ทุกวัน ไม่จำกัดครึ่งงวด) */
function _fsMonthDays(PDO $pdo, int $projectId, string $ym): array {
    $y = (int)substr($ym, 0, 4); $m = (int)substr($ym, 5, 2);
    $last = (int)date('t', mktime(0, 0, 0, $m, 1, $y));
    $keys = [];
    for ($d = 1; $d <= $last; $d++) $keys[] = sprintf('%04d-%02d-%02d', $y, $m, $d);
    return _fsDayStatusList($pdo, $projectId, $keys);
}

/** จำนวนวันของ "เดือนปัจจุบัน" ที่บันทึกแล้วแต่ BS ยังไม่ตรวจ — badge เมนูบันทึกรายวัน */
function _fsPendingVerifyDays(PDO $pdo, int $projectId): int {
    $n = 0;
    foreach (_fsMonthDays($pdo, $projectId, substr(_fsToday(), 0, 7)) as $x) {
        if ($x['status'] === 'recorded') $n++;
    }
    return $n;
}

/** ผู้ใช้ที่ล็อกอินมีลายเซ็นในระบบหรือยัง — BS ต้องมีก่อนจึงกด "ตรวจสอบแล้ว · ลงลายเซ็น" ได้ */
function _fsMyHasSignature(PDO $pdo, ?array $user): bool {
    if (!$user || ($user['accountType'] ?? '') !== 'user') return false;
    $st = $pdo->prepare("SELECT signature_path FROM users WHERE id = ? LIMIT 1");
    $st->execute([(int)($user['accountId'] ?? 0)]);
    return trim((string)$st->fetchColumn()) !== '';
}

/** จำนวนเคส "แสกนเกินลงงาน" ที่ยังไม่ได้ชี้แจง ทั้งไซต์ */
function _fsPendingAlertCount(PDO $pdo, int $projectId): int {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM finger_scan_logs
         WHERE project_id = ? AND no_report = 0
           AND worker_count IS NOT NULL AND scan_count IS NOT NULL
           AND scan_count > worker_count
           AND (scan_exceed_note IS NULL OR scan_exceed_note = '')"
    );
    $st->execute([$projectId]);
    return (int)$st->fetchColumn();
}

// ---------------------------------------------------------------------------
// แท็บ 1 — บันทึกรายวัน
// ---------------------------------------------------------------------------

/** getFingerScanData({siteCode, date, username}) */
function rpc_getFingerScanData(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_fsCanUse($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];
        $pid  = $site['projectId'];

        $date = trim((string)($p['date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = _fsToday();

        $rows      = _fsLoadRows($pdo, $pid, $date, $date);
        $closedMap = _fsInactiveSubNames($pdo, $pid);
        $prev      = _fsPrevDayWorkers($pdo, $pid, $date);
        $ps        = _fsPeriodStatus($pdo, $pid, $date);

        $closedRows = [];
        $incomplete = 0;
        foreach ($rows as $r) {
            $isClosed = isset($closedMap[$r['subName']]);
            if ($isClosed) { $closedRows[] = $r; continue; }
            // นับเฉพาะแถวที่ยังแก้ได้ — แถวของชุดที่ปิดแล้วไม่ควรบล็อกการเซ็นของ BS
            if (_fsRowIncomplete([
                'no_report' => $r['noReport'], 'worker_count' => $r['workerCount'],
                'scan_count' => $r['scanCount'], 'system_worker_count' => $r['systemWorkerCount'],
            ])) $incomplete++;
        }

        // แถบเลือกวัน = ทุกวันของเดือนที่กำลังดู พร้อมสถานะรายวัน
        $ym        = substr($date, 0, 7);
        $monthDays = _fsMonthDays($pdo, $pid, $ym);

        // badge "รอ BS เซ็น" = วันที่บันทึกแล้วแต่ยังไม่ตรวจ ของ *เดือนปัจจุบัน* เสมอ
        // ถ้ากำลังดูเดือนปัจจุบันอยู่ก็นับจาก $monthDays ที่มีแล้ว ไม่ต้องยิง query ซ้ำ (ล้อ GAS)
        if ($ym === substr(_fsToday(), 0, 7)) {
            $pendv = 0;
            foreach ($monthDays as $x) { if ($x['status'] === 'recorded') $pendv++; }
        } else {
            $pendv = _fsPendingVerifyDays($pdo, $pid);
        }

        return [
            'success'            => true,
            'sites'              => $site['sites'],
            'isAdmin'            => $site['isAdmin'],
            'siteCode'           => $site['siteCode'],
            'siteName'           => $site['siteName'],
            'date'               => $date,
            'rate'               => _fsRate($pdo, $pid),
            'subs'               => _fsActiveSubs($pdo, $pid),
            'rows'               => $rows,
            'closedRows'         => $closedRows,
            'prevWorkerBySubId'  => $prev['workers'],
            'prevWorkerDate'     => $prev['date'],
            'alertPending'       => _fsPendingAlertCount($pdo, $pid),
            'periodDays'         => $ps['days'],          // [{d,status,isToday,isFuture,crews}]
            'monthDays'          => $monthDays,           // รูปเดียวกัน — แถบเลือกวันใช้ตัวนี้ก่อน
            'notRecordedDays'    => $ps['notRecorded'],   // จำนวนวัน (int) — client parseInt ไปทำ badge
            'unverifiedDays'     => $ps['unverified'],    // จำนวนวัน (int)
            'pendingVerifyDays'  => $pendv,               // จำนวนวันของเดือนปัจจุบันที่รอ BS เซ็น
            'dayRecorded'        => count($rows) > 0,
            'dayIncomplete'      => $incomplete,
            'dayVerify'          => _fsVerifyOf($pdo, $pid, $date),
            'canBS'              => _fsCanBS($pdo, $user),
            'myHasSignature'     => _fsMyHasSignature($pdo, $user),   // ไม่มี = ปุ่มลงลายเซ็นถูก disable
            'today'              => _fsToday(),
        ];
    } catch (Throwable $e) {
        error_log('getFingerScanData error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * saveFingerScanDay({siteCode, date, rows[], username})
 * แทนที่ข้อมูลทั้งวัน — แต่คงแถวของชุดที่ "ปิดใช้งานแล้วและไม่ได้ถูกส่งมา" ไว้เสมอ (v1.10.0)
 */
function rpc_saveFingerScanDay(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_fsCanUse($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];
        $pid  = $site['projectId'];

        $date = trim((string)($p['date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['success' => false, 'message' => 'รูปแบบวันที่ไม่ถูกต้อง'];
        }
        if ($date > _fsToday()) {
            return ['success' => false, 'message' => 'บันทึกล่วงหน้าไม่ได้ — เลือกได้ถึงวันนี้เท่านั้น'];
        }

        // ชุดในไซต์นี้ (รวม inactive — กันข้อมูลวันเก่าของชุดที่เพิ่งปิด)
        $st = $pdo->prepare(
            "SELECT s.id, s.sub_code, s.name FROM subcontractors s
             JOIN sub_projects sp ON sp.sub_id = s.id AND sp.project_id = ?"
        );
        $st->execute([$pid]);
        $byCode = [];
        foreach ($st->fetchAll() as $r) {
            $byCode[fmtSubId($r['sub_code'])] = ['id' => (int)$r['id'], 'name' => (string)$r['name']];
        }

        $rate     = _fsRate($pdo, $pid);
        $username = trim((string)($user['username'] ?? ''));
        $inRows   = is_array($p['rows'] ?? null) ? $p['rows'] : [];

        // SubID ที่หน้าเว็บ "มีช่องให้กรอก" รอบนี้ — เก็บก่อนกรองแถวว่าง เพราะแถวที่ถูกล้างจนว่าง
        // = ผู้ใช้ตั้งใจลบข้อมูลของชุดนั้น (ต้องปล่อยให้ลบ ไม่ใช่คงแถวเดิมไว้)
        $submitted = [];
        foreach ($inRows as $r) {
            $sid = fmtSubId(is_array($r) ? ($r['subId'] ?? '') : '');
            if ($sid !== '') $submitted[$sid] = true;
        }

        $newRows = [];
        $incompleteCount = 0;
        foreach ($inRows as $r) {
            if (!is_array($r)) continue;
            $sid = fmtSubId($r['subId'] ?? '');
            if ($sid === '' || !isset($byCode[$sid])) continue;   // ชุดไม่อยู่ในไซต์ → ข้าม
            $w   = _fsNum($r['workerCount'] ?? '');
            $s   = _fsNum($r['scanCount'] ?? '');
            $sys = _fsNum($r['systemWorkerCount'] ?? '');
            $noReport = (($r['noReport'] ?? false) === true);
            $note = trim((string)($r['note'] ?? ''));

            // แถวว่างจริง = ชุดไม่ลงงานวันนั้น → ไม่บันทึก (และถูกลบถ้ามีของเดิม)
            if ($w === '' && $s === '' && $sys === '' && !$noReport && $note === '') continue;

            $rowIncomplete = $noReport
                ? ($sys === '' || (float)$sys <= 0)
                : (($w !== '' && $s === '') || ($w === '' && $s !== ''));
            if ($rowIncomplete) $incompleteCount++;

            $newRows[] = [
                'subId'   => $sid,
                'subRowId'=> $byCode[$sid]['id'],
                'subName' => $byCode[$sid]['name'],
                // ไม่แจ้งยอด → ไม่เก็บทั้ง ลงงาน/แสกน (ฐานคิดปรับคือแรงงานในระบบ)
                'worker'  => $noReport ? null : ($w === '' ? null : (float)$w),
                'scan'    => $noReport ? null : ($s === '' ? null : (float)$s),
                'noReport'=> $noReport,
                'sys'     => $sys === '' ? null : (float)$sys,
                // ค่าปรับ = สูตรล้วนฝั่ง server · แถวไม่ครบ = NULL (ยังคิดไม่ได้)
                'fine'    => $rowIncomplete ? null : _fsComputeFine($w, $s, $noReport, $sys, $rate),
                'note'    => $note,
            ];
        }

        $pdo->beginTransaction();
        try {
            // คำชี้แจง "แสกนเกิน" เดิมของวันนี้ — carry forward เมื่อยัง exceed อยู่
            $st = $pdo->prepare(
                "SELECT sub_name, scan_exceed_note, scan_exceed_by, scan_exceed_at
                 FROM finger_scan_logs
                 WHERE project_id = ? AND scan_date = ? AND scan_exceed_note IS NOT NULL AND scan_exceed_note <> ''"
            );
            $st->execute([$pid, $date]);
            $oldExceed = [];
            foreach ($st->fetchAll() as $r) $oldExceed[(string)$r['sub_name']] = $r;

            // ชุดที่ปิดใช้งานแล้วและรอบนี้ไม่ได้ส่งมา = แก้ไม่ได้ → คงแถวเดิมไว้ ไม่ให้ถูกลบ
            $closedMap = _fsInactiveSubNames($pdo, $pid);
            $keepNames = [];
            foreach ($closedMap as $name => $_) {
                $code = null;
                foreach ($byCode as $c => $info) { if ($info['name'] === $name) { $code = $c; break; } }
                if ($code === null || !isset($submitted[$code])) $keepNames[] = $name;
            }

            $sql = "DELETE FROM finger_scan_logs WHERE project_id = ? AND scan_date = ?";
            $params = [$pid, $date];
            if ($keepNames) {
                $sql .= ' AND sub_name NOT IN (' . implode(',', array_fill(0, count($keepNames), '?')) . ')';
                $params = array_merge($params, $keepNames);
            }
            $del = $pdo->prepare($sql);
            $del->execute($params);

            $ins = $pdo->prepare(
                "INSERT INTO finger_scan_logs
                   (scan_date, project_id, sub_id, sub_name, worker_count, scan_count, no_report,
                    system_worker_count, fine_amt, note, scan_exceed_note, scan_exceed_by, scan_exceed_at,
                    recorded_by, recorded_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $exceedToday = 0;
            $exceedTodayPending = 0;
            foreach ($newRows as $r) {
                // ยัง "แสกนเกินลงงาน" → เอาคำชี้แจงเดิมกลับมา (กันการบันทึกทั้งวันไปลบทิ้ง)
                $exNote = null; $exBy = null; $exAt = null;
                $isExceed = ($r['worker'] !== null && $r['scan'] !== null && $r['scan'] > $r['worker']);
                if ($isExceed) {
                    $exceedToday++;
                    if (isset($oldExceed[$r['subName']])) {
                        $exNote = $oldExceed[$r['subName']]['scan_exceed_note'];
                        $exBy   = $oldExceed[$r['subName']]['scan_exceed_by'];
                        $exAt   = $oldExceed[$r['subName']]['scan_exceed_at'];
                    }
                    if (trim((string)$exNote) === '') $exceedTodayPending++;
                }
                $ins->execute([
                    $date, $pid, $r['subRowId'], $r['subName'],
                    $r['worker'], $r['scan'], $r['noReport'] ? 1 : 0,
                    $r['sys'], $r['fine'], $r['note'] !== '' ? $r['note'] : null,
                    $exNote, $exBy, $exAt, $username,
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // ข้อมูลของวันเปลี่ยน → ล้างการยืนยันของ BS ของวันนั้น (ต้องตรวจ+เซ็นใหม่)
        _fsClearVerify($pdo, $pid, $date);
        $ps = _fsPeriodStatus($pdo, $pid, $date);

        return [
            'success' => true, 'saved' => count($newRows), 'date' => $date,
            'keptClosed' => count($keepNames), 'incomplete' => $incompleteCount,
            'exceedToday' => $exceedToday, 'exceedTodayPending' => $exceedTodayPending,
            'alertPending' => _fsPendingAlertCount($pdo, $pid),
            'notRecordedDays' => $ps['notRecorded'], 'unverifiedDays' => $ps['unverified'],
            'pendingVerifyDays' => _fsPendingVerifyDays($pdo, $pid),
        ];
    } catch (Throwable $e) {
        error_log('saveFingerScanDay error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/** saveFingerScanRate({siteCode, rate, username}) — BS/R0 ตั้งอัตราค่าปรับของไซต์ */
function rpc_saveFingerScanRate(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_fsCanBS($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];
        $rate = _fsNum($p['rate'] ?? '');
        if ($rate === '' || (float)$rate < 0) {
            return ['success' => false, 'message' => 'กรุณาระบุอัตราค่าปรับเป็นตัวเลข (0 ขึ้นไป)'];
        }
        $pdo->prepare(
            "INSERT INTO finger_scan_config (project_id, cfg_key, cfg_value, updated_by, updated_at)
             VALUES (?, 'rate', ?, ?, NOW())
             ON DUPLICATE KEY UPDATE cfg_value = VALUES(cfg_value),
                                     updated_by = VALUES(updated_by), updated_at = NOW()"
        )->execute([$site['projectId'], (string)(float)$rate, trim((string)($user['username'] ?? ''))]);
        return ['success' => true, 'rate' => (float)$rate];
    } catch (Throwable $e) {
        error_log('saveFingerScanRate error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/** getFingerScanBadge(username) — badge เมนู: วันที่ยังไม่บันทึก / ยังไม่ตรวจ / เคสชี้แจงค้าง */
function rpc_getFingerScanBadge(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_fsCanUse($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $site = _ssResolveSite($pdo, $user, []);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];
        $pid = $site['projectId'];
        $ps  = _fsPeriodStatus($pdo, $pid, _fsToday());
        // ⚠ ชื่อ key ต้องตรงกับที่ _fsUpdateVerifyBadge() ใน index.php อ่าน
        return [
            'success'           => true,
            'notRecordedDays'   => $ps['notRecorded'],   // SC: วันที่ยังไม่บันทึก
            'unverifiedDays'    => $ps['unverified'],    // BS: วันที่ยังไม่ตรวจ
            'pendingVerifyDays' => _fsPendingVerifyDays($pdo, $pid),
            'alertPending'      => _fsPendingAlertCount($pdo, $pid),
        ];
    } catch (Throwable $e) {
        error_log('getFingerScanBadge error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// แท็บ 2 — สรุปงวด (ครึ่งเดือน) + ลิงก์เซ็นรับทราบของผู้รับเหมา
// ---------------------------------------------------------------------------

/** ช่วงวันของงวดครึ่งเดือน */
function _fsPeriodKeys(string $ym, int $half): array {
    $y = (int)substr($ym, 0, 4); $m = (int)substr($ym, 5, 2);
    $last = (int)date('t', mktime(0, 0, 0, $m, 1, $y));
    return $half === 1
        ? ['from' => sprintf('%04d-%02d-01', $y, $m),  'to' => sprintf('%04d-%02d-15', $y, $m)]
        : ['from' => sprintf('%04d-%02d-16', $y, $m),  'to' => sprintf('%04d-%02d-%02d', $y, $m, $last)];
}

/** เลขเอกสารคำขอเซ็นของสแกนนิ้ว — 'FS-{SITE}-{YYYYMM}-G{half}-{subId}' */
function _fsSignDocNo(string $siteCode, string $ym, int $half, string $subId): string {
    return 'FS-' . ($siteCode !== '' ? $siteCode : 'NA') . '-' . str_replace('-', '', $ym)
         . '-G' . $half . '-' . ($subId !== '' ? $subId : 'NA');
}

/** _fsSummarize_ : รวมยอดต่อชุดในช่วง (เรียงค่าปรับมาก→น้อย) */
function _fsSummarize(array $rows): array {
    $by = [];
    foreach ($rows as $r) {
        $k = $r['subId'] !== '' ? $r['subId'] : $r['subName'];
        if (!isset($by[$k])) {
            $by[$k] = ['subId' => $r['subId'], 'subName' => $r['subName'], 'days' => 0,
                       'noReportDays' => 0, 'workers' => 0.0, 'scans' => 0.0, 'missing' => 0.0, 'fine' => 0.0];
        }
        $rec =& $by[$k];
        $rec['days']++;
        if ($r['noReport']) $rec['noReportDays']++;
        if ($r['workerCount'] !== '') $rec['workers'] += (float)$r['workerCount'];
        if ($r['scanCount'] !== '')   $rec['scans']   += (float)$r['scanCount'];
        if (!$r['noReport'] && $r['workerCount'] !== '' && $r['scanCount'] !== '') {
            $rec['missing'] += max(0, (float)$r['workerCount'] - (float)$r['scanCount']);
        }
        if ($r['noReport'] && $r['systemWorkerCount'] !== '') $rec['missing'] += (float)$r['systemWorkerCount'];
        if ($r['fineAmt'] !== '') $rec['fine'] += (float)$r['fineAmt'];
        unset($rec);
    }
    $total = 0.0;
    $list = [];
    foreach ($by as $rec) {
        $rec['rate']  = $rec['workers'] > 0 ? $rec['scans'] / $rec['workers'] : ($rec['scans'] > 0 ? null : 1);
        $rec['fine']  = round($rec['fine'], 2);
        $total += $rec['fine'];
        $list[] = $rec;
    }
    $collator = class_exists('Collator') ? new Collator('th_TH') : null;
    usort($list, function ($a, $b) use ($collator) {
        if ($b['fine'] != $a['fine']) return $b['fine'] <=> $a['fine'];
        return $collator ? $collator->compare($a['subName'], $b['subName']) : strcmp($a['subName'], $b['subName']);
    });
    return ['list' => $list, 'total' => round($total, 2)];
}

/** งวดจาก payload ({ym, half}) — null ถ้าไม่ถูกต้อง */
function _fsParsePeriod(array $p): ?array {
    $ym = trim((string)($p['ym'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) return null;
    $half = (int)($p['half'] ?? 0);
    if ($half !== 1 && $half !== 2) return null;
    return ['ym' => $ym, 'half' => $half];
}

/** เติมชื่อ Mango อย่างเดียว — แท็บแดชบอร์ดใช้ (ไม่มีสถานะเซ็นราย งวด) */
function _fsAttachMango(PDO $pdo, array &$list, int $projectId): void {
    $st = $pdo->prepare(
        "SELECT s.sub_code, sp.mango_vendor_code, sp.mango_vendor_name
         FROM sub_projects sp JOIN subcontractors s ON s.id = sp.sub_id
         WHERE sp.project_id = ?"
    );
    $st->execute([$projectId]);
    $mango = [];
    foreach ($st->fetchAll() as $r) {
        $mango[fmtSubId($r['sub_code'])] = [
            'code' => (string)($r['mango_vendor_code'] ?? ''),
            'name' => (string)($r['mango_vendor_name'] ?? ''),
        ];
    }
    foreach ($list as &$rec) {
        $m = $mango[$rec['subId']] ?? ['code' => '', 'name' => ''];
        $rec['mangoCode'] = $m['code'];
        $rec['mangoName'] = $m['name'];
    }
    unset($rec);
}

/** เติมชื่อ Mango + สถานะเซ็นให้แต่ละชุดในตารางงวด */
function _fsAttachMangoAndSign(PDO $pdo, array &$list, int $projectId, string $siteCode, string $ym, int $half): void {
    _fsAttachMango($pdo, $list, $projectId);
    foreach ($list as &$rec) {
        $docNo = _fsSignDocNo($siteCode, $ym, $half, $rec['subId']);
        $rec['docNo'] = $docNo;
        $rec['sign']  = _sigRowSummary(_sigFind($pdo, $docNo, 'contractor'));
    }
    unset($rec);
}

/** getFingerScanSummary({siteCode, ym, half, username}) */
function rpc_getFingerScanSummary(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_fsCanUse($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];
        $period = _fsParsePeriod($p);
        if (!$period) return ['success' => false, 'message' => 'bad_period'];

        $keys = _fsPeriodKeys($period['ym'], $period['half']);
        $rows = _fsLoadRows($pdo, $site['projectId'], $keys['from'], $keys['to']);
        $sum  = _fsSummarize($rows);
        _fsAttachMangoAndSign($pdo, $sum['list'], $site['projectId'], $site['siteCode'],
                              $period['ym'], $period['half']);

        // จำนวน "วันที่บันทึกแล้ว" ในงวด — หัวตารางแท็บสรุปงวดโชว์ตัวนี้
        $dayKeys = [];
        foreach ($rows as $r) $dayKeys[$r['date']] = true;

        return [
            'success'  => true,
            'sites'    => $site['sites'], 'isAdmin' => $site['isAdmin'],
            'siteCode' => $site['siteCode'], 'siteName' => $site['siteName'],
            'ym'       => $period['ym'], 'half' => $period['half'],
            'periodLabel' => _ssPeriodLabel($period['ym'], $period['half']),
            'from'     => $keys['from'], 'to' => $keys['to'],
            'rate'     => _fsRate($pdo, $site['projectId']),
            'list'     => $sum['list'], 'total' => $sum['total'],
            'dayCount' => count($dayKeys),
            'canBS'    => _fsCanBS($pdo, $user),
            'hasMySignature' => _fsMyHasSignature($pdo, $user),
            'alertPending'   => _fsPendingAlertCount($pdo, $site['projectId']),
            // client ประกอบลิงก์เป็น webAppUrl + '?page=sign&token=…' (โครงเดิมของ GAS)
            // ฝั่ง PHP หน้าเซ็นเป็นไฟล์แยก → ชี้มาที่ sign.php ตรง ๆ
            'webAppUrl' => rtrim(APP_BASE, '/') . '/sign.php',
        ];
    } catch (Throwable $e) {
        error_log('getFingerScanSummary error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// แท็บ 3 — ชี้แจงแสกนเกิน
// ---------------------------------------------------------------------------

/** getFingerScanAlerts({siteCode, username}) — ทุกเคสที่แสกน > ลงงาน */
function rpc_getFingerScanAlerts(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_fsCanUse($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];

        $st = $pdo->prepare(
            "SELECT l.*, s.sub_code FROM finger_scan_logs l
             LEFT JOIN subcontractors s ON s.id = l.sub_id
             WHERE l.project_id = ? AND l.no_report = 0
               AND l.worker_count IS NOT NULL AND l.scan_count IS NOT NULL
               AND l.scan_count > l.worker_count
             ORDER BY l.scan_date DESC, l.sub_name"
        );
        $st->execute([$site['projectId']]);
        $list = [];
        foreach ($st->fetchAll() as $r) {
            $note = trim((string)($r['scan_exceed_note'] ?? ''));
            $list[] = [
                'date'    => (string)$r['scan_date'],
                'subId'   => fmtSubId($r['sub_code'] ?? ''),
                'subName' => (string)$r['sub_name'],
                'workerCount' => (float)$r['worker_count'],
                'scanCount'   => (float)$r['scan_count'],
                'diff'    => (float)$r['scan_count'] - (float)$r['worker_count'],
                'note'    => $note,
                'by'      => (string)($r['scan_exceed_by'] ?? ''),
                'at'      => !empty($r['scan_exceed_at']) ? strtotime($r['scan_exceed_at']) * 1000 : null,
                'cleared' => $note !== '',
            ];
        }
        $pending = 0;
        foreach ($list as $x) { if (!$x['cleared']) $pending++; }
        return [
            'success' => true,
            'sites' => $site['sites'], 'isAdmin' => $site['isAdmin'],
            'siteCode' => $site['siteCode'], 'siteName' => $site['siteName'],
            'list' => $list, 'pendingCount' => $pending,
        ];
    } catch (Throwable $e) {
        error_log('getFingerScanAlerts error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/** เขียนคำชี้แจง 1 เคส (ใช้ร่วมทั้ง single/bulk) */
function _fsWriteAlertNote(PDO $pdo, int $projectId, string $date, string $subId,
                           string $note, string $by): bool {
    $st = $pdo->prepare(
        "UPDATE finger_scan_logs l
         LEFT JOIN subcontractors s ON s.id = l.sub_id
            SET l.scan_exceed_note = ?, l.scan_exceed_by = ?, l.scan_exceed_at = NOW()
          WHERE l.project_id = ? AND l.scan_date = ? AND s.sub_code = ?"
    );
    $st->execute([$note !== '' ? $note : null, $note !== '' ? $by : null, $projectId, $date, $subId]);
    return $st->rowCount() > 0;
}

/** saveFingerScanAlertNote({siteCode, date, subId, note, username}) */
function rpc_saveFingerScanAlertNote(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_fsCanUse($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];
        $date  = trim((string)($p['date'] ?? ''));
        $subId = fmtSubId($p['subId'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $subId === '') {
            return ['success' => false, 'message' => 'bad_request'];
        }
        $ok = _fsWriteAlertNote($pdo, $site['projectId'], $date, $subId,
                                trim((string)($p['note'] ?? '')), trim((string)($user['username'] ?? '')));
        if (!$ok) return ['success' => false, 'message' => 'ไม่พบแถวของวัน/ชุดนี้'];
        return ['success' => true, 'alertPending' => _fsPendingAlertCount($pdo, $site['projectId'])];
    } catch (Throwable $e) {
        error_log('saveFingerScanAlertNote error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/** saveFingerScanAlertNotes({siteCode, notes:[{date, subId, note}], username}) — บันทึกทีเดียวหลายเคส */
function rpc_saveFingerScanAlertNotes(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_fsCanUse($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];
        $by    = trim((string)($user['username'] ?? ''));
        $notes = is_array($p['notes'] ?? null) ? $p['notes'] : [];
        $saved = 0;
        $pdo->beginTransaction();
        try {
            foreach ($notes as $n) {
                if (!is_array($n)) continue;
                $date  = trim((string)($n['date'] ?? ''));
                $subId = fmtSubId($n['subId'] ?? '');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $subId === '') continue;
                if (_fsWriteAlertNote($pdo, $site['projectId'], $date, $subId,
                                      trim((string)($n['note'] ?? '')), $by)) $saved++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['success' => true, 'saved' => $saved,
                'alertPending' => _fsPendingAlertCount($pdo, $site['projectId'])];
    } catch (Throwable $e) {
        error_log('saveFingerScanAlertNotes error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// แท็บ 4 — แดชบอร์ด
// ---------------------------------------------------------------------------

/** getFingerScanDashboard({siteCode, ym, username}) */
function rpc_getFingerScanDashboard(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_fsCanUse($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];
        $pid  = $site['projectId'];
        $ym   = trim((string)($p['ym'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = substr(_fsToday(), 0, 7);

        $y = (int)substr($ym, 0, 4); $m = (int)substr($ym, 5, 2);
        $last = (int)date('t', mktime(0, 0, 0, $m, 1, $y));
        $rows = _fsLoadRows($pdo, $pid, sprintf('%04d-%02d-01', $y, $m), sprintf('%04d-%02d-%02d', $y, $m, $last));

        $halves = [];
        foreach ([1, 2] as $h) {
            $keys = _fsPeriodKeys($ym, $h);
            $sub  = array_values(array_filter($rows, function ($r) use ($keys) {
                return $r['date'] >= $keys['from'] && $r['date'] <= $keys['to'];
            }));
            $s = _fsSummarize($sub);
            $signed = 0; $auto = 0; $pending = 0; $none = 0;
            foreach ($s['list'] as $rec) {
                $sr = _sigFind($pdo, _fsSignDocNo($site['siteCode'], $ym, $h, $rec['subId']), 'contractor');
                if (!$sr)                            { $none++;    continue; }
                if ($sr['status'] === 'signed')      { $signed++;  continue; }
                if ($sr['status'] === 'auto')        { $auto++;    continue; }
                if ($sr['status'] === 'pending')     { $pending++; continue; }
                $none++;
            }
            $halves[] = ['half' => $h, 'fine' => $s['total'], 'crews' => count($s['list']),
                         'signed' => $signed, 'auto' => $auto, 'pending' => $pending, 'none' => $none];
        }

        // รวมรายวัน — กราฟแท่ง "ลงงาน/แสกน รายวัน" + ตัวนับสถานะวันบนการ์ดด้านบน
        $byDay = [];
        foreach ($rows as $r) {
            $dk = $r['date'];
            if (!isset($byDay[$dk])) $byDay[$dk] = ['workers' => 0.0, 'scans' => 0.0, 'fine' => 0.0, 'crews' => 0, 'inc' => 0];
            $byDay[$dk]['crews']++;
            if ($r['workerCount'] !== '') $byDay[$dk]['workers'] += (float)$r['workerCount'];
            if ($r['scanCount'] !== '')   $byDay[$dk]['scans']   += (float)$r['scanCount'];
            if ($r['fineAmt'] !== '')     $byDay[$dk]['fine']    += (float)$r['fineAmt'];
            if (_fsRowIncomplete([
                'no_report' => $r['noReport'], 'worker_count' => $r['workerCount'],
                'scan_count' => $r['scanCount'], 'system_worker_count' => $r['systemWorkerCount'],
            ])) $byDay[$dk]['inc']++;
        }
        $vst = $pdo->prepare("SELECT scan_date FROM finger_scan_verify
                               WHERE project_id = ? AND scan_date LIKE ?");
        $vst->execute([$pid, $ym . '-%']);
        $vmap = [];
        foreach ($vst->fetchAll() as $r) $vmap[(string)$r['scan_date']] = true;

        $today = _fsToday();
        $daily = [];
        $recordedDays = 0; $verifiedDays = 0; $incompleteDays = 0; $pastDays = 0;
        for ($d = 1; $d <= $last; $d++) {
            $dk  = sprintf('%04d-%02d-%02d', $y, $m, $d);
            $rec = $byDay[$dk] ?? null;
            $ver = isset($vmap[$dk]);
            $fut = $dk > $today;
            if (!$fut) $pastDays++;
            if ($rec) {
                $recordedDays++;
                if ($ver) $verifiedDays++;
                if ($rec['inc']) $incompleteDays++;
            }
            $daily[] = [
                'd'        => $dk,
                'workers'  => $rec ? $rec['workers'] : 0,
                'scans'    => $rec ? $rec['scans'] : 0,
                'fine'     => $rec ? round($rec['fine'], 2) : 0,
                'crews'    => $rec ? $rec['crews'] : 0,
                'inc'      => $rec ? $rec['inc'] : 0,
                'recorded' => $rec !== null,
                'verified' => $ver,
                'isToday'  => $dk === $today,
                'isFuture' => $fut,
            ];
        }

        $alertMonth = 0; $alertMonthPending = 0;
        $workers = 0.0; $scans = 0.0; $fineTotal = 0.0;
        foreach ($rows as $r) {
            $isExceed = (!$r['noReport'] && $r['workerCount'] !== '' && $r['scanCount'] !== ''
                         && (float)$r['scanCount'] > (float)$r['workerCount']);
            if ($isExceed) { $alertMonth++; if (trim($r['scanExceedNote']) === '') $alertMonthPending++; }
            if ($r['workerCount'] !== '') $workers += (float)$r['workerCount'];
            if ($r['scanCount'] !== '')   $scans   += (float)$r['scanCount'];
            if ($r['fineAmt'] !== '')     $fineTotal += (float)$r['fineAmt'];
        }
        $sumMonth = _fsSummarize($rows);
        _fsAttachMango($pdo, $sumMonth['list'], $pid);   // ตาราง "สรุปรายชุด (ทั้งเดือน)" โชว์ชื่อใช้เบิก

        return [
            'success'  => true,
            'sites'    => $site['sites'], 'isAdmin' => $site['isAdmin'],
            'siteCode' => $site['siteCode'], 'siteName' => $site['siteName'],
            'ym'       => $ym, 'monthLabel' => _sigThMonthLabel($ym), 'lastDay' => $last,
            'rate'     => _fsRate($pdo, $pid),
            'halves'   => $halves,
            'alertMonth' => $alertMonth, 'alertMonthPending' => $alertMonthPending,
            'workers'  => $workers, 'scans' => $scans,
            'scanRate' => $workers > 0 ? $scans / $workers : null,
            'fineTotal' => round($fineTotal, 2),
            // ⚠ client อ่านชื่อ crews/crewsCount + ตัวนับวันด้านล่างนี้ — ห้ามเปลี่ยนชื่อ key
            'recordedDays'   => $recordedDays,
            'verifiedDays'   => $verifiedDays,
            'incompleteDays' => $incompleteDays,
            'pastDays'       => $pastDays,
            'daily'      => $daily,
            'crews'      => $sumMonth['list'],
            'crewsCount' => count($sumMonth['list']),
        ];
    } catch (Throwable $e) {
        error_log('getFingerScanDashboard error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// BS ตรวจสอบ + ลงลายเซ็นรายวัน (FingerScanVerify) — v1.9.0
// ---------------------------------------------------------------------------

/** verifyFingerScanDay({siteCode, date, note, username}) — ใช้ลายเซ็นที่ผูกกับบัญชี BS */
function rpc_verifyFingerScanDay(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_fsCanBS($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];
        $pid  = $site['projectId'];
        $date = trim((string)($p['date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['success' => false, 'message' => 'รูปแบบวันที่ไม่ถูกต้อง'];
        }

        $rows = _fsLoadRows($pdo, $pid, $date, $date);
        if (!$rows) return ['success' => false, 'message' => 'ยังไม่มีการบันทึกข้อมูลของวันนี้ — ยืนยันไม่ได้'];

        // แถว "กรอกไม่ครบ" ของชุดที่ยังเปิดใช้ = ยังเซ็นไม่ได้ (v1.9.2)
        // ข้ามชุดที่ปิดใช้งานแล้ว — กรอกเพิ่มไม่ได้ ไม่ควรบล็อกการเซ็น (v1.10.0)
        $closed = _fsInactiveSubNames($pdo, $pid);
        $inc = [];
        foreach ($rows as $r) {
            if (isset($closed[$r['subName']])) continue;
            if (_fsRowIncomplete([
                'no_report' => $r['noReport'], 'worker_count' => $r['workerCount'],
                'scan_count' => $r['scanCount'], 'system_worker_count' => $r['systemWorkerCount'],
            ])) $inc[] = ['subId' => $r['subId'], 'subName' => $r['subName']];
        }
        if ($inc) return ['success' => false, 'message' => 'day_incomplete', 'incomplete' => $inc];

        // ต้องมีลายเซ็นผูกกับบัญชีก่อน
        $st = $pdo->prepare("SELECT signature_path FROM users WHERE id = ? LIMIT 1");
        $st->execute([(int)$user['accountId']]);
        $mine = trim((string)($st->fetchColumn() ?: ''));
        if ($mine === '') return ['success' => false, 'message' => 'no_signature'];

        // คัดลอกไฟล์ไว้กับการยืนยัน — แก้ลายเซ็นบัญชีทีหลังต้องไม่กระทบวันที่เซ็นไปแล้ว
        $rel = _sigSaveDataUrl(_sigReadDataUrl($mine), 'fsv_' . $pid . '_' . str_replace('-', '', $date));
        if ($rel === null) return ['success' => false, 'message' => 'คัดลอกลายเซ็นไม่สำเร็จ'];

        _fsClearVerify($pdo, $pid, $date);   // ลบของเดิม (ถ้ามี) + ไฟล์เก่า
        $pdo->prepare(
            "INSERT INTO finger_scan_verify (scan_date, project_id, verified_by, verified_at, signature_path, note)
             VALUES (?, ?, ?, NOW(), ?, ?)"
        )->execute([$date, $pid, trim((string)$user['username']), $rel,
                    trim((string)($p['note'] ?? '')) ?: null]);

        $ps = _fsPeriodStatus($pdo, $pid, $date);
        return ['success' => true, 'date' => $date, 'dayVerify' => _fsVerifyOf($pdo, $pid, $date),
                'notRecordedDays' => $ps['notRecorded'], 'unverifiedDays' => $ps['unverified'],
                'pendingVerifyDays' => _fsPendingVerifyDays($pdo, $pid)];
    } catch (Throwable $e) {
        error_log('verifyFingerScanDay error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/** cancelFingerScanVerify({siteCode, date, username}) — ยกเลิกการยืนยันของวัน */
function rpc_cancelFingerScanVerify(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_fsCanBS($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];
        $date = trim((string)($p['date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['success' => false, 'message' => 'รูปแบบวันที่ไม่ถูกต้อง'];
        }
        _fsClearVerify($pdo, $site['projectId'], $date);
        $ps = _fsPeriodStatus($pdo, $site['projectId'], $date);
        return ['success' => true, 'date' => $date,
                'notRecordedDays' => $ps['notRecorded'], 'unverifiedDays' => $ps['unverified'],
                'pendingVerifyDays' => _fsPendingVerifyDays($pdo, $site['projectId'])];
    } catch (Throwable $e) {
        error_log('cancelFingerScanVerify error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/** getFingerScanVerifyView({siteCode, date, username}) — ดูลายเซ็นที่ BS ลงไว้ */
function rpc_getFingerScanVerifyView(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_fsCanUse($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];
        $date = trim((string)($p['date'] ?? ''));
        $st = $pdo->prepare("SELECT * FROM finger_scan_verify WHERE project_id = ? AND scan_date = ?");
        $st->execute([$site['projectId'], $date]);
        $r = $st->fetch();
        if (!$r) return ['success' => false, 'message' => 'วันนี้ยังไม่ได้ตรวจสอบ'];
        return [
            'success'  => true, 'date' => $date,
            'by'       => (string)($r['verified_by'] ?? ''),
            'at'       => !empty($r['verified_at']) ? strtotime($r['verified_at']) * 1000 : null,
            'note'     => (string)($r['note'] ?? ''),
            'dataUrl'  => _sigReadDataUrl($r['signature_path'] ?? ''),
        ];
    } catch (Throwable $e) {
        error_log('getFingerScanVerifyView error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// ลิงก์เซ็นรับทราบค่าปรับสแกนนิ้ว (ใช้ทะเบียน sign_requests ร่วมกับใบหักเงิน)
// DocNo = FS-{SITE}-{YYYYMM}-G{half}-{subId}
// ---------------------------------------------------------------------------

/** สร้าง/คืนคำขอเซ็นของชุดหนึ่งในงวดหนึ่ง */
function _fsEnsureLink(PDO $pdo, array $site, string $ym, int $half, string $subId,
                       string $subName, float $deadlineDays, string $by): array {
    $docNo = _fsSignDocNo($site['siteCode'], $ym, $half, $subId);
    $row = _sigFind($pdo, $docNo, 'contractor');
    if ($row && in_array($row['status'], ['signed', 'auto'], true)) {
        return ['docNo' => $docNo, 'row' => $row, 'existed' => true];
    }
    $keys = _fsPeriodKeys($ym, $half);
    $daysLabel = $half === 1 ? 'งวด 1-15' : 'งวด 16-สิ้นเดือน';
    if ($row) {
        $pdo->prepare(
            "UPDATE sign_requests SET status = 'pending', deadline_days = ?,
                    token = COALESCE(token, ?), requested_by = ?, requested_at = NOW()
              WHERE id = ?"
        )->execute([$deadlineDays, _sigNewToken(), $by, (int)$row['id']]);
    } else {
        $pdo->prepare(
            "INSERT INTO sign_requests
               (doc_no, project_id, ym, sub_name, days_label, role, token, status,
                deadline_days, requested_by, requested_at)
             VALUES (?, ?, ?, ?, ?, 'contractor', ?, 'pending', ?, ?, NOW())"
        )->execute([$docNo, $site['projectId'], $ym, $subName, $daysLabel,
                    _sigNewToken(), $deadlineDays, $by]);
    }
    return ['docNo' => $docNo, 'row' => _sigFind($pdo, $docNo, 'contractor'), 'existed' => false];
}

/** createFingerScanLink({siteCode, ym, half, subId, deadlineDays, username}) */
function rpc_createFingerScanLink(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_fsCanBS($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];
        $period = _fsParsePeriod($p);
        if (!$period) return ['success' => false, 'message' => 'bad_period'];
        $subId = fmtSubId($p['subId'] ?? '');
        if ($subId === '') return ['success' => false, 'message' => 'ไม่ระบุชุด'];

        $dl = (float)($p['deadlineDays'] ?? 0);
        if (!is_finite($dl) || $dl < 0) $dl = 0;
        if ($dl > 60) $dl = 60;

        $st = $pdo->prepare("SELECT name FROM subcontractors WHERE sub_code = ? LIMIT 1");
        $st->execute([$subId]);
        $subName = (string)($st->fetchColumn() ?: $subId);

        $r = _fsEnsureLink($pdo, $site, $period['ym'], $period['half'], $subId, $subName,
                           $dl, trim((string)($user['username'] ?? '')));
        return ['success' => true, 'docNo' => $r['docNo'], 'existed' => $r['existed'],
                'sign' => _sigRowSummary($r['row']),
                'url'  => rtrim(APP_BASE, '/') . '/sign.php?token=' . (string)($r['row']['token'] ?? '')];
    } catch (Throwable $e) {
        error_log('createFingerScanLink error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/** createAllFingerScanLinks({siteCode, ym, half, deadlineDays, username}) — ทุกชุดที่มีค่าปรับในงวด */
function rpc_createAllFingerScanLinks(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_fsCanBS($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];
        $period = _fsParsePeriod($p);
        if (!$period) return ['success' => false, 'message' => 'bad_period'];

        $dl = (float)($p['deadlineDays'] ?? 0);
        if (!is_finite($dl) || $dl < 0) $dl = 0;
        if ($dl > 60) $dl = 60;
        $by = trim((string)($user['username'] ?? ''));

        $keys = _fsPeriodKeys($period['ym'], $period['half']);
        $sum  = _fsSummarize(_fsLoadRows($pdo, $site['projectId'], $keys['from'], $keys['to']));

        $made = [];
        $skipped = [];
        foreach ($sum['list'] as $rec) {
            if ($rec['subId'] === '') continue;
            $r = _fsEnsureLink($pdo, $site, $period['ym'], $period['half'],
                               $rec['subId'], $rec['subName'], $dl, $by);
            if ($r['existed']) { $skipped[] = $rec['subName']; continue; }
            $made[] = ['subId' => $rec['subId'], 'subName' => $rec['subName'], 'docNo' => $r['docNo'],
                       'url' => rtrim(APP_BASE, '/') . '/sign.php?token=' . (string)($r['row']['token'] ?? '')];
        }
        return ['success' => true, 'created' => $made, 'skipped' => $skipped];
    } catch (Throwable $e) {
        error_log('createAllFingerScanLinks error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/** setFingerScanSent({siteCode, ym, half, subId, sent, username}) — เริ่มนับ deadline */
function rpc_setFingerScanSent(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_fsCanBS($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];
        $period = _fsParsePeriod($p);
        if (!$period) return ['success' => false, 'message' => 'bad_period'];
        $subId = fmtSubId($p['subId'] ?? '');
        $docNo = _fsSignDocNo($site['siteCode'], $period['ym'], $period['half'], $subId);
        $row = _sigFind($pdo, $docNo, 'contractor');
        if (!$row) return ['success' => false, 'message' => 'ยังไม่ได้สร้างลิงก์ของชุดนี้'];
        if ($row['status'] !== 'pending') {
            return ['success' => false, 'message' => 'ชุดนี้ตอบกลับแล้ว (' . $row['status'] . ')'];
        }
        $sent = (($p['sent'] ?? true) !== false);
        $pdo->prepare("UPDATE sign_requests SET sent_at = ? WHERE id = ?")
            ->execute([$sent ? date('Y-m-d H:i:s') : null, (int)$row['id']]);
        return ['success' => true, 'sign' => _sigRowSummary(_sigFind($pdo, $docNo, 'contractor'))];
    } catch (Throwable $e) {
        error_log('setFingerScanSent error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/** cancelFingerScanLink({siteCode, ym, half, subId, username}) */
function rpc_cancelFingerScanLink(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_fsCanBS($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];
        $period = _fsParsePeriod($p);
        if (!$period) return ['success' => false, 'message' => 'bad_period'];
        $subId = fmtSubId($p['subId'] ?? '');
        $docNo = _fsSignDocNo($site['siteCode'], $period['ym'], $period['half'], $subId);
        $row = _sigFind($pdo, $docNo, 'contractor');
        if (!$row) return ['success' => false, 'message' => 'ไม่พบลิงก์ของชุดนี้'];
        if ($row['status'] !== 'pending') {
            return ['success' => false, 'message' => 'ชุดนี้ตอบกลับแล้ว — ยกเลิกไม่ได้'];
        }
        $pdo->prepare("UPDATE sign_requests SET status = 'cancelled', token = NULL WHERE id = ?")
            ->execute([(int)$row['id']]);
        return ['success' => true, 'docNo' => $docNo];
    } catch (Throwable $e) {
        error_log('cancelFingerScanLink error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/** cancelAllFingerScanLinks({siteCode, ym, half, username}) — ยกเลิกเฉพาะที่ยัง pending */
function rpc_cancelAllFingerScanLinks(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_fsCanBS($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $site = _ssResolveSite($pdo, $user, $p);
        if (!$site['projectId']) return ['success' => false, 'message' => 'ไม่พบ Site'];
        $period = _fsParsePeriod($p);
        if (!$period) return ['success' => false, 'message' => 'bad_period'];

        $prefix = 'FS-' . $site['siteCode'] . '-' . str_replace('-', '', $period['ym'])
                . '-G' . $period['half'] . '-%';
        $st = $pdo->prepare(
            "UPDATE sign_requests SET status = 'cancelled', token = NULL
              WHERE role = 'contractor' AND status = 'pending'
                AND project_id = ? AND doc_no LIKE ?"
        );
        $st->execute([$site['projectId'], $prefix]);
        return ['success' => true, 'cancelled' => $st->rowCount()];
    } catch (Throwable $e) {
        error_log('cancelAllFingerScanLinks error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * generateFingerScanPDF({siteCode, ym, half, username}) — สรุปงวดสแกนนิ้วเป็น PDF
 * ตาราง 6 คอลัมน์ (ชุด · ชื่อ MANGO · วันที่ลง · คนลงงาน · คนสแกน · ค่าปรับ)
 */
/** ลายเซ็นที่ผูกไว้กับชุดของโครงการ → ['byId'=>subCode→dataUrl, 'byName'=>lower(ชื่อ)→dataUrl] */
function _fsSubBoundSigs(PDO $pdo, int $projectId): array {
    $out = ['byId' => [], 'byName' => []];
    $st = $pdo->prepare(
        'SELECT s.sub_code, s.name, s.signature_path FROM subcontractors s
         JOIN sub_projects sp ON sp.sub_id = s.id AND sp.project_id = ? ORDER BY s.id'
    );
    $st->execute([$projectId]);
    foreach ($st->fetchAll() as $r) {
        $rel = trim((string)($r['signature_path'] ?? ''));
        if ($rel === '') continue;
        $d = _sigReadDataUrl($rel);
        if ($d === '') continue;
        $code = fmtSubId($r['sub_code']);
        $nm   = mb_strtolower(trim((string)$r['name']), 'UTF-8');
        if ($code !== '' && !isset($out['byId'][$code]))  $out['byId'][$code]  = $d;
        if ($nm !== ''   && !isset($out['byName'][$nm])) $out['byName'][$nm] = $d;
    }
    return $out;
}

/**
 * รวบรวมข้อมูล + ประกอบตัวแปร template สรุปค่าปรับสแกนนิ้ว (ล้อ generateFingerScanPDF ของ GAS)
 * คืน ['ok'=>true,'vars'=>...,'fileName'=>...] หรือ ['ok'=>false,'resp'=>ก้อนตอบ client]
 * (แยกจาก wrapper เพื่อให้โหมด "พิมพ์รวม" ของหักคจช. เรนเดอร์เป็น fragment ต่อท้ายได้ — ล้อ hostSS)
 */
function _fsBuildPdfDoc(PDO $pdo, ?array $user, array $p): array {
    $fail = function ($m) { return ['ok' => false, 'resp' => ['success' => false, 'message' => $m]]; };
    if (!_fsCanUse($pdo, $user)) return $fail('no_permission');
    require_once __DIR__ . '/pdf_engine.php';

    $site = _ssResolveSite($pdo, $user, $p);
    if (!$site['projectId']) return $fail('ไม่พบ Site');
    $period = _fsParsePeriod($p);
    if (!$period) return $fail('bad_period');
    $pid = $site['projectId'];

    $label = _ssPeriodLabel($period['ym'], $period['half']);   // 'วันที่ 16-31 ส.ค.69'
    $keys  = _fsPeriodKeys($period['ym'], $period['half']);
    $rows  = _fsLoadRows($pdo, $pid, $keys['from'], $keys['to']);
    if (!$rows) {
        return $fail('ยังไม่มีข้อมูลสแกนนิ้วของงวด' . $label . ' — กรุณาบันทึกก่อนพิมพ์เอกสาร');
    }
    $sum = _fsSummarize($rows);

    // สถานะเซ็นรับทราบรายชุด — auto-ack ถูก materialize ใน _sigFind แล้ว
    $signBySub = [];
    foreach ($sum['list'] as $rec) {
        $sr = _sigFind($pdo, _fsSignDocNo($site['siteCode'], $period['ym'], $period['half'], $rec['subId']), 'contractor');
        if ($sr) $signBySub[$rec['subId']] = $sr;
    }
    $boundSigs = _fsSubBoundSigs($pdo, $pid);

    $username = trim((string)($user['username'] ?? ''));
    $now = nowBkk();
    $genStamp = 'สร้างเมื่อ ' . $now->format('d/m/Y H:i') . ' น.' . ($username !== '' ? ' โดย ' . $username : '');
    $projectName  = trim((string)($p['projectName'] ?? '')) !== '' ? (string)$p['projectName']
                  : ($site['siteName'] !== '' ? $site['siteName'] : $site['siteCode']);
    $preparerName = trim((string)($p['preparerName'] ?? ''));
    $preparerPos  = trim((string)($p['preparerPos'] ?? ''));

    // ผู้จัดทำ = คนที่กดสร้างเอกสาร — ฝังลายเซ็นที่ตั้งไว้อัตโนมัติ
    $prepImg = '';
    if ($username !== '') {
        $uq = $pdo->prepare('SELECT signature_path FROM users WHERE username = ? LIMIT 1');
        $uq->execute([$username]);
        $rel = trim((string)$uq->fetchColumn());
        if ($rel !== '') $prepImg = _sigReadDataUrl($rel);
    }

    // วัน+เวลาที่ลงนามแบบไทยสั้น: '25 ก.ค. 69 · 14:32 น.' (mirror signStamp)
    $signStamp = function ($ts) {
        if (empty($ts)) return '';
        try { $d = new DateTime((string)$ts); } catch (Throwable $e) { return ''; }
        return pdfThaiShortBE2($d) . ' · ' . $d->format('H:i') . ' น.';
    };

    $daysSet = [];
    foreach ($rows as $r) { $daysSet[$r['date']] = true; }

    $tplRows = [];
    foreach ($sum['list'] as $i => $rec) {
        $sr = $signBySub[$rec['subId']] ?? null;
        $img = '';
        $lines = [];
        if ($sr && $sr['status'] === 'signed') {
            $img = _sigReadDataUrl((string)($sr['signature_path'] ?? ''));
            $sn  = trim((string)($sr['signer_name'] ?? ''));
            $lines = ['( ' . ($sn !== '' ? $sn : $rec['subName']) . ' )', 'ลงนาม ' . $signStamp($sr['signed_at'] ?? '')];
        } elseif ($sr && $sr['status'] === 'auto') {
            // เกินกำหนดโดยไม่ตอบกลับ — ชุดที่ผูกลายเซ็นไว้ ใช้ลายเซ็นนั้นแสดงแทน (กำกับว่าปริยาย)
            $img = $boundSigs['byId'][$rec['subId']]
                ?? $boundSigs['byName'][mb_strtolower((string)$rec['subName'], 'UTF-8')] ?? '';
            $lines = $img !== ''
                ? ['( ' . $rec['subName'] . ' )', 'รับทราบโดยปริยาย', $signStamp($sr['signed_at'] ?? '')]
                : ['รับทราบโดยปริยาย', '(ครบกำหนด ' . $signStamp($sr['signed_at'] ?? '') . ')'];
        } elseif ($sr && $sr['status'] === 'pending') {
            $lines = [!empty($sr['sent_at']) ? 'รอลงนาม (ส่งแล้ว)' : 'รอลงนาม'];
        }
        $tplRows[] = [
            'no'      => $i + 1,
            'subName' => (string)$rec['subName'],
            'fineStr' => number_format(round((float)$rec['fine'])),
            'fineRed' => (float)$rec['fine'] > 0,
            'rateStr' => $rec['rate'] === null ? '' : number_format((float)$rec['rate'] * 100, 2) . '%',
            'sign'    => ['img' => $img, 'lines' => $lines],
            'note'    => $rec['noReportDays'] > 0 ? ('ไม่แจ้งยอด ' . (int)$rec['noReportDays'] . ' วัน') : '',
        ];
    }

    $fileName = 'สรุปค่าปรับสแกนนิ้ว งวด' . str_replace('วันที่ ', '', $label) . '_' . $site['siteCode'] . '.pdf';
    return [
        'ok'   => true,
        'vars' => [
            'projectName'  => $projectName,
            'siteCode'     => $site['siteCode'],
            'siteName'     => (string)$site['siteName'],
            'periodLabel'  => $label,
            'rateTxt'      => pdfBalNum(_fsRate($pdo, $pid)),
            'daysRecorded' => count($daysSet),
            'total'        => $sum['total'],
            'rows'         => $tplRows,
            'preparer'     => ['name' => $preparerName, 'pos' => $preparerPos, 'img' => $prepImg],
            'genStamp'     => $genStamp,
        ],
        'fileName' => $fileName,
    ];
}

function rpc_generateFingerScanPDF(PDO $pdo, ?array $user, array $args) {
    try {
        $p = is_array($args[0] ?? null) ? $args[0] : [];
        $b = _fsBuildPdfDoc($pdo, $user, $p);
        if (empty($b['ok'])) return $b['resp'];
        $html = pdfRenderTemplate('fingerscan_summary.php', $b['vars']);
        $bin  = renderPdf($html, 'a4', 'portrait');
        return ['success' => true, 'fileName' => $b['fileName'],
                'dataUri' => 'data:application/pdf;base64,' . base64_encode($bin)];
    } catch (Throwable $e) {
        error_log('generateFingerScanPDF error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
