<?php
/**
 * CONNEXT — lib/pdf_thai.php : วางสระบน/วรรณยุกต์ไทยให้ถูกตำแหน่งใน PDF ของ dompdf
 *
 * [PDF Thai fix 2026-09-23] dompdf 2.x ไม่ทำ OpenType shaping (GSUB/GPOS) → Sarabun วางเครื่องหมาย
 * ที่ตำแหน่งตั้งต้นเสมอ: ไม้เอกบนสระบน (ที่ ชื่อ เครื่อง) จมหายในเส้นสระ · วรรณยุกต์/สระบนชนหาง ป ฝ ฟ ·
 * วรรณยุกต์ทับนิคหิตของ ำ · ญ ฐ ไม่ตัดเชิงเมื่อมีสระล่าง
 *
 * วิธีแก้ (ไม่แตะ vendor):
 *   1) pdf/fonts/SarabunPdf-*.ttf = Sarabun + "รูปตามตำแหน่ง" ที่รหัส PUA U+EE00… (ตำแหน่งคำนวณจาก GPOS
 *      ของฟอนต์เองด้วย HarfBuzz — ดู build_sarabun_pdf.py ในโฟลเดอร์ patch) · ตารางรหัสอยู่ใน SarabunPdf-thai.php
 *   2) PdfThaiCanvas::text() เลือกรหัส PUA ตามบริบท (pdfThaiShape) ตอนเขียนลง PDF เท่านั้น —
 *      การจัดหน้า/ตัดบรรทัดของ dompdf ยังคิดจากข้อความจริง (ทุกรูปกว้างเท่าอักษรเดิม ตำแหน่งจึงไม่เลื่อน)
 *   3) PdfThaiCpdf เขียน ToUnicode ให้รหัส PUA แปลงกลับเป็นอักษรไทยจริง → ค้นหา/คัดลอก/ดึงข้อความได้ถูกต้อง
 *
 * ต้อง require หลัง includes/dompdf/autoload.inc.php (คลาสด้านล่าง extends คลาสของ dompdf)
 * PHP 7.4-compatible เท่านั้น
 */

/** ตาราง PUA ของ SarabunPdf (null = ไม่มีไฟล์ตาราง → ไม่จัดตำแหน่ง ข้อความออกแบบเดิม) */
function pdfThaiForms(): ?array {
    static $map = false;
    if ($map === false) {
        $f = _pdfRoot() . 'pdf/fonts/SarabunPdf-thai.php';
        $m = is_file($f) ? (require $f) : null;
        $map = (is_array($m) && !empty($m['forms']) && !empty($m['unicode'])) ? $m : null;
    }
    return $map;
}

/**
 * แปลงข้อความไทยเป็นรหัสรูปตามตำแหน่ง (ใช้กับฟอนต์ SarabunPdf เท่านั้น) — ล้อกฎ ccmp ของ Sarabun:
 *   ป ฝ ฟ + สระบน/วรรณยุกต์ → รูป .narrow (หลบหาง) · สระบน + วรรณยุกต์ → วรรณยุกต์เล็กยกขึ้นเหนือสระ ·
 *   วรรณยุกต์ + ำ → ligature นิคหิต+วรรณยุกต์ (ำ เหลือ า) · ญ ฐ + สระล่าง → ตัดเชิง ·
 *   ฎ ฏ ฤ ฦ + สระล่าง → ตัวสั้น + สระล่างเล็ก · ฬ + เครื่องหมายบน → ตัวสั้น
 */
function pdfThaiShape(string $s): string {
    if ($s === '' || !preg_match('/[\x{0E31}\x{0E33}-\x{0E3A}\x{0E47}-\x{0E4D}]/u', $s)) {
        return $s;
    }
    $map = pdfThaiForms();
    $chars = $map ? preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) : false;
    if (!$chars) {
        return $s;
    }
    $F = $map['forms'];
    $form = function (string $key, int $cp) use ($F): int {
        return isset($F[$key]) ? (int)$F[$key] : $cp;
    };
    $cp = [];
    foreach ($chars as $ch) { $cp[] = mb_ord($ch, 'UTF-8'); }

    $out   = [];
    $base  = -1;     // index ใน $out ของพยัญชนะต้นกลุ่ม (-1 = ไม่มีฐาน)
    $bch   = 0;      // พยัญชนะต้นกลุ่ม (รหัสจริง)
    $cls   = '';     // ac = ป ฝ ฟ · lo = ฬ · dcs = ฎ ฏ · fr = ฤ ฦ · dcl = ญ ฐ
    $uv    = 0;      // สระบนตัวล่าสุดในกลุ่ม (ั ิ ี ึ ื ็)
    $amTone = false; // วรรณยุกต์ก่อน ำ ถูกแปลงเป็น ligature (มีนิคหิตแล้ว)
    $n = count($cp);
    for ($i = 0; $i < $n; $i++) {
        $c = $cp[$i];
        if ($c >= 0x0E01 && $c <= 0x0E2E) {                         // พยัญชนะ → เริ่มกลุ่มใหม่
            $base = count($out); $bch = $c; $uv = 0; $amTone = false;
            if ($c === 0x0E1B || $c === 0x0E1D || $c === 0x0E1F)      { $cls = 'ac'; }
            elseif ($c === 0x0E2C)                                  { $cls = 'lo'; }
            elseif ($c === 0x0E0E || $c === 0x0E0F)                 { $cls = 'dcs'; }
            elseif ($c === 0x0E24 || $c === 0x0E26)                 { $cls = 'fr'; }
            elseif ($c === 0x0E0D || $c === 0x0E10)                 { $cls = 'dcl'; }
            else                                                    { $cls = ''; }
            $out[] = $c;
            continue;
        }
        if ($base < 0) {                                            // ไม่มีพยัญชนะนำ → ใช้รูปเดิม
            $out[] = $c;
            continue;
        }
        $baseKey = sprintf('base:%04X', $bch);
        if ($c === 0x0E31 || ($c >= 0x0E34 && $c <= 0x0E37) || $c === 0x0E47 || $c === 0x0E4D) {   // สระบน / นิคหิต
            $out[] = $cls === 'ac' ? $form(sprintf('narrow:%04X', $c), $c) : $c;
            if ($cls === 'lo') { $out[$base] = $form($baseKey, $bch); }
            if ($c !== 0x0E4D) { $uv = $c; }
        } elseif ($c >= 0x0E48 && $c <= 0x0E4C) {                    // วรรณยุกต์ / ทัณฑฆาต
            $nx = $i + 1 < $n ? $cp[$i + 1] : 0;
            $ac = $cls === 'ac' ? 'ac' : '';
            if ($nx === 0x0E33 && $c !== 0x0E4C && isset($F[sprintf('amtone%s:%04X', $ac, $c)])) {
                $out[] = $form(sprintf('amtone%s:%04X', $ac, $c), $c);
                $amTone = true;
            } elseif ($uv) {
                $out[] = $form(sprintf('stack%s:%04X:%04X', $ac, $uv, $c), $c);
            } elseif ($ac) {
                $out[] = $form(sprintf('narrow:%04X', $c), $c);
            } else {
                $out[] = $c;
            }
            if ($cls === 'lo') { $out[$base] = $form($baseKey, $bch); }
        } elseif ($c === 0x0E33) {                                   // ำ (สระลอย → ปิดกลุ่ม)
            if ($amTone)           { $out[] = $form('amaa', $c); }
            elseif ($cls === 'ac') { $out[] = $form('amac', $c); }
            else                   { $out[] = $c; }
            if ($cls === 'lo') { $out[$base] = $form($baseKey, $bch); }
            $base = -1;
        } elseif ($c >= 0x0E38 && $c <= 0x0E3A) {                    // สระล่าง / พินทุ
            if ($cls === 'dcs' || $cls === 'fr') {
                $out[$base] = $form($baseKey, $bch);
                $out[] = $form(sprintf($cls === 'dcs' ? 'bvsmall:%04X' : 'bvsmallup:%04X', $c), $c);
            } else {
                if ($cls === 'dcl') { $out[$base] = $form($baseKey, $bch); }
                $out[] = $c;
            }
        } else {                                                     // อักษรอื่น → จบกลุ่ม
            $out[] = $c;
            $base = -1;
        }
    }
    $r = '';
    foreach ($out as $c) { $r .= mb_chr($c, 'UTF-8'); }
    return $r;
}

/** ToUnicode CMap: รหัสทั่วไปแปลงตรงตัว (เหมือน dompdf) ยกเว้นบล็อก PUA ของเรา → อักษรไทยจริง */
function _pdfThaiToUnicodeCMap(array $puaToUni): string {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $skip = [];
    foreach ($puaToUni as $code => $u) { $skip[((int)$code) >> 8] = true; }
    $ranges = [];
    for ($hi = 0; $hi < 256; $hi++) {
        if (!isset($skip[$hi])) { $ranges[] = sprintf('<%02X00> <%02XFF> <%02X00>', $hi, $hi, $hi); }
    }
    $chars = [];
    ksort($puaToUni);
    foreach ($puaToUni as $code => $u) { $chars[] = sprintf('<%04X> <%04X>', $code, $u); }
    $s = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
       . "/CIDSystemInfo\n<</Registry (Adobe)\n/Ordering (UCS)\n/Supplement 0\n>> def\n"
       . "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
       . "1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";
    foreach (array_chunk($ranges, 100) as $chunk) {
        $s .= count($chunk) . " beginbfrange\n" . implode("\n", $chunk) . "\nendbfrange\n";
    }
    foreach (array_chunk($chars, 100) as $chunk) {
        $s .= count($chunk) . " beginbfchar\n" . implode("\n", $chunk) . "\nendbfchar\n";
    }
    $s .= "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
    return $cache = $s;
}

/** Cpdf ที่เขียน ToUnicode รู้จักรหัส PUA ของ SarabunPdf */
class PdfThaiCpdf extends \Dompdf\Cpdf
{
    protected function o_toUnicode($id, $action)
    {
        $map = pdfThaiForms();
        if ($action !== 'out' || !$map || $this->encrypted) {
            return parent::o_toUnicode($id, $action);
        }
        $stream = _pdfThaiToUnicodeCMap($map['unicode']);
        $filter = '';
        if ($this->compressionReady && !empty($this->options['compression'])) {
            $stream = gzcompress($stream, 6);
            $filter = ' /Filter /FlateDecode';
        }
        return "\n$id 0 obj\n<</Length " . strlen($stream) . $filter . " >>\nstream\n" . $stream . "\nendstream\nendobj";
    }
}

/** Canvas CPDF ที่จัดรูปอักษรไทยตอนเขียนข้อความ (ใส่ผ่าน $dompdf->setCanvas() ก่อน render) */
class PdfThaiCanvas extends \Dompdf\Adapter\CPDF
{
    public function __construct($paper = 'letter', string $orientation = 'portrait', ?\Dompdf\Dompdf $dompdf = null)
    {
        parent::__construct($paper, $orientation, $dompdf);
        // สลับ Cpdf เป็น PdfThaiCpdf — ขั้นตอนเดียวกับ Adapter\CPDF::__construct ของ dompdf 2.0.8
        $opts = $this->_dompdf->getOptions();
        $pdf  = new PdfThaiCpdf([0, 0, $this->_width, $this->_height], true, $opts->getFontCache(), $opts->getTempDir());
        $pdf->addInfo('Producer', sprintf('%s + CPDF', $this->_dompdf->version));
        $time = substr_replace(date('YmdHisO'), '\'', -2, 0) . '\'';
        $pdf->addInfo('CreationDate', "D:$time");
        $pdf->addInfo('ModDate', "D:$time");
        $this->_pdf   = $pdf;
        $this->_pages = [$pdf->getFirstPageId()];
    }

    public function text($x, $y, $text, $font, $size, $color = [0, 0, 0], $word_space = 0.0, $char_space = 0.0, $angle = 0.0)
    {
        if (stripos(basename((string)$font), 'SarabunPdf') === 0) {
            $text = pdfThaiShape((string)$text);
        }
        parent::text($x, $y, $text, $font, $size, $color, $word_space, $char_space, $angle);
    }
}
