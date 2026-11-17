<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database as DB;

/** Riwayat perubahan status kehadiran (siapa, kapan, dari apa ke apa, alasan). */
final class StatusHistory
{
    public const SOURCES = [
        'scan' => 'Scan', 'manual' => 'Tandai hadir manual', 'auto' => 'Alpa otomatis', 'leave' => 'Izin/sakit dicatat',
        'leave_cancel' => 'Izin/sakit dibatalkan', 'correction' => 'Koreksi', 'reopen' => 'Sesi dibuka kembali',
    ];

    public static function add(?int $attendanceId, int $sessionId, int $studentId, ?string $old, ?string $new, string $source, ?string $reason = null, ?int $actorId = null, ?int $leaveId = null): void
    {
        DB::insert('status_history', [
            'attendance_id' => $attendanceId, 'session_id' => $sessionId, 'student_id' => $studentId, 'old_status' => $old, 'new_status' => $new,
            'source' => $source, 'reason' => $reason !== null && $reason !== '' ? mb_substr($reason, 0, 255) : null, 'leave_id' => $leaveId,
            'actor_id' => $actorId, 'created_at' => now(),
        ]);
    }

    public static function forSession(int $sessionId): array
    {
        return DB::all('SELECT h.*, st.full_name, u.name AS actor_name FROM status_history h JOIN students st ON st.id = h.student_id
            LEFT JOIN users u ON u.id = h.actor_id WHERE h.session_id = ? ORDER BY h.id DESC', [$sessionId]);
    }

    public static function forStudent(int $studentId, string $from, string $to, int $limit = 300): array
    {
        return DB::all('SELECT h.*, s.session_date, s.kind, s.starts_at, s.period_start, sb.name AS subject_name, u.name AS actor_name
            FROM status_history h JOIN sessions s ON s.id = h.session_id LEFT JOIN subjects sb ON sb.id = s.subject_id LEFT JOIN users u ON u.id = h.actor_id
            WHERE h.student_id = ? AND s.session_date BETWEEN ? AND ? ORDER BY h.id DESC LIMIT ' . $limit, [$studentId, $from, $to]);
    }
}
