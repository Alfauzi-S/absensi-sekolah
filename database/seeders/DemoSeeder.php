<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Leave;
use App\Models\Period;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\LessonService;
use App\Services\SchoolCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/** Data contoh untuk mencoba aplikasi: php artisan db:seed --class=DemoSeeder (password semua akun: password123). */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        mt_srand(7);
        Setting::put('school_name', 'SMA Negeri 1 Contoh');
        Setting::put('school_address', 'Jl. Pendidikan No. 1');
        Setting::put('headmaster_name', 'Dra. Hj. Siti Aminah, M.Pd.');

        $admin = User::firstOrCreate(['username' => 'admin'], ['name' => 'Administrator', 'password' => 'password123', 'role' => 'admin']);
        User::firstOrCreate(['username' => 'kepsek'], ['name' => 'Siti Aminah', 'password' => 'password123', 'role' => 'kepsek']);
        User::firstOrCreate(['username' => 'tu'], ['name' => 'Rina Marlina', 'password' => 'password123', 'role' => 'tu']);
        $teachers = collect(['Adi Pratama', 'Budi Santoso', 'Citra Lestari', 'Dewi Anggraini'])->map(fn ($n, $i) => User::firstOrCreate(
            ['username' => 'guru0'.($i + 1)], ['name' => $n, 'password' => 'password123', 'role' => 'guru']));

        foreach (['Matematika', 'Bahasa Indonesia', 'Bahasa Inggris', 'Fisika', 'Biologi', 'Sejarah'] as $s) {
            Subject::firstOrCreate(['name' => $s]);
        }
        foreach ([['07:15', '08:00'], ['08:00', '08:45'], ['08:45', '09:30'], ['09:45', '10:30'], ['10:30', '11:15'], ['11:15', '12:00']] as $i => [$a, $b]) {
            Period::firstOrCreate(['number' => $i + 1], ['start_time' => $a, 'end_time' => $b]);
        }

        $first = ['Ahmad', 'Siti', 'Budi', 'Dewi', 'Rizky', 'Nur', 'Fajar', 'Putri', 'Agus', 'Aisyah', 'Dimas', 'Intan', 'Yoga', 'Rahma', 'Bayu', 'Salsa', 'Hendra', 'Nabila', 'Iqbal', 'Zahra', 'Fikri', 'Ayu', 'Rafi', 'Lestari'];
        $last = ['Saputra', 'Rahmawati', 'Hidayat', 'Kusuma', 'Pratama', 'Wulandari', 'Nugroho', 'Permata', 'Setiawan', 'Maharani', 'Firmansyah', 'Anggraini'];
        $nis = 2026001;
        foreach (['X-1', 'X-2', 'XI-1', 'XII-1'] as $ci => $name) {
            $class = SchoolClass::firstOrCreate(['name' => $name], ['grade' => [10, 10, 11, 12][$ci], 'homeroom_teacher_id' => $teachers[$ci]->id]);
            for ($i = 0; $i < 24; $i++) {
                Student::firstOrCreate(['nis' => (string) $nis++], [
                    'name' => $first[($i + $ci * 5) % 24].' '.$last[($i * 7 + $ci) % 12],
                    'gender' => $i % 2 ? 'P' : 'L', 'class_id' => $class->id, 'status' => 'aktif',
                ]);
            }
        }

        // Riwayat 30 hari terakhir.
        $svc = app(AttendanceService::class);
        $students = Student::all();
        $today = CarbonImmutable::today();
        for ($d = $today->subDays(30); $d->lessThanOrEqualTo($today); $d = $d->addDay()) {
            if (! SchoolCalendar::isSchoolDay($d)) {
                continue;
            }
            foreach ($students as $s) {
                $r = mt_rand(1, 100);
                if ($r <= 4 && $d->lessThan($today)) {
                    continue; // akan menjadi Alpa
                }
                if ($r <= 8) {
                    $leave = Leave::create(['student_id' => $s->id, 'kind' => ['S', 'I', 'S', 'D'][$r % 4], 'date_from' => $d->toDateString(), 'date_to' => $d->toDateString(),
                        'reason' => ['Demam', 'Acara keluarga', 'Sakit perut', 'Lomba OSN'][$r % 4], 'created_by' => $admin->id]);
                    $svc->applyLeave($leave, $admin->id);

                    continue;
                }
                $minute = $r > 85 ? mt_rand(11, 45) : mt_rand(-50, 9);
                $at = $d->setTime(7, 0)->addMinutes($minute)->addSeconds(mt_rand(0, 59));
                if ($d->isToday() && $at->isFuture()) {
                    continue;
                }
                $svc->scan($s->qr_code, $at);
                if (! $d->isToday()) {
                    $svc->scan($s->qr_code, $d->setTime(14, mt_rand(0, 40)));
                }
            }
            $svc->markAbsent($d, $d->setTime(15, 0));
        }

        // Beberapa sesi pelajaran hari ini.
        $lessons = app(LessonService::class);
        foreach (SchoolClass::all() as $i => $c) {
            if (SchoolCalendar::isSchoolDay($today)) {
                $lesson = $lessons->create(['class_id' => $c->id, 'subject_id' => Subject::skip($i)->value('id'), 'date' => $today->toDateString(), 'period_start' => 1, 'period_end' => 2], $teachers[$i]->id);
                $lesson->attendances()->inRandomOrder()->limit(2)->update(['status' => 'A']);
            }
        }
    }
}
