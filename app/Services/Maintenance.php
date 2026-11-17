<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\ScanLog;
use App\Models\ScanMetric;
use App\Models\Setting;

/**
 * Pekerjaan berkala (cron tiap 5 menit atau bin/scheduler.php di Docker):
 * tutup sesi yang lewat waktu, kirim antrean WhatsApp, buang log scan > 90 hari, dan cadangan harian pada jam backup_time.
 * Setiap putaran mencatat detak (scheduler_last_at) yang dipantau halaman Kesehatan Sistem.
 */
final class Maintenance
{
    /** @return array<string, mixed> ringkasan putaran */
    public static function tick(?string $now = null, bool $withBackup = true): array
    {
        $now ??= now();
        $out = ['at' => $now];
        $out['closed'] = self::safely('sessions', static fn () => SessionCloser::closeExpired($now));
        $out['wa'] = self::safely('wa', static fn () => Notifier::run($now));
        $out['purged'] = self::safely('purge', static fn () => ScanLog::purge(90) + ScanMetric::purge(90));
        if ($withBackup && self::backupDue($now)) {
            $out['backup'] = self::safely('backup', static function (): array {
                $b = Backup::create();
                $b['pruned'] = Backup::prune();
                $b['path'] = basename($b['path']);
                return $b;
            });
        }
        Setting::set('scheduler_last_at', $now);
        Setting::set('scheduler_last_report', (string) json_encode($out, JSON_UNESCAPED_UNICODE));
        return $out;
    }

    /** Cadangan hari ini belum dibuat dan jam backup_time sudah lewat. */
    public static function backupDue(string $now): bool
    {
        $time = Setting::get('backup_time', '01:30');
        if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
            $time = '01:30';
        }
        $today = substr($now, 0, 10);
        if (substr($now, 11, 5) < $time) {
            return false;
        }
        $last = Setting::get('backup_last_at');
        return $last === '' || substr($last, 0, 10) < $today;
    }

    private static function safely(string $what, callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            error_log("maintenance $what gagal: " . $e->getMessage());
            return ['error' => mb_substr($e->getMessage(), 0, 200)];
        }
    }
}
