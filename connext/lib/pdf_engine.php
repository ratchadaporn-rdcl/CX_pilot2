<?php
/**
 * CONNEXT — lib/pdf_engine.php : Dompdf wrapper + Thai/format helpers สำหรับรายงาน PDF
 *
 * GAS เดิมสร้างชีตชั่วคราวแล้ว export ผ่าน Google export URL — พอร์ตนี้ render
 * HTML (templates ใต้ pdf/templates/) ด้วย Dompdf 2 แทน โดยคง layout ตาม
 * .build/template*.html (Sarabun, A4) — สีเดิม navy/gold เปลี่ยนเป็นขาว-ดำ (monotone) ทั้งชุด
 * ตั้งแต่ 2026-10-08 (patch 2026-10-08-pdf-monotone) — template ใหม่ใช้เฉพาะเฉดเทาด้วย
 *
 * PHP 7.4-compatible เท่านั้น
 */

require_once __DIR__ . '/../helpers.php';

/** โฟลเดอร์ราก (forward slash เสมอ) */
function _pdfRoot(): string {
    $root = defined('ROOT_PATH') ? ROOT_PATH : (dirname(__DIR__) . '/');
    return rtrim(str_replace('\\', '/', $root), '/') . '/';
}

/**
 * ลงทะเบียนฟอนต์ Sarabun ให้ dompdf (Regular/Bold; italic ใช้ไฟล์เดียวกัน — Dompdf ไม่สังเคราะห์เอง)
 * คืน true เมื่อใช้ SarabunPdf-* (มีรูปอักษรไทยตามตำแหน่ง → ใช้คู่กับ PdfThaiCanvas)
 *
 * [PDF font fix 2026-09-23] เดิม registerFont() ลงใน pdf/fontcache แล้ว "เจอ family แล้วข้าม" — แต่
 * installed-fonts.json ที่ติดมากับ zip เก็บ path เต็มของเครื่อง dev (C:/Users/Admin/Desktop/…) เพราะ
 * registerFont ของ dompdf 2.0.8 เขียน style ที่ลงไว้ก่อนกลับเป็น path เต็ม → เครื่องอื่นหา .ttf ไม่เจอ →
 * ไม่ฝังฟอนต์ ไม่มี FontDescriptor → Chrome แสดงไทยเป็นช่องว่าง, MuPDF ดึงข้อความเพี้ยน
 * ตอนนี้ .ttf + .ufm + installed-fonts.json อยู่ใน pdf/fonts (= fontDir) อ้างด้วยชื่อไฟล์ล้วน —
 * ย้ายโฟลเดอร์/ขึ้นเซิร์ฟเวอร์ได้โดยไม่ต้องเขียนไฟล์ · ไฟล์ .ufm/json หาย → สร้างใหม่เอง
 */
function _pdfRegisterSarabun($dompdf): bool {
    $fm   = $dompdf->getFontMetrics();
    $opts = $fm->getOptions();
    $dir  = rtrim(str_replace('\\', '/', $opts->getFontDir()), '/') . '/';
    $thai = is_file($dir . 'SarabunPdf-Regular.ttf') && is_file($dir . 'SarabunPdf-Bold.ttf')
         && pdfThaiForms() !== null;
    $reg  = $thai ? 'SarabunPdf-Regular' : 'Sarabun-Regular';
    $bold = $thai ? 'SarabunPdf-Bold' : (is_file($dir . 'Sarabun-Bold.ttf') ? 'Sarabun-Bold' : $reg);
    if (!is_file($dir . $reg . '.ttf')) {
        error_log('pdf_engine: ' . $reg . '.ttf missing at ' . $dir);
        return false;
    }
    foreach (array_unique([$reg, $bold]) as $name) {
        _pdfEnsureFontMetrics($dir, $name, $opts->getFontCache());
    }
    $want = ['normal' => $reg, 'bold' => $bold, 'italic' => $reg, 'bold_italic' => $bold];
    $have = $fm->getFamily('sarabun');
    foreach ($want as $style => $name) {
        if (!is_array($have) || ($have[$style] ?? '') !== $dir . $name) {
            // เขียน installed-fonts.json (ชื่อไฟล์ล้วน) — ถ้าเขียนไม่ได้ก็ยังใช้ได้ในรอบนี้ (ค่าอยู่ในหน่วยความจำ)
            @$fm->setFontFamily('sarabun', $want);
            break;
        }
    }
    return $thai;
}

/** .ufm (metrics ที่ dompdf ใช้) ของฟอนต์ใน fontDir — หายเมื่อไรสร้างใหม่ด้วย php-font-lib ตัวเดียวกับ dompdf */
function _pdfEnsureFontMetrics(string $dir, string $name, string $fontCache): void {
    $ufm = $dir . $name . '.ufm';
    if (!is_file($ufm)) {
        try {
            $font = \FontLib\Font::load($dir . $name . '.ttf');
            $font->parse();
            $font->saveAdobeFontMetrics($ufm);
            $font->close();
        } catch (Throwable $e) {
            error_log('pdf_engine: cannot build ' . $ufm . ': ' . $e->getMessage());
        }
    }
    // Cpdf แคช metrics ที่แปลงแล้วใน fontCache ตามชื่อไฟล์ — แคชเก่ากว่า .ufm (เปลี่ยนฟอนต์) ต้องทิ้ง
    $json = rtrim(str_replace('\\', '/', $fontCache), '/') . '/' . $name . '.ufm.json';
    if (is_file($json) && is_file($ufm) && filemtime($json) < filemtime($ufm)) {
        @unlink($json);
    }
}

/**
 * Render HTML → PDF binary string
 *
 * @param string $html        เอกสาร HTML เต็ม (UTF-8)
 * @param string $paper       'a4'
 * @param string $orientation 'landscape' | 'portrait'
 * @param array  $opts        ['pageNumbers' => bool,          // 'หน้า X/Y' มุมล่างขวา
 *                             'headerText'  => string]        // หัวรายงานวิ่ง มุมบนขวาทุกหน้า
 *                                                             // (แทน frozen row ของ Sheets export)
 */
function renderPdf(string $html, string $paper = 'a4', string $orientation = 'landscape', array $opts = []): string {
    require_once _pdfRoot() . 'includes/dompdf/autoload.inc.php';
    require_once __DIR__ . '/pdf_thai.php';

    $root = _pdfRoot();
    $fontCache = $root . 'pdf/fontcache';
    if (!is_dir($fontCache)) { @mkdir($fontCache, 0775, true); }

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);      // ห้ามดึงไฟล์นอกเครื่อง (Drive URL เดิม render เป็นข้อความแทน)
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', false);
    $options->set('defaultFont', 'sarabun');
    $options->set('defaultMediaType', 'print');
    $options->set('dpi', 96);                     // ให้ px ใน template = px จริง
    $options->set('fontDir', $root . 'pdf/fonts');   // [PDF font fix 2026-09-23] ฟอนต์+metrics+installed-fonts.json
    $options->set('fontCache', $fontCache);          // แคช metrics ที่ Cpdf แปลงแล้ว
    $options->setChroot($root);

    $dompdf = new \Dompdf\Dompdf($options);
    $thaiForms = _pdfRegisterSarabun($dompdf);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper($paper, $orientation);
    if ($thaiForms) {
        // [PDF Thai fix 2026-09-23] วางสระบน/วรรณยุกต์ตามตำแหน่ง + ToUnicode ของรูป PUA (lib/pdf_thai.php)
        $thaiCanvas = new PdfThaiCanvas($paper, $orientation, $dompdf);
        $dompdf->setCanvas($thaiCanvas);
        $dompdf->getFontMetrics()->setCanvas($thaiCanvas);
    }
    $dompdf->render();

    $canvas = $dompdf->getCanvas();
    $fm     = $dompdf->getFontMetrics();
    $font   = $fm->getFont('sarabun', 'normal');
    $muted  = [0.35, 0.35, 0.35];   // เทา (#595959) — เอกสารขาว-ดำ

    // ตัววิ่งอยู่ในโซนขอบกระดาษ — เว้นจากขอบจริง ≥ ~5mm กันเครื่องพิมพ์ตัด (ขอบเนื้อหา
    // ของทุก template ตั้งไว้ ≥ 9mm/12mm จึงไม่ชนเนื้อหา)
    if (!empty($opts['headerText'])) {
        $txt = (string)$opts['headerText'];
        $w   = $fm->getTextWidth($txt, $font, 8.5);
        $canvas->page_text($canvas->get_width() - 24 - $w, 14, $txt, $font, 8.5, $muted);
    }
    if (!empty($opts['pageNumbers'])) {
        $tpl    = 'หน้า {PAGE_NUM}/{PAGE_COUNT}';
        $sample = 'หน้า 88/88';
        $w      = $fm->getTextWidth($sample, $font, 8);
        $canvas->page_text($canvas->get_width() - 24 - $w, $canvas->get_height() - 26, $tpl, $font, 8, $muted);
    }

    return $dompdf->output();
}

/** render template PHP ใต้ pdf/templates/ → HTML string ($vars ถูก extract เข้า scope) */
function pdfRenderTemplate(string $template, array $vars): string {
    $__tplFile = _pdfRoot() . 'pdf/templates/' . $template;
    if (!is_file($__tplFile)) {
        throw new RuntimeException('PDF template not found: ' . $template);
    }
    extract($vars, EXTR_SKIP);
    ob_start();
    include $__tplFile;
    return (string)ob_get_clean();
}

// =========================================================================
// format helpers (ล้อรูปแบบตัวเลข/วันที่ของ GAS)
// =========================================================================

/** เงิน 2 ตำแหน่ง + คั่นหลักพัน (GAS money(): Math.round(n*100)/100 → #,##0.00) */
function pdfMoney($n): string {
    return number_format(round(((float)$n) * 100) / 100, 2, '.', ',');
}

/** จำนวน (Qty) — เลขตัดศูนย์ท้าย (DECIMAL '5.000' → '5'), ไม่ใช่เลขคืน verbatim */
function pdfQty($v): string {
    if ($v === null || $v === '') { return ''; }
    if (is_numeric($v)) {
        $s = number_format((float)$v, 3, '.', '');
        $s = rtrim(rtrim($s, '0'), '.');
        return $s === '' ? '0' : $s;
    }
    return (string)$v;
}

/** เลขยอด Balance — '#,##0.##' ของ GAS (คั่นพัน สูงสุด 2 ตำแหน่ง) */
function pdfBalNum($v): string {
    $s = number_format((float)$v, 2, '.', ',');
    $s = rtrim(rtrim($s, '0'), '.');
    return $s === '' ? '0' : $s;
}

/** ชื่อเดือนไทยย่อ (1–12) */
function pdfThaiMonthAbbr(int $m): string {
    $abbr = [1 => 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
             'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    return isset($abbr[$m]) ? $abbr[$m] : '';
}

/** '12 ก.ค. 2569' — thaiShort ของ generateStatsReportPDF (ปี พ.ศ. เต็ม) */
function pdfThaiShortBE(DateTime $dt): string {
    return $dt->format('j') . ' ' . pdfThaiMonthAbbr((int)$dt->format('n')) . ' ' . ((int)$dt->format('Y') + 543);
}

/** '12 ก.ค. 69' — thShort ของ generateDeductionPDF (ปี พ.ศ. 2 หลัก) */
function pdfThaiShortBE2(DateTime $dt): string {
    $yy = str_pad((string)(((int)$dt->format('Y') + 543) % 100), 2, '0', STR_PAD_LEFT);
    return $dt->format('j') . ' ' . pdfThaiMonthAbbr((int)$dt->format('n')) . ' ' . $yy;
}

/** epoch ms → DateTime เวลาไทย (default tz = Asia/Bangkok จาก config) */
function pdfMsToDate($ms): DateTime {
    $dt = new DateTime();
    $dt->setTimestamp((int)floor(((float)$ms) / 1000));
    return $dt;
}
