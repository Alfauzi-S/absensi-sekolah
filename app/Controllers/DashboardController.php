<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database as DB;
use App\Core\Migrator;
use App\Models\AuditLog;
use App\Models\Master;
use App\Models\Schedule;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\Student;
use App\Services\Access;
use App\Services\Recap;

final class DashboardController extends Controller
{
    public function index(): void
    {
        $user = Auth::require();
        $year = \App\Models\AcademicYear::active();
        $semester = Semester::active();
        $data = ['title' => t('Dashboard'), 'active' => 'dashboard'];
        if ($user['role'] === Auth::GURU) {
            $today = (int) date('N');
            $classIds = Access::classIds($user) ?? [];
            $data += [
                'todaySchedules' => $semester ? Schedule::forTeacher((int) $semester['id'], (int) $user['id'], $today) : [],
                'weekCount' => $semester ? count(Schedule::forTeacher((int) $semester['id'], (int) $user['id'])) : 0,
                'homerooms' => $year ? SchoolClass::homeroomOf((int) $user['id'], (int) $year['id']) : [],
                'classes' => $year ? SchoolClass::forYear((int) $year['id'], $classIds) : [],
                'isHoliday' => Master::isHoliday(today()),
            ];
            $homeIds = Access::homeroomClassIds($user);
            $data['atts'] = array_map(fn (int $cid) => $this->attendance([$cid], $semester), $homeIds);
            $this->render('guru/dashboard', $data);
            return;
        }
        $counts = [
            'students' => Student::totals(),
            'classes' => $year ? (int) DB::value('SELECT COUNT(*) FROM classes WHERE academic_year_id = ?', [(int) $year['id']]) : 0,
            'unplaced' => $year ? (int) DB::value('SELECT COUNT(*) FROM students s WHERE s.status = ? AND NOT EXISTS (SELECT 1 FROM class_members m WHERE m.student_id = s.id AND m.academic_year_id = ?)', ['Aktif', (int) $year['id']]) : 0,
            'teachers' => (int) DB::value("SELECT COUNT(*) FROM users WHERE role = 'guru' AND status = 'Aktif'"),
            'rooms' => (int) DB::value('SELECT COUNT(*) FROM rooms'),
            'subjects' => (int) DB::value('SELECT COUNT(*) FROM subjects'),
            'periods' => (int) DB::value('SELECT COUNT(*) FROM periods'),
            'schedules' => $semester ? Schedule::countForSemester((int) $semester['id']) : 0,
            'classesNoHomeroom' => $year ? (int) DB::value('SELECT COUNT(*) FROM classes WHERE academic_year_id = ? AND homeroom_teacher_id IS NULL', [(int) $year['id']]) : 0,
            'classesNoSchedule' => $year && $semester ? (int) DB::value('SELECT COUNT(*) FROM classes c WHERE c.academic_year_id = ? AND NOT EXISTS (SELECT 1 FROM schedules h WHERE h.class_id = c.id AND h.semester_id = ?)', [(int) $year['id'], (int) $semester['id']]) : 0,
        ];
        $this->render('admin/dashboard', $data + [
            'counts' => $counts,
            'classes' => $year ? SchoolClass::forYear((int) $year['id']) : [],
            'pendingMigrations' => $user['role'] === Auth::SUPER ? Migrator::pending() : [],
            'recent' => $user['role'] === Auth::SUPER ? AuditLog::list('', '', 8) : [],
            'att' => $year ? $this->attendance(null, $semester) : null,
        ]);
    }

    /** Data ringkasan kehadiran untuk partial attendance_overview. @param int[]|null $classIds null = seluruh sekolah */
    private function attendance(?array $classIds, ?array $semester): array
    {
        $year = \App\Models\AcademicYear::active();
        $classes = $year ? SchoolClass::forYear((int) $year['id'], $classIds) : [];
        $from = $semester ? max($semester['start_date'], date('Y-m-01')) : date('Y-m-01');
        return [
            'today' => Recap::today($classIds, today()),
            'month' => Recap::school($classes, date('Y-m-01'), today()),
            'frequent' => Recap::frequentAbsent($classIds, $semester['start_date'] ?? $from, today()),
            'monthLabel' => month_id(substr(today(), 0, 7)), 'single' => $classIds !== null && count($classIds) === 1,
        ];
    }

    /** Jalankan migrasi aditif yang tertunda (khusus Super Admin). */
    public function migrate(): void
    {
        $user = Auth::require(Auth::SUPER);
        try {
            $ran = Migrator::run();
            AuditLog::add($user, 'system.migrate', null, 'migration', null, ['ran' => $ran]);
            flash('ok', 'Migrasi database selesai', $ran ? implode(', ', $ran) : t('Tidak ada migrasi baru.'));
        } catch (\Throwable $e) {
            error_log((string) $e);
            flash('bad', 'Migrasi gagal', t('Periksa log server. Data lama tidak diubah.'));
        }
        redirect('admin');
    }
}
