<?php
/**
 * CONNEXT — lib/directory.php : ข้อมูลอ้างอิง / directory (port 1:1 จาก Code.js)
 *
 * ครอบคลุมฟังก์ชัน GAS: getUserDirectory, getApproversList, getSubcontractsList,
 * getMaterialsList, getMaterialsMainList, getGatesList, getBalanceList,
 * getMaterialBalance
 *
 * ทุกคีย์ผลลัพธ์ใช้ชื่อเดียวกับหัวคอลัมน์ชีตเดิม (client destructure ตรงชื่อ):
 *   Users        → username / fullName (getUserDirectory คืน lower-key แบบ GAS)
 *   Subcontracts → SubID / SubName / SiteCode / Status / MangoVendorCode / MangoVendorName
 *   SiteMaterials+MaterialsMain → MatCode / GateID / SiteCode / Name / Unit / CatID / CharID / SUBGROUPNAME
 *   Gates        → GateID / GateName / SiteCode
 *   Balance      → MatCode / SiteCode / In / Out / OnHand / Pending + enrich
 */

// ---------------------------------------------------------------------------
// helpers ภายในไฟล์
// ---------------------------------------------------------------------------

/** project id จาก SiteCode ('' → null = ทุกโครงการ ตามพฤติกรรม filter เดิม) */
function _dirProjectIdByCode(PDO $pdo, string $code): ?int {
    $code = trim($code);
    if ($code === '') return null;
    $stmt = $pdo->prepare("SELECT id FROM projects WHERE code = ? LIMIT 1");
    $stmt->execute([$code]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

/**
 * ล้อ getUserRoleLevel_ ของ GAS:
 *   1. users → roles.level
 *   2. subcontractors (GAS เทียบ SubName; PHP เทียบทั้ง sub_code และ name
 *      เพราะ session ฝั่ง PHP ใช้ sub_code เป็น username) → 1
 *   3. ไม่พบ → 99
 * คืน ['level' => int, 'projectId' => ?int]
 */
function _dirUserRoleLevel(PDO $pdo, string $username): array {
    $username = trim($username);
    if ($username === '') return ['level' => 99, 'projectId' => null];

    $stmt = $pdo->prepare(
        "SELECT r.level, u.project_id
         FROM users u JOIN roles r ON r.id = u.role_id
         WHERE u.username = ? LIMIT 1"
    ); // utf8mb4_unicode_ci → case-insensitive เหมือน eqUser_
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if ($row) return ['level' => (int)$row['level'], 'projectId' => (int)$row['project_id']];

    $stmt = $pdo->prepare(
        "SELECT sp.project_id
         FROM subcontractors s
         JOIN sub_projects sp ON sp.sub_id = s.id AND sp.enabled = 1
         WHERE (s.sub_code = ? OR s.name = ?) AND s.status = 'active'
         ORDER BY sp.project_id LIMIT 1"
    );
    $stmt->execute([fmtSubId($username), $username]);
    $pid = $stmt->fetchColumn();
    if ($pid !== false) return ['level' => 1, 'projectId' => (int)$pid];

    return ['level' => 99, 'projectId' => null];
}

// ---------------------------------------------------------------------------
// getUserDirectory() — [{username, fullName}] เฉพาะ active (ทุกโครงการ ตาม GAS)
// ---------------------------------------------------------------------------
function rpc_getUserDirectory(PDO $pdo, ?array $user, array $args) {
    try {
        $stmt = $pdo->query(
            "SELECT username, full_name FROM users WHERE status <> 'inactive' ORDER BY id"
        );
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $username = trim((string)$row['username']);
            if ($username === '') continue;
            $fullName = trim((string)$row['full_name']);
            $out[] = ['username' => $username, 'fullName' => $fullName !== '' ? $fullName : $username];
        }
        return $out;
    } catch (Throwable $e) {
        error_log('getUserDirectory error: ' . $e->getMessage());
        return [];
    }
}

// ---------------------------------------------------------------------------
// getApproversList(submitterUsername)
// ผู้อนุมัติ = user ที่ active, ไม่ใช่ตัวเอง, level > submitter, level >= 4,
// level < 99 (GAS ไม่กรองไซต์; ฝั่ง DB หลายโครงการ → จำกัดที่โครงการเดียวกับ
// ผู้ยื่น) เรียง level น้อย→มาก แล้วชื่อเต็ม
// คีย์: username, fullName, roleId, roleLevel ('R6'), roleName, siteCode
// ---------------------------------------------------------------------------
function rpc_getApproversList(PDO $pdo, ?array $user, array $args) {
    try {
        $submitter = trim((string)($args[0] ?? ''));
        if ($submitter === '') return [];

        $info = _dirUserRoleLevel($pdo, $submitter);
        $submitterLevel = $info['level'];
        if ($submitterLevel >= 99) return []; // unknown submitter

        // โครงการของผู้ยื่น (fallback → session)
        $projectId = $info['projectId'];
        if ($projectId === null && $user) $projectId = (int)$user['projectId'];
        if ($projectId === null) return [];

        $stmt = $pdo->prepare(
            "SELECT u.username, u.full_name, r.role_code, r.level, r.name AS role_name,
                    p.code AS project_code
             FROM users u
             JOIN roles r ON r.id = u.role_id
             JOIN projects p ON p.id = u.project_id
             WHERE u.status <> 'inactive'
               AND u.project_id = ?
               AND u.username <> ?
               AND (r.level > ? OR r.level >= 6)
               AND r.level >= 4
               AND r.level < 99"
        );
        // [2026-10-02 · GP-16] ผู้ส่ง R6 ขึ้นไปที่ไม่ใช่ PM ต้องให้คนอื่นอนุมัติใบเบิกวัสดุหลัก → เลือก R6 ขึ้นไปคนอื่นได้
        //   (เดิมต้องระดับสูงกว่าผู้ส่ง — ผู้ส่งระดับสูงสุดของไซต์จะไม่มีใครให้เลือก)
        $stmt->execute([$projectId, $submitter, $submitterLevel]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $username = trim((string)$row['username']);
            if ($username === '') continue;
            $out[] = [
                'username'  => $username,
                'fullName'  => trim((string)$row['full_name']) !== '' ? (string)$row['full_name'] : $username,
                'roleId'    => (string)$row['role_code'],
                'roleLevel' => 'R' . (int)$row['level'],
                'roleName'  => (string)$row['role_name'],
                'siteCode'  => (string)$row['project_code'],
            ];
        }

        // เรียง: level ต่ำสุดที่ผ่านเกณฑ์ก่อน แล้วชื่อเต็ม (localeCompare 'th' เดิม)
        $collator = class_exists('Collator') ? new Collator('th_TH') : null;
        usort($out, function ($a, $b) use ($collator) {
            $al = (int)preg_replace('/\D/', '', $a['roleLevel']);
            $bl = (int)preg_replace('/\D/', '', $b['roleLevel']);
            if ($al !== $bl) return $al - $bl;
            if ($collator) return $collator->compare($a['fullName'], $b['fullName']);
            return strcmp($a['fullName'], $b['fullName']);
        });

        return $out;
    } catch (Throwable $e) {
        error_log('getApproversList error: ' . $e->getMessage());
        return [];
    }
}

// ---------------------------------------------------------------------------
// getSubcontractsList() — แถวชีต Subcontracts (SubID ผ่าน fmtSubId เสมอ)
// GAS คืนทุกแถวทุกไซต์ (ชีต pilot มีไซต์เดียว); ฝั่ง DB หลายโครงการ →
// จำกัดโครงการของผู้เรียก + เฉพาะ active และไม่ส่งคอลัมน์ password
// ---------------------------------------------------------------------------
function rpc_getSubcontractsList(PDO $pdo, ?array $user, array $args) {
    try {
        $projectId = $user ? (int)$user['projectId'] : 0;
        // ทะเบียนกลาง: "ชุดของโครงการนี้" = แถวใน sub_projects · Status ที่ส่งกลับสะท้อน
        // การเปิดใช้ "ในโครงการนี้" (sp.enabled) ไม่ใช่สถานะบัญชีทั้งระบบ — ตรงเจตนา GAS เดิม
        // ที่ Status บนชีต Subcontracts เป็นของไซต์นั้น ๆ · จับคู่ Mango ก็เป็นรายโครงการแล้ว
        $stmt = $pdo->prepare(
            "SELECT s.sub_code, s.name, s.status, sp.enabled,
                    sp.mango_vendor_code, sp.mango_vendor_name,
                    p.code AS project_code
             FROM subcontractors s
             JOIN sub_projects sp ON sp.sub_id = s.id
             JOIN projects p ON p.id = sp.project_id
             WHERE sp.project_id = ? AND sp.enabled = 1 AND s.status = 'active'
             ORDER BY s.id"
        );
        $stmt->execute([$projectId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'SubID'           => fmtSubId($row['sub_code']),
                'SubName'         => (string)$row['name'],
                'SiteCode'        => (string)$row['project_code'],
                'Status'          => (string)$row['status'],
                'MangoVendorCode' => (string)($row['mango_vendor_code'] ?? ''),
                'MangoVendorName' => (string)($row['mango_vendor_name'] ?? ''),
            ];
        }
        return $out;
    } catch (Throwable $e) {
        error_log('getSubcontractsList error: ' . $e->getMessage());
        return [];
    }
}

// ---------------------------------------------------------------------------
// getMaterialsList(siteCode) — SiteMaterials enrich ด้วย MaterialsMain
// ('' = ทุกไซต์ ตาม filter เดิม `!siteCode || r.SiteCode === siteCode`)
//
// ⚠ คืนเฉพาะรหัส IC (มติ 34 — บังคับทั้งแอปตั้งแต่ 2026-09-23): ดรอปดาวน์ฟอร์มเบิก/
// เบ็ดเตล็ด/ยืม-คืน ใช้ตัวนี้ · รหัส Mango ที่ยังค้างในโครงการ (ยังไม่ผูก IC/ย้ายยอดที่
// setup_master.php) จึงไม่ขึ้นให้เลือก · ฝั่งส่งเอกสารกันซ้ำอีกชั้นที่ _docsIcOnlyError
// ---------------------------------------------------------------------------
function rpc_getMaterialsList(PDO $pdo, ?array $user, array $args) {
    try {
        $siteCode = trim((string)($args[0] ?? ''));
        $sql =
            "SELECT m.mat_code, m.name, m.unit, m.cat_id, m.char_id, m.subgroup_name,
                    p.code AS project_code, g.gate_code
             FROM project_materials pm
             JOIN materials m ON m.id = pm.material_id
             JOIN projects  p ON p.id = pm.project_id
             LEFT JOIN gates g ON g.id = pm.gate_id
             WHERE m.code_type = 'ic'";
        $params = [];
        if ($siteCode !== '') {
            $sql .= " AND p.code = ?";
            $params[] = $siteCode;
        }
        $sql .= " ORDER BY pm.id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $code = trim((string)$row['mat_code']);
            if ($code === '') continue;
            $out[] = [
                'MatCode'      => $code,
                'GateID'       => (string)($row['gate_code'] ?? ''),
                'SiteCode'     => (string)$row['project_code'],
                'Name'         => trim((string)$row['name']) !== '' ? (string)$row['name'] : $code,
                'Unit'         => (string)$row['unit'],
                'CatID'        => (string)$row['cat_id'],
                'CharID'       => (string)($row['char_id'] ?? ''),
                'SUBGROUPNAME' => (string)($row['subgroup_name'] ?? ''),
            ];
        }
        return $out;
    } catch (Throwable $e) {
        error_log('getMaterialsList Error: ' . $e->getMessage());
        return [];
    }
}

// ---------------------------------------------------------------------------
// getMaterialsMainList() — master (คีย์ = หัวชีต MaterialsMain;
// คอลัมน์ 'BRB' เดิมถูก alias เป็น CharID เรียบร้อยตั้งแต่ import)
//
// ⚠ กรองเหลือ code_type='ic' ตั้งแต่มติ 39 — เดิมคืนทั้งตาราง 6,8xx แถว
// ผู้เรียกมีที่เดียวคือดรอปดาวน์ **ฟอร์มรับเข้าคลัง** ในแอปหลัก (index.php
// `loadInboundMaterials`) ซึ่งคอมเมนต์เดิมเขียนไว้ว่า "not filtered by site —
// Inbound brings stock into ANY material" · หลังมติ 33/34 นั่นแปลว่าดรอปดาวน์
// ยังโชว์รหัส Mango ครบทุกตัวและเลือกเข้าสต๊อกได้ — มติ 34 ที่กันด้วยการลบแถว
// `project_materials` คุมแค่ฟอร์มเบิก/เบ็ดเตล็ด/ยืม-คืน ไม่เคยคุมฟอร์มนี้เลย
//
// กรองที่นี่แทนการแก้ index.php เพราะไฟล์นั้น generate จาก GAS ผ่าน
// tools/build_index.py — แก้ตรงนั้นเสี่ยง drift (มติ 15)
//
// ผลที่ตั้งใจ: ฟอร์มรับเข้าคลัง = **ปรับยอดของ IC ที่มีอยู่แล้ว** เท่านั้น
// ของใหม่ต้องออกรหัสผ่านสาย PO → buffer → IcCode ที่ rc.php
// (ยอดจากทางนี้ไม่มีรหัส Mango ต้นทาง จึงไปโผล่ตาราง "ปรับยอด" ใน mango_bal.php)
// ---------------------------------------------------------------------------
function rpc_getMaterialsMainList(PDO $pdo, ?array $user, array $args) {
    $stmt = $pdo->query(
        "SELECT mat_code, name, unit, cat_id, char_id, subgroup_name, item_photo
         FROM materials WHERE code_type = 'ic' ORDER BY id"
    );
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[] = [
            'MatCode'      => (string)$row['mat_code'],
            'Name'         => (string)$row['name'],
            'Unit'         => (string)$row['unit'],
            'CatID'        => (string)$row['cat_id'],
            'CharID'       => (string)($row['char_id'] ?? ''),
            'SUBGROUPNAME' => (string)($row['subgroup_name'] ?? ''),
            'ItemPhoto'    => (string)($row['item_photo'] ?? ''),
        ];
    }
    return $out;
}

// ---------------------------------------------------------------------------
// getGatesList() — ทุกแถวชีต Gates (client กรอง SiteCode เอง)
// คีย์ชีตเดิม: GateID / GateName / SiteCode
// ---------------------------------------------------------------------------
function rpc_getGatesList(PDO $pdo, ?array $user, array $args) {
    $stmt = $pdo->query(
        "SELECT g.gate_code, g.name, p.code AS project_code
         FROM gates g JOIN projects p ON p.id = g.project_id
         WHERE g.status = 'active'
         ORDER BY g.id"
    );
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[] = [
            'GateID'   => (string)$row['gate_code'],
            'GateName' => (string)$row['name'],
            'SiteCode' => (string)$row['project_code'],
        ];
    }
    return $out;
}

// ---------------------------------------------------------------------------
// getBalanceList(siteCode) — แถว Balance enrich ด้วย MaterialsMain +
// GateID จาก SiteMaterials (คีย์ MatCode|SiteCode)  ('' = ทุกไซต์ สำหรับ R0)
// client ใช้: MatCode, Name, Unit, In, Out, OnHand, Pending, CatID, CharID,
// SUBGROUPNAME, GateID, GateName, SiteCode
//
// ⚠ คืนเฉพาะรหัส IC (มติ 34): ตัวนี้เลี้ยงทั้งตาราง Dashboard และยอดที่ฟอร์มเบิกใช้เช็กของพอ
// ยอดที่ยังค้างบนรหัส Mango (ยังไม่ย้ายยอด) Dashboard แจ้งแยกผ่าน getLegacyMangoStock
// ไม่ให้ของหายเงียบ · MatCode ที่คืน = รหัส IC 20 หลัก (ชื่อคีย์คงเดิมเพื่อไม่ให้ client พัง)
// ---------------------------------------------------------------------------
function rpc_getBalanceList(PDO $pdo, ?array $user, array $args) {
    try {
        $siteCode = trim((string)($args[0] ?? ''));
        $sql =
            "SELECT b.qty_in, b.qty_out, b.on_hand, b.pending,
                    m.mat_code, m.name, m.unit, m.cat_id, m.char_id, m.subgroup_name, m.code_type,
                    p.code AS project_code, g.gate_code
             FROM stock_balances b
             JOIN materials m ON m.id = b.material_id
             JOIN projects  p ON p.id = b.project_id
             LEFT JOIN project_materials pm
                    ON pm.project_id = b.project_id AND pm.material_id = b.material_id
             LEFT JOIN gates g ON g.id = pm.gate_id
             WHERE m.code_type = 'ic'";
        $params = [];
        if ($siteCode !== '') {
            $sql .= " AND p.code = ?";
            $params[] = $siteCode;
        }
        $sql .= " ORDER BY b.id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        // ยอดรายประตูของทุกวัสดุในผลลัพธ์ — [PHP port 2026-09-25 per-gate · มติ 51]
        // Dashboard/ฟอร์มใช้แสดง "G01 10 · G03 50" โดยไม่ต้องยิง getGateBalanceList แยก
        $gatesByKey = [];
        $gsql =
            "SELECT p.code AS project_code, m.mat_code, g.gate_code, g.name AS gate_name, gb.on_hand, gb.pending
             FROM stock_gate_balances gb
             JOIN materials m ON m.id = gb.material_id
             JOIN projects  p ON p.id = gb.project_id
             JOIN gates     g ON g.id = gb.gate_id
             WHERE g.status = 'active' AND m.code_type = 'ic' AND (gb.on_hand <> 0 OR gb.pending <> 0)";
        $gparams = [];
        if ($siteCode !== '') {
            $gsql .= " AND p.code = ?";
            $gparams[] = $siteCode;
        }
        $gsql .= " ORDER BY g.gate_code";
        $gst = $pdo->prepare($gsql);
        $gst->execute($gparams);
        foreach ($gst->fetchAll() as $gr) {
            $k = (string)$gr['project_code'] . '|' . trim((string)$gr['mat_code']);
            $gatesByKey[$k][] = [
                'GateID'   => (string)$gr['gate_code'],
                'GateName' => (string)($gr['gate_name'] ?? ''),
                'OnHand'   => (float)$gr['on_hand'],
                'Pending'  => (float)$gr['pending'],
            ];
        }

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $matCode = trim((string)$row['mat_code']);
            if ($matCode === '') continue;
            $gateId = (string)($row['gate_code'] ?? '');
            $gates  = $gatesByKey[(string)$row['project_code'] . '|' . $matCode] ?? [];
            $out[] = [
                'MatCode'      => $matCode,
                'SiteCode'     => (string)$row['project_code'],
                'In'           => (float)$row['qty_in'],
                'Out'          => (float)$row['qty_out'],
                'OnHand'       => (float)$row['on_hand'],
                'Pending'      => (float)$row['pending'],
                'Name'         => trim((string)$row['name']) !== '' ? (string)$row['name'] : $matCode,
                'Unit'         => trim((string)$row['unit']),
                'CatID'        => (string)$row['cat_id'],
                'CharID'       => (string)($row['char_id'] ?? ''),
                'SUBGROUPNAME' => (string)($row['subgroup_name'] ?? ''),
                // เป็น 'ic' ทุกแถวแล้ว (กรองที่ WHERE) — คงคีย์ไว้ให้ client รุ่นเก่าที่ยังอ่านอยู่
                // (เดิมใช้ติดธง "IC" เตือนว่าแปลงยอดกลับเป็นหน่วย Mango ไม่ได้ · มติ 9)
                'CodeType'     => (string)($row['code_type'] ?? 'mango'),
                'GateID'       => $gateId,
                'GateName'     => $gateId, // GAS ตั้ง GateName = gateId เดียวกัน
                // รายประตู (มติ 51): [{GateID, GateName, OnHand, Pending}] เรียงตามรหัสประตู · ว่าง = ยังไม่ลงประตู
                'Gates'        => $gates,
            ];
        }
        return $out;
    } catch (Throwable $e) {
        error_log('getBalanceList Error: ' . $e->getMessage());
        return [];
    }
}

// ---------------------------------------------------------------------------
// getGateBalanceList(siteCode) — ยอดคงเหลือ "รายประตู" ของไซต์
//
// วัสดุตัวเดียวกันอยู่ได้หลายประตู (ของเข้าคนละรอบคนละประตู) ฟอร์มเบิก/เบ็ดเตล็ด/
// ยืม-คืน จึงต้องรู้ว่าประตูไหนมีเท่าไหร่ ก่อนให้ผู้ใช้เลือกประตูที่จะไปรับของ
// คืนทุกแถวรวมที่ยอด 0 ด้วย — ฝั่งหน้าเว็บเป็นคนตัดสินว่าจะซ่อนหรือแสดงเป็นสีจาง
// ---------------------------------------------------------------------------
function rpc_getGateBalanceList(PDO $pdo, ?array $user, array $args) {
    try {
        $siteCode = trim((string)($args[0] ?? ''));
        $sql =
            "SELECT gb.qty_in, gb.qty_out, gb.on_hand, gb.pending,
                    m.mat_code, m.name, m.unit,
                    p.code AS project_code,
                    g.gate_code, g.name AS gate_name
             FROM stock_gate_balances gb
             JOIN materials m ON m.id = gb.material_id
             JOIN projects  p ON p.id = gb.project_id
             JOIN gates     g ON g.id = gb.gate_id
             WHERE g.status = 'active' AND m.code_type = 'ic'";   // เฉพาะรหัส IC (มติ 34)
        $params = [];
        if ($siteCode !== '') {
            $sql .= " AND p.code = ?";
            $params[] = $siteCode;
        }
        $sql .= " ORDER BY m.mat_code, g.gate_code";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $matCode = trim((string)$row['mat_code']);
            if ($matCode === '') { continue; }
            $out[] = [
                'MatCode'  => $matCode,
                'SiteCode' => (string)$row['project_code'],
                'GateID'   => (string)$row['gate_code'],
                'GateName' => (string)($row['gate_name'] ?? ''),
                'In'       => (float)$row['qty_in'],
                'Out'      => (float)$row['qty_out'],
                'OnHand'   => (float)$row['on_hand'],
                'Pending'  => (float)$row['pending'],
                'Name'     => trim((string)$row['name']) !== '' ? (string)$row['name'] : $matCode,
                'Unit'     => trim((string)$row['unit']),
            ];
        }
        return $out;
    } catch (Throwable $e) {
        error_log('getGateBalanceList Error: ' . $e->getMessage());
        return [];
    }
}

// ---------------------------------------------------------------------------
// getMaterialBalance(matCode, siteCode) — ยอดเดี่ยวแบบเร็วสำหรับ qty modal
// รวมทุกแถวที่ตรง (GAS ลูปทั้งชีต += ได้หลายไซต์เมื่อ siteCode ว่าง)
// คืน { onHand, pending, found } — error → { ..., error: true }
// ---------------------------------------------------------------------------
function rpc_getMaterialBalance(PDO $pdo, ?array $user, array $args) {
    try {
        $matCode  = trim((string)($args[0] ?? ''));
        $siteCode = trim((string)($args[1] ?? ''));
        // args[2] = รหัสประตู (มติ 51) — ระบุแล้วคืนยอด "ของประตูนั้น" แทนยอดรวมไซต์
        $gateCode = strtoupper(trim((string)($args[2] ?? '')));
        if ($matCode === '') return ['onHand' => 0, 'pending' => 0, 'found' => false];

        if ($gateCode !== '') {
            $sql =
                "SELECT gb.on_hand, gb.pending
                 FROM stock_gate_balances gb
                 JOIN materials m ON m.id = gb.material_id
                 JOIN projects  p ON p.id = gb.project_id
                 JOIN gates     g ON g.id = gb.gate_id
                 WHERE m.mat_code = ? AND m.code_type = 'ic' AND g.gate_code = ?";
            $params = [$matCode, $gateCode];
        } else {
            $sql =
                "SELECT b.on_hand, b.pending
                 FROM stock_balances b
                 JOIN materials m ON m.id = b.material_id
                 JOIN projects  p ON p.id = b.project_id
                 WHERE m.mat_code = ? AND m.code_type = 'ic'";   // รหัส Mango = found:false (มติ 34)
            $params = [$matCode];
        }
        if ($siteCode !== '') {
            $sql .= " AND p.code = ?";
            $params[] = $siteCode;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $onHand = 0.0; $pending = 0.0; $found = false;
        foreach ($stmt->fetchAll() as $row) {
            $onHand  += (float)$row['on_hand'];
            $pending += (float)$row['pending'];
            $found = true;
        }
        return ['onHand' => $onHand, 'pending' => $pending, 'found' => $found, 'gate' => $gateCode];
    } catch (Throwable $e) {
        error_log('getMaterialBalance Error: ' . $e->getMessage());
        return ['onHand' => 0, 'pending' => 0, 'found' => false, 'error' => true];
    }
}

// ---------------------------------------------------------------------------
// getLegacyMangoStock(siteCode) — รหัส Mango ที่ยังมียอด/ยอดจองค้าง (ยังไม่ย้ายเป็นรหัส IC)
// ไม่มีใน GAS — เพิ่มพร้อมการกรองรายการยอดให้เหลือเฉพาะรหัส IC (มติ 34 · 2026-09-23)
//
// หลังกรองแล้วยอดพวกนี้ไม่โผล่ที่ไหนในแอปหลักเลย Dashboard จึงเรียกตัวนี้ไปขึ้นแถบเตือน
// ว่ายังค้างอยู่กี่รายการ ไม่ให้ของหายเงียบ · ทางแก้คือหน้า setup_master.php (ADM)
// ผูกรหัส IC แล้วย้ายยอด · แถวที่ยอดเป็น 0 ทั้งคู่ไม่นับ (ไม่มีของให้หาย)
// คืน { count, items: [{ MatCode, Name, Unit, OnHand, Pending, SiteCode }] }
// ---------------------------------------------------------------------------
function rpc_getLegacyMangoStock(PDO $pdo, ?array $user, array $args) {
    try {
        $siteCode = trim((string)($args[0] ?? ''));
        $sql =
            "SELECT m.mat_code, m.name, m.unit, b.on_hand, b.pending, p.code AS project_code
             FROM stock_balances b
             JOIN materials m ON m.id = b.material_id
             JOIN projects  p ON p.id = b.project_id
             WHERE m.code_type <> 'ic' AND (b.on_hand <> 0 OR b.pending <> 0)";
        $params = [];
        if ($siteCode !== '') {
            $sql .= " AND p.code = ?";
            $params[] = $siteCode;
        }
        $sql .= " ORDER BY p.code, m.mat_code";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $matCode = trim((string)$row['mat_code']);
            if ($matCode === '') { continue; }
            $items[] = [
                'MatCode'  => $matCode,
                'Name'     => trim((string)$row['name']) !== '' ? (string)$row['name'] : $matCode,
                'Unit'     => trim((string)$row['unit']),
                'OnHand'   => (float)$row['on_hand'],
                'Pending'  => (float)$row['pending'],
                'SiteCode' => (string)$row['project_code'],
            ];
        }
        return ['count' => count($items), 'items' => $items];
    } catch (Throwable $e) {
        error_log('getLegacyMangoStock Error: ' . $e->getMessage());
        return ['count' => 0, 'items' => []];
    }
}
