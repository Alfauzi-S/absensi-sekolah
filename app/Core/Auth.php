<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\User;

/** Autentikasi berbasis sesi dan pemeriksaan hak akses per peran. */
final class Auth
{
    public const SUPER = 'super';
    public const KEPSEK = 'kepsek';
    public const TU = 'tu';
    public const GURU = 'guru';

    public const ROLES = [self::SUPER, self::KEPSEK, self::TU, self::GURU];

    public const ROLE_LABELS = [
        self::SUPER => 'Super Admin',
        self::KEPSEK => 'Kepala Sekolah',
        self::TU => 'TU / Staff',
        self::GURU => 'Guru',
    ];

    public const ROLE_HINTS = [
        self::SUPER => 'Semua akses: pengaturan sekolah, akun, perangkat, data master, audit log.',
        self::KEPSEK => 'Melihat dashboard dan rekap seluruh sekolah; tidak mengubah data.',
        self::TU => 'Mengelola siswa, kelas, ruangan, jadwal, kartu QR, izin, dan rekap semua kelas.',
        self::GURU => 'Melihat kelas yang diajar; wali kelas mengelola kelas perwaliannya.',
    ];

    private static ?array $user = null;
    private static bool $loaded = false;

    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;
        $id = (int) ($_SESSION['uid'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $user = User::find($id);
        if (!$user || $user['status'] !== 'Aktif') {
            unset($_SESSION['uid']);
            return null;
        }
        return self::$user = $user;
    }

    /** Dipakai pengujian: pasang pengguna tanpa sesi. */
    public static function impersonate(?array $user): void
    {
        self::$user = $user;
        self::$loaded = true;
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $user['id'];
        self::$user = $user;
        self::$loaded = true;
        User::touchLogin((int) $user['id']);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        self::$user = null;
    }

    public static function roleLabel(?string $role): string
    {
        return t(self::ROLE_LABELS[$role ?? ''] ?? (string) $role);
    }

    public static function isSuper(): bool
    {
        return (self::user()['role'] ?? '') === self::SUPER;
    }

    /** Hentikan request bila pengguna belum login atau perannya tidak sesuai. */
    public static function require(string ...$roles): array
    {
        $user = self::user();
        if (!$user) {
            if (is_api_request()) {
                json_out(['ok' => false, 'state' => 'auth', 'message' => t('Sesi berakhir. Silakan login kembali.')], 401);
            }
            $_SESSION['next'] = Router::currentPath();
            flash('info', 'Silakan login terlebih dahulu');
            redirect('masuk');
        }
        if ($roles && !in_array($user['role'], $roles, true)) {
            self::forbidden($user);
        }
        return $user;
    }

    public static function forbidden(array $user): never
    {
        if (is_api_request()) {
            json_out(['ok' => false, 'state' => 'forbidden', 'message' => t('Akun ini tidak memiliki akses.')], 403);
        }
        http_response_code(403);
        View::render('errors/forbidden', ['title' => t('Akses ditolak'), 'active' => '', 'user' => $user], 'app');
        exit;
    }
}
