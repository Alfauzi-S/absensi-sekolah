<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\Schedule;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\User;
use App\Services\Access;
use App\Services\Validator;

/** Kelola akun pengguna (khusus Super Admin): Kepala Sekolah, TU/Staff, Guru, dan Super Admin lain. */
final class AccountController extends Controller
{
    public function index(): void
    {
        $this->allow('accounts');
        $role = in_array(query('peran'), Auth::ROLES, true) ? query('peran') : '';
        $q = trim(query('q'));
        $this->render('admin/accounts/index', [
            'title' => t('Akun Pengguna'), 'active' => 'accounts', 'role' => $role, 'q' => $q,
            'users' => User::all($role ?: null, $q), 'counts' => User::countByRole(),
        ]);
    }

    private function formView(array $d, array $errors, ?array $u = null): void
    {
        $this->render('admin/accounts/form', [
            'title' => $u ? t('Ubah Akun') : t('Buat Akun'), 'active' => 'accounts', 'back' => $u ? 'admin/akun/' . $u['id'] : 'admin/akun',
            'd' => $d, 'errors' => $errors, 'editing' => $u,
        ]);
    }

    public function create(): void
    {
        $this->allow('accounts');
        $role = in_array(query('peran'), Auth::ROLES, true) ? query('peran') : Auth::GURU;
        $this->formView(['name' => '', 'username' => '', 'password' => User::randomPassword(10), 'role' => $role, 'nip' => '', 'phone' => '', 'status' => 'Aktif'], []);
    }

    private function input(): array
    {
        return [
            'name' => trim(input('name')),
            'username' => strtolower(trim(input('username'))),
            'password' => (string) ($_POST['password'] ?? ''),
            'role' => input('role', Auth::GURU),
            'nip' => trim(input('nip')),
            'phone' => Validator::normalizePhone(input('phone')),
            'status' => input('status', 'Aktif'),
        ];
    }

    public function store(): void
    {
        $super = $this->allow('accounts');
        $d = $this->input();
        if ($d['username'] === '' && $d['name'] !== '') {
            $d['username'] = User::suggestUsername($d['name']);
        }
        $errors = Validator::account($d, true);
        if ($errors) {
            http_response_code(422);
            $this->formView($d, $errors);
            return;
        }
        $id = User::create($d + ['must_change_password' => 1]);
        AuditLog::add($super, 'account.create', null, 'user', $id, ['username' => $d['username'], 'role' => $d['role']]);
        $_SESSION['new_credential'] = ['id' => $id, 'username' => $d['username'], 'password' => $d['password']];
        flash('ok', 'Akun dibuat', '@' . $d['username']);
        redirect('admin/akun/' . $id);
    }

    private function findOr404(string $id): array
    {
        $u = User::find((int) $id);
        if (!$u) {
            abort(404, 'Akun tidak ditemukan');
        }
        return $u;
    }

    public function show(string $id): void
    {
        $this->allow('accounts');
        $u = $this->findOr404($id);
        $cred = $_SESSION['new_credential'] ?? null;
        if ($cred && (int) $cred['id'] === (int) $u['id']) {
            unset($_SESSION['new_credential']);
        } else {
            $cred = null;
        }
        $year = AcademicYear::active();
        $sem = Semester::active();
        $this->render('admin/accounts/show', [
            'title' => t('Detail Akun'), 'active' => 'accounts', 'back' => 'admin/akun', 'u' => $u, 'cred' => $cred,
            'homerooms' => $u['role'] === Auth::GURU && $year ? SchoolClass::homeroomOf((int) $u['id'], (int) $year['id']) : [],
            'schedules' => $u['role'] === Auth::GURU && $sem ? Schedule::forTeacher((int) $sem['id'], (int) $u['id']) : [],
            'superCount' => User::countByRole()[Auth::SUPER],
        ]);
    }

    public function edit(string $id): void
    {
        $this->allow('accounts');
        $u = $this->findOr404($id);
        $this->formView(['name' => $u['name'], 'username' => $u['username'], 'password' => '', 'role' => $u['role'], 'nip' => (string) $u['nip'], 'phone' => (string) $u['phone'], 'status' => $u['status']], [], $u);
    }

    public function update(string $id): void
    {
        $super = $this->allow('accounts');
        $u = $this->findOr404($id);
        $d = $this->input();
        $d['username'] = $u['username'];
        $errors = Validator::account($d, false, (int) $u['id']);
        if ((int) $u['id'] === (int) $super['id'] && ($d['role'] !== Auth::SUPER || $d['status'] !== 'Aktif')) {
            $errors['role'] = t('Anda tidak bisa menurunkan peran atau menonaktifkan akun sendiri.');
        }
        if ($errors) {
            http_response_code(422);
            $this->formView($d, $errors, $u);
            return;
        }
        User::update((int) $u['id'], ['name' => $d['name'], 'role' => $d['role'], 'nip' => $d['nip'] ?: null, 'phone' => $d['phone'] ?: null, 'status' => $d['status']]);
        Access::forget();
        AuditLog::add($super, 'account.update', null, 'user', $u['id'], ['username' => $u['username'], 'role' => $d['role'], 'status' => $d['status']]);
        flash('ok', 'Akun diperbarui', '@' . $u['username']);
        redirect('admin/akun/' . $u['id']);
    }

    public function toggle(string $id): void
    {
        $super = $this->allow('accounts');
        $u = $this->findOr404($id);
        if ((int) $u['id'] === (int) $super['id']) {
            flash('bad', 'Tidak bisa menonaktifkan akun sendiri');
            redirect('admin/akun/' . $u['id']);
        }
        $new = $u['status'] === 'Aktif' ? 'Nonaktif' : 'Aktif';
        User::update((int) $u['id'], ['status' => $new]);
        AuditLog::add($super, 'account.status', null, 'user', $u['id'], ['username' => $u['username'], 'status' => $new]);
        flash('ok', $new === 'Aktif' ? 'Akun diaktifkan' : 'Akun dinonaktifkan', '@' . $u['username']);
        redirect('admin/akun/' . $u['id']);
    }

    public function reset(string $id): void
    {
        $super = $this->allow('accounts');
        $u = $this->findOr404($id);
        $password = User::randomPassword(10);
        User::setPassword((int) $u['id'], $password, true);
        AuditLog::add($super, 'account.reset_password', null, 'user', $u['id'], ['username' => $u['username']]);
        $_SESSION['new_credential'] = ['id' => (int) $u['id'], 'username' => $u['username'], 'password' => $password];
        flash('ok', 'Password sementara dibuat', t('Sampaikan ke pemilik akun; wajib diganti saat login.'));
        redirect('admin/akun/' . $u['id']);
    }
}
