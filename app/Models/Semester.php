<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database as DB;

final class Semester
{
    public const NAMES = ['Ganjil', 'Genap'];

    private static ?array $active = null;
    private static bool $loaded = false;

    public static function forYear(int $yearId): array
    {
        return DB::all('SELECT s.*, (SELECT COUNT(*) FROM schedules h WHERE h.semester_id = s.id) AS schedule_count
            FROM semesters s WHERE s.academic_year_id = ? ORDER BY s.start_date ASC', [$yearId]);
    }

    public static function find(int $id): ?array
    {
        return DB::one('SELECT s.*, y.name AS year_name FROM semesters s JOIN academic_years y ON y.id = s.academic_year_id WHERE s.id = ?', [$id]);
    }

    public static function active(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            self::$active = DB::tableExists('semesters')
                ? DB::one('SELECT s.*, y.name AS year_name FROM semesters s JOIN academic_years y ON y.id = s.academic_year_id WHERE s.is_active = 1 AND y.is_active = 1 LIMIT 1')
                : null;
        }
        return self::$active;
    }

    public static function create(int $yearId, array $d): int
    {
        return DB::insert('semesters', [
            'academic_year_id' => $yearId, 'name' => $d['name'], 'start_date' => $d['start_date'], 'end_date' => $d['end_date'], 'is_active' => 0,
        ]);
    }

    public static function update(int $id, array $d): void
    {
        DB::update('semesters', ['start_date' => $d['start_date'], 'end_date' => $d['end_date']], 'id = ?', [$id]);
    }

    public static function activate(int $id): void
    {
        $s = self::find($id);
        if (!$s) {
            return;
        }
        DB::transaction(static function () use ($s): void {
            DB::run('UPDATE semesters SET is_active = 0');
            DB::run('UPDATE semesters SET is_active = 1 WHERE id = ?', [(int) $s['id']]);
            DB::run('UPDATE academic_years SET is_active = 0');
            DB::run('UPDATE academic_years SET is_active = 1 WHERE id = ?', [(int) $s['academic_year_id']]);
        });
        self::forget();
        AcademicYear::forget();
    }

    public static function exists(int $yearId, string $name): bool
    {
        return (bool) DB::value('SELECT id FROM semesters WHERE academic_year_id = ? AND name = ?', [$yearId, $name]);
    }

    public static function delete(int $id): void
    {
        DB::run('DELETE FROM semesters WHERE id = ?', [$id]);
        self::forget();
    }

    public static function forget(): void
    {
        self::$loaded = false;
        self::$active = null;
    }
}
