<?php

use App\Models\User;
use App\Services\AttendanceService;
use App\Services\LegacyImporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('absensi:tandai-alpa {--date= : Tanggal (Y-m-d), bawaan hari ini}', function (AttendanceService $svc) {
    $date = CarbonImmutable::parse($this->option('date') ?: 'today');
    $n = $svc->markAbsent($date);
    $this->info("{$date->toDateString()}: {$n} siswa diberi Alpa/izin.");
})->purpose('Beri Alpa kepada siswa yang belum scan setelah jam masuk berakhir');

Artisan::command('absensi:impor-lama {--auto : Hanya jalan bila data lama ada dan aplikasi baru masih kosong}', function () {
    $importer = new LegacyImporter;
    if (! $importer->available()) {
        $this->line('Data aplikasi lama tidak ditemukan, impor dilewati.');

        return 0;
    }
    if ($importer->alreadyImported()) {
        $this->line('Aplikasi baru sudah berisi data, impor dilewati.');

        return 0;
    }
    $this->info('Memindahkan data dari aplikasi lama...');
    $stats = $importer->run(fn ($m) => $this->line('  '.$m));
    $this->info('Selesai: '.collect($stats)->map(fn ($n, $k) => "{$k} {$n}")->implode(', '));

    return 0;
})->purpose('Pindahkan data dari aplikasi absensi versi lama (sekali saja)');

Artisan::command('absensi:admin {username} {--name=Administrator} {--password=}', function () {
    $password = $this->option('password') ?: $this->secret('Password baru');
    $user = User::updateOrCreate(
        ['username' => $this->argument('username')],
        ['name' => $this->option('name'), 'password' => $password, 'role' => 'admin', 'is_active' => true],
    );
    $this->info("Akun admin {$user->username} siap dipakai.");
})->purpose('Buat atau reset akun admin');

Schedule::call(fn () => app(AttendanceService::class)->markAbsent(CarbonImmutable::today()))
    ->name('tandai-alpa')->everyFiveMinutes()->withoutOverlapping();
