#!/usr/bin/env php
<?php
/**
 * Pekerjaan berkala: tutup sesi yang lewat waktunya (siswa belum scan = Alpa), kirim antrean WhatsApp,
 * buang log scan lama, dan buat cadangan harian (jam backup_time, bawaan 01:30).
 * Jalankan tiap 5 menit lewat cron:  *\/5 * * * *  php /path/absensi-sekolah/bin/close-sessions.php
 * Di Docker sudah dijalankan otomatis oleh service "scheduler" (bin/scheduler.php).
 * (Tanpa cron, penutupan dan pengiriman tetap berjalan sebagai cadangan saat kiosk memanggil /api/device/me.)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Hanya dari baris perintah.\n");
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/bin/close-sessions';
require dirname(__DIR__) . '/app/bootstrap.php';

$r = \App\Services\Maintenance::tick(null, !in_array('--no-backup', $argv, true));
$wa = is_array($r['wa']) ? $r['wa'] : [];
printf("%s sesi ditutup: %s, WA diantrekan: %d, terkirim: %d, dicoba ulang: %d, gagal: %d, dilewati: %d, log lama dihapus: %s%s\n",
    $r['at'], is_int($r['closed']) ? $r['closed'] : 'GAGAL', $wa['queued'] ?? 0, $wa['sent'] ?? 0, $wa['retry'] ?? 0, $wa['failed'] ?? 0, $wa['skipped'] ?? 0,
    is_int($r['purged']) ? $r['purged'] : 'GAGAL', isset($r['backup']) ? ', cadangan: ' . ($r['backup']['path'] ?? ('GAGAL ' . ($r['backup']['error'] ?? ''))) : '');
