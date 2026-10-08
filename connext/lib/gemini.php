<?php
/**
 * CONNEXT — lib/gemini.php : อ่านใบสั่งซื้อ (PDF) ด้วย Gemini API → JSON โครงเดียวทุกใบ
 *
 * เฟส 0 ของสาย PO → OCR → buffer → IC (มติทั้งหมด: db/design_po_ocr_ic_v1.md)
 *
 *  · ส่ง "ไฟล์ PDF ทั้งไฟล์" ให้โมเดล — ไม่ส่ง text ที่ extract เอง เพราะลำดับ text
 *    ในไฟล์ต้นฉบับสลับ label/value (label อยู่หัวหน้า ค่าจริงไปกองท้ายหน้า) — มติ 22
 *  · บังคับ structured output ด้วย responseSchema → ไม่ต้องแกะ markdown/regex
 *  · คืน raw response กลับมาเสมอ เพื่อเก็บลง ocr_logs ในเฟสถัดไป (มติ 21)
 *  · ห้าม hard-code ชื่อรุ่น — ตั้งใน settings/gemini.php แล้วดูรุ่นที่ key ใช้ได้จริง
 *    ผ่าน geminiListModels() (ปุ่มในหน้า po_ocr_test.php)
 *
 * PHP 7.4-compatible เท่านั้น — ห้ามใช้ syntax PHP 8
 */

require_once __DIR__ . '/../helpers.php';

/** พาธไฟล์ตั้งค่า */
function geminiSettingsFile(): string {
    $root = defined('ROOT_PATH') ? ROOT_PATH : (dirname(__DIR__) . '/');
    return rtrim(str_replace('\\', '/', $root), '/') . '/settings/gemini.php';
}

/**
 * ค่าตั้งจาก settings/gemini.php (โฟลเดอร์ settings/ ถูก .gitignore กันไว้แล้ว)
 * ส่ง $set เข้ามาเพื่อทับค่าที่โหลดไว้ในรีเควสต์นี้ (ใช้โดย geminiSetModel เท่านั้น)
 */
function geminiConfig(?array $set = null): array {
    static $cfg = null;
    if ($set !== null) { $cfg = $set; return $cfg; }
    if ($cfg !== null) { return $cfg; }

    $file = geminiSettingsFile();
    $raw  = is_file($file) ? (array)(require $file) : [];

    $cfg = array_merge([
        'api_key'           => '',
        'model'             => '',       // เช่น 'models/xxx' หรือ 'xxx' — ต้องตั้งเอง
        'endpoint'          => 'https://generativelanguage.googleapis.com/v1beta',
        'timeout'           => 240,      // วินาที — ใบ 7 หน้าใช้เวลานาน
        'connect_timeout'   => 15,
        'max_output_tokens' => 32768,
        'temperature'       => 0,        // งานสกัดข้อมูล ไม่ต้องการความสร้างสรรค์
        'thinking_budget'   => null,     // null = ไม่ส่ง (ใช้ค่า default ของรุ่น) · 0 = ปิดคิด
        'max_pdf_bytes'     => 15728640, // 15 MB — เกินนี้ต้องใช้ Files API (ยังไม่ทำในเฟส 0)
        'sample_dir'        => '',       // โฟลเดอร์ไฟล์ตัวอย่างของหน้าทดสอบ (dev เท่านั้น)
    ], $raw);

    return $cfg;
}

/** ตั้งค่าครบพร้อมยิงหรือยัง */
function geminiReady(): bool {
    $c = geminiConfig();
    return $c['api_key'] !== '' && $c['model'] !== '';
}

/** ชื่อรุ่นในรูปแบบ path ของ API ('models/xxx') */
function geminiModelPath(string $model): string {
    $model = trim($model);
    return strpos($model, 'models/') === 0 ? $model : ('models/' . $model);
}

/** ชื่อรุ่นที่รับได้ — กันอักขระแปลกก่อนเอาไปต่อ URL หรือเขียนลงไฟล์ PHP */
function geminiValidModelName(string $model): bool {
    return (bool)preg_match('#^[A-Za-z0-9._/-]{1,120}$#', trim($model));
}

/** เปลี่ยนรุ่นที่ใช้เฉพาะรีเควสต์นี้ (ไม่แตะไฟล์ตั้งค่า) */
function geminiSetModel(string $model): void {
    $cfg = geminiConfig();
    $cfg['model'] = trim($model);
    geminiConfig($cfg);
}

/**
 * เขียนชื่อรุ่นลง settings/gemini.php แบบถาวร
 * แทนที่เฉพาะค่าในบรรทัด 'model' => '...' — คอมเมนต์และค่าอื่นคงเดิมทั้งหมด
 * @return array ['ok','error']
 */
function geminiWriteModelToSettings(string $model): array {
    $model = trim($model);
    if (!geminiValidModelName($model)) {
        return ['ok' => false, 'error' => 'ชื่อรุ่นมีอักขระที่ไม่อนุญาต'];
    }

    $file = geminiSettingsFile();
    if (!is_file($file)) {
        return ['ok' => false, 'error' => 'ไม่พบไฟล์ settings/gemini.php'];
    }
    if (!is_writable($file)) {
        return ['ok' => false, 'error' => 'ไฟล์ settings/gemini.php เขียนไม่ได้ (สิทธิ์ไฟล์)'];
    }

    $src = (string)file_get_contents($file);
    $n   = 0;
    $new = preg_replace(
        '#([\'"]model[\'"]\s*=>\s*)([\'"])[^\'"]*\2#',
        '${1}\'' . $model . '\'',
        $src,
        1,
        $n
    );

    if ($new === null || $n !== 1) {
        return ['ok' => false, 'error' => 'หาบรรทัด \'model\' => \'...\' ในไฟล์ไม่เจอ (หรือเจอมากกว่า 1 จุด) — แก้ไฟล์เองแทน'];
    }
    // ตรวจ syntax ก่อนแตะไฟล์จริง — parse error ในไฟล์ตั้งค่า = ทั้งแอปล่ม กู้ยาก
    try {
        token_get_all($new, TOKEN_PARSE);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'ผลลัพธ์ที่จะเขียนมี syntax error — ยกเลิก ไม่แตะไฟล์'];
    }

    if (file_put_contents($file, $new, LOCK_EX) === false) {
        return ['ok' => false, 'error' => 'เขียนไฟล์ไม่สำเร็จ'];
    }

    // อ่านกลับมายืนยันว่าได้ค่าตามที่ตั้งใจจริง
    $check = include $file;
    if (!is_array($check) || (string)($check['model'] ?? '') !== $model) {
        file_put_contents($file, $src, LOCK_EX);   // ย้อนของเดิมคืน
        return ['ok' => false, 'error' => 'อ่านค่ากลับมาไม่ตรง — ย้อนไฟล์เดิมคืนแล้ว'];
    }

    return ['ok' => true, 'error' => ''];
}

/**
 * ยิง HTTP ไป Gemini — คืน ['ok','http','raw','data','error','ms']
 * ไม่ throw: ทุกความผิดพลาดกลับมาเป็น ok=false + error ที่อ่านออก
 */
function geminiHttp(string $path, ?array $body = null): array {
    $cfg = geminiConfig();
    $out = ['ok' => false, 'http' => 0, 'raw' => '', 'data' => null, 'error' => '', 'ms' => 0];

    if ($cfg['api_key'] === '') {
        $out['error'] = 'ยังไม่ได้ตั้ง api_key ใน settings/gemini.php';
        return $out;
    }
    if (!function_exists('curl_init')) {
        $out['error'] = 'PHP ตัวนี้ไม่มี extension curl — เปิด php_curl ก่อน';
        return $out;
    }

    $url = rtrim((string)$cfg['endpoint'], '/') . '/' . ltrim($path, '/');
    $ch  = curl_init($url);

    $headers = ['x-goog-api-key: ' . $cfg['api_key']];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int)$cfg['connect_timeout']);
    curl_setopt($ch, CURLOPT_TIMEOUT, (int)$cfg['timeout']);

    if ($body !== null) {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $out['error'] = 'สร้าง JSON request ไม่สำเร็จ: ' . json_last_error_msg();
            curl_close($ch);
            return $out;
        }
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $t0   = microtime(true);
    $resp = curl_exec($ch);
    $out['ms']   = (int)round((microtime(true) - $t0) * 1000);
    $out['http'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($resp === false) {
        $out['error'] = 'curl: ' . curl_error($ch);
        curl_close($ch);
        return $out;
    }
    curl_close($ch);

    $out['raw']  = (string)$resp;
    $decoded     = json_decode((string)$resp, true);
    $out['data'] = is_array($decoded) ? $decoded : null;

    if ($out['http'] < 200 || $out['http'] >= 300) {
        $msg = isset($decoded['error']['message']) ? (string)$decoded['error']['message'] : '';
        $out['error'] = 'HTTP ' . $out['http'] . ($msg !== '' ? ' — ' . $msg : '');
        return $out;
    }
    if ($out['data'] === null) {
        $out['error'] = 'ตอบกลับมาไม่ใช่ JSON';
        return $out;
    }

    $out['ok'] = true;
    return $out;
}

/** รายชื่อรุ่นที่ API key นี้เรียกได้จริง — ใช้แทนการเดาชื่อรุ่นจากความจำ */
function geminiListModels(): array {
    $r = geminiHttp('models?pageSize=200', null);
    if (!$r['ok']) { return ['ok' => false, 'error' => $r['error'], 'models' => []]; }

    $models = [];
    foreach ((array)($r['data']['models'] ?? []) as $m) {
        $methods = (array)($m['supportedGenerationMethods'] ?? []);
        if (!in_array('generateContent', $methods, true)) { continue; }
        $models[] = [
            'name'       => (string)($m['name'] ?? ''),
            'display'    => (string)($m['displayName'] ?? ''),
            'in_tokens'  => (int)($m['inputTokenLimit'] ?? 0),
            'out_tokens' => (int)($m['outputTokenLimit'] ?? 0),
        ];
    }
    usort($models, function ($a, $b) { return strcmp($a['name'], $b['name']); });
    return ['ok' => true, 'error' => '', 'models' => $models];
}

/**
 * โครง JSON ที่บังคับให้โมเดลตอบ (responseSchema — subset ของ OpenAPI 3.0)
 * ทุกช่องเป็น required เพื่อให้รูปคำตอบเหมือนกันทุกใบ — ไม่มีค่า = "" หรือ 0
 */
function poOcrSchema(): array {
    $lineProps = [
        'line_no'     => ['type' => 'INTEGER', 'description' => 'เลขลำดับตามที่พิมพ์ในคอลัมน์ "ลำดับ"'],
        'mat_code'    => ['type' => 'STRING',  'description' => 'รหัสวัสดุในคอลัมน์ "รหัสวัสดุ / Mat. Code" (ปกติ 14 ตัวอักษร) ไม่มี = ""'],
        'name'        => ['type' => 'STRING',  'description' => 'ข้อความบรรทัดแรกของคอลัมน์ "รายการ"'],
        'description' => ['type' => 'STRING',  'description' => 'ข้อความที่เหลือของคอลัมน์ "รายการ" รวมทุกบรรทัด รวมส่วนที่ต่อข้ามหน้า'],
        'qty'         => ['type' => 'NUMBER',  'description' => 'คอลัมน์ "จำนวน" ตัดคอมมาออก'],
        'unit'        => ['type' => 'STRING',  'description' => 'คอลัมน์ "หน่วย"'],
        'unit_price'  => ['type' => 'NUMBER',  'description' => 'คอลัมน์ "ราคา/หน่วย" ติดลบได้'],
        'discount'    => ['type' => 'NUMBER',  'description' => 'คอลัมน์ "ส่วนลด" ไม่มี = 0'],
        'amount'      => ['type' => 'NUMBER',  'description' => 'คอลัมน์ "จำนวนเงิน" ติดลบได้'],
    ];
    $lineKeys = ['line_no', 'mat_code', 'name', 'description', 'qty', 'unit', 'unit_price', 'discount', 'amount'];

    $headKeys = [
        'po_no', 'po_date', 'pr_no', 'project_code', 'project_text',
        'vendor_name', 'vendor_tax_id', 'vendor_address', 'vendor_contact_person', 'vendor_phone',
        'quotation_no', 'quotation_date', 'delivery_date', 'payment_terms',
        'deposit_text', 'retention_text', 'page_count', 'lines', 'notes',
        'sum_before_special_discount', 'special_discount', 'sum_after_special_discount',
        'vat', 'grand_total', 'amount_in_words',
    ];

    return [
        'type'             => 'OBJECT',
        'propertyOrdering' => $headKeys,
        'required'         => $headKeys,
        'properties'       => [
            'po_no'        => ['type' => 'STRING', 'description' => 'เลขที่ใบสั่งซื้อ เช่น PO-ARI-000594'],
            'po_date'      => ['type' => 'STRING', 'description' => 'วันที่ของใบสั่งซื้อ คงรูป DD/MM/YYYY ตามที่พิมพ์'],
            'pr_no'        => ['type' => 'STRING', 'description' => 'เลขที่ใบขอซื้อ เช่น PR-ARI-000792'],
            'project_code' => ['type' => 'STRING', 'description' => 'รหัสไซต์ในวงเล็บท้ายชื่อโครงการ/สถานที่ส่งของ เช่น ARI, KATU (ไม่มีให้ถอดจากเลขที่ PO)'],
            'project_text' => ['type' => 'STRING', 'description' => 'ข้อความโครงการ/สถานที่ส่งของแบบเต็ม'],

            'vendor_name'           => ['type' => 'STRING', 'description' => 'ชื่อผู้ขาย (ไม่ใช่บริษัทหัวกระดาษ ซึ่งคือผู้ซื้อ)'],
            'vendor_tax_id'         => ['type' => 'STRING', 'description' => 'เลขประจำตัวผู้เสียภาษีของผู้ขาย'],
            'vendor_address'        => ['type' => 'STRING'],
            'vendor_contact_person' => ['type' => 'STRING', 'description' => 'ชื่อผู้ติดต่อฝั่งผู้ขาย'],
            'vendor_phone'          => ['type' => 'STRING'],

            'quotation_no'   => ['type' => 'STRING'],
            'quotation_date' => ['type' => 'STRING'],
            'delivery_date'  => ['type' => 'STRING', 'description' => 'วันที่ส่งมอบ'],
            'payment_terms'  => ['type' => 'STRING', 'description' => 'เงื่อนไขการชำระเงิน เช่น "60 วัน"'],
            'deposit_text'   => ['type' => 'STRING', 'description' => 'ข้อความในช่อง "เงินมัดจำ" ทั้งบรรทัด'],
            'retention_text' => ['type' => 'STRING', 'description' => 'ข้อความในช่อง "เงินประกันผลงาน" ทั้งบรรทัด'],
            'page_count'     => ['type' => 'INTEGER', 'description' => 'จำนวนหน้าทั้งหมด (จาก "Page x/y")'],

            'lines' => [
                'type'        => 'ARRAY',
                'description' => 'ทุกบรรทัดในตารางสินค้า เรียงตามเลขลำดับ ห้ามตกและห้ามซ้ำ',
                'items'       => [
                    'type'             => 'OBJECT',
                    'propertyOrdering' => $lineKeys,
                    'required'         => $lineKeys,
                    'properties'       => $lineProps,
                ],
            ],
            'notes' => [
                'type'        => 'ARRAY',
                'description' => 'ข้อความเงื่อนไข/หมายเหตุที่แทรกในตารางแต่ไม่มีเลขลำดับกำกับ — ห้ามยัดเป็นบรรทัดสินค้า',
                'items'       => ['type' => 'STRING'],
            ],

            'sum_before_special_discount' => ['type' => 'NUMBER', 'description' => 'ยอดรวมก่อนหักส่วนลดพิเศษ (Exclude VAT)'],
            'special_discount'            => ['type' => 'NUMBER', 'description' => 'หักส่วนลดพิเศษ'],
            'sum_after_special_discount'  => ['type' => 'NUMBER', 'description' => 'ยอดรวมหลังหักส่วนลดพิเศษ'],
            'vat'                         => ['type' => 'NUMBER', 'description' => 'ยอด VAT 7%'],
            'grand_total'                 => ['type' => 'NUMBER', 'description' => 'รวมเงินสุทธิ'],
            'amount_in_words'             => ['type' => 'STRING', 'description' => 'จำนวนเงินเป็นตัวอักษรในวงเล็บ'],
        ],
    ];
}

/** คำสั่งที่ส่งคู่กับไฟล์ — เขียนจากกับดักที่เจอในใบจริง (มติ 18/21/22) */
function poOcrPrompt(): string {
    $t = [];
    $t[] = 'คุณกำลังอ่าน "ใบสั่งซื้อ (Purchase Order)" ภาษาไทยของบริษัทรับเหมาก่อสร้าง ทุกใบใช้เทมเพลตเดียวกัน';
    $t[] = 'หน้าที่ของคุณคือถอดข้อมูลออกมาเป็น JSON ตามโครงที่กำหนด — ห้ามสรุป ห้ามเดา ห้ามเติมข้อมูลที่ไม่มีในเอกสาร';
    $t[] = '';
    $t[] = 'กติกาที่ต้องทำตามเคร่งครัด';
    $t[] = '';
    $t[] = '1. อ่านให้ครบทุกหน้า หัวกระดาษ (ชื่อบริษัท ที่อยู่ เลขที่ PO ผู้ขาย โครงการ) และท้ายกระดาษ';
    $t[] = '   (ช่องลายเซ็น ผู้จัดทำ/ผู้อนุมัติ หมายเหตุ 1./2. Page x/y) จะพิมพ์ซ้ำทุกหน้า';
    $t[] = '   ให้ถือเป็นข้อมูลชุดเดียว ห้ามนับซ้ำ และห้ามนับเป็นบรรทัดสินค้าเด็ดขาด';
    $t[] = '';
    $t[] = '2. บริษัทที่หัวกระดาษคือ "ผู้ซื้อ" ไม่ใช่ผู้ขาย — ชื่อผู้ขายอยู่ในช่อง "ชื่อผู้ขาย"';
    $t[] = '';
    $t[] = '3. ตารางสินค้ามีคอลัมน์: ลำดับ | รหัสวัสดุ | รายการ | จำนวน | หน่วย | ราคา/หน่วย | ส่วนลด | จำนวนเงิน';
    $t[] = '   หนึ่งบรรทัดสินค้าเริ่มด้วยเลขลำดับเสมอ (1, 2, 3, ...)';
    $t[] = '   ข้อความในคอลัมน์ "รายการ" มักยาวหลายบรรทัด และอาจขาดครึ่งไปต่อที่หน้าถัดไป';
    $t[] = '   (ส่วนที่เหลือจะโผล่ใต้หัวกระดาษของหน้าใหม่ ก่อนเลขลำดับถัดไป) — ให้ต่อกลับเข้าบรรทัดเดิมที่ยังค้างอยู่';
    $t[] = '';
    $t[] = '4. ข้อความยาว ๆ ที่เป็นเงื่อนไขสัญญา/ข้อตกลง/หมายเหตุ ซึ่งไม่มีเลขลำดับและไม่มีจำนวน-ราคา';
    $t[] = '   ห้ามทำเป็นบรรทัดสินค้า ให้ใส่ในอาเรย์ notes แทน';
    $t[] = '';
    $t[] = '5. บางบรรทัดไม่ใช่สินค้าจริง เช่น "ค่าส่วนลด" ที่มีหน่วยเป็น "บาท" ราคา/หน่วยเป็น -1.00';
    $t[] = '   และจำนวนเงินติดลบ — ให้ถอดออกมาเป็นบรรทัดปกติตามที่พิมพ์ (คงเครื่องหมายลบไว้)';
    $t[] = '   ระบบปลายทางจะจัดประเภทเอง คุณไม่ต้องตัดสิน และห้ามข้ามบรรทัดพวกนี้';
    $t[] = '';
    $t[] = '6. ตัวเลขทุกช่อง: ตัดคอมมาออก คงทศนิยมตามที่พิมพ์ คงเครื่องหมายลบ ห้ามปัดเศษ ห้ามคำนวณใหม่';
    $t[] = '   ช่องไหนว่างให้เป็น 0 (ตัวเลข) หรือ "" (ข้อความ)';
    $t[] = '';
    $t[] = '7. วันที่ให้คงรูปแบบตามที่พิมพ์ในเอกสาร (เช่น 24/08/2026) ห้ามแปลงรูปแบบ';
    $t[] = '';
    $t[] = '8. ยอดสรุปท้ายใบ (ยอดรวมก่อนหักส่วนลดพิเศษ / หักส่วนลดพิเศษ / ยอดรวมหลังหักส่วนลดพิเศษ /';
    $t[] = '   VAT 7% / รวมเงินสุทธิ) มีเฉพาะหน้าสุดท้าย ให้อ่านจากที่นั่น';
    $t[] = '';
    $t[] = 'ตอบกลับเป็น JSON ตามโครงเท่านั้น ไม่ต้องมีคำอธิบายอื่น';

    return implode("\n", $t);
}

/**
 * อ่านใบสั่งซื้อจากไบต์ของไฟล์ PDF
 * @return array ['ok','po','raw','error','http','ms','usage','model','finish']
 */
function geminiReadPo(string $pdfBytes): array {
    $cfg = geminiConfig();
    $out = ['ok' => false, 'po' => null, 'raw' => '', 'error' => '', 'http' => 0,
            'ms' => 0, 'usage' => [], 'model' => (string)$cfg['model'], 'finish' => ''];

    if (!geminiReady()) {
        $out['error'] = 'ยังตั้งค่าไม่ครบใน settings/gemini.php (ต้องมีทั้ง api_key และ model)';
        return $out;
    }
    if ($pdfBytes === '') {
        $out['error'] = 'ไฟล์ว่าง';
        return $out;
    }
    if (strncmp($pdfBytes, '%PDF-', 5) !== 0) {
        $out['error'] = 'ไฟล์นี้ไม่ใช่ PDF (ไม่ขึ้นต้นด้วย %PDF-)';
        return $out;
    }
    if (strlen($pdfBytes) > (int)$cfg['max_pdf_bytes']) {
        $out['error'] = 'ไฟล์ใหญ่เกิน ' . round($cfg['max_pdf_bytes'] / 1048576, 1) . ' MB';
        return $out;
    }

    $genCfg = [
        'responseMimeType' => 'application/json',
        'responseSchema'   => poOcrSchema(),
        'temperature'      => (float)$cfg['temperature'],
        'maxOutputTokens'  => (int)$cfg['max_output_tokens'],
    ];
    if ($cfg['thinking_budget'] !== null) {
        $genCfg['thinkingConfig'] = ['thinkingBudget' => (int)$cfg['thinking_budget']];
    }

    $body = [
        'contents' => [[
            'role'  => 'user',
            'parts' => [
                ['inline_data' => ['mime_type' => 'application/pdf', 'data' => base64_encode($pdfBytes)]],
                ['text' => poOcrPrompt()],
            ],
        ]],
        'generationConfig' => $genCfg,
    ];

    $r = geminiHttp(geminiModelPath((string)$cfg['model']) . ':generateContent', $body);
    $out['raw']  = $r['raw'];
    $out['http'] = $r['http'];
    $out['ms']   = $r['ms'];

    if (!$r['ok']) { $out['error'] = $r['error']; return $out; }

    $d = $r['data'];
    $out['usage']  = (array)($d['usageMetadata'] ?? []);
    $out['finish'] = (string)($d['candidates'][0]['finishReason'] ?? '');

    if (isset($d['promptFeedback']['blockReason'])) {
        $out['error'] = 'คำขอถูกบล็อก: ' . (string)$d['promptFeedback']['blockReason'];
        return $out;
    }

    $text = '';
    foreach ((array)($d['candidates'][0]['content']['parts'] ?? []) as $p) {
        if (isset($p['text'])) { $text .= (string)$p['text']; }
    }
    if ($text === '') {
        $out['error'] = $out['finish'] === 'MAX_TOKENS'
            ? 'คำตอบถูกตัดกลางคัน (MAX_TOKENS) — เพิ่ม max_output_tokens ใน settings/gemini.php'
            : 'ไม่มีเนื้อหาในคำตอบ' . ($out['finish'] !== '' ? ' (finishReason=' . $out['finish'] . ')' : '');
        return $out;
    }

    $po = json_decode($text, true);
    if (!is_array($po)) {
        $out['error'] = 'คำตอบไม่ใช่ JSON ที่ parse ได้: ' . json_last_error_msg();
        return $out;
    }

    $out['ok'] = true;
    $out['po'] = $po;
    return $out;
}
