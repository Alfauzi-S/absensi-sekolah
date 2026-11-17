<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\AuditLog;
use App\Models\Card;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\Access;
use App\Services\Exporter;
use App\Services\Photo;
use App\Services\Validator;

final class StudentController extends Controller
{
    private const PER_PAGE = 40;

    public function index(): void
    {
        $user = $this->allow('class:view');
        $year = $this->needYear();
        $q = trim(query('q'));
        $classId = query('kelas') === 'tanpa' ? -1 : (int) query('kelas');
        $status = in_array(query('st'), Student::STATUSES, true) ? query('st') : (query('st') === 'all' ? 'all' : 'Aktif');
        $only = Access::classIds($user);
        if ($only !== null && $classId > 0 && !in_array($classId, $only, true)) {
            Access::deny($user, 'class:view', $classId);
        }
        $page = $this->page();
        $total = Student::count((int) $year['id'], $q, $classId, $status, $only);
        $this->render('admin/students/index', [
            'title' => t('Siswa'), 'active' => 'students', 'q' => $q, 'classId' => $classId, 'status' => $status,
            'classes' => SchoolClass::forYear((int) $year['id'], $only),
            'students' => Student::search((int) $year['id'], $q, $classId, $status, $only, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'page' => $page, 'pages' => (int) ceil($total / self::PER_PAGE), 'total' => $total,
            'canManage' => Access::can($user, 'master'),
        ]);
    }

    private function formView(array $d, array $errors, ?array $s = null): void
    {
        $year = $this->needYear();
        $this->render('admin/students/form', [
            'title' => $s ? t('Ubah Data Siswa') : t('Siswa Baru'), 'active' => 'students', 'back' => $s ? 'admin/siswa/' . $s['id'] : 'admin/siswa',
            'd' => $d, 'errors' => $errors, 'editing' => $s, 'classes' => SchoolClass::forYear((int) $year['id']),
        ]);
    }

    private const EMPTY = ['nis' => '', 'nisn' => '', 'full_name' => '', 'gender' => '', 'birth_date' => '', 'parent_name' => '', 'parent_phone' => '', 'status' => 'Aktif', 'class_id' => ''];

    public function create(): void
    {
        $this->allow('master');
        $this->formView(self::EMPTY + ['class_id' => query('kelas')], []);
    }

    private function input(): array
    {
        $d = [];
        foreach (array_keys(self::EMPTY) as $k) {
            $d[$k] = trim(input($k));
        }
        $d['parent_phone'] = Validator::normalizePhone($d['parent_phone']);
        return $d;
    }

    public function store(): void
    {
        $user = $this->allow('master');
        $year = $this->needYear();
        $d = $this->input();
        $errors = Validator::student($d);
        if ($d['class_id'] !== '' && !SchoolClass::find((int) $d['class_id'])) {
            $errors['class_id'] = t('Kelas tidak ditemukan.');
        }
        if ($errors) {
            http_response_code(422);
            $this->formView($d, $errors);
            return;
        }
        $id = Student::create($d);
        if ($d['class_id'] !== '') {
            SchoolClass::addMember((int) $d['class_id'], $id, (int) $year['id']);
        }
        AuditLog::add($user, 'student.create', $d['class_id'] !== '' ? (int) $d['class_id'] : null, 'student', $id, ['nis' => $d['nis'], 'name' => $d['full_name']]);
        $this->savePhoto($user, $id);
        flash('ok', 'Siswa ditambahkan', $d['full_name']);
        redirect('admin/siswa/' . $id);
    }

    /** Simpan foto bila ada unggahan di form; kesalahan ditampilkan sebagai peringatan, data siswa tetap tersimpan. */
    private function savePhoto(array $user, int $studentId): void
    {
        $f = $_FILES['photo'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return;
        }
        $err = Photo::store($studentId, $f);
        if ($err !== null) {
            flash('warn', 'Foto tidak tersimpan', $err);
            return;
        }
        Student::setPhoto($studentId, 'photos/' . $studentId . '.jpg');
        AuditLog::add($user, 'student.photo', null, 'student', $studentId, ['action' => 'upload']);
    }

    public function deletePhoto(string $id): void
    {
        $user = $this->allow('master');
        $s = $this->findOr404($id);
        Photo::delete((int) $s['id']);
        Student::setPhoto((int) $s['id'], null);
        AuditLog::add($user, 'student.photo', null, 'student', $s['id'], ['action' => 'delete']);
        flash('ok', 'Foto dihapus');
        redirect('admin/siswa/' . $s['id']);
    }

    private function findOr404(string $id): array
    {
        return Student::find((int) $id) ?? abort(404, 'Siswa tidak ditemukan');
    }

    public function show(string $id): void
    {
        $s = $this->findOr404($id);
        $user = \App\Core\Auth::require();
        $year = $this->needYear();
        $class = Student::classIn((int) $s['id'], (int) $year['id']);
        // Guru hanya boleh melihat siswa di kelasnya; siswa tanpa kelas hanya untuk TU/Super Admin/Kepsek
        Access::require($user, 'class:view', $class ? (int) $class['id'] : 0);
        $this->render('admin/students/show', [
            'title' => t('Detail Siswa'), 'active' => 'students', 'back' => 'admin/siswa', 's' => $s, 'class' => $class,
            'history' => Student::classHistory((int) $s['id']),
            'canManage' => Access::can($user, 'master'),
            'card' => Card::activeFor((int) $s['id']), 'cards' => Card::history((int) $s['id']),
            'hasPhoto' => Photo::exists((int) $s['id']),
            'portalCode' => $this->takePortalCode((int) $s['id']),
        ]);
    }

    /** Kode portal yang baru dibuat (ditampilkan sekali, lalu dibuang dari sesi). */
    private function takePortalCode(int $studentId): ?string
    {
        $once = $_SESSION['portal_code_once'] ?? null;
        unset($_SESSION['portal_code_once']);
        return is_array($once) && (int) ($once['id'] ?? 0) === $studentId ? (string) $once['code'] : null;
    }

    public function edit(string $id): void
    {
        $this->allow('master');
        $s = $this->findOr404($id);
        $year = $this->needYear();
        $class = Student::classIn((int) $s['id'], (int) $year['id']);
        $d = array_intersect_key($s, self::EMPTY);
        $d = array_map(static fn ($v) => (string) ($v ?? ''), $d) + ['class_id' => $class ? (string) $class['id'] : ''];
        $this->formView($d, [], $s);
    }

    public function update(string $id): void
    {
        $user = $this->allow('master');
        $s = $this->findOr404($id);
        $year = $this->needYear();
        $d = $this->input();
        $errors = Validator::student($d, (int) $s['id']);
        if ($d['class_id'] !== '' && !SchoolClass::find((int) $d['class_id'])) {
            $errors['class_id'] = t('Kelas tidak ditemukan.');
        }
        if ($errors) {
            http_response_code(422);
            $this->formView($d, $errors, $s);
            return;
        }
        Student::update((int) $s['id'], $d);
        $current = Student::classIn((int) $s['id'], (int) $year['id']);
        if ($d['class_id'] === '' && $current) {
            SchoolClass::removeMember((int) $current['id'], (int) $s['id']);
        } elseif ($d['class_id'] !== '') {
            SchoolClass::addMember((int) $d['class_id'], (int) $s['id'], (int) $year['id']);
        }
        AuditLog::add($user, 'student.update', $d['class_id'] !== '' ? (int) $d['class_id'] : null, 'student', $s['id'], ['nis' => $d['nis'], 'name' => $d['full_name'], 'status' => $d['status']]);
        $this->savePhoto($user, (int) $s['id']);
        flash('ok', 'Data siswa diperbarui', $d['full_name']);
        redirect('admin/siswa/' . $s['id']);
    }

    public function destroy(string $id): void
    {
        $user = $this->allow('master');
        $s = $this->findOr404($id);
        Student::delete((int) $s['id']);
        Photo::delete((int) $s['id']);
        AuditLog::add($user, 'student.delete', null, 'student', $s['id'], ['nis' => $s['nis'], 'name' => $s['full_name']]);
        flash('ok', 'Siswa dihapus', $s['full_name']);
        redirect('admin/siswa');
    }

    /** Ekspor daftar siswa (sesuai filter) ke XLSX/CSV; formatnya sama dengan template impor. */
    public function export(): void
    {
        $user = $this->allow('reports');
        $year = $this->needYear();
        $classId = query('kelas') === 'tanpa' ? -1 : (int) query('kelas');
        $status = in_array(query('st'), Student::STATUSES, true) ? query('st') : 'all';
        $rows = [];
        foreach (Student::search((int) $year['id'], trim(query('q')), $classId, $status, null, 100000, 0) as $s) {
            $rows[] = [$s['nis'], (string) $s['nisn'], $s['full_name'], $s['gender'], (string) ($s['class_name'] ?? ''), (string) $s['birth_date'], (string) $s['parent_name'], (string) $s['parent_phone'], $s['status']];
        }
        $headers = ['nis', 'nisn', 'nama', 'jk', 'kelas', 'tanggal_lahir', 'nama_ortu', 'hp_ortu', 'status'];
        AuditLog::add($user, 'report.export', null, 'students', null, ['rows' => count($rows)]);
        $name = 'siswa-' . str_replace('/', '-', $year['name']) . '-' . date('Ymd');
        if (query('format') === 'csv' || !Exporter::xlsxAvailable()) {
            Exporter::csv($name . '.csv', $headers, $rows);
        }
        Exporter::xlsx($name . '.xlsx', $headers, $rows, 'Siswa');
    }
}
