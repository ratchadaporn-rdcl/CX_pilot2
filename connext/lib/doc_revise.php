<?php
/**
 * CONNEXT — lib/doc_revise.php : ตีกลับใบให้ผู้ขอแก้ยอด (2026-10-06)
 *
 * ผู้ใช้สั่ง 6 ต.ค.: ใบที่ของไม่พอต้องอนุมัติไม่ได้ และ "ตีกลับไปให้ต้นทางแก้ยอดได้"
 *   1) ผู้อนุมัติกด "ตีกลับให้แก้" + เหตุผล (rpc_updateApprovalStatus · newStatus = DOC_ST_REVISE · args[5] = เหตุผล)
 *      → ใบ = "Awaiting revision" · แจ้งผู้ขอในแอป · ไม่จองของ (ยอดจองนับเฉพาะใบอนุมัติแล้ว)
 *   2) ผู้ขอแก้จำนวนที่หน้า "การอนุมัติ" (ลดได้อย่างเดียว · ตั้ง 0 = ตัดรายการ · ต้องเหลืออย่างน้อย 1 รายการ ·
 *      เพิ่มวัสดุ/เปลี่ยนประตูไม่ได้) แล้ว "ส่งใหม่" (rpc_reviseRequisition) → ตรวจของที่ประตูอีกรอบ →
 *      ใบกลับเป็น "Awaiting approval" เลขเดิม ผู้อนุมัติเดิม · แจ้งผู้อนุมัติในแอป
 *   3) หรือผู้ขอยกเลิกใบ (rpc_cancelRequisition เดิม — สถานะนี้ยกเลิกได้)
 *
 * ชื่อสถานะ "Awaiting revision" ตั้งใจให้มีคำว่า awaiting: คิวอนุมัติ (LIKE '%awaiting%') ดึงขึ้นมาเอง ·
 * ตัวช่วยสถานะแบบ substring ทั้งระบบถือเป็น "ยังไม่จบ/รอ" · แต่ไม่ตรง 'awaiting approval' →
 * อนุมัติไม่ได้ (rpc_updateApprovalStatus เทียบตรงตัว) · ไม่จอง (recalcPending) · ไม่ขึ้นกระดานรอบจ่าย
 * ไม่มีคำว่า return / approved / reject / cancel (ตัวตรวจสถานะแบบ substring ที่อื่นจึงไม่สับสน)
 *
 * บันทึก: activity_log action 'revise_request' (new_value = JSON {note, short[]}) · 'revise_submit' (old/new qty)
 *
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/borrow.php';   // borrowNotify (user_notices)

const DOC_ST_REVISE = 'Awaiting revision';

/** [rev2] สถานะที่ผู้ขอแก้จำนวนได้: รออนุมัติ (แก้เองก่อนผู้อนุมัติดู) · ตีกลับให้แก้ */
function docReviseEditableStatus(string $status): bool {
    $s = trim($status);
    return strcasecmp($s, Doc::ST_AWAITING) === 0 || strcasecmp($s, DOC_ST_REVISE) === 0;
}

/** ชนิดใบที่ต้องตรวจของก่อนอนุมัติ (ใบเบิกออก) */
function docReviseStockTypes(): array {
    return ['RD', 'OD', 'BD', 'TD', 'TG'];
}

/**
 * ยอดพร้อมเบิกของรายการในใบ ณ ประตูของใบ — on_hand − pending (ใบอื่นที่อนุมัติแล้วจองไว้)
 *   ใบที่ยังไม่อนุมัติไม่อยู่ใน pending จึงไม่ต้องหักยอดของตัวเอง
 *   วัสดุที่ยังไม่มีแถวรายประตูเลย (ข้อมูลยุคก่อนแยกประตู) / ใบไม่มีประตู → ยอดรวมไซต์
 * @param array $need mat_code → qty (รวมแล้ว)
 * @return array mat_code → ['onHand','pending','avail','need','short','scope' => 'gate'|'site','gate' => code]
 */
function docReviseStockCheck(PDO $pdo, int $projectId, ?int $gateId, array $need, bool $lock = false): array {
    $out = [];
    if (!$need) { return $out; }
    $gateCode = '';
    if ($gateId !== null && $gateId > 0) {
        $g = $pdo->prepare('SELECT gate_code FROM gates WHERE id = ?');
        $g->execute([$gateId]);
        $gateCode = strtoupper(trim((string)$g->fetchColumn()));
    }
    $mq = $pdo->prepare('SELECT id FROM materials WHERE mat_code = ?');
    $bq = $pdo->prepare('SELECT on_hand, pending FROM stock_balances WHERE project_id = ? AND material_id = ?' . ($lock ? ' FOR UPDATE' : ''));
    foreach ($need as $mc => $q) {
        $q = (float)$q;
        $mq->execute([$mc]);
        $mid = $mq->fetchColumn();
        if ($mid === false) { continue; }   // ไม่มีใน master = ไม่ตรวจ (พฤติกรรมเดิมของ guard อนุมัติ)
        $mid = (int)$mid;
        $row = null;
        if ($gateCode !== '') {
            $ga = stockGateAvail($pdo, $projectId, $mid, $gateCode, $lock);
            if ($ga['any']) {
                $row = ['onHand' => $ga['on_hand'], 'pending' => $ga['pending'], 'avail' => $ga['avail'], 'scope' => 'gate', 'gate' => $gateCode];
            }
        }
        if ($row === null) {
            $bq->execute([$projectId, $mid]);
            $b = $bq->fetch();
            $on = $b ? (float)$b['on_hand'] : 0.0;
            $pe = $b ? (float)$b['pending'] : 0.0;
            $row = ['onHand' => $on, 'pending' => $pe, 'avail' => $on - $pe, 'scope' => 'site', 'gate' => $gateCode];
        }
        $row['need']  = $q;
        $row['short'] = $q > $row['avail'] + 0.0005;
        $out[$mc] = $row;
    }
    return $out;
}

/** ข้อความสั้นของรายการที่ไม่พอ เช่น "MRO… ขอ 21 พร้อมเบิกที่ G01 6 (ในคลัง 21 · อนุมัติจองแล้ว 15)" */
function docReviseShortText(string $matCode, array $s): string {
    $n = function ($v) { return stockNumStr($v); };
    return $matCode . ' ขอ ' . $n($s['need']) . ' พร้อมเบิก' . ($s['scope'] === 'gate' ? 'ที่ ' . $s['gate'] : '') . ' '
         . $n(max(0, $s['avail'])) . ' (ในคลัง ' . $n($s['onHand']) . ($s['pending'] > 0.0005 ? ' · อนุมัติจองแล้ว ' . $n($s['pending']) : '') . ')';
}

/** เหตุผลตีกลับล่าสุดของแต่ละใบ → doc_no → ['note','by','at','short'] */
function docReviseLatestRequests(PDO $pdo, array $docNos): array {
    $out = [];
    $docNos = array_values(array_unique(array_filter(array_map('strval', $docNos))));
    if (!$docNos) { return $out; }
    $ph = implode(',', array_fill(0, count($docNos), '?'));
    $st = $pdo->prepare("SELECT entity_id, user_name, new_value, created_at FROM activity_log
                          WHERE entity_type = 'document' AND action = 'revise_request' AND entity_id IN ($ph)
                          ORDER BY id DESC");
    $st->execute($docNos);
    foreach ($st->fetchAll() as $r) {
        $k = (string)$r['entity_id'];
        if (isset($out[$k])) { continue; }
        $j = json_decode((string)$r['new_value'], true);
        $out[$k] = ['note' => is_array($j) ? (string)($j['note'] ?? '') : (string)$r['new_value'],
                    'short' => is_array($j) ? (array)($j['short'] ?? []) : [],
                    'by' => (string)$r['user_name'], 'at' => substr((string)$r['created_at'], 0, 16)];
    }
    return $out;
}

/**
 * reviseRequisition(docNo, items[{matCode, qty}], note) — ผู้ขอแก้จำนวนแล้วส่งใหม่
 *   [rev2] ใบต้องเป็น "Awaiting approval" (แก้เองก่อนผู้อนุมัติดู) หรือ "Awaiting revision" (ถูกตีกลับ) · ผู้ขอ = บัญชีที่ล็อกอิน · จำนวนใหม่ 0 ≤ qty ≤ จำนวนเดิม (0 = ตัดรายการ)
 *   ต้องเหลืออย่างน้อย 1 รายการ · ตรวจของที่ประตูของใบ (on_hand − ยอดที่อนุมัติจองแล้ว) ก่อนเขียน
 *   ผ่าน → แก้ document_items · ใบ = Awaiting approval (ผู้อนุมัติเดิม) · activity_log · แจ้งผู้อนุมัติ/ผู้ตีกลับ
 */
function rpc_reviseRequisition(PDO $pdo, ?array $user, array $args) {
    try {
        if (!$user) { return ['success' => false, 'message' => 'session หมดอายุ — ล็อกอินใหม่']; }
        $docNo = strtoupper(trim((string)($args[0] ?? '')));
        $want  = is_array($args[1] ?? null) ? $args[1] : [];
        $note  = trim(mb_substr((string)($args[2] ?? ''), 0, 255, 'UTF-8'));
        $me    = borrowEffectiveName($user);
        if ($docNo === '') { return ['success' => false, 'message' => 'ไม่พบเลขเอกสาร']; }

        $pdo->beginTransaction();
        try {
            $ds = $pdo->prepare('SELECT id, doc_no, doc_type, project_id, gate_id, status, requester_username, approver_username
                                   FROM documents WHERE doc_no = ? FOR UPDATE');
            $ds->execute([$docNo]);
            $doc = $ds->fetch();
            $err = null;
            if (!$doc) {
                $err = 'ไม่พบใบ ' . $docNo;
            } elseif (!docReviseEditableStatus((string)$doc['status'])) {
                $err = 'ใบ ' . $docNo . ' แก้ไม่ได้แล้ว (สถานะ: ' . (string)$doc['status'] . ') — แก้ได้เฉพาะตอนรออนุมัติหรือถูกตีกลับ';
            } elseif (!eqUser((string)$doc['requester_username'], $me)) {
                $err = 'แก้ได้เฉพาะผู้ขอของใบนี้';
            }
            if ($err !== null) { $pdo->rollBack(); return ['success' => false, 'message' => $err]; }
            $docId     = (int)$doc['id'];
            $projectId = (int)$doc['project_id'];
            $wasRevise = strcasecmp(trim((string)$doc['status']), DOC_ST_REVISE) === 0;   // [rev2]

            $it = $pdo->prepare('SELECT id, mat_code, qty FROM document_items WHERE document_id = ? ORDER BY id FOR UPDATE');
            $it->execute([$docId]);
            $rows = $it->fetchAll();

            // จำนวนใหม่ต่อรหัส (รายการเดียวกันหลายแถว → แบ่งตามลำดับแถว) — รับเฉพาะรหัสที่มีในใบ
            $newBy = [];
            foreach ($want as $w) {
                if (!is_array($w)) { continue; }
                $mc = strtoupper(trim((string)($w['matCode'] ?? $w['MatCode'] ?? '')));
                if ($mc === '' || !is_numeric($w['qty'] ?? $w['Qty'] ?? null)) { continue; }
                $newBy[$mc] = round((float)($w['qty'] ?? $w['Qty']), 3);
            }
            $oldBy = [];
            foreach ($rows as $r) {
                $mc = strtoupper(trim((string)$r['mat_code']));
                $oldBy[$mc] = ($oldBy[$mc] ?? 0.0) + (float)$r['qty'];
            }
            foreach ($newBy as $mc => $q) {
                if (!isset($oldBy[$mc])) { $pdo->rollBack(); return ['success' => false, 'message' => 'รหัส ' . $mc . ' ไม่มีในใบ — เพิ่มวัสดุใหม่ไม่ได้ (ออกใบใหม่แทน)']; }
                if ($q < 0) { $pdo->rollBack(); return ['success' => false, 'message' => 'จำนวนของ ' . $mc . ' ติดลบไม่ได้']; }
                if ($q > $oldBy[$mc] + 0.0005) {
                    $pdo->rollBack();
                    return ['success' => false, 'message' => $mc . ' แก้ได้ไม่เกินจำนวนเดิม ' . stockNumStr($oldBy[$mc]) . ' (ลดได้อย่างเดียว — ต้องการมากกว่าให้ออกใบใหม่)'];
                }
            }
            $final = [];
            foreach ($oldBy as $mc => $q) { $final[$mc] = array_key_exists($mc, $newBy) ? $newBy[$mc] : $q; }
            $keep = array_filter($final, function ($q) { return $q > 0.0005; });
            if (!$keep) {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'ตัดทุกรายการแล้ว — ถ้าไม่ต้องการของแล้วให้กด "ยกเลิกใบ" แทน'];
            }

            // ตรวจของที่ประตูของใบอีกรอบ (ล็อกแถวยอด) — ใบเบิกออกเท่านั้น
            if (in_array((string)$doc['doc_type'], docReviseStockTypes(), true)) {
                $chk = docReviseStockCheck($pdo, $projectId, $doc['gate_id'] !== null ? (int)$doc['gate_id'] : null, $keep, true);
                $short = [];
                foreach ($chk as $mc => $s) { if ($s['short']) { $short[] = docReviseShortText($mc, $s); } }
                if ($short) {
                    $pdo->rollBack();
                    return ['success' => false, 'message' => 'ของยังไม่พอ — ' . implode(' · ', $short) . "\nลดจำนวนลงอีก หรือยกเลิกใบ", 'short' => $chk];
                }
            }

            // เขียน: แบ่งจำนวนใหม่ลงแถวเดิมตามลำดับ (แถวที่เหลือ 0 = ลบ)
            $upd = $pdo->prepare('UPDATE document_items SET qty = ? WHERE id = ?');
            $del = $pdo->prepare('DELETE FROM document_items WHERE id = ?');
            $left = $final;
            foreach ($rows as $r) {
                $mc  = strtoupper(trim((string)$r['mat_code']));
                $q   = min((float)$r['qty'], max(0.0, $left[$mc] ?? 0.0));
                $left[$mc] = ($left[$mc] ?? 0.0) - $q;
                if ($q <= 0.0005) { $del->execute([(int)$r['id']]); }
                elseif (abs($q - (float)$r['qty']) > 0.0005) { $upd->execute([$q, (int)$r['id']]); }
            }
            $pdo->prepare('UPDATE documents SET status = ? WHERE id = ?')->execute([Doc::ST_AWAITING, $docId]);

            $changes = [];
            foreach ($oldBy as $mc => $q) {
                if (abs($q - $final[$mc]) > 0.0005) { $changes[] = $mc . ' ' . stockNumStr($q) . ' → ' . stockNumStr($final[$mc]); }
            }
            $logOld = json_encode(['items' => $oldBy], JSON_UNESCAPED_UNICODE);
            $logNew = json_encode(['items' => $final, 'note' => $note, 'changes' => $changes,
                                   'from' => $wasRevise ? DOC_ST_REVISE : Doc::ST_AWAITING], JSON_UNESCAPED_UNICODE);
            $pdo->prepare('INSERT INTO activity_log (entity_type, entity_id, user_name, action, old_value, new_value) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute(['document', $docNo, $me, 'revise_submit', $logOld, $logNew]);

            // แจ้งผู้อนุมัติที่กำหนดไว้ + ผู้ที่ตีกลับ (ในแอป)
            $last = $wasRevise ? (docReviseLatestRequests($pdo, [$docNo])[$docNo] ?? null) : null;
            $to   = array_filter([(string)$doc['approver_username'], $last ? $last['by'] : '']);
            if ($to && $changes) {   // [rev2] แจ้งเมื่อมีการแก้จริง
                borrowNotify($pdo, $projectId, $to, 'revise_back',
                    $wasRevise ? 'ผู้ขอแก้ใบ ' . $docNo . ' แล้ว — รออนุมัติอีกครั้ง' : 'ผู้ขอแก้จำนวนใบ ' . $docNo . ' (ยังรออนุมัติ)',
                    ($changes ? 'แก้: ' . implode(' · ', $changes) : 'ไม่ได้แก้จำนวน') . ($note !== '' ? "\nหมายเหตุ: " . $note : ''), $docNo, $me);
            }
            recalcPending($pdo, $projectId);
            $pdo->commit();
            return ['success' => true, 'docNo' => $docNo, 'changes' => $changes, 'wasRevise' => $wasRevise];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    } catch (Throwable $e) {
        error_log('reviseRequisition: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Server error: ' . $e->getMessage()];
    }
}
