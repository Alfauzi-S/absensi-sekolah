<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database as DB;
use App\Models\Attendance;
use App\Models\Leave;
use App\Models\Session;
use App\Models\Setting;
use App\Models\StatusHistory;
use App\Services\Access;
use App\Services\AttendanceRules;
use App\Services\Recap;
use App\Services\RecapExport;
use App\Services\ScanService;
use App\Services\SessionCloser;
use App\Services\Xlsx;
use PHPUnit\Framework\TestCase;

/** Fase 5: prioritas status (koreksi > izin > scan > Alpa otomatis), riwayat, ketepatan rekap, ekspor, hak akses rekap. */
final class LeaveRecapTest extends TestCase
{
    private array $f;

    protected function setUp(): void
    {
        $this->f = Fixture::base();
        Setting::set('late_tolerance_minutes', '10');
    }

    private function student(int $class, string $nis, string $name = ''): int
    {
        $id = DB::insert('students', ['nis' => $nis, 'full_name' => $name ?: 'Siswa ' . $nis, 'gender' => 'L', 'status' => 'Aktif', 'created_at' => now(), 'updated_at' => now()]);
        DB::insert('class_members', ['class_id' => $class, 'student_id' => $id, 'academic_year_id' => $this->f['year'], 'created_at' => now()]);
        return $id;
    }

    private function session(int $class, string $date, string $kind = 'harian_masuk', string $start = '07:00', string $status = 'open', ?int $period = null): array
    {
        $id = DB::insert('sessions', ['slot_key' => 't:' . $kind . ':' . $class . ':' . $date . ':' . $start, 'class_id' => $class, 'room_id' => $this->f['room'], 'session_date' => $date,
            'scan_from' => $date . ' 06:00:00', 'starts_at' => $date . ' ' . $start . ':00', 'late_after' => date('Y-m-d H:i:s', strtotime($date . ' ' . $start) + 600),
            'ends_at' => date('Y-m-d H:i:s', strtotime($date . ' ' . $start) + 2700), 'kind' => $kind, 'status' => $status, 'period_start' => $period, 'period_end' => $period, 'created_at' => now()]);
        return Session::findLight($id);
    }

    private function leave(int $sid, string $kind, string $from, string $to): int
    {
        $id = Leave::create(['student_id' => $sid, 'class_id' => $this->f['classA'], 'kind' => $kind, 'date_from' => $from, 'date_to' => $to, 'reason' => 'Surat dokter', 'created_by' => $this->f['tu']]);
        AttendanceRules::applyLeave($id, $this->f['tu']);
        return $id;
    }

    public function testLeaveThenScanKeepsLeaveAndScanTime(): void
    {
        $sid = $this->student($this->f['classA'], '501');
        $s = $this->session($this->f['classA'], '2026-11-16');
        $this->leave($sid, 'S', '2026-11-16', '2026-11-17');
        $r = ScanService::record($s, $sid, $this->f['classA'], '2026-11-16 07:05:00', null, null);
        $this->assertSame('S', $r['status'], 'izin/sakit mengalahkan scan');
        $row = Attendance::find((int) $s['id'], $sid);
        $this->assertSame('2026-11-16 07:05:00', $row['scanned_at'], 'jam scan tetap disimpan');
        $this->assertNotNull($row['leave_id']);
    }

    public function testCancelLeaveRestoresScanOrAlpa(): void
    {
        $a = $this->student($this->f['classA'], '511');
        $b = $this->student($this->f['classA'], '512');
        $s = $this->session($this->f['classA'], '2026-11-16');
        ScanService::record($s, $a, $this->f['classA'], '2026-11-16 07:15:00', null, null); // Terlambat 15 menit
        $la = $this->leave($a, 'I', '2026-11-16', '2026-11-16');
        $lb = $this->leave($b, 'I', '2026-11-16', '2026-11-16');
        $this->assertSame('I', Attendance::find((int) $s['id'], $a)['status'], 'izin dicatat belakangan tetap mengalahkan scan');
        SessionCloser::close($s, null);
        $this->assertSame('I', Attendance::find((int) $s['id'], $b)['status'], 'tutup sesi: siswa berizin tidak menjadi Alpa');
        AttendanceRules::cancelLeave($la, $this->f['tu'], 'Salah input');
        AttendanceRules::cancelLeave($lb, $this->f['tu'], 'Salah input');
        $ra = Attendance::find((int) $s['id'], $a);
        $this->assertSame(['T', 15], [$ra['status'], (int) $ra['late_minutes']], 'kembali ke hasil scan');
        $this->assertSame('A', Attendance::find((int) $s['id'], $b)['status'], 'tanpa scan dan sesi sudah ditutup → Alpa');
        $sources = array_column(StatusHistory::forSession((int) $s['id']), 'source');
        $this->assertContains('leave', $sources);
        $this->assertContains('leave_cancel', $sources);
    }

    public function testCorrectionBeatsLeaveAndScanAndIsLogged(): void
    {
        $sid = $this->student($this->f['classA'], '521');
        $s = $this->session($this->f['classA'], '2026-11-16');
        ScanService::record($s, $sid, $this->f['classA'], '2026-11-16 07:20:00', null, null);
        $r = AttendanceRules::correct($s, $sid, 'H', 'Sudah hadir sejak 06.50, scanner mati', $this->f['teacher']);
        $this->assertSame(['old' => 'T', 'new' => 'H'], $r);
        $this->leave($sid, 'S', '2026-11-16', '2026-11-16');
        $this->assertSame('H', Attendance::find((int) $s['id'], $sid)['status'], 'koreksi tidak ditimpa izin');
        $this->assertSame('dup', ScanService::record($s, $sid, $this->f['classA'], '2026-11-16 07:30:00', null, null)['state']);
        try {
            AttendanceRules::correct($s, $sid, 'A', '  ', $this->f['teacher']);
            $this->fail('alasan wajib');
        } catch (\InvalidArgumentException) {
        }
        $h = StatusHistory::forSession((int) $s['id']);
        $this->assertSame('correction', $h[0]['source']);
        $this->assertSame((int) $this->f['teacher'], (int) $h[0]['actor_id']);
        $this->assertSame('Sudah hadir sejak 06.50, scanner mati', $h[0]['reason']);
        // Hapus koreksi: kembali ke izin (prioritas berikutnya)
        $this->assertSame('S', AttendanceRules::uncorrect($s, $sid, 'Koreksi keliru', $this->f['teacher']));
    }

    public function testCorrectionOfStudentWithoutRow(): void
    {
        $sid = $this->student($this->f['classA'], '531');
        $s = $this->session($this->f['classA'], '2026-11-16');
        AttendanceRules::correct($s, $sid, 'D', 'Lomba OSN', $this->f['tu']);
        SessionCloser::close($s, null);
        $row = Attendance::find((int) $s['id'], $sid);
        $this->assertSame(['D', 1], [$row['status'], (int) $row['corrected']]);
    }

    public function testDayCodeRules(): void
    {
        $this->assertSame('H', Recap::dayCode(['H', 'A', 'H']));
        $this->assertSame('T', Recap::dayCode(['T', 'H']));
        $this->assertSame('T', Recap::dayCode(['A', 'H']), 'baru datang di jam kedua = terlambat');
        $this->assertSame('H', Recap::dayCode(['I', 'H']), 'jam berizin dilewati');
        $this->assertSame('S', Recap::dayCode(['S', 'S']));
        $this->assertSame('I', Recap::dayCode(['A', 'I']));
        $this->assertSame('A', Recap::dayCode(['A', null]));
        $this->assertSame('', Recap::dayCode([null, null]));
    }

    /**
     * Ketepatan rekap (PRD uji Fase 5): data acak 1 bulan untuk 3 kelas dibandingkan dengan perhitungan manual yang ditulis
     * terpisah dari Recap (langsung dari rencana data, bukan dari database). Harus cocok 100%.
     */
    public function testMonthlyRecapMatchesManualCountForThreeClasses(): void
    {
        mt_srand(55);
        $classC = \App\Models\SchoolClass::create(['academic_year_id' => $this->f['year'], 'name' => 'X-C', 'grade' => 10, 'homeroom_teacher_id' => null, 'room_id' => null]);
        $classes = [$this->f['classA'], $this->f['classB'], $classC];
        DB::insert('holidays', ['holiday_date' => '2026-11-10', 'name' => 'Hari Pahlawan']);
        $plan = []; // class => student => date => [statuses per sesi]
        $students = [];
        foreach ($classes as $k => $cid) {
            for ($i = 1; $i <= 8; $i++) {
                $students[$cid][] = $this->student($cid, (string) (7000 + $k * 100 + $i));
            }
        }
        $dates = [];
        for ($d = 2; $d <= 27; $d++) {
            $date = sprintf('2026-11-%02d', $d);
            if ((int) date('N', strtotime($date)) >= 6 || $date === '2026-11-10') {
                continue;
            }
            $dates[] = $date;
            foreach ($classes as $cid) {
                // Kelas A per jam (3 jam), kelas B dan C masuk/pulang; satu hari kelas C jam kosong (tidak efektif); 27 Nov belum ditutup
                $kinds = $cid === $this->f['classA'] ? [['jadwal', '07:00', 1], ['jadwal', '08:00', 2], ['jadwal', '09:00', 3]] : [['harian_masuk', '07:00', null], ['harian_pulang', '14:00', null]];
                $status = $date === '2026-11-27' ? 'open' : (($cid === $classC && $date === '2026-11-12') ? 'cancelled' : 'closed');
                $sess = [];
                foreach ($kinds as [$kind, $t, $p]) {
                    $sess[] = [$this->session($cid, $date, $kind, $t, $status, $p), $kind];
                }
                foreach ($students[$cid] as $sid) {
                    $pool = ['H', 'H', 'H', 'H', 'H', 'H', 'T', 'S', 'I', 'A', 'A', 'D', null];
                    foreach ($sess as $j => [$s, $kind]) {
                        $st = $pool[mt_rand(0, count($pool) - 1)];
                        if ($status === 'closed' && $st === null) {
                            $st = 'A';
                        }
                        if ($st !== null) {
                            Attendance::tryInsert(['session_id' => (int) $s['id'], 'student_id' => $sid, 'class_id' => $cid, 'status' => $st, 'method' => 'manual']);
                        }
                        if ($status !== 'cancelled') {
                            $plan[$cid][$sid][$date][] = ['st' => $st, 'kind' => $kind, 'closed' => $status === 'closed'];
                        }
                    }
                }
            }
        }
        // Perhitungan manual
        $manual = function (array $sess): string {
            $count = array_values(array_filter($sess, static fn ($x) => $x['kind'] !== 'harian_pulang'));
            $sts = array_column($count, 'st');
            $hadir = in_array('H', $sts, true) || in_array('T', $sts, true);
            if ($hadir) {
                $firstReal = null;
                foreach ($sts as $x) {
                    if (in_array($x, ['H', 'T', 'A'], true)) {
                        $firstReal = $x;
                        break;
                    }
                }
                return $firstReal === 'H' ? 'H' : 'T';
            }
            foreach ($sts as $x) {
                if (in_array($x, ['S', 'I', 'D'], true)) {
                    return $x;
                }
            }
            return in_array('A', $sts, true) ? 'A' : '';
        };
        foreach ($classes as $cid) {
            $g = Recap::grid($cid, '2026-11-01', '2026-11-30');
            $effective = 0;
            foreach ($dates as $date) {
                $any = $plan[$cid][$students[$cid][0]][$date] ?? [];
                if ($any && $any[0]['closed']) {
                    $effective++;
                }
            }
            $this->assertSame($effective, $g['effective'], 'hari efektif kelas ' . $cid);
            foreach ($students[$cid] as $sid) {
                $tot = array_fill_keys(['H', 'T', 'S', 'I', 'A', 'D'], 0);
                foreach ($dates as $date) {
                    $sess = $plan[$cid][$sid][$date] ?? [];
                    $code = $sess ? $manual($sess) : '';
                    $this->assertSame($code, $g['codes'][$sid][$date], "kode $cid/$sid/$date");
                    if ($code !== '' && $sess[0]['closed']) {
                        $tot[$code]++;
                    }
                }
                foreach ($tot as $k => $n) {
                    $this->assertSame($n, $g['totals'][$sid][$k], "jumlah $k siswa $sid");
                }
                $this->assertEqualsWithDelta(round(($tot['H'] + $tot['T']) / $effective * 100, 1), $g['totals'][$sid]['pct'], 0.001);
            }
            // Daftar alpa ≥ 3 versi SQL sama dengan hitungan grid
            $expect = [];
            foreach ($g['totals'] as $sid => $t) {
                if ($t['A'] >= 3) {
                    $expect[$sid] = $t['A'];
                }
            }
            $got = [];
            foreach (Recap::frequentAbsent([$cid], '2026-11-01', '2026-11-30') as $r) {
                $got[(int) $r['student_id']] = (int) $r['n'];
            }
            ksort($expect);
            ksort($got);
            $this->assertSame($expect, $got, 'alpa ≥ 3 kelas ' . $cid);
        }
        $this->assertNotEmpty(array_filter(Recap::frequentAbsent(null, '2026-11-01', '2026-11-30')), 'data uji memang punya siswa alpa ≥ 3');
    }

    public function testExportIsSafeFromFormulaInjection(): void
    {
        $sid = $this->student($this->f['classA'], '601', '=HYPERLINK("http://x","klik")');
        $s = $this->session($this->f['classA'], '2026-11-16', 'harian_masuk', '07:00', 'closed');
        Attendance::tryInsert(['session_id' => (int) $s['id'], 'student_id' => $sid, 'class_id' => $this->f['classA'], 'status' => 'H', 'method' => 'manual']);
        $class = \App\Models\SchoolClass::find($this->f['classA']);
        $g = Recap::grid($this->f['classA'], '2026-11-01', '2026-11-30');
        $x = new Xlsx();
        RecapExport::monthlyXlsx($x, RecapExport::monthly($class, '2026-11', $g), 'X-A');
        $file = tempnam(sys_get_temp_dir(), 'x') . '.xlsx';
        $x->save($file);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($file) === true);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($file);
        $this->assertStringContainsString("&apos;=HYPERLINK", $sheet, 'teks diawali = diberi tanda kutip');
        $this->assertStringNotContainsString('<f>', $sheet, 'tidak ada sel rumus');
        $this->assertSame("'=HYPERLINK(\"http://x\",\"klik\")", Xlsx::safe('=HYPERLINK("http://x","klik")'));
        $this->assertSame("'+1", Xlsx::safe('+1'));
        $this->assertSame("'@SUM(A1)", Xlsx::safe('@SUM(A1)'));
    }

    public function testRecapAccess(): void
    {
        $wali = Fixture::user($this->f['teacher']); // wali X-A
        $guru = Fixture::user($this->f['teacher2']);
        $this->assertTrue(Access::can($wali, 'class:recap', $this->f['classA']));
        $this->assertFalse(Access::can($wali, 'class:recap', $this->f['classB']), 'wali kelas membuka kelas lain → ditolak');
        $this->assertFalse(Access::can($guru, 'class:recap', $this->f['classA']), 'guru mapel tanpa perwalian tidak melihat rekap kelas');
        $this->assertTrue(Access::can(Fixture::user($this->f['kepsek']), 'class:recap', $this->f['classB']));
        $this->assertFalse(Access::can(Fixture::user($this->f['kepsek']), 'class:manage', $this->f['classB']), 'kepsek tidak mengubah kehadiran');
        $this->assertTrue(Access::can(Fixture::user($this->f['tu']), 'class:manage', $this->f['classB']));
        $this->assertFalse(Access::can($wali, 'class:manage', $this->f['classB']));
    }

    public function testLeaveValidation(): void
    {
        $this->assertArrayHasKey('date_to', Leave::validate(['kind' => 'S', 'date_from' => '2026-11-10', 'date_to' => '2026-11-09', 'reason' => 'Sakit']));
        $this->assertArrayHasKey('kind', Leave::validate(['kind' => 'X', 'date_from' => '2026-11-10', 'date_to' => '2026-11-10', 'reason' => 'Sakit']));
        $this->assertArrayHasKey('reason', Leave::validate(['kind' => 'S', 'date_from' => '2026-11-10', 'date_to' => '2026-11-10', 'reason' => '']));
        $this->assertArrayHasKey('date_to', Leave::validate(['kind' => 'S', 'date_from' => '2026-01-01', 'date_to' => '2026-12-31', 'reason' => 'Lama']));
        $this->assertSame([], Leave::validate(['kind' => 'D', 'date_from' => '2026-11-10', 'date_to' => '2026-11-12', 'reason' => 'Lomba']));
    }
}
