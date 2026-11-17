<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database as DB;
use App\Models\Attendance;
use App\Models\Leave;
use App\Models\Session;
use App\Models\StatusHistory;

/**
 * Aturan prioritas status bila bentrok (PRD bagian 9):
 *   koreksi manual guru/wali kelas > Izin/Sakit/Dispensasi > scan (Hadir/Terlambat) > Alpa otomatis.
 * Fakta scan (jam scan, menit terlambat) selalu disimpan walau statusnya kalah prioritas, sehingga bila izin
 * dibatalkan status kembali ke hasil scan. Setiap perubahan status dicatat di status_history.
 */
final class AttendanceRules
{
    /** Status akhir satu baris kehadiran menurut prioritas. */
    public static function statusFor(array $row, array $session): string
    {
        if (!empty($row['corrected'])) {
            return (string) $row['status'];
        }
        if (!empty($row['leave_id']) && ($leave = DB::one("SELECT kind FROM leaves WHERE id = ? AND status = 'active'", [(int) $row['leave_id']]))) {
            return $leave['kind'];
        }
        if (!empty($row['scanned_at'])) {
            return ScanService::judge($session, (string) $row['scanned_at'])[0];
        }
        return 'A';
    }

    /** Catat izin baru dan terapkan ke sesi yang sudah ada dalam rentangnya. @return int baris yang berubah */
    public static function applyLeave(int $leaveId, ?int $actorId): int
    {
        $leave = Leave::find($leaveId) ?? throw new \InvalidArgumentException('Izin tidak ditemukan');
        $n = 0;
        $rows = DB::all('SELECT a.*, s.session_date FROM attendances a JOIN sessions s ON s.id = a.session_id
            WHERE a.student_id = ? AND s.session_date BETWEEN ? AND ? AND a.corrected = 0', [(int) $leave['student_id'], $leave['date_from'], $leave['date_to']]);
        foreach ($rows as $r) {
            if ($r['status'] === $leave['kind'] && (int) $r['leave_id'] === $leaveId) {
                continue;
            }
            DB::update('attendances', ['status' => $leave['kind'], 'leave_id' => $leaveId, 'changed_by' => $actorId, 'changed_at' => now()], 'id = ?', [(int) $r['id']]);
            StatusHistory::add((int) $r['id'], (int) $r['session_id'], (int) $r['student_id'], $r['status'], $leave['kind'], 'leave', $leave['reason'], $actorId, $leaveId);
            $n++;
        }
        // Sesi yang sudah ditutup tetapi siswa belum punya baris (mis. baru masuk kelas): beri status izin juga
        foreach (DB::all("SELECT s.id FROM sessions s WHERE s.class_id = ? AND s.status = 'closed' AND s.session_date BETWEEN ? AND ?
            AND NOT EXISTS (SELECT 1 FROM attendances a WHERE a.session_id = s.id AND a.student_id = ?)",
            [(int) $leave['class_id'], $leave['date_from'], $leave['date_to'], (int) $leave['student_id']]) as $s) {
            $id = Attendance::tryInsert(['session_id' => (int) $s['id'], 'student_id' => (int) $leave['student_id'], 'class_id' => (int) $leave['class_id'],
                'status' => $leave['kind'], 'method' => 'auto', 'leave_id' => $leaveId, 'changed_by' => $actorId]);
            if ($id !== null) {
                StatusHistory::add($id, (int) $s['id'], (int) $leave['student_id'], null, $leave['kind'], 'leave', $leave['reason'], $actorId, $leaveId);
                $n++;
            }
        }
        return $n;
    }

    /** Batalkan izin: baris yang memakainya kembali ke izin lain yang masih berlaku, hasil scan, atau Alpa (sesi sudah ditutup). */
    public static function cancelLeave(int $leaveId, ?int $actorId, string $reason): int
    {
        $leave = Leave::find($leaveId) ?? throw new \InvalidArgumentException('Izin tidak ditemukan');
        Leave::markCancelled($leaveId, $actorId, $reason);
        $n = 0;
        foreach (DB::all('SELECT a.*, s.session_date FROM attendances a JOIN sessions s ON s.id = a.session_id WHERE a.leave_id = ? AND a.corrected = 0', [$leaveId]) as $r) {
            $session = Session::findLight((int) $r['session_id']);
            $other = Leave::activeFor((int) $r['student_id'], $r['session_date']);
            if ($other) {
                $new = $other['kind'];
                DB::update('attendances', ['status' => $new, 'leave_id' => (int) $other['id'], 'changed_by' => $actorId, 'changed_at' => now()], 'id = ?', [(int) $r['id']]);
            } elseif (!empty($r['scanned_at'])) {
                [$new, $late] = ScanService::judge($session, (string) $r['scanned_at']);
                DB::update('attendances', ['status' => $new, 'late_minutes' => $late, 'leave_id' => null, 'changed_by' => $actorId, 'changed_at' => now()], 'id = ?', [(int) $r['id']]);
            } elseif ($session['status'] === 'closed') {
                $new = 'A';
                DB::update('attendances', ['status' => 'A', 'method' => 'auto', 'leave_id' => null, 'changed_by' => $actorId, 'changed_at' => now()], 'id = ?', [(int) $r['id']]);
            } else {
                $new = null; // sesi masih berjalan dan belum scan: kembali "belum"
                DB::run('DELETE FROM attendances WHERE id = ?', [(int) $r['id']]);
            }
            StatusHistory::add((int) $r['id'], (int) $r['session_id'], (int) $r['student_id'], $r['status'], $new, 'leave_cancel', $reason, $actorId, $leaveId);
            $n++;
        }
        return $n;
    }

    /**
     * Koreksi status oleh guru (sesinya), wali kelas (kelasnya), TU, atau Super Admin. Alasan wajib.
     * Koreksi mengalahkan izin dan scan, dan tidak diubah lagi oleh izin yang dicatat kemudian.
     * @return array{old:?string, new:string}
     */
    public static function correct(array $session, int $studentId, string $status, string $reason, int $actorId): array
    {
        if (!in_array($status, ['H', 'T', 'I', 'S', 'D', 'A'], true)) {
            throw new \InvalidArgumentException('Status tidak dikenal');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new \InvalidArgumentException(t('Alasan koreksi wajib diisi.'));
        }
        return DB::transaction(static function () use ($session, $studentId, $status, $reason, $actorId): array {
            $row = Attendance::find((int) $session['id'], $studentId);
            $late = 0;
            if ($status === 'T' && $row && !empty($row['scanned_at'])) {
                $late = max(1, (int) floor((strtotime($row['scanned_at']) - strtotime($session['starts_at'])) / 60));
            }
            if ($row) {
                DB::update('attendances', ['status' => $status, 'late_minutes' => $status === 'T' ? $late : 0, 'corrected' => 1, 'note' => mb_substr($reason, 0, 200),
                    'changed_by' => $actorId, 'changed_at' => now()], 'id = ?', [(int) $row['id']]);
                $id = (int) $row['id'];
            } else {
                $id = (int) Attendance::tryInsert(['session_id' => (int) $session['id'], 'student_id' => $studentId, 'class_id' => (int) $session['class_id'],
                    'status' => $status, 'method' => 'manual', 'note' => $reason, 'changed_by' => $actorId]);
                DB::update('attendances', ['corrected' => 1], 'id = ?', [$id]);
            }
            StatusHistory::add($id, (int) $session['id'], $studentId, $row['status'] ?? null, $status, 'correction', $reason, $actorId);
            return ['old' => $row['status'] ?? null, 'new' => $status];
        });
    }

    /** Hapus koreksi: status kembali mengikuti izin/scan/Alpa. */
    public static function uncorrect(array $session, int $studentId, string $reason, int $actorId): ?string
    {
        $row = Attendance::find((int) $session['id'], $studentId);
        if (!$row || empty($row['corrected'])) {
            return null;
        }
        $leave = Leave::activeFor($studentId, $session['session_date']);
        $row['corrected'] = 0;
        $row['leave_id'] = $leave['id'] ?? null;
        if (!$leave && empty($row['scanned_at']) && $session['status'] !== 'closed') {
            DB::run('DELETE FROM attendances WHERE id = ?', [(int) $row['id']]);
            StatusHistory::add((int) $row['id'], (int) $session['id'], $studentId, $row['status'], null, 'correction', $reason, $actorId);
            return null;
        }
        $new = self::statusFor($row, $session);
        $late = $new === 'T' && $row['scanned_at'] ? ScanService::judge($session, $row['scanned_at'])[1] : 0;
        DB::update('attendances', ['status' => $new, 'late_minutes' => $late, 'corrected' => 0, 'leave_id' => $row['leave_id'], 'method' => $row['scanned_at'] ? $row['method'] : 'auto',
            'changed_by' => $actorId, 'changed_at' => now()], 'id = ?', [(int) $row['id']]);
        StatusHistory::add((int) $row['id'], (int) $session['id'], $studentId, $row['status'], $new, 'correction', $reason, $actorId);
        return $new;
    }
}
