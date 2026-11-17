#!/usr/bin/env php
<?php
/**
 * Penjadwal untuk Docker (service "scheduler"): menjalankan pekerjaan bin/close-sessions.php tiap 5 menit tanpa cron.
 * Di server tanpa Docker cukup pakai cron (lihat bin/close-sessions.php).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Hanya dari baris perintah.\n");
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/bin/scheduler';
$every = 300;
$root = dirname(__DIR__);
echo date('Y-m-d H:i:s') . " penjadwal mulai, tiap {$every} detik\n";
while (true) {
    $start = time();
    // Proses terpisah per putaran: perubahan kode/konfigurasi langsung terpakai dan kebocoran memori tidak menumpuk.
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/close-sessions.php'), $code);
    if ($code !== 0) {
        echo date('Y-m-d H:i:s') . " putaran berakhir dengan kode $code\n";
    }
    sleep(max(5, $every - (time() - $start)));
}
