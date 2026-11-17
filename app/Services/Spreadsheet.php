<?php
declare(strict_types=1);

namespace App\Services;

use ZipArchive;

/**
 * Pembaca file tabel untuk impor: CSV (koma / titik koma / tab, UTF-8 atau Windows-1252)
 * dan XLSX (sheet pertama, tanpa pustaka luar: cukup ekstensi zip + SimpleXML).
 * Mengembalikan baris sebagai array string; baris pertama dianggap judul kolom.
 */
final class Spreadsheet
{
    public const MAX_ROWS = 5000;

    /** @return array{headers:string[], rows:array<int,array<int,string>>} atau lempar RuntimeException */
    public static function read(string $path, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $rows = match ($ext) {
            'xlsx' => self::readXlsx($path),
            'csv', 'txt', 'tsv' => self::readCsv($path),
            default => throw new \RuntimeException('Format file tidak didukung. Gunakan .xlsx atau .csv.'),
        };
        // Buang baris yang seluruhnya kosong
        $rows = array_values(array_filter($rows, static fn (array $r) => implode('', $r) !== ''));
        if (!$rows) {
            throw new \RuntimeException('File kosong atau tidak terbaca.');
        }
        if (count($rows) - 1 > self::MAX_ROWS) {
            throw new \RuntimeException('Maksimal 5000 baris data per impor.');
        }
        $headers = array_map([self::class, 'normalizeHeader'], array_shift($rows));
        return ['headers' => $headers, 'rows' => $rows];
    }

    /** "Nama Lengkap " → "nama_lengkap"; "NIS" → "nis" */
    public static function normalizeHeader(string $h): string
    {
        $h = strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $h) ?? $h));
        return trim(preg_replace('/[^a-z0-9]+/', '_', $h) ?? $h, '_');
    }

    /** Ubah baris menjadi peta kolom => nilai berdasarkan judul kolom. */
    public static function assoc(array $headers, array $row): array
    {
        $out = [];
        foreach ($headers as $i => $h) {
            if ($h === '') {
                continue;
            }
            $out[$h] = trim((string) ($row[$i] ?? ''));
        }
        return $out;
    }

    private static function readCsv(string $path): array
    {
        $raw = (string) file_get_contents($path);
        if ($raw === '') {
            return [];
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        $first = strtok($raw, "\r\n") ?: '';
        $delim = ',';
        foreach ([';', "\t", ','] as $d) {
            if (substr_count($first, $d) > substr_count($first, $delim)) {
                $delim = $d;
            }
        }
        $rows = [];
        $h = fopen('php://temp', 'r+');
        fwrite($h, $raw);
        rewind($h);
        while (($r = fgetcsv($h, 0, $delim, '"', '\\')) !== false) {
            $rows[] = array_map(static fn ($v) => trim((string) $v), $r);
        }
        fclose($h);
        return $rows;
    }

    private static function readXlsx(string $path): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new \RuntimeException('Ekstensi zip PHP tidak aktif; unggah CSV sebagai gantinya.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('File .xlsx tidak dapat dibuka.');
        }
        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $sx = self::xml($xml);
            foreach ($sx->si as $si) {
                $shared[] = isset($si->t) ? (string) $si->t : implode('', array_map(static fn ($r) => (string) $r->t, iterator_to_array($si->r, false)));
            }
        }
        $sheetFile = self::firstSheet($zip);
        $xml = $zip->getFromName($sheetFile);
        $zip->close();
        if ($xml === false) {
            throw new \RuntimeException('Sheet pertama tidak ditemukan di file .xlsx.');
        }
        $sx = self::xml($xml);
        $rows = [];
        foreach ($sx->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $col = self::colIndex(preg_replace('/\d+/', '', $ref) ?? 'A');
                $type = (string) $c['t'];
                $v = isset($c->v) ? (string) $c->v : (isset($c->is->t) ? (string) $c->is->t : '');
                if ($type === 's') {
                    $v = $shared[(int) $v] ?? '';
                } elseif ($type !== 'inlineStr' && $type !== 'str' && is_numeric($v) && str_contains($v, 'E')) {
                    $v = rtrim(rtrim(number_format((float) $v, 0, '.', ''), '0'), '.'); // NIS besar yang ditulis ilmiah
                }
                $cells[$col] = trim($v);
            }
            if (!$cells) {
                $rows[] = [];
                continue;
            }
            $max = max(array_keys($cells));
            $line = [];
            for ($i = 0; $i <= $max; $i++) {
                $line[] = $cells[$i] ?? '';
            }
            $rows[] = $line;
        }
        return $rows;
    }

    private static function firstSheet(ZipArchive $zip): string
    {
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb !== false && $rels !== false) {
            $w = self::xml($wb);
            $first = $w->sheets->sheet[0] ?? null;
            if ($first) {
                $rid = (string) $first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                foreach (self::xml($rels)->Relationship as $rel) {
                    if ((string) $rel['Id'] === $rid) {
                        $target = ltrim((string) $rel['Target'], '/');
                        return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                    }
                }
            }
        }
        return 'xl/worksheets/sheet1.xml';
    }

    private static function xml(string $xml): \SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        $sx = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOENT & 0);
        libxml_use_internal_errors($prev);
        if ($sx === false) {
            throw new \RuntimeException('Isi file .xlsx rusak.');
        }
        return $sx;
    }

    private static function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return max(0, $n - 1);
    }
}
