<?php
/**
 * CONNEXT — lib/signatures.php : ลายเซ็นกลาง + คำขอลงนามใบหักเงิน (port จาก Code.js v1.9.x)
 *
 * ครอบคลุมฟังก์ชัน GAS: getMySignature, saveMySignature, deleteMySignature, getMySignTasks,
 * requestSignature, cancelSignRequest, submitSignature, getSignatureView, getSignDocDetail,
 * createContractorLink, setContractorSent, getSignPageData, submitContractorSignature
 *
 * 3 บทบาทของคำขอ (sign_requests.role):
 *   inspector / approver = ผู้ใช้ในระบบ — เห็นใน "งานเซ็นของฉัน" แล้วกดเซ็นในแอป
 *   contractor           = ผู้รับเหมา — เข้าผ่านลิงก์ token ที่ sign.php ไม่ต้องล็อกอิน
 *
 * "รับทราบโดยปริยาย" (auto): pending + ส่งลิงก์แล้ว + เกิน DeadlineDays วัน → materialize
 * เป็นสถานะ 'auto' ตอนอ่าน (เหมือน _signApplyAutoAck_ ของ GAS ที่เขียนลงชีตตอนอ่าน)
 *
 * ต่างจาก GAS: ลายเซ็นเก็บเป็น "ไฟล์" ใต้ uploads/signatures/ ไม่ใช่ data URL ในเซลล์
 * (อ่านกลับเป็น data URL ให้ client เหมือนเดิม — client ไม่รู้ความต่าง)
 */

// ---------------------------------------------------------------------------
// helpers — ไฟล์ลายเซ็น
// ---------------------------------------------------------------------------

/** _validSigDataUrl_ : png/jpeg base64 ยาวไม่เกิน 45,000 (ลิมิตเดิมของ GAS) */
function _sigValidDataUrl(string $s): bool {
    if ($s === '' || strlen($s) > 45000) return false;
    return (bool)preg_match('#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=]{50,}$#', $s);
}

function _sigRoot(): string {
    return rtrim(str_replace('\\', '/', defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__) . '/'), '/');
}

/** data URL → ไฟล์ใต้ uploads/signatures/ · คืน path สัมพัทธ์ หรือ null ถ้าไม่สำเร็จ */
function _sigSaveDataUrl(string $dataUrl, string $prefix): ?string {
    if (!preg_match('#^data:image/(png|jpeg);base64,(.+)$#', $dataUrl, $m)) return null;
    $ext   = $m[1] === 'png' ? 'png' : 'jpg';
    $bytes = base64_decode($m[2], true);
    if ($bytes === false || strlen($bytes) < 32) return null;
    $subDir = 'uploads/signatures';
    $absDir = _sigRoot() . '/' . $subDir;
    if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) return null;
    $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $prefix);
    $name = $safe . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    return @file_put_contents($absDir . '/' . $name, $bytes) !== false ? $subDir . '/' . $name : null;
}

/** ไฟล์ลายเซ็น → data URL ('' ถ้าไม่มี/อ่านไม่ได้) */
function _sigReadDataUrl(?string $rel): string {
    $rel = trim((string)$rel);
    if ($rel === '') return '';
    $abs = _sigRoot() . '/' . ltrim($rel, '/');
    if (!is_file($abs)) return '';
    $bytes = @file_get_contents($abs);
    if ($bytes === false) return '';
    $mime = (substr($bytes, 0, 8) === "\x89PNG\r\n\x1a\n") ? 'image/png' : 'image/jpeg';
    return 'data:' . $mime . ';base64,' . base64_encode($bytes);
}

/** ลบไฟล์ลายเซ็นเก่า (เงียบ) */
function _sigUnlink(?string $rel): void {
    $rel = trim((string)$rel);
    if ($rel === '') return;
    $abs = _sigRoot() . '/' . ltrim($rel, '/');
    if (is_file($abs)) @unlink($abs);
}

/** token แบบเดียวกับ GAS: hex 40 ตัว (uuid 32 + อีก 8) */
function _sigNewToken(): string {
    return bin2hex(random_bytes(20));
}

// ---------------------------------------------------------------------------
// helpers — สิทธิ์ + แถวคำขอ
// ---------------------------------------------------------------------------

/** userCanDailyCheck_ : role ที่ติ๊ก CanDailyCheck (ผู้ออก/จัดการคำขอลงนาม) */
function _sigCanDaily(PDO $pdo, ?array $user): bool {
    if (!$user || ($user['accountType'] ?? '') !== 'user') return false;
    try {
        $stmt = $pdo->prepare(
            "SELECT r.can_daily_check FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? LIMIT 1"
        );
        $stmt->execute([(int)($user['accountId'] ?? 0)]);
        $v = $stmt->fetchColumn();
        return $v !== false && (int)$v === 1;
    } catch (Throwable $e) {
        error_log('_sigCanDaily: ' . $e->getMessage());
        return false;
    }
}

/** เส้นตายเป็น ms (null = ไม่มีกำหนด/ยังไม่ส่ง) — _signDeadlineMs_ */
function _sigDeadlineMs(array $row): ?int {
    $sentAt = trim((string)($row['sent_at'] ?? ''));
    $days   = (float)($row['deadline_days'] ?? 0);
    if ($sentAt === '' || $days <= 0) return null;
    $ts = strtotime($sentAt);
    if ($ts === false) return null;
    return (int)(($ts + $days * 86400) * 1000);
}

/**
 * _signApplyAutoAck_ : pending + ส่งแล้ว + เกินกำหนด → เขียนสถานะ 'auto' ลง DB
 * เรียกก่อนอ่านทุกครั้ง เพื่อให้หน้าจอ/PDF สะท้อนสถานะจริง
 * คืน row ที่อัปเดตแล้ว (แก้ในตัว)
 */
function _sigApplyAutoAck(PDO $pdo, array &$row): void {
    if (($row['status'] ?? '') !== 'pending') return;
    $dl = _sigDeadlineMs($row);
    if ($dl === null || $dl > time() * 1000) return;
    $days = (float)$row['deadline_days'];
    $daysTxt = rtrim(rtrim(number_format($days, 1, '.', ''), '0'), '.');
    $note = 'รับทราบโดยปริยาย — ไม่ตอบกลับภายใน ' . $daysTxt . ' วัน '
          . '(ครบกำหนด ' . thaiDateFull(new DateTime('@' . (int)($dl / 1000))) . ')';
    try {
        $pdo->prepare("UPDATE sign_requests SET status = 'auto', signed_at = ?, note = ? WHERE id = ?")
            ->execute([date('Y-m-d H:i:s', (int)($dl / 1000)), $note, (int)$row['id']]);
        $row['status']    = 'auto';
        $row['signed_at'] = date('Y-m-d H:i:s', (int)($dl / 1000));
        $row['note']      = $note;
    } catch (Throwable $e) {
        error_log('_sigApplyAutoAck: ' . $e->getMessage());
    }
}

/** แถวคำขอของ (docNo, role) — materialize auto-ack ให้ก่อนคืน */
function _sigFind(PDO $pdo, string $docNo, string $role): ?array {
    $stmt = $pdo->prepare("SELECT * FROM sign_requests WHERE doc_no = ? AND role = ? LIMIT 1");
    $stmt->execute([$docNo, $role]);
    $row = $stmt->fetch();
    if (!$row) return null;
    _sigApplyAutoAck($pdo, $row);
    return $row;
}

/** _signRowSummary_ : รูปที่ client ใช้ (คีย์ตรงกับ GAS ทุกตัว) */
function _sigRowSummary(?array $row): ?array {
    if (!$row) return null;
    $out = [
        'role'          => (string)$row['role'],
        'assignee'      => (string)($row['assignee'] ?? ''),
        'assigneePos'   => (string)($row['assignee_pos'] ?? ''),
        'status'        => (string)$row['status'],
        'signerName'    => (string)($row['signer_name'] ?? ''),
        'signerPos'     => (string)($row['signer_pos'] ?? ''),
        'hasSignature'  => trim((string)($row['signature_path'] ?? '')) !== '',
        'signedAt'      => !empty($row['signed_at']) ? strtotime($row['signed_at']) * 1000 : null,
        'requestedBy'   => (string)($row['requested_by'] ?? ''),
        'requestedAt'   => !empty($row['requested_at']) ? strtotime($row['requested_at']) * 1000 : null,
        'sentAt'        => !empty($row['sent_at']) ? strtotime($row['sent_at']) * 1000 : null,
        'deadlineDays'  => (float)($row['deadline_days'] ?? 0),
        'deadlineAt'    => _sigDeadlineMs($row),
        'note'          => (string)($row['note'] ?? ''),
    ];
    if ($row['role'] === 'contractor') $out['token'] = (string)($row['token'] ?? '');
    return $out;
}

/** ใบหักเงินตามเลขที่ — null ถ้าไม่พบ */
function _sigDeductionDoc(PDO $pdo, string $docNo): ?array {
    $stmt = $pdo->prepare(
        "SELECT d.*, p.code AS project_code, p.name AS project_name
         FROM deduction_docs d JOIN projects p ON p.id = d.project_id
         WHERE d.doc_no = ? LIMIT 1"
    );
    $stmt->execute([$docNo]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// ---------------------------------------------------------------------------
// ลายเซ็นของฉัน (Users:Signature)
// ---------------------------------------------------------------------------

function rpc_getMySignature(PDO $pdo, ?array $user, array $args) {
    try {
        // server-authoritative: ใช้ผู้ใช้ใน session เสมอ (arg ที่ client ส่งมาเป็นแค่ของเดิม)
        if (!$user || ($user['accountType'] ?? '') !== 'user') {
            return ['success' => false, 'message' => 'no_user'];
        }
        $stmt = $pdo->prepare("SELECT signature_path FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$user['accountId']]);
        return ['success' => true, 'dataUrl' => _sigReadDataUrl($stmt->fetchColumn() ?: '')];
    } catch (Throwable $e) {
        error_log('getMySignature error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function rpc_saveMySignature(PDO $pdo, ?array $user, array $args) {
    try {
        if (!$user || ($user['accountType'] ?? '') !== 'user') {
            return ['success' => false, 'message' => 'no_user'];
        }
        $p = is_array($args[0] ?? null) ? $args[0] : [];
        $dataUrl = (string)($p['dataUrl'] ?? '');
        if (!_sigValidDataUrl($dataUrl)) {
            return ['success' => false, 'message' => 'รูปลายเซ็นไม่ถูกต้องหรือใหญ่เกินไป (ลองลดขนาดรูป)'];
        }
        $stmt = $pdo->prepare("SELECT signature_path FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$user['accountId']]);
        $old = (string)($stmt->fetchColumn() ?: '');

        $rel = _sigSaveDataUrl($dataUrl, 'user_' . (int)$user['accountId']);
        if ($rel === null) return ['success' => false, 'message' => 'บันทึกไฟล์ลายเซ็นไม่สำเร็จ'];

        $pdo->prepare("UPDATE users SET signature_path = ? WHERE id = ?")
            ->execute([$rel, (int)$user['accountId']]);
        if ($old !== '' && $old !== $rel) _sigUnlink($old);
        return ['success' => true];
    } catch (Throwable $e) {
        error_log('saveMySignature error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function rpc_deleteMySignature(PDO $pdo, ?array $user, array $args) {
    try {
        if (!$user || ($user['accountType'] ?? '') !== 'user') {
            return ['success' => false, 'message' => 'no_user'];
        }
        $stmt = $pdo->prepare("SELECT signature_path FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$user['accountId']]);
        $old = (string)($stmt->fetchColumn() ?: '');
        $pdo->prepare("UPDATE users SET signature_path = NULL WHERE id = ?")->execute([(int)$user['accountId']]);
        _sigUnlink($old);
        return ['success' => true];
    } catch (Throwable $e) {
        error_log('deleteMySignature error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// งานเซ็นของฉัน + คำขอลงนาม (inspector / approver)
// ---------------------------------------------------------------------------

/** getMySignTasks(username) — คำขอที่รอ "ฉัน" เซ็น · ล่าสุดก่อน */
function rpc_getMySignTasks(PDO $pdo, ?array $user, array $args) {
    try {
        if (!$user || ($user['accountType'] ?? '') !== 'user') {
            return ['success' => true, 'tasks' => [], 'hasSignature' => false];
        }
        $uname = trim((string)$user['username']);

        // materialize auto-ack ของคำขอที่ค้างเกินกำหนดก่อน แล้วค่อยอ่านรายการ pending
        $stmt = $pdo->prepare(
            "SELECT * FROM sign_requests
             WHERE role IN ('inspector','approver') AND status = 'pending' AND assignee = ?"
        );
        $stmt->execute([$uname]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) { _sigApplyAutoAck($pdo, $r); }
        unset($r);

        // ชื่อเต็มของผู้ขอ (username → fullName)
        $nameMap = [];
        foreach ($pdo->query("SELECT username, full_name FROM users WHERE status <> 'inactive'") as $u) {
            $nameMap[mb_strtolower(trim((string)$u['username']), 'UTF-8')] = trim((string)$u['full_name']);
        }
        $projCode = [];
        foreach ($pdo->query("SELECT id, code FROM projects") as $pr) {
            $projCode[(int)$pr['id']] = (string)$pr['code'];
        }

        $tasks = [];
        foreach ($rows as $r) {
            if ($r['status'] !== 'pending') continue;   // เพิ่งกลายเป็น auto ไป
            $by = trim((string)($r['requested_by'] ?? ''));
            $tasks[] = [
                'docNo'           => (string)$r['doc_no'],
                'siteCode'        => $projCode[(int)$r['project_id']] ?? '',
                'ym'              => (string)$r['ym'],
                'monthLabel'      => _sigThMonthLabel((string)$r['ym']),
                'subName'         => (string)$r['sub_name'],
                'days'            => (string)$r['days_label'],
                'role'            => (string)$r['role'],
                'assigneePos'     => (string)($r['assignee_pos'] ?? ''),
                'requestedBy'     => $by,
                'requestedByName' => $nameMap[mb_strtolower($by, 'UTF-8')] ?? $by,
                'requestedAt'     => !empty($r['requested_at']) ? strtotime($r['requested_at']) * 1000 : null,
            ];
        }
        usort($tasks, function ($a, $b) { return ($b['requestedAt'] ?? 0) <=> ($a['requestedAt'] ?? 0); });

        $stmt = $pdo->prepare("SELECT signature_path FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$user['accountId']]);
        $hasSig = trim((string)($stmt->fetchColumn() ?: '')) !== '';

        return ['success' => true, 'tasks' => $tasks, 'hasSignature' => $hasSig];
    } catch (Throwable $e) {
        error_log('getMySignTasks error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage(), 'tasks' => []];
    }
}

/** 'YYYY-MM' → 'กรกฎาคม 2569' */
function _sigThMonthLabel(string $ym): string {
    if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) return $ym;
    return thaiMonthName((int)$m[2]) . ' ' . ((int)$m[1] + 543);
}

/**
 * requestSignature({docNos[]|docNo, role, assignee, assigneePos, username})
 * สร้างคำขอให้ inspector/approver — ใบที่มีคำขอค้างอยู่แล้วจะถูกข้าม (คืนใน skipped)
 */
function rpc_requestSignature(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_sigCanDaily($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p    = is_array($args[0] ?? null) ? $args[0] : [];
        $role = mb_strtolower(trim((string)($p['role'] ?? '')), 'UTF-8');
        if ($role !== 'inspector' && $role !== 'approver') return ['success' => false, 'message' => 'bad_role'];
        $assignee    = trim((string)($p['assignee'] ?? ''));
        $assigneePos = trim((string)($p['assigneePos'] ?? ''));
        if ($assignee === '') return ['success' => false, 'message' => 'กรุณาเลือกผู้ลงนาม'];

        $docNos = is_array($p['docNos'] ?? null) ? $p['docNos'] : (isset($p['docNo']) ? [$p['docNo']] : []);
        $docNos = array_values(array_filter(array_map(function ($d) { return trim((string)$d); }, $docNos), 'strlen'));
        if (!$docNos) return ['success' => false, 'message' => 'no_doc'];

        // ผู้ถูกขอต้องมีอยู่จริงและไม่ถูกระงับ
        $stmt = $pdo->prepare("SELECT 1 FROM users WHERE username = ? AND status <> 'inactive' LIMIT 1");
        $stmt->execute([$assignee]);
        if (!$stmt->fetchColumn()) {
            return ['success' => false, 'message' => 'ไม่พบผู้ใช้ ' . $assignee . ' (หรือถูกระงับการใช้งาน)'];
        }

        $done = [];
        $skipped = [];
        $now = date('Y-m-d H:i:s');
        $ins = $pdo->prepare(
            "INSERT INTO sign_requests
               (doc_no, project_id, ym, sub_name, days_label, role, assignee, assignee_pos,
                status, requested_by, requested_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)"
        );
        foreach ($docNos as $docNo) {
            $doc = _sigDeductionDoc($pdo, $docNo);
            if (!$doc) { $skipped[] = ['docNo' => $docNo, 'reason' => 'ไม่พบเอกสารในทะเบียน']; continue; }
            $exist = _sigFind($pdo, $docNo, $role);
            if ($exist && $exist['status'] === 'pending') {
                $skipped[] = ['docNo' => $docNo, 'reason' => 'มีคำขอค้างอยู่แล้ว']; continue;
            }
            if ($exist && in_array($exist['status'], ['signed', 'auto'], true)) {
                $skipped[] = ['docNo' => $docNo, 'reason' => 'ลงนามแล้ว']; continue;
            }
            if ($exist) {   // cancelled → เปิดคำขอใหม่ทับแถวเดิม (uq_sr_doc_role กันซ้ำ)
                $pdo->prepare(
                    "UPDATE sign_requests
                        SET assignee = ?, assignee_pos = ?, status = 'pending',
                            signer_name = NULL, signer_pos = NULL, signature_path = NULL, signed_at = NULL,
                            requested_by = ?, requested_at = ?, note = NULL
                      WHERE id = ?"
                )->execute([$assignee, $assigneePos, trim((string)$user['username']), $now, (int)$exist['id']]);
            } else {
                $ins->execute([
                    $docNo, (int)$doc['project_id'], (string)$doc['ym'], (string)$doc['sub_name'],
                    (string)$doc['days_label'], $role, $assignee, $assigneePos,
                    trim((string)$user['username']), $now,
                ]);
            }
            $done[] = $docNo;
        }
        return ['success' => true, 'done' => $done, 'skipped' => $skipped];
    } catch (Throwable $e) {
        error_log('requestSignature error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/** cancelSignRequest({docNo, role, username}) — ยกเลิกได้เฉพาะที่ยัง pending */
function rpc_cancelSignRequest(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_sigCanDaily($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p     = is_array($args[0] ?? null) ? $args[0] : [];
        $docNo = trim((string)($p['docNo'] ?? ''));
        $role  = mb_strtolower(trim((string)($p['role'] ?? '')), 'UTF-8');
        if ($docNo === '' || $role === '') return ['success' => false, 'message' => 'bad_request'];

        $row = _sigFind($pdo, $docNo, $role);
        if (!$row) return ['success' => false, 'message' => 'ไม่พบคำขอ'];
        if ($row['status'] !== 'pending') {
            return ['success' => false, 'message' => 'ใบนี้มีการลงนามแล้ว — ยกเลิกไม่ได้'];
        }
        // ยกเลิก = ตัดลิงก์ทิ้งด้วย (token เดิมใช้ไม่ได้อีก) เพื่อไม่ให้ลิงก์เก่ายังเซ็นได้
        $pdo->prepare("UPDATE sign_requests SET status = 'cancelled', token = NULL WHERE id = ?")
            ->execute([(int)$row['id']]);
        return ['success' => true];
    } catch (Throwable $e) {
        error_log('cancelSignRequest error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * submitSignature({docNo, role, dataUrl?, username}) — ผู้ถูกขอกดเซ็นในแอป
 * ไม่ส่ง dataUrl มา = ใช้ "ลายเซ็นของฉัน" ที่ผูกไว้
 */
function rpc_submitSignature(PDO $pdo, ?array $user, array $args) {
    try {
        if (!$user || ($user['accountType'] ?? '') !== 'user') {
            return ['success' => false, 'message' => 'no_user'];
        }
        $p     = is_array($args[0] ?? null) ? $args[0] : [];
        $docNo = trim((string)($p['docNo'] ?? ''));
        $role  = mb_strtolower(trim((string)($p['role'] ?? '')), 'UTF-8');
        if ($role !== 'inspector' && $role !== 'approver') return ['success' => false, 'message' => 'bad_role'];

        $row = _sigFind($pdo, $docNo, $role);
        if (!$row) return ['success' => false, 'message' => 'ไม่พบคำขอลงนามของใบนี้'];
        $uname = trim((string)$user['username']);
        if (!eqUser((string)($row['assignee'] ?? ''), $uname)) {
            return ['success' => false, 'message' => 'คำขอนี้ไม่ได้ขอให้คุณลงนาม'];
        }
        if ($row['status'] !== 'pending') {
            return ['success' => false, 'message' => 'ใบนี้ลงนามไปแล้ว (' . $row['status'] . ')'];
        }

        // ลายเซ็น: ใช้ที่ส่งมา ไม่งั้นใช้ที่ผูกไว้กับบัญชี
        $rel = null;
        $dataUrl = (string)($p['dataUrl'] ?? '');
        if ($dataUrl !== '') {
            if (!_sigValidDataUrl($dataUrl)) {
                return ['success' => false, 'message' => 'รูปลายเซ็นไม่ถูกต้องหรือใหญ่เกินไป'];
            }
            $rel = _sigSaveDataUrl($dataUrl, 'sign_' . preg_replace('/[^A-Za-z0-9_-]/', '', $docNo) . '_' . $role);
            if ($rel === null) return ['success' => false, 'message' => 'บันทึกไฟล์ลายเซ็นไม่สำเร็จ'];
        } else {
            $stmt = $pdo->prepare("SELECT signature_path FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$user['accountId']]);
            $mine = trim((string)($stmt->fetchColumn() ?: ''));
            if ($mine === '') {
                return ['success' => false, 'message' => 'ยังไม่ได้ตั้งลายเซ็นของคุณ — ไปที่ "ตั้งค่าบัญชี" เพื่อวาด/นำเข้าลายเซ็นก่อน'];
            }
            // คัดลอกไฟล์ไว้กับคำขอ เพื่อให้เอกสารที่เซ็นแล้วไม่เปลี่ยนตามลายเซ็นบัญชีที่แก้ทีหลัง
            $rel = _sigSaveDataUrl(_sigReadDataUrl($mine),
                                   'sign_' . preg_replace('/[^A-Za-z0-9_-]/', '', $docNo) . '_' . $role);
            if ($rel === null) return ['success' => false, 'message' => 'คัดลอกลายเซ็นไม่สำเร็จ'];
        }

        $signerPos = trim((string)($p['signerPos'] ?? '')) ?: trim((string)($row['assignee_pos'] ?? ''));
        $pdo->prepare(
            "UPDATE sign_requests
                SET status = 'signed', signer_name = ?, signer_pos = ?, signature_path = ?, signed_at = NOW()
              WHERE id = ?"
        )->execute([trim((string)$user['fullName']) ?: $uname, $signerPos, $rel, (int)$row['id']]);

        return ['success' => true, 'docNo' => $docNo, 'role' => $role];
    } catch (Throwable $e) {
        error_log('submitSignature error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/** getSignatureView({docNo, role, username}) — ดูลายเซ็นที่ลงไว้ (ผู้เกี่ยวข้องเท่านั้น) */
function rpc_getSignatureView(PDO $pdo, ?array $user, array $args) {
    try {
        if (!$user) return ['success' => false, 'message' => 'no_user'];
        $p     = is_array($args[0] ?? null) ? $args[0] : [];
        $docNo = trim((string)($p['docNo'] ?? ''));
        $role  = mb_strtolower(trim((string)($p['role'] ?? '')), 'UTF-8');
        $row   = _sigFind($pdo, $docNo, $role);
        if (!$row) return ['success' => false, 'message' => 'ไม่พบข้อมูลลายเซ็นของใบนี้'];

        $uname = trim((string)($user['username'] ?? ''));
        if (!_sigCanDaily($pdo, $user) && !eqUser((string)($row['assignee'] ?? ''), $uname)) {
            return ['success' => false, 'message' => 'no_permission'];
        }
        return [
            'success'    => true,
            'docNo'      => $docNo,
            'role'       => $role,
            'signerName' => (string)($row['signer_name'] ?? ''),
            'signerPos'  => (string)($row['signer_pos'] ?? ''),
            'signedAt'   => !empty($row['signed_at']) ? strtotime($row['signed_at']) * 1000 : null,
            'status'     => (string)$row['status'],
            'note'       => (string)($row['note'] ?? ''),
            'dataUrl'    => _sigReadDataUrl($row['signature_path'] ?? ''),
        ];
    } catch (Throwable $e) {
        error_log('getSignatureView error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// ใบหักเงิน — ออกเลข + แช่ราคา (GAS v1.9.3)
//
// กติกา: ออกใบครั้งแรก = ล็อกราคาต่อวัสดุลง deduction_doc_rates
//        ครั้งต่อ ๆ ไปอ่านราคาจากที่ล็อกไว้เสมอ → ยอดของใบนิ่งแม้ Rate Card เปลี่ยนภายหลัง
//        อยากแก้ราคาที่ล็อกผิด: ลบแถวของ DocNo นั้นแล้วออกใบซ้ำ
// ---------------------------------------------------------------------------

/** ป้ายงวดครึ่งเดือน (คงที่ ตรงกับ GAS) */
function _sigHalfLabel(int $half): string {
    return $half === 1 ? 'งวด 1-15' : 'งวด 16-สิ้นเดือน';
}

/** ป้ายงวด → เซ็ตวันที่ 'Y-m-d' (null = ทั้งเดือน / ไม่จำกัด) — _daySetFromLabel_ */
function _sigDaySetFromLabel(string $ym, string $label): ?array {
    $label = trim($label);
    if ($label === '' || $label === 'ทั้งเดือน') return null;
    $y = (int)substr($ym, 0, 4);
    $m = (int)substr($ym, 5, 2);
    $last = (int)date('t', mktime(0, 0, 0, $m, 1, $y));
    $days = [];
    if (mb_strpos($label, 'งวด', 0, 'UTF-8') === 0) {
        $range = (mb_strpos($label, '16', 0, 'UTF-8') !== false) ? range(16, $last) : range(1, 15);
        $days = $range;
    } else {
        foreach (explode(',', $label) as $x) {
            $n = (int)trim($x);
            if ($n >= 1 && $n <= 31) $days[] = $n;
        }
        if (!$days) return null;
    }
    $set = [];
    foreach ($days as $d) {
        if ($d > $last) continue;
        $set[sprintf('%04d-%02d-%02d', $y, $m, $d)] = true;
    }
    return $set;
}

/**
 * _collectDeductionGroups_ : รายการติดธงหักเงินของเดือน → subName → items[]
 * (คิวรีเดียวกับที่ generateDeductionPDF ใช้อยู่ เพื่อให้ยอดตรงกันเป๊ะ)
 */
function _sigCollectGroups(PDO $pdo, string $siteCode, string $ym, ?array $daySet, ?array $wantSet): array {
    $y = (int)substr($ym, 0, 4);
    $m = (int)substr($ym, 5, 2);
    $start = sprintf('%04d-%02d-01 00:00:00', $y, $m);
    $nextY = $m === 12 ? $y + 1 : $y;
    $nextM = $m === 12 ? 1 : $m + 1;
    $end   = sprintf('%04d-%02d-01 00:00:00', $nextY, $nextM);

    // [Scenario 05 ⑦ ⑬] หักเงินตามจำนวนหยิบจริง (qty_actual) — รายการที่หยิบจริง 0 ไม่ถูกหัก
    $sql = "SELECT d.doc_no, d.doc_type, d.doc_ts, d.receiver_name, d.approver_username,
                   p.code AS site_code,
                   i.mat_code, i.mat_name, COALESCE(i.qty_actual, i.qty) AS qty,
                   m.name AS master_name, m.unit AS master_unit, m.subgroup_name
            FROM document_items i
            JOIN documents d ON d.id = i.document_id
            JOIN projects  p ON p.id = d.project_id
            LEFT JOIN materials m ON m.mat_code = i.mat_code
            WHERE i.charge_money = 1
              AND COALESCE(i.qty_actual, i.qty) > 0
              AND d.doc_type IN ('RD','OD')
              AND LOWER(TRIM(d.status)) = 'completed'
              AND d.doc_ts >= ? AND d.doc_ts < ?";
    $params = [$start, $end];
    if ($siteCode !== '') { $sql .= ' AND p.code = ?'; $params[] = $siteCode; }
    $sql .= ' ORDER BY d.doc_ts ASC, d.id ASC, i.id ASC';
    $st = $pdo->prepare($sql);
    $st->execute($params);

    $groups = [];
    $resolvedSite = $siteCode;
    foreach ($st->fetchAll() as $r) {
        $rcv = trim((string)($r['receiver_name'] ?? ''));
        if ($rcv === '' || $rcv === '-') continue;
        if ($wantSet !== null && !isset($wantSet[$rcv])) continue;
        $dt = new DateTime((string)$r['doc_ts']);
        $dayKey = $dt->format('Y-m-d');
        if ($daySet !== null && !isset($daySet[$dayKey])) continue;

        $mc   = trim((string)$r['mat_code']);
        $name = ((string)$r['doc_type'] === 'OD') ? trim((string)($r['mat_name'] ?? '')) : '';
        if ($name === '') $name = trim((string)($r['master_name'] ?? ''));
        if ($name === '') $name = $mc !== '' ? $mc : '-';
        $rsc = trim((string)$r['site_code']);
        if ($resolvedSite === '' && $rsc !== '') $resolvedSite = $rsc;

        $groups[$rcv][] = [
            'ms'       => $dt->getTimestamp() * 1000,
            'dayKey'   => $dayKey,
            'dateTh'   => thaiDateFull($dt),
            'docId'    => (string)$r['doc_no'],
            'matCode'  => $mc,
            'name'     => $name,
            'unit'     => trim((string)($r['master_unit'] ?? '')),
            'subgroup' => trim((string)($r['subgroup_name'] ?? '')),
            'qty'      => $r['qty'],
            // ชื่อผู้เบิกบน PDF = ผู้อนุมัติของใบ (mirror _collectDeductionGroups_ ที่ส่ง picker)
            'approver' => trim((string)($r['approver_username'] ?? '')),
        ];
    }
    return ['groups' => $groups, 'resolvedSite' => $resolvedSite];
}

/** Rate Card ปัจจุบันของโครงการ: matCode → ราคา */
function _sigRateMap(PDO $pdo, int $projectId): array {
    $out = [];
    $st = $pdo->prepare(
        'SELECT m.mat_code, r.unit_price FROM rate_cards r JOIN materials m ON m.id = r.material_id
         WHERE r.project_id = ? AND r.unit_price IS NOT NULL'
    );
    $st->execute([$projectId]);
    foreach ($st->fetchAll() as $r) {
        $c = trim((string)$r['mat_code']);
        if ($c !== '') $out[$c] = (float)$r['unit_price'];
    }
    return $out;
}

/** ราคาที่ล็อกไว้ → [docNo][matCode] = price */
function _sigFrozenRates(PDO $pdo, array $docNos): array {
    if (!$docNos) return [];
    $ph = implode(',', array_fill(0, count($docNos), '?'));
    $st = $pdo->prepare("SELECT doc_no, mat_code, unit_price FROM deduction_doc_rates WHERE doc_no IN ($ph)");
    $st->execute(array_values($docNos));
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(string)$r['doc_no']][(string)$r['mat_code']] = (float)$r['unit_price'];
    }
    return $out;
}

/** _freezeDocRates_ : ล็อกราคาลงใบ (INSERT IGNORE — ล็อกครั้งแรกชนะเสมอ) */
function _sigFreezeDocRates(PDO $pdo, array $createdDocs, array $rateMap,
                            int $projectId, string $ym, string $by): int {
    if (!$createdDocs) return 0;
    $ins = $pdo->prepare(
        "INSERT IGNORE INTO deduction_doc_rates
           (doc_no, mat_code, project_id, ym, sub_name, unit_price, frozen_by, frozen_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
    );
    $n = 0;
    foreach ($createdDocs as $d) {
        $seen = [];
        foreach ($d['items'] as $it) {
            $mc = trim((string)$it['matCode']);
            if ($mc === '' || isset($seen[$mc])) continue;
            $seen[$mc] = true;
            if (!array_key_exists($mc, $rateMap)) continue;   // ยังไม่ตั้งราคา → ไม่ล็อก
            $ins->execute([$d['docNo'], $mc, $projectId, $ym, $d['subName'], $rateMap[$mc], $by]);
            $n += $ins->rowCount();
        }
    }
    return $n;
}

/**
 * issueDeductionDocs({siteCode, ym, half, username})
 * ออกเลขใบหักเงินให้ "ทุกชุด" ที่มีรายการในงวดนั้น + แช่ราคาทันที (ไม่สร้าง PDF)
 */
function rpc_issueDeductionDocs(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_sigCanDaily($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p  = is_array($args[0] ?? null) ? $args[0] : [];
        $ym = trim((string)($p['ym'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) return ['success' => false, 'message' => 'bad_month'];
        $half = (int)($p['half'] ?? 0);
        if ($half !== 1 && $half !== 2) return ['success' => false, 'message' => 'กรุณาระบุงวด (1 หรือ 2)'];
        $siteCode = trim((string)($p['siteCode'] ?? ''));
        $username = trim((string)$user['username']);

        $daysLabel = _sigHalfLabel($half);
        $daySet    = _sigDaySetFromLabel($ym, $daysLabel);
        $col       = _sigCollectGroups($pdo, $siteCode, $ym, $daySet, null);
        $resolved  = $col['resolvedSite'];

        $subs = array_keys(array_filter($col['groups'], function ($v) { return count($v) > 0; }));
        if (!$subs) {
            return ['success' => false, 'code' => 'no_items', 'message' => 'ไม่มีรายการหักเงินใน' . $daysLabel];
        }
        // เรียง ก-ฮ ให้เลขรันตามลำดับชื่อ (เหมือน GAS)
        $collator = class_exists('Collator') ? new Collator('th_TH') : null;
        usort($subs, function ($a, $b) use ($collator) {
            return $collator ? $collator->compare($a, $b) : strcmp($a, $b);
        });

        $st = $pdo->prepare("SELECT id FROM projects WHERE code = ? LIMIT 1");
        $st->execute([$resolved]);
        $projectId = (int)$st->fetchColumn();
        if (!$projectId) return ['success' => false, 'message' => 'ไม่พบโครงการ ' . $resolved];

        $ymCompact = str_replace('-', '', $ym);
        $created = [];
        $createdDocs = [];
        $already = 0;

        $pdo->beginTransaction();
        try {
            // ล็อกทะเบียนเลขของ (โครงการ, เดือน) — กันเลขชนตอนกดพร้อมกัน
            $st = $pdo->prepare(
                "SELECT running_no, sub_name, days_label FROM deduction_docs
                 WHERE project_id = ? AND ym = ? ORDER BY id FOR UPDATE"
            );
            $st->execute([$projectId, $ym]);
            $maxNo = 0;
            $existing = [];
            foreach ($st->fetchAll() as $r) {
                $maxNo = max($maxNo, (int)$r['running_no']);
                $existing[trim((string)$r['sub_name']) . '|' . trim((string)$r['days_label'])] = true;
            }

            $ins = $pdo->prepare(
                "INSERT INTO deduction_docs
                   (doc_no, project_id, ym, running_no, sub_id, sub_name, days_label, days_json,
                    item_count, issued_by, issued_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, NOW())"
            );
            $subIdQ = $pdo->prepare(
                "SELECT s.id FROM subcontractors s
                   JOIN sub_projects sp ON sp.sub_id = s.id AND sp.project_id = ?
                  WHERE s.name = ? ORDER BY s.id LIMIT 1"
            );
            $nextNo = $maxNo;
            foreach ($subs as $sub) {
                if (isset($existing[$sub . '|' . $daysLabel])) { $already++; continue; }
                $nextNo++;
                $docNo = 'SUB-' . ($resolved !== '' ? $resolved : 'NA') . '-' . $ymCompact
                       . '-' . str_pad((string)$nextNo, 2, '0', STR_PAD_LEFT);
                $subIdQ->execute([$projectId, $sub]);
                $subId = $subIdQ->fetchColumn();
                $ins->execute([
                    $docNo, $projectId, $ym, $nextNo, $subId !== false ? (int)$subId : null,
                    $sub, $daysLabel, count($col['groups'][$sub]), $username,
                ]);
                $created[] = $docNo;
                $createdDocs[] = ['docNo' => $docNo, 'subName' => $sub, 'items' => $col['groups'][$sub]];
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // แช่ราคาหลังปล่อยล็อกทะเบียนเลข (เหมือน GAS ที่ freeze นอก lock)
        $frozen = 0;
        if ($createdDocs) {
            $frozen = _sigFreezeDocRates($pdo, $createdDocs, _sigRateMap($pdo, $projectId),
                                         $projectId, $ym, $username);
        }
        return ['success' => true, 'created' => $created, 'already' => $already,
                'daysLabel' => $daysLabel, 'resolvedSite' => $resolved, 'frozen' => $frozen];
    } catch (Throwable $e) {
        error_log('issueDeductionDocs error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * freezeDeductionRatesNow({siteCode, ym, username})
 * ล็อกราคาย้อนหลังให้ใบที่ออกเลขไปแล้วแต่ยังไม่มีราคาล็อก (ใบเก่าก่อน v1.9.3)
 */
function rpc_freezeDeductionRatesNow(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_sigCanDaily($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p  = is_array($args[0] ?? null) ? $args[0] : [];
        $ym = trim((string)($p['ym'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) return ['success' => false, 'message' => 'bad_month'];
        $siteCode = trim((string)($p['siteCode'] ?? ''));
        $username = trim((string)$user['username']);

        $sql = "SELECT d.doc_no, d.project_id, d.sub_name, d.days_label
                FROM deduction_docs d JOIN projects p ON p.id = d.project_id
                WHERE d.ym = ?";
        $params = [$ym];
        if ($siteCode !== '') { $sql .= ' AND p.code = ?'; $params[] = $siteCode; }
        $st = $pdo->prepare($sql . ' ORDER BY d.running_no');
        $st->execute($params);
        $docs = $st->fetchAll();
        if (!$docs) return ['success' => true, 'frozen' => 0, 'docs' => 0];

        $already = _sigFrozenRates($pdo, array_column($docs, 'doc_no'));
        $frozen = 0;
        $touched = 0;
        foreach ($docs as $d) {
            if (!empty($already[(string)$d['doc_no']])) continue;   // ใบนี้ล็อกไว้แล้ว
            $daySet = _sigDaySetFromLabel($ym, (string)$d['days_label']);
            $col = _sigCollectGroups($pdo, $siteCode, $ym, $daySet, [(string)$d['sub_name'] => true]);
            $items = $col['groups'][(string)$d['sub_name']] ?? [];
            if (!$items) continue;
            $frozen += _sigFreezeDocRates(
                $pdo, [['docNo' => (string)$d['doc_no'], 'subName' => (string)$d['sub_name'], 'items' => $items]],
                _sigRateMap($pdo, (int)$d['project_id']), (int)$d['project_id'], $ym, $username
            );
            $touched++;
        }
        return ['success' => true, 'frozen' => $frozen, 'docs' => $touched];
    } catch (Throwable $e) {
        error_log('freezeDeductionRatesNow error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/** _signDocDetail_ : รายการ + ยอดของใบหนึ่ง (ใช้ราคาที่ล็อกไว้ก่อนเสมอ) */
function _sigDocDetail(PDO $pdo, array $doc): array {
    $ym       = (string)$doc['ym'];
    $subName  = (string)$doc['sub_name'];
    $daySet   = _sigDaySetFromLabel($ym, (string)$doc['days_label']);
    $col      = _sigCollectGroups($pdo, (string)$doc['project_code'], $ym, $daySet, [$subName => true]);
    $items    = $col['groups'][$subName] ?? [];
    usort($items, function ($a, $b) { return $a['ms'] <=> $b['ms']; });

    $frozen  = _sigFrozenRates($pdo, [(string)$doc['doc_no']])[(string)$doc['doc_no']] ?? [];
    $live    = _sigRateMap($pdo, (int)$doc['project_id']);
    $total   = 0.0;
    $out     = [];
    foreach ($items as $i => $it) {
        $mc    = (string)$it['matCode'];
        $price = array_key_exists($mc, $frozen) ? $frozen[$mc]
               : (array_key_exists($mc, $live) ? $live[$mc] : null);
        $qty   = is_numeric($it['qty']) ? (float)$it['qty'] : null;
        $amt   = ($price !== null && $qty !== null) ? $price * $qty : null;
        if ($amt !== null) $total += $amt;
        $out[] = [
            'no' => $i + 1, 'dateTh' => $it['dateTh'], 'docId' => $it['docId'],
            'matCode' => $mc, 'subgroup' => $it['subgroup'], 'name' => $it['name'],
            'unit' => $it['unit'], 'qty' => $it['qty'], 'price' => $price, 'amount' => $amt,
        ];
    }
    // จับคู่ Mango ของชุดนี้ในโครงการนี้
    $st = $pdo->prepare(
        "SELECT sp.mango_vendor_code, sp.mango_vendor_name
         FROM sub_projects sp JOIN subcontractors s ON s.id = sp.sub_id
         WHERE sp.project_id = ? AND s.name = ? LIMIT 1"
    );
    $st->execute([(int)$doc['project_id'], $subName]);
    $mango = $st->fetch() ?: ['mango_vendor_code' => '', 'mango_vendor_name' => ''];

    return [
        'docNo'      => (string)$doc['doc_no'],
        'subName'    => $subName,
        'ym'         => $ym,
        'monthLabel' => _sigThMonthLabel($ym),
        'days'       => (string)$doc['days_label'],
        'siteCode'   => (string)$doc['project_code'],
        'siteName'   => (string)$doc['project_name'],
        'mangoCode'  => (string)($mango['mango_vendor_code'] ?? ''),
        'mangoName'  => (string)($mango['mango_vendor_name'] ?? ''),
        'items'      => $out,
        'total'      => round($total, 2),
        // ไม่คิด VAT — เอกสารหักเงินค่าวัสดุ ไม่ใช่การขาย (GAS v1.9.3)
        'vat'        => 0,
        'net'        => round($total, 2),
    ];
}

/** getSignDocDetail({docNo, username}) */
function rpc_getSignDocDetail(PDO $pdo, ?array $user, array $args) {
    try {
        if (!$user) return ['success' => false, 'message' => 'no_user'];
        $p     = is_array($args[0] ?? null) ? $args[0] : [];
        $docNo = trim((string)($p['docNo'] ?? ''));
        $doc   = _sigDeductionDoc($pdo, $docNo);
        if (!$doc) return ['success' => false, 'message' => 'ไม่พบเอกสาร ' . $docNo];

        $uname = trim((string)($user['username'] ?? ''));
        $mine  = false;
        foreach (['inspector', 'approver'] as $role) {
            $r = _sigFind($pdo, $docNo, $role);
            if ($r && eqUser((string)($r['assignee'] ?? ''), $uname)) { $mine = true; break; }
        }
        if (!_sigCanDaily($pdo, $user) && !$mine) return ['success' => false, 'message' => 'no_permission'];

        $detail = _sigDocDetail($pdo, $doc);
        $detail['success'] = true;
        foreach (['inspector', 'approver', 'contractor'] as $role) {
            $detail[$role] = _sigRowSummary(_sigFind($pdo, $docNo, $role));
        }
        return $detail;
    } catch (Throwable $e) {
        error_log('getSignDocDetail error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// ลิงก์เซ็นของผู้รับเหมา (role = contractor) — เข้าผ่าน token ไม่ต้องล็อกอิน
// ---------------------------------------------------------------------------

/** createContractorLink({docNo, deadlineDays, username}) — สร้าง/คืนลิงก์ของใบนั้น */
function rpc_createContractorLink(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_sigCanDaily($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p     = is_array($args[0] ?? null) ? $args[0] : [];
        $docNo = trim((string)($p['docNo'] ?? ''));
        if ($docNo === '') return ['success' => false, 'message' => 'no_doc'];

        $deadlineDays = (float)($p['deadlineDays'] ?? 0);
        if (!is_finite($deadlineDays) || $deadlineDays < 0) $deadlineDays = 0;
        if ($deadlineDays > 60) $deadlineDays = 60;   // เพดานเดียวกับ GAS

        $doc = _sigDeductionDoc($pdo, $docNo);
        if (!$doc) return ['success' => false, 'message' => 'ไม่พบเอกสาร ' . $docNo . ' ในทะเบียน'];

        $row = _sigFind($pdo, $docNo, 'contractor');
        if ($row && in_array($row['status'], ['signed', 'auto'], true)) {
            // ตอบกลับไปแล้ว — คืนสถานะเดิม ไม่ออกลิงก์ใหม่
            return ['success' => true, 'contractor' => _sigRowSummary($row), 'existed' => true];
        }
        if ($row) {
            $pdo->prepare(
                "UPDATE sign_requests SET status = 'pending', deadline_days = ?,
                        token = COALESCE(token, ?), requested_by = ?, requested_at = NOW()
                  WHERE id = ?"
            )->execute([$deadlineDays, _sigNewToken(), trim((string)$user['username']), (int)$row['id']]);
        } else {
            $pdo->prepare(
                "INSERT INTO sign_requests
                   (doc_no, project_id, ym, sub_name, days_label, role, token, status,
                    deadline_days, requested_by, requested_at)
                 VALUES (?, ?, ?, ?, ?, 'contractor', ?, 'pending', ?, ?, NOW())"
            )->execute([
                $docNo, (int)$doc['project_id'], (string)$doc['ym'], (string)$doc['sub_name'],
                (string)$doc['days_label'], _sigNewToken(), $deadlineDays, trim((string)$user['username']),
            ]);
        }
        $row = _sigFind($pdo, $docNo, 'contractor');
        return ['success' => true, 'contractor' => _sigRowSummary($row),
                'url' => rtrim(APP_BASE, '/') . '/sign.php?token=' . (string)$row['token']];
    } catch (Throwable $e) {
        error_log('createContractorLink error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/** setContractorSent({docNo, sent, username}) — ทำเครื่องหมาย "ส่งลิงก์แล้ว" = เริ่มนับ deadline */
function rpc_setContractorSent(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_sigCanDaily($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p     = is_array($args[0] ?? null) ? $args[0] : [];
        $docNo = trim((string)($p['docNo'] ?? ''));
        $row   = _sigFind($pdo, $docNo, 'contractor');
        if (!$row) return ['success' => false, 'message' => 'ยังไม่ได้สร้างลิงก์ของใบนี้'];
        if ($row['status'] !== 'pending') {
            return ['success' => false, 'message' => 'ใบนี้มีการตอบกลับแล้ว (' . $row['status'] . ')'];
        }
        $sent = (($p['sent'] ?? true) !== false);
        $pdo->prepare("UPDATE sign_requests SET sent_at = ? WHERE id = ?")
            ->execute([$sent ? date('Y-m-d H:i:s') : null, (int)$row['id']]);
        $row = _sigFind($pdo, $docNo, 'contractor');
        return ['success' => true, 'contractor' => _sigRowSummary($row)];
    } catch (Throwable $e) {
        error_log('setContractorSent error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * getSignPageData(token) — ข้อมูลสำหรับหน้า sign.php (public, ไม่ต้องล็อกอิน)
 * คืนเฉพาะสิ่งที่จำเป็นต่อการแสดงเอกสาร + สถานะการเซ็น (ไม่มีข้อมูลไซต์อื่น)
 */
function rpc_getSignPageData(PDO $pdo, ?array $user, array $args) {
    try {
        $token = trim((string)($args[0] ?? ''));
        if ($token === '' || !preg_match('/^[a-f0-9]{40}$/i', $token)) {
            return ['success' => false, 'message' => 'bad_token'];
        }
        $stmt = $pdo->prepare("SELECT * FROM sign_requests WHERE role = 'contractor' AND token = ? LIMIT 1");
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if (!$row) return ['success' => false, 'message' => 'ไม่พบลิงก์นี้ หรือลิงก์ถูกยกเลิกแล้ว'];
        _sigApplyAutoAck($pdo, $row);

        $doc = _sigDeductionDoc($pdo, (string)$row['doc_no']);
        if (!$doc) return ['success' => false, 'message' => 'ไม่พบเอกสารของลิงก์นี้'];
        $detail = _sigDocDetail($pdo, $doc);

        // ลายเซ็นที่ผูกไว้กับชุด — ผู้รับเหมากด "ยืนยันด้วยลายเซ็นที่ผูกไว้" ได้เลย
        $st = $pdo->prepare(
            "SELECT s.signature_path FROM subcontractors s
               JOIN sub_projects sp ON sp.sub_id = s.id AND sp.project_id = ?
              WHERE s.name = ? LIMIT 1"
        );
        $st->execute([(int)$doc['project_id'], (string)$doc['sub_name']]);
        $boundSig = _sigReadDataUrl($st->fetchColumn() ?: '');

        // คีย์ต้องตรงกับที่ SignPage.html อ่าน: detail / boundSig / status
        return [
            'success'    => true,
            'detail'     => $detail,
            'boundSig'   => $boundSig,
            'status'     => (string)$row['status'],
            'contractor' => _sigRowSummary($row),
            'signerName' => (string)($row['signer_name'] ?? ''),
            'signedAt'   => !empty($row['signed_at']) ? strtotime($row['signed_at']) * 1000 : null,
            'signedData' => _sigReadDataUrl($row['signature_path'] ?? ''),
        ];
    } catch (Throwable $e) {
        error_log('getSignPageData error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * submitContractorSignature({token, dataUrl?, signerName?, signerPos?})
 * ผู้รับเหมากดยืนยันรับทราบ — ไม่ส่ง dataUrl = ใช้ลายเซ็นที่ผูกไว้กับชุด
 */
function rpc_submitContractorSignature(PDO $pdo, ?array $user, array $args) {
    try {
        $p     = is_array($args[0] ?? null) ? $args[0] : [];
        $token = trim((string)($p['token'] ?? ''));
        if ($token === '' || !preg_match('/^[a-f0-9]{40}$/i', $token)) {
            return ['success' => false, 'message' => 'bad_token'];
        }
        $stmt = $pdo->prepare("SELECT * FROM sign_requests WHERE role = 'contractor' AND token = ? LIMIT 1");
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if (!$row) return ['success' => false, 'message' => 'ไม่พบลิงก์นี้ หรือลิงก์ถูกยกเลิกแล้ว'];
        _sigApplyAutoAck($pdo, $row);
        if ($row['status'] !== 'pending') {
            return ['success' => false, 'message' => 'ใบนี้ตอบกลับไปแล้ว (' . $row['status'] . ')'];
        }

        $doc = _sigDeductionDoc($pdo, (string)$row['doc_no']);
        if (!$doc) return ['success' => false, 'message' => 'ไม่พบเอกสารของลิงก์นี้'];

        $rel = null;
        // useBound = true → ใช้ลายเซ็นที่ผูกไว้กับชุด (หน้าเซ็นไม่ส่งรูปขึ้นมา)
        $useBound = (($p['useBound'] ?? false) === true);
        $dataUrl  = $useBound ? '' : (string)($p['dataUrl'] ?? '');
        if ($dataUrl !== '') {
            if (!_sigValidDataUrl($dataUrl)) {
                return ['success' => false, 'message' => 'รูปลายเซ็นไม่ถูกต้องหรือใหญ่เกินไป'];
            }
            $rel = _sigSaveDataUrl($dataUrl, 'sub_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$row['doc_no']));
        } else {
            // ใช้ลายเซ็นที่ผูกไว้กับชุด (คัดลอกเก็บไว้กับคำขอ — เอกสารที่เซ็นแล้วต้องไม่เปลี่ยนตามทีหลัง)
            $st = $pdo->prepare(
                "SELECT s.signature_path FROM subcontractors s
                   JOIN sub_projects sp ON sp.sub_id = s.id AND sp.project_id = ?
                  WHERE s.name = ? LIMIT 1"
            );
            $st->execute([(int)$doc['project_id'], (string)$doc['sub_name']]);
            $bound = _sigReadDataUrl($st->fetchColumn() ?: '');
            if ($bound === '') {
                return ['success' => false,
                        'message' => 'ยังไม่มีลายเซ็นที่ผูกไว้กับชุดนี้ — กรุณาวาดลายเซ็นในช่องด้านล่าง'];
            }
            $rel = _sigSaveDataUrl($bound, 'sub_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$row['doc_no']));
        }
        if ($rel === null) return ['success' => false, 'message' => 'บันทึกไฟล์ลายเซ็นไม่สำเร็จ'];

        $signerName = trim((string)($p['signerName'] ?? '')) ?: (string)$doc['sub_name'];
        $signerPos  = trim((string)($p['signerPos'] ?? '')) ?: 'ผู้รับเหมา';
        $pdo->prepare(
            "UPDATE sign_requests
                SET status = 'signed', signer_name = ?, signer_pos = ?, signature_path = ?, signed_at = NOW()
              WHERE id = ?"
        )->execute([$signerName, $signerPos, $rel, (int)$row['id']]);

        return ['success' => true, 'docNo' => (string)$row['doc_no']];
    } catch (Throwable $e) {
        error_log('submitContractorSignature error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * getDeductionSignData({siteCode, ym, username})
 * ตารางแท็บ "ลายเซ็นออนไลน์": ใบที่ออกเลขแล้วของเดือนนั้น + สถานะลงนาม 3 บทบาท
 * pendingSubs = ชุดที่มีรายการในงวดนั้นแต่ยังไม่ออกเลข (ให้ปุ่ม "ออกเลขเอกสาร")
 */
function rpc_getDeductionSignData(PDO $pdo, ?array $user, array $args) {
    try {
        if (!_sigCanDaily($pdo, $user)) return ['success' => false, 'message' => 'no_permission'];
        $p  = is_array($args[0] ?? null) ? $args[0] : [];
        $ym = trim((string)($p['ym'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) return ['success' => false, 'message' => 'bad_month'];
        $siteCode = trim((string)($p['siteCode'] ?? ''));

        $sql = "SELECT d.doc_no, d.running_no, d.ym, d.sub_name, d.days_label, d.item_count,
                       d.issued_by, d.issued_at, p.code AS site_code
                FROM deduction_docs d JOIN projects p ON p.id = d.project_id
                WHERE d.ym = ?";
        $params = [$ym];
        if ($siteCode !== '') { $sql .= ' AND p.code = ?'; $params[] = $siteCode; }
        $st = $pdo->prepare($sql . ' ORDER BY d.running_no');
        $st->execute($params);

        $docs = [];
        foreach ($st->fetchAll() as $r) {
            $docNo = (string)$r['doc_no'];
            $docs[] = [
                'docNo'      => $docNo,
                'no'         => (int)$r['running_no'],
                'ym'         => (string)$r['ym'],
                'subName'    => (string)$r['sub_name'],
                'days'       => (string)$r['days_label'],
                'itemCount'  => (int)$r['item_count'],
                'siteCode'   => (string)$r['site_code'],
                'issuedBy'   => (string)($r['issued_by'] ?? ''),
                'issuedAt'   => !empty($r['issued_at']) ? strtotime($r['issued_at']) * 1000 : null,
                'contractor' => _sigRowSummary(_sigFind($pdo, $docNo, 'contractor')),
                'inspector'  => _sigRowSummary(_sigFind($pdo, $docNo, 'inspector')),
                'approver'   => _sigRowSummary(_sigFind($pdo, $docNo, 'approver')),
            ];
        }

        // ชุดที่ยังไม่ออกเลข แยกตามงวดครึ่งเดือน
        $issued = [];
        foreach ($docs as $d) { $issued[$d['subName'] . '|' . $d['days']] = true; }
        $pendingSubs = [1 => [], 2 => []];
        $col = _sigCollectGroups($pdo, $siteCode, $ym, null, null);
        foreach ($col['groups'] as $sub => $items) {
            $cnt = [1 => 0, 2 => 0];
            foreach ($items as $it) {
                $d = (int)substr((string)$it['dayKey'], 8, 2);
                if ($d >= 1) $cnt[$d <= 15 ? 1 : 2]++;
            }
            foreach ([1, 2] as $h) {
                if ($cnt[$h] <= 0) continue;
                if (isset($issued[$sub . '|' . _sigHalfLabel($h)])) continue;
                $pendingSubs[$h][] = ['subName' => $sub, 'count' => $cnt[$h]];
            }
        }
        // client ประกอบลิงก์เป็น webAppUrl + '?page=sign&token=…' (โครงเดิมของ GAS)
        // ฝั่ง PHP หน้าเซ็นเป็นไฟล์แยก → ชี้มาที่ sign.php ตรง ๆ (พารามิเตอร์ page ที่ติดมาถูกมองข้าม)
        return ['success' => true, 'docs' => $docs, 'pendingSubs' => $pendingSubs,
                'ym' => $ym, 'monthLabel' => _sigThMonthLabel($ym), 'siteCode' => $siteCode,
                'webAppUrl' => rtrim(APP_BASE, '/') . '/sign.php'];
    } catch (Throwable $e) {
        error_log('getDeductionSignData error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
