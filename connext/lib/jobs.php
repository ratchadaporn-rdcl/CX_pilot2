<?php
/**
 * CONNEXT — lib/jobs.php : งานตั้งเวลา (ผลพิจารณา 2 ต.ค. 2026)
 *
 *   ตู้ออฟไลน์ (GP-32/33 · AL-27)  ทุกครั้งที่งานรัน — ghCheckOffline (lib/gate_health.php)
 *   กระทบยอด reconcile (GP-41)     ทุก N นาที (app_settings reconcile_every_min · เสนอ 5 — รอยืนยัน · 0 = ปิด)
 *                                  เรียก api/gate.php?action=reconcile ผ่าน loopback + jobtoken (ไม่ใช้ key ตู้)
 *   ใบยืมเกินกำหนดรายวัน (OP-30)   borrowDailyTick — ตัวตั้งเวลาเรียกทุกนาที → รันครั้งแรกหลังเที่ยงคืน
 *                                  (เดิมรันกับคำขอแรกของวันเท่านั้น — ยังคงไว้เป็นทางสำรองใน config.php)
 *   สุ่มนับสต๊อกรายสัปดาห์ต่อ G (GP-03 · รอยืนยัน)  app_settings sc_weekly_random (ค่าตั้งต้น เปิด):
 *                                  ต้นสัปดาห์สุ่มวัน (จันทร์–เสาร์) ให้แต่ละ G ที่มีตู้ → วันนั้น 07:00–17:00 แจ้งสายสโตร์ของไซต์ให้สร้างใบนับ SC
 *
 * ตัวเรียก: cron/jobs.php (Windows Task Scheduler ทุก 1 นาที — แนะนำ) · heartbeat ของตู้ (หลังตอบตู้แล้ว) ·
 *           Dashboard getGateHealth (เฉพาะงานเบา = ตู้ออฟไลน์)
 * กันรันซ้อน/ถี่: flock settings/.jobs-lock-<db> · ห่างกันอย่างน้อย 55 วินาทีต่อโหมด (settings/.jobs-last-<db>-<mode>)
 *
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/app_settings.php';
require_once __DIR__ . '/qr_sign.php';      // qrSecret() — ใช้เป็นกุญแจของ jobtoken
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/gate_health.php';

/** token ของงานตั้งเวลา (เปลี่ยนทุกชั่วโมง) — api/gate.php รับเฉพาะ GET action=reconcile */
function cnxJobToken(int $hoursAgo = 0): string {
    return hash_hmac('sha256', 'jobs|' . date('YmdH', time() - $hoursAgo * 3600), qrSecret());
}
function cnxJobTokenValid(string $t): bool {
    $t = trim($t);
    if ($t === '') { return false; }
    return hash_equals(cnxJobToken(0), $t) || hash_equals(cnxJobToken(1), $t);
}

/** ตอบ JSON ให้ผู้เรียกแล้วปิดการเชื่อมต่อ — โค้ดหลังจากนี้ทำงานต่อโดยผู้เรียกไม่ต้องรอ (heartbeat ของตู้) */
function cnxRespondThenContinue(array $data): void {
    ignore_user_abort(true);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if (php_sapi_name() === 'cli') { echo $json; return; }
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Length: ' . strlen($json));
    header('Connection: close');
    echo $json;
    @flush();
    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
}

function cnxJobsDir(): string { return __DIR__ . '/../settings/'; }

/**
 * รันงานตั้งเวลาถ้าถึงรอบ — $mode 'full' (ทุกงาน) | 'light' (ตู้ออฟไลน์อย่างเดียว)
 * @return array ผลแต่ละงาน หรือ ['skipped' => เหตุ]
 */
function cnxJobsMaybeRun(PDO $pdo, string $mode = 'full', bool $force = false): array {
    $mode = $mode === 'light' ? 'light' : 'full';
    try { $db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn(); } catch (Throwable $e) { $db = '_'; }
    $safe = preg_replace('/[^A-Za-z0-9_]/', '_', $db !== '' ? $db : '_');
    $last = cnxJobsDir() . '.jobs-last-' . $safe . '-' . $mode;
    if (!$force && is_file($last) && (time() - (int)@filemtime($last)) < 55) { return ['skipped' => 'throttle']; }
    $fh = @fopen(cnxJobsDir() . '.jobs-lock-' . $safe, 'c');
    if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) { if ($fh) { fclose($fh); } return ['skipped' => 'locked']; }
    @touch($last);
    $out = ['mode' => $mode, 'at' => date('Y-m-d H:i:s')];
    try {
        $out['offline'] = ghCheckOffline($pdo);
        if ($mode === 'full') {
            if (function_exists('borrowDailyTick')) {
                try { borrowDailyTick($pdo, $db); $out['borrowDaily'] = 'checked'; } catch (Throwable $e) { $out['borrowDaily'] = 'error: ' . $e->getMessage(); }
            }
            $out['reconcile'] = cnxJobReconcileIfDue($pdo, $safe, $force);
            $out['scWeekly']  = appSettingOn($pdo, 'sc_weekly_random') ? cnxJobScWeekly($pdo) : 'off';
        }
    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();
        error_log('cnxJobsMaybeRun: ' . $e->getMessage());
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
    return $out;
}

/** URL ของ api/gate.php สำหรับเรียกจากเครื่องเดียวกัน (app_settings internal_base_url — ค่าตั้งต้น http://127.0.0.1 + APP_BASE) */
function cnxInternalBaseUrl(PDO $pdo): string {
    $u = trim(appSetting($pdo, 'internal_base_url'));
    if ($u === '') { $u = 'http://127.0.0.1' . (defined('APP_BASE') ? APP_BASE : '/connext'); }
    return rtrim($u, '/');
}

/** reconcile ทุก N นาที (0 = ปิด) */
function cnxJobReconcileIfDue(PDO $pdo, string $safeDb, bool $force = false) {
    $every = (int)appSetting($pdo, 'reconcile_every_min');
    if ($every <= 0) { return 'off'; }
    $mark = cnxJobsDir() . '.jobs-reconcile-' . $safeDb;
    if (!$force && is_file($mark) && (time() - (int)@filemtime($mark)) < $every * 60 - 5) { return 'not due'; }
    @touch($mark);
    $url = cnxInternalBaseUrl($pdo) . '/api/gate.php?action=reconcile&jobtoken=' . rawurlencode(cnxJobToken());
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 5]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false || $code !== 200) {
        error_log('job reconcile failed: HTTP ' . $code . ' ' . $err);
        return 'failed (HTTP ' . $code . ($err !== '' ? ' ' . $err : '') . ')';
    }
    $j = json_decode((string)$res, true);
    return is_array($j) ? ['finalized' => $j['finalized'] ?? null, 'flagged' => $j['flagged'] ?? null, 'message' => $j['message'] ?? ''] : 'bad response';
}

/**
 * GP-03 (รอยืนยัน): สุ่มวันนับสต๊อกต่อ G ทุกสัปดาห์ แล้วแจ้งสายสโตร์ของไซต์ในวันนั้น (จันทร์–เสาร์ 07:00–17:00)
 * แผนเก็บที่ app_settings sc_weekly_plan {"week":"2026-W40","gates":{"<gate_id>":{"day":3,"done":false}}}
 * @return int จำนวนประตูที่แจ้งในรอบนี้
 */
function cnxJobScWeekly(PDO $pdo, ?DateTime $now = null): int {
    $now  = $now ?: new DateTime('now');
    $dow  = (int)$now->format('N');
    $hour = (int)$now->format('G');
    if ($dow === 7 || $hour < 7 || $hour >= 17) { return 0; }
    $week = $now->format('o-\WW');
    $plan = json_decode(appSetting($pdo, 'sc_weekly_plan', '{}'), true);
    if (!is_array($plan)) { $plan = []; }
    if (($plan['week'] ?? '') !== $week) {
        $plan = ['week' => $week, 'gates' => []];
        $gs = $pdo->query("SELECT g.id FROM gates g WHERE g.status = 'active' AND g.hardware_close = 1
                             AND EXISTS (SELECT 1 FROM stock_gate_balances b WHERE b.gate_id = g.id AND b.on_hand <> 0)");
        foreach ($gs->fetchAll(PDO::FETCH_COLUMN) as $gid) {
            $plan['gates'][(string)$gid] = ['day' => random_int($dow, 6), 'done' => false];
        }
    }
    $n = 0;
    $gq = $pdo->prepare('SELECT g.gate_code, g.project_id, p.code AS site FROM gates g JOIN projects p ON p.id = g.project_id WHERE g.id = ?');
    $uq = $pdo->prepare("SELECT u.username FROM users u JOIN roles r ON r.id = u.role_id
                          WHERE u.project_id = ? AND u.status <> 'inactive' AND r.can_req = 1 AND r.level >= 1 AND r.level < 99");
    $ins = $pdo->prepare('INSERT INTO user_notices (project_id, username, kind, title, body, ref_doc, created_by) VALUES (?, ?, ?, ?, ?, NULL, ?)');
    foreach ($plan['gates'] as $gid => &$p) {
        if (!empty($p['done']) || (int)$p['day'] > $dow) { continue; }
        $gq->execute([(int)$gid]);
        $g = $gq->fetch(PDO::FETCH_ASSOC);
        $p['done'] = true;
        if (!$g) { continue; }
        $uq->execute([(int)$g['project_id']]);
        foreach ($uq->fetchAll(PDO::FETCH_COLUMN) as $u) {
            $ins->execute([(int)$g['project_id'], (string)$u, 'sc_weekly', 'สุ่มนับสต๊อกประจำสัปดาห์ — ประตู ' . $g['gate_code'],
                           'วันนี้ระบบสุ่มให้นับสต๊อกประตู ' . $g['gate_code'] . ' (' . $g['site'] . ') — สร้างใบนับ (SC) ที่หน้า "ตรวจสอบประจำวัน" แล้วนับทุกรายการ (GP-03)',
                           'ระบบ']);
        }
        $n++;
    }
    unset($p);
    $st = $pdo->prepare('INSERT INTO app_settings (skey, svalue, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), updated_by = VALUES(updated_by)');
    $st->execute(['sc_weekly_plan', json_encode($plan), 'jobs']);
    appSettingsAll($pdo, true);
    return $n;
}
