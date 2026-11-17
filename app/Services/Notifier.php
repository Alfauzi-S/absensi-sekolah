<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database as DB;
use App\Models\AcademicYear;
use App\Models\Master;
use App\Models\Notification;
use App\Models\Semester;
use App\Models\Setting;

/**
 * Notifikasi WhatsApp ke orang tua saat anak Alpa atau Terlambat (PRD Fase 6). Mati secara bawaan; dinyalakan pengurus di Pengaturan.
 *
 * Aturan:
 *  - paling banyak satu pesan per siswa per hari (UNIQUE student_id + notify_date di tabel notifications);
 *  - Terlambat: diantrekan saat scan, bila status harian siswa hari itu Terlambat (bukan sekadar terlambat di jam ke-3);
 *  - Alpa: diantrekan setelah semua sesi terhitung kelas itu hari ini ditutup dan status harian siswa Alpa;
 *  - sebelum dikirim status harian diperiksa ulang: bila sudah berubah (izin dicatat, koreksi, scan offline masuk) pesan dilewati;
 *  - gagal kirim dicoba ulang dengan jeda 1, 5, 15, 60 menit; setelah MAX_ATTEMPTS kali berstatus Gagal (bisa dicoba ulang dari halaman Notifikasi);
 *  - nomor HP orang tua hanya dibaca di sini (server), tidak pernah dikirim ke perangkat scanner.
 */
final class Notifier
{
    public const MAX_ATTEMPTS = 5;
    public const BACKOFF_MINUTES = [1, 5, 15, 60];
    public const STALE_MINUTES = 10;   // baris 'sending' lebih lama dari ini dianggap macet (proses mati) dan dikembalikan ke antrean
    public const GRACE_MINUTES = 10;   // jeda setelah jam pelajaran terakhir sebelum Alpa diantrekan

    public static function enabled(string $kind): bool
    {
        $mode = Setting::get('wa_notify', 'off');
        return $kind === 'A' ? in_array($mode, ['alpa', 'both'], true) : ($kind === 'T' ? in_array($mode, ['terlambat', 'both'], true) : false);
    }

    /** Status harian siswa pada tanggal itu menurut sesi terhitung yang sudah ada (Recap::dayCode). */
    public static function dayCode(int $studentId, int $classId, string $date): string
    {
        $kinds = "'" . implode("','", Recap::DAILY_KINDS) . "'";
        $rows = DB::all("SELECT a.status FROM sessions s LEFT JOIN attendances a ON a.session_id = s.id AND a.student_id = ?
            WHERE s.class_id = ? AND s.session_date = ? AND s.status IN ('open','closed') AND s.kind IN ($kinds) ORDER BY s.starts_at, s.id",
            [$studentId, $classId, $date]);
        return Recap::dayCode(array_column($rows, 'status'));
    }

    /**
     * Dipanggil setelah scan berstatus Terlambat tercatat. Murah: satu query status harian + satu INSERT.
     * Bila hari itu sudah ada pesan Alpa yang belum terkirim (scan offline masuk belakangan), pesan itu diganti menjadi Terlambat.
     */
    public static function enqueueLate(int $studentId, int $classId, string $date, string $scannedAt, int $lateMinutes): void
    {
        if (!self::enabled('T') || self::dayCode($studentId, $classId, $date) !== 'T') {
            return;
        }
        $st = self::studentInfo($studentId);
        if (!$st) {
            return;
        }
        $vars = ['jam' => substr($scannedAt, 11, 5), 'menit' => (string) $lateMinutes];
        $row = self::row($st, $date, 'T', $vars);
        if (Notification::tryInsert($row) !== null) {
            return;
        }
        DB::update('notifications', ['kind' => 'T', 'message' => $row['message'], 'phone' => $row['phone'], 'status' => $row['status'],
            'last_error' => $row['last_error'], 'attempts' => 0, 'next_attempt_at' => null],
            "student_id = ? AND notify_date = ? AND kind = 'A' AND status IN ('pending','failed','skipped')", [$studentId, $date]);
    }

    /**
     * Antrekan pesan Alpa untuk kelas yang hari ini sudah selesai (semua sesi terhitung ditutup, jam pelajaran terakhir lewat).
     * Idempoten: siswa yang sudah punya baris hari itu tidak diantrekan lagi. @return int jumlah pesan baru
     */
    public static function queueDailyAbsent(string $date, ?string $now = null): int
    {
        $now ??= now();
        if (!self::enabled('A') || in_array($date, Recap::holidays($date, $date), true)) {
            return 0;
        }
        $kinds = "'" . implode("','", Recap::DAILY_KINDS) . "'";
        $classes = DB::all("SELECT class_id, SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open_n, SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) AS closed_n
            FROM sessions WHERE session_date = ? AND kind IN ($kinds) AND status IN ('open','closed') GROUP BY class_id", [$date]);
        $n = 0;
        foreach ($classes as $c) {
            $classId = (int) $c['class_id'];
            if ((int) $c['open_n'] > 0 || (int) $c['closed_n'] === 0 || !self::scheduleOver($classId, $date, $now)) {
                continue;
            }
            $already = array_flip(array_map('intval', array_column(DB::all('SELECT n.student_id FROM notifications n
                JOIN class_members m ON m.student_id = n.student_id AND m.class_id = ? WHERE n.notify_date = ?', [$classId, $date]), 'student_id')));
            $g = Recap::grid($classId, $date, $date);
            foreach ($g['students'] as $st) {
                $sid = (int) $st['id'];
                if (isset($already[$sid]) || ($g['codes'][$sid][$date] ?? '') !== 'A') {
                    continue;
                }
                $info = self::studentInfo($sid);
                if ($info && Notification::tryInsert(self::row($info, $date, 'A', [])) !== null) {
                    $n++;
                }
            }
        }
        return $n;
    }

    /**
     * Kirim antrean yang jatuh tempo. Aman dijalankan bersamaan (cron + tombol "Kirim sekarang"): setiap baris diklaim
     * dengan UPDATE … WHERE status = 'pending' sehingga hanya satu proses yang mengirimnya.
     * @return array{sent:int, failed:int, retry:int, skipped:int}
     */
    public static function sendPending(int $limit = 100, ?string $now = null): array
    {
        $now ??= now();
        $out = ['sent' => 0, 'failed' => 0, 'retry' => 0, 'skipped' => 0];
        DB::run("UPDATE notifications SET status = 'pending', claimed_at = NULL WHERE status = 'sending' AND claimed_at < ?",
            [date('Y-m-d H:i:s', strtotime($now) - self::STALE_MINUTES * 60)]);
        $due = DB::all("SELECT id FROM notifications WHERE status = 'pending' AND (next_attempt_at IS NULL OR next_attempt_at <= ?) ORDER BY id LIMIT " . max(1, $limit), [$now]);
        foreach ($due as $d) {
            $id = (int) $d['id'];
            if (DB::update('notifications', ['status' => 'sending', 'claimed_at' => $now], "id = ? AND status = 'pending'", [$id]) !== 1) {
                continue; // diambil proses lain
            }
            $n = Notification::find($id);
            if (($why = self::stale($n)) !== null) {
                DB::update('notifications', ['status' => 'skipped', 'last_error' => $why, 'claimed_at' => null], 'id = ?', [$id]);
                $out['skipped']++;
                continue;
            }
            $r = WaGateway::send((string) $n['phone'], (string) $n['message']);
            $attempts = (int) $n['attempts'] + 1;
            if ($r['ok']) {
                DB::update('notifications', ['status' => 'sent', 'attempts' => $attempts, 'sent_at' => now(), 'provider_ref' => $r['ref'], 'last_error' => null, 'claimed_at' => null], 'id = ?', [$id]);
                $out['sent']++;
            } elseif (!$r['retry'] || $attempts >= self::MAX_ATTEMPTS) {
                DB::update('notifications', ['status' => 'failed', 'attempts' => $attempts, 'last_error' => $r['error'], 'claimed_at' => null, 'next_attempt_at' => null], 'id = ?', [$id]);
                $out['failed']++;
            } else {
                $wait = self::BACKOFF_MINUTES[min($attempts - 1, count(self::BACKOFF_MINUTES) - 1)];
                DB::update('notifications', ['status' => 'pending', 'attempts' => $attempts, 'last_error' => $r['error'], 'claimed_at' => null,
                    'next_attempt_at' => date('Y-m-d H:i:s', strtotime($now) + $wait * 60)], 'id = ?', [$id]);
                $out['retry']++;
            }
        }
        return $out;
    }

    /** Jalankan antrean (dari cron). @return array{queued:int, sent:int, failed:int, retry:int, skipped:int} */
    public static function run(?string $now = null, int $limit = 100): array
    {
        $now ??= now();
        if (Setting::get('wa_notify', 'off') === 'off' && !DB::value("SELECT 1 FROM notifications WHERE status = 'pending' LIMIT 1")) {
            return ['queued' => 0, 'sent' => 0, 'failed' => 0, 'retry' => 0, 'skipped' => 0];
        }
        $queued = self::queueDailyAbsent(substr($now, 0, 10), $now);
        return ['queued' => $queued] + self::sendPending($limit, $now);
    }

    /** Isi template: {nama} {kelas} {tanggal} {jam} {menit} {sekolah}. */
    public static function render(string $template, array $vars): string
    {
        $map = [];
        foreach (['nama', 'kelas', 'tanggal', 'jam', 'menit', 'sekolah'] as $k) {
            $map['{' . $k . '}'] = (string) ($vars[$k] ?? '');
        }
        return trim(strtr($template, $map));
    }

    /** Alasan pesan tidak lagi relevan (null = tetap kirim). */
    private static function stale(?array $n): ?string
    {
        if (!$n) {
            return 'Baris hilang';
        }
        if (!self::enabled((string) $n['kind'])) {
            return 'Notifikasi untuk jenis ini dimatikan';
        }
        if (!$n['phone']) {
            return 'Nomor HP orang tua kosong atau tidak valid';
        }
        $classId = self::classOf((int) $n['student_id']);
        if (!$classId || self::dayCode((int) $n['student_id'], $classId, (string) $n['notify_date']) !== $n['kind']) {
            return 'Status harian sudah berubah';
        }
        return null;
    }

    private static function row(array $st, string $date, string $kind, array $vars): array
    {
        $phone = Phone::normalize($st['parent_phone'] ?? null);
        $tpl = Setting::get($kind === 'A' ? 'wa_template_alpa' : 'wa_template_late') ?: ($kind === 'A' ? Setting::WA_TEMPLATE_ALPA : Setting::WA_TEMPLATE_LATE);
        $school = Setting::get('school_name') ?: (string) config('app_name', 'Absensi Sekolah');
        $msg = self::render($tpl, $vars + ['nama' => $st['full_name'], 'kelas' => $st['class_name'] ?? '', 'tanggal' => date_id($date, true), 'sekolah' => $school]);
        return ['student_id' => (int) $st['id'], 'notify_date' => $date, 'kind' => $kind, 'phone' => $phone, 'message' => mb_substr($msg, 0, 1000),
            'status' => $phone ? 'pending' : 'skipped', 'last_error' => $phone ? null : 'Nomor HP orang tua kosong atau tidak valid'];
    }

    private static function studentInfo(int $studentId): ?array
    {
        $year = AcademicYear::active();
        return DB::one('SELECT s.id, s.full_name, s.parent_phone, c.name AS class_name FROM students s
            LEFT JOIN class_members m ON m.student_id = s.id AND m.academic_year_id = ? LEFT JOIN classes c ON c.id = m.class_id WHERE s.id = ?',
            [$year ? (int) $year['id'] : 0, $studentId]);
    }

    private static function classOf(int $studentId): ?int
    {
        $year = AcademicYear::active();
        $v = $year ? DB::value('SELECT class_id FROM class_members WHERE student_id = ? AND academic_year_id = ?', [$studentId, (int) $year['id']]) : null;
        return $v ? (int) $v : null;
    }

    /** Jam pelajaran terakhir kelas itu hari ini sudah lewat (mode per jam); mode masuk/pulang cukup sesi Masuk ditutup. */
    private static function scheduleOver(int $classId, string $date, string $now): bool
    {
        if (Setting::get('attendance_mode') === Setting::ATT_MODE_DAILY) {
            return true;
        }
        $sem = Semester::active();
        if (!$sem) {
            return true;
        }
        $last = DB::value('SELECT MAX(period_end) FROM schedules WHERE semester_id = ? AND class_id = ? AND day_of_week = ?',
            [(int) $sem['id'], $classId, (int) date('N', strtotime($date))]);
        if (!$last) {
            return true;
        }
        $p = Master::periods()[(int) $last] ?? null;
        if (!$p) {
            return true;
        }
        $end = $date . ' ' . substr((string) $p['end_time'], 0, 5) . ':00';
        return strtotime($now) >= strtotime($end) + self::GRACE_MINUTES * 60;
    }
}
