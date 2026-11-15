<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database as DB;

final class Schedule
{
    private const SELECT = 'SELECT h.*, c.name AS class_name, s.name AS subject_name, s.code AS subject_code,
        u.name AS teacher_name, r.name AS room_name, r.code AS room_code
        FROM schedules h
        JOIN classes c ON c.id = h.class_id
        JOIN subjects s ON s.id = h.subject_id
        LEFT JOIN users u ON u.id = h.teacher_id
        LEFT JOIN rooms r ON r.id = h.room_id';

    public static function find(int $id): ?array
    {
        return DB::one(self::SELECT . ' WHERE h.id = ?', [$id]);
    }

    public static function forClass(int $semesterId, int $classId): array
    {
        return DB::all(self::SELECT . ' WHERE h.semester_id = ? AND h.class_id = ? ORDER BY h.day_of_week, h.period_start', [$semesterId, $classId]);
    }

    public static function forTeacher(int $semesterId, int $teacherId, ?int $day = null): array
    {
        $w = $day ? ' AND h.day_of_week = ?' : '';
        $p = $day ? [$semesterId, $teacherId, $day] : [$semesterId, $teacherId];
        return DB::all(self::SELECT . ' WHERE h.semester_id = ? AND h.teacher_id = ?' . $w . ' ORDER BY h.day_of_week, h.period_start', $p);
    }

    public static function forRoom(int $semesterId, int $roomId, int $day): array
    {
        return DB::all(self::SELECT . ' WHERE h.semester_id = ? AND h.room_id = ? AND h.day_of_week = ? ORDER BY h.period_start', [$semesterId, $roomId, $day]);
    }

    public static function countForSemester(int $semesterId): int
    {
        return (int) DB::value('SELECT COUNT(*) FROM schedules WHERE semester_id = ?', [$semesterId]);
    }

    /**
     * Cari bentrok: kelas, guru, atau ruangan yang sudah terpakai pada hari dan rentang jam yang sama.
     * @return array<string,array> kunci: class|teacher|room → baris jadwal yang bentrok
     */
    public static function conflicts(array $d, int $exceptId = 0): array
    {
        $out = [];
        $base = ' WHERE h.semester_id = ? AND h.day_of_week = ? AND h.period_start <= ? AND h.period_end >= ? AND h.id <> ?';
        $params = [(int) $d['semester_id'], (int) $d['day_of_week'], (int) $d['period_end'], (int) $d['period_start'], $exceptId];
        $checks = ['class' => ['h.class_id', $d['class_id']], 'teacher' => ['h.teacher_id', $d['teacher_id'] ?? null], 'room' => ['h.room_id', $d['room_id'] ?? null]];
        foreach ($checks as $k => [$col, $val]) {
            if (!$val) {
                continue;
            }
            $row = DB::one(self::SELECT . $base . ' AND ' . $col . ' = ? LIMIT 1', array_merge($params, [(int) $val]));
            if ($row) {
                $out[$k] = $row;
            }
        }
        return $out;
    }

    public static function create(array $d): int
    {
        return DB::insert('schedules', self::row($d) + ['created_at' => now()]);
    }

    public static function update(int $id, array $d): void
    {
        DB::update('schedules', self::row($d), 'id = ?', [$id]);
    }

    private static function row(array $d): array
    {
        return [
            'semester_id' => (int) $d['semester_id'],
            'class_id' => (int) $d['class_id'],
            'subject_id' => (int) $d['subject_id'],
            'teacher_id' => !empty($d['teacher_id']) ? (int) $d['teacher_id'] : null,
            'room_id' => !empty($d['room_id']) ? (int) $d['room_id'] : null,
            'day_of_week' => (int) $d['day_of_week'],
            'period_start' => (int) $d['period_start'],
            'period_end' => (int) $d['period_end'],
        ];
    }

    public static function delete(int $id): void
    {
        DB::run('DELETE FROM schedules WHERE id = ?', [$id]);
    }

    public static function deleteForClass(int $semesterId, int $classId): int
    {
        return DB::run('DELETE FROM schedules WHERE semester_id = ? AND class_id = ?', [$semesterId, $classId])->rowCount();
    }

    /** Susun jadwal kelas menjadi grid hari × jam untuk tampilan. @return array<int, array<int, array|null>> */
    public static function grid(array $rows, array $periods, array $days): array
    {
        $grid = [];
        foreach ($days as $d) {
            foreach ($periods as $n => $p) {
                $grid[$d][$n] = null;
            }
        }
        foreach ($rows as $r) {
            $d = (int) $r['day_of_week'];
            for ($n = (int) $r['period_start']; $n <= (int) $r['period_end']; $n++) {
                $grid[$d][$n] = $r + ['first' => $n === (int) $r['period_start']];
            }
        }
        return $grid;
    }
}
