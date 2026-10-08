<?php
/**
 * CONNEXT — lib/gate_sec.php : ความปลอดภัยของตู้ประตู (ผลพิจารณา 2 ต.ค. 2026)
 *
 *   GP-46 / OP-77  ประตู 2 ประเภท: มี CCTV / ไม่มี CCTV (gates.has_cctv) — แก้ได้เฉพาะ ADM (หน้า admin.php) · จด + แจ้งเตือนทุกครั้ง
 *                  ตู้ประเภทไม่มี CCTV: ไม่มี ALARM 4 + ข้ามตรวจกล้องตอนเปิดเครื่อง (ตู้อ่านค่าจาก getGateSettings · รอยืนยัน)
 *   GP-22 / GP-23  key ต่อตู้ (gates.api_key_hash) — เว็บรู้ไซต์และ G จาก key ไม่เชื่อรหัสที่ตู้ส่งมา
 *                  ระหว่างเปลี่ยน: key กลางเดิม (settings gate_api_key) ยังใช้ได้ (app_settings gate_shared_key_ok) แต่คำสั่งต้องมีรหัส G
 *   GP-17 / GP-21  ใบต้องเป็นของไซต์ตู้ + รหัสตรวจสอบ QR (lib/qr_sign.php) — ไม่ผ่าน = ไม่รับใบนั้น + error_logs "QR rejected"
 *
 * สคีมา: gates.has_cctv · api_key_hash · api_key_hint · api_key_at · api_key_by (settings/.gatesec-schema-v1-<db>)
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/app_settings.php';
require_once __DIR__ . '/qr_sign.php';

function gateSecEnsureSchema(PDO $pdo, string $dbName = ''): void {
    static $done = [];
    $key = $dbName !== '' ? $dbName : '_';
    if (isset($done[$key])) { return; }
    $done[$key] = true;
    $safe   = preg_replace('/[^A-Za-z0-9_]/', '_', $key);
    $marker = __DIR__ . '/../settings/.gatesec-schema-v1-' . $safe;
    if (is_file($marker)) { return; }
    if ($pdo->inTransaction()) { return; }
    try {
        $cols = [];
        foreach ($pdo->query('SHOW COLUMNS FROM gates')->fetchAll() as $c) { $cols[(string)$c['Field']] = true; }
        $add = [];
        if (!isset($cols['has_cctv'])) {
            $add[] = "ADD COLUMN has_cctv TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'ประเภทประตู: 1 มี CCTV · 0 ไม่มี CCTV (ไม่มี ALARM 4 · ข้ามตรวจกล้อง) — GP-46'";
        }
        if (!isset($cols['api_key_hash'])) {
            $add[] = "ADD COLUMN api_key_hash CHAR(64) NULL DEFAULT NULL COMMENT 'SHA-256 ของ key ตู้ (GP-22) — เว็บรู้ไซต์/G จาก key'";
            $add[] = "ADD COLUMN api_key_hint VARCHAR(8) NULL DEFAULT NULL COMMENT '4 ตัวท้ายของ key (แสดงในหน้าตั้งค่า)'";
            $add[] = "ADD COLUMN api_key_at DATETIME NULL DEFAULT NULL";
            $add[] = "ADD COLUMN api_key_by VARCHAR(100) NULL DEFAULT NULL";
            $add[] = "ADD UNIQUE KEY uq_gates_api_key (api_key_hash)";
        }
        if ($add) { $pdo->exec('ALTER TABLE gates ' . implode(', ', $add)); }
        @file_put_contents($marker, date('c') . "\n");
    } catch (Throwable $e) {
        error_log('gateSecEnsureSchema: ' . $e->getMessage());
    }
}

// =========================================================================
// key ต่อตู้ (GP-22 / GP-23)
// =========================================================================

/** key ที่ตู้ส่งมา → ตู้ที่ผูกไว้ ['gate_id','gate_code','project_id','site','has_cctv'] หรือ null */
function gateSecKeyLookup(PDO $pdo, string $key): ?array {
    $key = trim($key);
    if (strlen($key) < 24) { return null; }
    try {
        $st = $pdo->prepare("SELECT g.id AS gate_id, g.gate_code, g.project_id, p.code AS site, g.has_cctv
                               FROM gates g JOIN projects p ON p.id = g.project_id
                              WHERE g.api_key_hash = ? AND g.status = 'active' LIMIT 1");
        $st->execute([hash('sha256', $key)]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable $e) {
        return null;   // ยังไม่มีคอลัมน์ (ก่อน ensure) = ไม่มี key ต่อตู้
    }
}

/** ผูกคำขอกับตู้ของ key: รหัสไซต์/G ทุกชื่อพารามิเตอร์ที่ api/gate.php อ่าน = ค่าของ key (ไม่เชื่อค่าที่ส่งมา) */
function gateSecBindRequest(array $bound, array &$body): void {
    $site = (string)$bound['site'];
    $gate = (string)$bound['gate_code'];
    foreach (['SiteCode', 'siteCode', 'site'] as $k) { $body[$k] = $site; $_GET[$k] = $site; }
    foreach (['GateID', 'gateId', 'gateID'] as $k) { $body[$k] = $gate; $_GET[$k] = $gate; }
}

/** key กลางยังใช้ได้ไหม (ปิดหลังทุกตู้มี key ของตัวเอง) */
function gateSecSharedKeyAllowed(PDO $pdo): bool {
    return appSettingOn($pdo, 'gate_shared_key_ok');
}

/** สร้าง key ใหม่ให้ตู้ — คืน key (แสดงครั้งเดียว) · เก็บแค่ SHA-256 */
function gateSecNewKey(PDO $pdo, int $gateId, string $by): string {
    $key = bin2hex(random_bytes(20));   // 40 ตัวฐาน 16
    $st = $pdo->prepare('UPDATE gates SET api_key_hash = ?, api_key_hint = ?, api_key_at = NOW(), api_key_by = ? WHERE id = ?');
    $st->execute([hash('sha256', $key), substr($key, -4), $by !== '' ? $by : null, $gateId]);
    return $key;
}

function gateSecRevokeKey(PDO $pdo, int $gateId): void {
    $pdo->prepare('UPDATE gates SET api_key_hash = NULL, api_key_hint = NULL, api_key_at = NULL, api_key_by = NULL WHERE id = ?')
        ->execute([$gateId]);
}

/** ประเภทตู้สำหรับคำตอบ getGateSettings / getCardList */
function gateSecGateInfo(PDO $pdo, string $siteCode, string $gateCode): ?array {
    $siteCode = trim($siteCode);
    $gateCode = strtoupper(trim($gateCode));
    if ($siteCode === '' || $gateCode === '') { return null; }
    try {
        $st = $pdo->prepare("SELECT g.gate_code, g.has_cctv, g.hardware_close, g.api_key_hash IS NOT NULL AS has_key
                               FROM gates g JOIN projects p ON p.id = g.project_id
                              WHERE p.code = ? AND g.gate_code = ? AND g.status = 'active' LIMIT 1");
        $st->execute([$siteCode, $gateCode]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }
    if (!$r) { return null; }
    return ['code' => (string)$r['gate_code'], 'hasCctv' => isTrueFlag($r['has_cctv']),
            'hardwareClose' => isTrueFlag($r['hardware_close']), 'ownKey' => isTrueFlag($r['has_key'])];
}

// =========================================================================
// ใบที่ตู้ส่งมา: ไซต์ของใบ + รหัสตรวจสอบ QR (GP-17 / GP-21)
// =========================================================================

/** Codes จากตู้รุ่นใหม่: {"<เลขที่สแกน>": "<Chk>"} (หรือ [{doc, chk}]) → [UPPER(doc) => UPPER(chk)] */
function gateSecCodes(array $body): array {
    $raw = $body['Codes'] ?? $body['codes'] ?? [];
    if (is_string($raw)) { $j = json_decode($raw, true); $raw = is_array($j) ? $j : []; }
    $out = [];
    if (!is_array($raw)) { return $out; }
    foreach ($raw as $k => $v) {
        if (is_array($v)) {
            $d = strtoupper(trim((string)($v['doc'] ?? $v['Doc'] ?? '')));
            $c = strtoupper(trim((string)($v['chk'] ?? $v['Chk'] ?? '')));
        } else {
            $d = strtoupper(trim((string)$k));
            $c = strtoupper(trim((string)$v));
        }
        if ($d !== '' && $c !== '') { $out[$d] = $c; }
    }
    return $out;
}

/**
 * กรองใบก่อนรับเข้ารอบ — ใบที่ไม่ผ่านไม่ถูกแตะ (ไม่เปิดรอบให้ใบนั้น)
 *   ไซต์ของใบ ≠ ไซต์ตู้ → ไม่รับ (เดิมเทียบแค่รหัส G — ใบไซต์อื่นที่ G ซ้ำกันเปิดตู้ได้)
 *   ส่งรหัสตรวจสอบมาแต่ไม่ตรง → ไม่รับ (QR ปลอม/แก้ไข) · ไม่ส่งรหัส: บังคับแล้ว = ไม่รับ · ยังไม่บังคับ = รับ (ช่วงเปลี่ยนตู้)
 *   ใบที่ไม่พบเลข: ปล่อยให้ gateAcceptDocs ตอบ notFound ตามเดิม
 * @return array [$keep, $rejected(['docId','reason','kind']), $skipped(string[]), $logs(string[])]
 */
function gateSecFilterDocs(PDO $pdo, array $docSet, string $siteCode, array $codes, string $cardId = ''): array {
    $keep = []; $rej = []; $skip = []; $logs = [];
    $siteCode = strtoupper(trim($siteCode));
    $require = qrRequireCode($pdo);
    foreach ($docSet as $docNo) {
        $id  = strtoupper(trim((string)$docNo));
        $doc = qrDocOfScan($pdo, $id);
        if (!$doc) { $keep[] = $docNo; continue; }
        $docSite = strtoupper((string)$doc['site']);
        $why = null; $kind = '';
        if ($siteCode !== '' && $docSite !== $siteCode) {
            $why = 'ใบของไซต์ ' . $docSite . ' — สแกนที่ตู้ไซต์ ' . $siteCode . ' ไม่ได้';
            $kind = 'site';
        } elseif (isset($codes[$id])) {
            if (!qrVerify($docSite, $id, $codes[$id])) {
                $why = 'รหัสตรวจสอบ QR ไม่ถูกต้อง (QR ปลอมหรือถูกแก้) — เปิด QR จากหน้า "QR เอกสาร" ใหม่';
                $kind = 'qr_code';
            }
        } elseif ($require) {
            $why = 'QR ไม่มีรหัสตรวจสอบ — เปิด QR จากหน้า "QR เอกสาร" ใหม่';
            $kind = 'qr_code';
        }
        if ($why === null) { $keep[] = $docNo; continue; }
        $rej[]  = ['docId' => $id, 'reason' => $why, 'kind' => $kind];
        $skip[] = $id . ' (' . $why . ')';
        $logs[] = 'QR rejected: ' . $id . ' — ' . $why . ($cardId !== '' ? ' | card=' . $cardId : '');
    }
    return [$keep, $rej, $skip, $logs];
}
