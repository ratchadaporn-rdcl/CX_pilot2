<?php
/**
 * CONNEXT — lib/gate_health.php : สัญญาณชีพตู้ + สถานะอุปกรณ์ (ผลพิจารณา 2 ต.ค. 2026)
 *
 *   GP-32 / GP-33 / AL-27  ตู้ส่ง heartbeat ทุก 1 นาที (api/gate.php action=heartbeat) · เงียบเกิน 5 นาที = ออฟไลน์
 *                          → error_logs "Gate offline" + แจ้ง R&D (ในแอป + Teams/อีเมล — lib/notify.php) + Dashboard
 *                          (ตรวจโดยงานตั้งเวลา lib/jobs.php) · กลับมาส่งสัญญาณ = "Gate back online" + แจ้ง
 *   GP-30 / GP-31          ตู้ตรวจกล้อง/ตัวตรวจจับ/หัวอ่านเป็นระยะแล้วรายงานใน heartbeat — เสียใหม่ = แจ้ง R&D ครั้งเดียวต่อเหตุ ·
 *                          หายแล้ว = แจ้งอีกครั้ง · ตู้ประเภทไม่มี CCTV ไม่ตรวจกล้อง (camera = off)
 *   GP-02                  ตู้แจ้ง "Program closed" (logError) — Dashboard แสดงเหตุที่ตู้ออฟไลน์
 * ตู้ที่ยังไม่เคยส่ง heartbeat (โปรแกรมรุ่นเก่า) ไม่ถูกนับว่าออฟไลน์ — เริ่มเฝ้าหลัง heartbeat แรก
 *
 * สคีมา: gate_status (หนึ่งแถวต่อประตู) — settings/.gatehealth-schema-v1-<db>
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/notify.php';

const GH_OFFLINE_MIN = 5;   // เงียบเกินกี่นาที = ออฟไลน์ (GP-32)

function ghEnsureSchema(PDO $pdo, string $dbName = ''): void {
    static $done = [];
    $key = $dbName !== '' ? $dbName : '_';
    if (isset($done[$key])) { return; }
    $done[$key] = true;
    $safe   = preg_replace('/[^A-Za-z0-9_]/', '_', $key);
    $marker = __DIR__ . '/../settings/.gatehealth-schema-v1-' . $safe;
    if (is_file($marker)) { return; }
    if ($pdo->inTransaction()) { return; }
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS gate_status (
                gate_id        INT          NOT NULL PRIMARY KEY,
                project_id     INT          NOT NULL,
                last_seen      DATETIME     NULL COMMENT 'heartbeat ล่าสุด',
                app_version    VARCHAR(40)  NULL,
                screen         VARCHAR(40)  NULL COMMENT 'หน้าจอตู้ตอนส่ง',
                picking_id     VARCHAR(30)  NULL COMMENT 'รอบที่เปิดอยู่',
                camera         VARCHAR(10)  NULL COMMENT 'ok / fail / off (ไม่มี CCTV)',
                detector       VARCHAR(10)  NULL,
                reader         VARCHAR(10)  NULL,
                lock_state     VARCHAR(20)  NULL,
                payload        TEXT         NULL,
                offline_since  DATETIME     NULL COMMENT 'งานตั้งเวลาตั้งเมื่อเงียบเกิน 5 นาที (แจ้งแล้ว)',
                device_alert   VARCHAR(255) NULL COMMENT 'อุปกรณ์ที่เสียอยู่ (แจ้งแล้ว)',
                updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_gs_project (project_id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        @file_put_contents($marker, date('c') . "\n");
    } catch (Throwable $e) {
        error_log('ghEnsureSchema: ' . $e->getMessage());
    }
}

/** ค่าสถานะอุปกรณ์ที่รับ: ok / fail / off */
function ghDev($v): ?string {
    if ($v === null || $v === '') { return null; }
    if (is_bool($v)) { return $v ? 'ok' : 'fail'; }
    $s = strtolower(trim((string)$v));
    if (in_array($s, ['ok', 'pass', 'true', '1', 'online'], true)) { return 'ok'; }
    if (in_array($s, ['off', 'na', 'n/a', 'none', 'disabled'], true)) { return 'off'; }
    return 'fail';
}

function ghErrorLog(PDO $pdo, string $siteCode, string $gateCode, string $message): void {
    try {
        $st = $pdo->prepare('SELECT id FROM projects WHERE code = ?');
        $st->execute([trim($siteCode)]);
        $pid = $st->fetchColumn();
        $pdo->prepare('INSERT INTO error_logs (project_id, gate_code, message) VALUES (?, ?, ?)')
            ->execute([$pid === false ? null : (int)$pid, $gateCode !== '' ? $gateCode : null, $message]);
    } catch (Throwable $e) {
        error_log('ghErrorLog: ' . $e->getMessage() . ' — ' . $message);
    }
}

/**
 * บันทึก heartbeat ของตู้ (ไซต์/G มาจาก key ต่อตู้ หรือพารามิเตอร์ของตู้ที่ยังใช้ key กลาง)
 * @return array ['ok' => bool, 'message' => string, 'gate' => ['gate_id','project_id','gate_code','site']]
 */
function ghHeartbeat(PDO $pdo, string $siteCode, string $gateCode, array $body): array {
    $st = $pdo->prepare("SELECT g.id, g.project_id, g.gate_code, p.code AS site FROM gates g JOIN projects p ON p.id = g.project_id
                          WHERE p.code = ? AND g.gate_code = ? AND g.status = 'active' LIMIT 1");
    $st->execute([trim($siteCode), strtoupper(trim($gateCode))]);
    $g = $st->fetch(PDO::FETCH_ASSOC);
    if (!$g) { return ['ok' => false, 'message' => 'ไม่พบประตู ' . $gateCode . ' ของไซต์ ' . $siteCode, 'gate' => null]; }

    $dev = is_array($body['devices'] ?? null) ? $body['devices'] : [];
    $row = [
        'app_version' => mb_substr(trim((string)($body['version'] ?? $body['appVersion'] ?? '')), 0, 40),
        'screen'      => mb_substr(trim((string)($body['screen'] ?? '')), 0, 40),
        'picking_id'  => mb_substr(trim((string)($body['pickingId'] ?? $body['PickingID'] ?? '')), 0, 30),
        'camera'      => ghDev($dev['camera'] ?? null),
        'detector'    => ghDev($dev['detector'] ?? null),
        'reader'      => ghDev($dev['reader'] ?? null),
        'lock_state'  => mb_substr(trim((string)($dev['lock'] ?? '')), 0, 20),
    ];
    $payload = json_encode(['devices' => $dev, 'alarms' => $body['alarms'] ?? [], 'uptime' => $body['uptime'] ?? null,
                            'ip' => $_SERVER['REMOTE_ADDR'] ?? ''], JSON_UNESCAPED_UNICODE);

    $old = $pdo->prepare('SELECT offline_since, device_alert, last_seen FROM gate_status WHERE gate_id = ?');
    $old->execute([(int)$g['id']]);
    $prev = $old->fetch(PDO::FETCH_ASSOC) ?: null;

    // อุปกรณ์ที่เสียตอนนี้ (กล้อง/ตัวตรวจจับ/หัวอ่าน)
    $names = ['camera' => 'กล้อง', 'detector' => 'ตัวตรวจจับ', 'reader' => 'หัวอ่าน'];
    $bad = [];
    foreach ($names as $k => $label) { if ($row[$k] === 'fail') { $bad[] = $k; } }
    $alertNow = $bad ? implode(',', $bad) : null;

    $pdo->prepare(
        'INSERT INTO gate_status (gate_id, project_id, last_seen, app_version, screen, picking_id, camera, detector, reader, lock_state, payload, offline_since, device_alert)
         VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)
         ON DUPLICATE KEY UPDATE project_id = VALUES(project_id), last_seen = NOW(), app_version = VALUES(app_version), screen = VALUES(screen),
                                 picking_id = VALUES(picking_id), camera = VALUES(camera), detector = VALUES(detector), reader = VALUES(reader),
                                 lock_state = VALUES(lock_state), payload = VALUES(payload), offline_since = NULL, device_alert = VALUES(device_alert)'
    )->execute([(int)$g['id'], (int)$g['project_id'], $row['app_version'], $row['screen'], $row['picking_id'], $row['camera'], $row['detector'],
                $row['reader'], $row['lock_state'], $payload, $alertNow]);

    $label = $g['gate_code'] . ' (' . $g['site'] . ')';
    if ($prev && $prev['offline_since'] !== null) {
        $msg = 'Gate back online: ' . $g['gate_code'] . ' — offline since ' . $prev['offline_since'];
        ghErrorLog($pdo, (string)$g['site'], (string)$g['gate_code'], $msg);
        cnxNotifyAdmins($pdo, 'gate_online', 'ตู้ ' . $label . ' กลับมาออนไลน์',
                        'เงียบตั้งแต่ ' . substr((string)$prev['offline_since'], 0, 16) . ' — ส่งสัญญาณชีพแล้ว ' . date('H:i'), '', (int)$g['project_id']);
    }
    $prevAlert = $prev ? (string)($prev['device_alert'] ?? '') : '';
    if ((string)$alertNow !== $prevAlert) {
        $newBad = array_diff($bad, $prevAlert !== '' ? explode(',', $prevAlert) : []);
        $fixed  = array_diff($prevAlert !== '' ? explode(',', $prevAlert) : [], $bad);
        if ($newBad) {
            $txt = implode(' · ', array_map(function ($k) use ($names) { return $names[$k]; }, $newBad));
            ghErrorLog($pdo, (string)$g['site'], (string)$g['gate_code'], 'Device check failed: ' . implode(',', $newBad) . ' — ' . $g['gate_code']);
            cnxNotifyAdmins($pdo, 'gate_device', 'ตู้ ' . $label . ': ' . $txt . ' ใช้ไม่ได้',
                            'ตู้รายงานตอน ' . date('H:i') . ' — ตรวจสาย/ไฟของอุปกรณ์ (หน้าจอตู้แสดงเหตุ)', '', (int)$g['project_id']);
        }
        if ($fixed) {
            $txt = implode(' · ', array_map(function ($k) use ($names) { return $names[$k]; }, $fixed));
            ghErrorLog($pdo, (string)$g['site'], (string)$g['gate_code'], 'Device check recovered: ' . implode(',', $fixed) . ' — ' . $g['gate_code']);
            cnxNotifyAdmins($pdo, 'gate_device', 'ตู้ ' . $label . ': ' . $txt . ' กลับมาใช้ได้', 'ตู้รายงานตอน ' . date('H:i'), '', (int)$g['project_id']);
        }
    }
    return ['ok' => true, 'message' => '', 'gate' => ['gate_id' => (int)$g['id'], 'project_id' => (int)$g['project_id'],
                                                       'gate_code' => (string)$g['gate_code'], 'site' => (string)$g['site']]];
}

/**
 * ตู้ที่เงียบเกิน GH_OFFLINE_MIN นาที (เคยส่ง heartbeat แล้ว · ประตูใช้งาน · มีตู้) → จด + แจ้งครั้งเดียวต่อเหตุ
 * @return int จำนวนตู้ที่เพิ่งออฟไลน์
 */
function ghCheckOffline(PDO $pdo): int {
    $st = $pdo->prepare(
        "SELECT s.gate_id, s.project_id, s.last_seen, g.gate_code, p.code AS site
           FROM gate_status s JOIN gates g ON g.id = s.gate_id JOIN projects p ON p.id = s.project_id
          WHERE s.offline_since IS NULL AND s.last_seen IS NOT NULL AND s.last_seen < NOW() - INTERVAL ? MINUTE
            AND g.status = 'active' AND g.hardware_close = 1"
    );
    $st->execute([GH_OFFLINE_MIN]);
    $n = 0;
    $mark = $pdo->prepare('UPDATE gate_status SET offline_since = last_seen WHERE gate_id = ? AND offline_since IS NULL');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $mark->execute([(int)$r['gate_id']]);
        if ($mark->rowCount() < 1) { continue; }   // งานอื่นแจ้งไปแล้ว
        $n++;
        $why = ghLastProgramClose($pdo, (string)$r['site'], (string)$r['gate_code'], (string)$r['last_seen']);
        ghErrorLog($pdo, (string)$r['site'], (string)$r['gate_code'],
                   'Gate offline: ' . $r['gate_code'] . ' — no heartbeat since ' . $r['last_seen'] . ($why !== '' ? ' (' . $why . ')' : ''));
        cnxNotifyAdmins($pdo, 'gate_offline', 'ตู้ ' . $r['gate_code'] . ' (' . $r['site'] . ') ออฟไลน์',
                        'ไม่มีสัญญาณชีพตั้งแต่ ' . substr((string)$r['last_seen'], 0, 16) . ' (เกิน ' . GH_OFFLINE_MIN . ' นาที)'
                        . ($why !== '' ? ' · ' . $why : ' · ตรวจไฟ/เน็ต/โปรแกรมตู้ — ประตูไม่มีไฟ = ไม่ล็อก (fail-safe)'), '', (int)$r['project_id']);
    }
    return $n;
}

/** เหตุที่ตู้เงียบ: ตู้แจ้ง "Program closed" ก่อนเงียบไหม (GP-02) */
function ghLastProgramClose(PDO $pdo, string $site, string $gate, string $since): string {
    try {
        $st = $pdo->prepare("SELECT e.message, e.created_at FROM error_logs e JOIN projects p ON p.id = e.project_id
                              WHERE p.code = ? AND e.gate_code = ? AND e.message LIKE 'Program closed%' AND e.created_at >= ? - INTERVAL 2 MINUTE
                              ORDER BY e.id DESC LIMIT 1");
        $st->execute([$site, $gate, $since]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) { return ''; }
        $who = preg_match('/cardholder=([^|]+)/', (string)$r['message'], $m) ? trim($m[1]) : '';
        return 'โปรแกรมตู้ถูกปิด ' . substr((string)$r['created_at'], 11, 5) . ($who !== '' ? ' โดย ' . $who : '');
    } catch (Throwable $e) {
        return '';
    }
}

/** RPC getGateHealth(siteCode) — Dashboard "สถานะตู้" (R0 / R8 ขึ้นไป / สายสโตร์) */
function rpc_getGateHealth(PDO $pdo, ?array $user, array $args) {
    try {
        if (!$user) { return ['success' => false, 'data' => []]; }
        $r = docExtUserRole($pdo, $user);
        if (!($r['level'] === 0 || $r['canReq'] || ($r['level'] >= 8 && $r['level'] < 99))) { return ['success' => true, 'data' => []]; }
        if (function_exists('cnxJobsMaybeRun')) { cnxJobsMaybeRun($pdo, 'light'); }
        $site = trim((string)($args[0] ?? ''));
        if ($r['level'] !== 0) { $site = (string)($user['siteCode'] ?? ''); }   // ระดับ 0 เลือกไซต์ได้ ('' = ทุกไซต์)
        $sql = "SELECT g.id, g.gate_code, g.name, g.has_cctv, p.code AS site, s.last_seen, s.app_version, s.screen, s.picking_id,
                       s.camera, s.detector, s.reader, s.offline_since, s.device_alert,
                       TIMESTAMPDIFF(SECOND, s.last_seen, NOW()) AS age
                  FROM gates g JOIN projects p ON p.id = g.project_id LEFT JOIN gate_status s ON s.gate_id = g.id
                 WHERE g.status = 'active' AND g.hardware_close = 1" . ($site !== '' ? ' AND p.code = ?' : '') . '
                 ORDER BY p.code, g.gate_code';
        $st = $pdo->prepare($sql);
        $st->execute($site !== '' ? [$site] : []);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $state = $x['last_seen'] === null ? 'never' : (((int)$x['age'] > GH_OFFLINE_MIN * 60 || $x['offline_since'] !== null) ? 'offline' : 'online');
            $out[] = [
                'site' => (string)$x['site'], 'gate' => (string)$x['gate_code'], 'name' => (string)$x['name'],
                'hasCctv' => isTrueFlag($x['has_cctv']), 'state' => $state, 'lastSeen' => $x['last_seen'] ? substr((string)$x['last_seen'], 0, 16) : '',
                'ageSec' => $x['age'] !== null ? (int)$x['age'] : null, 'version' => (string)($x['app_version'] ?? ''),
                'screen' => (string)($x['screen'] ?? ''), 'pickingId' => (string)($x['picking_id'] ?? ''),
                'camera' => (string)($x['camera'] ?? ''), 'detector' => (string)($x['detector'] ?? ''), 'reader' => (string)($x['reader'] ?? ''),
                'why' => $state === 'offline' ? ghLastProgramClose($pdo, (string)$x['site'], (string)$x['gate_code'], (string)$x['last_seen']) : '',
            ];
        }
        return ['success' => true, 'data' => $out, 'offlineMin' => GH_OFFLINE_MIN];
    } catch (Throwable $e) {
        error_log('getGateHealth: ' . $e->getMessage());
        return ['success' => false, 'data' => []];
    }
}
