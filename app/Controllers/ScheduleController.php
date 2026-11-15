<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\AuditLog;
use App\Models\Master;
use App\Models\Schedule;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\User;
use App\Services\Access;
use App\Services\Validator;

/** Jadwal pelajaran per kelas pada semester aktif (TU/Super Admin); guru melihat jadwal mengajarnya. */
final class ScheduleController extends Controller
{
    private const DAYS = [1, 2, 3, 4, 5, 6];

    private function semester(): array
    {
        $s = Semester::active();
        if (!$s) {
            flash('warn', 'Belum ada semester aktif', t('Aktifkan semester di halaman Tahun Ajaran.'));
            redirect('admin/tahun-ajaran');
        }
        return $s;
    }

    public function index(): void
    {
        $user = $this->allow('class:view');
        $year = $this->needYear();
        $sem = $this->semester();
        $only = Access::classIds($user);
        $classes = SchoolClass::forYear((int) $year['id'], $only);
        $classId = (int) query('kelas') ?: (int) ($classes[0]['id'] ?? 0);
        if ($classId && $only !== null && !in_array($classId, $only, true)) {
            Access::deny($user, 'class:view', $classId);
        }
        $class = $classId ? SchoolClass::find($classId) : null;
        $periods = Master::periods();
        $rows = $class ? Schedule::forClass((int) $sem['id'], (int) $class['id']) : [];
        $this->render('admin/schedules/index', [
            'title' => t('Jadwal Pelajaran'), 'active' => 'schedules', 'classes' => $classes, 'class' => $class, 'semester' => $sem,
            'periods' => $periods, 'days' => self::DAYS, 'rows' => $rows, 'grid' => Schedule::grid($rows, $periods, self::DAYS),
            'canManage' => Access::can($user, 'master'),
            'mine' => $user['role'] === \App\Core\Auth::GURU ? Schedule::forTeacher((int) $sem['id'], (int) $user['id']) : [],
        ]);
    }

    private function formView(array $d, array $errors, ?array $row, array $class): void
    {
        $this->render('admin/schedules/form', [
            'title' => $row ? t('Ubah Jadwal') : t('Tambah Jadwal'), 'active' => 'schedules', 'back' => 'admin/jadwal?kelas=' . $class['id'],
            'd' => $d, 'errors' => $errors, 'editing' => $row, 'class' => $class,
            'periods' => Master::periods(), 'subjects' => Master::all('mapel'), 'teachers' => User::teachers(), 'rooms' => Master::all('ruangan'), 'days' => self::DAYS,
        ]);
    }

    private function input(array $sem, array $class): array
    {
        return [
            'semester_id' => (int) $sem['id'], 'class_id' => (int) $class['id'],
            'subject_id' => input('subject_id'), 'teacher_id' => input('teacher_id'), 'room_id' => input('room_id'),
            'day_of_week' => input('day_of_week'), 'period_start' => input('period_start'), 'period_end' => input('period_end'),
        ];
    }

    public function create(): void
    {
        $this->allow('master');
        $sem = $this->semester();
        $class = SchoolClass::find((int) query('kelas')) ?? abort(404, 'Kelas tidak ditemukan');
        $this->formView(['subject_id' => '', 'teacher_id' => '', 'room_id' => (string) ($class['room_id'] ?? ''), 'day_of_week' => query('hari', '1'),
            'period_start' => query('jam', '1'), 'period_end' => query('jam', '1')], [], null, $class);
    }

    public function store(): void
    {
        $user = $this->allow('master');
        $sem = $this->semester();
        $class = SchoolClass::find((int) input('class_id')) ?? abort(404, 'Kelas tidak ditemukan');
        $d = $this->input($sem, $class);
        $errors = Validator::schedule($d, Master::periods());
        $errors += $this->conflictErrors($d, 0);
        if ($errors) {
            http_response_code(422);
            $this->formView($d, $errors, null, $class);
            return;
        }
        $id = Schedule::create($d);
        Access::forget();
        AuditLog::add($user, 'schedule.create', (int) $class['id'], 'schedule', $id, ['class' => $class['name'], 'day' => $d['day_of_week'], 'periods' => $d['period_start'] . '-' . $d['period_end']]);
        flash('ok', 'Jadwal ditambahkan', $class['name'] . ' · ' . day_name((int) $d['day_of_week']));
        redirect('admin/jadwal', ['kelas' => $class['id']]);
    }

    private function conflictErrors(array $d, int $exceptId): array
    {
        $e = [];
        foreach (Schedule::conflicts($d, $exceptId) as $k => $row) {
            $msg = t('Bentrok dengan :cls (:subj, :day jam :a–:b).', ['cls' => $row['class_name'], 'subj' => $row['subject_name'], 'day' => day_name((int) $row['day_of_week']), 'a' => $row['period_start'], 'b' => $row['period_end']]);
            $e[['class' => 'period_start', 'teacher' => 'teacher_id', 'room' => 'room_id'][$k]] = $msg;
        }
        return $e;
    }

    public function edit(string $id): void
    {
        $this->allow('master');
        $row = Schedule::find((int) $id) ?? abort(404, 'Jadwal tidak ditemukan');
        $class = SchoolClass::find((int) $row['class_id']);
        $this->formView(array_map(static fn ($v) => (string) ($v ?? ''), array_intersect_key($row, array_flip(['subject_id', 'teacher_id', 'room_id', 'day_of_week', 'period_start', 'period_end']))), [], $row, $class);
    }

    public function update(string $id): void
    {
        $user = $this->allow('master');
        $row = Schedule::find((int) $id) ?? abort(404, 'Jadwal tidak ditemukan');
        $sem = Semester::find((int) $row['semester_id']);
        $class = SchoolClass::find((int) $row['class_id']);
        $d = $this->input($sem, $class);
        $errors = Validator::schedule($d, Master::periods());
        $errors += $this->conflictErrors($d, (int) $row['id']);
        if ($errors) {
            http_response_code(422);
            $this->formView($d, $errors, $row, $class);
            return;
        }
        Schedule::update((int) $row['id'], $d);
        Access::forget();
        AuditLog::add($user, 'schedule.update', (int) $class['id'], 'schedule', $row['id'], ['class' => $class['name'], 'day' => $d['day_of_week'], 'periods' => $d['period_start'] . '-' . $d['period_end']]);
        flash('ok', 'Jadwal diperbarui');
        redirect('admin/jadwal', ['kelas' => $class['id']]);
    }

    public function destroy(string $id): void
    {
        $user = $this->allow('master');
        $row = Schedule::find((int) $id) ?? abort(404, 'Jadwal tidak ditemukan');
        Schedule::delete((int) $row['id']);
        Access::forget();
        AuditLog::add($user, 'schedule.delete', (int) $row['class_id'], 'schedule', $row['id'], ['class' => $row['class_name'], 'subject' => $row['subject_name']]);
        flash('ok', 'Jadwal dihapus');
        redirect('admin/jadwal', ['kelas' => $row['class_id']]);
    }
}
