<?php
/**
 * CONNEXT — lib/qr_sign.php : รหัสตรวจสอบ QR (GP-17 / GP-21 / OP-73 · 2026-10-02)
 *
 * QR ของใบ = JSON {Doc, Req, Receiver, Site, Chk}
 *   Site = รหัสไซต์ของใบ · Chk = HMAC-SHA256(รหัสลับของเว็บ, "v2|SITE|เลขที่สแกน") 12 ตัวแรก (ฐาน 16 ตัวใหญ่)
 *   ตู้รุ่นใหม่: Site ต้องตรงกับไซต์ของตู้ + ส่ง Chk มากับเลขใบ (Codes) · เว็บตรวจรหัสและไซต์ของใบอีกชั้น (api/gate.php)
 *   ตู้รุ่นเก่าอ่านแค่ Doc (คีย์อื่นไม่สนใจ) → ใช้ QR ใหม่ได้ระหว่างรออัปเดตโปรแกรมตู้
 *   เปิด "บังคับรหัสตรวจสอบ" (app_settings qr_require_code) หลังตู้ทุกตู้อัปเดตแล้ว → QR ไม่มีรหัส/สร้างเอง = ไม่รับ
 * "เลขที่สแกน" = ข้อความ Doc ใน QR ตรงตัว: เลขใบ (…G01) · ขาคืนใบยืม (…G01RT) · ขานำเข้าใบย้าย Gate (TG…G01G03) · ใบนับ SC
 * รหัสลับอยู่ที่ settings/qr_secret.php (สร้างเองครั้งแรก — สุ่ม 32 ไบต์) · ห้ามเผยแพร่ · เปลี่ยนรหัสลับ = QR ที่เปิดค้างไว้ใช้ไม่ได้
 *
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/app_settings.php';

function qrSecret(): string {
    static $secret = null;
    if ($secret !== null) { return $secret; }
    $file = __DIR__ . '/../settings/qr_secret.php';
    if (is_file($file)) {
        $v = include $file;
        if (is_string($v) && strlen($v) >= 32) { $secret = $v; return $secret; }
    }
    $v = bin2hex(random_bytes(32));
    $php = "<?php\n// CONNEXT — รหัสลับสำหรับรหัสตรวจสอบ QR (lib/qr_sign.php) · สร้างอัตโนมัติ " . date('Y-m-d H:i') . "\n"
         . "// ห้ามเผยแพร่ · เปลี่ยนค่า = QR ที่เปิดค้างไว้บนมือถือใช้ไม่ได้ (เปิดหน้า QR ใหม่)\nreturn '" . $v . "';\n";
    if (@file_put_contents($file, $php, LOCK_EX) === false) {
        error_log('qrSecret: cannot write ' . $file);
    }
    $secret = $v;
    return $secret;
}

/** รหัสตรวจสอบของ (ไซต์, เลขที่สแกน) — 12 ตัวฐาน 16 ตัวใหญ่ */
function qrCheckCode(string $siteCode, string $scanId): string {
    $msg = 'v2|' . strtoupper(trim($siteCode)) . '|' . strtoupper(trim($scanId));
    return strtoupper(substr(hash_hmac('sha256', $msg, qrSecret()), 0, 12));
}

function qrVerify(string $siteCode, string $scanId, string $code): bool {
    $code = strtoupper(trim($code));
    if ($code === '' || $siteCode === '' || $scanId === '') { return false; }
    return hash_equals(qrCheckCode($siteCode, $scanId), $code);
}

/** บังคับรหัสตรวจสอบหรือยัง (app_settings qr_require_code — ค่าตั้งต้น ปิด ระหว่างรออัปเดตตู้) */
function qrRequireCode(PDO $pdo): bool {
    return appSettingOn($pdo, 'qr_require_code');
}

/**
 * ใบของเลขที่สแกน: แถวประตู (gate_logs.doc_no ตรงตัว — ครอบคลุม …RT และขานำเข้า TG) หรือเลขใบตรง ๆ
 * @return array|null ['doc_id','doc_no','doc_type','project_id','site','requester','receiver_sub_id','gate_code']
 */
function qrDocOfScan(PDO $pdo, string $scanId): ?array {
    $scanId = strtoupper(trim($scanId));
    if ($scanId === '' || !preg_match('/^[A-Z0-9][A-Z0-9_\-.\/]{0,39}$/', $scanId)) { return null; }
    $st = $pdo->prepare(
        'SELECT d.id AS doc_id, d.doc_no, d.doc_type, d.project_id, p.code AS site, d.requester_username AS requester,
                d.receiver_sub_id, g.gate_code
           FROM gate_logs gl
           JOIN documents d ON d.id = gl.document_id
           JOIN projects p ON p.id = d.project_id
           LEFT JOIN gates g ON g.id = gl.gate_id
          WHERE gl.doc_no = ? ORDER BY gl.id DESC LIMIT 1'
    );
    $st->execute([$scanId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        $st = $pdo->prepare(
            'SELECT d.id AS doc_id, d.doc_no, d.doc_type, d.project_id, p.code AS site, d.requester_username AS requester,
                    d.receiver_sub_id, g.gate_code
               FROM documents d JOIN projects p ON p.id = d.project_id LEFT JOIN gates g ON g.id = d.gate_id
              WHERE d.doc_no = ? LIMIT 1'
        );
        $st->execute([$scanId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
    }
    return $r ?: null;
}

/**
 * ใครขอ QR (รหัสตรวจสอบ) ของใบได้ — ตามสิทธิ์หน้า QR เอกสาร:
 *   ผู้ใช้ไซต์เดียวกันที่เป็น R8 ขึ้นไป / สายสโตร์ (can_req) = ทุกใบของไซต์ · คนอื่น = ใบที่ตัวเองเป็นผู้ขอ ·
 *   บัญชีผู้รับเหมา = ใบที่ตัวเองเป็นผู้ขอ/ผู้รับ · ระดับ 0 (ADM / R&D) = ทุกไซต์
 */
function qrCanView(PDO $pdo, ?array $user, array $doc): bool {
    if (!$user) { return false; }
    if (($user['accountType'] ?? '') === 'subcontractor') {
        if ((int)($user['projectId'] ?? 0) !== (int)$doc['project_id']) { return false; }
        $me = trim((string)($user['fullName'] ?? ''));
        return ((int)($doc['receiver_sub_id'] ?? 0) > 0 && (int)$doc['receiver_sub_id'] === (int)($user['accountId'] ?? 0))
            || ($me !== '' && eqUser((string)$doc['requester'], $me));
    }
    $r = docExtUserRole($pdo, $user);
    if ($r['level'] === 0) { return true; }
    if ((int)($user['projectId'] ?? 0) !== (int)$doc['project_id']) { return false; }
    if ($r['canReq'] || ($r['level'] >= 8 && $r['level'] < 99)) { return true; }
    return eqUser((string)$doc['requester'], trim((string)($user['username'] ?? '')));
}

/** RPC getQrPayload(scanId) → {success, site, chk, required} — หน้า QR เรียกก่อนวาด QR (js/qr-sign.js) */
function rpc_getQrPayload(PDO $pdo, ?array $user, array $args) {
    try {
        $scanId = strtoupper(trim((string)($args[0] ?? '')));
        $doc = qrDocOfScan($pdo, $scanId);
        if (!$doc) { return ['success' => false, 'message' => 'ไม่พบเอกสาร ' . $scanId]; }
        if (!qrCanView($pdo, $user, $doc)) {
            return ['success' => false, 'message' => 'ไม่มีสิทธิ์เปิด QR ของใบนี้'];
        }
        return ['success' => true, 'doc' => $scanId, 'site' => (string)$doc['site'],
                'chk' => qrCheckCode((string)$doc['site'], $scanId), 'required' => qrRequireCode($pdo)];
    } catch (Throwable $e) {
        error_log('getQrPayload: ' . $e->getMessage());
        return ['success' => false, 'message' => 'สร้างรหัสตรวจสอบ QR ไม่สำเร็จ'];
    }
}
