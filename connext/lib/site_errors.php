<?php
/**
 * CONNEXT — lib/site_errors.php : Error log รายไซต์ (ตาราง error_logs) สำหรับทบทวนปัญหาหน้างาน
 * [PHP port 2026-09-24] — ไม่มีใน GAS (GAS เขียน ErrorLogs ลงชีตแต่ไม่มีหน้าดู)
 *
 * RPC: getSiteErrorLog(siteCode, days) → สรุปตามประเภท + รายวัน + รายการ (ใหม่ → เก่า)
 *
 * ที่มาของ error_logs:
 *   - เครื่องประตู (Raspberry Pi) ส่ง action=logError ผ่าน api/gate.php — บัตรไม่ได้ลงทะเบียน · ปิดประตูก่อนยืนยันครบ ·
 *     ประตูล็อกไม่ทัน · ตรวจอุปกรณ์ตอนเปิดเครื่องไม่ผ่าน · ส่งข้อมูลไม่สำเร็จ · ALARM 4 (LATCHED) + การปิดสัญญาณด้วยบัตรสายสโตร์ (2026-09-30)
 *   - api/gate.php เอง — submitPickingList ส่งเลขเอกสารที่ไม่มีรายการรอที่ประตู
 *   - lib/stock.php — ตัดยอดเกินที่มี (ปัดติดลบเป็น 0)
 *   - lib/gate_api.php — หน้าถ่ายรูปยืนยันบันทึกหยิบจริง 0 ทุกรายการ ("Zero pick:" · 2026-09-30)
 *   - ประวัติจาก GAS (ErrorLogs.csv ตอนนำเข้า)
 *
 * สิทธิ์ดู: ADM (R0) และระดับ ≥ 8 ทุกไซต์ · สายคลัง (roles.can_req) เฉพาะไซต์ตัวเอง · คนอื่นไม่เห็น
 * (ข้อความมีรหัสบัตรและชื่อผู้ถือบัตร)
 *
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/inventory_insights.php';   // _iiSite / _iiRole

/**
 * ประเภท: [ป้าย, pattern, ความหมาย/สิ่งที่ควรตรวจ] — ตรวจตามลำดับ (ทดสอบก่อนเสมอ จะได้ไม่ปนกับของจริง)
 */
function _seCategories(): array {
    return [
        'test'        => ['ข้อมูลทดสอบ', '/\bTEST\b|Claude|ZZTEST/i',
                          'บันทึกจากการทดสอบระบบ — ไม่ต้องดำเนินการ'],
        'card'        => ['สแกนบัตรที่ไม่ได้ลงทะเบียน', '/^Unauthorized card\b/i',
                          'บัตรนี้ไม่อยู่ในรายชื่อที่เปิดประตูได้ — ถ้าเป็นคนของไซต์ให้ลงทะเบียนบัตร ถ้าไม่ใช่ให้ตรวจว่าใครพยายามเข้า · บัตรที่ขึ้นซ้ำบ่อยควรดูก่อน'],
        'early_close' => ['ปิดประตูก่อนยืนยันครบ', '/Gate closed before all/i',
                          'ปิดประตูทั้งที่ยังถ่ายรูปยืนยันไม่ครบทุกใบในชุดหยิบ — ทบทวนขั้นตอนหน้าประตูกับผู้ถือบัตรที่ขึ้นบ่อย'],
        'not_locked'  => ['ประตูล็อกไม่ทันเวลา', '/not locked in time/i',
                          'ประตูไม่กลับมาล็อกภายในเวลาที่กำหนด — ตรวจกลอน/เซ็นเซอร์ประตู และการปิดประตูหลังหยิบของ'],
        'unknown_doc' => ['เลขเอกสารที่ประตูไม่รู้จัก', '/DocIDs not in GateLogs/i',
                          'เครื่องประตูส่งเลขเอกสารที่ไม่มีรายการรอที่ประตู — QR เก่า · ใบถูกยกเลิก · สแกนซ้ำหลังปิดงาน'],
        'network'     => ['ประตูส่งข้อมูลไม่สำเร็จ', '/postPicking failed|HTTPSConnectionPool|ConnectionError|Max retries|timed out|NameResolution/i',
                          'เครื่องประตูติดต่อเซิร์ฟเวอร์ไม่ได้ (เน็ต/เซิร์ฟเวอร์ล่ม) — ตรวจสัญญาณ LAN/4G · เอกสารช่วงนั้นอาจต้องตรวจสถานะซ้ำ'],
        'selfcheck'   => ['ตรวจอุปกรณ์ตอนเปิดเครื่องไม่ผ่าน', '/Self-check failed/i',
                          'อุปกรณ์ที่ประตูไม่พร้อมตอนเปิดเครื่อง (เช่น กล้องวงจรปิด) — ตรวจสาย ไฟ และตัวอุปกรณ์'],
        'stock'       => ['ยอดติดลบ (ตัดเกินที่มี)', '/would go negative|went negative/i',
                          'ระบบตัดยอดเกินจำนวนที่มี — ตั้งแต่ 2 ต.ค. 2026 ยอดติดลบแสดงสีแดงจนกว่าจะแก้ (ก่อนหน้านั้นปัดเป็น 0) · ตรวจเอกสารช่วงนั้นแล้วนับสต๊อกจริง (ใบนับ SC) หรือออกใบรับเข้า'],
        // ---- 2026-10-02 · health (GP-02 / GP-30–33 · AL-27) ----
        'gate_offline' => ['ตู้ออฟไลน์ / กลับมาออนไลน์', '/Gate offline|Gate back online/i',
                          'ตู้ไม่ส่งสัญญาณชีพเกิน 5 นาที (ไฟ/เน็ต/โปรแกรมตู้) — ประตูไม่มีไฟ = ไม่ล็อก (fail-safe) ให้สายสโตร์ดูแลประตูจนตู้กลับมา'],
        'device'      => ['อุปกรณ์ตู้เสีย / กลับมาใช้ได้', '/Device check (failed|recovered)/i',
                          'กล้อง/ตัวตรวจจับ/หัวอ่านของตู้ใช้ไม่ได้ระหว่างใช้งาน — ตรวจสายและไฟของอุปกรณ์'],
        'program_closed' => ['โปรแกรมตู้ถูกปิด', '/Program closed|โปรแกรมถูกปิด/i',
                          'มีคนปิดโปรแกรมที่ตู้ (ต้องแตะบัตรสายสโตร์ — ดูชื่อผู้แตะบัตร) · ระหว่างปิดประตูไม่ล็อก (fail-safe)'],
        // ---- 2026-10-02 · GP-17 / GP-21 ----
        'qr_reject'   => ['QR ถูกปฏิเสธ (ไซต์ / รหัสตรวจสอบ)', '/QR rejected/i',
                          'ตู้ส่งใบที่ไม่ใช่ของไซต์ตู้ หรือรหัสตรวจสอบ QR ไม่ตรง (QR สร้างเอง/แก้ไข/เปิดค้างก่อนเปลี่ยนรหัสลับ) — ดูบัตรที่แตะ ถ้าซ้ำบ่อยให้ตรวจสอบผู้ถือบัตร'],
        // ---- Scenario 05 (2026-09-28) ----
        'over_cap'    => ['รอบเกินเพดานเวลา (120 นาที)', '/OVER-CAP/i',
                          'รอบเบิกขอเวลาเพิ่มจนเวลารวมเกินเพดาน (ต้องใช้บัตรสายสโตร์) — ทบทวนว่าทำไมรอบนั้นใช้เวลานาน · ใบใหญ่ควรแยกรอบ'],
        'pick_time'   => ['หมดเวลาหยิบของ / ขอเวลาเพิ่ม', '/Pick time (expired|extended)/i',
                          'เวลาหยิบของของรอบหมด (3 นาที × รายการ) แล้วมีการกด "ขอเวลาเพิ่ม" + แตะบัตร — ดูผู้แตะบัตรที่ขึ้นบ่อย'],
        'close_late'  => ['ปิดประตูเกินเวลา (ALARM 3)', '/Close-door (timeout|time extended)|did not close the door within/i',
                          'ยืนยันครบแล้วแต่ไม่ปิดประตูภายใน 5 นาที หรือกดเลื่อนเวลา — ทบทวนกับผู้แตะบัตร'],
        'intruder'    => ['คนเข้าโซนโดยไม่สแกน (ALARM 4)', '/Person detected inside the zone/i',
                          'กล้องเห็นคนในโซนสีแดงขณะประตูล็อก — เปิดดูรูปหลักฐานของเหตุการณ์ · ตั้งแต่ 30 ก.ย. สัญญาณค้าง (LATCHED) และตู้หยุดรับงานจนกว่าสายสโตร์จะแตะบัตรแล้วกดยืนยันปิดสัญญาณที่ตู้'],
        // ---- ALARM 4 kill (2026-09-30) — ตู้ connext_access.py: บัตรสายสโตร์ + ปุ่มยืนยันที่ตู้ ----
        'alarm_kill'  => ['ปิดสัญญาณ ALARM 4 ที่ตู้', '/^ALARM 4 (killed|kill refused)\b/i',
                          '"killed" = สายสโตร์แตะบัตรและกดยืนยันปิดสัญญาณแล้ว (ดูเวลาที่สัญญาณค้างและผู้ปิด) · "kill refused" = มีคนพยายามปิดด้วยบัตรที่ไม่ใช่สายสโตร์หรือบัตรที่ไม่ได้ลงทะเบียน — ตรวจว่าเป็นใคร'],
        'wrong_gate'  => ['สแกนใบผิดประตู', '/ใบไม่ได้ออกให้ประตูนี้/u',
                          'นำใบของ G อื่นมาสแกน — ใบยังรอสแกน ให้ไปสแกนที่ตู้ตามรหัส G ท้ายเลขใบ (ไม่ต้องออกใบใหม่)'],
        'skipped_doc' => ['สแกนใบที่ไม่ได้รอสแกน', '/ข้ามใบที่ไม่ได้รอสแกน|ข้ามใบที่ยกเลิก|addToPicking rejected/u',
                          'QR ของใบที่แตะบัตรไปแล้ว/ปิดงาน/ยกเลิก ถูกสแกนซ้ำ หรือเพิ่มใบเข้ารอบที่ปิดแล้ว — ตรวจการใช้ QR เก่า'],
        'late_confirm'=> ['ยืนยันใบหลังตู้ปิดรอบ', '/Late confirm|closeGate PickingID=\S+ ขณะยังมีใบ/u',
                          'ตู้ส่งปิดประตูก่อนใบในรอบยืนยันครบ — ระบบปิดงานใบที่ค้างตอนบันทึกรูป · ตรวจโปรแกรมตู้/ขั้นตอนหน้าประตู'],
        // ---- Scenario 05 ③ (2026-09-29) — งานตรวจรายวัน lib/borrow.php ----
        'borrow_overdue' => ['ยืมอุปกรณ์เกินกำหนดคืน', '/^Borrow overdue:/i',
                          'ใบยืมเลยกำหนดวันคืนแล้วยังคืนไม่ครบ (ขึ้นซ้ำทุกวันจนกว่าจะคืนครบหรือสายสโตร์ตีเป็นชำรุด/สูญหาย) — ตามของจากผู้ยืมที่ขึ้นบ่อย'],
        // ---- Bypass ประตู (2026-09-29) — หน้า Bypass โหมดเลือกเลขเอกสาร (lib/bypass.php) ----
        'gate_bypass' => ['Bypass ประตู (ตู้/ประตูใช้ไม่ได้)', '/^Gate bypass (open|close)\b/i',
                          'ADM เปิด/ปิดรอบแทนการแตะบัตร/ตู้ปิดประตู — ใบในรอบถ่ายรูปยืนยันตามปกติ · ตรวจว่าตู้ของประตูนั้นมีปัญหาอะไรและแก้ให้ใช้ได้'],
        // ---- 2026-09-30 — ช่องโหว่ GP-05: หน้าถ่ายรูปยืนยันบันทึกหยิบจริง 0 ทุกรายการ (lib/gate_api.php) ----
        'zero_pick'   => ['ลดทุกรายการเป็น 0 (ยกเลิกใบทางอ้อม)', '/^Zero pick:/i',
                          'ใบที่แตะบัตรแล้วถูกบันทึกหยิบจริง 0 ทุกรายการ = ยกเลิกใบหลังเปิดประตูโดยอ้อม · ตั้งแต่ 2 ต.ค. ใบที่สแกนผิดใช้วิธีนี้ได้ (เหตุผล "สแกนผิด" — ไม่ต้องคืนด้วยใบ IN) · ตรวจว่าเหตุผลตรงกับความจริงกับผู้บันทึก/ผู้แตะบัตรและภาพกล้อง ถ้าสงสัยว่าเอาของไปจริงให้นับสต๊อก (SC) ที่ประตูนั้น'],
        'other'       => ['อื่น ๆ', null, 'ข้อความจากระบบที่ยังไม่ได้จัดประเภท — อ่านรายละเอียดทีละรายการ'],
    ];
}

/** จัดประเภท + ดึงรายละเอียด (รหัสบัตร · ชุดหยิบ · ผู้ถือบัตร · material_id) */
function _seClassify(string $msg): array {
    $cat = 'other';
    foreach (_seCategories() as $k => $c) {
        if ($c[1] !== null && preg_match($c[1], $msg)) { $cat = $k; break; }
    }
    $d = [];
    if (preg_match('/Unauthorized card\s+([0-9A-Za-z]+)/i', $msg, $m)) $d['card'] = strtoupper($m[1]);
    if (preg_match('/PickingID=([A-Za-z0-9_-]+)/i', $msg, $m))          $d['picking'] = $m[1];
    if (preg_match('/cardholder=([^|]+)/i', $msg, $m))                 $d['holder'] = trim($m[1]);
    if (preg_match('/material_id=(\d+)/i', $msg, $m))                  $d['materialId'] = (int)$m[1];
    if (preg_match('/\bborrower=([^|]+)/i', $msg, $m))                 $d['borrower'] = trim($m[1]);   // ยืมเกินกำหนด
    if (preg_match('/^Borrow overdue:\s*(\S+)/i', $msg, $m))           $d['doc'] = $m[1];
    if (preg_match('/^ALARM 4 kill refused: card\s+([0-9A-Za-z]+)/i', $msg, $m)) $d['card'] = strtoupper($m[1]);   // 2026-09-30
    if (preg_match('/^Zero pick:\s*(\S+)/i', $msg, $m))                $d['doc'] = $m[1];                        // 2026-09-30
    if (preg_match('/\|\s*by=([^|]+)/i', $msg, $m))                    $d['by'] = trim($m[1]);
    return [$cat, $d];
}

function _seCanView(PDO $pdo, ?array $user, string $siteCode): bool {
    if ($siteCode === '') return false;
    $r = _iiRole($pdo, $user);
    if ($r['level'] === 0 || $r['level'] >= 8) return true;
    return $r['canReq'] && strcasecmp(trim((string)($user['siteCode'] ?? '')), $siteCode) === 0;
}

// =========================================================================
// getSiteErrorLog(siteCode, days)
// =========================================================================
function rpc_getSiteErrorLog(PDO $pdo, ?array $user, array $args) {
    try {
        $site = _iiSite($pdo, $user, $args[0] ?? '', true);
        if ($site['id'] === null) return ['success' => false, 'message' => 'ไม่พบไซต์ ' . $site['code']];
        if (!_seCanView($pdo, $user, $site['code'])) return ['success' => false, 'message' => 'no_permission'];
        $days = (int)($args[1] ?? 90);
        if ($days < 0) $days = 0;

        $w = ['e.project_id = ?'];
        $ar = [$site['id']];
        if ($days > 0) { $w[] = 'e.created_at >= CURDATE() - INTERVAL ? DAY'; $ar[] = $days - 1; }
        $st = $pdo->prepare('SELECT e.id, e.gate_code, e.message, e.created_at FROM error_logs e
                              WHERE ' . implode(' AND ', $w) . ' ORDER BY e.created_at DESC, e.id DESC LIMIT 3000');
        $st->execute($ar);
        $raw = $st->fetchAll(PDO::FETCH_ASSOC);

        // material_id → รหัส/ชื่อ (ยอดติดลบ)
        $matIds = [];
        $rows = [];
        foreach ($raw as $r) {
            list($cat, $d) = _seClassify((string)$r['message']);
            if (isset($d['materialId'])) $matIds[$d['materialId']] = true;
            $rows[] = ['id' => (int)$r['id'], 'at' => substr((string)$r['created_at'], 0, 19), 'gate' => (string)($r['gate_code'] ?? ''),
                       'cat' => $cat, 'message' => (string)$r['message'], 'detail' => $d];
        }
        if ($matIds) {
            $ids = array_keys($matIds);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT id, mat_code, name FROM materials WHERE id IN ($ph)");
            $st->execute($ids);
            $mm = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) { $mm[(int)$m['id']] = $m; }
            foreach ($rows as &$r) {
                if (isset($r['detail']['materialId'], $mm[$r['detail']['materialId']])) {
                    $r['detail']['matCode'] = (string)$mm[$r['detail']['materialId']]['mat_code'];
                    $r['detail']['matName'] = (string)$mm[$r['detail']['materialId']]['name'];
                }
            }
            unset($r);
        }

        // สรุปตามประเภท + หัวข้อที่ขึ้นบ่อย (บัตร/ผู้ถือบัตร/วัสดุ) + รายวัน
        $cats = [];
        foreach (_seCategories() as $k => $c) {
            $cats[$k] = ['key' => $k, 'label' => $c[0], 'advice' => $c[2], 'count' => 0, 'firstAt' => '', 'lastAt' => '', 'top' => []];
        }
        $daily = [];
        $gates = [];
        $tops = [];
        foreach ($rows as $r) {
            $k = $r['cat'];
            $cats[$k]['count']++;
            if ($cats[$k]['lastAt'] === '' || $r['at'] > $cats[$k]['lastAt']) $cats[$k]['lastAt'] = $r['at'];
            if ($cats[$k]['firstAt'] === '' || $r['at'] < $cats[$k]['firstAt']) $cats[$k]['firstAt'] = $r['at'];
            $day = substr($r['at'], 0, 10);
            if (!isset($daily[$day])) $daily[$day] = [];
            $daily[$day][$k] = ($daily[$day][$k] ?? 0) + 1;
            if ($r['gate'] !== '') $gates[$r['gate']] = true;
            $subject = $r['detail']['card'] ?? ($r['detail']['holder'] ?? ($r['detail']['borrower'] ?? ($r['detail']['matCode'] ?? ($r['detail']['by'] ?? ''))));
            if ($subject !== '') { $tops[$k][$subject] = ($tops[$k][$subject] ?? 0) + 1; }
        }
        foreach ($tops as $k => $t) {
            arsort($t);
            $list = [];
            foreach (array_slice($t, 0, 5, true) as $v => $n) { $list[] = ['value' => (string)$v, 'count' => $n]; }
            $cats[$k]['top'] = $list;
            $cats[$k]['distinct'] = count($t);
        }
        ksort($daily);
        $dailyOut = [];
        foreach ($daily as $day => $counts) { $dailyOut[] = ['date' => $day, 'counts' => $counts]; }

        // เลข badge: 7 วันล่าสุด ไม่นับทดสอบ
        $st = $pdo->prepare("SELECT message FROM error_logs WHERE project_id = ? AND created_at >= CURDATE() - INTERVAL 6 DAY");
        $st->execute([$site['id']]);
        $last7 = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $msg) {
            list($c) = _seClassify((string)$msg);
            if ($c !== 'test') $last7++;
        }

        $sites = [];
        $role = _iiRole($pdo, $user);
        if ($role['level'] === 0 || $role['level'] >= 8) {
            foreach ($pdo->query("SELECT p.code, p.name, COUNT(e.id) AS n FROM projects p LEFT JOIN error_logs e ON e.project_id = p.id
                                   WHERE p.status = 'active' GROUP BY p.id, p.code, p.name ORDER BY n DESC, p.code") as $r) {
                $sites[] = ['code' => (string)$r['code'], 'name' => (string)$r['name'], 'count' => (int)$r['n']];
            }
        }
        ksort($gates);
        return [
            'success' => true, 'siteCode' => $site['code'], 'siteName' => $site['name'], 'sites' => $sites,
            'days' => $days, 'total' => count($rows), 'truncated' => count($raw) >= 3000, 'last7' => $last7,
            'categories' => array_values($cats), 'daily' => $dailyOut, 'gates' => array_keys($gates), 'rows' => $rows,
        ];
    } catch (Throwable $e) {
        error_log('getSiteErrorLog: ' . $e->getMessage());
        return ['success' => false, 'message' => 'โหลด Error log ไม่สำเร็จ'];
    }
}
