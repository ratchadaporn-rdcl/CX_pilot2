<?php
/**
 * CONNEXT — lib/dispatch_rounds.php : รอบจ่ายของ (dispatch rounds) รายไซต์  [PHP port 2026-09-24 · มติ 50]
 *
 * ตัวอย่างผู้ใช้: "เบิกก่อน 8 โมงจะจ่ายของ 9 โมง · เบิก 8–10 จ่าย 11 โมง"
 *
 * RPC:
 *   getDispatchRounds(siteCode)                 ค่าตั้งของไซต์ (ทุกคนที่ล็อกอินดูไซต์ตัวเองได้ — หน้าเบิก/QR ใช้โชว์รอบ)
 *   saveDispatchRounds({siteCode, enabled, workDays, rounds:[{cutoff,dispatch}]})   ADM ทุกไซต์ · สายคลัง (can_req) ไซต์ตัวเอง
 *   getDispatchBoard(siteCode, date)            กระดานรอบจ่ายของวันนั้น: ใบที่ยังไม่จ่ายแยกตามรอบ + ของที่ต้องเตรียม + ค้างรอบ
 *
 * มติ 50 — กติกาจัดรอบ
 *   - รอบ = [เบิกก่อน (cutoff), จ่ายเวลา (dispatch)] เรียงตาม cutoff · ช่วงของรอบ = ตั้งแต่ cutoff รอบก่อน ถึง "ก่อน" cutoff รอบนี้
 *     (เบิก 08:00 พอดี = ไม่ทัน "ก่อน 8 โมง" → รอบถัดไป)
 *   - เบิกหลัง cutoff สุดท้าย หรือเบิกวันที่ไม่ใช่วันทำงาน → รอบแรกของวันทำงานถัดไป
 *   - ใช้เวลาออกใบ (documents.doc_ts) ตามที่ผู้ใช้กำหนด — ใบที่ต้องอนุมัติแล้วอนุมัติช้า จะขึ้นเป็น "ค้างรอบ" บนกระดาน
 *     (ระบบไม่มีเวลาที่อนุมัติเก็บไว้)
 *   - ต้อง จ่าย > เบิกก่อน ในวันเดียวกัน · cutoff และเวลาจ่ายเรียงเพิ่มขึ้น · สูงสุด 12 รอบ
 *   - ตาราง dispatch_settings / dispatch_rounds สร้างเองตอนบันทึกครั้งแรก — ยังไม่ตั้ง = ปิด (ไม่โชว์อะไร)
 *   - คำนวณสด ๆ จากค่าที่ตั้งปัจจุบัน (ไม่ได้เขียนรอบลงเอกสาร) — เปลี่ยนรอบแล้วใบที่ยังไม่จ่ายจัดรอบใหม่ตามค่าใหม่
 *
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/inventory_insights.php';   // _iiSite / _iiRole / _iiCanEdit / _iiLog

const DR_MAX_ROUNDS = 12;
const DR_DEFAULT_DAYS = [1, 2, 3, 4, 5, 6];         // จันทร์–เสาร์ (ISO-8601: จันทร์ = 1 … อาทิตย์ = 7)
const DR_OPEN_STATUSES = ['awaiting approval', 'awaiting for approval', 'approved', 'sent borrow'];   // = PENDING_STATUSES

function _drTablesExist(PDO $pdo): bool {
    static $ok = null;
    if ($ok === true) return true;
    $ok = (bool)$pdo->query("SHOW TABLES LIKE 'dispatch_rounds'")->fetchColumn();
    return $ok;
}

function _drEnsureTables(PDO $pdo): void {
    if (_drTablesExist($pdo)) return;
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS dispatch_settings (
            project_id INT NOT NULL PRIMARY KEY,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            work_days VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5,6' COMMENT 'ISO-8601 จันทร์=1 … อาทิตย์=7',
            updated_by VARCHAR(100) NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_dispatch_settings_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='รอบจ่าย: เปิด/ปิด + วันทำงาน ต่อไซต์ (มติ 50)'"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS dispatch_rounds (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            cutoff_time TIME NOT NULL COMMENT 'เบิกก่อนเวลานี้',
            dispatch_time TIME NOT NULL COMMENT 'จ่ายของเวลานี้',
            sort_no INT NOT NULL DEFAULT 0,
            KEY idx_dispatch_rounds_project (project_id),
            CONSTRAINT fk_dispatch_rounds_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='รอบจ่าย: เบิกก่อน → จ่ายเวลา ต่อไซต์ (มติ 50)'"
    );
}

/** 'H:i' ที่ถูกต้อง หรือ null */
function _drTime($v): ?string {
    $s = trim((string)$v);
    if (!preg_match('/^(\d{1,2})[:.](\d{2})(?::\d{2})?$/', $s, $m)) return null;
    $h = (int)$m[1]; $i = (int)$m[2];
    if ($h > 23 || $i > 59) return null;
    return sprintf('%02d:%02d', $h, $i);
}

/** ค่าที่ตั้งของไซต์ → ['enabled'=>bool, 'saved'=>bool, 'workDays'=>int[], 'rounds'=>[['cutoff'=>'08:00','dispatch'=>'09:00'],...]] */
function _drSettings(PDO $pdo, int $projectId): array {
    $out = ['enabled' => false, 'saved' => false, 'workDays' => DR_DEFAULT_DAYS, 'rounds' => [], 'updatedBy' => '', 'updatedAt' => ''];
    if (!_drTablesExist($pdo)) return $out;
    $st = $pdo->prepare('SELECT enabled, work_days, updated_by, updated_at FROM dispatch_settings WHERE project_id = ?');
    $st->execute([$projectId]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if ($s) {
        $out['saved'] = true;
        $out['enabled'] = (int)$s['enabled'] === 1;
        $days = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$s['work_days'])), function ($d) { return $d >= 1 && $d <= 7; })));
        sort($days);
        $out['workDays'] = $days;
        $out['updatedBy'] = (string)($s['updated_by'] ?? '');
        $out['updatedAt'] = substr((string)($s['updated_at'] ?? ''), 0, 16);
    }
    $st = $pdo->prepare('SELECT cutoff_time, dispatch_time FROM dispatch_rounds WHERE project_id = ? ORDER BY cutoff_time, sort_no');
    $st->execute([$projectId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out['rounds'][] = ['cutoff' => substr((string)$r['cutoff_time'], 0, 5), 'dispatch' => substr((string)$r['dispatch_time'], 0, 5)];
    }
    if (!$out['rounds'] || !$out['workDays']) $out['enabled'] = false;   // ไม่มีรอบ/ไม่มีวันทำงาน = ใช้ไม่ได้
    return $out;
}

/**
 * รอบของเวลาที่เบิก — คืน ['date'=>'Y-m-d', 'cutoff'=>'H:i', 'dispatch'=>'H:i', 'idx'=>int, 'at'=>'Y-m-d H:i'] หรือ null (ไม่ได้ตั้ง)
 * @param array $rounds  เรียงตาม cutoff แล้ว
 */
function drRoundFor(array $rounds, array $workDays, DateTime $ts): ?array {
    if (!$rounds || !$workDays) return null;
    $d = (clone $ts)->setTime(0, 0, 0);
    $t = $ts->format('H:i');
    for ($i = 0; $i < 15; $i++) {
        if (in_array((int)$d->format('N'), $workDays, true)) {
            foreach ($rounds as $k => $r) {
                if ($i > 0 || $t < $r['cutoff']) {
                    $day = $d->format('Y-m-d');
                    return ['date' => $day, 'cutoff' => $r['cutoff'], 'dispatch' => $r['dispatch'], 'idx' => $k, 'at' => $day . ' ' . $r['dispatch']];
                }
            }
        }
        $d->modify('+1 day');
    }
    return null;
}

// =========================================================================
// getDispatchRounds(siteCode)
// =========================================================================
function rpc_getDispatchRounds(PDO $pdo, ?array $user, array $args) {
    try {
        $site = _iiSite($pdo, $user, $args[0] ?? '', true);
        if ($site['id'] === null) return ['success' => false, 'message' => 'ไม่พบไซต์'];
        $s = _drSettings($pdo, $site['id']);
        $role = _iiRole($pdo, $user);
        $sites = [];
        if ($role['level'] === 0) {
            foreach ($pdo->query("SELECT code, name FROM projects WHERE status = 'active' ORDER BY code") as $r) {
                $sites[] = ['code' => (string)$r['code'], 'name' => (string)$r['name']];
            }
        }
        return array_merge(['success' => true, 'siteCode' => $site['code'], 'siteName' => $site['name'], 'sites' => $sites,
                            'canEdit' => _iiCanEdit($pdo, $user, $site['code']), 'serverNow' => date('Y-m-d H:i:s')], $s);
    } catch (Throwable $e) {
        error_log('getDispatchRounds: ' . $e->getMessage());
        return ['success' => false, 'message' => 'โหลดรอบจ่ายไม่สำเร็จ'];
    }
}

// =========================================================================
// saveDispatchRounds({siteCode, enabled, workDays, rounds})
// =========================================================================
function rpc_saveDispatchRounds(PDO $pdo, ?array $user, array $args) {
    try {
        $p = is_array($args[0] ?? null) ? $args[0] : [];
        $want = trim((string)($p['siteCode'] ?? ''));
        $site = _iiSite($pdo, $user, $want, false);
        if ($site['id'] === null) return ['success' => false, 'message' => 'ไม่ระบุไซต์'];
        if (($want !== '' && strcasecmp($want, $site['code']) !== 0) || !_iiCanEdit($pdo, $user, $site['code'])) {
            return ['success' => false, 'message' => 'ไม่มีสิทธิ์ตั้งรอบจ่ายของไซต์ ' . ($want !== '' ? $want : $site['code']) . ' (ADM หรือสายคลังของไซต์นั้น)'];
        }
        $enabled = !empty($p['enabled']);
        $days = [];
        foreach ((array)($p['workDays'] ?? []) as $d) { $d = (int)$d; if ($d >= 1 && $d <= 7) $days[$d] = true; }
        $days = array_keys($days);
        sort($days);

        $rounds = []; $err = [];
        foreach ((array)($p['rounds'] ?? []) as $i => $r) {
            if (!is_array($r)) continue;
            $c = _drTime($r['cutoff'] ?? ''); $d = _drTime($r['dispatch'] ?? '');
            if (($r['cutoff'] ?? '') === '' && ($r['dispatch'] ?? '') === '') continue;   // แถวว่าง — ข้าม
            if ($c === null || $d === null) { $err[] = 'รอบที่ ' . ($i + 1) . ': เวลาไม่ถูกต้อง (ใช้รูปแบบ 08:00)'; continue; }
            if ($d <= $c) { $err[] = 'รอบที่ ' . ($i + 1) . ': เวลาจ่าย (' . $d . ') ต้องหลังเวลาเบิกก่อน (' . $c . ')'; continue; }
            $rounds[] = ['cutoff' => $c, 'dispatch' => $d];
        }
        usort($rounds, function ($a, $b) { return strcmp($a['cutoff'], $b['cutoff']); });
        for ($i = 1; $i < count($rounds); $i++) {
            if ($rounds[$i]['cutoff'] === $rounds[$i - 1]['cutoff']) $err[] = 'เวลาเบิกก่อน ' . $rounds[$i]['cutoff'] . ' ซ้ำกัน';
            elseif ($rounds[$i]['dispatch'] <= $rounds[$i - 1]['dispatch']) {
                $err[] = 'รอบ "เบิกก่อน ' . $rounds[$i]['cutoff'] . '" ต้องจ่ายหลังรอบก่อนหน้า (' . $rounds[$i - 1]['dispatch'] . ')';
            }
        }
        if (count($rounds) > DR_MAX_ROUNDS) $err[] = 'ตั้งได้ไม่เกิน ' . DR_MAX_ROUNDS . ' รอบ';
        if ($enabled && !$rounds) $err[] = 'เปิดใช้รอบจ่ายต้องมีอย่างน้อย 1 รอบ';
        if ($enabled && !$days) $err[] = 'เลือกวันทำงานอย่างน้อย 1 วัน';
        if ($err) return ['success' => false, 'message' => implode("\n", $err)];

        _drEnsureTables($pdo);   // DDL ก่อนเปิดทรานแซกชัน
        $by = (string)($user['username'] ?? '');
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO dispatch_settings (project_id, enabled, work_days, updated_by) VALUES (?,?,?,?)
                           ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), work_days = VALUES(work_days), updated_by = VALUES(updated_by)')
                ->execute([$site['id'], $enabled ? 1 : 0, implode(',', $days ?: DR_DEFAULT_DAYS), $by]);
            $pdo->prepare('DELETE FROM dispatch_rounds WHERE project_id = ?')->execute([$site['id']]);
            $ins = $pdo->prepare('INSERT INTO dispatch_rounds (project_id, cutoff_time, dispatch_time, sort_no) VALUES (?,?,?,?)');
            foreach ($rounds as $k => $r) { $ins->execute([$site['id'], $r['cutoff'] . ':00', $r['dispatch'] . ':00', $k]); }
            if ($ownTx) $pdo->commit();
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        try {
            $pdo->prepare('INSERT INTO activity_log (entity_type, entity_id, user_name, action, old_value, new_value) VALUES (?,?,?,?,?,?)')
                ->execute(['dispatch_rounds', $site['code'], $by, 'save', null,
                           json_encode(['enabled' => $enabled, 'workDays' => $days, 'rounds' => $rounds], JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable $e) { error_log('saveDispatchRounds log: ' . $e->getMessage()); }
        return ['success' => true, 'siteCode' => $site['code'], 'enabled' => $enabled && (bool)$rounds, 'workDays' => $days, 'rounds' => $rounds];
    } catch (Throwable $e) {
        error_log('saveDispatchRounds: ' . $e->getMessage());
        return ['success' => false, 'message' => 'บันทึกรอบจ่ายไม่สำเร็จ'];
    }
}

// =========================================================================
// getDispatchBoard(siteCode, date) — กระดานรอบจ่าย (สายคลัง/ADM/ระดับ ≥ 8)
// =========================================================================
function rpc_getDispatchBoard(PDO $pdo, ?array $user, array $args) {
    try {
        $site = _iiSite($pdo, $user, $args[0] ?? '', true);
        if ($site['id'] === null) return ['success' => false, 'message' => 'ไม่พบไซต์'];
        $role = _iiRole($pdo, $user);
        $canView = $role['level'] === 0 || $role['level'] >= 8
            || ($role['canReq'] && strcasecmp(trim((string)($user['siteCode'] ?? '')), $site['code']) === 0);
        if (!$canView) return ['success' => false, 'message' => 'no_permission'];

        $s = _drSettings($pdo, $site['id']);
        $today = date('Y-m-d');
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($args[1] ?? '')) ? (string)$args[1] : $today;
        $now = date('Y-m-d H:i');
        $sites = [];
        if ($role['level'] === 0 || $role['level'] >= 8) {
            foreach ($pdo->query("SELECT code, name FROM projects WHERE status = 'active' ORDER BY code") as $r) {
                $sites[] = ['code' => (string)$r['code'], 'name' => (string)$r['name']];
            }
        }
        $base = ['success' => true, 'siteCode' => $site['code'], 'siteName' => $site['name'], 'sites' => $sites, 'date' => $date, 'today' => $today,
                 'now' => $now, 'enabled' => $s['enabled'], 'saved' => $s['saved'], 'workDays' => $s['workDays'], 'settingsRounds' => $s['rounds'],
                 'canEdit' => _iiCanEdit($pdo, $user, $site['code'])];
        if (!$s['enabled']) return $base + ['rounds' => [], 'overdue' => [], 'later' => 0];

        // ใบที่ยังไม่จ่าย (สถานะค้าง = PENDING_STATUSES) ของไซต์ — RD / OD / BD / TD (เบิกโอนย้ายข้ามไซต์ · 2026-09-29)
        $ph = implode(',', array_fill(0, count(DR_OPEN_STATUSES), '?'));
        $st = $pdo->prepare(
            "SELECT d.id, d.doc_no, d.doc_type, d.status, d.doc_ts, d.requester_username, d.receiver_name, g.gate_code
               FROM documents d LEFT JOIN gates g ON g.id = d.gate_id
              WHERE d.project_id = ? AND d.doc_type IN ('RD','OD','BD','TD') AND LOWER(TRIM(d.status)) IN ($ph)
              ORDER BY d.doc_ts, d.id"
        );
        $st->execute(array_merge([$site['id']], DR_OPEN_STATUSES));
        $docs = $st->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        if ($docs) {
            $ids = array_map(function ($d) { return (int)$d['id']; }, $docs);
            $st = $pdo->prepare('SELECT di.document_id, di.mat_code, COALESCE(m.name, di.mat_name) AS name, m.unit, di.qty
                                   FROM document_items di LEFT JOIN materials m ON m.id = di.material_id
                                  WHERE di.document_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY di.id');
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $items[(int)$r['document_id']][] = $r; }
        }

        $byRound = [];      // idx → docs
        $overdue = [];
        $later = 0;
        foreach ($docs as $d) {
            $r = drRoundFor($s['rounds'], $s['workDays'], new DateTime((string)$d['doc_ts']));
            if (!$r) continue;
            $statusL = strtolower(trim((string)$d['status']));
            $doc = [
                'docId' => (string)$d['doc_no'], 'type' => (string)$d['doc_type'], 'status' => (string)$d['status'],
                'awaiting' => strpos($statusL, 'awaiting') !== false,
                'requestedAt' => substr((string)$d['doc_ts'], 0, 16), 'reqName' => (string)$d['requester_username'],
                'receiver' => (string)$d['receiver_name'], 'gate' => (string)($d['gate_code'] ?? ''),
                'roundAt' => $r['at'], 'items' => array_map(function ($it) {
                    return ['matCode' => (string)$it['mat_code'], 'name' => (string)$it['name'], 'unit' => (string)($it['unit'] ?? ''), 'qty' => (float)$it['qty']];
                }, $items[(int)$d['id']] ?? []),
            ];
            if ($date === $today && $r['at'] < $now && $r['date'] <= $today) { $overdue[] = $doc; continue; }   // เลยเวลาจ่ายแล้วยังค้าง
            if ($r['date'] === $date) { $byRound[$r['idx']][] = $doc; }
            elseif ($r['date'] > $date) { $later++; }
        }

        $rounds = [];
        foreach ($s['rounds'] as $k => $r) {
            $at = $date . ' ' . $r['dispatch'];
            $list = $byRound[$k] ?? [];
            // ของที่ต้องเตรียม — รวมเฉพาะใบที่อนุมัติแล้ว (ใบรออนุมัติอาจไม่ทันรอบ)
            $agg = [];
            foreach ($list as $doc) {
                if ($doc['awaiting']) continue;
                foreach ($doc['items'] as $it) {
                    $key = $it['matCode'];
                    if (!isset($agg[$key])) $agg[$key] = ['matCode' => $key, 'name' => $it['name'], 'unit' => $it['unit'], 'qty' => 0.0, 'docs' => 0];
                    $agg[$key]['qty'] += $it['qty'];
                    $agg[$key]['docs']++;
                }
            }
            $agg = array_values($agg);
            usort($agg, function ($a, $b) { return strcmp($a['name'], $b['name']); });
            $prevCut = $k > 0 ? $s['rounds'][$k - 1]['cutoff'] : null;
            $rounds[] = [
                'cutoff' => $r['cutoff'], 'dispatch' => $r['dispatch'], 'from' => $prevCut, 'at' => $at,
                'state' => $date < $today ? 'past' : ($date > $today ? 'upcoming' : ($at <= $now ? 'past' : ($date . ' ' . $r['cutoff'] <= $now ? 'closed' : 'open'))),
                'docs' => $list, 'items' => $agg,
                'awaiting' => count(array_filter($list, function ($x) { return $x['awaiting']; })),
            ];
        }
        return $base + ['rounds' => $rounds, 'overdue' => $overdue, 'later' => $later];
    } catch (Throwable $e) {
        error_log('getDispatchBoard: ' . $e->getMessage());
        return ['success' => false, 'message' => 'โหลดกระดานรอบจ่ายไม่สำเร็จ'];
    }
}
