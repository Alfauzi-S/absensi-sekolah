<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\Card;
use App\Models\Device;
use App\Models\ScanLog;
use App\Models\Session;
use App\Models\Student;

/**
 * Inti absensi (PRD 5.2): satu scan → satu keputusan. Semua jalur mencatat ke scan_logs.
 *
 * Hasil (state): ok, late, dup, wrongclass, invalid, nosession.
 * Idempoten: scan_id yang sama mengembalikan jawaban yang sama tanpa mencatat ulang.
 */
final class ScanService
{
    public const STATES = ['ok', 'late', 'dup', 'wrongclass', 'invalid', 'nosession'];

    /**
     * @param array $device baris perangkat (aktif)
     * @param array{scan_id?:string, code?:string, scanned_at?:string, metrics?:array} $body
     * @return array jawaban JSON untuk kiosk (ok, state, message, student, status, late_minutes, …)
     */
    public static function scan(array $device, array $body, ?string $now = null): array
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
        $scannedAt = self::scannedAt($body['scanned_at'] ?? null, $now);
        $metrics = is_array($body['metrics'] ?? null) ? array_slice($body['metrics'], 0, 12, true) : [];
        $log = ['scan_id' => $scanId, 'device_id' => (int) $device['id'], 'code' => $code, 'scanned_at' => $scannedAt, 'metrics' => $metrics];

        $finish = static function (array $resp, string $result, ?int $sessionId = null, ?int $studentId = null) use ($log, $t0, $scanId): array {
            $resp['t_server_ms'] = round((hrtime(true) - $t0) / 1e6, 2);
            $resp['scan_id'] = $scanId;
            $log += ['result' => $result, 'session_id' => $sessionId, 'student_id' => $studentId, 'response' => $resp, 't_server_ms' => $resp['t_server_ms']];
            if (!ScanLog::add($log) && $scanId !== null && ($prev = ScanLog::findByScanId($scanId))) {
                return (json_decode((string) $prev['response'], true) ?: $resp) + ['replayed' => true];
            }
            return $resp;
        };

        // 1. Kartu
        $card = Card::isValidFormat($code) ? Card::findByCode($code) : null;
        if (!$card || $card['status'] !== 'active' || $card['student_status'] !== 'Aktif') {
            return $finish(['ok' => false, 'state' => 'invalid', 'message' => t('Kartu tidak berlaku, hubungi TU')], 'invalid', null, $card ? (int) $card['student_id'] : null);
        }
        $studentId = (int) $card['student_id'];

        // 2. Sesi di ruangan perangkat
        $roomId = (int) ($device['room_id'] ?? 0);
        $sessions = $roomId > 0 ? SessionResolver::resolve($roomId, $now) : [];
        if (!$sessions) {
            return $finish(['ok' => false, 'state' => 'nosession', 'message' => t('Belum ada jadwal di ruangan ini'),
                'student' => self::studentOut($card, null)], 'nosession', null, $studentId);
        }

        // 3. Kelas siswa vs kelas sesi
        $year = AcademicYear::active();
        $class = $year ? Student::classIn($studentId, (int) $year['id']) : null;
        $classId = $class ? (int) $class['id'] : 0;
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

    /** Sisipkan kehadiran secara atomik. @return array{state:string, status:string, late_minutes:int, scanned_at:string} */
    public static function record(array $session, int $studentId, int $classId, string $scannedAt, ?int $deviceId, ?string $scanId, string $method = 'scan', ?int $userId = null): array
    {
        [$status, $late] = self::judge($session, $scannedAt);
        $id = Attendance::tryInsert([
            'session_id' => (int) $session['id'], 'student_id' => $studentId, 'class_id' => $classId, 'status' => $status,
            'scanned_at' => $scannedAt, 'late_minutes' => $late, 'method' => $method, 'device_id' => $deviceId, 'scan_id' => $scanId, 'changed_by' => $userId,
        ]);
        if ($id === null) {
            $prev = Attendance::find((int) $session['id'], $studentId);
            return ['state' => 'dup', 'status' => (string) $prev['status'], 'late_minutes' => (int) $prev['late_minutes'], 'scanned_at' => (string) ($prev['scanned_at'] ?: $prev['created_at'])];
        }
        return ['state' => $status === 'T' ? 'late' : 'ok', 'status' => $status, 'late_minutes' => $late, 'scanned_at' => $scannedAt];
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

    /** Waktu scan dari kiosk dipakai bila masuk akal (≤ 24 jam ke belakang, tidak di masa depan); selain itu waktu server. */
    private static function scannedAt(mixed $v, string $now): string
    {
        if (!is_string($v) || $v === '') {
            return $now;
        }
        $ts = strtotime($v);
        if ($ts === false) {
            return $now;
        }
        $nowTs = strtotime($now);
        if ($ts > $nowTs + 120 || $ts < $nowTs - 86400) {
            return $now;
        }
        return date('Y-m-d H:i:s', min($ts, $nowTs));
    }

    public static function studentOut(array $card, ?array $class): array
    {
        return ['id' => (int) $card['student_id'], 'name' => $card['full_name'], 'nis' => $card['nis'], 'class' => $class['name'] ?? null,
            'photo' => Photo::exists((int) $card['student_id'])];
    }

    public static function sessionOut(array $s): array
    {
        return ['id' => (int) $s['id'], 'kind' => $s['kind'], 'class' => $s['class_name'], 'subject' => $s['subject_name'] ?? null,
            'label' => SessionResolver::label($s), 'starts_at' => substr($s['starts_at'], 11, 5), 'ends_at' => substr($s['ends_at'], 11, 5), 'late_after' => substr($s['late_after'], 11, 5)];
    }
}
