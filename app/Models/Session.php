<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database as DB;

/**
 * Sesi absensi: wadah kehadiran untuk satu kelas pada satu rentang waktu.
 * Dibuat otomatis dari jadwal (kind jadwal) atau jam masuk/pulang (harian_*), atau manual (tambahan).
 */
final class Session
{
    public const KINDS = ['jadwal' => 'Jam pelajaran', 'tambahan' => 'Sesi tambahan', 'harian_masuk' => 'Masuk', 'harian_pulang' => 'Pulang'];
    public const STATUSES = ['open' => 'Berjalan', 'closed' => 'Ditutup', 'cancelled' => 'Jam kosong'];

    private const SELECT = 'SELECT s.*, c.name AS class_name, sb.name AS subject_name, sb.code AS subject_code, u.name AS teacher_name,
        r.code AS room_code, r.name AS room_name,
        (SELECT COUNT(*) FROM class_members m WHERE m.class_id = s.class_id) AS student_count,
        (SELECT COUNT(*) FROM attendances a WHERE a.session_id = s.id AND a.status IN (\'H\',\'T\')) AS present_count,
        (SELECT COUNT(*) FROM attendances a WHERE a.session_id = s.id AND a.status = \'T\') AS late_count,
        (SELECT COUNT(*) FROM attendances a WHERE a.session_id = s.id AND a.status = \'A\') AS absent_count,
        (SELECT COUNT(*) FROM attendances a WHERE a.session_id = s.id AND a.status IN (\'I\',\'S\',\'D\')) AS excused_count
        FROM sessions s
        JOIN classes c ON c.id = s.class_id
        LEFT JOIN subjects sb ON sb.id = s.subject_id
        LEFT JOIN users u ON u.id = s.teacher_id
        LEFT JOIN rooms r ON r.id = s.room_id';

    /** Versi ringan (tanpa hitungan kehadiran) untuk jalur scan. */
    private const LIGHT = 'SELECT s.*, c.name AS class_name, sb.name AS subject_name, sb.code AS subject_code
        FROM sessions s JOIN classes c ON c.id = s.class_id LEFT JOIN subjects sb ON sb.id = s.subject_id';

    public static function findLight(int $id): ?array
    {
        return DB::one(self::LIGHT . ' WHERE s.id = ?', [$id]);
    }

    public static function find(int $id): ?array
    {
        return DB::one(self::SELECT . ' WHERE s.id = ?', [$id]);
    }

    public static function findBySlot(string $slotKey): ?array
    {
        return DB::one(self::LIGHT . ' WHERE s.slot_key = ?', [$slotKey]);
    }

    /**
     * Buat sesi; bila slot_key sudah ada (dibuat scanner lain bersamaan), kembalikan yang sudah ada.
     * @param array{slot_key?:?string, schedule_id?:?int, semester_id?:?int, class_id:int, room_id?:?int, subject_id?:?int, teacher_id?:?int,
     *   session_date:string, period_start?:?int, period_end?:?int, scan_from:string, starts_at:string, late_after:string, ends_at:string,
     *   kind:string, note?:?string, opened_by?:?int} $d
     */
    public static function createOrGet(array $d): array
    {
        $slot = $d['slot_key'] ?? null;
        if ($slot !== null && ($existing = self::findBySlot($slot))) {
            return $existing;
        }
        try {
            $id = DB::insert('sessions', [
                'slot_key' => $slot,
                'schedule_id' => $d['schedule_id'] ?? null,
                'semester_id' => $d['semester_id'] ?? null,
                'class_id' => (int) $d['class_id'],
                'room_id' => $d['room_id'] ?? null,
                'subject_id' => $d['subject_id'] ?? null,
                'teacher_id' => $d['teacher_id'] ?? null,
                'session_date' => $d['session_date'],
                'period_start' => $d['period_start'] ?? null,
                'period_end' => $d['period_end'] ?? null,
                'scan_from' => $d['scan_from'],
                'starts_at' => $d['starts_at'],
                'late_after' => $d['late_after'],
                'ends_at' => $d['ends_at'],
                'kind' => $d['kind'],
                'status' => 'open',
                'note' => isset($d['note']) && $d['note'] !== '' ? mb_substr($d['note'], 0, 200) : null,
                'opened_by' => $d['opened_by'] ?? null,
                'created_at' => now(),
            ]);
        } catch (\PDOException $e) {
            if ($slot !== null && $e->getCode() === '23000' && ($existing = self::findBySlot($slot))) {
                return $existing;
            }
            throw $e;
        }
        \App\Core\Cache::forgetPrefix('sessions:');
        return self::findLight($id);
    }

    /** Sesi pada satu tanggal, dibatasi daftar kelas (null = semua). @param int[]|null $classIds */
    public static function onDate(string $date, ?array $classIds = null): array
    {
        if ($classIds !== null && $classIds === []) {
            return [];
        }
        $w = $classIds === null ? '' : ' AND s.class_id IN (' . implode(',', array_map('intval', $classIds)) . ')';
        return DB::all(self::SELECT . ' WHERE s.session_date = ?' . $w . ' ORDER BY s.starts_at ASC, c.name ASC', [$date]);
    }

    /** Sesi yang masih terbuka tetapi waktunya sudah lewat (untuk penutupan otomatis). */
    public static function expired(string $now, int $limit = 200): array
    {
        return DB::all(self::SELECT . " WHERE s.status = 'open' AND s.ends_at < ? ORDER BY s.ends_at ASC LIMIT " . $limit, [$now]);
    }

    public static function setStatus(int $id, string $status, ?int $userId = null, ?string $note = null): void
    {
        $data = ['status' => $status];
        if ($status !== 'open') {
            $data['closed_at'] = now();
        }
        if ($note !== null && $note !== '') {
            $data['note'] = mb_substr($note, 0, 200);
        }
        DB::update('sessions', $data, 'id = ?', [$id]);
        \App\Core\Cache::forgetPrefix('sessions:');
    }

    /** Daftar siswa kelas beserta kehadirannya pada sesi ini (siswa tanpa baris = belum scan). */
    public static function roster(array $session): array
    {
        return DB::all('SELECT st.id, st.nis, st.full_name, st.gender, st.photo_path, a.status, a.scanned_at, a.late_minutes, a.method, a.note
            FROM class_members m JOIN students st ON st.id = m.student_id
            LEFT JOIN attendances a ON a.session_id = ? AND a.student_id = st.id
            WHERE m.class_id = ? ORDER BY st.full_name ASC', [(int) $session['id'], (int) $session['class_id']]);
    }

    /** Id siswa yang sudah tercatat hadir/terlambat per sesi (untuk roster kiosk). @param int[] $sessionIds @return array<int,int[]> */
    public static function presentIds(array $sessionIds): array
    {
        $out = [];
        if (!$sessionIds) {
            return $out;
        }
        foreach (DB::all("SELECT session_id, student_id FROM attendances WHERE status IN ('H','T') AND session_id IN (" . implode(',', array_map('intval', $sessionIds)) . ')') as $r) {
            $out[(int) $r['session_id']][] = (int) $r['student_id'];
        }
        return $out;
    }

    public static function countOnDate(string $date): int
    {
        return (int) DB::value('SELECT COUNT(*) FROM sessions WHERE session_date = ?', [$date]);
    }
}
