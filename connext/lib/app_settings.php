<?php
/**
 * CONNEXT — lib/app_settings.php : ค่าตั้งระบบที่ ADM แก้ได้จากหน้าเว็บ (ตาราง app_settings) — 2026-10-02
 *
 * เดิมค่าตั้งทั้งหมดอยู่ใน settings/config.php (แก้ได้เฉพาะคนที่เข้าเครื่อง) · ค่าที่ต้องเปิด/ปิดระหว่างเปลี่ยนระบบ
 * (เช่น บังคับรหัสตรวจสอบ QR หลังอัปเดตโปรแกรมตู้ครบ) และค่าการแจ้งเตือน ย้ายมาไว้ที่นี่
 * ทุกการแก้จด activity_log (ใคร · ค่าเดิม · ค่าใหม่)
 *
 * PHP 7.4-compatible เท่านั้น
 */

/** ค่าตั้งต้นของทุกคีย์ที่ระบบรู้จัก (ค่าที่ยังไม่เคยบันทึก = ค่านี้) */
const APP_SETTING_DEFAULTS = [
    // GP-17 / GP-21: ตู้ต้องส่งรหัสตรวจสอบ QR ทุกใบ — เปิดหลังอัปเดตโปรแกรมตู้ทุกตู้แล้ว (ระหว่างนี้ QR ไม่มีรหัสยังรับ)
    'qr_require_code'     => '0',
    // GP-22 / GP-23: ยังรับ key กลาง (settings gate_api_key) — ปิดหลังทุกตู้มี key ของตัวเองแล้ว
    'gate_shared_key_ok'  => '1',
    // [2026-10-02 · health] งานตั้งเวลา (lib/jobs.php)
    'reconcile_every_min' => '5',    // GP-41: กระทบยอดอัตโนมัติทุก N นาที (เสนอ 5 — รอยืนยัน) · 0 = ปิด
    'sc_weekly_random'    => '1',    // GP-03: สุ่มวันนับสต๊อกรายสัปดาห์ต่อ G แล้วแจ้งสายสโตร์ (รอยืนยัน)
    'internal_base_url'   => '',     // ว่าง = http://127.0.0.1 + APP_BASE (งานตั้งเวลาเรียก api/gate.php ของเครื่องเดียวกัน)
    // [2026-10-02 · health] แจ้งเตือน R&D นอกแอป (lib/notify.php) — ว่าง = ไม่ส่ง
    'notify_teams_url'    => '',     // URL webhook ของ Teams (Workflows "เมื่อได้รับ webhook" หรือ Incoming Webhook)
    'notify_email_to'     => '',     // อีเมลผู้รับ คั่นด้วย ,
    'smtp_host'           => '',
    'smtp_port'           => '587',
    'smtp_secure'         => 'tls',  // tls (STARTTLS) · ssl · none
    'smtp_user'           => '',
    'smtp_pass'           => '',
    'smtp_from'           => '',
];

function appSettingsEnsureSchema(PDO $pdo, string $dbName = ''): void {
    static $done = [];
    $key = $dbName !== '' ? $dbName : '_';
    if (isset($done[$key])) { return; }
    $done[$key] = true;
    $safe   = preg_replace('/[^A-Za-z0-9_]/', '_', $key);
    $marker = __DIR__ . '/../settings/.appsettings-schema-v1-' . $safe;
    if (is_file($marker)) { return; }
    if ($pdo->inTransaction()) { return; }
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS app_settings (
                skey        VARCHAR(64)  NOT NULL PRIMARY KEY,
                svalue      TEXT         NULL,
                updated_by  VARCHAR(100) NULL,
                updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        @file_put_contents($marker, date('c') . "\n");
    } catch (Throwable $e) {
        error_log('appSettingsEnsureSchema: ' . $e->getMessage());
    }
}

/** ค่าทั้งหมด (cache ต่อคำขอ) */
function appSettingsAll(PDO $pdo, bool $reload = false): array {
    static $cache = null;
    if ($cache !== null && !$reload) { return $cache; }
    $cache = [];
    try {
        foreach ($pdo->query('SELECT skey, svalue FROM app_settings')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cache[(string)$r['skey']] = $r['svalue'] === null ? '' : (string)$r['svalue'];
        }
    } catch (Throwable $e) {
        // ตารางยังไม่มี (ก่อน ensure) = ใช้ค่าตั้งต้น
    }
    return $cache;
}

function appSetting(PDO $pdo, string $key, ?string $default = null): string {
    $all = appSettingsAll($pdo);
    if (array_key_exists($key, $all)) { return $all[$key]; }
    if ($default !== null) { return $default; }
    return (string)(APP_SETTING_DEFAULTS[$key] ?? '');
}

function appSettingOn(PDO $pdo, string $key): bool {
    return isTrueFlag(appSetting($pdo, $key));
}

/** บันทึกค่า + activity_log · คืน [old, new] · $secret = ไม่จดค่าจริงลง log (รหัสผ่าน SMTP) */
function appSettingSet(PDO $pdo, string $key, string $value, string $by, bool $secret = false): array {
    $old = appSetting($pdo, $key);
    $st = $pdo->prepare('INSERT INTO app_settings (skey, svalue, updated_by) VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), updated_by = VALUES(updated_by)');
    $st->execute([$key, $value, $by !== '' ? $by : null]);
    appSettingsAll($pdo, true);
    try {
        $pdo->prepare('INSERT INTO activity_log (entity_type, entity_id, user_name, action, old_value, new_value) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['app_setting', $key, $by !== '' ? $by : null, 'setting_change',
                       $secret ? ($old !== '' ? '(ตั้งไว้)' : '(ว่าง)') : $old, $secret ? ($value !== '' ? '(เปลี่ยนแล้ว)' : '(ล้าง)') : $value]);
    } catch (Throwable $e) {
        error_log('appSettingSet log: ' . $e->getMessage());
    }
    return [$old, $value];
}
