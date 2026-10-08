<?php
/**
 * CONNEXT — lib/notify.php : แจ้งเตือนผู้ดูแลระบบ (2026-10-02)
 *
 * ใช้กับเหตุที่ต้องมีคนรู้ทันที เช่น ADM แก้ค่าตั้งประตู/หมวดวัสดุ (GP-46) · สร้าง/ยกเลิก key ตู้ (GP-22) ·
 * ตู้เงียบ (AL-27 — ต่อในแพตช์ health)
 *   - ในแอป: แถวใน user_notices ของผู้ใช้ระดับ 0 ที่ยังใช้งาน (ADM / R&D) — ขึ้นแถบแจ้งเตือนเดียวกับแจ้งเตือนใบยืม
 *   - ช่องทางนอกแอป (Teams / อีเมล) ต่อในแพตช์ health (cnxNotifyExternal) — ไม่มีการตั้งค่า = ข้าม
 * ล้มเหลวห้ามทำให้งานหลักล้ม (กลืน exception + error_log)
 *
 * [2026-10-02 · health] ช่องทางนอกแอป: Teams (webhook) + อีเมล (SMTP) — ตั้งค่าที่ admin.php (app_settings notify_* / smtp_*)
 *   ส่งทุกแจ้งเตือนผู้ดูแลระบบ (ตู้ออฟไลน์/กลับมา · อุปกรณ์เสีย · แก้ค่าตั้งประตู/หมวดวัสดุ · key ตู้) · ส่งไม่สำเร็จ = error_log ไม่ล้มงานหลัก
 *
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/app_settings.php';

/** [2026-10-05] ตอนนี้แจ้งเตือนในแอปอย่างเดียว — เปลี่ยนเป็น true เมื่อพร้อมใช้ Teams / อีเมล (ค่าที่ตั้งไว้ยังอยู่ครบ) */
if (!defined('CNX_NOTIFY_EXTERNAL')) { define('CNX_NOTIFY_EXTERNAL', false); }

/** ชื่อผู้รับในแอป: ผู้ใช้ระดับ 0 ที่ยังใช้งาน */
function cnxNotifyAdminUsers(PDO $pdo): array {
    try {
        $st = $pdo->query("SELECT u.username FROM users u JOIN roles r ON r.id = u.role_id
                            WHERE r.level = 0 AND u.status <> 'inactive' AND u.username <> '' ORDER BY u.username");
        return array_values(array_unique(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN))));
    } catch (Throwable $e) {
        error_log('cnxNotifyAdminUsers: ' . $e->getMessage());
        return [];
    }
}

/**
 * แจ้งผู้ดูแลระบบ — คืนจำนวนผู้รับในแอป
 * @param string $kind  ชนิด (เช่น gate_setting · material_category · gate_key · gate_offline)
 */
function cnxNotifyAdmins(PDO $pdo, string $kind, string $title, string $body, string $by = '', ?int $projectId = null, string $refDoc = ''): int {
    $n = 0;
    try {
        $ins = $pdo->prepare('INSERT INTO user_notices (project_id, username, kind, title, body, ref_doc, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach (cnxNotifyAdminUsers($pdo) as $u) {
            $ins->execute([$projectId, $u, mb_substr($kind, 0, 30), mb_substr($title, 0, 200, 'UTF-8'), $body,
                           $refDoc !== '' ? mb_substr($refDoc, 0, 35) : null, $by !== '' ? $by : null]);
            $n++;
        }
    } catch (Throwable $e) {
        error_log('cnxNotifyAdmins: ' . $e->getMessage());
    }
    if (CNX_NOTIFY_EXTERNAL && function_exists('cnxNotifyExternal')) {
        try { cnxNotifyExternal($pdo, $kind, $title, $body, $by); } catch (Throwable $e) { error_log('cnxNotifyExternal: ' . $e->getMessage()); }
    }
    return $n;
}

// =========================================================================
// ช่องทางนอกแอป (2026-10-02 · GP-30/31/32/33 · AL-27: "อีเมลหรือ Teams ของบริษัท")
// =========================================================================

/** ส่งแจ้งเตือนผู้ดูแลระบบออกนอกแอป — คืน ['teams' => bool|null, 'email' => bool|null] (null = ไม่ได้ตั้งค่า) */
function cnxNotifyExternal(PDO $pdo, string $kind, string $title, string $body, string $by = ''): array {
    $out = ['teams' => null, 'email' => null];
    $line = date('d/m/Y H:i') . ($by !== '' ? ' · โดย ' . $by : '');
    $teams = trim(appSetting($pdo, 'notify_teams_url'));
    if ($teams !== '') { $out['teams'] = cnxSendTeams($teams, $title, $body, $line); }
    $to = trim(appSetting($pdo, 'notify_email_to'));
    if ($to !== '' && trim(appSetting($pdo, 'smtp_host')) !== '') {
        $out['email'] = cnxSendMail($pdo, $to, '[CONNEXT] ' . $title, $title . "\n\n" . $body . "\n\n" . $line . "\n(" . $kind . ')');
    }
    return $out;
}

/** Teams: Adaptive Card ผ่าน webhook (ใช้ได้ทั้ง Workflows และ Incoming Webhook แบบเดิม) */
function cnxSendTeams(string $url, string $title, string $body, string $footer = ''): bool {
    if (!preg_match('#^https://#i', $url) && !preg_match('#^http://(127\.0\.0\.1|localhost)[:/]#i', $url)) {
        error_log('cnxSendTeams: URL ต้องเป็น https');
        return false;
    }
    $card = ['type' => 'AdaptiveCard', '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json', 'version' => '1.4',
             'body' => [['type' => 'TextBlock', 'text' => 'CONNEXT · ' . $title, 'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true],
                        ['type' => 'TextBlock', 'text' => $body, 'wrap' => true],
                        ['type' => 'TextBlock', 'text' => $footer, 'isSubtle' => true, 'spacing' => 'Small', 'wrap' => true]]];
    $payload = json_encode(['type' => 'message', 'attachments' => [['contentType' => 'application/vnd.microsoft.card.adaptive',
                                                                     'contentUrl' => null, 'content' => $card]]], JSON_UNESCAPED_UNICODE);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5]);
    $res  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($res === false || $code < 200 || $code >= 300) {
        error_log('cnxSendTeams failed: HTTP ' . $code . ' ' . $err . ' ' . substr((string)$res, 0, 200));
        return false;
    }
    return true;
}

/** อ่านคำตอบ SMTP (หลายบรรทัด) → [code, text] */
function _cnxSmtpRead($fp): array {
    $text = '';
    while (($line = fgets($fp, 1024)) !== false) {
        $text .= $line;
        if (strlen($line) < 4 || $line[3] === ' ') { break; }
    }
    return [(int)substr($text, 0, 3), $text];
}
function _cnxSmtpCmd($fp, string $cmd, array $okCodes, string $label = ''): array {
    fwrite($fp, $cmd . "\r\n");
    $r = _cnxSmtpRead($fp);
    if (!in_array($r[0], $okCodes, true)) {
        throw new RuntimeException('SMTP ' . ($label !== '' ? $label : $cmd) . ' → ' . trim($r[1]));
    }
    return $r;
}

/** อีเมลข้อความล้วน UTF-8 ผ่าน SMTP (STARTTLS / SSL / ไม่เข้ารหัส + AUTH LOGIN) */
function cnxSendMail(PDO $pdo, string $to, string $subject, string $text): bool {
    $host   = trim(appSetting($pdo, 'smtp_host'));
    $port   = (int)appSetting($pdo, 'smtp_port') ?: 587;
    $secure = strtolower(trim(appSetting($pdo, 'smtp_secure')));
    $user   = trim(appSetting($pdo, 'smtp_user'));
    $pass   = appSetting($pdo, 'smtp_pass');
    $from   = trim(appSetting($pdo, 'smtp_from')) ?: $user;
    $rcpts  = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $to) ?: []), function ($a) {
        return (bool)filter_var($a, FILTER_VALIDATE_EMAIL);
    }));
    if ($host === '' || !$rcpts || !filter_var($from, FILTER_VALIDATE_EMAIL)) { return false; }
    $fp = null;
    try {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) { throw new RuntimeException('connect ' . $host . ':' . $port . ' — ' . $errstr); }
        stream_set_timeout($fp, 15);
        $g = _cnxSmtpRead($fp);
        if ($g[0] !== 220) { throw new RuntimeException('greeting ' . trim($g[1])); }
        $me = 'connext.local';
        _cnxSmtpCmd($fp, 'EHLO ' . $me, [250]);
        if ($secure === 'tls') {
            _cnxSmtpCmd($fp, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { throw new RuntimeException('STARTTLS handshake failed'); }
            _cnxSmtpCmd($fp, 'EHLO ' . $me, [250]);
        }
        if ($user !== '') {
            _cnxSmtpCmd($fp, 'AUTH LOGIN', [334]);
            _cnxSmtpCmd($fp, base64_encode($user), [334], 'AUTH user');
            _cnxSmtpCmd($fp, base64_encode($pass), [235], 'AUTH password');
        }
        _cnxSmtpCmd($fp, 'MAIL FROM:<' . $from . '>', [250]);
        foreach ($rcpts as $r) { _cnxSmtpCmd($fp, 'RCPT TO:<' . $r . '>', [250, 251]); }
        _cnxSmtpCmd($fp, 'DATA', [354]);
        $headers = 'From: CONNEXT <' . $from . ">\r\n" . 'To: ' . implode(', ', $rcpts) . "\r\n"
                 . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n" . 'Date: ' . date('r') . "\r\n"
                 . 'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . $me . ">\r\n"
                 . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";
        fwrite($fp, $headers . "\r\n" . chunk_split(base64_encode($text)) . ".\r\n");
        $r = _cnxSmtpRead($fp);
        if ($r[0] !== 250) { throw new RuntimeException('DATA → ' . trim($r[1])); }
        @fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return true;
    } catch (Throwable $e) {
        error_log('cnxSendMail: ' . $e->getMessage());
        if ($fp) { @fclose($fp); }
        return false;
    }
}
