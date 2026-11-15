<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database as DB;

final class AcademicYear
{
    private static ?array $active = null;
    private static bool $loaded = false;

    public static function all(): array
    {
        return DB::all('SELECT y.*, (SELECT COUNT(*) FROM classes c WHERE c.academic_year_id = y.id) AS class_count,
            (SELECT COUNT(*) FROM class_members m WHERE m.academic_year_id = y.id) AS student_count
            FROM academic_years y ORDER BY y.start_date DESC');
    }

    public static function find(int $id): ?array
    {
        return DB::one('SELECT * FROM academic_years WHERE id = ?', [$id]);
    }

    public static function active(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            self::$active = DB::tableExists('academic_years') ? DB::one('SELECT * FROM academic_years WHERE is_active = 1 ORDER BY start_date DESC LIMIT 1') : null;
        }
        return self::$active;
    }

    public static function create(array $d): int
    {
        return DB::insert('academic_years', [
            'name' => $d['name'], 'start_date' => $d['start_date'], 'end_date' => $d['end_date'],
            'is_active' => 0, 'created_at' => now(),
        ]);
    }

    public static function update(int $id, array $d): void
    {
        DB::update('academic_years', ['name' => $d['name'], 'start_date' => $d['start_date'], 'end_date' => $d['end_date']], 'id = ?', [$id]);
    }

    /** Hanya satu tahun ajaran aktif; mengaktifkan satu menonaktifkan yang lain. */
    public static function activate(int $id): void
    {
        DB::transaction(static function () use ($id): void {
            DB::run('UPDATE academic_years SET is_active = 0');
            DB::run('UPDATE academic_years SET is_active = 1 WHERE id = ?', [$id]);
            // Semester aktif harus milik tahun ajaran aktif
            DB::run('UPDATE semesters SET is_active = 0 WHERE academic_year_id <> ?', [$id]);
            if (!DB::value('SELECT id FROM semesters WHERE academic_year_id = ? AND is_active = 1', [$id])) {
                DB::run("UPDATE semesters SET is_active = 1 WHERE id = (SELECT id FROM (SELECT id FROM semesters WHERE academic_year_id = ? ORDER BY start_date ASC LIMIT 1) AS t)", [$id]);
            }
        });
        self::forget();
        Semester::forget();
    }

    public static function delete(int $id): void
    {
        DB::run('DELETE FROM academic_years WHERE id = ?', [$id]);
        self::forget();
    }

    public static function nameTaken(string $name, int $exceptId = 0): bool
    {
        return (bool) DB::value('SELECT id FROM academic_years WHERE name = ? AND id <> ?', [$name, $exceptId]);
    }

    public static function forget(): void
    {
        self::$loaded = false;
        self::$active = null;
    }
}
