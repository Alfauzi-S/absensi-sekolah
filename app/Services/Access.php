<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database as DB;
use App\Core\Router;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\Semester;

/**
 * Kebijakan akses (deny-by-default), dicek di server pada setiap request.
 *
 * Izin dan siapa yang memilikinya:
 * - master       : kelola data master (siswa, kelas, ruangan, mapel, jam, jadwal, libur, impor) → Super Admin, TU
 * - accounts     : kelola akun pengguna → Super Admin
 * - settings     : pengaturan sekolah → Super Admin
 * - audit        : audit log → Super Admin
 * - reports      : rekap seluruh sekolah → Super Admin, Kepsek, TU
 * - class:view   : melihat data satu kelas → Super Admin, Kepsek, TU; Guru hanya kelas yang diajar atau diwali
 * - class:manage : koreksi status dan izin satu kelas → Super Admin, TU, Wali Kelas kelas itu
 * - class:recap  : rekap kehadiran satu kelas → Super Admin, Kepsek, TU, Wali Kelas kelas itu (guru mapel tidak)
 */
final class Access
{
    public const PERMS = ['master', 'accounts', 'settings', 'audit', 'reports', 'class:view', 'class:manage', 'class:recap'];

    /** @var array<int, array{teach:int[], homeroom:int[]}> */
    private static array $cache = [];

    public static function forget(): void
    {
        self::$cache = [];
    }

    public static function can(array $user, string $perm, ?int $classId = null): bool
    {
        $role = $user['role'];
        if ($role === Auth::SUPER) {
            return true;
        }
        return match ($perm) {
            'master' => $role === Auth::TU,
            'reports' => in_array($role, [Auth::KEPSEK, Auth::TU], true),
            'accounts', 'settings', 'audit' => false,
            'class:view' => in_array($role, [Auth::KEPSEK, Auth::TU], true)
                || ($role === Auth::GURU && self::inList(self::classIds($user), $classId)),
            'class:recap' => in_array($role, [Auth::KEPSEK, Auth::TU], true)
                || ($role === Auth::GURU && self::inList(self::homeroomClassIds($user), $classId)),
            'class:manage' => $role === Auth::TU
                || ($role === Auth::GURU && self::inList(self::homeroomClassIds($user), $classId)),
            default => false,
        };
    }

    /** $classId null = "punya akses ke setidaknya satu kelas" (untuk menampilkan menu). */
    private static function inList(?array $ids, ?int $classId): bool
    {
        if ($ids === null) {
            return true;
        }
        return $classId === null ? $ids !== [] : in_array($classId, $ids, true);
    }

    /** Hentikan request dan catat di audit log bila tidak berhak. */
    public static function require(array $user, string $perm, ?int $classId = null): void
    {
        if (!self::can($user, $perm, $classId)) {
            self::deny($user, $perm, $classId);
        }
    }

    public static function deny(array $user, string $perm, ?int $classId = null): never
    {
        AuditLog::add($user, 'access.denied', $classId, 'perm', $perm, ['path' => Router::currentPath()], 'denied');
        Auth::forbidden($user);
    }

    /** @return int[]|null null = semua kelas (Super Admin, Kepsek, TU); Guru: kelas yang diajar atau diwali. */
    public static function classIds(array $user): ?array
    {
        if ($user['role'] !== Auth::GURU) {
            return null;
        }
        $d = self::load($user);
        return array_values(array_unique(array_merge($d['teach'], $d['homeroom'])));
    }

    /** @return int[] kelas perwalian guru ini pada tahun ajaran aktif (kosong untuk peran lain). */
    public static function homeroomClassIds(array $user): array
    {
        return $user['role'] === Auth::GURU ? self::load($user)['homeroom'] : [];
    }

    public static function isHomeroom(array $user): bool
    {
        return self::homeroomClassIds($user) !== [];
    }

    private static function load(array $user): array
    {
        $uid = (int) $user['id'];
        if (isset(self::$cache[$uid])) {
            return self::$cache[$uid];
        }
        $year = AcademicYear::active();
        $semester = Semester::active();
        $homeroom = $year
            ? array_map('intval', array_column(DB::all('SELECT id FROM classes WHERE academic_year_id = ? AND homeroom_teacher_id = ?', [(int) $year['id'], $uid]), 'id'))
            : [];
        $teach = $semester
            ? array_map('intval', array_column(DB::all('SELECT DISTINCT class_id FROM schedules WHERE semester_id = ? AND teacher_id = ?', [(int) $semester['id'], $uid]), 'class_id'))
            : [];
        return self::$cache[$uid] = ['teach' => $teach, 'homeroom' => $homeroom];
    }
}
