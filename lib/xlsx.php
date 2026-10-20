<?php
// Minimal .xlsx reader: xlsx_sheets() returns every worksheet as ['name' => ..., 'rows' => [[...], ...]] in tab order;
// xlsx_rows() returns just the first one's rows.
// Handles shared strings, inline strings, booleans and numbers. Dates come back as Excel serial
// numbers (e.g. 46297); to_date() in db.php converts those.

declare(strict_types=1);

function xlsx_rows(string $bytes): array { return xlsx_sheets($bytes)[0]['rows'] ?? []; }

function xlsx_sheets(string $bytes): array {
    if (!class_exists('ZipArchive')) throw new RuntimeException('This server cannot read Excel files. Save the sheet as CSV and upload that.');
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) { @unlink($tmp); throw new RuntimeException('Not a valid .xlsx file.'); }
    try {
        $shared = [];
        if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $x = xlsx_xml($ss);
            foreach ($x->si as $si) $shared[] = xlsx_text($si);
        }
        $sheets = [];
        foreach (xlsx_sheet_paths($zip) as [$name, $path]) {
            $xml = $zip->getFromName($path);
            if ($xml !== false) $sheets[] = ['name' => $name, 'rows' => xlsx_sheet_rows(xlsx_xml($xml), $shared)];
        }
        if (!$sheets) throw new RuntimeException('The workbook has no readable sheets.');
        return $sheets;
    } finally {
        $zip->close(); @unlink($tmp);
    }
}

function xlsx_sheet_rows(SimpleXMLElement $sheet, array $shared): array {
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
// [[sheet name, zip path], ...] in tab order.
function xlsx_sheet_paths(ZipArchive $zip): array {
    $wb = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    $out = [];
    if ($wb !== false && $rels !== false) {
        $targets = [];
        foreach (xlsx_xml($rels)->Relationship as $r) {
            $t = ltrim((string)$r['Target'], '/');
            $targets[(string)$r['Id']] = str_starts_with($t, 'xl/') ? $t : 'xl/' . $t;
        }
        $w = xlsx_xml($wb); $w->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        foreach ($w->xpath('//m:sheets/m:sheet') as $sh) {
            $rid = (string)$sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            if (isset($targets[$rid])) $out[] = [(string)$sh['name'], $targets[$rid]];
        }
    }
    return $out ?: [['Sheet1', 'xl/worksheets/sheet1.xml']];
}
