<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;

/**
 * Tabel rekap siap ekspor (PRD 4.4): baris judul (kop sekolah), tabel, baris jumlah, tanda tangan.
 * Satu definisi dipakai untuk XLSX (dengan gaya) dan CSV (hanya tabelnya).
 */
final class RecapExport
{
    public static function kop(string $title, string $sub): array
    {
        $school = Setting::get('school_name') ?: t('Nama sekolah belum diisi');
        $addr = Setting::get('school_address');
        $npsn = Setting::get('school_npsn');
        return [
            [['v' => mb_strtoupper($school), 's' => 'title']],
            [['v' => trim($addr . ($npsn ? ' · NPSN ' . $npsn : '')), 's' => 'sub']],
            [['v' => $title, 's' => 'b']],
            [['v' => $sub, 's' => 'sub']],
            [],
        ];
    }

    private static function signatures(string $wali, int $width, string $place = ''): array
    {
        $head = Setting::get('headmaster_name');
        $right = max(4, $width - 6);
        $pad = static fn (array $cells, int $at) => array_merge(array_fill(0, $at, ''), $cells);
        return [
            [],
            $pad([t('Mengetahui,')], 1) + [],
            array_replace($pad([t('Kepala Sekolah')], 1), [$right => ($place ? $place . ', ' : '') . date_id(today())]),
            array_replace(array_fill(0, $right + 1, ''), [$right => t('Wali Kelas')]),
            [], [],
            array_replace($pad([['v' => $head ?: '(............................)', 's' => 'b']], 1), [$right => ['v' => $wali ?: '(............................)', 's' => 'b']]),
        ];
    }

    private static function codeCell(string $code, bool $off): array
    {
        return ['v' => $code, 's' => $off ? 'off' : ($code === '' ? 'c' : $code)];
    }

    /** Rekap bulanan per kelas: satu kolom per tanggal, jumlah per kode, % hadir; baris hadir per hari; tanda tangan. */
    public static function monthly(array $class, string $month, array $g): array
    {
        $dates = array_keys($g['dates']);
        $head = [t('No'), t('NIS'), t('Nama'), t('L/P')];
        foreach ($dates as $d) {
            $head[] = (string) (int) substr($d, 8, 2);
        }
        array_push($head, 'H', 'T', 'S', 'I', 'A', 'D', t('% hadir'));
        $rows = [];
        foreach ($g['students'] as $i => $st) {
            $sid = (int) $st['id'];
            $r = [$i + 1, $st['nis'], $st['full_name'], $st['gender']];
            foreach ($dates as $d) {
                $r[] = $g['codes'][$sid][$d] ?? '';
            }
            $t = $g['totals'][$sid];
            array_push($r, $t['H'], $t['T'], $t['S'], $t['I'], $t['A'], $t['D'], $t['pct']);
            $rows[] = $r;
        }
        $foot = ['', '', t('Jumlah hadir per hari'), ''];
        foreach ($dates as $d) {
            $foot[] = $g['dates'][$d]['effective'] ? $g['present_per_day'][$d] : '';
        }
        $title = t('REKAP KEHADIRAN BULANAN KELAS :c', ['c' => $class['name']]);
        $sub = month_id($month) . ' · ' . t('Hari efektif: :n', ['n' => $g['effective']]) . ' · ' . t('Wali kelas: :w', ['w' => $class['homeroom_name'] ?? '—']);
        return ['title' => $title, 'sub' => $sub, 'head' => $head, 'rows' => $rows, 'foot' => $foot, 'dates' => $dates, 'g' => $g, 'class' => $class];
    }

    public static function monthlyXlsx(Xlsx $x, array $t, string $sheetName): void
    {
        $g = $t['g'];
        $out = self::kop($t['title'], $t['sub']);
        $nDates = count($t['dates']);
        $out[] = array_map(static fn ($h) => ['v' => $h, 's' => 'th'], $t['head']);
        foreach ($t['rows'] as $r) {
            $row = [['v' => $r[0], 's' => 'c'], ['v' => $r[1], 's' => 'c'], ['v' => $r[2], 's' => 'l'], ['v' => $r[3], 's' => 'c']];
            foreach ($t['dates'] as $k => $d) {
                $info = $g['dates'][$d];
                $row[] = self::codeCell((string) $r[4 + $k], $info['holiday'] || ($info['weekend'] && !$info['sessions']));
            }
            for ($k = 4 + $nDates; $k < count($r); $k++) {
                $row[] = ['v' => $r[$k], 's' => 'num'];
            }
            $out[] = $row;
        }
        $foot = [];
        foreach ($t['foot'] as $k => $v) {
            $foot[] = ['v' => $v, 's' => $k === 2 ? 'b' : 'c'];
        }
        $out[] = $foot;
        $out[] = [['v' => t('Keterangan: H Hadir · T Terlambat · S Sakit · I Izin · A Alpa · D Dispensasi · kolom abu-abu = hari libur. % hadir = (H + T) ÷ hari efektif.'), 's' => 'sub']];
        $out = array_merge($out, self::signatures($t['class']['homeroom_name'] ?? '', count($t['head'])));
        $widths = array_merge([4, 11, 28, 4], array_fill(0, $nDates, 3.2), array_fill(0, 6, 4), [8]);
        $last = Xlsx::col(count($t['head']) - 1);
        $x->sheet($sheetName, $out, ['widths' => $widths, 'merge' => ['A1:' . $last . '1', 'A2:' . $last . '2', 'A3:' . $last . '3', 'A4:' . $last . '4'], 'freeze' => 'E7']);
    }

    /** Rekap semester per kelas: per bulan H/T/S/I/A/D, total, % hadir. */
    public static function semester(array $class, array $semester, array $g): array
    {
        $head = [t('No'), t('NIS'), t('Nama'), t('L/P')];
        foreach ($g['months'] as $m) {
            foreach (Recap::CODES as $c) {
                $head[] = month_id($m, true) . ' ' . $c;
            }
        }
        array_push($head, 'H', 'T', 'S', 'I', 'A', 'D', t('Hari efektif'), t('% hadir'));
        $rows = [];
        foreach ($g['students'] as $i => $st) {
            $sid = (int) $st['id'];
            $r = [$i + 1, $st['nis'], $st['full_name'], $st['gender']];
            foreach ($g['months'] as $m) {
                foreach (Recap::CODES as $c) {
                    $r[] = $g['by_month'][$sid][$m][$c];
                }
            }
            $t = $g['totals'][$sid];
            array_push($r, $t['H'], $t['T'], $t['S'], $t['I'], $t['A'], $t['D'], $g['effective'], $t['pct']);
            $rows[] = $r;
        }
        $title = t('REKAP KEHADIRAN SEMESTER KELAS :c', ['c' => $class['name']]);
        $sub = t('Semester :s · :a s.d. :b', ['s' => $semester['name'], 'a' => date_id($semester['start_date']), 'b' => date_id($g['to'] ?? $semester['end_date'])]) . ' · ' . t('Wali kelas: :w', ['w' => $class['homeroom_name'] ?? '—']);
        return ['title' => $title, 'sub' => $sub, 'head' => $head, 'rows' => $rows, 'g' => $g, 'class' => $class];
    }

    public static function semesterXlsx(Xlsx $x, array $t, string $sheetName): void
    {
        $out = self::kop($t['title'], $t['sub']);
        $months = $t['g']['months'];
        $grp = [['v' => '', 's' => 'th'], ['v' => '', 's' => 'th'], ['v' => '', 's' => 'th'], ['v' => '', 's' => 'th']];
        $merge = [];
        foreach ($months as $k => $m) {
            $c0 = 4 + $k * 6;
            $grp[] = ['v' => month_id($m), 's' => 'th'];
            for ($j = 1; $j < 6; $j++) {
                $grp[] = ['v' => '', 's' => 'th'];
            }
            $merge[] = Xlsx::col($c0) . '6:' . Xlsx::col($c0 + 5) . '6';
        }
        $grp[] = ['v' => t('Semester'), 's' => 'th'];
        $c0 = 4 + count($months) * 6;
        for ($j = 1; $j < 8; $j++) {
            $grp[] = ['v' => '', 's' => 'th'];
        }
        $merge[] = Xlsx::col($c0) . '6:' . Xlsx::col($c0 + 7) . '6';
        $out[] = $grp;
        $head = [t('No'), t('NIS'), t('Nama'), t('L/P')];
        foreach ($months as $m) {
            array_push($head, ...Recap::CODES);
        }
        array_push($head, 'H', 'T', 'S', 'I', 'A', 'D', t('Efektif'), t('% hadir'));
        $out[] = array_map(static fn ($h) => ['v' => $h, 's' => 'th'], $head);
        foreach ($t['rows'] as $r) {
            $row = [['v' => $r[0], 's' => 'c'], ['v' => $r[1], 's' => 'c'], ['v' => $r[2], 's' => 'l'], ['v' => $r[3], 's' => 'c']];
            for ($k = 4; $k < count($r); $k++) {
                $row[] = ['v' => $r[$k], 's' => $k >= count($r) - 8 ? 'num' : 'c'];
            }
            $out[] = $row;
        }
        $out = array_merge($out, self::signatures($t['class']['homeroom_name'] ?? '', count($head)));
        $last = Xlsx::col(count($head) - 1);
        $merge = array_merge(['A1:' . $last . '1', 'A2:' . $last . '2', 'A3:' . $last . '3', 'A4:' . $last . '4'], $merge);
        $x->sheet($sheetName, $out, ['widths' => array_merge([4, 11, 28, 4], array_fill(0, count($months) * 6, 3.6), array_fill(0, 6, 4.5), [7, 8]), 'merge' => $merge, 'freeze' => 'E8']);
    }

    /** Rekap harian per kelas: kolom per sesi + status harian. */
    public static function daily(array $class, array $d): array
    {
        $head = [t('No'), t('NIS'), t('Nama'), t('L/P')];
        foreach ($d['columns'] as $c) {
            $head[] = $c['label'];
        }
        $head[] = t('Status hari ini');
        $rows = [];
        foreach ($d['students'] as $i => $st) {
            $sid = (int) $st['id'];
            $r = [$i + 1, $st['nis'], $st['full_name'], $st['gender']];
            foreach ($d['columns'] as $c) {
                $cell = $d['cells'][$sid][(int) $c['id']] ?? null;
                $r[] = $cell ? $cell['status'] . ($cell['status'] === 'T' ? ' ' . $cell['late_minutes'] . "'" : '') : '';
            }
            $r[] = $d['codes'][$sid][$d['date']] ?? '';
            $rows[] = $r;
        }
        return ['title' => t('REKAP KEHADIRAN HARIAN KELAS :c', ['c' => $class['name']]), 'sub' => date_id($d['date'], true), 'head' => $head, 'rows' => $rows, 'class' => $class];
    }

    public static function simpleXlsx(Xlsx $x, array $t, string $sheetName, array $widths = []): void
    {
        $out = self::kop($t['title'], $t['sub']);
        $out[] = array_map(static fn ($h) => ['v' => $h, 's' => 'th'], $t['head']);
        foreach ($t['rows'] as $r) {
            $out[] = array_map(static fn ($v) => ['v' => $v, 's' => is_string($v) && mb_strlen($v) > 3 ? 'l' : (in_array($v, Recap::CODES, true) ? $v : 'c')], $r);
        }
        if (!empty($t['class'])) {
            $out = array_merge($out, self::signatures($t['class']['homeroom_name'] ?? '', count($t['head'])));
        }
        $last = Xlsx::col(max(0, count($t['head']) - 1));
        $x->sheet($sheetName, $out, ['widths' => $widths ?: array_merge([4, 11, 28, 4], array_fill(0, max(0, count($t['head']) - 4), 8)), 'merge' => ['A1:' . $last . '1', 'A2:' . $last . '2', 'A3:' . $last . '3', 'A4:' . $last . '4']]);
    }

    /** Ringkasan sekolah per kelas. */
    public static function school(array $rows, string $label): array
    {
        $head = [t('No'), t('Kelas'), t('Wali kelas'), t('Siswa'), t('Hari efektif'), 'H', 'T', 'S', 'I', 'A', 'D', t('% hadir'), t('Siswa alpa ≥ 3')];
        $out = [];
        foreach ($rows as $i => $r) {
            $out[] = [$i + 1, $r['class']['name'], $r['class']['homeroom_name'] ?? '', $r['students'], $r['effective'], $r['sum']['H'], $r['sum']['T'], $r['sum']['S'], $r['sum']['I'], $r['sum']['A'], $r['sum']['D'], $r['pct'] ?? '', $r['frequent_absent']];
        }
        return ['title' => t('RINGKASAN KEHADIRAN SEKOLAH'), 'sub' => $label . ' · ' . t('jumlah hari-siswa per kode'), 'head' => $head, 'rows' => $out];
    }

    /** Rekap per siswa: satu baris per tanggal. */
    public static function student(array $st, array $class, array $rows, string $from, string $to): array
    {
        $head = [t('Tanggal'), t('Hari'), t('Status'), t('Jam scan'), t('Terlambat (menit)'), t('Jam alpa'), t('Keterangan')];
        return ['title' => t('RIWAYAT KEHADIRAN SISWA'), 'sub' => $st['full_name'] . ' · NIS ' . $st['nis'] . ' · ' . $class['name'] . ' · ' . date_id($from) . ' s.d. ' . date_id($to),
            'head' => $head, 'rows' => array_map(static fn ($r) => [$r['date'], day_name((int) date('N', strtotime($r['date']))), $r['code'], $r['first_scan'] ?? '', $r['late'] ?: '', $r['jam_alpa'] ?: '', $r['note']], $rows), 'class' => $class];
    }
}
