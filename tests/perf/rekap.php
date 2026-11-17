#!/usr/bin/env php
<?php
/**
 * Uji ekspor rekap (PRD uji Fase 5): isi riwayat satu semester untuk seluruh sekolah (data demo: 30 kelas × 36 siswa,
 * Masuk + Pulang setiap hari sekolah), lalu ukur waktu membuat rekap semester semua kelas dalam satu file XLSX.
 * Pakai (setelah instalasi dengan data demo, config/config.php menunjuk ke database itu):
 *   php tests/perf/rekap.php [hari=100]
 * Target: selesai < 30 detik.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Hanya dari baris perintah.\n");
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/tests/perf/rekap';
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Database as DB;

$days = (int) ($argv[1] ?? 100);
$sem = \App\Models\Semester::active() ?? exit("Semester belum aktif\n");
$year = \App\Models\AcademicYear::active();
DB::run('DELETE FROM attendances');
DB::run('DELETE FROM leaves');
DB::run('DELETE FROM sessions');
mt_srand(7);
$t0 = microtime(true);
$rows = \App\Services\DemoSeeder::history($days, (int) $sem['id']);
$seed = microtime(true) - $t0;
$from = $sem['start_date'];
$to = max($from, min($sem['end_date'], today()));
printf("Data: %d baris kehadiran, %d sesi, rentang %s s.d. %s (diisi dalam %.1f detik)\n", $rows, (int) DB::value('SELECT COUNT(*) FROM sessions'), $from, $to, $seed);

$t0 = microtime(true);
$x = \App\Controllers\RecapController::buildSchoolSemester($year, $sem, $from, $to);
$file = sys_get_temp_dir() . '/rekap-semester-' . getmypid() . '.xlsx';
$x->save($file);
$secs = microtime(true) - $t0;
$zip = new ZipArchive();
$zip->open($file);
$sheets = $zip->numFiles - 5;
$zip->close();
printf("Ekspor rekap semester seluruh sekolah: %.2f detik, %d sheet, %.0f KB, memori puncak %.0f MB → %s\n", $secs, $sheets, filesize($file) / 1024, memory_get_peak_usage(true) / 1048576, $secs < 30 ? 'LULUS (< 30 detik)' : 'GAGAL');
@unlink($file);

$t0 = microtime(true);
$n = count(\App\Services\Recap::frequentAbsent(null, $from, $to));
printf("Daftar alpa ≥ 3 seluruh sekolah (dashboard): %.3f detik, %d siswa\n", microtime(true) - $t0, $n);
$t0 = microtime(true);
\App\Services\Recap::school(\App\Models\SchoolClass::forYear((int) $year['id']), date('Y-m-01'), today());
printf("Ringkasan sekolah bulan ini (dashboard): %.3f detik\n", microtime(true) - $t0);
exit($secs < 30 ? 0 : 1);
