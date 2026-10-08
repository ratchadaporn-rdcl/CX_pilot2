<?php
/**
 * CONNEXT — lib/auth_api.php : RPC auth/บัญชี (พอร์ตจาก Code.js)
 * ครอบคลุม GAS: checkLogin, getUserPermissions, changePassword, changeSite,
 *               getAvailableSites, getAppBuild (+ logoutServer ใหม่ฝั่ง PHP)
 *
 * ทุกฟังก์ชันคืนโครงเดียวกับ GAS เดิมเป๊ะ — client (Index.html/LoginPage.html)
 * เดิมถูกเรียกผ่าน gas-shim โดยไม่แก้โค้ดฝั่งนั้น
 * PHP 7.4 เท่านั้น
 */

// helpers.php + auth.php ถูกโหลดแล้วโดย api/rpc.php (ผ่าน config.php)
// require_once ซ้ำเพื่อกันเรียกจาก entry อื่น (idempotent)
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../auth.php';

// =========================================================================
// ภายใน: ตรวจรหัสผ่านแบบรองรับ legacy plaintext
//  - เก็บแบบ bcrypt ('$2...') → password_verify
//  - เก็บแบบ plaintext เดิม (import มาแล้วยังไม่ hash) → เทียบแบบ GAS
//    (trim ทั้งสองฝั่ง เหมือน row[passIdx].trim() === password.trim())
//    ถ้าตรง → re-hash ให้ทันทีแบบโปร่งใส
//  $table รับเฉพาะค่าคงที่ 'users' | 'subcontractors' (ห้ามส่งค่าจากผู้ใช้)
// =========================================================================
function _cnxVerifyPasswordAndUpgrade(PDO $pdo, string $table, int $id, string $stored, string $inputPass): bool {
    if (strncmp($stored, '$2', 2) === 0) {
        return password_verify($inputPass, $stored);
    }
    // legacy plaintext — GAS เทียบแบบ trim ทั้งสองฝั่ง, case-sensitive
    if (trim($stored) !== '' && trim($stored) === $inputPass) {
        try {
            $sql = ($table === 'subcontractors')
                ? "UPDATE subcontractors SET password = ? WHERE id = ?"
                : "UPDATE users SET password = ? WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([password_hash($inputPass, PASSWORD_DEFAULT), $id]);
        } catch (Throwable $e) {
            error_log('password upgrade failed: ' . $e->getMessage());
            // upgrade พลาดไม่ทำให้ login ล้ม — รหัสถูกต้องแล้ว
        }
        return true;
    }
    return false;
}

// ภายใน: หา subcontractor แถวแรกที่ SubID ตรง (ไม่กรอง status — GAS เช็ค
// inactive หลังเจอแถว เพื่อคืนข้อความ "ถูกระงับ" ได้เหมือนเดิม)
function _cnxFindSubForLogin(PDO $pdo, string $username): ?array {
    $norm = fmtSubId($username);
    if ($norm === '') return null;
    $stmt = $pdo->prepare(
        "SELECT s.*, sp.project_id, p.code AS project_code
         FROM subcontractors s
         JOIN sub_projects sp ON sp.sub_id = s.id AND sp.enabled = 1
         JOIN projects p ON p.id = sp.project_id
         WHERE s.sub_code = ?
         ORDER BY sp.project_id LIMIT 1"
    ); // collation utf8mb4_unicode_ci → case-insensitive ตรงพฤติกรรม GAS
    $stmt->execute([$norm]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// =========================================================================
// checkLogin(username, password) — GAS Code.js:497
// Users ก่อน → fallback Subcontracts · โครง return เดิมเป๊ะ:
//   {success, ok, message, redirect, userData:{username,name,fullName,role,
//    roleLevel,roleId,canReq,canDaily,siteCode,firstPage}}
// เพิ่มฝั่ง PHP: rate-limit 5/15 นาที + สร้าง session ฝั่ง server
// เรียกซ้ำตอน login ค้างอยู่ได้ (สลับบัญชี — establishSession regenerate id)
// =========================================================================
function rpc_checkLogin(PDO $pdo, ?array $user, array $args) {
    $username  = trim((string)($args[0] ?? ''));
    $inputPass = trim((string)($args[1] ?? '')); // GAS ใช้ password.trim()

    try {
        // ---- Rate limit (ใหม่ฝั่ง PHP — GAS ไม่มี) ----
        if (loginRateLimited($pdo, $username)) {
            return ['success' => false, 'message' => 'พยายามเข้าสู่ระบบบ่อยเกินไป กรุณารอ 15 นาที'];
        }

        // ---- 1) Users ----
        $acc = fetchUserAccountByUsername($pdo, $username);
        if ($acc && _cnxVerifyPasswordAndUpgrade($pdo, 'users', (int)$acc['id'], (string)$acc['password'], $inputPass)) {
            // GAS เช็ค inactive หลังรหัสผ่านตรงแล้วเท่านั้น
            if (mb_strtolower(trim((string)$acc['status']), 'UTF-8') === 'inactive') {
                return ['success' => false, 'message' => 'บัญชีผู้ใช้นี้ถูกระงับการใช้งาน'];
            }
            clearLoginFailures($pdo, $username);
            $sess = establishSession($pdo, 'user', $acc);

            $userData = $sess;
            $userData['name'] = $sess['fullName']; // GAS ส่งทั้ง name และ fullName

            return [
                'success'  => true,
                'ok'       => true,
                'message'  => 'ยินดีต้อนรับ ' . $acc['full_name'] . '!',
                'redirect' => APP_BASE . '/index.php?page=' . rawurlencode($sess['firstPage']),
                'userData' => $userData,
            ];
        }
        // หมายเหตุ: GAS วน loop เทียบ username+password พร้อมกัน — user ที่มีจริง
        // แต่รหัสผิดจะ "ตกลงไป" เช็คชีต Subcontracts ต่อ (พฤติกรรมเดิม คงไว้)

        // ---- 2) Fallback: Subcontracts ----
        $sub = _cnxFindSubForLogin($pdo, $username);
        if ($sub) {
            // GAS เช็ค inactive ก่อนเทียบรหัสผ่าน
            if (mb_strtolower(trim((string)$sub['status']), 'UTF-8') === 'inactive') {
                return ['success' => false, 'message' => 'บัญชีผู้รับเหมานี้ถูกระงับการใช้งาน'];
            }
            if (!_cnxVerifyPasswordAndUpgrade($pdo, 'subcontractors', (int)$sub['id'], (string)$sub['password'], $inputPass)) {
                recordLoginFailure($pdo, $username);
                return ['success' => false, 'message' => 'รหัสผ่านไม่ถูกต้อง'];
            }
            clearLoginFailures($pdo, $username);
            $sess = establishSession($pdo, 'subcontractor', $sub);

            $userData = $sess;
            $userData['name']   = $sess['fullName'];
            // GAS ให้ roleId ของ SUB = SubID จริง เช่น '002' (session เก็บ 'SUB'
            // สำหรับฝั่ง server — ฝั่ง client ต้องเห็นค่าเดิมของ GAS)
            $userData['roleId'] = (string)$sub['sub_code'];

            return [
                'success'  => true,
                'ok'       => true,
                'message'  => 'ยินดีต้อนรับ ' . $sub['name'] . '!',
                'redirect' => APP_BASE . '/index.php?page=' . rawurlencode($sess['firstPage']),
                'userData' => $userData,
            ];
        }

        recordLoginFailure($pdo, $username);
        return ['success' => false, 'message' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'];

    } catch (Throwable $e) {
        error_log('checkLogin: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Server error: ' . $e->getMessage()];
    }
}

// =========================================================================
// getUserPermissions(username) — GAS Code.js:743
// คืน {canReq, canDaily} จาก Roles ล่าสุด — server-authoritative:
// ใช้ตัวตนจาก session (ไม่เชื่อ username จาก args — client ส่งของตัวเองเสมอ)
// SUB → false ทั้งคู่ (GAS: SubName ไม่อยู่ในชีต Users → lookup พลาด → false)
// =========================================================================
function rpc_getUserPermissions(PDO $pdo, ?array $user, array $args) {
    try {
        if (!$user || ($user['accountType'] ?? '') !== 'user') {
            return ['canReq' => false, 'canDaily' => false];
        }
        // GAS ไม่กรอง Status ตอนไล่หา RoleID — ไม่กรองที่นี่เช่นกัน
        $stmt = $pdo->prepare(
            "SELECT r.can_req, r.can_daily_check, r.sc, r.bs
             FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.id = ? LIMIT 1"
        );
        $stmt->execute([(int)$user['accountId']]);
        $row = $stmt->fetch();
        if (!$row) return ['canReq' => false, 'canDaily' => false];
        return [
            'canReq'   => (bool)$row['can_req'],
            'canDaily' => (bool)$row['can_daily_check'],
            'canSC'    => (bool)($row['sc'] ?? 0),
            'canBS'    => (bool)($row['bs'] ?? 0),
        ];
    } catch (Throwable $e) {
        error_log('getUserPermissions: ' . $e->getMessage());
        return ['canReq' => false, 'canDaily' => false];
    }
}

// =========================================================================
// changePassword(username, newPassword[, legacyNewPassword]) — GAS Code.js:2804
// รูปเรียกเก่า changePassword(username, oldPass, newPass) ยังใช้ได้:
// ถ้ามี arg ตัวที่ 3 → ตัวสุดท้ายคือรหัสใหม่จริง (oldPass ถูกทิ้ง — GAS เดิมก็ทิ้ง)
// Server-authoritative: เปลี่ยนได้เฉพาะรหัสของบัญชีใน session เท่านั้น
// บัญชี subcontractor → อัปเดตตาราง subcontractors
// =========================================================================
function rpc_changePassword(PDO $pdo, ?array $user, array $args) {
    try {
        $argUsername = trim((string)($args[0] ?? ''));
        $effectiveNewPassword = (array_key_exists(2, $args) && $args[2] !== null)
            ? (string)$args[2]
            : (string)($args[1] ?? '');

        // ตรวจนโยบายก่อนแตะ DB (พฤติกรรม validatePassword_: ห้ามว่าง/ไทย/ช่องว่าง
        // — ตรวจบนค่าดิบไม่ trim เพื่อจับช่องว่างหัวท้ายด้วย)
        $err = validatePasswordPolicy($effectiveNewPassword);
        if ($err !== null) return ['success' => false, 'message' => $err];

        if (!$user) return ['success' => false, 'message' => 'ไม่พบผู้ใช้งาน'];
        // GAS แก้แถวตาม username ที่ส่งมา — ฝั่ง PHP บังคับว่าต้องเป็นบัญชีตัวเอง
        if ($argUsername !== '' && !eqUser($argUsername, (string)$user['username'])) {
            return ['success' => false, 'message' => 'ไม่พบผู้ใช้งาน'];
        }

        $hash = password_hash($effectiveNewPassword, PASSWORD_DEFAULT);
        if (($user['accountType'] ?? '') === 'subcontractor') {
            $stmt = $pdo->prepare("UPDATE subcontractors SET password = ? WHERE id = ?");
        } else {
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        }
        $stmt->execute([$hash, (int)$user['accountId']]);

        if ($stmt->rowCount() === 0) {
            // แถวหาย (บัญชีถูกลบ) — ตรวจว่ามีจริงไหมเพื่อคงข้อความเดิมของ GAS
            $chk = ($user['accountType'] ?? '') === 'subcontractor'
                ? $pdo->prepare("SELECT id FROM subcontractors WHERE id = ?")
                : $pdo->prepare("SELECT id FROM users WHERE id = ?");
            $chk->execute([(int)$user['accountId']]);
            if (!$chk->fetch()) return ['success' => false, 'message' => 'ไม่พบผู้ใช้งาน'];
            // rowCount 0 เพราะรหัสใหม่ hash แล้วค่าเดิม? (เป็นไปไม่ได้กับ bcrypt salt)
        }
        return ['success' => true, 'message' => 'เปลี่ยนรหัสผ่านสำเร็จ'];
    } catch (Throwable $e) {
        error_log('changePassword: ' . $e->getMessage());
        return ['success' => false, 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()];
    }
}

// =========================================================================
// changeSite(username, newSiteCode, fromSiteCode, roleId) — GAS Code.js:2841
// เฉพาะ R0: (ก) session ต้องเป็นบัญชี user ที่ role level = 0 จาก DB
//           (ข) เป้าหมาย (username arg) ต้องเป็น R0 ด้วย — เกณฑ์เดิมของ GAS
// เขียน activity_log แทนชีต UserLogs เดิม (Timestamp/UserID/RoleID/FromSite/ToSite)
// =========================================================================
function rpc_changeSite(PDO $pdo, ?array $user, array $args) {
    try {
        $target       = trim((string)($args[0] ?? ''));
        $newSiteCode  = trim((string)($args[1] ?? ''));
        $fromSiteCode = trim((string)($args[2] ?? ''));
        $roleIdArg    = trim((string)($args[3] ?? ''));

        $denyMsg = 'เฉพาะผู้ดูแลระบบ (R0) เท่านั้นที่เปลี่ยน Site ได้';

        // ---- ผู้เรียก (session) ต้องเป็น R0 — ตรวจสดจาก DB ไม่เชื่อ session cache
        if (!$user || ($user['accountType'] ?? '') !== 'user') {
            return ['success' => false, 'message' => $denyMsg];
        }
        $stmt = $pdo->prepare(
            "SELECT r.level FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? LIMIT 1"
        );
        $stmt->execute([(int)$user['accountId']]);
        $callerLevel = $stmt->fetchColumn();
        if ($callerLevel === false || (int)$callerLevel !== 0) {
            return ['success' => false, 'message' => $denyMsg];
        }

        // ---- เป้าหมายต้องเป็น R0 (เกณฑ์เดิม getUserRoleLevel_(username) !== 0)
        $stmt = $pdo->prepare(
            "SELECT u.id, u.username, r.level
             FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.username = ? LIMIT 1"
        );
        $stmt->execute([$target]);
        $targetRow = $stmt->fetch();
        if (!$targetRow || (int)$targetRow['level'] !== 0) {
            return ['success' => false, 'message' => $denyMsg];
        }

        // ---- Site ใหม่ต้องมีจริง (DB มี FK — GAS เขียนสตริงอิสระได้ แต่ client
        //      เลือกจาก getAvailableSites เสมอ จึงไม่กระทบ flow จริง)
        $stmt = $pdo->prepare("SELECT id, code FROM projects WHERE code = ? LIMIT 1");
        $stmt->execute([$newSiteCode]);
        $proj = $stmt->fetch();
        if (!$proj) {
            return ['success' => false, 'message' => 'ไม่พบ Site ที่เลือก'];
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE users SET project_id = ? WHERE id = ?");
            $stmt->execute([(int)$proj['id'], (int)$targetRow['id']]);

            // เดิม: UserLogs.appendRow([Timestamp, UserID, RoleID, FromSite, ToSite])
            $stmt = $pdo->prepare(
                "INSERT INTO activity_log (entity_type, entity_id, user_name, action, old_value, new_value)
                 VALUES ('user', ?, ?, 'change_site', ?, ?)"
            );
            $stmt->execute([
                $target,
                (string)$user['username'],
                json_encode(['fromSite' => $fromSiteCode, 'roleId' => $roleIdArg], JSON_UNESCAPED_UNICODE),
                $newSiteCode,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // เปลี่ยน site ของตัวเอง → sync session ให้ filter ฝั่ง server ตามทันที
        if (eqUser($target, (string)$user['username']) && !empty($_SESSION['connext_user'])) {
            $_SESSION['connext_user']['siteCode']  = (string)$proj['code'];
            $_SESSION['connext_user']['projectId'] = (int)$proj['id'];
        }

        return ['success' => true, 'message' => 'เปลี่ยน Site สำเร็จ', 'siteCode' => $newSiteCode];
    } catch (Throwable $e) {
        error_log('changeSite: ' . $e->getMessage());
        return ['success' => false, 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()];
    }
}

// =========================================================================
// getAvailableSites() — GAS Code.js:2888
// คืน [{siteCode, siteName}] เรียงตามชื่อ (เดิม localeCompare 'th' —
// ที่นี่ให้ MySQL utf8mb4_unicode_ci เรียงให้ ซึ่งเรียงไทยตามพจนานุกรมเช่นกัน)
// =========================================================================
function rpc_getAvailableSites(PDO $pdo, ?array $user, array $args) {
    try {
        $stmt = $pdo->query(
            "SELECT code, name FROM projects WHERE status = 'active' ORDER BY name, code"
        );
        $sites = [];
        while ($row = $stmt->fetch()) {
            $code = trim((string)$row['code']);
            if ($code === '') continue;
            $name = trim((string)$row['name']);
            $sites[] = ['siteCode' => $code, 'siteName' => ($name !== '' ? $name : $code)];
        }
        return $sites;
    } catch (Throwable $e) {
        error_log('getAvailableSites: ' . $e->getMessage());
        return [];
    }
}

// =========================================================================
// getAppBuild() — GAS Code.js:401
// เดิม = APP_VERSION + '.' + md5(Index.html)[0..12] — ฝั่ง PHP ใช้ค่าคงที่
// APP_BUILD (คำนวณจาก filemtime ไฟล์หลักใน config.php) ความหมายเดียวกัน:
// ไฟล์เปลี่ยน → build เปลี่ยน → client เด้ง banner อัปเดต
// =========================================================================
function rpc_getAppBuild(PDO $pdo, ?array $user, array $args) {
    try {
        return ['success' => true, 'build' => APP_BUILD, 'version' => APP_VERSION];
    } catch (Throwable $e) {
        return ['success' => false, 'build' => '', 'version' => APP_VERSION, 'message' => $e->getMessage()];
    }
}

// =========================================================================
// logoutServer() — ใหม่ฝั่ง PHP (GAS ไม่มี session ฝั่ง server ให้ล้าง)
// ล้าง session + remember token ของบัญชีปัจจุบัน
// =========================================================================
function rpc_logoutServer(PDO $pdo, ?array $user, array $args) {
    try {
        logoutCurrent($pdo);
    } catch (Throwable $e) {
        error_log('logoutServer: ' . $e->getMessage());
    }
    return ['success' => true];
}
