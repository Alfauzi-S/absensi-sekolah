<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database as DB;

final class User
{
    public static function find(int $id): ?array
    {
        return DB::one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public static function findByUsername(string $username): ?array
    {
        return DB::one('SELECT * FROM users WHERE username = ?', [strtolower($username)]);
    }

    public static function hasSuper(): bool
    {
        return (bool) DB::value("SELECT id FROM users WHERE role = 'super' LIMIT 1");
    }

    /** Semua akun (kecuali, bila diminta, difilter per peran), dengan jumlah kelas perwalian dan jadwal mengajar aktif. */
    public static function all(?string $role = null, string $q = ''): array
    {
        $year = AcademicYear::active();
        $sem = Semester::active();
        $w = ['1 = 1'];
        $p = [];
        if ($role !== null && $role !== '') {
            $w[] = 'u.role = ?';
            $p[] = $role;
        }
        if ($q !== '') {
            $w[] = '(u.name LIKE ? OR u.username LIKE ? OR u.nip LIKE ?)';
            array_push($p, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
        }
        return DB::all(
            'SELECT u.*,
                (SELECT COUNT(*) FROM classes c WHERE c.homeroom_teacher_id = u.id AND c.academic_year_id = ' . ($year ? (int) $year['id'] : 0) . ') AS homeroom_count,
                (SELECT COUNT(*) FROM schedules h WHERE h.teacher_id = u.id AND h.semester_id = ' . ($sem ? (int) $sem['id'] : 0) . ') AS schedule_count
             FROM users u WHERE ' . implode(' AND ', $w) . ' ORDER BY u.status ASC, u.role ASC, u.name ASC',
            $p
        );
    }

    /** Daftar guru aktif untuk pilihan wali kelas / pengampu. */
    public static function teachers(): array
    {
        return DB::all("SELECT id, name, nip FROM users WHERE role = 'guru' AND status = 'Aktif' ORDER BY name ASC");
    }

    public static function findTeacherByNip(string $nip): ?array
    {
        return DB::one("SELECT * FROM users WHERE role = 'guru' AND nip = ?", [$nip]);
    }

    public static function countByRole(): array
    {
        $out = array_fill_keys(Auth::ROLES, 0);
        foreach (DB::all('SELECT role, COUNT(*) AS n FROM users GROUP BY role') as $r) {
            $out[$r['role']] = (int) $r['n'];
        }
        return $out;
    }

    public static function create(array $data): int
    {
        return DB::insert('users', [
            'name' => $data['name'],
            'username' => strtolower($data['username']),
            'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
            'role' => $data['role'],
            'nip' => ($data['nip'] ?? '') !== '' ? $data['nip'] : null,
            'phone' => ($data['phone'] ?? '') !== '' ? $data['phone'] : null,
            'status' => $data['status'] ?? 'Aktif',
            'must_change_password' => (int) ($data['must_change_password'] ?? 0),
            'created_at' => now(),
        ]);
    }

    public static function update(int $id, array $data): void
    {
        DB::update('users', $data, 'id = ?', [$id]);
    }

    public static function setPassword(int $id, string $password, bool $mustChange = false): void
    {
        DB::update('users', [
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'must_change_password' => $mustChange ? 1 : 0,
        ], 'id = ?', [$id]);
    }

    public static function touchLogin(int $id): void
    {
        DB::update('users', ['last_login_at' => now()], 'id = ?', [$id]);
    }

    public static function randomPassword(int $length = 10): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $out;
    }

    /** Username unik dari nama: "Sari Dewi" → sari.dewi, sari.dewi2, … */
    public static function suggestUsername(string $name): string
    {
        $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '.', $name) ?? '', '.'));
        $base = mb_substr($base ?: 'pengguna', 0, 30);
        $u = $base;
        $i = 2;
        while (self::findByUsername($u)) {
            $u = $base . $i++;
        }
        return $u;
    }
}
