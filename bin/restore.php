#!/usr/bin/env php
<?php
/**
 * Pulihkan cadangan ke database yang dikonfigurasi di config/config.php (pindah server / pemulihan bencana).
 *   php bin/restore.php storage/backups/absensi-20261117-013000.zip          tampilkan isi cadangan
 *   php bin/restore.php storage/backups/absensi-20261117-013000.zip --yes    pulihkan (SEMUA data di database ini diganti)
 * Cadangan dari SQLite bisa dipulihkan ke MariaDB/MySQL dan sebaliknya.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Hanya dari baris perintah.\n");
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/bin/restore';
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\Backup;

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Pakai: php bin/restore.php <file-cadangan.zip> [--yes]\n");
    exit(2);
}
try {
    $m = Backup::manifest($file);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}
printf("Cadangan %s\n  dibuat %s dari %s (%s), %d baris di %d tabel, %d file\n", basename($file), $m['created_at'], $m['school'] ?: '-', $m['driver'], $m['rows'], count($m['tables']), $m['files']);
printf("Tujuan: %s%s\n", \App\Core\Database::driver(), \App\Core\Database::driver() === 'sqlite' ? ' ' . config('db.sqlite_path') : ' ' . config('db.host') . '/' . config('db.database'));
if (!in_array('--yes', $argv, true)) {
    echo "Belum dipulihkan. SEMUA data di database tujuan akan diganti; jalankan lagi dengan --yes bila yakin.\n";
    exit(1);
}
try {
    $r = Backup::restore($file, static function (string $s): void { echo '  ' . $s . "\n"; });
} catch (Throwable $e) {
    fwrite(STDERR, 'Pemulihan gagal: ' . $e->getMessage() . "\n");
    exit(1);
}
printf("Selesai: %d baris, %d file, %.1f detik.\n", $r['rows'], $r['files'], $r['seconds']);
if ($r['mismatch']) {
    echo "PERINGATAN jumlah baris tidak cocok:\n";
    foreach ($r['mismatch'] as $t => [$want, $got]) {
        echo "  $t: cadangan $want, sekarang $got\n";
    }
    exit(1);
}
echo "Jumlah baris semua tabel cocok dengan cadangan.\n";
