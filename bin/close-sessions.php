#!/usr/bin/env php
<?php
/**
 * Tutup sesi absensi yang sudah lewat waktunya: siswa yang belum scan ditandai Alpa.
 * Jalankan tiap 5 menit lewat cron:  *\/5 * * * *  php /path/absensi-sekolah/bin/close-sessions.php
 * (Tanpa cron, penutupan tetap berjalan sebagai cadangan saat kiosk memanggil /api/device/me.)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Hanya dari baris perintah.\n");
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/bin/close-sessions';
require dirname(__DIR__) . '/app/bootstrap.php';

$n = \App\Services\SessionCloser::closeExpired();
$purged = \App\Models\ScanLog::purge(90);
echo date('Y-m-d H:i:s') . " sesi ditutup: $n, log scan lama dihapus: $purged\n";
