<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database as DB;

/** Rombongan belajar (kelas) per tahun ajaran. Nama "SchoolClass" karena "class" kata kunci PHP. */
final class SchoolClass
{
    private const SELECT = 'SELECT c.*, y.name AS year_name, u.name AS homeroom_name, r.name AS room_name, r.code AS room_code,
        (SELECT COUNT(*) FROM class_members m WHERE m.class_id = c.id) AS student_count
        FROM classes c
        JOIN academic_years y ON y.id = c.academic_year_id
        LEFT JOIN users u ON u.id = c.homeroom_teacher_id
        LEFT JOIN rooms r ON r.id = c.room_id';

    public static function find(int $id): ?array
    {
        return DB::one(self::SELECT . ' WHERE c.id = ?', [$id]);
    }

    /** @param int[]|null $onlyIds null = semua */
    public static function forYear(int $yearId, ?array $onlyIds = null): array
    {
        if ($onlyIds !== null && $onlyIds === []) {
            return [];
        }
        $w = $onlyIds === null ? '' : ' AND c.id IN (' . implode(',', array_map('intval', $onlyIds)) . ')';
        return DB::all(self::SELECT . ' WHERE c.academic_year_id = ?' . $w . ' ORDER BY c.grade ASC, LENGTH(c.name) ASC, c.name ASC', [$yearId]);
    }

    public static function create(array $d): int
    {
        return DB::insert('classes', [
            'academic_year_id' => (int) $d['academic_year_id'],
            'name' => $d['name'],
            'grade' => (int) $d['grade'],
            'homeroom_teacher_id' => $d['homeroom_teacher_id'] ?: null,
            'room_id' => $d['room_id'] ?: null,
            'created_at' => now(),
        ]);
    }

    public static function update(int $id, array $d): void
    {
        DB::update('classes', [
            'name' => $d['name'],
            'grade' => (int) $d['grade'],
            'homeroom_teacher_id' => $d['homeroom_teacher_id'] ?: null,
            'room_id' => $d['room_id'] ?: null,
        ], 'id = ?', [$id]);
    }

    public static function delete(int $id): void
    {
        DB::run('DELETE FROM classes WHERE id = ?', [$id]);
    }

    public static function nameTaken(int $yearId, string $name, int $exceptId = 0): bool
    {
        return (bool) DB::value('SELECT id FROM classes WHERE academic_year_id = ? AND name = ? AND id <> ?', [$yearId, $name, $exceptId]);
    }

    public static function findByName(int $yearId, string $name): ?array
    {
        return DB::one('SELECT * FROM classes WHERE academic_year_id = ? AND name = ?', [$yearId, $name]);
    }

    /** Anggota kelas beserta data siswa. */
    public static function members(int $classId): array
    {
        return DB::all('SELECT s.*, m.id AS member_id FROM class_members m JOIN students s ON s.id = m.student_id WHERE m.class_id = ? ORDER BY s.full_name ASC', [$classId]);
    }

    /** Tambah siswa ke kelas; bila siswa sudah punya kelas di tahun ajaran yang sama, dipindahkan. */
    public static function addMember(int $classId, int $studentId, int $yearId): string
    {
        $existing = DB::one('SELECT id, class_id FROM class_members WHERE student_id = ? AND academic_year_id = ?', [$studentId, $yearId]);
        if ($existing) {
            if ((int) $existing['class_id'] === $classId) {
                return 'exists';
            }
            DB::update('class_members', ['class_id' => $classId], 'id = ?', [(int) $existing['id']]);
            return 'moved';
        }
        DB::insert('class_members', ['class_id' => $classId, 'student_id' => $studentId, 'academic_year_id' => $yearId, 'created_at' => now()]);
        return 'added';
    }

    public static function removeMember(int $classId, int $studentId): void
    {
        DB::run('DELETE FROM class_members WHERE class_id = ? AND student_id = ?', [$classId, $studentId]);
    }

    /** Kelas yang membuat seorang guru menjadi wali kelas pada tahun ajaran tertentu. */
    public static function homeroomOf(int $teacherId, int $yearId): array
    {
        return DB::all(self::SELECT . ' WHERE c.academic_year_id = ? AND c.homeroom_teacher_id = ? ORDER BY c.name', [$yearId, $teacherId]);
    }
}
