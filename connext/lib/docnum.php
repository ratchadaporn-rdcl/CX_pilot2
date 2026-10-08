<?php
/**
 * CONNEXT — lib/docnum.php : เลขรันเอกสาร + PickingID
 *
 * Mirror พฤติกรรม GAS เป๊ะ:
 *   - เลขเอกสาร = TYPE + DDMMYY(เวลาไทย) + เลขรัน + GateCode (ถ้ามี)
 *     เช่น 'RD130526G01' (processRequisitionSubmission ฯลฯ:
 *     `${datePrefix}${String(running).padStart(2,'0')}` + gateId)
 *   - PickingID = 'PK' + DDMMYY + เลขรัน (submitPickingList_)
 *   - เลขรันนับต่อวันต่อชนิดเอกสาร แบบ GLOBAL ข้ามไซต์ (GAS สแกนทั้งชีต log
 *     โดยไม่กรอง SiteCode) — ขึ้นวันใหม่เริ่ม 1 ใหม่
 *   - รูปเลข = String(n).padStart(2,'0') → 1..9 = '01'..'09', 100 = '100'
 *     (padStart ไม่ตัด — เกิน 2 หลักคงตามจริง)
 *
 * แทน LockService ของ GAS ด้วย doc_counters + SELECT ... FOR UPDATE
 * ในทรานแซกชัน (รองรับทั้งกรณี caller ถือทรานแซกชันอยู่แล้ว และเรียกเดี่ยว)
 *
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/../helpers.php';

/**
 * เพิ่มเลขรันของ (counter_type, date_key) แบบ atomic แล้วคืนเลขใหม่
 * ใช้ SELECT ... FOR UPDATE — ถ้า caller ยังไม่เปิดทรานแซกชัน จะเปิด/ปิดเอง
 */
function _docCounterNext(PDO $pdo, string $counterType, string $dateKey): int {
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) { $pdo->beginTransaction(); }
    try {
        // สร้างแถว counter ของวันนี้ถ้ายังไม่มี (กัน race ด้วย ON DUPLICATE no-op)
        $ins = $pdo->prepare(
            'INSERT INTO doc_counters (counter_type, date_key, last_no) VALUES (?, ?, 0)
             ON DUPLICATE KEY UPDATE last_no = last_no'
        );
        $ins->execute([$counterType, $dateKey]);

        $sel = $pdo->prepare(
            'SELECT last_no FROM doc_counters WHERE counter_type = ? AND date_key = ? FOR UPDATE'
        );
        $sel->execute([$counterType, $dateKey]);
        $last = (int)$sel->fetchColumn();
        $next = $last + 1;

        $upd = $pdo->prepare(
            'UPDATE doc_counters SET last_no = ? WHERE counter_type = ? AND date_key = ?'
        );
        $upd->execute([$next, $counterType, $dateKey]);

        if ($ownTx) { $pdo->commit(); }
        return $next;
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

/** เลขรันแบบ GAS: String(n).padStart(2,'0') — 1→'01', 100→'100' (3 ตัวอักษร) */
function _docRunningStr(int $n): string {
    return str_pad((string)$n, 2, '0', STR_PAD_LEFT);
}

/**
 * เลขเอกสารถัดไป เช่น nextDocNo($pdo, 'RD', 'G01') → 'RD130526G01'
 * $gateCode ว่าง → ไม่ต่อท้าย (GAS: `gateId ? baseId + gateId : baseId`)
 */
function nextDocNo(PDO $pdo, string $type, string $gateCode): string {
    $type     = strtoupper(trim($type));
    $gateCode = trim($gateCode);
    $dateKey  = docDateKey(); // DDMMYY เวลาไทย (mirror bangkokDate_)
    $n        = _docCounterNext($pdo, $type, $dateKey);
    $base     = $type . $dateKey . _docRunningStr($n);
    return $gateCode !== '' ? $base . $gateCode : $base;
}

/** PickingID ถัดไป: 'PK{DDMMYY}{XX}' (mirror submitPickingList_) */
function nextPickingId(PDO $pdo): string {
    $dateKey = docDateKey();
    $n       = _docCounterNext($pdo, 'PK', $dateKey);
    return 'PK' . $dateKey . _docRunningStr($n);
}
