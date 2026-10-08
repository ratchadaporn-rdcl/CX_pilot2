<?php
/**
 * CONNEXT — lib/po_ocr.php : ตรวจ/จัดประเภทผลที่ Gemini อ่านมาจากใบสั่งซื้อ
 *
 * เฟส 0 ของสาย PO → OCR → buffer → IC (มติทั้งหมด: db/design_po_ocr_ic_v1.md)
 *
 *  · มติ 21 — กติกาตรวจตัวเองจากตัวใบ: ผลรวมบรรทัดต้องเท่ายอดท้ายใบ · VAT ต้องเท่า 7%
 *    ไม่ผ่าน = ห้ามสร้างใบคุมเงียบ ๆ ต้องให้คนดูก่อน
 *  · มติ 18 — บรรทัดที่ไม่ใช่ของจริง (ค่าส่วนลด/ขนส่ง/บริการ) ระบบตรวจเอง
 *    แล้วคนสลับกลับเป็น "ของจริง" ได้ในจอตรวจ
 *  · มติ 19/20 — ถอดรหัสไซต์จากเลขที่ PO และเทียบ Mat. Code กับ materials
 *
 * ไฟล์นี้ไม่แตะ DB นอกจาก SELECT — เฟส 0 ยังไม่เขียนอะไรลงฐาน
 *
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/../helpers.php';

/** ยอมรับความคลาดเคลื่อนระดับเศษสตางค์ (ทศนิยม 2 ตำแหน่ง) */
const PO_OCR_EPS_LINE = 0.01;
const PO_OCR_EPS_SUM  = 0.05;
const PO_OCR_VAT_RATE = 0.07;

/** แปลงค่าที่โมเดลส่งมาเป็น float อย่างปลอดภัย (เผื่อมาเป็นสตริงมีคอมมา) */
function poNum($v): float {
    if (is_int($v) || is_float($v)) { return (float)$v; }
    $s = trim((string)$v);
    if ($s === '') { return 0.0; }
    $s = str_replace([',', ' ', "\xc2\xa0"], '', $s);
    return is_numeric($s) ? (float)$s : 0.0;
}

/** ปัดเป็นสตางค์ */
function poMoney(float $v): float {
    return round($v, 2);
}

/** รหัสไซต์จากเลขที่ PO เช่น 'PO-KATU-000334' → 'KATU' (มติ 19) */
function poProjectCodeFromPoNo(string $poNo): string {
    if (preg_match('/^\s*PO-([A-Za-z0-9]+)-\d+\s*$/', $poNo, $m)) {
        return strtoupper($m[1]);
    }
    return '';
}

/**
 * จัดประเภทบรรทัด (มติ 18) — 'adjust' = รายการเงิน ไม่ใช่ของที่ต้องรับ
 * เกณฑ์อิงข้อมูลจริง: materials ไม่มีวัสดุที่หน่วยเป็น "บาท" สักตัว (0 จาก 6,814)
 * ส่วนคำนำหน้า PR ใช้ตัดสินไม่ได้ เพราะมีวัสดุจริงขึ้นต้น PR อยู่ 2 รายการ
 */
function poClassifyLine(array $line): array {
    $amount = poNum($line['amount'] ?? 0);
    $price  = poNum($line['unit_price'] ?? 0);
    $unit   = trim((string)($line['unit'] ?? ''));
    $name   = trim((string)($line['name'] ?? ''));

    $reasons = [];
    if ($amount < 0)        { $reasons[] = 'จำนวนเงินติดลบ'; }
    if ($price  < 0)        { $reasons[] = 'ราคา/หน่วยติดลบ'; }
    if ($unit === 'บาท')    { $reasons[] = 'หน่วยเป็น "บาท"'; }

    foreach (['ค่าส่วนลด', 'ส่วนลด', 'ค่าขนส่ง', 'ค่าบริการ', 'ค่าดำเนินการ'] as $kw) {
        if ($name !== '' && mb_strpos($name, $kw) === 0) {
            $reasons[] = 'ชื่อขึ้นต้นว่า "' . $kw . '"';
            break;
        }
    }

    return [
        'kind'    => empty($reasons) ? 'item' : 'adjust',
        'reasons' => $reasons,
    ];
}

/**
 * ตรวจเลขคณิตของบรรทัด — ใบตัวอย่างไม่เคยกรอกคอลัมน์ส่วนลด จึงยังไม่รู้ว่า
 * ส่วนลดเป็น "ต่อหน่วย" หรือ "ทั้งบรรทัด" → ลองทั้งสองสูตร ผ่านสูตรไหนก็บอกไป
 */
function poCheckLineMath(array $line): array {
    $qty   = poNum($line['qty'] ?? 0);
    $price = poNum($line['unit_price'] ?? 0);
    $disc  = poNum($line['discount'] ?? 0);
    $amt   = poNum($line['amount'] ?? 0);

    $asTotal   = poMoney($qty * $price - $disc);          // ส่วนลดทั้งบรรทัด
    $asPerUnit = poMoney($qty * ($price - $disc));        // ส่วนลดต่อหน่วย

    if (abs($asTotal - $amt) <= PO_OCR_EPS_LINE) {
        return ['ok' => true, 'formula' => $disc == 0.0 ? 'qty × ราคา' : 'qty × ราคา − ส่วนลด(ทั้งบรรทัด)', 'expect' => $asTotal];
    }
    if (abs($asPerUnit - $amt) <= PO_OCR_EPS_LINE) {
        return ['ok' => true, 'formula' => 'qty × (ราคา − ส่วนลดต่อหน่วย)', 'expect' => $asPerUnit];
    }
    return ['ok' => false, 'formula' => 'ไม่ตรงสักสูตร', 'expect' => $asTotal];
}

/**
 * กติกาตรวจตัวเองทั้งใบ (มติ 21)
 * @return array รายการเช็ค แต่ละตัว ['key','label','ok','expect','got','note']
 */
function poCheckTotals(array $po): array {
    $lines = isset($po['lines']) && is_array($po['lines']) ? $po['lines'] : [];

    $sumLines = 0.0;
    foreach ($lines as $l) { $sumLines += poNum($l['amount'] ?? 0); }
    $sumLines = poMoney($sumLines);

    $before = poMoney(poNum($po['sum_before_special_discount'] ?? 0));
    $spDisc = poMoney(poNum($po['special_discount'] ?? 0));
    $after  = poMoney(poNum($po['sum_after_special_discount'] ?? 0));
    $vat    = poMoney(poNum($po['vat'] ?? 0));
    $grand  = poMoney(poNum($po['grand_total'] ?? 0));

    $mk = function ($key, $label, $expect, $got, $eps, $note = '') {
        return [
            'key'    => $key,
            'label'  => $label,
            'expect' => poMoney((float)$expect),
            'got'    => poMoney((float)$got),
            'ok'     => abs((float)$expect - (float)$got) <= $eps,
            'note'   => $note,
        ];
    };

    $checks = [];
    $checks[] = $mk('lines_sum', 'Σ จำนวนเงินทุกบรรทัด = ยอดรวมก่อนหักส่วนลดพิเศษ',
                    $sumLines, $before, PO_OCR_EPS_SUM, count($lines) . ' บรรทัด');
    $checks[] = $mk('after_discount', 'ยอดก่อนหัก − ส่วนลดพิเศษ = ยอดหลังหัก',
                    $before - $spDisc, $after, PO_OCR_EPS_SUM);
    $checks[] = $mk('vat', 'ยอดหลังหัก × 7% = VAT',
                    poMoney($after * PO_OCR_VAT_RATE), $vat, PO_OCR_EPS_SUM);
    $checks[] = $mk('grand', 'ยอดหลังหัก + VAT = รวมเงินสุทธิ',
                    $after + $vat, $grand, PO_OCR_EPS_SUM);

    return $checks;
}

/** ตรวจความต่อเนื่องของเลขลำดับ — กันบรรทัดตกตอนข้ามหน้า (มติ 22) */
function poCheckLineNumbers(array $po): array {
    $lines = isset($po['lines']) && is_array($po['lines']) ? $po['lines'] : [];
    $nos   = [];
    foreach ($lines as $l) { $nos[] = (int)($l['line_no'] ?? 0); }

    $missing = [];
    $dup     = [];
    if (!empty($nos)) {
        $counts = array_count_values($nos);
        foreach ($counts as $n => $c) { if ($c > 1) { $dup[] = (int)$n; } }
        $max = max($nos);
        for ($i = 1; $i <= $max; $i++) {
            if (!isset($counts[$i])) { $missing[] = $i; }
        }
    }

    return [
        'count'   => count($lines),
        'max'     => empty($nos) ? 0 : max($nos),
        'missing' => $missing,
        'dup'     => $dup,
        'ok'      => empty($missing) && empty($dup) && !empty($nos),
    ];
}

/** เทียบ Mat. Code กับ materials + เทียบรหัสไซต์กับ projects (มติ 19/20) */
function poMatchMaster(PDO $pdo, array $po): array {
    $lines = isset($po['lines']) && is_array($po['lines']) ? $po['lines'] : [];

    $codes = [];
    foreach ($lines as $l) {
        $c = trim((string)($l['mat_code'] ?? ''));
        if ($c !== '') { $codes[$c] = true; }
    }
    $codes = array_keys($codes);

    $found = [];
    if (!empty($codes)) {
        $ph = implode(',', array_fill(0, count($codes), '?'));
        $st = $pdo->prepare("SELECT mat_code, name, unit, cat_id, char_id FROM materials WHERE mat_code IN ($ph)");
        $st->execute($codes);
        foreach ($st->fetchAll() as $r) { $found[(string)$r['mat_code']] = $r; }
    }

    // ไซต์: จากเลขที่ PO ก่อน ถ้าไม่ได้ค่อยใช้ที่โมเดลอ่านจากช่องโครงการ
    $fromNo   = poProjectCodeFromPoNo((string)($po['po_no'] ?? ''));
    $fromText = strtoupper(trim((string)($po['project_code'] ?? '')));
    $code     = $fromNo !== '' ? $fromNo : $fromText;

    $project = null;
    if ($code !== '') {
        $st = $pdo->prepare('SELECT id, code, name FROM projects WHERE code = ? LIMIT 1');
        $st->execute([$code]);
        $row = $st->fetch();
        if ($row) { $project = $row; }
    }

    return [
        'codes'         => $codes,
        'found'         => $found,
        'missing'       => array_values(array_diff($codes, array_keys($found))),
        'project_code'  => $code,
        'project_from_no'   => $fromNo,
        'project_from_text' => $fromText,
        'project_conflict'  => ($fromNo !== '' && $fromText !== '' && $fromNo !== $fromText),
        'project'       => $project,
    ];
}

/**
 * รวมทุกอย่างเป็นก้อนเดียวให้จอตรวจใช้
 * @return array ['lines','checks','numbering','master','summary']
 */
function poOcrReview(PDO $pdo, array $po): array {
    $master = poMatchMaster($pdo, $po);
    $rows   = [];
    $nItem  = 0;
    $nAdj   = 0;
    $nBadMath = 0;

    foreach ((isset($po['lines']) && is_array($po['lines']) ? $po['lines'] : []) as $l) {
        $cls  = poClassifyLine($l);
        $math = poCheckLineMath($l);
        $code = trim((string)($l['mat_code'] ?? ''));

        if ($cls['kind'] === 'adjust') { $nAdj++; } else { $nItem++; }
        if (!$math['ok']) { $nBadMath++; }

        $rows[] = [
            'line_no'     => (int)($l['line_no'] ?? 0),
            'mat_code'    => $code,
            'name'        => (string)($l['name'] ?? ''),
            'description' => (string)($l['description'] ?? ''),
            'qty'         => poNum($l['qty'] ?? 0),
            'unit'        => (string)($l['unit'] ?? ''),
            'unit_price'  => poNum($l['unit_price'] ?? 0),
            'discount'    => poNum($l['discount'] ?? 0),
            'amount'      => poNum($l['amount'] ?? 0),
            'kind'        => $cls['kind'],
            'reasons'     => $cls['reasons'],
            'math'        => $math,
            'master'      => ($code !== '' && isset($master['found'][$code])) ? $master['found'][$code] : null,
        ];
    }

    $checks    = poCheckTotals($po);
    $numbering = poCheckLineNumbers($po);

    $allOk = $numbering['ok'] && $nBadMath === 0;
    foreach ($checks as $c) { if (!$c['ok']) { $allOk = false; } }

    return [
        'lines'     => $rows,
        'checks'    => $checks,
        'numbering' => $numbering,
        'master'    => $master,
        'summary'   => [
            'n_item'     => $nItem,
            'n_adjust'   => $nAdj,
            'n_bad_math' => $nBadMath,
            'n_no_master'=> count($master['missing']),
            'pass'       => $allOk,
        ],
    ];
}
