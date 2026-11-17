<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database as DB;
use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\Leave;
use App\Models\StatusHistory;
use App\Models\Card;
use App\Models\ScanLog;
use App\Models\ScanMetric;

/**
 * Inti absensi (PRD 5.2): satu scan → satu keputusan. Semua jalur mencatat ke scan_logs.
 *
 * Hasil (state): ok, late, dup, wrongclass, invalid, nosession.
 * Idempoten: scan_id yang sama mengembalikan jawaban yang sama tanpa mencatat ulang.
 *
 * Fase 4:
 * - Jalur cepat: kartu + siswa + kelas dalam satu query, sesi ruangan dari cache singkat, satu INSERT kehadiran.
 *   Log scan ditulis setelah jawaban dikirim (lihat flushDeferred) agar tidak menambah waktu tunggu kiosk.
 * - Scan offline: scanned_at dari perangkat dipakai (jam saat scan, bukan jam sinkron), dikoreksi dengan selisih jam perangkat
 *   (sent_at perangkat vs jam server). Scan yang tersinkron setelah sesinya ditutup tetap dicatat dan menimpa Alpa otomatis.
 */
final class ScanService
{
    public const STATES = ['ok', 'late', 'dup', 'wrongclass', 'invalid', 'nosession'];
    public const MAX_AGE = 3 * 86400;   // scan offline diterima sampai 3 hari ke belakang
    public const MAX_BATCH = 100;

    /** @var array<int, callable():void> pekerjaan yang ditunda sampai setelah respons dikirim */
    private static array $deferred = [];

    /**
     * @param array $device baris perangkat (aktif)
     * @param array{scan_id?:string, code?:string, scanned_at?:string, sent_at?:string, metrics?:array} $body
     * @param bool $defer true = log ditulis lewat flushDeferred() (dipanggil controller setelah respons terkirim)
     * @return array jawaban JSON untuk kiosk (ok, state, message, student, status, late_minutes, …)
     */
    public static function scan(array $device, array $body, ?string $now = null, bool $defer = false, ?int $offsetMs = null): array
    {
        $t0 = hrtime(true);
        $now ??= now();
        $scanId = self::scanId($body['scan_id'] ?? null);
        if ($scanId !== null && ($prev = ScanLog::findByScanId($scanId))) {
            $resp = json_decode((string) $prev['response'], true) ?: ['ok' => false, 'state' => 'invalid'];
            return $resp + ['replayed' => true];
        }
        $raw = is_string($body['code'] ?? null) ? $body['code'] : '';
        $code = Card::normalize(mb_substr($raw, 0, 64));
        $offsetMs ??= self::clockOffsetMs($body['sent_at'] ?? null, $now);
        $scannedAt = self::scannedAt($body['scanned_at'] ?? null, $now, $offsetMs);
        $late = strtotime($now) - strtotime($scannedAt) > 90; // scan dari antrean offline
        $meta = is_array($body['metrics'] ?? null) ? array_slice($body['metrics'], 0, 12, true) : [];
        $log = ['scan_id' => $scanId, 'device_id' => (int) $device['id'], 'code' => $code, 'scanned_at' => $scannedAt, 'metrics' => $meta];

        $finish = static function (array $resp, string $result, ?int $sessionId = null, ?int $studentId = null) use ($log, $t0, $scanId, $defer): array {
            $resp['t_server_ms'] = round((hrtime(true) - $t0) / 1e6, 2);
            $resp['scan_id'] = $scanId;
            $log += ['result' => $result, 'session_id' => $sessionId, 'student_id' => $studentId, 'response' => $resp, 't_server_ms' => $resp['t_server_ms']];
            if ($defer) {
                self::$deferred[] = static fn () => ScanLog::add($log);
                return $resp;
            }
            if (!ScanLog::add($log) && $scanId !== null && ($prev = ScanLog::findByScanId($scanId))) {
                return (json_decode((string) $prev['response'], true) ?: $resp) + ['replayed' => true];
            }
            return $resp;
        };

        // 1. Kartu + siswa + kelas tahun ajaran aktif (satu query)
        $card = Card::isValidFormat($code) ? self::cardWithClass($code) : null;
        if (!$card || $card['status'] !== 'active' || $card['student_status'] !== 'Aktif') {
            return $finish(['ok' => false, 'state' => 'invalid', 'message' => t('Kartu tidak berlaku, hubungi TU')], 'invalid', null, $card ? (int) $card['student_id'] : null);
        }
        $studentId = (int) $card['student_id'];
        $classId = (int) ($card['class_id'] ?? 0);
        $class = $classId ? ['id' => $classId, 'name' => $card['class_name']] : null;

        // 2. Sesi di ruangan perangkat pada jam scan (scan offline: termasuk sesi yang sudah ditutup)
        $roomId = (int) ($device['room_id'] ?? 0);
        $sessions = $roomId > 0 ? SessionResolver::resolve($roomId, $scannedAt, $late) : [];
        if (!$sessions) {
            return $finish(['ok' => false, 'state' => 'nosession', 'message' => t('Belum ada jadwal di ruangan ini'),
                'student' => self::studentOut($card, $class)], 'nosession', null, $studentId);
        }

        // 3. Kelas siswa vs kelas sesi
        $matching = array_values(array_filter($sessions, static fn (array $s) => (int) $s['class_id'] === $classId));
        if (!$matching) {
            $primary = $sessions[0];
            return $finish(['ok' => false, 'state' => 'wrongclass', 'message' => t('Bukan kelas :c', ['c' => $primary['class_name']]),
                'student' => self::studentOut($card, $class), 'session' => self::sessionOut($primary)], 'wrongclass', (int) $primary['id'], $studentId);
        }

        // 4. Catat ke setiap sesi yang cocok (mode "keduanya" bisa dua); sesi pertama menentukan jawaban.
        $primary = $matching[0];
        $result = null;
        foreach ($matching as $s) {
            $r = self::record($s, $studentId, $classId, $scannedAt, (int) $device['id'], $scanId);
            $result ??= $r;
        }
        $state = $result['state'];
        $out = ['ok' => true, 'state' => $state, 'student' => self::studentOut($card, $class), 'session' => self::sessionOut($primary),
            'status' => $result['status'], 'late_minutes' => $result['late_minutes'], 'scanned_at' => date('H:i', strtotime($result['scanned_at']))];
        $out['message'] = match ($state) {
            'late' => t('Terlambat :n menit', ['n' => $result['late_minutes']]),
            'dup' => t('Sudah tercatat :t', ['t' => $out['scanned_at']]),
            default => $primary['kind'] === 'harian_pulang' ? t('Pulang tercatat') : t('Hadir'),
        };
        return $finish($out, $state, (int) $primary['id'], $studentId);
    }

    /**
     * Kirim beberapa scan sekaligus (antrean offline). Idempoten per scan_id: batch yang sama boleh dikirim berkali-kali.
     * @param array{sent_at?:string, scans?:array, metrics?:array, queue_size?:int} $body
     * @return array{ok:bool, results:array, clock_offset_ms:int, metrics_saved:int}
     */
    public static function batch(array $device, array $body, ?string $now = null, bool $defer = false): array
    {
        $now ??= now();
        $offsetMs = self::clockOffsetMs($body['sent_at'] ?? null, $now);
        $results = [];
        $scans = is_array($body['scans'] ?? null) ? array_slice(array_values($body['scans']), 0, self::MAX_BATCH) : [];
        foreach ($scans as $item) {
            if (!is_array($item)) {
                continue;
            }
            $results[] = self::scan($device, $item, $now, $defer, $offsetMs);
        }
        $saved = 0;
        if (is_array($body['metrics'] ?? null) && $body['metrics']) {
            $metrics = array_slice(array_values($body['metrics']), 0, 200);
            $deviceId = (int) $device['id'];
            $job = static fn (): int => ScanMetric::addMany($deviceId, $metrics);
            if ($defer) {
                self::$deferred[] = $job;
                $saved = count($metrics);
            } else {
                $saved = $job();
            }
        }
        return ['ok' => true, 'results' => $results, 'clock_offset_ms' => $offsetMs, 'metrics_saved' => $saved, 'server_time' => $now];
    }

    /** Jalankan pekerjaan tertunda (log scan, metrik). Dipanggil setelah respons dikirim ke kiosk. */
    public static function flushDeferred(): void
    {
        $jobs = self::$deferred;
        self::$deferred = [];
        foreach ($jobs as $job) {
            try {
                $job();
            } catch (\Throwable $e) {
                error_log('scan deferred gagal: ' . $e->getMessage());
            }
        }
    }

    /** Kartu beserta siswa dan kelasnya pada tahun ajaran aktif; null bila kode tidak dikenal. */
    public static function cardWithClass(string $code): ?array
    {
        $year = AcademicYear::active();
        return DB::one('SELECT c.student_id, c.status, c.version, s.nis, s.full_name, s.status AS student_status, s.photo_path, m.class_id, k.name AS class_name
            FROM cards c JOIN students s ON s.id = c.student_id
            LEFT JOIN class_members m ON m.student_id = s.id AND m.academic_year_id = ?
            LEFT JOIN classes k ON k.id = m.class_id
            WHERE c.code = ?', [$year ? (int) $year['id'] : 0, $code]);
    }

    /**
     * Sisipkan kehadiran secara atomik. Bila baris yang ada adalah Alpa otomatis (sesi sudah ditutup sebelum scan offline tersinkron),
     * scan menang (PRD: scan > Alpa otomatis) dan baris itu diperbarui.
     * @return array{state:string, status:string, late_minutes:int, scanned_at:string}
     */
    public static function record(array $session, int $studentId, int $classId, string $scannedAt, ?int $deviceId, ?string $scanId, string $method = 'scan', ?int $userId = null): array
    {
        [$scanStatus, $late] = self::judge($session, $scannedAt);
        // Izin/sakit/dispensasi mengalahkan scan (PRD bagian 9); jam scan tetap disimpan.
        $leave = Leave::activeFor($studentId, (string) $session['session_date']);
        $status = $leave['kind'] ?? $scanStatus;
        $state = $scanStatus === 'T' ? 'late' : 'ok';
        $id = Attendance::tryInsert([
            'session_id' => (int) $session['id'], 'student_id' => $studentId, 'class_id' => $classId, 'status' => $status,
            'scanned_at' => $scannedAt, 'late_minutes' => $late, 'method' => $method, 'device_id' => $deviceId, 'scan_id' => $scanId, 'changed_by' => $userId,
            'leave_id' => $leave['id'] ?? null,
        ]);
        if ($id !== null) {
            return ['state' => $state, 'status' => $status, 'late_minutes' => $late, 'scanned_at' => $scannedAt];
        }
        $prev = Attendance::find((int) $session['id'], $studentId);
        if ($prev && empty($prev['scanned_at'])) {
            // Baris tanpa scan: Alpa otomatis (scan tersinkron setelah sesi ditutup), izin, atau koreksi. Simpan fakta scan;
            // status ikut prioritas: koreksi tetap, izin tetap, Alpa otomatis menjadi hasil scan.
            $keep = !empty($prev['corrected']) || (!empty($prev['leave_id']) && $prev['status'] !== 'A');
            $new = $keep ? (string) $prev['status'] : $status;
            $n = DB::update('attendances', ['status' => $new, 'scanned_at' => $scannedAt, 'late_minutes' => $late, 'method' => $keep ? $prev['method'] : $method,
                'device_id' => $deviceId, 'scan_id' => $scanId, 'leave_id' => $keep ? $prev['leave_id'] : ($leave['id'] ?? null),
                'changed_at' => now(), 'note' => $prev['note'] ?? ($prev['status'] === 'A' ? 'Scan tersinkron setelah sesi ditutup' : null)],
                'id = ? AND scanned_at IS NULL', [(int) $prev['id']]);
            if ($n === 1) {
                if ($new !== $prev['status']) {
                    StatusHistory::add((int) $prev['id'], (int) $session['id'], $studentId, $prev['status'], $new, 'scan', null, $userId);
                }
                return ['state' => $state, 'status' => $new, 'late_minutes' => $late, 'scanned_at' => $scannedAt];
            }
            $prev = Attendance::find((int) $session['id'], $studentId);
        }
        return ['state' => 'dup', 'status' => (string) $prev['status'], 'late_minutes' => (int) $prev['late_minutes'], 'scanned_at' => (string) ($prev['scanned_at'] ?: $prev['created_at'])];
    }

    /**
     * Hadir bila menit keterlambatan (dibulatkan ke bawah, dihitung sejak starts_at) ≤ toleransi; selain itu Terlambat N menit.
     * Toleransi 10: 07:10:59 masih Hadir (menit ke-10), 07:11:00 Terlambat 11 menit. Sesi pulang tidak mengenal terlambat.
     * @return array{0:string,1:int}
     */
    public static function judge(array $session, string $scannedAt): array
    {
        if ($session['kind'] === 'harian_pulang') {
            return ['H', 0];
        }
        $late = (int) floor((strtotime($scannedAt) - strtotime($session['starts_at'])) / 60);
        $tolerance = (int) floor((strtotime($session['late_after']) - strtotime($session['starts_at'])) / 60);
        return $late <= $tolerance ? ['H', 0] : ['T', $late];
    }

    private static function scanId(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $v) ? $v : null;
    }

    /**
     * Selisih jam perangkat terhadap server (ms, positif = jam perangkat tertinggal), dari sent_at yang diisi perangkat tepat sebelum mengirim.
     * Selisih kecil (< 2 detik, termasuk waktu tempuh jaringan) dianggap nol; selisih > 12 jam dianggap salah zona dan tetap dikoreksi.
     */
    public static function clockOffsetMs(mixed $sentAt, string $now): int
    {
        if (!is_string($sentAt) || $sentAt === '' || ($ts = strtotime($sentAt)) === false) {
            return 0;
        }
        $diff = (strtotime($now) - $ts) * 1000;
        return abs($diff) < 2000 ? 0 : $diff;
    }

    /**
     * Jam scan menurut jam server: scanned_at perangkat + selisih jam perangkat. Dipakai bila masuk akal
     * (≤ MAX_AGE ke belakang, tidak di masa depan); selain itu jam server saat diterima.
     */
    private static function scannedAt(mixed $v, string $now, int $offsetMs = 0): string
    {
        if (!is_string($v) || $v === '') {
            return $now;
        }
        $ts = strtotime($v);
        if ($ts === false) {
            return $now;
        }
        $ts += intdiv($offsetMs, 1000);
        $nowTs = strtotime($now);
        if ($ts > $nowTs + 120 || $ts < $nowTs - self::MAX_AGE) {
            return $now;
        }
        return date('Y-m-d H:i:s', min($ts, $nowTs));
    }

    public static function studentOut(array $card, ?array $class): array
    {
        return ['id' => (int) $card['student_id'], 'name' => $card['full_name'], 'nis' => $card['nis'], 'class' => $class['name'] ?? null,
            'photo' => array_key_exists('photo_path', $card) ? !empty($card['photo_path']) : Photo::exists((int) $card['student_id'])];
    }

    public static function sessionOut(array $s): array
    {
        return ['id' => (int) $s['id'], 'kind' => $s['kind'], 'class' => $s['class_name'], 'class_id' => (int) $s['class_id'], 'subject' => $s['subject_name'] ?? null,
            'label' => SessionResolver::label($s), 'scan_from' => $s['scan_from'], 'starts' => $s['starts_at'], 'late' => $s['late_after'], 'ends' => $s['ends_at'],
            'starts_at' => substr($s['starts_at'], 11, 5), 'ends_at' => substr($s['ends_at'], 11, 5), 'late_after' => substr($s['late_after'], 11, 5)];
    }
}
