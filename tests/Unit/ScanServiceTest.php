<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database as DB;
use App\Models\Attendance;
use App\Models\Card;
use App\Models\Device;
use App\Models\ScanLog;
use App\Models\Schedule;
use App\Models\Session;
use App\Models\Setting;
use App\Services\ScanService;
use App\Services\SessionCloser;
use App\Services\SessionResolver;
use PHPUnit\Framework\TestCase;

/** Inti absensi: Hadir/Terlambat per menit, duplikat, salah kelas, kartu tidak berlaku, tanpa sesi, idempoten, tutup sesi → Alpa. */
final class ScanServiceTest extends TestCase
{
    private array $f;
    private array $device;
    private string $cardA;
    private string $cardA2;
    private string $cardB;
    private int $studentA;

    protected function setUp(): void
    {
        $this->f = Fixture::base();
        Setting::set('attendance_mode', Setting::ATT_MODE_PERIOD);
        Setting::set('late_tolerance_minutes', '10');
        Schedule::create(['semester_id' => $this->f['semester'], 'class_id' => $this->f['classA'], 'subject_id' => $this->f['mtk'], 'teacher_id' => $this->f['teacher'], 'room_id' => $this->f['room'], 'day_of_week' => 1, 'period_start' => 1, 'period_end' => 2]);
        $mk = function (string $nis, string $name, int $class): array {
            $id = DB::insert('students', ['nis' => $nis, 'full_name' => $name, 'gender' => 'L', 'status' => 'Aktif', 'created_at' => now(), 'updated_at' => now()]);
            DB::insert('class_members', ['class_id' => $class, 'student_id' => $id, 'academic_year_id' => $this->f['year'], 'created_at' => now()]);
            return [$id, Card::issue($id, null)['code']];
        };
        [$this->studentA, $this->cardA] = $mk('1001', 'Siswa A', $this->f['classA']);
        [, $this->cardA2] = $mk('1002', 'Siswa A2', $this->f['classA']);
        [, $this->cardB] = $mk('2001', 'Siswa B', $this->f['classB']);
        $created = Device::create('Tablet R-01', $this->f['room'], $this->f['super']);
        $this->device = Device::pair($created['code'], '127.0.0.1')['device'];
    }

    private function scan(string $code, string $time, ?string $scanId = null): array
    {
        return ScanService::scan($this->device, ['code' => $code, 'scan_id' => $scanId], '2026-11-16 ' . $time);
    }

    public function testPresentVsLateAtMinuteBoundaries(): void
    {
        $session = ['kind' => 'jadwal', 'starts_at' => '2026-11-16 07:00:00', 'late_after' => '2026-11-16 07:10:00'];
        $this->assertSame(['H', 0], ScanService::judge($session, '2026-11-16 06:55:00'), 'sebelum mulai');
        $this->assertSame(['H', 0], ScanService::judge($session, '2026-11-16 07:00:00'), 'menit ke-0');
        $this->assertSame(['H', 0], ScanService::judge($session, '2026-11-16 07:10:00'), 'menit ke-10 (toleransi)');
        $this->assertSame(['H', 0], ScanService::judge($session, '2026-11-16 07:10:59'), 'masih menit ke-10');
        $this->assertSame(['T', 11], ScanService::judge($session, '2026-11-16 07:11:00'), 'menit ke-11: terlambat 11 menit');
        $this->assertSame(['T', 44], ScanService::judge($session, '2026-11-16 07:44:30'));
        $this->assertSame(['H', 0], ScanService::judge(['kind' => 'harian_pulang'] + $session, '2026-11-16 07:44:30'), 'pulang tidak pernah terlambat');
    }

    public function testScanFlowOkDupWrongClassInvalidAndLog(): void
    {
        $r = $this->scan($this->cardA, '07:05:00', 'scan-0000-0001');
        $this->assertTrue($r['ok']);
        $this->assertSame('ok', $r['state']);
        $this->assertSame('H', $r['status']);
        $this->assertSame('Siswa A', $r['student']['name']);
        $this->assertSame('X-A', $r['student']['class']);
        $this->assertSame('Matematika · X-A · Jam 1–2', $r['session']['label']);
        $this->assertArrayHasKey('t_server_ms', $r);

        $dup = $this->scan($this->cardA, '07:20:00', 'scan-0000-0002');
        $this->assertSame('dup', $dup['state']);
        $this->assertSame('Sudah tercatat 07:05', $dup['message']);
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM attendances'), 'duplikat tidak menambah baris');

        $late = $this->scan($this->cardA2, '07:23:10');
        $this->assertSame('late', $late['state']);
        $this->assertSame('T', $late['status']);
        $this->assertSame(23, $late['late_minutes']);
        $this->assertSame('Terlambat 23 menit', $late['message']);

        $wc = $this->scan($this->cardB, '07:06:00');
        $this->assertFalse($wc['ok']);
        $this->assertSame('wrongclass', $wc['state']);
        $this->assertSame('Bukan kelas X-A', $wc['message']);
        $this->assertSame('X-B', $wc['student']['class']);

        $inv = $this->scan('ZZZZZZZZZZZZ', '07:06:00');
        $this->assertSame('invalid', $inv['state']);
        $this->assertSame('invalid', $this->scan('bukan kode', '07:06:00')['state']);
        Card::revoke((int) DB::value('SELECT id FROM cards WHERE code = ?', [$this->cardB]), 'hilang');
        $this->assertSame('invalid', $this->scan($this->cardB, '07:06:00')['state'], 'kartu dicabut tidak berlaku');

        $this->assertSame(2, (int) DB::value('SELECT COUNT(*) FROM attendances'), 'hanya A dan A2 yang tersimpan');
        $this->assertSame(7, (int) DB::value('SELECT COUNT(*) FROM scan_logs'), 'semua scan tercatat di log, termasuk yang ditolak');
        $this->assertSame(['dup', 'invalid', 'invalid', 'invalid', 'late', 'ok', 'wrongclass'], array_column(DB::all('SELECT result FROM scan_logs ORDER BY result'), 'result'));
    }

    public function testNoSessionOutsideScheduleAndNothingSaved(): void
    {
        $r = $this->scan($this->cardA, '11:00:00');
        $this->assertSame('nosession', $r['state']);
        $this->assertSame('Siswa A', $r['student']['name'], 'nama tetap ditampilkan agar petugas tahu kartunya terbaca');
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM attendances'));
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM sessions'), 'tidak ada sesi yang dibuat');
        $r2 = ScanService::scan(['room_id' => null] + $this->device, ['code' => $this->cardA], '2026-11-16 07:05:00');
        $this->assertSame('nosession', $r2['state'], 'perangkat tanpa ruangan');
    }

    public function testIdempotentScanId(): void
    {
        $a = $this->scan($this->cardA, '07:05:00', 'abcd-1234-efgh');
        $b = $this->scan($this->cardA, '07:30:00', 'abcd-1234-efgh');
        $this->assertSame('ok', $b['state'], 'pengiriman ulang memberi jawaban yang sama, bukan duplikat');
        $this->assertTrue($b['replayed']);
        $this->assertSame($a['scanned_at'], $b['scanned_at']);
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM scan_logs'));
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM attendances'));
    }

    public function testFiftyScansOfSameStudentYieldOneRecord(): void
    {
        $states = [];
        for ($i = 0; $i < 50; $i++) {
            $states[] = $this->scan($this->cardA, '07:05:0' . ($i % 10))['state'];
        }
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM attendances WHERE student_id = ?', [$this->studentA]));
        $this->assertSame(['dup' => 49, 'ok' => 1], array_count_values($states) ? ['dup' => count(array_keys($states, 'dup', true)), 'ok' => count(array_keys($states, 'ok', true))] : []);
    }

    public function testAttendanceInsertIsAtomicOnUniqueKey(): void
    {
        $s = SessionResolver::resolve($this->f['room'], '2026-11-16 07:05:00')[0];
        $row = ['session_id' => (int) $s['id'], 'student_id' => $this->studentA, 'class_id' => $this->f['classA'], 'status' => 'H', 'scanned_at' => now()];
        $this->assertNotNull(Attendance::tryInsert($row));
        $this->assertNull(Attendance::tryInsert($row), 'baris kedua ditolak UNIQUE(session, student)');
    }

    public function testCloseSessionMarksMissingAsAlpaAndReopenRemovesThem(): void
    {
        $this->scan($this->cardA, '07:05:00');
        $s = Session::find((int) DB::value('SELECT id FROM sessions'));
        $this->assertSame(0, SessionCloser::closeExpired('2026-11-16 08:49:00'), 'masih dalam masa tenggang 5 menit setelah berakhir');
        $this->assertSame(1, SessionCloser::closeExpired('2026-11-16 08:50:01'));
        $counts = Attendance::countBySession((int) $s['id']);
        $this->assertSame(1, $counts['H']);
        $this->assertSame(1, $counts['A'], 'A2 belum scan → Alpa; B bukan anggota kelas');
        $this->assertSame('closed', Session::find((int) $s['id'])['status']);
        $this->assertSame('nosession', $this->scan($this->cardA, '08:30:00')['state'], 'sesi yang sudah ditutup tidak menerima scan lagi');
    }

    public function testDevicePairingTokenLifecycle(): void
    {
        $c = Device::create('HP Kelas', $this->f['room'], $this->f['super']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $c['code']);
        $this->assertNull(Device::pair('000000', '1.1.1.1'), 'kode salah');
        $res = Device::pair($c['code'], '1.1.1.1', '3.0');
        $this->assertNotNull($res);
        $this->assertSame(64, strlen($res['token']));
        $this->assertNull(Device::pair($c['code'], '1.1.1.1'), 'kode hanya sekali pakai');
        $this->assertNull(DB::value('SELECT pair_code FROM devices WHERE id = ?', [$c['id']]));
        $this->assertNotSame($res['token'], DB::value('SELECT token_hash FROM devices WHERE id = ?', [$c['id']]), 'token tidak disimpan mentah');
        $d = Device::byToken($res['token']);
        $this->assertSame('active', $d['status']);
        Device::revoke((int) $d['id']);
        $this->assertNull(Device::byToken($res['token']), 'token dicabut tidak dikenali lagi');
        // kedaluwarsa
        $c2 = Device::create('Lama', $this->f['room'], null);
        DB::update('devices', ['pair_expires_at' => '2020-01-01 00:00:00'], 'id = ?', [$c2['id']]);
        $this->assertNull(Device::pair($c2['code'], '1.1.1.1'), 'kode kedaluwarsa');
    }
}
