<?php
/**
 * CONNEXT — auth.php : session, CSRF, login (Users + Subcontractors fallback),
 * rate-limit, remember-token 30 วัน (ออกอัตโนมัติ — UX เดิมคือไม่ต้อง login ซ้ำ)
 */

// ---- Session -----------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,            // session cookie; ความคงอยู่ยาวใช้ remember token
        'path'     => (APP_BASE ?: '/'),
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',        // Lax เพื่อให้เปิดจาก PWA/ลิงก์ภายนอกได้ (แอปเดิมพึ่ง localStorage)
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', '86400');
    session_start();
}

// ---- CSRF ---------------------------------------------------------------
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function verifyCsrfOrFail(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(csrfToken(), $token)) {
        // [2026-10-02] แนบ token ของ session ปัจจุบันกลับไป — หน้าที่ถือ token ของ session เก่า (ออกจากระบบแล้วล็อกอินใหม่
        // โดยไม่รีโหลด · session หมดอายุ) ใช้ค่านี้แล้วลองใหม่ 1 ครั้ง (js/gas-shim.js) · เว็บอื่นอ่านคำตอบไม่ได้ (ไม่มี CORS)
        jsonOut(['ok' => false, 'error' => 'CSRF token invalid', 'code' => 'csrf', 'csrf' => csrfToken()], 403);
    }
}

// ---- Identity -----------------------------------------------------------
/** โครง user ใน session — คีย์ล้อ userData ของ GAS เพื่อ map ง่าย */
function currentUser(): ?array {
    return $_SESSION['connext_user'] ?? null;
}
function requireLogin(): array {
    $u = currentUser();
    if (!$u) {
        // ลองกู้จาก remember token ก่อนตัดสิน
        $u = tryRememberLogin();
    }
    if (!$u) {
        jsonOut(['ok' => false, 'error' => 'session expired', 'code' => 'auth'], 401);
    }
    return $u;
}
function isLoggedIn(): bool {
    return currentUser() !== null || tryRememberLogin() !== null;
}

// ---- Rate limit (5 ครั้ง / 15 นาที) --------------------------------------
function loginRateLimited(PDO $pdo, string $username): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    // ล้างของเก่า >24 ชม. (ทำตอน insert เพื่อไม่ต้องมี cron)
    $pdo->prepare("DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)")->execute();
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM login_attempts
         WHERE (ip_address = ? OR username = ?) AND attempted_at > (NOW() - INTERVAL 15 MINUTE)"
    );
    $stmt->execute([$ip, $username]);
    return (int)$stmt->fetchColumn() >= 5;
}
function recordLoginFailure(PDO $pdo, string $username): void {
    $stmt = $pdo->prepare("INSERT INTO login_attempts (ip_address, username) VALUES (?, ?)");
    $stmt->execute([$_SERVER['REMOTE_ADDR'] ?? '', $username]);
}
function clearLoginFailures(PDO $pdo, string $username): void {
    $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ? OR username = ?");
    $stmt->execute([$_SERVER['REMOTE_ADDR'] ?? '', $username]);
}

// ---- Password policy (พฤติกรรม validatePassword_ เดิม: ห้ามไทย/ช่องว่าง) --
function validatePasswordPolicy(string $p): ?string {
    if ($p === '') return 'กรุณากรอกรหัสผ่าน';
    if (preg_match('/[\x{0E00}-\x{0E7F}]/u', $p)) return 'รหัสผ่านต้องไม่มีตัวอักษรภาษาไทย';
    if (preg_match('/\s/', $p)) return 'รหัสผ่านต้องไม่มีช่องว่าง';
    return null;
}

// ---- Session builder ------------------------------------------------------
/**
 * สร้าง session + userData ให้โครงเหมือน GAS checkLogin ทุก field
 * account: แถวจาก users(join roles, projects) หรือ subcontractors(join projects)
 */
function establishSession(PDO $pdo, string $type, array $acc, bool $issueRemember = true): array {
    session_regenerate_id(true);
    if ($type === 'user') {
        $userData = [
            'username'  => $acc['username'],
            'fullName'  => $acc['full_name'],
            'role'      => $acc['role_name'],        // RoleName เช่น 'Store 1'
            'roleId'    => $acc['role_code'],        // RoleID เช่น 'ST1'
            'roleLevel' => 'R' . (int)$acc['level'], // 'R4' (client parse ตัวเลขเอง)
            'siteCode'  => $acc['project_code'],
            'canReq'    => (bool)$acc['can_req'],
            'canDaily'  => (bool)$acc['can_daily_check'],
            // [Roles:SC]/[Roles:BS] — คุมเมนูสแกนนิ้ว/หักคจช./ตั้งค่าผู้รับเหมา (GAS v1.8-1.9)
            'canSC'     => (bool)($acc['sc'] ?? 0),
            'canBS'     => (bool)($acc['bs'] ?? 0),
            'firstPage' => 'requisition',            // ROLE_FIRST_PAGE เดิม: ทุก role = requisition
        ];
    } else {
        $userData = [
            'username'  => $acc['sub_code'],
            'fullName'  => $acc['name'],
            'role'      => 'Subcontractor',
            'roleId'    => 'SUB',
            'roleLevel' => 'R1',
            'siteCode'  => $acc['project_code'],
            'canReq'    => false,
            'canDaily'  => false,
            'canSC'     => false,
            'canBS'     => false,
            'firstPage' => 'requisition',
        ];
    }
    $_SESSION['connext_user'] = array_merge($userData, [
        'accountType' => $type,                      // 'user' | 'subcontractor'
        'accountId'   => (int)$acc['id'],
        'projectId'   => (int)$acc['project_id'],
    ]);
    if ($issueRemember) { issueRememberToken($pdo, $type, (int)$acc['id']); }
    return $_SESSION['connext_user'];
}

// ---- Remember token (selector + validator, หมุนทุกครั้ง) ------------------
const REMEMBER_COOKIE = 'connext_remember';
const REMEMBER_DAYS = 30;

function issueRememberToken(PDO $pdo, string $type, int $accountId): void {
    $selector  = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare(
        "INSERT INTO remember_tokens (account_type, account_id, selector, validator_hash, expires_at)
         VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL " . REMEMBER_DAYS . " DAY))"
    );
    $stmt->execute([$type, $accountId, $selector, hash('sha256', $validator)]);
    setcookie(REMEMBER_COOKIE, $selector . ':' . $validator, [
        'expires'  => time() + REMEMBER_DAYS * 86400,
        'path'     => (APP_BASE ?: '/'),
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function tryRememberLogin(): ?array {
    global $pdo;
    if (!empty($_SESSION['connext_user'])) return $_SESSION['connext_user'];
    $raw = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if ($raw === '' || strpos($raw, ':') === false) return null;
    list($selector, $validator) = explode(':', $raw, 2);
    try {
        $stmt = $pdo->prepare("SELECT * FROM remember_tokens WHERE selector = ? AND expires_at > NOW()");
        $stmt->execute([$selector]);
        $row = $stmt->fetch();
        if (!$row || !hash_equals($row['validator_hash'], hash('sha256', $validator))) {
            clearRememberCookie();
            return null;
        }
        // โหลดบัญชี
        if ($row['account_type'] === 'user') {
            $acc = fetchUserAccountById($pdo, (int)$row['account_id']);
        } else {
            $acc = fetchSubAccountById($pdo, (int)$row['account_id']);
        }
        if (!$acc) { clearRememberCookie(); return null; }
        // หมุน token: ลบอันเก่า ออกอันใหม่
        $pdo->prepare("DELETE FROM remember_tokens WHERE id = ?")->execute([$row['id']]);
        return establishSession($pdo, $row['account_type'], $acc, true);
    } catch (Throwable $e) {
        error_log('remember login: ' . $e->getMessage());
        return null;
    }
}

function clearRememberCookie(): void {
    setcookie(REMEMBER_COOKIE, '', ['expires' => time() - 3600, 'path' => (APP_BASE ?: '/')]);
}

function logoutCurrent(PDO $pdo): void {
    $u = currentUser();
    if ($u) {
        $stmt = $pdo->prepare("DELETE FROM remember_tokens WHERE account_type = ? AND account_id = ?");
        $stmt->execute([$u['accountType'], $u['accountId']]);
    }
    clearRememberCookie();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ---- Account fetchers ------------------------------------------------------
function fetchUserAccountByUsername(PDO $pdo, string $username): ?array {
    $stmt = $pdo->prepare(
        "SELECT u.*, r.role_code, r.name AS role_name, r.level, r.can_req, r.can_daily_check, r.sc, r.bs,
                p.code AS project_code
         FROM users u
         JOIN roles r ON r.id = u.role_id
         JOIN projects p ON p.id = u.project_id
         WHERE u.username = ? LIMIT 1"
    );
    $stmt->execute([trim($username)]); // utf8mb4_unicode_ci = case-insensitive ตรงพฤติกรรม eqUser_
    $row = $stmt->fetch();
    return $row ?: null;
}
function fetchUserAccountById(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare(
        "SELECT u.*, r.role_code, r.name AS role_name, r.level, r.can_req, r.can_daily_check, r.sc, r.bs,
                p.code AS project_code
         FROM users u JOIN roles r ON r.id = u.role_id JOIN projects p ON p.id = u.project_id
         WHERE u.id = ? AND u.status = 'active' LIMIT 1"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}
// ทะเบียนกลาง: โครงการของผู้รับเหมามาจาก sub_projects (enabled=1) ไม่ใช่คอลัมน์บนแถวชุดแล้ว
// มติจุดที่ 13: เปิดใช้โครงการเดียว → เข้าโครงการนั้นตรง ๆ · เปิดใช้หลายโครงการ → เอาตัวแรก
// (เรียงตาม project_id เพื่อให้ผลคาดเดาได้) แล้วให้เลือกทีหลัง — ยังไม่มีข้อมูลเคสหลายโครงการ
function fetchSubAccountByCode(PDO $pdo, string $code): ?array {
    // พฤติกรรมเดิม: SubID เทียบแบบ normalize ตัวเลข ('2' == '002') เอาแถว active แถวแรก
    $norm = fmtSubId($code);
    $stmt = $pdo->prepare(
        "SELECT s.*, sp.project_id, p.code AS project_code
         FROM subcontractors s
         JOIN sub_projects sp ON sp.sub_id = s.id AND sp.enabled = 1
         JOIN projects p ON p.id = sp.project_id
         WHERE s.sub_code = ? AND s.status = 'active'
         ORDER BY sp.project_id LIMIT 1"
    );
    $stmt->execute([$norm]);
    $row = $stmt->fetch();
    return $row ?: null;
}
function fetchSubAccountById(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare(
        "SELECT s.*, sp.project_id, p.code AS project_code
         FROM subcontractors s
         JOIN sub_projects sp ON sp.sub_id = s.id AND sp.enabled = 1
         JOIN projects p ON p.id = sp.project_id
         WHERE s.id = ? AND s.status = 'active'
         ORDER BY sp.project_id LIMIT 1"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** โครงการทั้งหมดที่ผู้รับเหมารายนี้ถูกเปิดใช้ — ใช้ตอนต้องให้เลือกไซต์ (มติจุดที่ 13) */
function fetchSubProjects(PDO $pdo, int $subId): array {
    $stmt = $pdo->prepare(
        "SELECT p.id, p.code, p.name
         FROM sub_projects sp JOIN projects p ON p.id = sp.project_id
         WHERE sp.sub_id = ? AND sp.enabled = 1
         ORDER BY sp.project_id"
    );
    $stmt->execute([$subId]);
    return $stmt->fetchAll() ?: [];
}
