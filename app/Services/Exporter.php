<?php
declare(strict_types=1);

namespace App\Services;

use ZipArchive;

/** Penulis CSV dan Excel (.xlsx) sederhana untuk ekspor dan template impor. */
final class Exporter
{
    public static function csv(string $filename, array $headers, array $rows): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM agar Excel membaca UTF-8
        fputcsv($out, $headers, ',', '"', '\\');
        foreach ($rows as $r) {
            fputcsv($out, array_map([self::class, 'safe'], $r), ',', '"', '\\');
        }
        fclose($out);
        exit;
    }

    /** Cegah formula injection di spreadsheet. */
    private static function safe(mixed $v): mixed
    {
        return is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
    }

    public static function xlsxAvailable(): bool
    {
        return class_exists(ZipArchive::class);
    }

    public static function xlsx(string $filename, array $headers, array $rows, string $sheetName = 'Data'): never
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . htmlspecialchars(mb_substr($sheetName, 0, 31), ENT_XML1) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellXfs count="2"><xf fontId="0" fillId="0" borderId="0"/><xf fontId="1" fillId="0" borderId="0" applyFont="1"/></cellXfs></styleSheet>');

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        $sheet .= self::row(1, $headers, true);
        foreach ($rows as $i => $r) {
            $sheet .= self::row($i + 2, $r, false);
        }
        $sheet .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        header('Content-Length: ' . (string) filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    private static function row(int $n, array $cells, bool $bold): string
    {
        $out = '<row r="' . $n . '">';
        foreach (array_values($cells) as $i => $v) {
            $ref = self::col($i) . $n;
            $s = $bold ? ' s="1"' : '';
            if (is_int($v) || is_float($v)) {
                $out .= '<c r="' . $ref . '"' . $s . '><v>' . $v . '</v></c>';
            } else {
                $out .= '<c r="' . $ref . '" t="inlineStr"' . $s . '><is><t xml:space="preserve">' . htmlspecialchars((string) self::safe($v), ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
            }
        }
        return $out . '</row>';
    }

    private static function col(int $i): string
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
}
