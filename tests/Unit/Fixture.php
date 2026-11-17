<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database as DB;
use App\Models\AcademicYear;
use App\Models\Master;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\User;
use App\Services\Access;

/** Data dasar yang sama untuk semua tes: satu tahun ajaran aktif, jam, mapel, ruangan, guru, dan kelas. */
final class Fixture
{
    public static function reset(): void
    {
        foreach (['notifications', 'status_history', 'leaves', 'scan_metrics', 'scan_logs', 'attendances', 'sessions', 'devices', 'cards', 'rate_limits', 'schedules', 'class_members', 'classes', 'students', 'semesters', 'academic_years', 'periods', 'subjects', 'rooms', 'holidays', 'users', 'settings', 'audit_logs'] as $t) {
            DB::run('DELETE FROM ' . $t);
        }
        \App\Core\Cache::forgetPrefix('');
        Access::forget();
        AcademicYear::forget();
        Semester::forget();
        \App\Models\Setting::forget();
    }

    /** @return array{year:int, semester:int, teacher:int, teacher2:int, tu:int, kepsek:int, super:int, classA:int, classB:int, mtk:int, room:int} */
    public static function base(): array
    {
        self::reset();
        $year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-07-13', 'end_date' => '2027-06-27']);
        AcademicYear::activate($year);
        $sem = Semester::create($year, ['name' => 'Ganjil', 'start_date' => '2026-07-13', 'end_date' => '2026-12-20']);
        Semester::activate($sem);
        for ($n = 1; $n <= 4; $n++) {
            Master::create('jam', ['number' => (string) $n, 'start_time' => sprintf('%02d:00', 6 + $n), 'end_time' => sprintf('%02d:45', 6 + $n)]);
        }
        $mtk = Master::create('mapel', ['code' => 'MTK', 'name' => 'Matematika']);
        Master::create('mapel', ['code' => 'BIND', 'name' => 'Bahasa Indonesia']);
        $room = Master::create('ruangan', ['code' => 'R-01', 'name' => 'Ruang 1']);
        Master::create('ruangan', ['code' => 'R-02', 'name' => 'Ruang 2']);
        $mk = static fn (string $u, string $role, string $nip = '') => User::create(['name' => ucfirst($u), 'username' => $u, 'password' => 'rahasia12345', 'role' => $role, 'nip' => $nip]);
        $ids = ['year' => $year, 'semester' => $sem, 'teacher' => $mk('guru.a', 'guru', '1001'), 'teacher2' => $mk('guru.b', 'guru', '1002'), 'tu' => $mk('tu', 'tu'), 'kepsek' => $mk('kepsek', 'kepsek'), 'super' => $mk('super', 'super'), 'mtk' => $mtk, 'room' => $room];
        $ids['classA'] = SchoolClass::create(['academic_year_id' => $year, 'name' => 'X-A', 'grade' => 10, 'homeroom_teacher_id' => $ids['teacher'], 'room_id' => $room]);
        $ids['classB'] = SchoolClass::create(['academic_year_id' => $year, 'name' => 'X-B', 'grade' => 10, 'homeroom_teacher_id' => null, 'room_id' => null]);
        Access::forget();
        return $ids;
    }

    public static function user(int $id): array
    {
        return User::find($id);
    }
}
