<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database as DB;
use App\Models\Attendance;
use App\Models\Card;
use App\Models\Device;
use App\Models\ScanMetric;
use App\Models\Schedule;
use App\Models\Session;
use App\Models\Setting;
use App\Services\Roster;
use App\Services\ScanService;
use App\Services\SessionCloser;
use PHPUnit\Framework\TestCase;

/** Fase 4: batch idempoten, jam scan = jam saat scan (dikoreksi selisih jam perangkat), sinkron setelah sesi ditutup, roster, metrik. */
final class ScanBatchTest extends TestCase
{
    private array $f;
    private array $device;
    /** @var array<int, array{0:int,1:string}> */
    private array $students = [];
    private string $cardB;

    protected function setUp(): void
    {
        $this->f = Fixture::base();
        Setting::set('attendance_mode', Setting::ATT_MODE_PERIOD);
        Setting::set('late_tolerance_minutes', '10');
        // Senin 2026-11-16 jam 1–2 (07:00–08:45) kelas X-A di R-01
        Schedule::create(['semester_id' => $this->f['semester'], 'class_id' => $this->f['classA'], 'subject_id' => $this->f['mtk'], 'teacher_id' => $this->f['teacher'], 'room_id' => $this->f['room'], 'day_of_week' => 1, 'period_start' => 1, 'period_end' => 2]);
        $mk = function (string $nis, int $class): array {
            $id = DB::insert('students', ['nis' => $nis, 'full_name' => 'Siswa ' . $nis, 'gender' => 'L', 'status' => 'Aktif', 'created_at' => now(), 'updated_at' => now()]);
            DB::insert('class_members', ['class_id' => $class, 'student_id' => $id, 'academic_year_id' => $this->f['year'], 'created_at' => now()]);
            return [$id, Card::issue($id, null)['code']];
        };
        for ($i = 1; $i <= 5; $i++) {
            $this->students[] = $mk('10' . $i, $this->f['classA']);
        }
        $this->cardB = $mk('201', $this->f['classB'])[1];
        $this->device = Device::pair(Device::create('Tablet', $this->f['room'], null)['code'], '127.0.0.1')['device'];
    }

    private function item(int $i, string $time, string $tz = '+08:00'): array
    {
        return ['scan_id' => 'offline-scan-000' . $i, 'code' => $this->students[$i][1], 'scanned_at' => '2026-11-16T' . $time . $tz];
    }

    public function testSameBatchThreeTimesKeepsOneRecordPerScan(): void
    {
        $body = ['sent_at' => '2026-11-16T07:30:00+08:00', 'scans' => [$this->item(0, '07:02:00'), $this->item(1, '07:05:00'), $this->item(2, '07:15:00'), $this->item(0, '07:02:00')]];
        $r1 = ScanService::batch($this->device, $body, '2026-11-16 07:30:00');
        $this->assertSame(['ok', 'ok', 'late', 'ok'], array_column($r1['results'], 'state'), 'scan_id yang sama di batch yang sama dijawab sama');
        $this->assertTrue($r1['results'][3]['replayed']);
        $r2 = ScanService::batch($this->device, $body, '2026-11-16 07:31:00');
        $r3 = ScanService::batch($this->device, $body, '2026-11-16 09:00:00');
        $this->assertSame(['ok', 'ok', 'late', 'ok'], array_column($r2['results'], 'state'));
        $this->assertSame(['ok', 'ok', 'late', 'ok'], array_column($r3['results'], 'state'), 'pengiriman ulang tidak berubah jadi duplikat');
        $this->assertSame(3, (int) DB::value('SELECT COUNT(*) FROM attendances'));
        $this->assertSame(3, (int) DB::value('SELECT COUNT(*) FROM scan_logs'));
    }

    public function testScanTimeIsTimeOfScanNotTimeOfSync(): void
    {
        // Disinkronkan 08:20, tetapi scan terjadi 07:04 (hadir) dan 07:25 (terlambat 25 menit)
        $r = ScanService::batch($this->device, ['sent_at' => '2026-11-16T08:20:00+08:00', 'scans' => [$this->item(0, '07:04:10'), $this->item(1, '07:25:40')]], '2026-11-16 08:20:00');
        $this->assertSame('ok', $r['results'][0]['state']);
        $this->assertSame('07:04', $r['results'][0]['scanned_at']);
        $this->assertSame('late', $r['results'][1]['state']);
        $this->assertSame(25, $r['results'][1]['late_minutes']);
        $this->assertSame('2026-11-16 07:04:10', DB::value('SELECT scanned_at FROM attendances WHERE student_id = ?', [$this->students[0][0]]));
        $this->assertSame(0, $r['clock_offset_ms']);
    }

    public function testDeviceClockSkewIsCorrected(): void
    {
        // Jam perangkat tertinggal 30 menit: perangkat mengira scan 06:35 dan mengirim 06:36, padahal server 07:06 → scan sebenarnya 07:05
        $r = ScanService::batch($this->device, ['sent_at' => '2026-11-16T06:36:00+08:00', 'scans' => [$this->item(0, '06:35:00')]], '2026-11-16 07:06:00');
        $this->assertSame(1800000, $r['clock_offset_ms']);
        $this->assertSame('ok', $r['results'][0]['state']);
        $this->assertSame('2026-11-16 07:05:00', DB::value('SELECT scanned_at FROM attendances'));
        // Zona waktu perangkat berbeda (UTC) tetapi jamnya benar: tidak ada koreksi, jam scan tetap tepat
        $r2 = ScanService::batch($this->device, ['sent_at' => '2026-11-15T23:20:00+00:00', 'scans' => [['scanned_at' => '2026-11-15T23:12:00+00:00'] + $this->item(1, '07:12:00')]], '2026-11-16 07:20:00');
        $this->assertSame(0, $r2['clock_offset_ms']);
        $this->assertSame('late', $r2['results'][0]['state']);
        $this->assertSame(12, $r2['results'][0]['late_minutes']);
        $this->assertSame(0, ScanService::clockOffsetMs('2026-11-16T07:00:01+08:00', '2026-11-16 07:00:00'), 'selisih < 2 detik diabaikan');
    }

    public function testLateSyncAfterSessionClosedOverridesAutoAlpa(): void
    {
        ScanService::scan($this->device, ['code' => $this->students[0][1]], '2026-11-16 07:03:00');
        $this->assertSame(1, SessionCloser::closeExpired('2026-11-16 09:00:00'));
        $sid = (int) DB::value('SELECT id FROM sessions');
        $this->assertSame(4, Attendance::countBySession($sid)['A'], 'empat siswa lain Alpa otomatis');
        // Tablet baru tersambung 09:30; scan siswa ke-2 terjadi 07:08 saat offline
        $r = ScanService::batch($this->device, ['sent_at' => '2026-11-16T09:30:00+08:00', 'scans' => [$this->item(1, '07:08:00'), $this->item(2, '07:40:00'), $this->item(0, '07:09:00')]], '2026-11-16 09:30:00');
        $this->assertSame(['ok', 'late', 'dup'], array_column($r['results'], 'state'));
        $c = Attendance::countBySession($sid);
        $this->assertSame(['H' => 2, 'T' => 1, 'A' => 2], ['H' => $c['H'], 'T' => $c['T'], 'A' => $c['A']], 'scan menang atas Alpa otomatis; yang tidak scan tetap Alpa');
        $this->assertSame('closed', Session::find($sid)['status'], 'sesi tetap tertutup');
        $row = Attendance::find($sid, $this->students[1][0]);
        $this->assertSame('scan', $row['method']);
        $this->assertSame('2026-11-16 07:08:00', $row['scanned_at']);
        // Scan langsung (bukan dari antrean) ke sesi yang sudah ditutup tetap ditolak
        $this->assertSame('nosession', ScanService::scan($this->device, ['code' => $this->students[3][1]], '2026-11-16 08:44:00')['state']);
    }

    public function testBatchLimitsAndTooOldScans(): void
    {
        $old = ScanService::batch($this->device, ['sent_at' => '2026-11-23T07:05:00+08:00', 'scans' => [$this->item(0, '07:05:00')]], '2026-11-23 07:05:00');
        $this->assertSame('ok', $old['results'][0]['state'], 'scan berumur > 3 hari dicatat memakai jam server (Senin berikutnya 07:05)');
        $this->assertSame('2026-11-23 07:05:00', DB::value('SELECT scanned_at FROM attendances'));
        $many = array_fill(0, 150, ['code' => 'ZZZZZZZZZZZZ']);
        $this->assertCount(ScanService::MAX_BATCH, ScanService::batch($this->device, ['scans' => $many], '2026-11-16 07:05:00')['results']);
        $this->assertSame([], ScanService::batch($this->device, ['scans' => 'bukan array'], '2026-11-16 07:05:00')['results']);
    }

    public function testDeferredLogIsWrittenOnFlush(): void
    {
        $r = ScanService::scan($this->device, ['code' => $this->students[0][1], 'scan_id' => 'deferred-0001'], '2026-11-16 07:03:00', true);
        $this->assertSame('ok', $r['state']);
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM attendances'), 'kehadiran langsung tersimpan');
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM scan_logs'), 'log menunggu sampai respons terkirim');
        ScanService::flushDeferred();
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM scan_logs'));
    }

    public function testRosterContainsScheduledClassOnly(): void
    {
        ScanService::scan($this->device, ['code' => $this->students[0][1]], '2026-11-16 07:03:00');
        $r = Roster::forRoom($this->f['room'], '2026-11-16 07:10:00');
        $this->assertSame('2026-11-16', $r['date']);
        $this->assertCount(1, $r['sessions']);
        $s = $r['sessions'][0];
        $this->assertSame('Matematika · X-A · Jam 1–2', $s['label']);
        $this->assertSame([$this->students[0][0]], $s['present'], 'yang sudah scan ikut dikirim agar duplikat terdeteksi lokal');
        $this->assertSame(strtotime('2026-11-16 07:10:00') * 1000, $s['late_ms']);
        $codes = array_column($r['students'], 0);
        $this->assertCount(5, $codes);
        $this->assertNotContains($this->cardB, $codes, 'kelas lain tidak masuk roster ruangan ini');
        Card::revoke((int) DB::value('SELECT id FROM cards WHERE code = ?', [$this->students[4][1]]), 'hilang');
        $r2 = Roster::forRoom($this->f['room'], '2026-11-16 07:11:00');
        $this->assertCount(4, $r2['students'], 'kartu dicabut hilang dari roster');
        $this->assertNotSame($r['version'], $r2['version']);
        $this->assertSame($r2['version'], Roster::forRoom($this->f['room'], '2026-11-16 07:12:00')['version'], 'versi stabil bila isi sama');
        $this->assertSame([], Roster::forRoom($this->f['room'], '2026-11-15 07:10:00')['sessions'], 'Minggu: kosong');
    }

    public function testMetricsStoredOncePerScanAndSummarized(): void
    {
        ScanService::scan($this->device, ['code' => $this->students[0][1], 'scan_id' => 'metric-scan-0001'], '2026-11-16 07:03:00');
        $m = [['scan_id' => 'metric-scan-0001', 'source' => 'usb', 'result' => 'ok', 'local' => 1, 't_decode' => 40, 't_feedback' => 12.4, 't_roundtrip' => 80, 't_total' => 95, 'queued' => 0, 'queue_wait' => 90],
              ['scan_id' => 'metric-scan-0002', 'source' => 'camera', 't_feedback' => 300, 't_total' => 'x', 'queued' => 1], 'rusak'];
        $this->assertSame(2, ScanService::batch($this->device, ['metrics' => $m], '2026-11-16 07:04:00')['metrics_saved']);
        $this->assertSame(0, ScanService::batch($this->device, ['metrics' => $m], '2026-11-16 07:05:00')['metrics_saved'], 'metrik per scan_id hanya sekali');
        $rows = ScanMetric::since('2000-01-01 00:00:00');
        $this->assertCount(2, $rows);
        $sum = ScanMetric::summarize($rows);
        $this->assertSame(2, $sum['n']);
        $this->assertSame(1, $sum['queued']);
        $this->assertSame(1, $sum['local']);
        $this->assertSame(12.0, $sum['feedback']['p50']);
        $this->assertSame(300.0, $sum['feedback']['p95']);
        $this->assertSame(1, $sum['total']['n'], 'nilai bukan angka diabaikan');
        $this->assertGreaterThan(0, $sum['server']['p95'], 't_server diambil dari log scan');
        $this->assertSame(95.0, ScanMetric::percentile([10, 20, 95, 100], 75));
    }
}
