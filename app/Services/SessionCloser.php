<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database as DB;
use App\Core\RateLimiter;
use App\Models\Attendance;
use App\Models\Session;

/**
 * Menutup sesi: siswa yang belum tercatat menjadi Alpa (method auto). Izin/sakit (Fase 5) akan dikecualikan di sini.
 * Dijalankan oleh bin/close-sessions.php (cron) dan, sebagai cadangan, sesekali dari permintaan kiosk.
 */
final class SessionCloser
{
    public const GRACE_MINUTES = 5; // tunggu sebentar setelah ends_at agar scan yang terlambat dikirim masih masuk

    /** @return int jumlah sesi yang ditutup */
    public static function closeExpired(?string $now = null): int
    {
        $now ??= now();
        $limit = date('Y-m-d H:i:s', strtotime($now) - self::GRACE_MINUTES * 60);
        $n = 0;
        foreach (Session::expired($limit) as $s) {
            self::close($s, null);
            $n++;
        }
        return $n;
    }

    /** Tutup satu sesi (manual oleh guru/TU atau otomatis). @return int jumlah siswa yang ditandai Alpa */
    public static function close(array $session, ?int $userId): int
    {
        return DB::transaction(static function () use ($session, $userId): int {
            $n = 0;
            foreach (Attendance::missingStudentIds($session) as $sid) {
                if (Attendance::tryInsert(['session_id' => (int) $session['id'], 'student_id' => $sid, 'class_id' => (int) $session['class_id'],
                    'status' => 'A', 'method' => 'auto', 'changed_by' => $userId]) !== null) {
                    $n++;
                }
            }
            Session::setStatus((int) $session['id'], 'closed', $userId);
            return $n;
        });
    }

    /** Jalankan penutupan paling sering sekali per 5 menit (dipanggil dari API kiosk sebagai cadangan cron). */
    public static function maybeRun(): void
    {
        if (RateLimiter::tooMany('close-sessions', 1, 300)) {
            return;
        }
        RateLimiter::hit('close-sessions');
        try {
            self::closeExpired();
        } catch (\Throwable $e) {
            error_log('close-sessions gagal: ' . $e->getMessage());
        }
    }
}
