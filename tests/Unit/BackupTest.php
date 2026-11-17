<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database as DB;
use App\Models\Setting;
use App\Services\Backup;
use App\Services\Maintenance;
use PHPUnit\Framework\TestCase;

/** Fase 7: cadangan harian dan pemulihan (pindah server). */
final class BackupTest extends TestCase
{
    private string $store;

    protected function setUp(): void
    {
        Fixture::base();
        $this->store = sys_get_temp_dir() . '/absensi-bk-' . bin2hex(random_bytes(3));
        mkdir($this->store . '/photos/1', 0777, true);
        mkdir($this->store . '/leaves', 0777, true);
        Backup::$storage = $this->store;
    }

    protected function tearDown(): void
    {
        Backup::$storage = null;
        exec('rm -rf ' . escapeshellarg($this->store));
    }

    public function testRoundTripRestoresEveryRowAndFile(): void
    {
        $f = Fixture::base();
        $sid = DB::insert('students', ['nis' => '7001', 'full_name' => "Siti 'Aisyah\" — ñ 🙂", 'gender' => 'P', 'parent_phone' => '0812', 'status' => 'Aktif', 'created_at' => now(), 'updated_at' => now()]);
        DB::insert('class_members', ['class_id' => $f['classA'], 'student_id' => $sid, 'academic_year_id' => $f['year'], 'created_at' => now()]);
        for ($i = 0; $i < 4500; $i++) { // lebih dari satu potongan (2.000 baris)
            DB::insert('audit_logs', ['user_id' => null, 'action' => 'uji', 'result' => 'ok', 'ip' => '127.0.0.1', 'created_at' => now()]);
        }
        Setting::set('school_name', 'SMA Cadangan');
        file_put_contents($this->store . '/photos/1/' . $sid . '.jpg', 'FOTO');
        file_put_contents($this->store . '/leaves/1-abcdef123456.pdf', '%PDF');
        $before = $this->snapshot();

        $b = Backup::create();
        $this->assertFileExists($b['path']);
        $this->assertSame(2, $b['files']);
        $m = Backup::manifest($b['path']);
        $this->assertSame((int) DB::value('SELECT COUNT(*) FROM audit_logs'), $m['tables']['audit_logs']);
        $this->assertGreaterThanOrEqual(4500, $m['tables']['audit_logs']);

        // Bencana: data dihapus/diubah, file hilang
        DB::run('DELETE FROM audit_logs');
        DB::run('UPDATE students SET full_name = ?', ['Rusak']);
        DB::insert('students', ['nis' => '9999', 'full_name' => 'Baru', 'gender' => 'L', 'status' => 'Aktif', 'created_at' => now(), 'updated_at' => now()]);
        exec('rm -rf ' . escapeshellarg($this->store . '/photos'));

        $r = Backup::restore($b['path']);
        $this->assertSame([], $r['mismatch']);
        $this->assertSame($before, $this->snapshot(), 'semua tabel kembali persis');
        $this->assertSame('FOTO', file_get_contents($this->store . '/photos/1/' . $sid . '.jpg'));
        $this->assertSame('%PDF', file_get_contents($this->store . '/leaves/1-abcdef123456.pdf'));
        $this->assertSame('SMA Cadangan', Setting::get('school_name'));
        $this->assertSame([], \App\Core\Migrator::pending());
        // Aplikasi tetap bisa menulis setelah pemulihan (autoincrement lanjut)
        $id = DB::insert('students', ['nis' => '7002', 'full_name' => 'Setelah', 'gender' => 'L', 'status' => 'Aktif', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertGreaterThan($sid, $id);
    }

    public function testPruneKeepsNewestAndRejectsForeignFiles(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            touch(Backup::dir() . sprintf('/absensi-2026110%d-013000.zip', $i), strtotime("2026-11-0$i 01:30"));
        }
        $this->assertSame(2, Backup::prune(3));
        $this->assertSame(['absensi-20261105-013000.zip', 'absensi-20261104-013000.zip', 'absensi-20261103-013000.zip'], array_column(Backup::list(), 'name'));
        $this->assertNull(Backup::path('../config.php'));
        $this->assertNull(Backup::path('absensi-20261105-013000.zip.php'));
        $this->assertNotNull(Backup::path('absensi-20261105-013000.zip'));
        file_put_contents(Backup::dir() . '/absensi-20261106-000000.zip', 'bukan zip');
        $this->expectException(\RuntimeException::class);
        Backup::manifest(Backup::dir() . '/absensi-20261106-000000.zip');
    }

    public function testDailyBackupDueOncePerDayAfterBackupTime(): void
    {
        Setting::set('backup_time', '01:30');
        $this->assertFalse(Maintenance::backupDue('2026-11-17 01:00:00'));
        $this->assertTrue(Maintenance::backupDue('2026-11-17 01:35:00'));
        Setting::set('backup_last_at', '2026-11-17 01:35:00');
        $this->assertFalse(Maintenance::backupDue('2026-11-17 13:00:00'));
        $this->assertTrue(Maintenance::backupDue('2026-11-18 01:31:00'));
        // Server mati saat jam cadangan: dibuat begitu menyala lagi pada hari itu
        $this->assertTrue(Maintenance::backupDue('2026-11-19 09:00:00'));
    }

    public function testTickRecordsHeartbeatAndBackup(): void
    {
        $r = Maintenance::tick(date('Y-m-d') . ' 23:59:00');
        $this->assertArrayHasKey('backup', $r);
        $this->assertStringStartsWith('absensi-', $r['backup']['path']);
        $this->assertNotSame('', Setting::get('scheduler_last_at'));
        $this->assertArrayNotHasKey('backup', Maintenance::tick(date('Y-m-d') . ' 23:59:30'), 'sekali sehari');
    }

    /** @return array<string, string> tabel => hash isi */
    private function snapshot(): array
    {
        $out = [];
        foreach (DB::tables() as $t) {
            if (in_array($t, ['rate_limits', 'schema_migrations'], true)) {
                continue;
            }
            $out[$t] = md5(json_encode(DB::all('SELECT * FROM ' . DB::ident($t) . ' ORDER BY 1')));
        }
        return $out;
    }
}
