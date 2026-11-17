<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\Student;
use App\Services\Access;
use App\Services\Exporter;
use App\Services\Recap;
use App\Services\RecapExport;
use App\Services\Xlsx;

/**
 * Rekap kehadiran (PRD 4.4): harian, bulanan, semester per kelas; per siswa; ringkasan sekolah. Ekspor XLSX dan CSV.
 * Akses: class:recap (Super Admin, Kepsek, TU, wali kelas kelasnya); ringkasan sekolah: reports.
 */
final class RecapController extends Controller
{
    public const TYPES = ['bulanan' => 'Bulanan', 'harian' => 'Harian', 'semester' => 'Semester'];

    /** Kelas yang boleh direkap pengguna ini (tahun ajaran aktif). */
    private function classes(array $user): array
    {
        $year = AcademicYear::active();
        if (!$year) {
            return [];
        }
        $ids = $user['role'] === Auth::GURU ? Access::homeroomClassIds($user) : null;
        return $ids === [] ? [] : SchoolClass::forYear((int) $year['id'], $ids);
    }

    private function month(): string
    {
        $m = query('bulan');
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? $m : substr(today(), 0, 7);
    }

    private function date(string $key = 'tanggal'): string
    {
        $d = query($key);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) ? $d : today();
    }

    /** Semester yang dipilih (default aktif) dan rentang tanggalnya sampai hari ini. @return array{0:?array,1:string,2:string} */
    private function semesterRange(): array
    {
        $sem = (int) query('semester') ? Semester::find((int) query('semester')) : Semester::active();
        if (!$sem) {
            return [null, today(), today()];
        }
        $to = min($sem['end_date'], today());
        return [$sem, $sem['start_date'], max($sem['start_date'], $to)];
    }

    private function format(): string
    {
        return in_array(query('format'), ['csv', 'xlsx'], true) ? query('format') : '';
    }

    public function index(): void
    {
        $user = Auth::require();
        $classes = $this->classes($user);
        if (!$classes && !Access::can($user, 'reports')) {
            Access::deny($user, 'class:recap');
        }
        $year = AcademicYear::active();
        $this->render('admin/recap/index', [
            'title' => t('Rekap Kehadiran'), 'active' => 'recap', 'classes' => $classes, 'month' => $this->month(), 'date' => $this->date(),
            'semesters' => $year ? Semester::forYear((int) $year['id']) : [], 'semester' => Semester::active(), 'canSchool' => Access::can($user, 'reports'),
        ]);
    }

    /** Form pilihan di halaman Rekap: ?id=…&jenis=… → /admin/rekap/kelas/{id}?jenis=… */
    public function pick(): void
    {
        Auth::require();
        $q = array_filter(['jenis' => query('jenis'), 'bulan' => query('bulan'), 'tanggal' => query('tanggal'), 'semester' => query('semester')]);
        redirect('admin/rekap/kelas/' . (int) query('id'), $q);
    }

    public function classRecap(string $id): void
    {
        $user = Auth::require();
        $class = SchoolClass::find((int) $id) ?? abort(404, 'Kelas tidak ditemukan');
        Access::require($user, 'class:recap', (int) $class['id']);
        $type = array_key_exists(query('jenis'), self::TYPES) ? query('jenis') : 'bulanan';
        $format = $this->format();
        $slug = preg_replace('/[^A-Za-z0-9-]+/', '-', $class['name']);
        if ($type === 'harian') {
            $date = $this->date();
            $data = Recap::daily((int) $class['id'], $date);
            $table = RecapExport::daily($class, $data);
            $file = 'rekap-harian-' . $slug . '-' . $date;
            $view = ['date' => $date, 'd' => $data];
        } elseif ($type === 'semester') {
            [$sem, $from, $to] = $this->semesterRange();
            if (!$sem) {
                flash('warn', 'Semester belum aktif');
                redirect('admin/rekap');
            }
            $data = Recap::semester((int) $class['id'], $from, $to) + ['to' => $to];
            $table = RecapExport::semester($class, $sem, $data);
            $file = 'rekap-semester-' . $slug . '-' . $sem['name'];
            $view = ['sem' => $sem, 'from' => $from, 'to' => $to, 'd' => $data];
        } else {
            $month = $this->month();
            $data = Recap::grid((int) $class['id'], $month . '-01', date('Y-m-t', strtotime($month . '-01')));
            $table = RecapExport::monthly($class, $month, $data);
            $file = 'rekap-bulanan-' . $slug . '-' . $month;
            $view = ['month' => $month, 'd' => $data];
        }
        if ($format !== '') {
            AuditLog::add($user, 'recap.export', (int) $class['id'], 'class', $class['id'], ['type' => $type, 'format' => $format]);
            if ($format === 'csv') {
                $rows = $table['rows'];
                if (!empty($table['foot'])) {
                    $rows[] = $table['foot'];
                }
                Exporter::csv($file . '.csv', $table['head'], $rows);
            }
            $x = new Xlsx();
            match ($type) {
                'bulanan' => RecapExport::monthlyXlsx($x, $table, $class['name']),
                'semester' => RecapExport::semesterXlsx($x, $table, $class['name']),
                default => RecapExport::simpleXlsx($x, $table, $class['name']),
            };
            $x->download($file . '.xlsx');
        }
        $this->render('admin/recap/class', [
            'title' => t('Rekap :t · :c', ['t' => t(self::TYPES[$type]), 'c' => $class['name']]), 'active' => 'recap', 'back' => 'admin/rekap',
            'class' => $class, 'type' => $type, 'table' => $table, 'year' => AcademicYear::active(),
            'semesters' => ($y = AcademicYear::active()) ? Semester::forYear((int) $y['id']) : [],
        ] + $view);
    }

    public function student(string $id): void
    {
        $user = Auth::require();
        $st = Student::find((int) $id) ?? abort(404, 'Siswa tidak ditemukan');
        $year = AcademicYear::active();
        $class = $year ? Student::classIn((int) $st['id'], (int) $year['id']) : null;
        if (!$class) {
            abort(404, 'Siswa belum punya kelas di tahun ajaran aktif');
        }
        Access::require($user, 'class:recap', (int) $class['id']);
        [$sem, $from, $to] = $this->semesterRange();
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', query('dari')) && preg_match('/^\d{4}-\d{2}-\d{2}$/', query('sampai')) && query('dari') <= query('sampai')) {
            [$from, $to] = [query('dari'), query('sampai')];
        }
        $data = Recap::studentDays((int) $class['id'], (int) $st['id'], $from, $to);
        $classRow = SchoolClass::find((int) $class['id']) ?? $class;
        $table = RecapExport::student($st, $classRow, $data['days'], $from, $to);
        if (($format = $this->format()) !== '') {
            AuditLog::add($user, 'recap.export', (int) $class['id'], 'student', $st['id'], ['format' => $format]);
            $file = 'riwayat-' . preg_replace('/[^A-Za-z0-9-]+/', '-', $st['nis']) . '-' . $from . '-' . $to;
            if ($format === 'csv') {
                Exporter::csv($file . '.csv', $table['head'], $table['rows']);
            }
            $x = new Xlsx();
            RecapExport::simpleXlsx($x, $table, $st['nis'], [11, 9, 7, 9, 9, 8, 60]);
            $x->download($file . '.xlsx');
        }
        $this->render('admin/recap/student', [
            'title' => t('Riwayat Kehadiran · :n', ['n' => $st['full_name']]), 'active' => 'recap', 'back' => 'admin/rekap/kelas/' . $class['id'],
            'st' => $st, 'class' => $classRow, 'from' => $from, 'to' => $to, 'd' => $data,
            'leaves' => \App\Models\Leave::forStudent((int) $st['id'], $from, $to),
            'history' => \App\Models\StatusHistory::forStudent((int) $st['id'], $from, $to, 100),
        ]);
    }

    public function school(): void
    {
        $user = Auth::require();
        Access::require($user, 'reports');
        $year = AcademicYear::active();
        $classes = $year ? SchoolClass::forYear((int) $year['id']) : [];
        $range = query('jenis') === 'semester' ? 'semester' : 'bulanan';
        if ($range === 'semester') {
            [$sem, $from, $to] = $this->semesterRange();
            $label = $sem ? t('Semester :s', ['s' => $sem['name']]) . ' · ' . date_id($from) . ' s.d. ' . date_id($to) : '—';
        } else {
            $month = $this->month();
            [$from, $to] = [$month . '-01', min(date('Y-m-t', strtotime($month . '-01')), max($month . '-01', today()))];
            $label = month_id($month);
        }
        $rows = Recap::school($classes, $from, $to);
        $table = RecapExport::school($rows, $label);
        if (($format = $this->format()) !== '') {
            AuditLog::add($user, 'recap.export', null, 'school', null, ['range' => $range, 'format' => $format]);
            $file = 'ringkasan-sekolah-' . ($range === 'semester' ? 'semester' : $this->month());
            if ($format === 'csv') {
                Exporter::csv($file . '.csv', $table['head'], $table['rows']);
            }
            $x = new Xlsx();
            RecapExport::simpleXlsx($x, $table, t('Ringkasan'), [4, 12, 26, 7, 8, 7, 7, 7, 7, 7, 7, 8, 9]);
            $x->download($file . '.xlsx');
        }
        $this->render('admin/recap/school', [
            'title' => t('Ringkasan Sekolah'), 'active' => 'recap', 'back' => 'admin/rekap', 'rows' => $rows, 'label' => $label, 'range' => $range,
            'month' => $this->month(), 'frequent' => Recap::frequentAbsent(null, $from, $to, 3, 50),
        ]);
    }

    /** Ekspor rekap semester seluruh sekolah: satu sheet per kelas + sheet ringkasan (PRD uji Fase 5: < 30 detik). */
    public function schoolSemester(): void
    {
        $user = Auth::require();
        Access::require($user, 'reports');
        [$sem, $from, $to] = $this->semesterRange();
        $year = AcademicYear::active();
        if (!$sem || !$year) {
            flash('warn', 'Semester belum aktif');
            redirect('admin/rekap');
        }
        @set_time_limit(120);
        $x = self::buildSchoolSemester($year, $sem, $from, $to);
        AuditLog::add($user, 'recap.export', null, 'school', null, ['range' => 'semester-all', 'format' => 'xlsx']);
        $x->download('rekap-semester-' . $sem['name'] . '-seluruh-kelas.xlsx');
    }

    public static function buildSchoolSemester(array $year, array $sem, string $from, string $to): Xlsx
    {
        $x = new Xlsx();
        $classes = SchoolClass::forYear((int) $year['id']);
        $summary = [];
        $sheets = [];
        foreach ($classes as $c) {
            $g = Recap::semester((int) $c['id'], $from, $to) + ['to' => $to];
            $sheets[] = [$c, RecapExport::semester($c, $sem, $g)];
            $sum = array_fill_keys(Recap::CODES, 0);
            foreach ($g['totals'] as $t) {
                foreach (Recap::CODES as $k) {
                    $sum[$k] += $t[$k];
                }
            }
            $n = count($g['students']);
            $summary[] = ['class' => $c, 'students' => $n, 'effective' => $g['effective'], 'sum' => $sum,
                'pct' => $n && $g['effective'] ? round(($sum['H'] + $sum['T']) / ($n * $g['effective']) * 100, 1) : null,
                'frequent_absent' => count(array_filter($g['totals'], static fn ($t) => $t['A'] >= 3))];
        }
        RecapExport::simpleXlsx($x, RecapExport::school($summary, t('Semester :s', ['s' => $sem['name']]) . ' · ' . date_id($from) . ' s.d. ' . date_id($to)), t('Ringkasan'), [4, 12, 26, 7, 8, 7, 7, 7, 7, 7, 7, 8, 9]);
        foreach ($sheets as [$c, $table]) {
            RecapExport::semesterXlsx($x, $table, $c['name']);
        }
        return $x;
    }
}
