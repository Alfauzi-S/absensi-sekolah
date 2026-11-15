<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Models\AuditLog;
use App\Models\Master;
use App\Models\Schedule;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Services\Access;
use App\Services\Validator;

/** Kelas/rombel: daftar, detail (anggota + jadwal), kelola anggota. Guru hanya melihat kelasnya. */
final class ClassController extends Controller
{
    public function index(): void
    {
        $user = $this->allow('class:view');
        $year = $this->needYear();
        $this->render('admin/classes/index', [
            'title' => t('Kelas'), 'active' => 'classes',
            'classes' => SchoolClass::forYear((int) $year['id'], Access::classIds($user)),
            'canManage' => Access::can($user, 'master'),
        ]);
    }

    private function formView(array $d, array $errors, ?array $c = null): void
    {
        $this->render('admin/classes/form', [
            'title' => $c ? t('Ubah Kelas') : t('Kelas Baru'), 'active' => 'classes', 'back' => $c ? 'admin/kelas/' . $c['id'] : 'admin/kelas',
            'd' => $d, 'errors' => $errors, 'editing' => $c, 'teachers' => User::teachers(), 'rooms' => Master::all('ruangan'),
        ]);
    }

    public function create(): void
    {
        $this->allow('master');
        $year = $this->needYear();
        $this->formView(['academic_year_id' => $year['id'], 'name' => '', 'grade' => '10', 'homeroom_teacher_id' => '', 'room_id' => ''], []);
    }

    private function input(int $yearId): array
    {
        return [
            'academic_year_id' => $yearId,
            'name' => trim(input('name')),
            'grade' => trim(input('grade')),
            'homeroom_teacher_id' => input('homeroom_teacher_id'),
            'room_id' => input('room_id'),
        ];
    }

    public function store(): void
    {
        $user = $this->allow('master');
        $year = $this->needYear();
        $d = $this->input((int) $year['id']);
        $errors = Validator::schoolClass($d);
        if ($errors) {
            http_response_code(422);
            $this->formView($d, $errors);
            return;
        }
        $id = SchoolClass::create($d);
        Access::forget();
        AuditLog::add($user, 'class.create', $id, 'class', $id, ['name' => $d['name']]);
        flash('ok', 'Kelas dibuat', $d['name']);
        redirect('admin/kelas/' . $id);
    }

    private function findOr404(string $id): array
    {
        return SchoolClass::find((int) $id) ?? abort(404, 'Kelas tidak ditemukan');
    }

    public function show(string $id): void
    {
        $c = $this->findOr404($id);
        $user = $this->allow('class:view', (int) $c['id']);
        $sem = Semester::active();
        $periods = Master::periods();
        $rows = $sem ? Schedule::forClass((int) $sem['id'], (int) $c['id']) : [];
        $this->render('admin/classes/show', [
            'title' => t('Kelas :name', ['name' => $c['name']]), 'active' => 'classes', 'back' => 'admin/kelas', 'c' => $c,
            'members' => SchoolClass::members((int) $c['id']),
            'schedules' => $rows, 'periods' => $periods, 'days' => [1, 2, 3, 4, 5, 6],
            'grid' => Schedule::grid($rows, $periods, [1, 2, 3, 4, 5, 6]),
            'canManage' => Access::can($user, 'master'),
            'isHomeroom' => in_array((int) $c['id'], Access::homeroomClassIds($user), true),
            'unplaced' => Access::can($user, 'master') ? Student::search((int) $c['academic_year_id'], trim(query('cari')), -1, 'Aktif', null, 20, 0) : [],
            'q' => trim(query('cari')),
        ]);
    }

    public function edit(string $id): void
    {
        $this->allow('master');
        $c = $this->findOr404($id);
        $this->formView(['academic_year_id' => $c['academic_year_id'], 'name' => $c['name'], 'grade' => (string) $c['grade'],
            'homeroom_teacher_id' => (string) ($c['homeroom_teacher_id'] ?? ''), 'room_id' => (string) ($c['room_id'] ?? '')], [], $c);
    }

    public function update(string $id): void
    {
        $user = $this->allow('master');
        $c = $this->findOr404($id);
        $d = $this->input((int) $c['academic_year_id']);
        $errors = Validator::schoolClass($d, (int) $c['id']);
        if ($errors) {
            http_response_code(422);
            $this->formView($d, $errors, $c);
            return;
        }
        SchoolClass::update((int) $c['id'], $d);
        Access::forget();
        AuditLog::add($user, 'class.update', (int) $c['id'], 'class', $c['id'], ['name' => $d['name'], 'homeroom' => $d['homeroom_teacher_id']]);
        flash('ok', 'Kelas diperbarui', $d['name']);
        redirect('admin/kelas/' . $c['id']);
    }

    public function destroy(string $id): void
    {
        $user = $this->allow('master');
        $c = $this->findOr404($id);
        if ((int) $c['student_count'] > 0) {
            flash('bad', 'Kelas masih berisi siswa', t('Pindahkan atau keluarkan :n siswa terlebih dahulu.', ['n' => (int) $c['student_count']]));
            redirect('admin/kelas/' . $c['id']);
        }
        SchoolClass::delete((int) $c['id']);
        Access::forget();
        AuditLog::add($user, 'class.delete', (int) $c['id'], 'class', $c['id'], ['name' => $c['name']]);
        flash('ok', 'Kelas dihapus', $c['name']);
        redirect('admin/kelas');
    }

    public function addMember(string $id): void
    {
        $user = $this->allow('master');
        $c = $this->findOr404($id);
        $ids = array_filter(array_map('intval', (array) ($_POST['student_ids'] ?? [])));
        $added = 0;
        foreach ($ids as $sid) {
            $s = Student::find($sid);
            if (!$s) {
                continue;
            }
            $state = SchoolClass::addMember((int) $c['id'], $sid, (int) $c['academic_year_id']);
            if ($state !== 'exists') {
                $added++;
                AuditLog::add($user, 'class.member_add', (int) $c['id'], 'student', $sid, ['class' => $c['name'], 'nis' => $s['nis'], 'state' => $state]);
            }
        }
        flash($added ? 'ok' : 'info', t(':n siswa dimasukkan ke :class', ['n' => $added, 'class' => $c['name']]));
        redirect('admin/kelas/' . $c['id']);
    }

    public function removeMember(string $id, string $studentId): void
    {
        $user = $this->allow('master');
        $c = $this->findOr404($id);
        $s = Student::find((int) $studentId) ?? abort(404, 'Siswa tidak ditemukan');
        SchoolClass::removeMember((int) $c['id'], (int) $s['id']);
        AuditLog::add($user, 'class.member_remove', (int) $c['id'], 'student', $s['id'], ['class' => $c['name'], 'nis' => $s['nis']]);
        flash('ok', 'Siswa dikeluarkan dari kelas', $s['full_name']);
        redirect('admin/kelas/' . $c['id']);
    }
}
