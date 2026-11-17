#!/usr/bin/env php
<?php
/**
 * Tutup sesi absensi yang sudah lewat waktunya: siswa yang belum scan ditandai Alpa.
 * Sekaligus mengantrekan dan mengirim notifikasi WhatsApp ke orang tua (bila dinyalakan di Pengaturan).
 * Jalankan tiap 5 menit lewat cron:  *\/5 * * * *  php /path/absensi-sekolah/bin/close-sessions.php
 * (Tanpa cron, penutupan dan pengiriman tetap berjalan sebagai cadangan saat kiosk memanggil /api/device/me.)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Hanya dari baris perintah.\n");
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/bin/close-sessions';
require dirname(__DIR__) . '/app/bootstrap.php';

$n = \App\Services\SessionCloser::closeExpired();
$wa = \App\Services\Notifier::run();
$purged = \App\Models\ScanLog::purge(90) + \App\Models\ScanMetric::purge(90);
printf("%s sesi ditutup: %d, WA diantrekan: %d, terkirim: %d, dicoba ulang: %d, gagal: %d, dilewati: %d, log scan lama dihapus: %d\n",
    date('Y-m-d H:i:s'), $n, $wa['queued'], $wa['sent'], $wa['retry'], $wa['failed'], $wa['skipped'], $purged);
