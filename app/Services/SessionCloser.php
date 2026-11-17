<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database as DB;
use App\Core\RateLimiter;
use App\Models\Attendance;
use App\Models\Leave;
use App\Models\Session;

/**
 * Menutup sesi: siswa yang belum tercatat menjadi Alpa (method auto), kecuali yang punya izin/sakit/dispensasi pada tanggal itu
 * (mendapat status izinnya). Sebelum menutup, sesi hari ini dibuat untuk setiap ruangan yang punya perangkat aktif, supaya
 * kelas yang scannernya mati seharian tetap punya sesi dan Alpa.
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
        self::materialize(substr($now, 0, 10), $now);
        $n = 0;
        foreach (Session::expired($limit) as $s) {
            self::close($s, null);
            $n++;
        }
        return $n;
    }

    /**
     * Buat sesi terjadwal hari ini (yang sudah dimulai) untuk ruangan yang punya perangkat aktif. Idempoten (slot_key unik).
     * @return int jumlah spesifikasi sesi yang diperiksa
     */
    public static function materialize(string $date, string $now): int
    {
        $n = 0;
        foreach (DB::all("SELECT DISTINCT room_id FROM devices WHERE status = 'active' AND room_id IS NOT NULL") as $r) {
            foreach (SessionResolver::daySpecs((int) $r['room_id'], $date) as $spec) {
                if ($spec['scan_from'] <= $now) {
                    Session::createOrGet($spec);
                    $n++;
                }
            }
        }
        return $n;
    }

    /** Tutup satu sesi (manual oleh guru/TU atau otomatis). @return int jumlah siswa yang ditandai Alpa */
    public static function close(array $session, ?int $userId): int
    {
        return DB::transaction(static function () use ($session, $userId): int {
            $n = 0;
            $leaves = Leave::activeForClass((int) $session['class_id'], (string) $session['session_date']);
            foreach (Attendance::missingStudentIds($session) as $sid) {
                $leave = $leaves[$sid] ?? null;
                if (Attendance::tryInsert(['session_id' => (int) $session['id'], 'student_id' => $sid, 'class_id' => (int) $session['class_id'],
                    'status' => $leave['kind'] ?? 'A', 'method' => 'auto', 'changed_by' => $userId, 'leave_id' => $leave['id'] ?? null]) !== null && !$leave) {
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
        // Antrean WhatsApp dikirim setelah jawaban ke kiosk selesai, supaya kiosk tidak ikut menunggu gateway.
        register_shutdown_function(static function (): void {
            if (function_exists('fastcgi_finish_request')) {
                @session_write_close();
                fastcgi_finish_request();
            }
            try {
                Notifier::run(null, 20);
            } catch (\Throwable $e) {
                error_log('notifikasi gagal: ' . $e->getMessage());
            }
        });
    }
}
