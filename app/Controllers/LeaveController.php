<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database as DB;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\Leave;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\Access;
use App\Services\AttendanceRules;

/**
 * Izin, sakit, dispensasi per rentang tanggal. Catat dan batalkan: class:manage (Super Admin, TU, wali kelas kelasnya).
 * Lihat daftar: juga Kepala Sekolah (reports). Izin langsung berlaku dan menimpa scan/Alpa pada tanggal itu (kecuali yang sudah dikoreksi).
 */
final class LeaveController extends Controller
{
    /** @return int[]|null kelas yang izinnya boleh dilihat; null = semua */
    private function visibleClassIds(array $user): ?array
    {
        if (in_array($user['role'], [Auth::SUPER, Auth::TU, Auth::KEPSEK], true)) {
            return null;
        }
        return Access::homeroomClassIds($user);
    }

    private function manageable(array $user): array
    {
        $year = AcademicYear::active();
        if (!$year || $user['role'] === Auth::KEPSEK) {
            return [];
        }
        $ids = $user['role'] === Auth::GURU ? Access::homeroomClassIds($user) : null;
        return $ids === [] ? [] : SchoolClass::forYear((int) $year['id'], $ids);
    }

    public function index(): void
    {
        $user = Auth::require();
        $ids = $this->visibleClassIds($user);
        if ($ids === []) {
            Access::deny($user, 'class:manage');
        }
        $year = AcademicYear::active();
        $classId = (int) query('kelas');
        if ($classId && $ids !== null && !in_array($classId, $ids, true)) {
            Access::deny($user, 'class:manage', $classId);
        }
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', query('dari')) ? query('dari') : date('Y-m-d', strtotime('-30 days'));
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', query('sampai')) ? query('sampai') : '';
        $this->render('admin/leaves/index', [
            'title' => t('Izin dan Sakit'), 'active' => 'leaves', 'classId' => $classId, 'from' => $from, 'to' => $to, 'q' => query('q'), 'status' => query('st', 'active'),
            'leaves' => Leave::search($ids, $classId, query('st', 'active'), $from, $to, query('q')),
            'classes' => $year ? SchoolClass::forYear((int) $year['id'], $ids) : [], 'canCreate' => (bool) $this->manageable($user),
        ]);
    }

    public function create(): void
    {
        $user = Auth::require();
        $classes = $this->manageable($user);
        if (!$classes) {
            Access::deny($user, 'class:manage');
        }
        $classId = (int) query('kelas') ?: (count($classes) === 1 ? (int) $classes[0]['id'] : 0);
        if ($classId && !Access::can($user, 'class:manage', $classId)) {
            Access::deny($user, 'class:manage', $classId);
        }
        $this->render('admin/leaves/form', [
            'title' => t('Catat Izin/Sakit'), 'active' => 'leaves', 'back' => 'admin/izin', 'classes' => $classes, 'classId' => $classId,
            'students' => $classId ? SchoolClass::members($classId) : [], 'old' => ['student_id' => query('siswa'), 'date_from' => query('tanggal', today()), 'date_to' => query('tanggal', today())], 'errors' => [],
        ]);
    }

    public function store(): void
    {
        $user = Auth::require();
        $student = Student::find((int) input('student_id'));
        $year = AcademicYear::active();
        $class = $student && $year ? Student::classIn((int) $student['id'], (int) $year['id']) : null;
        if (!$student || !$class) {
            flash('bad', 'Pilih siswa', t('Siswa harus punya kelas di tahun ajaran aktif.'));
            redirect('admin/izin/baru', ['kelas' => (int) input('class_id')]);
        }
        Access::require($user, 'class:manage', (int) $class['id']);
        $d = ['student_id' => (int) $student['id'], 'class_id' => (int) $class['id'], 'kind' => input('kind'), 'date_from' => input('date_from'),
            'date_to' => input('date_to') ?: input('date_from'), 'reason' => input('reason'), 'created_by' => (int) $user['id']];
        $errors = Leave::validate($d);
        if ($errors) {
            $this->render('admin/leaves/form', [
                'title' => t('Catat Izin/Sakit'), 'active' => 'leaves', 'back' => 'admin/izin', 'classes' => $this->manageable($user), 'classId' => (int) $class['id'],
                'students' => SchoolClass::members((int) $class['id']), 'old' => $d, 'errors' => $errors,
            ]);
            return;
        }
        $id = 0;
        $changed = DB::transaction(static function () use ($d, $user, &$id): int {
            $id = Leave::create($d);
            return AttendanceRules::applyLeave($id, (int) $user['id']);
        });
        $err = Leave::storeAttachment($id, $_FILES['attachment'] ?? null);
        AuditLog::add($user, 'leave.create', (int) $class['id'], 'student', $student['id'], ['leave' => $id, 'kind' => $d['kind'], 'from' => $d['date_from'], 'to' => $d['date_to'], 'changed' => $changed]);
        if ($err) {
            flash('warn', 'Izin dicatat tanpa lampiran', $err);
        } else {
            flash('ok', t(':k dicatat', ['k' => t(Leave::KINDS[$d['kind']])]), $student['full_name'] . ' · ' . date_id($d['date_from']) . ($d['date_to'] !== $d['date_from'] ? ' – ' . date_id($d['date_to']) : '')
                . ($changed ? ' · ' . t(':n catatan kehadiran diperbarui', ['n' => $changed]) : ''));
        }
        redirect('admin/izin', ['kelas' => (int) $class['id']]);
    }

    public function cancel(string $id): void
    {
        $user = Auth::require();
        $leave = Leave::find((int) $id) ?? abort(404, 'Izin tidak ditemukan');
        Access::require($user, 'class:manage', (int) $leave['class_id']);
        if ($leave['status'] !== 'active') {
            flash('warn', 'Izin sudah dibatalkan');
            redirect('admin/izin');
        }
        $reason = trim(input('reason'));
        if (mb_strlen($reason) < 3) {
            flash('bad', 'Alasan pembatalan wajib diisi');
            redirect('admin/izin', ['kelas' => (int) $leave['class_id']]);
        }
        $n = DB::transaction(static fn (): int => AttendanceRules::cancelLeave((int) $leave['id'], (int) $user['id'], $reason));
        AuditLog::add($user, 'leave.cancel', (int) $leave['class_id'], 'student', $leave['student_id'], ['leave' => $leave['id'], 'reason' => $reason, 'changed' => $n]);
        flash('ok', 'Izin dibatalkan', $leave['full_name'] . ($n ? ' · ' . t(':n catatan kehadiran dikembalikan', ['n' => $n]) : ''));
        redirect('admin/izin', ['kelas' => (int) $leave['class_id']]);
    }

    public function attachment(string $id): void
    {
        $user = Auth::require();
        $leave = Leave::find((int) $id) ?? abort(404, 'Izin tidak ditemukan');
        $ids = $this->visibleClassIds($user);
        if ($ids !== null && !in_array((int) $leave['class_id'], $ids, true)) {
            Access::deny($user, 'class:manage', (int) $leave['class_id']);
        }
        $path = Leave::attachmentPath($leave) ?? abort(404, 'Lampiran tidak ada');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="lampiran-' . (int) $leave['id'] . '.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }
}
