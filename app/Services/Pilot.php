<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database as DB;
use App\Models\ScanMetric;
use App\Models\Setting;

/**
 * Pilot 2 kelas selama 1 minggu (PRD Fase 7):
 *  - kecepatan nyata: p95 t_total scan USB per kelas per hari dari metrik kiosk (target ≤ 300 ms);
 *  - akurasi: status harian sistem dibandingkan dengan absen manual guru (pilot_checks); setiap selisih harus punya penjelasan;
 *  - masukan guru: tabel feedback.
 */
final class Pilot
{
    public const DAYS_ACCURACY = 3;
    public const TOPICS = ['scan' => 'Scan dan kiosk', 'rekap' => 'Rekap dan laporan', 'izin' => 'Izin dan koreksi', 'tampilan' => 'Tampilan', 'lainnya' => 'Lainnya'];

    /** @return array{classes:int[], from:string, to:string} */
    public static function config(): array
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', Setting::get('pilot_classes')))));
        $from = Setting::get('pilot_from');
        $to = Setting::get('pilot_to');
        return ['classes' => $ids, 'from' => $from, 'to' => $to];
    }

    public static function active(?string $date = null): bool
    {
        $c = self::config();
        $date ??= today();
        return $c['classes'] && $c['from'] !== '' && $c['from'] <= $date && ($c['to'] === '' || $date <= $c['to']);
    }

    /**
     * Kecepatan per kelas per hari (scan USB, langsung terkirim). @return array<int, array{total:array, days:array<string,array>}>
     */
    public static function speed(array $classIds, string $from, string $to): array
    {
        $out = [];
        foreach ($classIds as $cid) {
            $out[$cid] = ['total' => ScanMetric::summarize([]), 'days' => []];
        }
        if (!$classIds) {
            return $out;
        }
        $in = implode(',', array_map('intval', $classIds));
        $rows = DB::all("SELECT s.class_id, substr(m.created_at, 1, 10) AS day, m.source, m.t_feedback_ms, m.t_total_ms, m.t_roundtrip_ms, m.t_server_ms, m.t_decode_ms, m.local_hit, m.queued
            FROM scan_metrics m JOIN scan_logs l ON l.scan_id = m.scan_id JOIN sessions s ON s.id = l.session_id
            WHERE s.class_id IN ($in) AND m.created_at BETWEEN ? AND ? AND (m.source = 'usb' OR m.source IS NULL)", [$from . ' 00:00:00', $to . ' 23:59:59']);
        $by = [];
        foreach ($rows as $r) {
            $by[(int) $r['class_id']]['all'][] = $r;
            $by[(int) $r['class_id']]['days'][$r['day']][] = $r;
        }
        foreach ($by as $cid => $b) {
            ksort($b['days']);
            $out[$cid] = ['total' => ScanMetric::summarize($b['all']), 'days' => array_map([ScanMetric::class, 'summarize'], $b['days'])];
        }
        return $out;
    }

    /** Lulus bila p95 t_total ≤ target dan ada cukup data (≥ 20 scan). */
    public static function speedPass(array $summary): ?bool
    {
        $p = $summary['total']['p95'] ?? null;
        if ($p === null || ($summary['total']['n'] ?? 0) < 20) {
            return null;
        }
        return $p <= ScanMetric::TARGET_USB;
    }

    /**
     * Banding satu kelas satu tanggal: per siswa status sistem vs manual.
     * @return array<int, array{student_id:int, nis:string, full_name:string, system:string, manual:?string, match:?bool, explanation:?string, check_id:?int, first_scan:?string}>
     */
    public static function compare(int $classId, string $date): array
    {
        $g = Recap::grid($classId, $date, $date);
        $checks = [];
        foreach (DB::all('SELECT * FROM pilot_checks WHERE class_id = ? AND check_date = ?', [$classId, $date]) as $c) {
            $checks[(int) $c['student_id']] = $c;
        }
        $scans = [];
        foreach (DB::all("SELECT a.student_id, MIN(a.scanned_at) AS t FROM attendances a JOIN sessions s ON s.id = a.session_id
            WHERE s.class_id = ? AND s.session_date = ? AND a.scanned_at IS NOT NULL AND s.kind <> 'harian_pulang' GROUP BY a.student_id", [$classId, $date]) as $r) {
            $scans[(int) $r['student_id']] = substr((string) $r['t'], 11, 5);
        }
        $out = [];
        foreach ($g['students'] as $st) {
            $sid = (int) $st['id'];
            $sys = $g['codes'][$sid][$date] ?? '';
            $c = $checks[$sid] ?? null;
            $out[] = ['student_id' => $sid, 'nis' => $st['nis'], 'full_name' => $st['full_name'], 'system' => $sys, 'manual' => $c['manual_status'] ?? null,
                'match' => $c ? self::same($sys, $c['manual_status']) : null, 'explanation' => $c['explanation'] ?? null, 'check_id' => $c ? (int) $c['id'] : null,
                'first_scan' => $scans[$sid] ?? null];
        }
        return $out;
    }

    /** Hadir dan Terlambat sama-sama "hadir" di absen kertas sebagian guru; tetap dihitung selisih bila berbeda agar dijelaskan. */
    public static function same(string $system, string $manual): bool
    {
        return $system === $manual;
    }

    /** Simpan absen manual guru untuk satu kelas satu tanggal. @param array<int,string> $statuses student_id => kode @return int baris tersimpan */
    public static function saveManual(int $classId, string $date, array $statuses, int $userId): int
    {
        $members = array_flip(array_map('intval', array_column(Recap::students($classId), 'id')));
        $n = 0;
        DB::transaction(static function () use ($classId, $date, $statuses, $userId, $members, &$n): void {
            foreach ($statuses as $sid => $code) {
                $sid = (int) $sid;
                if (!isset($members[$sid])) {
                    continue;
                }
                if ($code === '') {
                    DB::run('DELETE FROM pilot_checks WHERE student_id = ? AND check_date = ?', [$sid, $date]);
                    continue;
                }
                if (!in_array($code, Recap::CODES, true)) {
                    continue;
                }
                $prev = DB::one('SELECT id, manual_status FROM pilot_checks WHERE student_id = ? AND check_date = ?', [$sid, $date]);
                if ($prev) {
                    if ($prev['manual_status'] !== $code) {
                        DB::update('pilot_checks', ['manual_status' => $code, 'entered_by' => $userId, 'explanation' => null, 'explained_by' => null, 'explained_at' => null], 'id = ?', [(int) $prev['id']]);
                    }
                } else {
                    DB::insert('pilot_checks', ['class_id' => $classId, 'student_id' => $sid, 'check_date' => $date, 'manual_status' => $code, 'entered_by' => $userId, 'created_at' => now()]);
                }
                $n++;
            }
        });
        return $n;
    }

    /**
     * Impor CSV absen manual: kolom nis, tanggal (YYYY-MM-DD atau DD/MM/YYYY), status (H/T/S/I/A/D atau kata Hadir/Sakit/…).
     * @return array{saved:int, errors:array<int,string>}
     */
    public static function importCsv(int $classId, string $file, string $originalName, int $userId): array
    {
        $sheet = Spreadsheet::read($file, $originalName);
        $rows = array_map(static fn (array $r) => Spreadsheet::assoc($sheet['headers'], $r), $sheet['rows']);
        $byNis = [];
        foreach (Recap::students($classId) as $s) {
            $byNis[(string) $s['nis']] = (int) $s['id'];
        }
        $words = ['HADIR' => 'H', 'TERLAMBAT' => 'T', 'SAKIT' => 'S', 'IZIN' => 'I', 'ALPA' => 'A', 'ALFA' => 'A', 'DISPENSASI' => 'D'];
        $per = [];
        $errors = [];
        foreach ($rows as $i => $r) {
            $line = $i + 2;
            $nis = trim((string) ($r['nis'] ?? ''));
            $date = self::date((string) ($r['tanggal'] ?? ''));
            $st = strtoupper(trim((string) ($r['status'] ?? '')));
            $st = $words[$st] ?? $st;
            if (!isset($byNis[$nis])) {
                $errors[$line] = t('NIS :n bukan siswa kelas ini', ['n' => $nis]);
            } elseif ($date === null) {
                $errors[$line] = t('Tanggal tidak dikenal');
            } elseif (!in_array($st, Recap::CODES, true)) {
                $errors[$line] = t('Status harus H, T, S, I, A, atau D');
            } else {
                $per[$date][$byNis[$nis]] = $st;
            }
        }
        $saved = 0;
        foreach ($per as $date => $statuses) {
            $saved += self::saveManual($classId, $date, $statuses, $userId);
        }
        return ['saved' => $saved, 'errors' => $errors];
    }

    /** Ringkasan akurasi per kelas: hari dibandingkan, baris, cocok, selisih, selisih belum dijelaskan. */
    public static function accuracy(int $classId, string $from, string $to): array
    {
        $dates = array_column(DB::all('SELECT DISTINCT check_date FROM pilot_checks WHERE class_id = ? AND check_date BETWEEN ? AND ? ORDER BY check_date', [$classId, $from, $to]), 'check_date');
        $out = ['days' => [], 'rows' => 0, 'match' => 0, 'diff' => 0, 'unexplained' => 0];
        foreach ($dates as $d) {
            $rows = array_filter(self::compare($classId, (string) $d), static fn ($r) => $r['manual'] !== null);
            $diff = array_filter($rows, static fn ($r) => !$r['match']);
            $un = array_filter($diff, static fn ($r) => trim((string) $r['explanation']) === '');
            $out['days'][$d] = ['rows' => count($rows), 'match' => count($rows) - count($diff), 'diff' => count($diff), 'unexplained' => count($un)];
            $out['rows'] += count($rows);
            $out['match'] += count($rows) - count($diff);
            $out['diff'] += count($diff);
            $out['unexplained'] += count($un);
        }
        $out['pass'] = $out['rows'] > 0 ? $out['unexplained'] === 0 : null;
        return $out;
    }

    public static function explain(int $checkId, string $text, int $userId): void
    {
        DB::update('pilot_checks', ['explanation' => $text !== '' ? mb_substr($text, 0, 500) : null, 'explained_by' => $userId, 'explained_at' => now()], 'id = ?', [$checkId]);
    }

    private static function date(string $v): ?string
    {
        $v = trim($v);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $v;
        }
        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $v, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        return null;
    }
}
