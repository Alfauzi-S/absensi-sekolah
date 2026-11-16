<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Master;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\Session;
use App\Models\Student;
use App\Services\Access;
use App\Services\ScanService;
use App\Services\SessionCloser;
use App\Services\SessionResolver;

/**
 * Sesi absensi: daftar hari ini, halaman live per sesi (guru), sesi tambahan, jam kosong, tutup sesi → Alpa.
 * Lihat: class:view. Kelola (tutup, batalkan, tandai manual, buat sesi tambahan): Super Admin, TU, guru pengampu sesi, wali kelas.
 */
final class SessionController extends Controller
{
    private function canManage(array $user, array $s): bool
    {
        if (in_array($user['role'], [Auth::SUPER, Auth::TU], true)) {
            return true;
        }
        if ($user['role'] !== Auth::GURU) {
            return false;
        }
        return (int) ($s['teacher_id'] ?? 0) === (int) $user['id'] || in_array((int) $s['class_id'], Access::homeroomClassIds($user), true);
    }

    private function manage(string $id): array
    {
        $user = Auth::require();
        $s = Session::find((int) $id) ?? abort(404, 'Sesi tidak ditemukan');
        if (!$this->canManage($user, $s)) {
            Access::deny($user, 'session:manage', (int) $s['class_id']);
        }
        return [$user, $s];
    }

    public function index(): void
    {
        $user = Auth::require();
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', query('tanggal')) && strtotime(query('tanggal')) ? query('tanggal') : today();
        $classIds = Access::classIds($user);
        $sessions = Session::onDate($date, $classIds);
        $year = AcademicYear::active();
        $this->render('admin/sessions/index', [
            'title' => t('Sesi Absensi'), 'active' => 'sessions', 'date' => $date, 'sessions' => $sessions,
            'canCreate' => in_array($user['role'], [Auth::SUPER, Auth::TU, Auth::GURU], true),
            'classes' => $year ? SchoolClass::forYear((int) $year['id'], $classIds) : [], 'rooms' => Master::all('ruangan'),
            'isHoliday' => Master::isHoliday($date),
        ]);
    }

    public function show(string $id): void
    {
        $user = Auth::require();
        $s = Session::find((int) $id) ?? abort(404, 'Sesi tidak ditemukan');
        Access::require($user, 'class:view', (int) $s['class_id']);
        $this->render('admin/sessions/show', [
            'title' => SessionResolver::label($s), 'active' => 'sessions', 'back' => 'admin/sesi?tanggal=' . $s['session_date'],
            's' => $s, 'roster' => Session::roster($s), 'counts' => Attendance::countBySession((int) $s['id']),
            'canManage' => $this->canManage($user, $s),
        ]);
    }

    /** JSON untuk halaman live (polling 5 detik). */
    public function live(string $id): void
    {
        $user = Auth::require();
        $s = Session::find((int) $id) ?? abort(404, 'Sesi tidak ditemukan');
        Access::require($user, 'class:view', (int) $s['class_id']);
        $roster = array_map(static fn (array $r) => [
            'id' => (int) $r['id'], 'name' => $r['full_name'], 'nis' => $r['nis'], 'status' => $r['status'], 'late' => (int) $r['late_minutes'],
            'at' => $r['scanned_at'] ? substr($r['scanned_at'], 11, 5) : null, 'method' => $r['method'],
        ], Session::roster($s));
        json_out(['ok' => true, 'status' => $s['status'], 'counts' => Attendance::countBySession((int) $s['id']), 'total' => (int) $s['student_count'], 'roster' => $roster, 'server_time' => now()]);
    }

    /** Sesi tambahan (di luar jadwal): kelas, ruangan, jam mulai–selesai. */
    public function store(): void
    {
        $user = Auth::require(Auth::SUPER, Auth::TU, Auth::GURU);
        $classId = (int) input('class_id');
        $class = $classId ? SchoolClass::find($classId) : null;
        if (!$class || !Access::can($user, 'class:view', $classId)) {
            flash('bad', 'Kelas tidak valid');
            redirect('admin/sesi');
        }
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', input('date')) ? input('date') : today();
        $start = input('start');
        $end = input('end');
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end) || $end <= $start) {
            flash('bad', 'Jam tidak valid', t('Isi jam mulai dan selesai (selesai harus setelah mulai).'));
            redirect('admin/sesi', ['tanggal' => $date]);
        }
        $roomId = (int) input('room_id') ?: ($class['room_id'] ? (int) $class['room_id'] : null);
        $tolerance = max(0, \App\Models\Setting::int('late_tolerance_minutes'));
        $starts = $date . ' ' . $start . ':00';
        $semester = Semester::active();
        $s = Session::createOrGet([
            'slot_key' => null, 'schedule_id' => null, 'semester_id' => $semester ? (int) $semester['id'] : null, 'class_id' => $classId,
            'room_id' => $roomId, 'subject_id' => (int) input('subject_id') ?: null, 'teacher_id' => $user['role'] === Auth::GURU ? (int) $user['id'] : ((int) input('teacher_id') ?: null),
            'session_date' => $date, 'scan_from' => SessionResolver::addMinutes($starts, -SessionResolver::GRACE_BEFORE), 'starts_at' => $starts,
            'late_after' => SessionResolver::addMinutes($starts, $tolerance), 'ends_at' => $date . ' ' . $end . ':00', 'kind' => 'tambahan',
            'note' => input('note'), 'opened_by' => (int) $user['id'],
        ]);
        AuditLog::add($user, 'session.create', $classId, 'session', $s['id'], ['date' => $date, 'start' => $start, 'end' => $end]);
        flash('ok', 'Sesi tambahan dibuat', t('Scanner di ruangan itu menerima scan kelas :c pada :a–:b.', ['c' => $class['name'], 'a' => $start, 'b' => $end]));
        redirect('admin/sesi/' . $s['id']);
    }

    public function close(string $id): void
    {
        [$user, $s] = $this->manage($id);
        if ($s['status'] !== 'open') {
            flash('warn', 'Sesi sudah tidak berjalan');
            redirect('admin/sesi/' . $s['id']);
        }
        $n = SessionCloser::close($s, (int) $user['id']);
        AuditLog::add($user, 'session.close', (int) $s['class_id'], 'session', $s['id'], ['alpa' => $n]);
        flash('ok', 'Sesi ditutup', t(':n siswa yang belum scan ditandai Alpa.', ['n' => $n]));
        redirect('admin/sesi/' . $s['id']);
    }

    /** Jam kosong: sesi dibatalkan, tidak ada yang dihitung Alpa. */
    public function cancel(string $id): void
    {
        [$user, $s] = $this->manage($id);
        if ($s['status'] !== 'open') {
            flash('warn', 'Sesi sudah tidak berjalan');
            redirect('admin/sesi/' . $s['id']);
        }
        Session::setStatus((int) $s['id'], 'cancelled', (int) $user['id'], input('note') ?: t('Jam kosong'));
        AuditLog::add($user, 'session.cancel', (int) $s['class_id'], 'session', $s['id'], ['note' => input('note')]);
        flash('ok', 'Sesi dibatalkan (jam kosong)', t('Kehadiran sesi ini tidak dihitung.'));
        redirect('admin/sesi/' . $s['id']);
    }

    /** Buka kembali sesi yang ditutup/dibatalkan (mis. salah tutup). Baris Alpa otomatis dihapus. */
    public function reopen(string $id): void
    {
        [$user, $s] = $this->manage($id);
        \App\Core\Database::run("DELETE FROM attendances WHERE session_id = ? AND status = 'A' AND method = 'auto'", [(int) $s['id']]);
        Session::setStatus((int) $s['id'], 'open');
        AuditLog::add($user, 'session.reopen', (int) $s['class_id'], 'session', $s['id']);
        flash('ok', 'Sesi dibuka kembali');
        redirect('admin/sesi/' . $s['id']);
    }

    /** Tandai hadir secara manual (kartu tertinggal/rusak). Koreksi status lain menyusul di Fase 5. */
    public function manual(string $id): void
    {
        [$user, $s] = $this->manage($id);
        $student = Student::find((int) input('student_id'));
        $member = $student ? \App\Core\Database::value('SELECT id FROM class_members WHERE class_id = ? AND student_id = ?', [(int) $s['class_id'], (int) $student['id']]) : null;
        if (!$student || !$member) {
            flash('bad', 'Siswa bukan anggota kelas ini');
            redirect('admin/sesi/' . $s['id']);
        }
        $r = ScanService::record($s, (int) $student['id'], (int) $s['class_id'], now(), null, null, 'manual', (int) $user['id']);
        AuditLog::add($user, 'attendance.manual', (int) $s['class_id'], 'student', $student['id'], ['session' => $s['id'], 'state' => $r['state']]);
        flash($r['state'] === 'dup' ? 'warn' : 'ok', $r['state'] === 'dup' ? 'Sudah tercatat sebelumnya' : 'Kehadiran dicatat manual', $student['full_name'] . ' · ' . status_label($r['status']));
        redirect('admin/sesi/' . $s['id']);
    }
}
