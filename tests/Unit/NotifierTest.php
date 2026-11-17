<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database as DB;
use App\Models\Notification;
use App\Models\Session;
use App\Models\Setting;
use App\Services\AttendanceRules;
use App\Services\Notifier;
use App\Services\Phone;
use App\Services\ScanService;
use App\Services\SessionCloser;
use App\Services\WaGateway;
use PHPUnit\Framework\TestCase;

/**
 * Fase 6: notifikasi WhatsApp. Uji utama PRD: gateway sandbox, 100 siswa alpa → satu pesan per siswa per hari,
 * tidak ada yang ganda, gagal kirim dicoba ulang.
 */
final class NotifierTest extends TestCase
{
    private array $f;
    /** @var array<int, array{phone:string, message:string}> */
    private array $sent = [];
    private const DATE = '2026-11-16';

    protected function setUp(): void
    {
        $this->f = Fixture::base();
        Setting::setMany(['wa_notify' => 'both', 'wa_provider' => 'sandbox', 'school_name' => 'SMA Uji', 'late_tolerance_minutes' => '10']);
        $this->sent = [];
        WaGateway::$fake = function (string $phone, string $message): array {
            $this->sent[] = ['phone' => $phone, 'message' => $message];
            return ['ok' => true, 'ref' => 'fake-' . count($this->sent)];
        };
    }

    protected function tearDown(): void
    {
        WaGateway::$fake = null;
    }

    private function student(int $class, string $nis, ?string $phone = '081234500000'): int
    {
        $id = DB::insert('students', ['nis' => $nis, 'full_name' => 'Siswa ' . $nis, 'gender' => 'P', 'parent_phone' => $phone, 'status' => 'Aktif', 'created_at' => now(), 'updated_at' => now()]);
        DB::insert('class_members', ['class_id' => $class, 'student_id' => $id, 'academic_year_id' => $this->f['year'], 'created_at' => now()]);
        return $id;
    }

    private function session(int $class, string $date = self::DATE, string $status = 'open'): array
    {
        $id = DB::insert('sessions', ['slot_key' => 't:m:' . $class . ':' . $date, 'class_id' => $class, 'room_id' => $this->f['room'], 'session_date' => $date,
            'scan_from' => $date . ' 06:00:00', 'starts_at' => $date . ' 07:00:00', 'late_after' => $date . ' 07:10:00', 'ends_at' => $date . ' 07:45:00',
            'kind' => 'harian_masuk', 'status' => $status, 'created_at' => now()]);
        return Session::findLight($id);
    }

    public function testPhoneNormalize(): void
    {
        $this->assertSame('6281234567890', Phone::normalize('0812-3456-7890'));
        $this->assertSame('6281234567890', Phone::normalize('+62 812 3456 7890'));
        $this->assertSame('6281234567890', Phone::normalize('81234567890'));
        $this->assertNull(Phone::normalize('021-555'));
        $this->assertNull(Phone::normalize(''));
        $this->assertSame('0812****7890', Phone::mask('6281234567890'));
    }

    public function testHundredAbsentStudentsOneMessageEachNoDuplicatesRetried(): void
    {
        $ids = [];
        for ($i = 1; $i <= 100; $i++) {
            $ids[] = $this->student($this->f['classA'], sprintf('A%03d', $i), sprintf('0812%07d', $i));
        }
        $s = $this->session($this->f['classA']);
        SessionCloser::close($s, null);

        // Antrean dijalankan berkali-kali (cron tiap 5 menit + cadangan kiosk) → tetap satu baris per siswa
        $now = self::DATE . ' 08:00:00';
        $this->assertSame(100, Notifier::queueDailyAbsent(self::DATE, $now));
        $this->assertSame(0, Notifier::queueDailyAbsent(self::DATE, $now));
        $this->assertSame(0, Notifier::queueDailyAbsent(self::DATE, $now));

        // Gateway sandbox gagal untuk sebagian nomor pada percobaan pertama (gangguan jaringan) → dicoba ulang
        $calls = [];
        WaGateway::$fake = function (string $phone, string $message) use (&$calls): array {
            $calls[$phone] = ($calls[$phone] ?? 0) + 1;
            $flaky = ((int) substr($phone, -2)) % 4 === 0;  // 25 siswa
            if ($flaky && $calls[$phone] < 3) {
                return ['ok' => false, 'error' => 'timeout', 'retry' => true];
            }
            $this->sent[] = ['phone' => $phone, 'message' => $message];
            return ['ok' => true, 'ref' => 'x'];
        };
        $r1 = Notifier::sendPending(500, $now);
        $this->assertSame(['sent' => 75, 'failed' => 0, 'retry' => 25, 'skipped' => 0], $r1);
        $this->assertSame(['sent' => 0, 'failed' => 0, 'retry' => 0, 'skipped' => 0], Notifier::sendPending(500, $now), 'belum jatuh tempo: tidak dikirim lagi');
        $r2 = Notifier::sendPending(500, date('Y-m-d H:i:s', strtotime($now) + 61));
        $this->assertSame(25, $r2['retry'], 'percobaan ke-2 masih gagal, dijadwalkan lagi');
        $r3 = Notifier::sendPending(500, date('Y-m-d H:i:s', strtotime($now) + 61 + 301));
        $this->assertSame(25, $r3['sent'], 'percobaan ke-3 berhasil');

        // Hasil: tepat satu pesan terkirim per siswa, tidak ada nomor yang menerima dua pesan
        $phones = array_column($this->sent, 'phone');
        $this->assertCount(100, $phones);
        $this->assertCount(100, array_unique($phones), 'tidak ada pesan ganda');
        $this->assertSame(100, (int) DB::value("SELECT COUNT(*) FROM notifications WHERE status = 'sent' AND notify_date = ?", [self::DATE]));
        $this->assertSame(100, (int) DB::value('SELECT COUNT(DISTINCT student_id) FROM notifications WHERE notify_date = ?', [self::DATE]));
        $this->assertStringContainsString('Alpa', $this->sent[0]['message']);
        $this->assertStringContainsString('SMA Uji', $this->sent[0]['message']);

        // Antrean dijalankan lagi setelah semua terkirim → tidak ada pesan baru
        Notifier::run(self::DATE . ' 12:00:00');
        $this->assertCount(100, $this->sent);
    }

    public function testFailsPermanentlyAfterMaxAttemptsAndCanBeRetried(): void
    {
        $sid = $this->student($this->f['classA'], 'F01');
        $s = $this->session($this->f['classA']);
        SessionCloser::close($s, null);
        Notifier::queueDailyAbsent(self::DATE, self::DATE . ' 08:00:00');
        WaGateway::$fake = static fn () => ['ok' => false, 'error' => 'HTTP 503', 'retry' => true];
        $t = strtotime(self::DATE . ' 08:00:00');
        for ($i = 0; $i < Notifier::MAX_ATTEMPTS; $i++) {
            Notifier::sendPending(10, date('Y-m-d H:i:s', $t));
            $t += 3700;
        }
        $n = Notification::forStudentDate($sid, self::DATE);
        $this->assertSame(['failed', Notifier::MAX_ATTEMPTS], [$n['status'], (int) $n['attempts']]);
        $this->assertSame(1, Notification::retryFailed());
        $this->assertSame('pending', Notification::forStudentDate($sid, self::DATE)['status']);

        // Token salah (4xx) tidak dicoba ulang
        Notifier::sendPending(10, date('Y-m-d H:i:s', $t));
        WaGateway::$fake = static fn () => ['ok' => false, 'error' => 'HTTP 401', 'retry' => false];
        Notification::retryFailed();
        DB::run("UPDATE notifications SET status = 'pending', attempts = 0, next_attempt_at = NULL");
        Notifier::sendPending(10, date('Y-m-d H:i:s', $t + 10));
        $n = Notification::forStudentDate($sid, self::DATE);
        $this->assertSame(['failed', 1], [$n['status'], (int) $n['attempts']]);
    }

    public function testLateScanQueuesOneMessageAndRepeatScanDoesNot(): void
    {
        $sid = $this->student($this->f['classA'], 'T01');
        $s = $this->session($this->f['classA']);
        ScanService::record($s, $sid, $this->f['classA'], self::DATE . ' 07:20:00', null, null);
        ScanService::record($s, $sid, $this->f['classA'], self::DATE . ' 07:21:00', null, null);
        $rows = DB::all('SELECT * FROM notifications');
        $this->assertCount(1, $rows);
        $this->assertSame(['T', 'pending'], [$rows[0]['kind'], $rows[0]['status']]);
        $this->assertStringContainsString('20 menit', $rows[0]['message']);
        $this->assertStringContainsString('07:20', $rows[0]['message']);
        SessionCloser::close($s, null);
        $this->assertSame(0, Notifier::queueDailyAbsent(self::DATE, self::DATE . ' 09:00:00'), 'siswa terlambat tidak mendapat pesan Alpa');
        $this->assertSame(1, Notifier::sendPending(10, self::DATE . ' 09:00:00')['sent']);
    }

    public function testOnTimeScanAndDisabledModeQueueNothing(): void
    {
        $a = $this->student($this->f['classA'], 'H01');
        $s = $this->session($this->f['classA']);
        ScanService::record($s, $a, $this->f['classA'], self::DATE . ' 07:05:00', null, null);
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM notifications'));

        Setting::set('wa_notify', 'off');
        $b = $this->student($this->f['classA'], 'H02');
        ScanService::record($s, $b, $this->f['classA'], self::DATE . ' 07:30:00', null, null);
        SessionCloser::close($s, null);
        $this->assertSame(0, Notifier::queueDailyAbsent(self::DATE, self::DATE . ' 09:00:00'));
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM notifications'), 'mati secara bawaan: tidak ada antrean');

        Setting::set('wa_notify', 'terlambat');
        $this->assertSame(0, Notifier::queueDailyAbsent(self::DATE, self::DATE . ' 09:00:00'), 'mode Hanya Terlambat: Alpa tidak diantrekan');
    }

    public function testOpenSessionNotQueuedAndChangedStatusSkipped(): void
    {
        $a = $this->student($this->f['classA'], 'S01');
        $b = $this->student($this->f['classA'], 'S02');
        $c = $this->student($this->f['classA'], 'S03', null);
        $s = $this->session($this->f['classA']);
        $this->assertSame(0, Notifier::queueDailyAbsent(self::DATE, self::DATE . ' 07:30:00'), 'sesi belum ditutup');
        SessionCloser::close($s, null);
        $this->assertSame(3, Notifier::queueDailyAbsent(self::DATE, self::DATE . ' 08:00:00'));
        $this->assertSame('skipped', Notification::forStudentDate($c, self::DATE)['status'], 'tanpa nomor HP: dilewati');

        // Sebelum terkirim: wali kelas mengoreksi a menjadi Sakit; b tersinkron scan offline (Terlambat)
        AttendanceRules::correct($s, $a, 'S', 'Surat dokter menyusul', $this->f['teacher']);
        ScanService::record($s, $b, $this->f['classA'], self::DATE . ' 07:25:00', null, null);
        $nb = Notification::forStudentDate($b, self::DATE);
        $this->assertSame(['T', 'pending'], [$nb['kind'], $nb['status']], 'pesan Alpa yang belum terkirim diganti Terlambat');

        $r = Notifier::sendPending(10, self::DATE . ' 08:05:00');
        $this->assertSame(['sent' => 1, 'failed' => 0, 'retry' => 0, 'skipped' => 1], $r);
        $this->assertSame('skipped', Notification::forStudentDate($a, self::DATE)['status']);
        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('terlambat', $this->sent[0]['message']);
    }

    public function testConcurrentClaimSendsOnce(): void
    {
        $sid = $this->student($this->f['classA'], 'C01');
        $s = $this->session($this->f['classA']);
        SessionCloser::close($s, null);
        Notifier::queueDailyAbsent(self::DATE, self::DATE . ' 08:00:00');
        // Proses lain sudah mengklaim baris ini
        DB::run("UPDATE notifications SET status = 'sending', claimed_at = ?", [self::DATE . ' 08:00:00']);
        $this->assertSame(0, Notifier::sendPending(10, self::DATE . ' 08:01:00')['sent']);
        // Proses itu mati: setelah 10 menit baris dikembalikan ke antrean dan dikirim sekali
        $this->assertSame(1, Notifier::sendPending(10, self::DATE . ' 08:11:00')['sent']);
        $this->assertSame(0, Notifier::sendPending(10, self::DATE . ' 08:20:00')['sent']);
        $this->assertCount(1, $this->sent);
    }

    public function testSandboxWritesLogAndTemplateRender(): void
    {
        WaGateway::$fake = null;
        $log = WaGateway::sandboxLog();
        $before = is_file($log) ? count(file($log)) : 0;
        $r = WaGateway::send('6281200000001', 'uji');
        $this->assertTrue($r['ok']);
        $this->assertSame($before + 1, count(file($log)));
        $this->assertSame('Halo Ani, X-A', Notifier::render('Halo {nama}, {kelas}', ['nama' => 'Ani', 'kelas' => 'X-A']));
    }
}
