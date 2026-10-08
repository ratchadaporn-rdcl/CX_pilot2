<?php
/**
 * includes/xlsx_lite.php — ตัวอ่าน/เขียน .xlsx ขนาดเล็ก (ZipArchive + XML ล้วน ไม่ใช้ lib ใหญ่)
 * ใช้กับหน้าตั้งค่าตัวสินค้า (llp_master.php) — เหตุที่เป็น .xlsx ไม่ใช่ CSV: Excel ชอบบันทึก
 * CSV เป็น codepage Windows-874 ทำให้ไทยเพี้ยน ส่วน .xlsx เป็น UTF-8 ภายในเสมอ
 * (ยกมาจาก wms-sim/includes/xlsx_lite.php ซึ่ง port จาก construction-portal
 *  catalogue_io.php + taskflow SimpleXlsxWriter — PHP 7.4)
 *
 * ต่างจากต้นฉบับ wms-sim: เพิ่ม 'validations' ต่อชีต → ได้ dropdown จริงในไฟล์
 * (กันคีย์ CatID/CharID ผิดตั้งแต่ต้นทาง ไม่ต้องรอ import มาด่า)
 */
declare(strict_types=1);

/**
 * อ่านทุก worksheet ในไฟล์ .xlsx → ['ชื่อชีต' => [ [cell,...], ... ]] (สตริง trim แล้ว)
 * รองรับ sharedStrings / inlineStr / ค่าตัวเลข-สูตร · โยน RuntimeException เมื่อไฟล์ใช้ไม่ได้
 */
function xlsxReadSheets(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('เปิดไฟล์ไม่ได้ — กรุณาอัปโหลดไฟล์ .xlsx');
    }

    // sharedStrings (Excel ใช้หลังผู้ใช้แก้แล้วบันทึก)
    $shared = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false && preg_match_all('/<si\b[^>]*>(.*?)<\/si>/s', $ss, $m)) {
        foreach ($m[1] as $si) {
            preg_match_all('/<t\b[^>]*>(.*?)<\/t>/s', $si, $tm);
            $shared[] = html_entity_decode(implode('', $tm[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
    }

    // แผนที่ ชื่อชีต → ไฟล์ worksheet (workbook.xml + rels)
    $wb   = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb === false) {
        $zip->close();
        throw new RuntimeException('ไฟล์ไม่ใช่ .xlsx (ไม่มี workbook)');
    }
    $relMap = [];   // rId → target path
    if ($rels !== false && preg_match_all('/<Relationship\b[^>]*>/', $rels, $rm)) {
        foreach ($rm[0] as $tag) {
            if (preg_match('/Id="([^"]+)"/', $tag, $a) && preg_match('/Target="([^"]+)"/', $tag, $b)) {
                $t = $b[1];
                if (strpos($t, '/') !== 0 && strpos($t, 'xl/') !== 0) {
                    $t = 'xl/' . $t;
                }
                $relMap[$a[1]] = ltrim($t, '/');
            }
        }
    }
    $sheets = [];   // ชื่อ → path
    if (preg_match_all('/<sheet\b[^>]*>/', $wb, $sm)) {
        foreach ($sm[0] as $i => $tag) {
            preg_match('/name="([^"]*)"/', $tag, $a);
            preg_match('/r:id="([^"]+)"/', $tag, $b);
            $name = html_entity_decode($a[1] ?? ('Sheet' . ($i + 1)), ENT_QUOTES | ENT_XML1, 'UTF-8');
            $file = isset($b[1], $relMap[$b[1]]) ? $relMap[$b[1]] : 'xl/worksheets/sheet' . ($i + 1) . '.xml';
            $sheets[$name] = $file;
        }
    }

    $out = [];
    foreach ($sheets as $name => $file) {
        $xml = $zip->getFromName($file);
        $out[$name] = $xml === false ? [] : xlsxParseSheet($xml, $shared);
    }
    $zip->close();
    if (!$out) {
        throw new RuntimeException('ไม่พบ worksheet ในไฟล์');
    }
    return $out;
}

/** แปลง worksheet XML หนึ่งชีตเป็นตารางสตริง (ภายใน — เรียกจาก xlsxReadSheets) */
function xlsxParseSheet(string $sheetXml, array $shared): array
{
    $rows = [];
    if (!preg_match_all('/<row\b[^>]*>(.*?)<\/row>/s', $sheetXml, $rm)) {
        return $rows;
    }
    foreach ($rm[1] as $rowXml) {
        $cells = [];
        if (preg_match_all('/<c\b([^>]*?)(?:\/>|>(.*?)<\/c>)/s', $rowXml, $cm, PREG_SET_ORDER)) {
            $seq = 0;
            foreach ($cm as $c) {
                $attrs = $c[1];
                $inner = $c[2] ?? '';
                $col   = preg_match('/\br="([A-Z]+)\d+"/', $attrs, $a) ? xlsxColToIdx($a[1]) : $seq;
                $t     = preg_match('/\bt="([^"]+)"/', $attrs, $b) ? $b[1] : 'n';
                $val   = '';
                if ($t === 's') {
                    if (preg_match('/<v>(.*?)<\/v>/s', $inner, $vm)) {
                        $val = $shared[(int)$vm[1]] ?? '';
                    }
                } elseif ($t === 'inlineStr') {
                    preg_match_all('/<t\b[^>]*>(.*?)<\/t>/s', $inner, $tm);
                    $val = html_entity_decode(implode('', $tm[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
                } else {   // 'str' (สูตร) หรือตัวเลข
                    if (preg_match('/<v>(.*?)<\/v>/s', $inner, $vm)) {
                        $val = html_entity_decode($vm[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                    }
                }
                $cells[$col] = trim($val);
                $seq++;
            }
        }
        $max = $cells ? max(array_keys($cells)) : -1;
        $arr = [];
        for ($i = 0; $i <= $max; $i++) {
            $arr[$i] = $cells[$i] ?? '';
        }
        $rows[] = $arr;
    }
    return $rows;
}

/** 'A' → 0, 'AA' → 26 */
function xlsxColToIdx(string $letters): int
{
    $n = 0;
    foreach (str_split($letters) as $ch) {
        $n = $n * 26 + (ord($ch) - 64);
    }
    return $n - 1;
}

/** 0 → 'A', 26 → 'AA' */
function xlsxColLetter(int $i): string
{
    $s = '';
    $i++;
    while ($i > 0) {
        $r = ($i - 1) % 26;
        $s = chr(65 + $r) . $s;
        $i = intdiv($i - 1, 26);
    }
    return $s;
}

/**
 * สร้าง .xlsx หลายชีต (เซลล์สตริงล้วน หัวแถว 1 ตัวหนา + freeze) แล้วคืนไบนารี
 * $sheets = [ชื่อชีต => [
 *     'widths'      => [..],
 *     'rows'        => [[..],..],
 *     'header'      => bool (มีหัวแถว 1),
 *     'validations' => [ ['sqref'=>'G2:G3310', 'list'=>['C01','C02'], 'error'=>'ข้อความ'] , .. ],
 * ]]
 * หมายเหตุ: รายการใน list รวมกันแล้วต้องไม่เกิน 255 ตัวอักษร (ข้อจำกัดของ Excel
 * สำหรับ dropdown แบบพิมพ์ค่าตรง) — ลิสต์ CatID/CharID ของเรายาวไม่ถึง
 */
function xlsxWrite(array $sheets): string
{
    $n  = count($sheets);
    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
    for ($i = 1; $i <= $n; $i++) {
        $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $ct .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>';

    $wbXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $relXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $parts = [];
    $sid = 0;
    foreach ($sheets as $name => $sh) {
        $sid++;
        $safe = htmlspecialchars(mb_substr(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', (string)$name), 0, 31), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $wbXml  .= '<sheet name="' . $safe . '" sheetId="' . $sid . '" r:id="rId' . $sid . '"/>';
        $relXml .= '<Relationship Id="rId' . $sid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sid . '.xml"/>';

        $rows = $sh['rows'] ?? [];
        $hasHead = !empty($sh['header']);
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
           . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if ($hasHead) {
            $x .= '<sheetViews><sheetView workbookViewId="0"' . ($sid === 1 ? ' tabSelected="1"' : '') . '>'
                . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        }
        if (!empty($sh['widths'])) {
            $x .= '<cols>';
            foreach (array_values($sh['widths']) as $i => $w) {
                $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float)$w . '" customWidth="1"/>';
            }
            $x .= '</cols>';
        }
        $x .= '<sheetData>';
        foreach ($rows as $ri => $cells) {
            $rn = $ri + 1;
            $x .= '<row r="' . $rn . '">';
            foreach (array_values($cells) as $ci => $val) {
                if ($val === null || $val === '') {
                    continue;
                }
                $style = ($hasHead && $rn === 1) ? ' s="1"' : '';
                $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string)$val);
                $x .= '<c r="' . xlsxColLetter($ci) . $rn . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">'
                    . htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</t></is></c>';
            }
            $x .= '</row>';
        }
        $x .= '</sheetData>';

        // dropdown ในไฟล์ — ต้องอยู่หลัง </sheetData> ตามลำดับของ schema
        $vals = $sh['validations'] ?? [];
        if ($vals) {
            $x .= '<dataValidations count="' . count($vals) . '">';
            foreach ($vals as $v) {
                $list = implode(',', array_map('strval', $v['list'] ?? []));
                $err  = (string)($v['error'] ?? 'ค่านี้ใช้ไม่ได้ — เลือกจากรายการที่ให้มา');
                $x .= '<dataValidation type="list" allowBlank="1" showInputMessage="1" showErrorMessage="1"'
                    . ' errorTitle="' . htmlspecialchars((string)($v['errorTitle'] ?? 'ค่าไม่ถูกต้อง'), ENT_QUOTES | ENT_XML1, 'UTF-8') . '"'
                    . ' error="' . htmlspecialchars($err, ENT_QUOTES | ENT_XML1, 'UTF-8') . '"'
                    . ' sqref="' . htmlspecialchars((string)($v['sqref'] ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8') . '">'
                    . '<formula1>&quot;' . htmlspecialchars($list, ENT_QUOTES | ENT_XML1, 'UTF-8') . '&quot;</formula1>'
                    . '</dataValidation>';
            }
            $x .= '</dataValidations>';
        }

        $x .= '</worksheet>';
        $parts['xl/worksheets/sheet' . $sid . '.xml'] = $x;
    }
    $wbXml  .= '</sheets></workbook>';
    $relXml .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';

    $parts['[Content_Types].xml'] = $ct;
    $parts['_rels/.rels'] =
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
    $parts['xl/workbook.xml'] = $wbXml;
    $parts['xl/_rels/workbook.xml.rels'] = $relXml;
    $parts['xl/styles.xml'] =
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Tahoma"/></font><font><b/><sz val="11"/><name val="Tahoma"/></font></fonts>'
        . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
        . '<borders count="1"><border/></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
        . '</styleSheet>';

    $tmp = tempnam(sys_get_temp_dir(), 'cnxx');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('สร้างไฟล์ Excel ไม่ได้');
    }
    foreach ($parts as $file => $content) {
        $zip->addFromString($file, $content);
    }
    $zip->close();
    $bytes = (string)file_get_contents($tmp);
    @unlink($tmp);
    return $bytes;
}
