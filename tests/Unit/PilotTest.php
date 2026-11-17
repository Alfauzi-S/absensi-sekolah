<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database as DB;
use App\Models\Session;
use App\Models\Setting;
use App\Services\Access;
use App\Services\Health;
use App\Services\Pilot;
use App\Services\ScanService;
use App\Services\SessionCloser;
use PHPUnit\Framework\TestCase;

/** Fase 7: laporan pilot (kecepatan per kelas, akurasi vs absen manual) dan kesehatan sistem. */
final class PilotTest extends TestCase
{
    private array $f;
    private const DATE = '2026-11-16';

    protected function setUp(): void
    {
        $this->f = Fixture::base();
        Setting::set('late_tolerance_minutes', '10');
    }

    private function student(int $class, string $nis): int
    {
        $id = DB::insert('students', ['nis' => $nis, 'full_name' => 'Siswa ' . $nis, 'gender' => 'L', 'status' => 'Aktif', 'created_at' => now(), 'updated_at' => now()]);
        DB::insert('class_members', ['class_id' => $class, 'student_id' => $id, 'academic_year_id' => $this->f['year'], 'created_at' => now()]);
        return $id;
    }

    private function session(int $class): array
    {
        $id = DB::insert('sessions', ['slot_key' => 'p:' . $class, 'class_id' => $class, 'room_id' => $this->f['room'], 'session_date' => self::DATE,
            'scan_from' => self::DATE . ' 06:00:00', 'starts_at' => self::DATE . ' 07:00:00', 'late_after' => self::DATE . ' 07:10:00', 'ends_at' => self::DATE . ' 07:45:00',
            'kind' => 'harian_masuk', 'status' => 'open', 'created_at' => now()]);
        return Session::findLight($id);
    }

    public function testAccuracyComparesDailyStatusAndNeedsExplanations(): void
    {
        $a = $this->student($this->f['classA'], 'P1');
        $b = $this->student($this->f['classA'], 'P2');
        $c = $this->student($this->f['classA'], 'P3');
        $s = $this->session($this->f['classA']);
        ScanService::record($s, $a, $this->f['classA'], self::DATE . ' 07:02:00', null, null);
        ScanService::record($s, $b, $this->f['classA'], self::DATE . ' 07:25:00', null, null);
        SessionCloser::close($s, null);
        // Guru: a hadir, b hadir (di kertas tidak membedakan terlambat), c alpa
        $this->assertSame(3, Pilot::saveManual($this->f['classA'], self::DATE, [$a => 'H', $b => 'H', $c => 'A', 99999 => 'H'], $this->f['teacher']));
        $rows = Pilot::compare($this->f['classA'], self::DATE);
        $by = array_column($rows, null, 'student_id');
        $this->assertSame([true, false, true], [$by[$a]['match'], $by[$b]['match'], $by[$c]['match']]);
        $this->assertSame('07:25', $by[$b]['first_scan']);
        $acc = Pilot::accuracy($this->f['classA'], self::DATE, self::DATE);
        $this->assertSame(['rows' => 3, 'match' => 2, 'diff' => 1, 'unexplained' => 1, 'pass' => false], array_diff_key($acc, ['days' => 1]));
        Pilot::explain((int) $by[$b]['check_id'], 'Terlambat 15 menit, di kertas ditulis hadir', $this->f['teacher']);
        $this->assertTrue(Pilot::accuracy($this->f['classA'], self::DATE, self::DATE)['pass'], 'setiap selisih punya penjelasan → lulus');
        // Status manual diubah: penjelasan lama dihapus agar dinilai ulang
        Pilot::saveManual($this->f['classA'], self::DATE, [$b => 'T'], $this->f['teacher']);
        $this->assertNull(DB::value('SELECT explanation FROM pilot_checks WHERE student_id = ?', [$b]));
        $this->assertSame(0, Pilot::accuracy($this->f['classA'], self::DATE, self::DATE)['diff']);
    }

    public function testImportCsvAcceptsWordsAndDayFirstDates(): void
    {
        $a = $this->student($this->f['classA'], 'Q1');
        $this->student($this->f['classB'], 'Q2');
        $file = tempnam(sys_get_temp_dir(), 'pc');
        file_put_contents($file, "nis,tanggal,status\nQ1,16/11/2026,Sakit\nQ2,2026-11-16,H\nQ1,2026-13-01,H\nQ1,2026-11-17,X\n");
        $r = Pilot::importCsv($this->f['classA'], $file, 'manual.csv', $this->f['teacher']);
        unlink($file);
        $this->assertSame(1, $r['saved']);
        $this->assertSame([3, 4, 5], array_keys($r['errors']), 'NIS kelas lain, tanggal salah, status salah');
        $this->assertSame('S', DB::value('SELECT manual_status FROM pilot_checks WHERE student_id = ? AND check_date = ?', [$a, '2026-11-16']));
    }

    public function testSpeedPerClassFromKioskMetrics(): void
    {
        $sid = $this->student($this->f['classA'], 'R1');
        $s = $this->session($this->f['classA']);
        for ($i = 0; $i < 40; $i++) {
            $scan = 'scanpilot' . $i;
            DB::insert('scan_logs', ['scan_id' => $scan, 'device_id' => null, 'code' => 'X', 'result' => 'ok', 'session_id' => $s['id'], 'student_id' => $sid, 'scanned_at' => self::DATE . ' 07:00:00',
                'response' => '{}', 't_server_ms' => 2, 'received_at' => self::DATE . ' 07:00:00']);
            DB::insert('scan_metrics', ['scan_id' => $scan, 'device_id' => null, 'source' => 'usb', 't_feedback_ms' => 10, 't_total_ms' => $i < 38 ? 100 + $i : 900, 'created_at' => self::DATE . ' 07:00:01']);
        }
        $sp = Pilot::speed([$this->f['classA'], $this->f['classB']], self::DATE, self::DATE);
        $this->assertSame(40, $sp[$this->f['classA']]['total']['total']['n']);
        $this->assertSame(137.0, $sp[$this->f['classA']]['total']['total']['p95'], 'p95 dari 40 scan: 2 lambat tidak menggeser p95');
        $this->assertTrue(Pilot::speedPass($sp[$this->f['classA']]['total']));
        $this->assertNull(Pilot::speedPass($sp[$this->f['classB']]['total']), 'kelas tanpa data: belum bisa dinilai');
    }

    public function testHealthChecksFlagMissingSchedulerAndBackup(): void
    {
        Setting::set('scheduler_last_at', '');
        $checks = array_column(Health::checks('2026-11-16 09:00:00'), null, 'key');
        $this->assertSame('warn', $checks['scheduler']['level']);
        $this->assertSame('ok', $checks['migrations']['level']);
        Setting::set('scheduler_last_at', '2026-11-16 07:00:00');
        $this->assertSame('bad', array_column(Health::checks('2026-11-16 09:00:00'), null, 'key')['scheduler']['level'], 'penjadwal mati 2 jam');
        $s = $this->session($this->f['classA']);
        $this->assertSame('bad', array_column(Health::checks('2026-11-16 09:00:00'), null, 'key')['sessions']['level'], 'sesi lewat masih terbuka');
        SessionCloser::close($s, null);
        $this->assertSame('ok', array_column(Health::checks('2026-11-16 09:00:00'), null, 'key')['sessions']['level']);
        $sum = Health::summary('2026-11-16 09:00:00');
        $this->assertTrue($sum['ok']);
        $this->assertSame(120, $sum['scheduler_age_min']);
        $this->assertSame([], array_diff(array_keys($sum), ['ok', 'db', 'time', 'scheduler_age_min', 'backup_age_hours']), 'tidak ada data pribadi di /healthz');
    }

    public function testRolesForPilotPages(): void
    {
        $this->assertTrue(Access::can(Fixture::user($this->f['kepsek']), 'reports'));
        $this->assertFalse(Access::can(Fixture::user($this->f['teacher']), 'reports'));
        $this->assertTrue(Access::can(Fixture::user($this->f['teacher']), 'class:manage', $this->f['classA']), 'wali kelas mengisi absen manual kelasnya');
        $this->assertFalse(Access::can(Fixture::user($this->f['teacher']), 'class:manage', $this->f['classB']));
        $this->assertFalse(Access::can(Fixture::user($this->f['tu']), 'settings'), 'kesehatan sistem hanya Super Admin');
    }
}
