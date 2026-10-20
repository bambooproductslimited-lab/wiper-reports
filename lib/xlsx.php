<?php
// Minimal .xlsx reader: returns the first worksheet as a list of rows (arrays of strings).
// Handles shared strings, inline strings, booleans and numbers. Dates come back as Excel serial
// numbers (e.g. 46297); to_date() in db.php converts those.

declare(strict_types=1);

function xlsx_rows(string $bytes): array {
    if (!class_exists('ZipArchive')) throw new RuntimeException('This server cannot read Excel files. Save the sheet as CSV and upload that.');
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) { @unlink($tmp); throw new RuntimeException('Not a valid .xlsx file.'); }
    try {
        $sheetPath = xlsx_first_sheet($zip);
        $shared = [];
        if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $x = xlsx_xml($ss);
            foreach ($x->si as $si) $shared[] = xlsx_text($si);
        }
        $xml = $zip->getFromName($sheetPath);
        if ($xml === false) throw new RuntimeException('The workbook has no readable first sheet.');
        $sheet = xlsx_xml($xml);
        $rows = [];
        foreach ($sheet->sheetData->row as $row) {
            // Excel omits empty rows; pad so list positions match spreadsheet row numbers.
            if (isset($row['r'])) while (count($rows) < (int)$row['r'] - 1) $rows[] = [];
            $out = []; $next = 0;
            foreach ($row->c as $c) {
                $idx = isset($c['r']) ? xlsx_col((string)$c['r']) : $next;
                $t = (string)($c['t'] ?? 'n');
                $v = isset($c->v) ? (string)$c->v : '';
                $val = match ($t) {
                    's' => $shared[(int)$v] ?? '',
                    'inlineStr' => xlsx_text($c->is),
                    'b' => $v === '1' ? 'TRUE' : 'FALSE',
                    'str', 'e' => $v,
                    default => is_numeric($v) ? xlsx_num($v) : $v,
                };
                while (count($out) < $idx) $out[] = '';
                $out[$idx] = $val; $next = $idx + 1;
            }
            $rows[] = $out;
        }
        return $rows;
    } finally {
        $zip->close(); @unlink($tmp);
    }
}

function xlsx_xml(string $s): SimpleXMLElement {
    $x = @simplexml_load_string($s, 'SimpleXMLElement', LIBXML_NONET);
    if ($x === false) throw new RuntimeException('The Excel file is damaged.');
    return $x;
}
// Text of a <si> or <is> node, including rich-text runs.
function xlsx_text(?SimpleXMLElement $n): string {
    if (!$n) return '';
    if (isset($n->t)) return (string)$n->t;
    $s = '';
    foreach ($n->r as $r) $s .= (string)$r->t;
    return $s;
}
// "B12" -> 1
function xlsx_col(string $ref): int {
    $letters = preg_replace('/\d+/', '', strtoupper($ref)); $n = 0;
    foreach (str_split($letters) as $ch) $n = $n * 26 + (ord($ch) - 64);
    return $n - 1;
}
// Excel stores 4.75 fine but 0.1 as 0.10000000000000001; round away float noise.
function xlsx_num(string $v): string {
    $s = rtrim(rtrim(sprintf('%.6F', (float)$v), '0'), '.');
    return $s === '-0' ? '0' : $s;
}
function xlsx_first_sheet(ZipArchive $zip): string {
    $wb = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb !== false && $rels !== false) {
        $w = xlsx_xml($wb); $w->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $first = $w->xpath('//m:sheets/m:sheet')[0] ?? null;
        if ($first) {
            $rid = (string)$first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            foreach (xlsx_xml($rels)->Relationship as $r) {
                if ((string)$r['Id'] === $rid) {
                    $target = ltrim((string)$r['Target'], '/');
                    return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                }
            }
        }
    }
    return 'xl/worksheets/sheet1.xml';
}
