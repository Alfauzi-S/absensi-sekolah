<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\View;
use App\Models\AcademicYear;
use App\Models\Semester;
use App\Models\User;
use App\Services\Access;
use App\Services\Validator;

abstract class Controller
{
    protected function render(string $template, array $data = [], string $layout = 'app'): void
    {
        View::render($template, $data + ['user' => Auth::user(), 'activeYear' => AcademicYear::active(), 'activeSemester' => Semester::active()], $layout);
    }

    /** Pengguna yang login dan punya izin tertentu (dicatat di audit log bila ditolak). */
    protected function allow(string $perm, ?int $classId = null): array
    {
        $user = Auth::require();
        Access::require($user, $perm, $classId);
        return $user;
    }

    /** Tahun ajaran aktif wajib ada untuk halaman data master; bila belum, arahkan ke halaman tahun ajaran. */
    protected function needYear(): array
    {
        $year = AcademicYear::active();
        if (!$year) {
            flash('warn', 'Belum ada tahun ajaran aktif', t('Buat dan aktifkan tahun ajaran terlebih dahulu.'));
            redirect('admin/tahun-ajaran');
        }
        return $year;
    }

    protected function page(): int
    {
        return max(1, (int) query('hal', '1'));
    }

    /** Ganti password akun yang sedang login. */
    protected function changeOwnPassword(array $user, string $back): void
    {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        if (!password_verify($current, $user['password_hash'])) {
            flash('bad', 'Password saat ini salah');
            redirect($back);
        }
        if ($err = Validator::newPassword($new, $confirm)) {
            flash('bad', 'Password belum diganti', t($err));
            redirect($back);
        }
        User::setPassword((int) $user['id'], $new);
        session_regenerate_id(true);
        flash('ok', 'Password berhasil diperbarui');
        redirect($back);
    }
}
