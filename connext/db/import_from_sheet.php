<?php
/**
 * CONNEXT — db/import_from_sheet.php
 * นำเข้าข้อมูลจริงจาก Google Sheet (export เป็น CSV ใน db/source_data/) สู่ schema ใหม่
 *
 * วิธีใช้ (CLI เท่านั้น):
 *   php db/import_from_sheet.php            — นำเข้า (ปฏิเสธถ้าตาราง documents มีข้อมูลแล้ว)
 *   php db/import_from_sheet.php --fresh    — ล้างข้อมูลทุกตารางก่อน แล้วนำเข้าใหม่
 *
 * PHP 7.4-compatible เท่านั้น
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("CLI only.\n");
}

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/stock.php';   // stockAllocateUnassigned() — ขั้น 10b
if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "ERROR: config.php did not provide \$pdo — ติดตั้งระบบ (settings/) ให้เรียบร้อยก่อน\n");
    exit(1);
}

$FRESH   = in_array('--fresh', $argv, true);
$SRC_DIR = __DIR__ . '/source_data';

if (!is_dir($SRC_DIR)) {
    fwrite(STDERR, "ERROR: ไม่พบโฟลเดอร์ $SRC_DIR\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Global state: counters + warnings + current-row context (for error report)
// ---------------------------------------------------------------------------
$GLOBALS['IMPORT_COUNTS']   = [];   // table => rows imported
$GLOBALS['IMPORT_WARNINGS'] = [];
$GLOBALS['IMPORT_CTX']      = '';   // "file:line — row json" of the row being processed

function ctxSet(string $file, int $line, array $row): void {
    $GLOBALS['IMPORT_CTX'] = $file . ' line ' . $line . ' — ' . json_encode($row, JSON_UNESCAPED_UNICODE);
}
function warn(string $msg): void {
    $GLOBALS['IMPORT_WARNINGS'][] = $msg;
    echo "  [WARN] $msg\n";
}
function bump(string $table, int $n = 1): void {
    $GLOBALS['IMPORT_COUNTS'][$table] = ($GLOBALS['IMPORT_COUNTS'][$table] ?? 0) + $n;
}
/** '' → null (สำหรับคอลัมน์ nullable) */
function nn(string $s): ?string { return $s === '' ? null : $s; }
/** parse ตัวเลขจากชีต (อาจมี comma) */
function numVal(string $s): float {
    $s = str_replace(',', '', trim($s));
    return $s === '' || !is_numeric($s) ? 0.0 : (float)$s;
}
/** DateTime → 'Y-m-d H:i:s' หรือ null */
function dtSql(?DateTime $dt): ?string { return $dt ? $dt->format('Y-m-d H:i:s') : null; }

// ---------------------------------------------------------------------------
// Streaming CSV reader — generator ให้ [lineNo, assocRow]
// header: quoted CSV, อาจมี trailing empty columns + BOM
// ---------------------------------------------------------------------------
function csvRows(string $path) {
    $fh = fopen($path, 'r');
    if ($fh === false) {
        throw new RuntimeException("เปิดไฟล์ไม่ได้: $path");
    }
    try {
        $header = fgetcsv($fh, 0, ',', '"');
        if ($header === false || $header === null) {
            return; // ไฟล์ว่าง
        }
        // strip UTF-8 BOM ที่คอลัมน์แรก
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        }
        // map ชื่อคอลัมน์ (ไม่ว่าง, ตัวแรกชนะ) → index
        $map = [];
        foreach ($header as $i => $name) {
            $name = trim((string)$name);
            if ($name !== '' && !isset($map[$name])) {
                $map[$name] = $i;
            }
        }
        $lineNo = 1;
        while (($row = fgetcsv($fh, 0, ',', '"')) !== false) {
            $lineNo++;
            if ($row === null) break;
            // ข้ามบรรทัดว่างสนิท
            $allEmpty = true;
            foreach ($row as $v) { if (trim((string)$v) !== '') { $allEmpty = false; break; } }
            if ($allEmpty) continue;
            $assoc = [];
            foreach ($map as $name => $i) {
                $assoc[$name] = isset($row[$i]) ? trim((string)$row[$i]) : '';
            }
            yield [$lineNo, $assoc];
        }
    } finally {
        fclose($fh);
    }
}

function srcPath(string $file): ?string {
    global $SRC_DIR;
    $p = $SRC_DIR . '/' . $file;
    if (!is_file($p)) {
        warn("ไม่พบไฟล์ $file — ข้ามขั้นตอนนี้");
        return null;
    }
    return $p;
}

// ---------------------------------------------------------------------------
// Lookup caches / get-or-create helpers
// ---------------------------------------------------------------------------
$PROJECTS  = []; // code(lower) => ['id'=>, 'code'=>]
$ROLES     = []; // role_code(lower) => id
$MATERIALS = []; // mat_code => ['id'=>, 'name'=>, 'unit'=>]
$GATES     = []; // project_id . '|' . gate_code => id
$SUB_BY_NAME = []; // project_id . '|' . lower(name) => id  (คนแรกชนะ)

/** โครงการตาม code — ถ้าไม่มีให้สร้าง (ชื่อ = code) + เตือน; '' → null */
function projectId(string $code, bool $warnOnCreate = true): ?int {
    global $pdo, $PROJECTS;
    $code = trim($code);
    if ($code === '') return null;
    $key = mb_strtolower($code, 'UTF-8');
    if (isset($PROJECTS[$key])) return $PROJECTS[$key]['id'];
    // สร้างใหม่
    $name = ($code === '0000') ? 'ส่วนกลาง (ไม่ระบุโครงการ)' : $code;
    $st = $pdo->prepare("INSERT INTO projects (code, name) VALUES (?, ?)");
    $st->execute([$code, $name]);
    $id = (int)$pdo->lastInsertId();
    $PROJECTS[$key] = ['id' => $id, 'code' => $code];
    bump('projects');
    if ($warnOnCreate) {
        warn("SiteCode '$code' ถูกอ้างถึงแต่ไม่มีใน Sites.csv — สร้างโครงการใหม่ (name = code)");
    }
    return $id;
}

/** วัสดุตาม MatCode → info หรือ null */
function materialInfo(string $matCode): ?array {
    global $MATERIALS;
    $matCode = trim($matCode);
    if ($matCode === '') return null;
    return $MATERIALS[$matCode] ?? null;
}

/** สร้างวัสดุ stub (ไม่พบใน MaterialsMain) */
function stubMaterial(string $matCode, string $context): int {
    global $pdo, $MATERIALS;
    $matCode = trim($matCode);
    $st = $pdo->prepare("INSERT INTO materials (mat_code, name, unit, cat_id) VALUES (?, ?, '', 'C02')");
    $st->execute([$matCode, $matCode]);
    $id = (int)$pdo->lastInsertId();
    $MATERIALS[$matCode] = ['id' => $id, 'name' => $matCode, 'unit' => ''];
    bump('materials');
    warn("MatCode '$matCode' ($context) ไม่พบใน MaterialsMain — สร้าง stub material");
    return $id;
}

/** ประตูตาม (project, gate_code) — สร้างอัตโนมัติถ้าไม่มี (hardware_close ตามกฎ G01) */
function gateId(?int $projectId, string $gateCode, string $context = ''): ?int {
    global $pdo, $GATES;
    $gateCode = trim($gateCode);
    if ($gateCode === '' || $projectId === null) return null;
    $key = $projectId . '|' . $gateCode;
    if (isset($GATES[$key])) return $GATES[$key];
    $hw = ($gateCode === 'G01') ? 1 : 0;
    $st = $pdo->prepare("INSERT INTO gates (gate_code, name, project_id, hardware_close) VALUES (?, ?, ?, ?)");
    $st->execute([$gateCode, $gateCode, $projectId, $hw]);
    $id = (int)$pdo->lastInsertId();
    $GATES[$key] = $id;
    bump('gates');
    warn("GateID '$gateCode' (project_id=$projectId" . ($context !== '' ? ", $context" : '') . ") ไม่มีใน Gates.csv — สร้างให้อัตโนมัติ");
    return $id;
}

/** resolve subcontractor id จากชื่อ (case-insensitive, ภายในโครงการ) */
function subIdByName(?int $projectId, string $name): ?int {
    global $SUB_BY_NAME;
    if ($projectId === null) return null;
    $name = trim($name);
    if ($name === '') return null;
    $key = $projectId . '|' . mb_strtolower($name, 'UTF-8');
    return $SUB_BY_NAME[$key] ?? null;
}

/** สถานะ 'active'/'inactive' ตามกฎ: 'inactive' คงไว้, อื่น ๆ / ว่าง = active */
function normStatus(string $s): string {
    return (mb_strtolower(trim($s), 'UTF-8') === 'inactive') ? 'inactive' : 'active';
}

/** map สถานะเอกสาร → สตริง canonical (case-insensitive); ไม่รู้จัก → verbatim + warn */
function canonDocStatus(string $s, string $context): string {
    static $canon = null;
    if ($canon === null) {
        $canon = [];
        foreach ([Doc::ST_AWAITING, Doc::ST_APPROVED, Doc::ST_COMPLETED, Doc::ST_CANCELLED,
                  Doc::ST_REJECTED, Doc::ST_SENT_BORROW, Doc::ST_BORROWED,
                  Doc::ST_SENT_RETURN, Doc::ST_RETURNED, Doc::ST_SENT_INBOUND] as $c) {
            $canon[mb_strtolower($c, 'UTF-8')] = $c;
        }
    }
    $s = trim($s);
    $key = mb_strtolower($s, 'UTF-8');
    if (isset($canon[$key])) return $canon[$key];
    warn("สถานะเอกสารไม่รู้จัก '$s' ($context) — เก็บ verbatim");
    return $s;
}

// ===========================================================================
// เริ่มทำงาน
// ===========================================================================
echo "CONNEXT import_from_sheet — " . date('Y-m-d H:i:s') . ($FRESH ? " (--fresh)" : "") . "\n";
echo str_repeat('=', 70) . "\n";

// ตารางที่สคริปต์นี้เป็นเจ้าของข้อมูล (ลำดับลบแบบ FK-safe: ลูกก่อนแม่)
$ALL_TABLES = [
    'remember_tokens', 'login_attempts', 'activity_log', 'error_logs',
    'sub_mango_map', 'mango_vendors', 'deduction_docs', 'daily_check_confirms',
    'rate_cards', 'doc_counters', 'gate_logs', 'document_items', 'documents',
    'stock_gate_balances', 'stock_balances', 'project_materials', 'subcontractors', 'users',
    'gates', 'materials', 'roles', 'projects',
];

if (!$FRESH) {
    $n = (int)$pdo->query("SELECT COUNT(*) FROM documents")->fetchColumn();
    if ($n > 0) {
        fwrite(STDERR, "ERROR: ตาราง documents มีข้อมูลอยู่แล้ว ($n แถว) — กัน import ซ้ำ\n");
        fwrite(STDERR, "ถ้าต้องการล้างแล้วนำเข้าใหม่ ให้รัน: php db/import_from_sheet.php --fresh\n");
        exit(1);
    }
}

$pdo->beginTransaction();
try {
    // -----------------------------------------------------------------
    // --fresh: ล้างข้อมูลทุกตาราง (DELETE ใน transaction; FK checks off)
    // -----------------------------------------------------------------
    if ($FRESH) {
        echo "[0] --fresh: ล้างข้อมูลเดิมทุกตาราง...\n";
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        foreach ($ALL_TABLES as $t) {
            $pdo->exec("DELETE FROM `$t`");
        }
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    }

    // preload ของเดิมใน DB (กรณีไม่ --fresh: installer อาจ seed โครงการ/แอดมินไว้)
    foreach ($pdo->query("SELECT id, code FROM projects") as $r) {
        $PROJECTS[mb_strtolower($r['code'], 'UTF-8')] = ['id' => (int)$r['id'], 'code' => $r['code']];
    }
    foreach ($pdo->query("SELECT id, role_code FROM roles") as $r) {
        $ROLES[mb_strtolower($r['role_code'], 'UTF-8')] = (int)$r['id'];
    }
    foreach ($pdo->query("SELECT id, mat_code, name, unit FROM materials") as $r) {
        $MATERIALS[$r['mat_code']] = ['id' => (int)$r['id'], 'name' => $r['name'], 'unit' => $r['unit']];
    }
    foreach ($pdo->query("SELECT id, gate_code, project_id FROM gates") as $r) {
        $GATES[$r['project_id'] . '|' . $r['gate_code']] = (int)$r['id'];
    }
    $existingUsers = []; // username(lower) => true
    foreach ($pdo->query("SELECT username FROM users") as $r) {
        $existingUsers[mb_strtolower($r['username'], 'UTF-8')] = true;
    }

    // -----------------------------------------------------------------
    // 1) Sites.csv → projects (+ ensure '0000')
    // -----------------------------------------------------------------
    echo "[1] Sites.csv → projects\n";
    if (($p = srcPath('Sites.csv')) !== null) {
        $ins = $pdo->prepare("INSERT INTO projects (code, name, site_ref) VALUES (?, ?, ?)");
        foreach (csvRows($p) as [$ln, $row]) {
            ctxSet('Sites.csv', $ln, $row);
            $code = $row['SiteCode'] ?? '';
            if ($code === '') { warn("Sites.csv line $ln: SiteCode ว่าง — ข้าม"); continue; }
            $key = mb_strtolower($code, 'UTF-8');
            if (isset($PROJECTS[$key])) {
                warn("Sites.csv line $ln: SiteCode '$code' ซ้ำ/มีอยู่แล้ว — ใช้แถวแรก");
                continue;
            }
            $name = ($row['SiteName'] ?? '') !== '' ? $row['SiteName'] : $code;
            $ins->execute([$code, $name, nn($row['SiteID'] ?? '')]);
            $PROJECTS[$key] = ['id' => (int)$pdo->lastInsertId(), 'code' => $code];
            bump('projects');
        }
    }
    // โครงการพิเศษ '0000' (Users.csv อ้างถึง) — projectId() สร้างพร้อมชื่อที่ถูกต้อง
    if (!isset($PROJECTS['0000'])) {
        projectId('0000', false);
        echo "  สร้างโครงการพิเศษ '0000' (ส่วนกลาง)\n";
    }

    // -----------------------------------------------------------------
    // 2) Roles.csv → roles
    // -----------------------------------------------------------------
    echo "[2] Roles.csv → roles\n";
    if (($p = srcPath('Roles.csv')) !== null) {
        $ins = $pdo->prepare("INSERT INTO roles (role_code, name, level, can_req, can_daily_check) VALUES (?, ?, ?, ?, ?)");
        foreach (csvRows($p) as [$ln, $row]) {
            ctxSet('Roles.csv', $ln, $row);
            $code = $row['RoleID'] ?? '';
            if ($code === '') { warn("Roles.csv line $ln: RoleID ว่าง — ข้าม"); continue; }
            $key = mb_strtolower($code, 'UTF-8');
            if (isset($ROLES[$key])) {
                warn("Roles.csv line $ln: RoleID '$code' ซ้ำ/มีอยู่แล้ว — ใช้แถวแรก");
                continue;
            }
            // 'R6' → 6 (ดึงเฉพาะตัวเลข)
            $level = 1;
            if (preg_match('/(\d+)/', (string)($row['RoleLevel'] ?? ''), $m)) {
                $level = (int)$m[1];
            }
            $ins->execute([
                $code,
                ($row['RoleName'] ?? '') !== '' ? $row['RoleName'] : $code,
                $level,
                isTrueFlag($row['CanReq'] ?? '') ? 1 : 0,
                isTrueFlag($row['CanDailyCheck'] ?? '') ? 1 : 0,
            ]);
            $ROLES[$key] = (int)$pdo->lastInsertId();
            bump('roles');
        }
    }

    // -----------------------------------------------------------------
    // 3) Users.csv → users
    // -----------------------------------------------------------------
    echo "[3] Users.csv → users (bcrypt — อาจใช้เวลาสักครู่)\n";
    if (($p = srcPath('Users.csv')) !== null) {
        $ins = $pdo->prepare(
            "INSERT INTO users (username, password, emp_code, full_name, role_id, project_id, card_id, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach (csvRows($p) as [$ln, $row]) {
            ctxSet('Users.csv', $ln, $row);
            $username = $row['Username'] ?? '';
            if ($username === '') { warn("Users.csv line $ln: Username ว่าง — ข้าม"); continue; }
            $ukey = mb_strtolower($username, 'UTF-8');
            if (isset($existingUsers[$ukey])) {
                warn("Users.csv line $ln: username '$username' ซ้ำ — ใช้แถวแรก");
                continue;
            }
            $roleCode = $row['RoleID'] ?? '';
            $rkey = mb_strtolower($roleCode, 'UTF-8');
            if (!isset($ROLES[$rkey])) {
                // role หาย → สร้าง placeholder level 1
                $stR = $pdo->prepare("INSERT INTO roles (role_code, name, level) VALUES (?, ?, 1)");
                $rc = $roleCode !== '' ? $roleCode : 'UNKNOWN';
                $stR->execute([$rc, $rc]);
                $ROLES[$rkey] = (int)$pdo->lastInsertId();
                bump('roles');
                warn("Users.csv line $ln: RoleID '$roleCode' ไม่พบใน Roles.csv — สร้าง placeholder (level 1)");
            }
            $projId = projectId($row['SiteCode'] ?? '');
            if ($projId === null) {
                $projId = projectId('0000', false);
                warn("Users.csv line $ln: SiteCode ว่าง — ใช้โครงการ '0000'");
            }
            $ins->execute([
                $username,
                password_hash((string)($row['Password'] ?? ''), PASSWORD_BCRYPT),
                nn($row['UserID'] ?? ''),
                $row['FullName'] ?? '',
                $ROLES[$rkey],
                $projId,
                nn($row['CardID'] ?? ''),
                normStatus($row['Status'] ?? ''),
            ]);
            $existingUsers[$ukey] = true;
            bump('users');
        }
    }

    // -----------------------------------------------------------------
    // 4) Subcontracts.csv → subcontractors
    // -----------------------------------------------------------------
    echo "[4] Subcontracts.csv → subcontractors (bcrypt — อาจใช้เวลาสักครู่)\n";
    if (($p = srcPath('Subcontracts.csv')) !== null) {
        // ทะเบียนกลาง: ชุดอยู่แถวเดียวทั้งระบบ · การผูกโครงการ + Mango ไปอยู่ sub_projects
        $ins = $pdo->prepare(
            "INSERT INTO subcontractors (sub_code, password, name, status)
             VALUES (?, ?, ?, ?)"
        );
        $insLink = $pdo->prepare(
            "INSERT INTO sub_projects (sub_id, project_id, enabled, mango_vendor_code, mango_vendor_name)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled),
                                     mango_vendor_code = VALUES(mango_vendor_code),
                                     mango_vendor_name = VALUES(mango_vendor_name)"
        );
        $subIdByCode = []; // sub_code → subcontractors.id (ชุดเดียวกันข้ามไซต์ = แถวเดียว)
        $seen = []; // sub_code|project_id
        foreach (csvRows($p) as [$ln, $row]) {
            ctxSet('Subcontracts.csv', $ln, $row);
            $subCode = fmtSubId($row['SubID'] ?? '');
            $name = $row['SubName'] ?? '';
            if ($subCode === '') { warn("Subcontracts.csv line $ln: SubID ว่าง — ข้าม"); continue; }
            $projId = projectId($row['SiteCode'] ?? '');
            if ($projId === null) { warn("Subcontracts.csv line $ln: SiteCode ว่าง — ข้าม"); continue; }
            $k = $subCode . '|' . $projId;
            if (isset($seen[$k])) {
                warn("Subcontracts.csv line $ln: SubID '$subCode' ซ้ำในโครงการเดียวกัน — ใช้แถวแรก");
                continue;
            }
            $status = normStatus($row['Status'] ?? '');
            if (isset($subIdByCode[$subCode])) {
                // ชุดนี้มีในทะเบียนแล้ว (มาจากไซต์ก่อนหน้า) — เพิ่มแค่ความผูกพันโครงการใหม่
                $id = $subIdByCode[$subCode];
            } else {
                $ins->execute([
                    $subCode,
                    password_hash((string)($row['Password'] ?? ''), PASSWORD_BCRYPT),
                    $name,
                    $status,
                ]);
                $id = (int)$pdo->lastInsertId();
                $subIdByCode[$subCode] = $id;
            }
            $insLink->execute([
                $id,
                $projId,
                $status === 'active' ? 1 : 0,
                nn($row['MangoVendorCode'] ?? ''),
                nn($row['MangoVendorName'] ?? ''),
            ]);
            $seen[$k] = true;
            $nameKey = $projId . '|' . mb_strtolower(trim($name), 'UTF-8');
            if (!isset($SUB_BY_NAME[$nameKey])) {  // ชื่อซ้ำ: คนแรกชนะ
                $SUB_BY_NAME[$nameKey] = $id;
            }
            bump('subcontractors');
        }
    }

    // -----------------------------------------------------------------
    // 5) MaterialsMain.csv → materials (batched 500)
    // -----------------------------------------------------------------
    echo "[5] MaterialsMain.csv → materials (batched)\n";
    if (($p = srcPath('MaterialsMain.csv')) !== null) {
        $batch = [];
        $flush = function () use ($pdo, &$batch) {
            if (!$batch) return;
            $ph = rtrim(str_repeat('(?,?,?,?,?,?,?),', count($batch)), ',');
            $vals = [];
            foreach ($batch as $b) { foreach ($b as $v) { $vals[] = $v; } }
            $pdo->prepare(
                "INSERT INTO materials (mat_code, name, unit, cat_id, char_id, subgroup_name, item_photo) VALUES $ph"
            )->execute($vals);
            bump('materials', count($batch));
            $batch = [];
        };
        // batch แล้ว lastInsertId ใช้ไม่ได้รายแถว — เก็บ mat_code ไว้ resolve id ทีหลัง
        $pendingCodes = [];
        foreach (csvRows($p) as [$ln, $row]) {
            ctxSet('MaterialsMain.csv', $ln, $row);
            $matCode = $row['MatCode'] ?? '';
            if ($matCode === '') { warn("MaterialsMain.csv line $ln: MatCode ว่าง — ข้าม"); continue; }
            if (isset($MATERIALS[$matCode]) || isset($pendingCodes[$matCode])) {
                warn("MaterialsMain.csv line $ln: MatCode '$matCode' ซ้ำ — ใช้แถวแรก");
                continue;
            }
            // header CharID อาจชื่อ 'CharID' หรือ 'BRB'
            $charId = $row['CharID'] ?? ($row['BRB'] ?? '');
            $catId  = ($row['CatID'] ?? '') !== '' ? $row['CatID'] : 'C02';
            $batch[] = [
                $matCode,
                $row['Name'] ?? '',
                $row['Unit'] ?? '',
                $catId,
                nn($charId),
                nn($row['SUBGROUPNAME'] ?? ''),
                nn($row['ItemPhoto'] ?? ''),
            ];
            $pendingCodes[$matCode] = true;
            if (count($batch) >= 500) $flush();
        }
        $flush();
        // reload map materials (id + snapshot name/unit)
        $MATERIALS = [];
        foreach ($pdo->query("SELECT id, mat_code, name, unit FROM materials") as $r) {
            $MATERIALS[$r['mat_code']] = ['id' => (int)$r['id'], 'name' => $r['name'], 'unit' => $r['unit']];
        }
    }

    // -----------------------------------------------------------------
    // 6) MangoVendors.csv → mango_vendors (batched 500)
    // -----------------------------------------------------------------
    echo "[6] MangoVendors.csv → mango_vendors (batched)\n";
    if (($p = srcPath('MangoVendors.csv')) !== null) {
        $batch = [];
        $seen = [];
        $flush = function () use ($pdo, &$batch) {
            if (!$batch) return;
            $ph = rtrim(str_repeat('(?,?),', count($batch)), ',');
            $vals = [];
            foreach ($batch as $b) { foreach ($b as $v) { $vals[] = $v; } }
            $pdo->prepare("INSERT INTO mango_vendors (vendor_code, vendor_name) VALUES $ph")->execute($vals);
            bump('mango_vendors', count($batch));
            $batch = [];
        };
        foreach ($pdo->query("SELECT vendor_code FROM mango_vendors") as $r) { $seen[$r['vendor_code']] = true; }
        foreach (csvRows($p) as [$ln, $row]) {
            ctxSet('MangoVendors.csv', $ln, $row);
            $code = $row['MangoVendorCode'] ?? '';
            if ($code === '') { warn("MangoVendors.csv line $ln: MangoVendorCode ว่าง — ข้าม"); continue; }
            if (isset($seen[$code])) {
                warn("MangoVendors.csv line $ln: MangoVendorCode '$code' ซ้ำ — ใช้แถวแรก");
                continue;
            }
            $batch[] = [$code, $row['MangoVendorName'] ?? ''];
            $seen[$code] = true;
            if (count($batch) >= 500) $flush();
        }
        $flush();
    }

    // -----------------------------------------------------------------
    // 7) SubMangoMap.csv → sub_mango_map (dedupe)
    // -----------------------------------------------------------------
    echo "[7] SubMangoMap.csv → sub_mango_map\n";
    if (($p = srcPath('SubMangoMap.csv')) !== null) {
        $ins = $pdo->prepare("INSERT INTO sub_mango_map (sub_code, vendor_code, vendor_name) VALUES (?, ?, ?)");
        $seen = [];
        foreach (csvRows($p) as [$ln, $row]) {
            ctxSet('SubMangoMap.csv', $ln, $row);
            $subCode = fmtSubId($row['SubID'] ?? '');
            $vCode   = $row['MangoVendorCode'] ?? '';
            if ($subCode === '' || $vCode === '') { warn("SubMangoMap.csv line $ln: SubID/MangoVendorCode ว่าง — ข้าม"); continue; }
            $k = $subCode . '|' . $vCode;
            if (isset($seen[$k])) {
                warn("SubMangoMap.csv line $ln: คู่ ($subCode, $vCode) ซ้ำ — ข้าม");
                continue;
            }
            $ins->execute([$subCode, $vCode, $row['MangoVendorName'] ?? '']);
            $seen[$k] = true;
            bump('sub_mango_map');
        }
    }

    // -----------------------------------------------------------------
    // 8) Gates.csv → gates (hardware_close = 1 เฉพาะ G01 — HARDWARE_CLOSE_GATES)
    // -----------------------------------------------------------------
    echo "[8] Gates.csv → gates\n";
    if (($p = srcPath('Gates.csv')) !== null) {
        $ins = $pdo->prepare("INSERT INTO gates (gate_code, name, project_id, hardware_close) VALUES (?, ?, ?, ?)");
        foreach (csvRows($p) as [$ln, $row]) {
            ctxSet('Gates.csv', $ln, $row);
            $gateCode = $row['GateID'] ?? '';
            if ($gateCode === '') { warn("Gates.csv line $ln: GateID ว่าง — ข้าม"); continue; }
            $projId = projectId($row['SiteCode'] ?? '');
            if ($projId === null) { warn("Gates.csv line $ln: SiteCode ว่าง — ข้าม"); continue; }
            $k = $projId . '|' . $gateCode;
            if (isset($GATES[$k])) {
                warn("Gates.csv line $ln: Gate ($gateCode, project_id=$projId) ซ้ำ/มีอยู่แล้ว — ใช้แถวแรก");
                continue;
            }
            $hw = ($gateCode === 'G01') ? 1 : 0; // mirror const HARDWARE_CLOSE_GATES = ['G01']
            $ins->execute([$gateCode, $row['GateName'] ?? '', $projId, $hw]); // Description: ไม่ใช้
            $GATES[$k] = (int)$pdo->lastInsertId();
            bump('gates');
        }
    }

    // -----------------------------------------------------------------
    // 9) SiteMaterials.csv → project_materials
    // -----------------------------------------------------------------
    echo "[9] SiteMaterials.csv → project_materials\n";
    if (($p = srcPath('SiteMaterials.csv')) !== null) {
        $ins = $pdo->prepare("INSERT INTO project_materials (project_id, material_id, gate_id) VALUES (?, ?, ?)");
        $seen = [];
        foreach (csvRows($p) as [$ln, $row]) {
            ctxSet('SiteMaterials.csv', $ln, $row);
            $matCode = $row['MatCode'] ?? '';
            if ($matCode === '') { warn("SiteMaterials.csv line $ln: MatCode ว่าง — ข้าม"); continue; }
            $projId = projectId($row['SiteCode'] ?? '');
            if ($projId === null) { warn("SiteMaterials.csv line $ln: SiteCode ว่าง — ข้าม"); continue; }
            $mat = materialInfo($matCode);
            $matId = $mat ? $mat['id'] : stubMaterial($matCode, "SiteMaterials.csv line $ln");
            $k = $projId . '|' . $matId;
            if (isset($seen[$k])) {
                warn("SiteMaterials.csv line $ln: (SiteCode, MatCode) ซ้ำ — ใช้แถวแรก");
                continue;
            }
            $gId = gateId($projId, $row['GateID'] ?? '', "SiteMaterials.csv line $ln");
            $ins->execute([$projId, $matId, $gId]);
            $seen[$k] = true;
            bump('project_materials');
        }
    }

    // -----------------------------------------------------------------
    // 10) Balance.csv → stock_balances (+ backfill materials.unit)
    // -----------------------------------------------------------------
    echo "[10] Balance.csv → stock_balances\n";
    if (($p = srcPath('Balance.csv')) !== null) {
        $ins = $pdo->prepare(
            "INSERT INTO stock_balances (project_id, material_id, qty_in, pending, qty_out, on_hand)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $updUnit = $pdo->prepare("UPDATE materials SET unit = ? WHERE id = ?");
        $seen = [];
        foreach (csvRows($p) as [$ln, $row]) {
            ctxSet('Balance.csv', $ln, $row);
            $matCode = $row['MatCode'] ?? '';
            if ($matCode === '') { warn("Balance.csv line $ln: MatCode ว่าง — ข้าม"); continue; }
            $projId = projectId($row['SiteCode'] ?? '');
            if ($projId === null) { warn("Balance.csv line $ln: SiteCode ว่าง — ข้าม"); continue; }
            $mat = materialInfo($matCode);
            if (!$mat) {
                stubMaterial($matCode, "Balance.csv line $ln");
                $mat = materialInfo($matCode);
            }
            $k = $projId . '|' . $mat['id'];
            if (isset($seen[$k])) {
                warn("Balance.csv line $ln: (SiteCode, MatCode) ซ้ำ — ใช้แถวแรก");
                continue;
            }
            // Unit: backfill ถ้า master ว่าง; ต่างกัน → เตือน (ไม่ทับ)
            $bUnit = $row['Unit'] ?? '';
            if ($bUnit !== '') {
                if ($mat['unit'] === '') {
                    $updUnit->execute([$bUnit, $mat['id']]);
                    $MATERIALS[$matCode]['unit'] = $bUnit;
                    $mat['unit'] = $bUnit;
                } elseif ($mat['unit'] !== $bUnit) {
                    warn("Balance.csv line $ln: Unit '$bUnit' ต่างจาก materials.unit '{$mat['unit']}' ของ '$matCode' — คงค่า master");
                }
            }
            $ins->execute([
                $projId, $mat['id'],
                numVal($row['In'] ?? ''), numVal($row['Pending'] ?? ''),
                numVal($row['Out'] ?? ''), numVal($row['OnHand'] ?? ''),
            ]);
            $seen[$k] = true;
            bump('stock_balances');
        }
    }

    // -----------------------------------------------------------------
    // 10b) ยอดรายประตูตั้งต้น — Balance.csv มีแต่ยอดรวมรายไซต์ ลงไว้ที่ประตูของวัสดุ
    //      (SiteMaterials.GateID ขั้น 9) ไม่งั้นฟอร์มเบิกขึ้น "ไม่มีของในประตูใดเลย" ทุกตัว
    // -----------------------------------------------------------------
    echo "[10b] stock_balances → stock_gate_balances (ประตูตั้งต้นของวัสดุ)\n";
    $al = stockAllocateUnassigned($pdo);
    bump('stock_gate_balances', $al['items']);
    foreach ($al['skipped'] as $s) {
        warn("ยอดรายประตู {$s[0]} / {$s[1]} ({$s[2]}): {$s[3]} — ยอดรวมถูกแต่ยังไม่อยู่ประตูใด");
    }

    // -----------------------------------------------------------------
    // 11) เอกสาร 4 ชนิด → documents + document_items
    // -----------------------------------------------------------------
    echo "[11] RequisitionLogs/OddsLogs/Borrow_Return/InboundLogs → documents + document_items\n";
    $docLogSpecs = [
        // file, type, idCol, tsCol, returnTsCol, approverCol, photoCol, photoReturnCol, nameCol, hasCharge, rsCol
        ['RequisitionLogs.csv', 'RD', 'RequisID',  'Timestamp',        null,               'Approver', 'PhotoURL',        null,              null,   true,  null],
        ['OddsLogs.csv',        'OD', 'OddsID',    'Timestamp',        null,               'Picker',   'PhotoURL',        null,              'Name', true,  null],
        ['Borrow_Return.csv',   'BD', 'BorrowID',  'Timestamp_Borrow', 'Timestamp_Return', 'Approver', 'Photo_BorrowURL', 'Photo_ReturnURL', null,   false, null],
        ['InboundLogs.csv',     'IN', 'InboundID', 'Timestamp',        null,               'Approver', 'PhotoURL',        null,              null,   false, 'RS'],
    ];
    $insDoc = $pdo->prepare(
        "INSERT INTO documents (doc_no, doc_type, project_id, requester_username, receiver_name, receiver_sub_id,
                                gate_id, usage_area, notice, approver_username, status, rs_no,
                                photo_url, photo_return_url, doc_ts, return_ts)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    $insItem = $pdo->prepare(
        "INSERT INTO document_items (document_id, material_id, mat_code, mat_name, unit, qty,
                                     usage_area, notice, rs_no, charge_money, stock_deducted)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)"
    );
    $DOC_IDS = []; // doc_no => documents.id (ใช้ตอน gate_logs + counters)

    foreach ($docLogSpecs as $spec) {
        list($file, $type, $idCol, $tsCol, $retTsCol, $apprCol, $photoCol, $photoRetCol, $nameCol, $hasCharge, $rsCol) = $spec;
        $p = srcPath($file);
        if ($p === null) continue;
        echo "  - $file ($type)\n";

        // จัดกลุ่มตามเลขเอกสาร (คงลำดับพบครั้งแรก)
        $docs = [];
        foreach (csvRows($p) as [$ln, $row]) {
            ctxSet($file, $ln, $row);
            $docNo = $row[$idCol] ?? '';
            if ($docNo === '') { warn("$file line $ln: $idCol ว่าง — ข้าม"); continue; }
            if (!isset($docs[$docNo])) {
                $docs[$docNo] = ['first' => $row, 'firstLine' => $ln, 'rows' => []];
            }
            $docs[$docNo]['rows'][] = [$ln, $row];
        }

        foreach ($docs as $docNo => $g) {
            $first = $g['first'];
            ctxSet($file, $g['firstLine'], $first);
            if (isset($DOC_IDS[$docNo])) {
                warn("$file: เลขเอกสาร '$docNo' ซ้ำข้ามไฟล์ — ข้ามกลุ่มนี้");
                continue;
            }
            $projId = projectId($first['SiteCode'] ?? '');
            if ($projId === null) { warn("$file doc $docNo: SiteCode ว่าง — ข้ามเอกสาร"); continue; }

            // doc_ts จากแถวแรก; fallback = DDMMYY ในเลขเอกสาร
            $dt = parseGasTimestamp($first[$tsCol] ?? '');
            if (!$dt && preg_match('/^[A-Z]{2}(\d{2})(\d{2})(\d{2})/', $docNo, $m)) {
                $dt = new DateTime();
                $dt->setDate(2000 + (int)$m[3], (int)$m[2], (int)$m[1]);
                $dt->setTime(0, 0, 0);
                warn("$file doc $docNo: Timestamp แปลงไม่ได้ ('" . ($first[$tsCol] ?? '') . "') — ใช้วันที่จากเลขเอกสาร");
            }
            if (!$dt) {
                warn("$file doc $docNo: ไม่มี Timestamp ใช้ได้เลย — ใช้เวลาปัจจุบัน");
                $dt = nowBkk();
            }

            // return_ts (BD): แถวแรกที่ไม่ว่าง
            $retDt = null;
            if ($retTsCol !== null) {
                foreach ($g['rows'] as [$ln2, $r2]) {
                    $retDt = parseGasTimestamp($r2[$retTsCol] ?? '');
                    if ($retDt) break;
                }
            }

            // Receiver: verbatim; resolve sub เฉพาะที่ไม่ใช่ 'DC:' prefix
            $receiver = $first['Receiver'] ?? '';
            $subId = null;
            if ($receiver !== '' && !str_starts_with($receiver, 'DC:')) {
                $subId = subIdByName($projId, $receiver);
            }

            // photo urls: รวมจากทุกแถว (distinct) คั่น ', ' ตามพฤติกรรม GAS join(", ")
            $photos = [];
            $retPhotos = [];
            foreach ($g['rows'] as [$ln2, $r2]) {
                $u = trim($r2[$photoCol] ?? '');
                if ($u !== '' && !in_array($u, $photos, true)) $photos[] = $u;
                if ($photoRetCol !== null) {
                    $ru = trim($r2[$photoRetCol] ?? '');
                    if ($ru !== '' && !in_array($ru, $retPhotos, true)) $retPhotos[] = $ru;
                }
            }

            $status = canonDocStatus($first['Status'] ?? '', "$file doc $docNo");
            $gId = gateId($projId, $first['GateID'] ?? '', "$file doc $docNo");

            $insDoc->execute([
                $docNo,
                $type,
                $projId,
                $first['UserName'] ?? '',
                nn($receiver),
                $subId,
                $gId,
                nn($first['UsageArea'] ?? ''),
                nn($first['Notice'] ?? ''),
                nn($first[$apprCol] ?? ''),
                $status,
                $rsCol !== null ? nn($first[$rsCol] ?? '') : null,
                $photos ? implode(', ', $photos) : null,
                $retPhotos ? implode(', ', $retPhotos) : null,
                dtSql($dt),
                dtSql($retDt),
            ]);
            $docId = (int)$pdo->lastInsertId();
            $DOC_IDS[$docNo] = $docId;
            bump('documents');

            foreach ($g['rows'] as [$ln2, $r2]) {
                ctxSet($file, $ln2, $r2);
                $matCode = $r2['MatCode'] ?? '';
                $mat = materialInfo($matCode);
                // snapshot ชื่อ: OddsLogs มีคอลัมน์ Name; อื่น ๆ ใช้ master
                $snapName = '';
                if ($nameCol !== null && ($r2[$nameCol] ?? '') !== '') {
                    $snapName = $r2[$nameCol];
                } elseif ($mat) {
                    $snapName = $mat['name'];
                }
                $insItem->execute([
                    $docId,
                    $mat ? $mat['id'] : null,   // ไม่แมตช์ → NULL (legacy import)
                    $matCode,
                    $snapName,
                    $mat ? $mat['unit'] : '',
                    numVal($r2['Qty'] ?? ''),
                    nn($r2['UsageArea'] ?? ''),
                    nn($r2['Notice'] ?? ''),
                    $rsCol !== null ? nn($r2[$rsCol] ?? '') : null,
                    ($hasCharge && isTrueFlag($r2['ChargeMoney'] ?? '')) ? 1 : 0,
                    isTrueFlag($r2['StockDeducted'] ?? '') ? 1 : 0,
                ]);
                bump('document_items');
                if ($matCode !== '' && !$mat) {
                    warn("$file line $ln2: MatCode '$matCode' ไม่พบใน materials — material_id = NULL");
                }
            }
        }
    }

    // -----------------------------------------------------------------
    // 12) GateLogs.csv → gate_logs (dedupe เอาสถานะก้าวหน้าสุด)
    // -----------------------------------------------------------------
    echo "[12] GateLogs.csv → gate_logs\n";
    if (($p = srcPath('GateLogs.csv')) !== null) {
        // rank สถานะ: Closed > Confirmed > Scanned > Opened > Awaiting > Cancelled
        $rank = [
            'closed'    => 5,
            'confirmed' => 4,
            'scanned'   => 3,
            'opened'    => 2,
            'awaiting'  => 1,
            'cancelled' => 0,
        ];
        $canonGate = [
            'closed' => Gate::ST_CLOSED, 'confirmed' => Gate::ST_CONFIRMED,
            'scanned' => Gate::ST_SCANNED, 'opened' => Gate::ST_OPENED,
            'awaiting' => Gate::ST_AWAITING, 'cancelled' => Gate::ST_CANCELLED,
        ];
        $byDoc = []; // DocID => ['row'=>, 'line'=>, 'rank'=>]
        foreach (csvRows($p) as [$ln, $row]) {
            ctxSet('GateLogs.csv', $ln, $row);
            $docNo = $row['DocID'] ?? '';
            if ($docNo === '') { warn("GateLogs.csv line $ln: DocID ว่าง — ข้าม"); continue; }
            $sKey = mb_strtolower(trim($row['Status'] ?? ''), 'UTF-8');
            $r = $rank[$sKey] ?? -1; // สถานะ legacy/ไม่รู้จัก → แพ้ทุกอัน (เก็บถ้าเป็นแถวเดียว)
            if (!isset($byDoc[$docNo])) {
                $byDoc[$docNo] = ['row' => $row, 'line' => $ln, 'rank' => $r];
            } else {
                warn("GateLogs.csv line $ln: DocID '$docNo' ซ้ำ — เก็บแถวสถานะก้าวหน้าสุด");
                if ($r > $byDoc[$docNo]['rank']) {
                    $byDoc[$docNo] = ['row' => $row, 'line' => $ln, 'rank' => $r];
                }
            }
        }
        $ins = $pdo->prepare(
            "INSERT INTO gate_logs (project_id, doc_no, document_id, leg, gate_id, picking_id, scanned_at, status, created_at)
             VALUES (?,?,?,?,?,?,?,?,?)"
        );
        foreach ($byDoc as $docNo => $e) {
            $row = $e['row'];
            ctxSet('GateLogs.csv', $e['line'], $row);
            $projId = projectId($row['SiteCode'] ?? '');
            if ($projId === null) { warn("GateLogs.csv line {$e['line']}: SiteCode ว่าง — ข้าม"); continue; }
            // 'RT' suffix = ขาคืนของเอกสาร BD เดิม
            $isReturn = (bool)preg_match('/RT$/', $docNo);
            $baseDocNo = $isReturn ? preg_replace('/RT$/', '', $docNo) : $docNo;
            $documentId = $DOC_IDS[$baseDocNo] ?? null;
            if ($documentId === null) {
                warn("GateLogs.csv line {$e['line']}: DocID '$docNo' ไม่พบเอกสาร '$baseDocNo' ใน documents — document_id = NULL");
            }
            $sKey = mb_strtolower(trim($row['Status'] ?? ''), 'UTF-8');
            $status = $canonGate[$sKey] ?? trim($row['Status'] ?? '');
            if (!isset($canonGate[$sKey])) {
                warn("GateLogs.csv line {$e['line']}: สถานะ gate ไม่รู้จัก '" . ($row['Status'] ?? '') . "' — เก็บ verbatim");
            }
            $dt = parseGasTimestamp($row['Timestamp'] ?? '');
            if (!$dt) {
                warn("GateLogs.csv line {$e['line']}: Timestamp แปลงไม่ได้ — ใช้เวลาปัจจุบัน");
                $dt = nowBkk();
            }
            // beyond Awaiting (Opened/Scanned/Confirmed/Closed) → มีการสแกนแล้ว
            $scannedAt = ($e['rank'] >= 2) ? dtSql($dt) : null;
            $ins->execute([
                $projId,
                $docNo,
                $documentId,
                $isReturn ? 'return' : 'out',
                gateId($projId, $row['GateID'] ?? '', "GateLogs.csv line {$e['line']}"),
                nn($row['PickingID'] ?? ''),
                $scannedAt,
                $status,
                dtSql($dt),
            ]);
            bump('gate_logs');
        }
    }

    // -----------------------------------------------------------------
    // 13) RateCard.csv → rate_cards (UnitPrice ว่าง → NULL ไม่ใช่ 0!)
    // -----------------------------------------------------------------
    echo "[13] RateCard.csv → rate_cards\n";
    if (($p = srcPath('RateCard.csv')) !== null) {
        $ins = $pdo->prepare(
            "INSERT INTO rate_cards (project_id, material_id, unit_price, updated_by, updated_at) VALUES (?,?,?,?,?)"
        );
        $seen = [];
        foreach (csvRows($p) as [$ln, $row]) {
            ctxSet('RateCard.csv', $ln, $row);
            $matCode = $row['MatCode'] ?? '';
            if ($matCode === '') { warn("RateCard.csv line $ln: MatCode ว่าง — ข้าม"); continue; }
            $projId = projectId($row['SiteCode'] ?? '');
            if ($projId === null) { warn("RateCard.csv line $ln: SiteCode ว่าง — ข้าม"); continue; }
            $mat = materialInfo($matCode);
            if (!$mat) {
                stubMaterial($matCode, "RateCard.csv line $ln");
                $mat = materialInfo($matCode);
            }
            $k = $projId . '|' . $mat['id'];
            if (isset($seen[$k])) {
                warn("RateCard.csv line $ln: (SiteCode, MatCode) ซ้ำ — ใช้แถวแรก");
                continue;
            }
            // ว่าง → NULL (= "ยังไม่ตั้งราคา"; สำคัญ — ห้ามใส่ 0)
            $rawPrice = str_replace(',', '', trim($row['UnitPrice'] ?? ''));
            $price = ($rawPrice === '' || !is_numeric($rawPrice)) ? null : (float)$rawPrice;
            if ($rawPrice !== '' && $price === null) {
                warn("RateCard.csv line $ln: UnitPrice '$rawPrice' ไม่ใช่ตัวเลข — เก็บ NULL");
            }
            $updAt = parseGasTimestamp($row['UpdatedAt'] ?? '');
            $ins->execute([
                $projId,
                $mat['id'],
                $price,
                nn($row['UpdatedBy'] ?? ''),
                dtSql($updAt) ?: date('Y-m-d H:i:s'),
            ]);
            $seen[$k] = true;
            bump('rate_cards');
        }
    }

    // -----------------------------------------------------------------
    // 14) DailyCheck.csv → daily_check_confirms
    // -----------------------------------------------------------------
    echo "[14] DailyCheck.csv → daily_check_confirms\n";
    if (($p = srcPath('DailyCheck.csv')) !== null) {
        $ins = $pdo->prepare(
            "INSERT INTO daily_check_confirms (project_id, check_date, confirmed_by, confirmed_at, item_count, notes)
             VALUES (?,?,?,?,?,?)"
        );
        $seen = [];
        foreach (csvRows($p) as [$ln, $row]) {
            ctxSet('DailyCheck.csv', $ln, $row);
            $date = $row['Date'] ?? ''; // ISO yyyy-mm-dd
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                warn("DailyCheck.csv line $ln: Date '$date' ไม่ใช่รูปแบบ yyyy-mm-dd — ข้าม");
                continue;
            }
            $projId = projectId($row['SiteCode'] ?? '');
            if ($projId === null) { warn("DailyCheck.csv line $ln: SiteCode ว่าง — ข้าม"); continue; }
            $k = $projId . '|' . $date;
            if (isset($seen[$k])) {
                warn("DailyCheck.csv line $ln: (SiteCode, Date) ซ้ำ — ใช้แถวแรก");
                continue;
            }
            $confAt = parseGasTimestamp($row['ConfirmedAt'] ?? '');
            $ins->execute([
                $projId,
                $date,
                $row['ConfirmedBy'] ?? '',
                dtSql($confAt) ?: ($date . ' 00:00:00'),
                (int)numVal($row['ItemCount'] ?? ''),
                nn($row['Notes'] ?? ''),
            ]);
            $seen[$k] = true;
            bump('daily_check_confirms');
        }
    }

    // -----------------------------------------------------------------
    // 15) DeductionDocs.csv → deduction_docs
    // -----------------------------------------------------------------
    echo "[15] DeductionDocs.csv → deduction_docs\n";
    if (($p = srcPath('DeductionDocs.csv')) !== null) {
        $ins = $pdo->prepare(
            "INSERT INTO deduction_docs (doc_no, project_id, ym, running_no, sub_id, sub_name,
                                         days_label, days_json, item_count, issued_by, issued_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        );
        $seen = [];
        foreach (csvRows($p) as [$ln, $row]) {
            ctxSet('DeductionDocs.csv', $ln, $row);
            $docNo = $row['DocNo'] ?? '';
            if ($docNo === '') { warn("DeductionDocs.csv line $ln: DocNo ว่าง — ข้าม"); continue; }
            if (isset($seen[$docNo])) {
                warn("DeductionDocs.csv line $ln: DocNo '$docNo' ซ้ำ — ใช้แถวแรก");
                continue;
            }
            $projId = projectId($row['SiteCode'] ?? '');
            if ($projId === null) { warn("DeductionDocs.csv line $ln: SiteCode ว่าง — ข้าม"); continue; }
            // 'SUB-ARI-202606-01' → ym '2026-06', running 1 (fallback: คอลัมน์ YM/No)
            $ym = ''; $runNo = 0;
            if (preg_match('/^SUB-.+-(\d{4})(\d{2})-(\d+)$/', $docNo, $m)) {
                $ym = $m[1] . '-' . $m[2];
                $runNo = (int)$m[3];
            } else {
                $ym = trim($row['YM'] ?? '');
                $runNo = (int)numVal($row['No'] ?? '');
                warn("DeductionDocs.csv line $ln: DocNo '$docNo' ไม่ตรงรูปแบบ SUB-…-YYYYMM-NN — ใช้คอลัมน์ YM/No แทน");
            }
            $subName = $row['SubName'] ?? '';
            // days: 'ทั้งเดือน' → NULL; อื่น ๆ parse เลขวัน/ช่วง (เช่น '1,2,15' หรือ '1-5') → JSON array
            $daysLabel = $row['Days'] ?? '';
            $daysJson = null;
            if ($daysLabel !== '' && $daysLabel !== 'ทั้งเดือน') {
                $days = [];
                foreach (explode(',', $daysLabel) as $tok) {
                    $tok = trim($tok);
                    if ($tok === '') continue;
                    if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $tok, $m2)) {
                        for ($d = (int)$m2[1]; $d <= (int)$m2[2]; $d++) { $days[] = $d; }
                    } elseif (preg_match('/^\d+$/', $tok)) {
                        $days[] = (int)$tok;
                    }
                }
                $days = array_values(array_unique($days));
                sort($days);
                if ($days) {
                    $daysJson = json_encode($days);
                } else {
                    warn("DeductionDocs.csv line $ln: Days '$daysLabel' parse ไม่ได้ — days_json = NULL");
                }
            }
            $issuedAt = parseGasTimestamp($row['IssuedAt'] ?? '');
            if (!$issuedAt) {
                warn("DeductionDocs.csv line $ln: IssuedAt แปลงไม่ได้ — ใช้เวลาปัจจุบัน");
                $issuedAt = nowBkk();
            }
            $ins->execute([
                $docNo,
                $projId,
                $ym,
                $runNo,
                subIdByName($projId, $subName),
                $subName,
                $daysLabel,
                $daysJson,
                (int)numVal($row['ItemCount'] ?? ''),
                $row['IssuedBy'] ?? '',
                dtSql($issuedAt),
            ]);
            $seen[$docNo] = true;
            bump('deduction_docs');
        }
    }

    // -----------------------------------------------------------------
    // 16) ErrorLogs.csv → error_logs · UserLogs.csv → activity_log
    // -----------------------------------------------------------------
    echo "[16] ErrorLogs.csv → error_logs / UserLogs.csv → activity_log\n";
    if (($p = srcPath('ErrorLogs.csv')) !== null) {
        $ins = $pdo->prepare("INSERT INTO error_logs (project_id, gate_code, message, created_at) VALUES (?,?,?,?)");
        foreach (csvRows($p) as [$ln, $row]) {
            ctxSet('ErrorLogs.csv', $ln, $row);
            $dt = parseGasTimestamp($row['Timestamp'] ?? '');
            $ins->execute([
                projectId($row['SiteCode'] ?? ''),
                nn($row['GateID'] ?? ''),
                $row['Message'] ?? '',
                dtSql($dt) ?: date('Y-m-d H:i:s'),
            ]);
            bump('error_logs');
        }
    }
    if (($p = srcPath('UserLogs.csv')) !== null) {
        $ins = $pdo->prepare(
            "INSERT INTO activity_log (entity_type, entity_id, user_name, action, old_value, new_value, created_at)
             VALUES ('user', ?, ?, 'change_site', ?, ?, ?)"
        );
        foreach (csvRows($p) as [$ln, $row]) {
            ctxSet('UserLogs.csv', $ln, $row);
            $dt = parseGasTimestamp($row['Timestamp'] ?? '');
            $ins->execute([
                $row['UserID'] ?? '',
                nn($row['UserID'] ?? ''),
                nn($row['FromSite'] ?? ''),
                nn($row['ToSite'] ?? ''),
                dtSql($dt) ?: date('Y-m-d H:i:s'),
            ]);
            bump('activity_log');
        }
    }

    // -----------------------------------------------------------------
    // 17) SEED doc_counters จากเลขเอกสาร + PickingID (สำคัญ — กันเลขชนวัน cutover)
    // -----------------------------------------------------------------
    echo "[17] Seed doc_counters จาก documents + gate_logs.picking_id\n";
    $GLOBALS['IMPORT_CTX'] = 'seed doc_counters';
    $max = []; // type|ddmmyy => last_no
    // เลขเอกสาร: TYPE(2) + DDMMYY(6) + running(>=1 หลัก) + 'G…' — ใช้ regex ไม่ใช่ SUBSTR ตายตัว
    foreach ($pdo->query("SELECT doc_no FROM documents") as $r) {
        if (preg_match('/^(RD|OD|BD|IN)(\d{6})(\d+)G/', $r['doc_no'], $m)) {
            $k = $m[1] . '|' . $m[2];
            $n = (int)$m[3];
            if (!isset($max[$k]) || $n > $max[$k]) $max[$k] = $n;
        } else {
            warn("doc_counters: doc_no '{$r['doc_no']}' ไม่ตรงรูปแบบ — ไม่นับใน counter");
        }
    }
    // PickingID: 'PK' + DDMMYY(6) + running — ข้ามค่า legacy ที่ไม่ตรงรูปแบบ
    foreach ($pdo->query("SELECT DISTINCT picking_id FROM gate_logs WHERE picking_id IS NOT NULL AND picking_id <> ''") as $r) {
        if (preg_match('/^PK(\d{6})(\d+)$/', $r['picking_id'], $m)) {
            $k = 'PK|' . $m[1];
            $n = (int)$m[2];
            if (!isset($max[$k]) || $n > $max[$k]) $max[$k] = $n;
        } else {
            warn("doc_counters: PickingID '{$r['picking_id']}' ไม่ตรงรูปแบบ PK{DDMMYY}{run} — ไม่นับใน counter (ค่า legacy)");
        }
    }
    $insC = $pdo->prepare(
        "INSERT INTO doc_counters (counter_type, date_key, last_no) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE last_no = GREATEST(last_no, VALUES(last_no))"
    );
    foreach ($max as $k => $n) {
        list($type, $dateKey) = explode('|', $k);
        $insC->execute([$type, $dateKey, $n]);
        bump('doc_counters');
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "\n" . str_repeat('!', 70) . "\n");
    fwrite(STDERR, "IMPORT FAILED — rollback ทั้งหมดแล้ว (ไม่มีข้อมูลถูกเขียน)\n");
    fwrite(STDERR, "แถวที่พัง: " . $GLOBALS['IMPORT_CTX'] . "\n");
    fwrite(STDERR, "ข้อผิดพลาด: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
}

// ===========================================================================
// สรุปผล
// ===========================================================================
echo "\n" . str_repeat('=', 70) . "\n";
echo "IMPORT สำเร็จ — สรุปจำนวนแถวต่อตาราง\n";
echo str_repeat('-', 70) . "\n";
printf("  %-28s %10s\n", 'ตาราง', 'แถว');
printf("  %-28s %10s\n", str_repeat('-', 28), str_repeat('-', 10));
ksort($GLOBALS['IMPORT_COUNTS']);
$total = 0;
foreach ($GLOBALS['IMPORT_COUNTS'] as $t => $n) {
    printf("  %-28s %10d\n", $t, $n);
    $total += $n;
}
printf("  %-28s %10s\n", str_repeat('-', 28), str_repeat('-', 10));
printf("  %-28s %10d\n", 'รวม', $total);

echo "\nคำเตือนทั้งหมด: " . count($GLOBALS['IMPORT_WARNINGS']) . " รายการ\n";
foreach ($GLOBALS['IMPORT_WARNINGS'] as $i => $w) {
    printf("  %3d. %s\n", $i + 1, $w);
}

echo "\n" . str_repeat('!', 70) . "\n";
echo "!! อย่าลืม: ลบโฟลเดอร์ db/source_data/ หลัง import ขึ้น production   !!\n";
echo "!! ไฟล์ CSV มีรหัสผ่านแบบ plaintext (Users.csv / Subcontracts.csv)   !!\n";
echo str_repeat('!', 70) . "\n";
exit(0);
