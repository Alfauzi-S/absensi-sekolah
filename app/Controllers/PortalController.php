<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database as DB;
use App\Core\RateLimiter;
use App\Core\View;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\Setting;
use App\Models\Student;
use App\Services\Exporter;
use App\Services\Portal;
use App\Services\Recap;

/**
 * Portal orang tua/siswa: riwayat kehadiran anak sendiri. Tidak memakai akun guru/staf; sesi portal terpisah dari sesi admin.
 * Pembuatan kode akses ada di halaman siswa dan kelas (Super Admin, TU).
 */
final class PortalController extends Controller
{
    public function home(): void
    {
        $this->needEnabled();
        $kids = Portal::children();
        if (count($kids) === 1 && query('tambah') !== '1') {
            redirect('portal/siswa/' . $kids[0]);
        }
        $this->view('portal/login', ['title' => t('Portal Orang Tua'), 'kids' => $this->kids($kids), 'nis' => '', 'error' => '']);
    }

    public function login(): void
    {
        $this->needEnabled();
        $nis = mb_substr(trim(input('nis')), 0, 30);
        $code = (string) ($_POST['code'] ?? '');
        $ipKey = 'portal:' . client_ip();
        $nisKey = 'portal-nis:' . $nis;
        $error = '';
        if ($nis === '' || trim($code) === '') {
            $error = t('NIS dan kode akses wajib diisi.');
        } elseif (RateLimiter::tooMany($ipKey, 10, 900) || RateLimiter::tooMany($nisKey, 5, 900)) {
            $error = t('Terlalu banyak percobaan. Tunggu 15 menit lalu coba lagi.');
        } elseif (!($st = Portal::verify($nis, $code))) {
            RateLimiter::hit($ipKey);
            RateLimiter::hit($nisKey);
            AuditLog::add(null, 'portal.login_failed', null, 'student', null, ['nis' => $nis], 'fail');
            $error = t('NIS atau kode akses salah. Kode akses didapat dari wali kelas atau TU.');
        } else {
            RateLimiter::clear($nisKey);
            session_regenerate_id(true);
            Portal::addChild((int) $st['id']);
            AuditLog::add(null, 'portal.login', null, 'student', $st['id']);
            redirect('portal/siswa/' . $st['id']);
        }
        http_response_code(422);
        $this->view('portal/login', ['title' => t('Portal Orang Tua'), 'kids' => $this->kids(Portal::children()), 'nis' => $nis, 'error' => $error]);
    }

    public function logout(): void
    {
        unset($_SESSION[Portal::SESSION_KEY]);
        session_regenerate_id(true);
        flash('ok', 'Sudah keluar dari portal');
        redirect('portal');
    }

    public function student(string $id): void
    {
        $this->needEnabled();
        $sid = (int) $id;
        if (!Portal::children()) {
            redirect('portal');
        }
        if (!Portal::canView($sid)) {
            AuditLog::add(null, 'portal.denied', null, 'student', $sid, ['open' => Portal::children()], 'denied');
            http_response_code(403);
            $this->view('portal/denied', ['title' => t('Akses ditolak'), 'kids' => $this->kids(Portal::children())]);
            return;
        }
        $st = Student::find($sid);
        $year = AcademicYear::active();
        $class = $st && $year ? Student::classIn($sid, (int) $year['id']) : null;
        $sem = Semester::active();
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', query('bulan')) ? query('bulan') : substr(today(), 0, 7);
        $from = $month . '-01';
        $to = min(date('Y-m-t', strtotime($from)), today());
        $data = $class && $from <= $to ? Recap::studentDays((int) $class['id'], $sid, $from, $to) : null;
        $semData = $class && $sem ? Recap::studentDays((int) $class['id'], $sid, $sem['start_date'], min($sem['end_date'], today())) : null;
        $this->view('portal/student', [
            'title' => $st ? $st['full_name'] : t('Portal Orang Tua'), 'st' => $st, 'class' => $class ? (SchoolClass::find((int) $class['id']) ?? $class) : null,
            'month' => $month, 'from' => $from, 'to' => $to, 'd' => $data, 'sem' => $sem, 'semTotals' => $semData['totals'] ?? null, 'semEffective' => $semData['effective'] ?? 0,
            'kids' => $this->kids(Portal::children()), 'prev' => date('Y-m', strtotime($from . ' -1 month')),
            'next' => $month < substr(today(), 0, 7) ? date('Y-m', strtotime($from . ' +1 month')) : null,
        ]);
    }

    /** POST /admin/siswa/{id}/kode-portal: kode baru untuk satu siswa, ditampilkan sekali. */
    public function issue(string $id): void
    {
        $user = $this->allow('master');
        $st = Student::find((int) $id) ?? abort(404, 'Siswa tidak ditemukan');
        $code = Portal::issue((int) $st['id']);
        AuditLog::add($user, 'portal.code', null, 'student', $st['id'], ['nis' => $st['nis']]);
        $_SESSION['portal_code_once'] = ['id' => (int) $st['id'], 'code' => Portal::pretty($code)];
        flash('ok', 'Kode portal dibuat', t('Catat atau cetak kodenya sekarang; kode hanya ditampilkan sekali.'));
        redirect('admin/siswa/' . $st['id']);
    }

    /** POST /admin/kelas/{id}/kode-portal: CSV kode untuk siswa kelas yang belum punya (semua=1: buat ulang semua). */
    public function issueClass(string $id): void
    {
        $user = $this->allow('master');
        $class = SchoolClass::find((int) $id) ?? abort(404, 'Kelas tidak ditemukan');
        $all = input('semua') === '1';
        $rows = [];
        DB::transaction(function () use ($class, $all, &$rows): void {
            foreach (DB::all("SELECT s.id, s.nis, s.full_name, s.portal_code_hash FROM class_members m JOIN students s ON s.id = m.student_id
                WHERE m.class_id = ? AND s.status = 'Aktif' ORDER BY s.full_name", [(int) $class['id']]) as $s) {
                if (!$all && $s['portal_code_hash']) {
                    continue;
                }
                $rows[] = [$s['nis'], $s['full_name'], $class['name'], Portal::pretty(Portal::issue((int) $s['id'])), full_url('portal')];
            }
        });
        AuditLog::add($user, 'portal.code_bulk', (int) $class['id'], 'class', $class['id'], ['count' => count($rows), 'regenerate' => $all]);
        if (!$rows) {
            flash('info', 'Semua siswa sudah punya kode portal', t('Pilih "Buat ulang semua" bila kode perlu diganti.'));
            redirect('admin/kelas/' . $class['id']);
        }
        Exporter::csv('kode-portal-' . preg_replace('/[^A-Za-z0-9-]+/', '-', $class['name']) . '.csv', ['NIS', 'Nama', 'Kelas', 'Kode akses', 'Alamat portal'], $rows);
    }

    private function needEnabled(): void
    {
        if (Setting::get('portal_enabled') !== '1') {
            abort(404, 'Portal orang tua belum dibuka', 'Sekolah belum menyalakan portal riwayat kehadiran.');
        }
    }

    private function kids(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        return DB::all('SELECT id, nis, full_name FROM students WHERE id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY full_name');
    }

    private function view(string $tpl, array $data): void
    {
        View::render($tpl, $data + ['school' => Setting::get('school_name') ?: (string) config('app_name', 'Absensi Sekolah')], 'bare');
    }
}
