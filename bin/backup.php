#!/usr/bin/env php
<?php
/**
 * Cadangan database + foto + lampiran ke storage/backups/absensi-YYYYMMDD-HHMMSS.zip.
 *   php bin/backup.php            buat cadangan sekarang (lalu sisakan 14 terbaru)
 *   php bin/backup.php --list     daftar cadangan
 * Cadangan harian otomatis dibuat oleh bin/close-sessions.php / service scheduler pada jam backup_time.
 * Salin folder storage/backups ke tempat lain (disk lain, Google Drive, dsb.) secara berkala.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Hanya dari baris perintah.\n");
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/bin/backup';
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\Backup;

if (in_array('--list', $argv, true)) {
    foreach (Backup::list() as $b) {
        printf("%s  %8.1f MB  %s\n", date('Y-m-d H:i', $b['time']), $b['size'] / 1048576, $b['path']);
    }
    exit(0);
}
$r = Backup::create(in_array('--manual', $argv, true) ? 'manual' : '');
$pruned = Backup::prune();
printf("Cadangan dibuat: %s\n  %d tabel, %d baris, %d file, %.1f MB, %.1f detik; cadangan lama dihapus: %d\n",
    $r['path'], $r['tables'], $r['rows'], $r['files'], $r['size'] / 1048576, $r['seconds'], $pruned);
