<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database as DB;

final class Student
{
    public const STATUSES = ['Aktif', 'Lulus', 'Pindah', 'Keluar'];
    public const GENDERS = ['L' => 'Laki-laki', 'P' => 'Perempuan'];

    public static function find(int $id): ?array
    {
        return DB::one('SELECT * FROM students WHERE id = ?', [$id]);
    }

    public static function findByNis(string $nis): ?array
    {
        return DB::one('SELECT * FROM students WHERE nis = ?', [$nis]);
    }

    /** Kelas siswa pada tahun ajaran tertentu (null bila belum ditempatkan). */
    public static function classIn(int $studentId, int $yearId): ?array
    {
        return DB::one('SELECT c.* FROM class_members m JOIN classes c ON c.id = m.class_id WHERE m.student_id = ? AND m.academic_year_id = ?', [$studentId, $yearId]);
    }

    /** Riwayat kelas dari tahun ke tahun. */
    public static function classHistory(int $studentId): array
    {
        return DB::all('SELECT c.id, c.name, y.name AS year_name, y.is_active FROM class_members m
            JOIN classes c ON c.id = m.class_id JOIN academic_years y ON y.id = c.academic_year_id
            WHERE m.student_id = ? ORDER BY y.start_date DESC', [$studentId]);
    }

    /**
     * Pencarian daftar siswa. $classId: 0 = semua, -1 = belum punya kelas tahun ini.
     * @param int[]|null $onlyClassIds pembatas akses (null = semua)
     */
    private static function where(int $yearId, string $q, int $classId, string $status, ?array $onlyClassIds): array
    {
        $w = ['1 = 1'];
        $p = [];
        if ($q !== '') {
            $w[] = '(s.full_name LIKE ? OR s.nis LIKE ? OR s.nisn LIKE ?)';
            $like = '%' . $q . '%';
            array_push($p, $like, $like, $like);
        }
        if ($status !== '' && $status !== 'all') {
            $w[] = 's.status = ?';
            $p[] = $status;
        }
        if ($classId > 0) {
            $w[] = 'm.class_id = ?';
            $p[] = $classId;
        } elseif ($classId === -1) {
            $w[] = 'm.id IS NULL';
        }
        if ($onlyClassIds !== null) {
            $w[] = $onlyClassIds ? 'm.class_id IN (' . implode(',', array_map('intval', $onlyClassIds)) . ')' : '1 = 0';
        }
        return [implode(' AND ', $w), $p];
    }

    private static function joinMembers(int $yearId): string
    {
        return ' LEFT JOIN class_members m ON m.student_id = s.id AND m.academic_year_id = ' . $yearId . ' LEFT JOIN classes c ON c.id = m.class_id ';
    }

    public static function search(int $yearId, string $q, int $classId, string $status, ?array $onlyClassIds, int $limit, int $offset): array
    {
        [$w, $p] = self::where($yearId, $q, $classId, $status, $onlyClassIds);
        return DB::all('SELECT s.*, c.name AS class_name, c.id AS class_id FROM students s' . self::joinMembers($yearId) .
            'WHERE ' . $w . ' ORDER BY c.name ASC, s.full_name ASC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset), $p);
    }

    public static function count(int $yearId, string $q, int $classId, string $status, ?array $onlyClassIds): int
    {
        [$w, $p] = self::where($yearId, $q, $classId, $status, $onlyClassIds);
        return (int) DB::value('SELECT COUNT(*) FROM students s' . self::joinMembers($yearId) . 'WHERE ' . $w, $p);
    }

    public static function create(array $d): int
    {
        return DB::insert('students', self::row($d) + ['created_at' => now(), 'updated_at' => now()]);
    }

    public static function update(int $id, array $d): void
    {
        DB::update('students', self::row($d) + ['updated_at' => now()], 'id = ?', [$id]);
    }

    private static function row(array $d): array
    {
        return [
            'nis' => trim($d['nis']),
            'nisn' => trim((string) ($d['nisn'] ?? '')) ?: null,
            'full_name' => trim($d['full_name']),
            'gender' => $d['gender'],
            'birth_date' => ($d['birth_date'] ?? '') !== '' ? $d['birth_date'] : null,
            'parent_name' => trim((string) ($d['parent_name'] ?? '')) ?: null,
            'parent_phone' => trim((string) ($d['parent_phone'] ?? '')) ?: null,
            'status' => $d['status'] ?? 'Aktif',
        ];
    }

    public static function setPhoto(int $id, ?string $path): void
    {
        DB::update('students', ['photo_path' => $path, 'updated_at' => now()], 'id = ?', [$id]);
    }

    public static function delete(int $id): void
    {
        DB::run('DELETE FROM students WHERE id = ?', [$id]);
    }

    public static function nisTaken(string $nis, int $exceptId = 0): bool
    {
        return (bool) DB::value('SELECT id FROM students WHERE nis = ? AND id <> ?', [$nis, $exceptId]);
    }

    public static function totals(): array
    {
        return [
            'total' => (int) DB::value('SELECT COUNT(*) FROM students'),
            'active' => (int) DB::value("SELECT COUNT(*) FROM students WHERE status = 'Aktif'"),
        ];
    }
}
