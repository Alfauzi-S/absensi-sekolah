<?php
declare(strict_types=1);

namespace App\Services;

use ZipArchive;

/**
 * Penulis XLSX tanpa pustaka luar (ekstensi zip): beberapa sheet, gaya sel sederhana, sel gabung, lebar kolom,
 * pengaturan cetak A4 landscape muat satu halaman lebar. Teks yang diawali = + - @ diberi ' (aman dari formula injection).
 * Sel: nilai skalar, atau ['v' => nilai, 's' => nama gaya].
 */
final class Xlsx
{
    /** Nama gaya => indeks cellXfs di styles.xml */
    public const STYLES = ['n' => 0, 'b' => 1, 'title' => 2, 'th' => 3, 'c' => 4, 'off' => 5, 'l' => 6, 'A' => 7, 'T' => 8, 'S' => 9, 'I' => 9, 'D' => 9, 'H' => 4, 'num' => 10, 'sub' => 11];

    /** @var array<int, array{name:string, rows:array, widths:array, merge:array, landscape:bool, freeze:?string}> */
    private array $sheets = [];

    public function sheet(string $name, array $rows, array $opt = []): void
    {
        $name = preg_replace('/[\[\]\*\?\/\\\\:]/', '-', $name) ?? 'Sheet';
        $name = mb_substr($name, 0, 31);
        $base = $name;
        $i = 2;
        while (in_array($name, array_column($this->sheets, 'name'), true)) {
            $name = mb_substr($base, 0, 28) . ' ' . $i++;
        }
        $this->sheets[] = ['name' => $name, 'rows' => $rows, 'widths' => $opt['widths'] ?? [], 'merge' => $opt['merge'] ?? [], 'landscape' => $opt['landscape'] ?? true, 'freeze' => $opt['freeze'] ?? null];
    }

    public static function safe(mixed $v): mixed
    {
        return is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
    }

    public static function col(int $i): string
    {
        $s = '';
        $i++;
        while ($i > 0) {
            $m = ($i - 1) % 26;
            $s = chr(65 + $m) . $s;
            $i = intdiv($i - 1, 26);
        }
        return $s;
    }

    public function save(string $path): void
    {
        if (!$this->sheets) {
            $this->sheet('Data', [[]]);
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('Tidak bisa membuat file XLSX');
        }
        $n = count($this->sheets);
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($this->sheets as $i => $sh) {
            $k = $i + 1;
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $k . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $wb .= '<sheet name="' . htmlspecialchars($sh['name'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '" sheetId="' . $k . '" r:id="rId' . $k . '"/>';
            $rels .= '<Relationship Id="rId' . $k . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $k . '.xml"/>';
            $zip->addFromString('xl/worksheets/sheet' . $k . '.xml', $this->sheetXml($sh));
        }
        $rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $zip->addFromString('[Content_Types].xml', $ct . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', $wb . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
        $zip->addFromString('xl/styles.xml', self::styles());
        $zip->close();
    }

    /** Unduh sebagai respons Laravel; file sementara dihapus setelah dikirim. */
    public function download(string $filename): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $this->save($tmp);

        return response()->download($tmp, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store',
        ])->deleteFileAfterSend();
    }

    private function sheetXml(array $sh): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $x .= '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>';
        if ($sh['freeze'] && preg_match('/^([A-Z]+)(\d+)$/', $sh['freeze'], $m)) {
            $colIdx = 0;
            foreach (str_split($m[1]) as $ch) {
                $colIdx = $colIdx * 26 + (ord($ch) - 64);
            }
            $x .= '<sheetViews><sheetView workbookViewId="0"><pane xSplit="' . ($colIdx - 1) . '" ySplit="' . ((int) $m[2] - 1) . '" topLeftCell="' . $sh['freeze'] . '" activePane="bottomRight" state="frozen"/></sheetView></sheetViews>';
        }
        if ($sh['widths']) {
            $x .= '<cols>';
            foreach ($sh['widths'] as $i => $w) {
                $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float) $w . '" customWidth="1"/>';
            }
            $x .= '</cols>';
        }
        $x .= '<sheetData>';
        foreach ($sh['rows'] as $r => $cells) {
            $n = $r + 1;
            $x .= '<row r="' . $n . '">';
            foreach (array_values($cells) as $i => $cell) {
                $v = is_array($cell) ? ($cell['v'] ?? '') : $cell;
                $s = is_array($cell) && isset($cell['s']) ? (self::STYLES[$cell['s']] ?? 0) : 0;
                if ($v === null || $v === '') {
                    if ($s) {
                        $x .= '<c r="' . self::col($i) . $n . '" s="' . $s . '"/>';
                    }
                    continue;
                }
                $ref = self::col($i) . $n;
                if (is_int($v) || is_float($v)) {
                    $x .= '<c r="' . $ref . '" s="' . $s . '"><v>' . $v . '</v></c>';
                } else {
                    $x .= '<c r="' . $ref . '" s="' . $s . '" t="inlineStr"><is><t xml:space="preserve">' . htmlspecialchars((string) self::safe((string) $v), ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
                }
            }
            $x .= '</row>';
        }
        $x .= '</sheetData>';
        if ($sh['merge']) {
            $x .= '<mergeCells count="' . count($sh['merge']) . '">';
            foreach ($sh['merge'] as $m) {
                $x .= '<mergeCell ref="' . $m . '"/>';
            }
            $x .= '</mergeCells>';
        }
        $x .= '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>';
        $x .= '<pageSetup paperSize="9" orientation="' . ($sh['landscape'] ? 'landscape' : 'portrait') . '" fitToWidth="1" fitToHeight="0"/>';
        return $x . '</worksheet>';
    }

    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="6"><font><sz val="10"/><name val="Calibri"/></font><font><b/><sz val="10"/><name val="Calibri"/></font><font><b/><sz val="13"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FFC62828"/><name val="Calibri"/></font><font><b/><sz val="10"/><color rgb="FFB26A00"/><name val="Calibri"/></font><font><sz val="10"/><color rgb="FF1565C0"/><name val="Calibri"/></font></fonts>'
            . '<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE8EDF2"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFD9D9D9"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="2"><border/><border><left style="thin"><color rgb="FF999999"/></left><right style="thin"><color rgb="FF999999"/></right><top style="thin"><color rgb="FF999999"/></top><bottom style="thin"><color rgb="FF999999"/></bottom></border></borders>'
            . '<cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="12">'
            . '<xf fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf fontId="1" fillId="0" borderId="0" applyFont="1"/>'
            . '<xf fontId="2" fillId="0" borderId="0" applyFont="1"/>'
            . '<xf fontId="1" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf fontId="0" fillId="0" borderId="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            . '<xf fontId="0" fillId="3" borderId="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            . '<xf fontId="0" fillId="0" borderId="1" applyBorder="1"/>'
            . '<xf fontId="3" fillId="0" borderId="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            . '<xf fontId="4" fillId="0" borderId="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            . '<xf fontId="5" fillId="0" borderId="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            . '<xf fontId="1" fillId="0" borderId="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            . '<xf fontId="0" fillId="0" borderId="0" applyAlignment="1"><alignment horizontal="left"/></xf>'
            . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }
}
