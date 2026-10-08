<?php
/**
 * CONNEXT — lib/mango_bal.php : รายงานแปลงรหัส Mango → IC (หน้า mango_bal.php)
 *
 * ใช้ทำอะไร: ตอบ 2 คำถามที่ต้องใช้คู่กันเวลากระทบยอดกับ ERP เดิมที่ยังถือรหัส Mango
 *   1. **ไปข้างหน้า** — รหัส Mango ที่รับเข้ามา กลายเป็น IcCode ตัวไหนบ้าง เท่าไหร่
 *   2. **ย้อนกลับ** (มติ 39) — ยอดที่ตัดเบิก/คงเหลือใต้ IcCode คิดกลับเป็นหน่วยซื้อ
 *      ของรหัส Mango ได้เท่าไหร่ เพื่อเอาตัวเลขไปคีย์ปรับใน ERP ให้ตรง
 *
 * ── ทำไมหน้านี้ไม่ใช่ "ยอดคงเหลือ" อีกต่อไป (มติ 38) ──────────────────────
 * ตั้งแต่มติ 33 (ล้างยอดเริ่มนับหนึ่ง) + มติ 34 (เบิกได้เฉพาะรหัส IC) ของทุกบาททุกชิ้น
 * ในโครงการต้องอยู่ใต้รหัส IC เท่านั้น — **รหัส Mango จึงห้ามมียอดใน `stock_balances`
 * หรือแถวใน `project_materials` เลย** เจอเมื่อไหร่แปลว่ามีอะไรผิดปกติ ไม่ใช่รายงานยอด
 * (ของจริงที่เคยเกิด: `tools/seed_gate_stock.php` รุ่นเก่ายัดรหัส Mango 5 ตัวกลับเข้ามา
 *  หลังล้างข้อมูล — ยอด 147/46/101 ที่เห็นบนหน้าจอไม่ได้มาจากการทำงานของระบบเลย)
 * `mbOrphanMango()` จึงเป็น "สัญญาณเตือน" ไม่ใช่ตารางรายงาน · ทางเขียนถูกปิดที่
 * `ensureProjectMaterial()` ใน lib/stock.php แล้ว
 *
 * ── ตัวเลขมาจากไหน · ทำไมแยก 2 query ─────────────────────────────────────
 * หน่วยซื้อ (รับเข้า/แปลงแล้ว/ค้าง) อ่านจาก `buffer_lines` ซึ่งเก็บต่อบรรทัดใบสั่งซื้อ
 * ส่วนรายตัว IC อ่านหน่วยเก็บจาก `push_allocs.qty` เท่านั้น — **ตั้งใจไม่แตะ
 * `buffer_pushes.qty_consumed` ในฝั่ง IC** เพราะ 1 push แตกได้หลาย IC (มติ 10)
 * ถ้าไป SUM ผ่าน push_allocs จำนวนหน่วยซื้อจะถูกนับซ้ำตามจำนวน alloc
 * (push ตัด 5 แตก 2 IC → รวมได้ 10) พอรวมยอดตามรหัส Mango จะเพี้ยนทันที
 *
 * ── หน่วยซื้อเป็นส่วนหนึ่งของคีย์แถว ───────────────────────────────────────
 * `po_lines.unit_po_name` เป็นหน่วยรายบรรทัด รหัสเดียวกันซื้อคนละหน่วยได้ (ลัง/อัน)
 * แถวจึง key ด้วย **(mat_code, unit_po_name)** ไม่ใช่ mat_code เดี่ยว ๆ — กันบวก
 * คนละหน่วยรวมกันเงียบ ๆ · กรณีปกติ (หน่วยเดียว) หน้าตาเหมือนเดิมทุกอย่าง
 *
 * บรรทัด `line_kind='adjust'` (ค่าส่วนลด/ขนส่ง — มติ 18) ไม่เข้ารายงาน
 * ใบ push ที่ถูกยกเลิก (มติ 26) ยังโชว์คู่ Mango↔IC ไว้พร้อมป้าย แต่จำนวนเป็น 0
 * เพราะของเด้งกลับ buffer แล้ว — `buffer_lines.qty_consumed` หักคืนให้เองอยู่แล้ว
 *
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/../helpers.php';

/** คอลัมน์ของไฟล์ CSV — 1 แถวต่อ 1 คู่ (Mango × IC) ให้เอาไป pivot ต่อใน Excel ได้ */
function mbHeaders(): array {
    return [
        'รหัส Mango', 'ชื่อวัสดุ', 'หน่วยซื้อ',
        'รับเข้า', 'แปลงเป็น IC แล้ว', 'ค้างใน buffer',
        'ตัดเบิกแล้ว (หน่วยซื้อ)', 'คงเหลือในคลัง (หน่วยซื้อ)', 'ใบสั่งซื้อ',
        'รหัส IC', 'ชื่อวัสดุ (IC)', 'หน่วยเก็บ', 'จำนวนหน่วยเก็บ',
        'อัตรา (หน่วยซื้อ/หน่วยเก็บ)', 'ตัดเบิก (หน่วยเก็บ)', 'ตัดเบิก (หน่วยซื้อ)',
        'คงเหลือ (หน่วยเก็บ)', 'คงเหลือ (หน่วยซื้อ)', 'สถานะ',
    ];
}

/**
 * ตัวเลขสำหรับ CSV — ทศนิยม 4 ตำแหน่ง (หน้าจอใช้ fmtQ 2 ตำแหน่งเหมือนทั้งระบบ)
 * ถัวเฉลี่ยให้เศษยาวเสมอ ปัดที่ 2 ตั้งแต่ในไฟล์แล้วเอาไปคำนวณต่อใน Excel ยอดจะเพี้ยน
 */
function mbNum($v): string {
    $s = rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.');
    return $s === '' || $s === '-0' ? '0' : $s;
}

/**
 * ไซต์ที่หน้านี้แสดง — R0 เลือกได้ทุกไซต์ · คนอื่นถูกล็อกไซต์ตัวเอง
 * (ล้อ uiGuard(): can_req เห็นได้เฉพาะไซต์ตัวเอง)
 */
function mbResolveProject(array $user, $requested): int {
    $own = (int)($user['projectId'] ?? 0);
    if ((string)($user['roleLevel'] ?? '') !== 'R0') { return $own; }
    $want = (int)$requested;
    return $want > 0 ? $want : $own;
}

/**
 * รายงานหลัก — 1 แถว = (รหัส Mango × หน่วยซื้อ) ของไซต์นั้น
 *
 * @param bool   $onlyLeft เอาเฉพาะแถวที่ยังมีของค้างใน buffer (รับแล้วยังไม่แปลงครบ)
 * @param string $q        ค้นด้วยรหัส Mango หรือชื่อวัสดุ
 */
function mbRows(PDO $pdo, int $projectId, bool $onlyLeft = false, string $q = ''): array {
    $w = ['bl.project_id = ?', "pl.line_kind <> 'adjust'"];
    $p = [$projectId];
    if ($onlyLeft) { $w[] = 'bl.qty_received > bl.qty_consumed'; }
    if (trim($q) !== '') {
        $w[]  = '(pl.mat_code LIKE ? ESCAPE \'!\' OR pl.mat_name LIKE ? ESCAPE \'!\' OR m.name LIKE ? ESCAPE \'!\')';
        $like = '%' . likeEscape(trim($q)) . '%';
        $p[]  = $like;
        $p[]  = $like;
        $p[]  = $like;
    }

    $st = $pdo->prepare(
        'SELECT pl.mat_code,
                COALESCE(NULLIF(m.name, \'\'), MAX(pl.mat_name)) AS mat_name,
                bl.unit_po_name AS unit_buy,
                SUM(bl.qty_received) AS qty_recv,
                SUM(bl.qty_consumed) AS qty_conv,
                m.id IS NOT NULL     AS in_registry,
                GROUP_CONCAT(DISTINCT bl.po_no ORDER BY bl.po_no SEPARATOR \', \') AS po_nos
           FROM buffer_lines bl
           JOIN po_lines  pl ON pl.po_no = bl.po_no AND pl.line_no = bl.line_no
      LEFT JOIN materials m  ON m.mat_code = pl.mat_code AND m.code_type <> \'ic\'
          WHERE ' . implode(' AND ', $w) . '
       GROUP BY pl.mat_code, bl.unit_po_name, m.id, m.name
       ORDER BY pl.mat_code, bl.unit_po_name'
    );
    $st->execute($p);

    $rows = [];
    $keys = [];
    foreach ($st->fetchAll() as $r) {
        $recv = (float)$r['qty_recv'];
        $conv = (float)$r['qty_conv'];
        $rows[] = [
            'mat_code'    => (string)$r['mat_code'],
            'mat_name'    => (string)$r['mat_name'],
            'unit_buy'    => (string)$r['unit_buy'],
            'qty_recv'    => $recv,
            'qty_conv'    => $conv,
            'qty_left'    => $recv - $conv,
            'po_nos'      => (string)($r['po_nos'] ?? ''),
            'in_registry' => (int)$r['in_registry'] === 1,
            'ics'         => [],
        ];
        $keys[] = (string)$r['mat_code'];
    }

    // รายตัว IC — ผูกทีเดียวทั้งก้อน ไม่ยิง query ต่อแถว
    if ($rows) {
        $br = mbIcBreakdown($pdo, $projectId, array_values(array_unique($keys)));
        foreach ($rows as $i => $r) {
            $k   = $r['mat_code'] . '|' . $r['unit_buy'];
            $ics = $br[$k] ?? [];
            $rows[$i]['ics'] = $ics;

            // รวมยอดที่แปลงกลับเป็นหน่วยซื้อแล้ว (มติ 39)
            $issued = 0.0; $onhand = 0.0; $split = false;
            foreach ($ics as $ic) {
                $issued += $ic['issued_buy'];
                $onhand += $ic['onhand_buy'];
                if ($ic['split']) { $split = true; }
            }
            $rows[$i]['qty_issued_buy'] = $issued;
            $rows[$i]['qty_onhand_buy'] = $onhand;
            $rows[$i]['has_split']      = $split;
            // ค้าง buffer + ตัดเบิก + คงเหลือ ควรเท่ารับเข้า — ต่างเมื่อไหร่แปลว่ามีของ
            // หลุดออกนอกสาย (ดู mbAdjustRows) หรือ push ถูกยกเลิกหลังของเข้าสต๊อกไปแล้ว
            $rows[$i]['gap'] = $r['qty_recv'] - ($r['qty_left'] + $issued + $onhand);
        }
    }
    return $rows;
}

/**
 * ยอดสต๊อกปัจจุบันของทุก IC ในไซต์ — ตัวหารของสูตรแปลงกลับ (มติ 39)
 * @return array ic_code → ['qty_in','qty_out','on_hand','unit']
 */
function mbIcStock(PDO $pdo, int $projectId): array {
    $st = $pdo->prepare(
        "SELECT m.mat_code, m.unit, b.qty_in, b.qty_out, b.on_hand
           FROM stock_balances b
           JOIN materials m ON m.id = b.material_id
          WHERE b.project_id = ? AND m.code_type = 'ic'"
    );
    $st->execute([$projectId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(string)$r['mat_code']] = [
            'qty_in'  => (float)$r['qty_in'],
            'qty_out' => (float)$r['qty_out'],
            'on_hand' => (float)$r['on_hand'],
            'unit'    => (string)$r['unit'],
        ];
    }
    return $out;
}

/**
 * IC ที่แตกออกมาจากแต่ละ (รหัส Mango, หน่วยซื้อ) + **การแปลงยอดกลับเป็นหน่วยซื้อ**
 *
 * ── หน่วยซื้อต่อ IC: ทำไมต้องหารด้วยยอดรวมของ push ──────────────────────
 * ⚠ ห้าม SUM `buffer_pushes.qty_consumed` ผ่าน `push_allocs` ตรง ๆ — 1 push แตกได้
 * หลาย IC (มติ 10) จำนวนหน่วยซื้อจะถูกนับซ้ำตามจำนวน alloc (ดูหัวไฟล์)
 * จึงเฉลี่ยลงราย IC ด้วยสัดส่วนหน่วยเก็บ: `qty_consumed × a.qty ÷ Σ(a.qty ของ push นั้น)`
 * push ที่มี alloc เดียวได้ตัวคูณ 1 พอดี — สูตรเดียวคลุมทั้งสองเคส
 * push ที่แตกหลาย IC ติดธง `split` เพราะตัวเลขเป็น "ค่าเฉลี่ยจากการแบ่ง" ไม่ใช่ของที่วัดมา
 *
 * ── แปลงยอดตัดเบิก/คงเหลือกลับ (ถัวเฉลี่ยถ่วงน้ำหนัก · มติ 39) ────────────
 * อัตราไม่คงที่: IC เดียวกันรับมาได้หลายอัตรา (5 A → 5 B ครั้งหนึ่ง · 5 A → 10 B อีกครั้ง)
 * และ 1 IC รับมาจากหลายรหัส Mango ได้ จึงเฉลี่ยทั้งกอง:
 *
 *     ตัดเบิก(หน่วยซื้อ) = qty_out × buy ÷ qty_in       ของ IC ตัวนั้น
 *     คงเหลือ(หน่วยซื้อ) = on_hand × buy ÷ qty_in
 *
 * **หารด้วย `qty_in` จริงของ IC ไม่ใช่ Σ store ของ push** — ของที่เข้ามานอกสาย PO
 * (ฟอร์มรับเข้าคลัง = ปรับยอด) ไม่มี Mango รองรับ ถ้าหารด้วยฐาน push ยอดที่ป้อน ERP
 * จะเกินของที่ซื้อจริง · ส่วนเกินไปโผล่ที่ `mbAdjustRows()` แทน ไม่ถูกแปลง
 *
 * เอกลักษณ์ที่ต้องจริงเสมอ: ค้าง buffer + ตัดเบิกแล้ว + คงเหลือ = รับเข้า
 *
 * @return array คีย์ 'mat_code|unit_buy' → รายการ IC
 */
function mbIcBreakdown(PDO $pdo, int $projectId, array $matCodes): array {
    if (!$matCodes) { return []; }
    $ph    = implode(',', array_fill(0, count($matCodes), '?'));
    $stock = mbIcStock($pdo, $projectId);

    $st = $pdo->prepare(
        "SELECT pl.mat_code, bl.unit_po_name AS unit_buy, a.ic_code,
                COALESCE(m.name, '') AS ic_name, COALESCE(m.unit, '') AS unit_store,
                SUM(CASE WHEN bp.cancelled_at IS NULL THEN a.qty ELSE 0 END) AS qty_store,
                SUM(CASE WHEN bp.cancelled_at IS NULL
                         THEN bp.qty_consumed * a.qty / t.tot_qty ELSE 0 END)  AS qty_buy,
                MAX(t.n_alloc > 1) AS is_split,
                SUM(bp.cancelled_at IS NULL) AS n_live,
                GROUP_CONCAT(DISTINCT bl.po_no ORDER BY bl.po_no SEPARATOR ', ') AS po_nos
           FROM push_allocs   a
           JOIN buffer_pushes bp ON bp.push_id = a.push_id
           JOIN buffer_lines  bl ON bl.id = bp.buffer_id
           JOIN po_lines      pl ON pl.po_no = bl.po_no AND pl.line_no = bl.line_no
           JOIN (SELECT push_id, SUM(qty) AS tot_qty, COUNT(*) AS n_alloc
                   FROM push_allocs GROUP BY push_id) t ON t.push_id = a.push_id
      LEFT JOIN materials     m  ON m.id = a.material_id
          WHERE bp.project_id = ? AND pl.mat_code IN ($ph)
       GROUP BY pl.mat_code, bl.unit_po_name, a.ic_code, m.name, m.unit
       ORDER BY pl.mat_code, bl.unit_po_name, a.ic_code"
    );
    $st->execute(array_merge([$projectId], $matCodes));

    // ยอดที่ push เข้าไปทั้งหมดต่อ IC — ใช้กันตัวหารเล็กเกินจริง (ดูด้านล่าง)
    $pushed = [];
    $pq = $pdo->prepare(
        "SELECT a.ic_code, SUM(CASE WHEN bp.cancelled_at IS NULL THEN a.qty ELSE 0 END) AS s
           FROM push_allocs a JOIN buffer_pushes bp ON bp.push_id = a.push_id
          WHERE bp.project_id = ? GROUP BY a.ic_code"
    );
    $pq->execute([$projectId]);
    foreach ($pq->fetchAll() as $r) { $pushed[(string)$r['ic_code']] = (float)$r['s']; }

    $out = [];
    foreach ($st->fetchAll() as $r) {
        $ic    = (string)$r['ic_code'];
        $buy   = (float)$r['qty_buy'];
        $store = (float)$r['qty_store'];
        $sk    = $stock[$ic] ?? null;

        // ตัวหาร = qty_in จริงของ IC · เป็น 0 ได้เมื่อ push ถูกยกเลิกหมด (ของยังไม่เคยเข้าสต๊อก)
        //
        // แต่ถ้ายอดที่ push เข้าไปรวมแล้ว **มากกว่า** qty_in (ข้อมูลไม่สอดคล้อง เช่น มีคน
        // แก้ยอดตรงฐาน หรือใบถูกยกเลิกครึ่ง ๆ) การหารด้วย qty_in จะทำให้แต่ละรหัส Mango
        // ได้ส่วนแบ่งเกินของที่มีอยู่จริง — หารด้วยตัวที่มากกว่าเสมอเพื่อไม่ให้ยอดที่ป้อน ERP
        // เกินความจริง · ผลคือแถวนั้นจะมีคอลัมน์ "ต่าง" ขึ้นให้เห็นแทนที่จะเงียบ
        $in      = $sk ? max($sk['qty_in'], $pushed[$ic] ?? 0.0) : 0.0;
        $canConv = $sk !== null && $in > 0.0 && $buy > 0.0;

        $out[(string)$r['mat_code'] . '|' . (string)$r['unit_buy']][] = [
            'ic_code'    => $ic,
            'ic_name'    => (string)$r['ic_name'],
            'unit_store' => (string)$r['unit_store'],
            'qty_store'  => $store,
            'qty_buy'    => $buy,
            // อัตรา = หน่วยซื้อต่อ 1 หน่วยเก็บ (เช่น 1 ขวด = 0.5 ปี๊บ)
            'rate'       => $store > 0.0 ? $buy / $store : 0.0,
            'issued_buy' => $canConv ? $sk['qty_out'] * $buy / $in : 0.0,
            'onhand_buy' => $canConv ? $sk['on_hand'] * $buy / $in : 0.0,
            'issued_ic'  => $sk ? $sk['qty_out'] : 0.0,
            'onhand_ic'  => $sk ? $sk['on_hand'] : 0.0,
            'split'      => (int)$r['is_split'] === 1,
            'po_nos'     => (string)($r['po_nos'] ?? ''),
            'cancelled'  => (int)$r['n_live'] === 0,
        ];
    }
    return $out;
}

/**
 * IC ที่มีของเข้ามา "นอกสาย PO" — แปลงกลับเป็นรหัส Mango ไม่ได้
 *
 * ฟอร์มรับเข้าคลังในแอปหลักยิง `processInboundBatch` ตรง ๆ ไม่ผ่าน buffer จึงเพิ่ม
 * `qty_in` ให้ IC ได้โดยไม่มี push รองรับ — ตกลงกันว่านับเป็น **การปรับยอด** ไม่ใช่การซื้อ
 * ของก้อนนี้ไม่มีรหัส Mango ต้นทางและไม่มีอัตราแปลง จึงต้องแยกออกจากยอดที่ป้อน ERP
 * (ถ้าเหมารวมเข้าไปด้วย ยอดที่ป้อนจะเกินของที่ซื้อจริง)
 *
 * ปกติตารางนี้ต้องว่าง — ดรอปดาวน์ฟอร์มรับเข้าคลังถูกกรองเหลือเฉพาะ IC แล้ว แต่ยัง
 * "เพิ่มยอดให้ IC เดิม" ได้อยู่ ซึ่งเป็นการปรับยอดที่ตั้งใจให้ทำได้
 */
function mbAdjustRows(PDO $pdo, int $projectId): array {
    $st = $pdo->prepare(
        "SELECT m.mat_code, m.name, m.unit, b.qty_in, b.qty_out, b.on_hand,
                COALESCE(p.base_store, 0) AS base_store
           FROM stock_balances b
           JOIN materials m ON m.id = b.material_id
      LEFT JOIN (SELECT a.ic_code, SUM(CASE WHEN bp.cancelled_at IS NULL THEN a.qty ELSE 0 END) AS base_store
                   FROM push_allocs a
                   JOIN buffer_pushes bp ON bp.push_id = a.push_id
                  WHERE bp.project_id = ?
               GROUP BY a.ic_code) p ON p.ic_code = m.mat_code
          WHERE b.project_id = ? AND m.code_type = 'ic'
            AND b.qty_in > COALESCE(p.base_store, 0) + 0.0001
       ORDER BY m.mat_code"
    );
    $st->execute([$projectId, $projectId]);

    $out = [];
    foreach ($st->fetchAll() as $r) {
        $in   = (float)$r['qty_in'];
        $base = (float)$r['base_store'];
        $out[] = [
            'ic_code'    => (string)$r['mat_code'],
            'name'       => (string)$r['name'],
            'unit'       => (string)$r['unit'],
            'qty_in'     => $in,
            'base_store' => $base,
            'adjust'     => $in - $base,
            // ส่วนแบ่งของยอดตัด/คงเหลือที่ตกอยู่กับก้อนปรับยอด (ตามสัดส่วนเดียวกับสูตรหลัก)
            'issued'     => $in > 0 ? (float)$r['qty_out'] * ($in - $base) / $in : 0.0,
            'onhand'     => $in > 0 ? (float)$r['on_hand'] * ($in - $base) / $in : 0.0,
        ];
    }
    return $out;
}

/**
 * สัญญาณเตือน — รหัส Mango ที่ยังมียอด/ยังผูกกับไซต์อยู่ (ต้องว่างเสมอ · มติ 38)
 *
 * ตรวจ 2 ทาง เพราะเสียหายคนละแบบ:
 *   'balance' = มีแถวใน `stock_balances` → ยอดของโครงการไม่ตรงกับสาย PO อีกต่อไป
 *   'form'    = มีแถวใน `project_materials` → รหัสนั้นกลับเข้าดรอปดาวน์ฟอร์มเบิก
 *               ให้คนเบิกของที่ไม่มีรหัส IC ได้ (มติ 34 พัง)
 * แถวเดียวติดได้ทั้งสองธง
 */
function mbOrphanMango(PDO $pdo, int $projectId): array {
    $st = $pdo->prepare(
        "SELECT m.mat_code, m.name, m.unit,
                b.qty_in, b.qty_out, b.on_hand, b.pending,
                b.material_id  IS NOT NULL AS has_balance,
                pm.material_id IS NOT NULL AS has_form
           FROM materials m
      LEFT JOIN stock_balances    b  ON b.material_id  = m.id AND b.project_id  = ?
      LEFT JOIN project_materials pm ON pm.material_id = m.id AND pm.project_id = ?
          WHERE m.code_type <> 'ic'
            AND (b.material_id IS NOT NULL OR pm.material_id IS NOT NULL)
       ORDER BY m.mat_code"
    );
    $st->execute([$projectId, $projectId]);

    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = [
            'mat_code'    => (string)$r['mat_code'],
            'name'        => (string)$r['name'],
            'unit'        => (string)$r['unit'],
            'qty_in'      => (float)$r['qty_in'],
            'qty_out'     => (float)$r['qty_out'],
            'on_hand'     => (float)$r['on_hand'],
            'pending'     => (float)$r['pending'],
            'has_balance' => (int)$r['has_balance'] === 1,
            'has_form'    => (int)$r['has_form'] === 1,
        ];
    }
    return $out;
}

/**
 * ยอดรวมท้ายตาราง — **นับจำนวนรหัสเท่านั้น ไม่บวกจำนวนของ**
 * แต่ละแถวเป็นคนละหน่วยซื้อได้ (ใบ/ถัง/ปี๊บ) บวกกันแล้วไม่มีความหมาย
 */
function mbTotals(array $rows): array {
    $t = ['n' => count($rows), 'left_n' => 0, 'conv_n' => 0, 'ic' => 0,
          'issued_n' => 0, 'split_n' => 0, 'gap_n' => 0];
    $seenIc = [];
    foreach ($rows as $r) {
        if ($r['qty_left'] > 0.0) { $t['left_n']++; }
        if ($r['qty_conv'] > 0.0) { $t['conv_n']++; }
        if (($r['qty_issued_buy'] ?? 0.0) > 0.0) { $t['issued_n']++; }
        if (!empty($r['has_split'])) { $t['split_n']++; }
        if (abs($r['gap'] ?? 0.0) > 0.0001) { $t['gap_n']++; }
        foreach ($r['ics'] as $ic) { $seenIc[$ic['ic_code']] = true; }
    }
    $t['ic'] = count($seenIc);
    return $t;
}

/** สถานะของแถว/คู่ — ข้อความเดียวกันทั้งหน้าจอ CSV และ PDF */
function mbStatusText(array $row, ?array $ic = null): string {
    if ($ic !== null) {
        return $ic['cancelled'] ? 'ใบถูกยกเลิก — ของเด้งกลับ buffer' : 'แปลงแล้ว';
    }
    return $row['qty_conv'] > 0.0 ? 'แปลงแล้วบางส่วน' : 'ยังไม่แปลง';
}

/**
 * เขียน CSV ลง stream — UTF-8 + BOM ให้ Excel อ่านภาษาไทยตรง
 * 1 แถว = 1 คู่ (Mango × IC) · แถวที่ยังไม่มี IC เลย ออกมา 1 แถวโดยช่อง IC ว่าง
 * ตัวเลขหน่วยซื้อซ้ำทุกแถวของรหัสเดียวกันโดยตั้งใจ — pivot ใน Excel ต้องการแบบนี้
 * (ผลรวมให้ใช้ค่าไม่ซ้ำ หรือ pivot ที่ระดับรหัส Mango)
 */
function mbWriteCsv($fh, array $rows, array $orphan = [], array $adjust = []): void {
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, mbHeaders());

    foreach ($rows as $r) {
        $head = [$r['mat_code'], $r['mat_name'], $r['unit_buy'],
                 mbNum($r['qty_recv']), mbNum($r['qty_conv']), mbNum($r['qty_left']),
                 mbNum($r['qty_issued_buy'] ?? 0), mbNum($r['qty_onhand_buy'] ?? 0), $r['po_nos']];
        if (!$r['ics']) {
            fputcsv($fh, array_merge($head, ['', '', '', '', '', '', '', '', '', mbStatusText($r)]));
            continue;
        }
        foreach ($r['ics'] as $ic) {
            fputcsv($fh, array_merge($head, [
                $ic['ic_code'], $ic['ic_name'], $ic['unit_store'], mbNum($ic['qty_store']),
                mbNum($ic['rate']),
                mbNum($ic['issued_ic']), mbNum($ic['issued_buy']),
                mbNum($ic['onhand_ic']), mbNum($ic['onhand_buy']),
                mbStatusText($r, $ic),
            ]));
        }
    }

    if ($adjust) {
        fputcsv($fh, []);
        fputcsv($fh, ['*** ของที่เข้ามานอกสายใบสั่งซื้อ (ปรับยอด) — ไม่มีรหัส Mango ต้นทางและไม่มีอัตราแปลง '
                    . 'จำนวนด้านล่างไม่ได้รวมอยู่ในยอดข้างบน และเอาไปคีย์ ERP ไม่ได้ ***']);
        fputcsv($fh, ['รหัส IC', 'ชื่อวัสดุ', 'หน่วยเก็บ', 'รับเข้าทั้งหมด',
                      'มาจากใบสั่งซื้อ', 'ปรับยอด', 'ตัดเบิกส่วนปรับยอด', 'คงเหลือส่วนปรับยอด']);
        foreach ($adjust as $r) {
            fputcsv($fh, [$r['ic_code'], $r['name'], $r['unit'], mbNum($r['qty_in']),
                          mbNum($r['base_store']), mbNum($r['adjust']),
                          mbNum($r['issued']), mbNum($r['onhand'])]);
        }
    }

    if (!$orphan) { return; }
    fputcsv($fh, []);
    fputcsv($fh, ['*** ผิดปกติ — รหัส Mango ด้านล่างยังมียอด/ยังผูกกับไซต์อยู่ '
                . 'ทั้งที่ของทุกชิ้นต้องอยู่ใต้รหัส IC เท่านั้น (มติ 33/34) แจ้งผู้ดูแลระบบ ***']);
    fputcsv($fh, ['รหัส Mango', 'ชื่อวัสดุ', 'หน่วย', 'รับเข้า', 'จ่ายออก', 'คงเหลือ', 'อาการ']);
    foreach ($orphan as $r) {
        $sym = [];
        if ($r['has_balance']) { $sym[] = 'มียอดคงเหลือ'; }
        if ($r['has_form'])    { $sym[] = 'อยู่ในฟอร์มเบิก'; }
        fputcsv($fh, [$r['mat_code'], $r['name'], $r['unit'], mbNum($r['qty_in']),
                      mbNum($r['qty_out']), mbNum($r['on_hand']), implode(' + ', $sym)]);
    }
}
