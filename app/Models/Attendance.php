<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database as DB;

/** Kehadiran per (sesi, siswa). Penyisipan atomik: baris kedua untuk pasangan yang sama ditolak oleh UNIQUE. */
final class Attendance
{
    public const METHODS = ['scan' => 'Scan', 'manual' => 'Manual', 'auto' => 'Otomatis'];

    public static function find(int $sessionId, int $studentId): ?array
    {
        return DB::one('SELECT * FROM attendances WHERE session_id = ? AND student_id = ?', [$sessionId, $studentId]);
    }

    /**
     * Coba sisipkan; null bila sudah ada (duplikat). Tidak memakai transaksi agar cepat (satu INSERT).
     * @return int|null id baris baru
     */
    public static function tryInsert(array $d): ?int
    {
        try {
            return DB::insert('attendances', [
                'session_id' => (int) $d['session_id'],
                'student_id' => (int) $d['student_id'],
                'class_id' => (int) $d['class_id'],
                'status' => $d['status'],
                'scanned_at' => $d['scanned_at'] ?? null,
                'late_minutes' => (int) ($d['late_minutes'] ?? 0),
                'method' => $d['method'] ?? 'scan',
                'device_id' => $d['device_id'] ?? null,
                'scan_id' => $d['scan_id'] ?? null,
                'note' => isset($d['note']) && $d['note'] !== '' ? mb_substr($d['note'], 0, 200) : null,
                'changed_by' => $d['changed_by'] ?? null,
                'changed_at' => isset($d['changed_by']) ? now() : null,
                'leave_id' => $d['leave_id'] ?? null,
                'created_at' => now(),
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return null;
            }
            throw $e;
        }
    }

    public static function setStatus(int $id, string $status, ?int $userId, string $note = '', ?string $scannedAt = null, int $late = 0, string $method = 'manual'): void
    {
        DB::update('attendances', [
            'status' => $status, 'note' => $note !== '' ? mb_substr($note, 0, 200) : null, 'changed_by' => $userId, 'changed_at' => now(),
            'scanned_at' => $scannedAt, 'late_minutes' => $late, 'method' => $method,
        ], 'id = ?', [$id]);
    }

    /** Hitung status per sesi. @return array<string,int> */
    public static function countBySession(int $sessionId): array
    {
        $out = ['H' => 0, 'T' => 0, 'I' => 0, 'S' => 0, 'D' => 0, 'A' => 0];
        foreach (DB::all('SELECT status, COUNT(*) AS n FROM attendances WHERE session_id = ? GROUP BY status', [$sessionId]) as $r) {
            $out[$r['status']] = (int) $r['n'];
        }
        return $out;
    }

    /** Siswa anggota kelas yang belum punya baris kehadiran pada sesi ini. @return int[] */
    public static function missingStudentIds(array $session): array
    {
        return array_map('intval', array_column(DB::all('SELECT m.student_id FROM class_members m
            WHERE m.class_id = ? AND NOT EXISTS (SELECT 1 FROM attendances a WHERE a.session_id = ? AND a.student_id = m.student_id)',
            [(int) $session['class_id'], (int) $session['id']]), 'student_id'));
    }
}
