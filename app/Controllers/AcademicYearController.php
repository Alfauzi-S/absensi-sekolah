<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\Semester;
use App\Services\Access;
use App\Services\Validator;

/** Tahun ajaran dan semester (Super Admin, TU). */
final class AcademicYearController extends Controller
{
    public function index(): void
    {
        $this->allow('master');
        $years = AcademicYear::all();
        $semesters = [];
        foreach ($years as $y) {
            $semesters[(int) $y['id']] = Semester::forYear((int) $y['id']);
        }
        $this->render('admin/years/index', [
            'title' => t('Tahun Ajaran'), 'active' => 'years', 'years' => $years, 'semesters' => $semesters,
            'errors' => $_SESSION['year_errors'] ?? [], 'old' => $_SESSION['year_old'] ?? [],
        ]);
        unset($_SESSION['year_errors'], $_SESSION['year_old']);
    }

    private function back(array $errors, array $old): never
    {
        $_SESSION['year_errors'] = $errors;
        $_SESSION['year_old'] = $old;
        flash('bad', 'Data belum disimpan', t('Periksa kolom yang ditandai.'));
        redirect('admin/tahun-ajaran');
    }

    public function store(): void
    {
        $user = $this->allow('master');
        $d = ['name' => trim(input('name')), 'start_date' => input('start_date'), 'end_date' => input('end_date')];
        $errors = Validator::academicYear($d);
        if ($errors) {
            $this->back($errors, $d);
        }
        $id = AcademicYear::create($d);
        // Dua semester bawaan: Ganjil (paruh pertama) dan Genap (paruh kedua)
        $mid = date('Y-m-d', (int) ((strtotime($d['start_date']) + strtotime($d['end_date'])) / 2));
        Semester::create($id, ['name' => 'Ganjil', 'start_date' => $d['start_date'], 'end_date' => $mid]);
        Semester::create($id, ['name' => 'Genap', 'start_date' => date('Y-m-d', strtotime($mid . ' +1 day')), 'end_date' => $d['end_date']]);
        AuditLog::add($user, 'year.create', null, 'academic_year', $id, ['name' => $d['name']]);
        if (!AcademicYear::active()) {
            AcademicYear::activate($id);
        }
        flash('ok', 'Tahun ajaran dibuat', $d['name']);
        redirect('admin/tahun-ajaran');
    }

    public function update(string $id): void
    {
        $user = $this->allow('master');
        $y = AcademicYear::find((int) $id) ?? abort(404, 'Tahun ajaran tidak ditemukan');
        $d = ['name' => trim(input('name')), 'start_date' => input('start_date'), 'end_date' => input('end_date')];
        $errors = Validator::academicYear($d, (int) $y['id']);
        if ($errors) {
            $this->back($errors, $d + ['id' => $y['id']]);
        }
        AcademicYear::update((int) $y['id'], $d);
        AuditLog::add($user, 'year.update', null, 'academic_year', $y['id'], $d);
        flash('ok', 'Tahun ajaran diperbarui', $d['name']);
        redirect('admin/tahun-ajaran');
    }

    public function activate(string $id): void
    {
        $user = $this->allow('master');
        $y = AcademicYear::find((int) $id) ?? abort(404, 'Tahun ajaran tidak ditemukan');
        AcademicYear::activate((int) $y['id']);
        Access::forget();
        AuditLog::add($user, 'year.activate', null, 'academic_year', $y['id'], ['name' => $y['name']]);
        flash('ok', 'Tahun ajaran aktif diubah', $y['name']);
        redirect('admin/tahun-ajaran');
    }

    public function destroy(string $id): void
    {
        $user = $this->allow('master');
        $y = AcademicYear::find((int) $id) ?? abort(404, 'Tahun ajaran tidak ditemukan');
        if ((int) $y['is_active'] === 1) {
            flash('bad', 'Tahun ajaran aktif tidak bisa dihapus', t('Aktifkan tahun ajaran lain terlebih dahulu.'));
            redirect('admin/tahun-ajaran');
        }
        $classes = (int) \App\Core\Database::value('SELECT COUNT(*) FROM classes WHERE academic_year_id = ?', [(int) $y['id']]);
        if ($classes > 0) {
            flash('bad', 'Tahun ajaran masih punya kelas', t('Hapus :n kelas di tahun ajaran ini terlebih dahulu.', ['n' => $classes]));
            redirect('admin/tahun-ajaran');
        }
        AcademicYear::delete((int) $y['id']);
        AuditLog::add($user, 'year.delete', null, 'academic_year', $y['id'], ['name' => $y['name']]);
        flash('ok', 'Tahun ajaran dihapus', $y['name']);
        redirect('admin/tahun-ajaran');
    }

    public function semesterStore(string $id): void
    {
        $user = $this->allow('master');
        $y = AcademicYear::find((int) $id) ?? abort(404, 'Tahun ajaran tidak ditemukan');
        $d = ['name' => input('name'), 'start_date' => input('start_date'), 'end_date' => input('end_date')];
        $errors = Validator::semester($d);
        if (!$errors && Semester::exists((int) $y['id'], $d['name'])) {
            $errors['name'] = t('Semester :name sudah ada di tahun ajaran ini.', ['name' => $d['name']]);
        }
        if ($errors) {
            $this->back($errors, $d + ['semester_year' => $y['id']]);
        }
        $sid = Semester::create((int) $y['id'], $d);
        AuditLog::add($user, 'semester.create', null, 'semester', $sid, ['year' => $y['name'], 'name' => $d['name']]);
        flash('ok', 'Semester ditambahkan', $y['name'] . ' · ' . $d['name']);
        redirect('admin/tahun-ajaran');
    }

    public function semesterUpdate(string $id): void
    {
        $user = $this->allow('master');
        $s = Semester::find((int) $id) ?? abort(404, 'Semester tidak ditemukan');
        $d = ['name' => $s['name'], 'start_date' => input('start_date'), 'end_date' => input('end_date')];
        $errors = Validator::semester($d);
        if ($errors) {
            $this->back($errors, $d + ['semester_id' => $s['id']]);
        }
        Semester::update((int) $s['id'], $d);
        flash('ok', 'Semester diperbarui', $s['year_name'] . ' · ' . $s['name']);
        redirect('admin/tahun-ajaran');
    }

    public function semesterActivate(string $id): void
    {
        $user = $this->allow('master');
        $s = Semester::find((int) $id) ?? abort(404, 'Semester tidak ditemukan');
        Semester::activate((int) $s['id']);
        Access::forget();
        AuditLog::add($user, 'semester.activate', null, 'semester', $s['id'], ['year' => $s['year_name'], 'name' => $s['name']]);
        flash('ok', 'Semester aktif diubah', $s['year_name'] . ' · ' . $s['name']);
        redirect('admin/tahun-ajaran');
    }

    public function semesterDestroy(string $id): void
    {
        $user = $this->allow('master');
        $s = Semester::find((int) $id) ?? abort(404, 'Semester tidak ditemukan');
        if ((int) $s['is_active'] === 1) {
            flash('bad', 'Semester aktif tidak bisa dihapus');
            redirect('admin/tahun-ajaran');
        }
        $n = \App\Models\Schedule::countForSemester((int) $s['id']);
        if ($n > 0) {
            flash('bad', 'Semester masih punya jadwal', t(':n jadwal harus dihapus terlebih dahulu.', ['n' => $n]));
            redirect('admin/tahun-ajaran');
        }
        Semester::delete((int) $s['id']);
        AuditLog::add($user, 'semester.delete', null, 'semester', $s['id'], ['year' => $s['year_name'], 'name' => $s['name']]);
        flash('ok', 'Semester dihapus');
        redirect('admin/tahun-ajaran');
    }
}
