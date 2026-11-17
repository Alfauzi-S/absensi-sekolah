<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database as DB;
use App\Models\Setting;

/**
 * Rekap kehadiran (PRD 4.4). Status harian siswa diturunkan dari status per sesi pada hari itu:
 *  - sesi yang dihitung: jam pelajaran (jadwal) dan Masuk; sesi Pulang, sesi tambahan, dan jam kosong tidak ikut;
 *  - hadir di salah satu sesi: Hadir bila sesi pertama yang ia ikuti (melewati sesi berstatus izin dan yang belum tercatat) berstatus Hadir,
 *    selain itu Terlambat (scan pertamanya terlambat, atau baru datang di jam berikutnya);
 *  - tidak hadir sama sekali: Izin/Sakit/Dispensasi bila ada, selain itu Alpa bila ada Alpa;
 *  - jam pelajaran Alpa pada hari siswa hadir dihitung sebagai "jam alpa" (rekap per siswa).
 * Hari efektif = tanggal yang punya setidaknya satu sesi terhitung yang sudah ditutup dan bukan hari libur.
 * Persentase hadir = (H + T) ÷ hari efektif.
 */
final class Recap
{
    public const CODES = ['H', 'T', 'S', 'I', 'A', 'D'];
    public const DAILY_KINDS = ['jadwal', 'harian_masuk'];

    /** Status harian dari daftar status sesi berurutan (null = belum tercatat). */
    public static function dayCode(array $statuses): string
    {
        $present = false;
        foreach ($statuses as $s) {
            if ($s === 'H' || $s === 'T') {
                $present = true;
                break;
            }
        }
        if ($present) {
            foreach ($statuses as $s) {
                if ($s === 'H' || $s === 'T' || $s === 'A') {
                    return $s === 'H' ? 'H' : 'T';
                }
            }
        }
        foreach ($statuses as $s) {
            if ($s === 'S' || $s === 'I' || $s === 'D') {
                return $s;
            }
        }
        return in_array('A', $statuses, true) ? 'A' : '';
    }

    public static function students(int $classId): array
    {
        return DB::all('SELECT s.id, s.nis, s.nisn, s.full_name, s.gender FROM class_members m JOIN students s ON s.id = m.student_id
            WHERE m.class_id = ? ORDER BY s.full_name ASC, s.id ASC', [$classId]);
    }

    /** @return string[] tanggal libur (tabel holidays) dalam rentang */
    public static function holidays(string $from, string $to): array
    {
        return array_column(DB::all('SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN ? AND ?', [$from, $to]), 'holiday_date');
    }

    /** @return string[] semua tanggal dari..sampai */
    public static function dates(string $from, string $to): array
    {
        $out = [];
        for ($t = strtotime($from); $t <= strtotime($to); $t = strtotime('+1 day', $t)) {
            $out[] = date('Y-m-d', $t);
        }
        return $out;
    }

    /**
     * Data dasar rekap satu kelas: per siswa per tanggal kode harian, jumlah per kode, % hadir, jam alpa.
     * @return array{students:array, dates:array<string,array{effective:bool,holiday:bool,weekend:bool,sessions:array}>,
     *   codes:array<int,array<string,string>>, totals:array<int,array<string,int|float>>, effective:int, present_per_day:array<string,int>,
     *   jam_alpa:array<int,int>, sessions:array<int,array<int,?string>>}
     */
    public static function grid(int $classId, string $from, string $to): array
    {
        $kinds = "'" . implode("','", self::DAILY_KINDS) . "'";
        $sessions = DB::all("SELECT id, session_date, kind, status, starts_at, period_start FROM sessions
            WHERE class_id = ? AND session_date BETWEEN ? AND ? AND status IN ('open','closed') AND kind IN ($kinds) ORDER BY session_date, starts_at, id", [$classId, $from, $to]);
        $status = [];
        foreach (DB::all("SELECT a.session_id, a.student_id, a.status FROM attendances a JOIN sessions s ON s.id = a.session_id
            WHERE s.class_id = ? AND s.session_date BETWEEN ? AND ? AND s.status IN ('open','closed') AND s.kind IN ($kinds)", [$classId, $from, $to]) as $r) {
            $status[(int) $r['student_id']][(int) $r['session_id']] = $r['status'];
        }
        $holidays = array_flip(self::holidays($from, $to));
        $weekend = Setting::weekendDays();
        $dates = [];
        foreach (self::dates($from, $to) as $d) {
            $dates[$d] = ['effective' => false, 'holiday' => isset($holidays[$d]), 'weekend' => in_array((int) date('N', strtotime($d)), $weekend, true), 'sessions' => []];
        }
        foreach ($sessions as $s) {
            $dates[$s['session_date']]['sessions'][] = $s;
            if ($s['status'] === 'closed' && !$dates[$s['session_date']]['holiday']) {
                $dates[$s['session_date']]['effective'] = true;
            }
        }
        $students = self::students($classId);
        $effective = count(array_filter($dates, static fn ($d) => $d['effective']));
        $codes = $totals = $jamAlpa = [];
        $perDay = array_fill_keys(array_keys($dates), 0);
        foreach ($students as $st) {
            $sid = (int) $st['id'];
            $t = array_fill_keys(self::CODES, 0);
            $jamAlpa[$sid] = 0;
            foreach ($dates as $d => $info) {
                if (!$info['sessions']) {
                    $codes[$sid][$d] = '';
                    continue;
                }
                $list = [];
                foreach ($info['sessions'] as $s) {
                    $list[] = $status[$sid][(int) $s['id']] ?? null;
                }
                $code = self::dayCode($list);
                $codes[$sid][$d] = $code;
                if ($info['effective'] && $code !== '') {
                    $t[$code]++;
                    if ($code === 'H' || $code === 'T') {
                        $perDay[$d]++;
                        foreach ($info['sessions'] as $i => $s) {
                            if ($s['kind'] === 'jadwal' && $list[$i] === 'A') {
                                $jamAlpa[$sid]++;
                            }
                        }
                    }
                }
            }
            $t['pct'] = $effective > 0 ? round(($t['H'] + $t['T']) / $effective * 100, 1) : 0.0;
            $t['effective'] = $effective;
            $totals[$sid] = $t;
        }
        return ['students' => $students, 'dates' => $dates, 'codes' => $codes, 'totals' => $totals, 'effective' => $effective,
            'present_per_day' => $perDay, 'jam_alpa' => $jamAlpa, 'status' => $status];
    }

    /** Rekap harian satu kelas: kolom per sesi (J1, J2, … atau Masuk/Pulang/tambahan) + status harian. */
    public static function daily(int $classId, string $date): array
    {
        $cols = DB::all("SELECT s.id, s.kind, s.status, s.starts_at, s.ends_at, s.period_start, s.period_end, sb.code AS subject_code, s.note
            FROM sessions s LEFT JOIN subjects sb ON sb.id = s.subject_id WHERE s.class_id = ? AND s.session_date = ? AND s.status <> 'cancelled' ORDER BY s.starts_at, s.id", [$classId, $date]);
        $grid = self::grid($classId, $date, $date);
        $cells = [];
        foreach (DB::all("SELECT a.session_id, a.student_id, a.status, a.scanned_at, a.late_minutes FROM attendances a JOIN sessions s ON s.id = a.session_id
            WHERE s.class_id = ? AND s.session_date = ?", [$classId, $date]) as $r) {
            $cells[(int) $r['student_id']][(int) $r['session_id']] = $r;
        }
        foreach ($cols as &$c) {
            $c['label'] = match ($c['kind']) {
                'jadwal' => 'J' . $c['period_start'] . ((int) $c['period_end'] > (int) $c['period_start'] ? '–' . $c['period_end'] : ''),
                'harian_masuk' => t('Masuk'), 'harian_pulang' => t('Pulang'), default => t('Tambahan'),
            };
        }
        unset($c);
        return ['date' => $date, 'columns' => $cols, 'cells' => $cells] + $grid;
    }

    /** Bulan-bulan (YYYY-MM) dalam rentang. */
    public static function months(string $from, string $to): array
    {
        $out = [];
        for ($t = strtotime(substr($from, 0, 7) . '-01'); $t <= strtotime($to); $t = strtotime('+1 month', $t)) {
            $out[] = date('Y-m', $t);
        }
        return $out;
    }

    /** Rekap semester per kelas: jumlah H/T/S/I/A/D per bulan per siswa + persentase semester. */
    public static function semester(int $classId, string $from, string $to): array
    {
        $grid = self::grid($classId, $from, $to);
        $months = self::months($from, $to);
        $byMonth = [];
        $effMonth = array_fill_keys($months, 0);
        foreach ($grid['dates'] as $d => $info) {
            if ($info['effective']) {
                $effMonth[substr($d, 0, 7)]++;
            }
        }
        foreach ($grid['students'] as $st) {
            $sid = (int) $st['id'];
            foreach ($months as $m) {
                $byMonth[$sid][$m] = array_fill_keys(self::CODES, 0);
            }
            foreach ($grid['codes'][$sid] as $d => $code) {
                if ($code !== '' && $grid['dates'][$d]['effective']) {
                    $byMonth[$sid][substr($d, 0, 7)][$code]++;
                }
            }
        }
        return ['months' => $months, 'by_month' => $byMonth, 'effective_month' => $effMonth] + $grid;
    }

    /** Ringkasan sekolah: per kelas jumlah siswa, hari efektif, total kode, % hadir. */
    public static function school(array $classes, string $from, string $to): array
    {
        $rows = [];
        foreach ($classes as $c) {
            $g = self::grid((int) $c['id'], $from, $to);
            $sum = array_fill_keys(self::CODES, 0);
            foreach ($g['totals'] as $t) {
                foreach (self::CODES as $k) {
                    $sum[$k] += $t[$k];
                }
            }
            $n = count($g['students']);
            $rows[] = ['class' => $c, 'students' => $n, 'effective' => $g['effective'], 'sum' => $sum,
                'pct' => $n > 0 && $g['effective'] > 0 ? round(($sum['H'] + $sum['T']) / ($n * $g['effective']) * 100, 1) : null,
                'frequent_absent' => count(array_filter($g['totals'], static fn ($t) => $t['A'] >= 3))];
        }
        return $rows;
    }

    /**
     * Siswa dengan Alpa harian ≥ $min dalam rentang (SQL, untuk dashboard; hasilnya sama dengan grid()).
     * @param int[]|null $classIds null = semua kelas
     * @return array<int, array{student_id:int, n:int, full_name:string, nis:string, class_id:int, class_name:string, last:string}>
     */
    public static function frequentAbsent(?array $classIds, string $from, string $to, int $min = 3, int $limit = 100): array
    {
        if ($classIds !== null && $classIds === []) {
            return [];
        }
        $kinds = "'" . implode("','", self::DAILY_KINDS) . "'";
        $w = $classIds === null ? '' : ' AND s.class_id IN (' . implode(',', array_map('intval', $classIds)) . ')';
        return DB::all("SELECT x.student_id, COUNT(*) AS n, MAX(x.session_date) AS last, st.full_name, st.nis, c.id AS class_id, c.name AS class_name
            FROM (SELECT DISTINCT a.student_id, s.session_date, s.class_id FROM attendances a JOIN sessions s ON s.id = a.session_id
                  WHERE a.status = 'A' AND s.kind IN ($kinds) AND s.status = 'closed' AND s.session_date BETWEEN ? AND ?$w
                    AND s.session_date NOT IN (SELECT holiday_date FROM holidays)
                    AND NOT EXISTS (SELECT 1 FROM attendances a2 JOIN sessions s2 ON s2.id = a2.session_id
                        WHERE a2.student_id = a.student_id AND s2.class_id = s.class_id AND s2.session_date = s.session_date
                          AND s2.kind IN ($kinds) AND s2.status IN ('open','closed') AND a2.status <> 'A')) x
            JOIN students st ON st.id = x.student_id JOIN classes c ON c.id = x.class_id
            GROUP BY x.student_id, st.full_name, st.nis, c.id, c.name HAVING COUNT(*) >= " . max(1, $min) . ' ORDER BY n DESC, st.full_name ASC LIMIT ' . $limit,
            [$from, $to]);
    }

    /** Ringkasan hari ini per kelas dari sesi terhitung: hadir, terlambat, izin/sakit/dispensasi, alpa, belum tercatat. */
    public static function today(?array $classIds, string $date): array
    {
        if ($classIds !== null && $classIds === []) {
            return [];
        }
        $out = [];
        $w = $classIds === null ? '' : ' AND id IN (' . implode(',', array_map('intval', $classIds)) . ')';
        $year = \App\Models\AcademicYear::active();
        if (!$year) {
            return [];
        }
        foreach (DB::all('SELECT id, name FROM classes WHERE academic_year_id = ?' . $w . ' ORDER BY grade, name', [(int) $year['id']]) as $c) {
            $g = self::grid((int) $c['id'], $date, $date);
            $cnt = ['H' => 0, 'T' => 0, 'X' => 0, 'A' => 0, 'none' => 0];
            foreach ($g['codes'] as $codes) {
                $code = $codes[$date] ?? '';
                match ($code) { 'H' => $cnt['H']++, 'T' => $cnt['T']++, 'I', 'S', 'D' => $cnt['X']++, 'A' => $cnt['A']++, default => $cnt['none']++ };
            }
            $out[] = ['class' => $c, 'students' => count($g['students']), 'has_sessions' => (bool) $g['dates'][$date]['sessions'], 'counts' => $cnt];
        }
        return $out;
    }

    /**
     * Riwayat satu siswa per tanggal (hanya tanggal yang punya sesi terhitung): status harian, jam scan pertama, menit terlambat,
     * jam alpa, dan keterangan (izin/sakit, koreksi).
     */
    public static function studentDays(int $classId, int $studentId, string $from, string $to): array
    {
        $g = self::grid($classId, $from, $to);
        $scans = [];
        foreach (DB::all("SELECT s.session_date, a.status, a.scanned_at, a.late_minutes, a.note, a.corrected, a.leave_id, s.kind, s.period_start
            FROM attendances a JOIN sessions s ON s.id = a.session_id WHERE a.student_id = ? AND s.class_id = ? AND s.session_date BETWEEN ? AND ?
            AND s.status IN ('open','closed') ORDER BY s.starts_at", [$studentId, $classId, $from, $to]) as $r) {
            $scans[$r['session_date']][] = $r;
        }
        $leaves = [];
        foreach (\App\Models\Leave::forStudent($studentId, $from, $to) as $l) {
            if ($l['status'] === 'active') {
                $leaves[] = $l;
            }
        }
        $out = [];
        foreach ($g['dates'] as $d => $info) {
            $code = $g['codes'][$studentId][$d] ?? '';
            if (!$info['sessions'] && $code === '') {
                continue;
            }
            $first = null;
            $late = 0;
            $jamAlpa = 0;
            $notes = [];
            foreach ($scans[$d] ?? [] as $r) {
                if ($r['scanned_at'] && $first === null && $r['kind'] !== 'harian_pulang') {
                    $first = substr($r['scanned_at'], 11, 5);
                    $late = $r['status'] === 'T' ? (int) $r['late_minutes'] : 0;
                }
                if ($r['kind'] === 'jadwal' && $r['status'] === 'A' && in_array($code, ['H', 'T'], true)) {
                    $jamAlpa++;
                }
                if ($r['corrected'] && $r['note']) {
                    $notes[] = t('Koreksi: :r', ['r' => $r['note']]);
                }
            }
            foreach ($leaves as $l) {
                if ($l['date_from'] <= $d && $l['date_to'] >= $d) {
                    $notes[] = t(\App\Models\Leave::KINDS[$l['kind']]) . ': ' . $l['reason'];
                }
            }
            $out[] = ['date' => $d, 'code' => $code, 'effective' => $info['effective'], 'first_scan' => $first, 'late' => $late, 'jam_alpa' => $jamAlpa,
                'note' => implode(' · ', array_unique($notes))];
        }
        return ['days' => $out, 'totals' => $g['totals'][$studentId] ?? null, 'jam_alpa' => $g['jam_alpa'][$studentId] ?? 0, 'effective' => $g['effective']];
    }
}
