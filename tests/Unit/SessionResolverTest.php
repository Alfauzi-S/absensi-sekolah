<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Master;
use App\Models\Schedule;
use App\Models\Session;
use App\Models\Setting;
use App\Services\SessionResolver;
use PHPUnit\Framework\TestCase;

/**
 * Penentu sesi (PRD Fase 3): batas jam, pergantian jam, hari libur, ruangan jadwal vs ruang kelas, mode masuk/pulang.
 * Fixture: jam 1–4 = 07:00–07:45, 08:00–08:45, 09:00–09:45, 10:00–10:45. 2026-11-16 = Senin, 2026-11-15 = Minggu.
 */
final class SessionResolverTest extends TestCase
{
    private array $f;
    private int $room2;

    protected function setUp(): void
    {
        $this->f = Fixture::base();
        Setting::set('attendance_mode', Setting::ATT_MODE_PERIOD);
        Setting::set('late_tolerance_minutes', '10');
        $this->room2 = (int) \App\Core\Database::value("SELECT id FROM rooms WHERE code = 'R-02'");
        // MTK kelas X-A Senin jam 1–2 di R-01 (ruangan jadwal); BIND kelas X-B Senin jam 3–4 tanpa ruangan → X-B tidak punya ruang kelas
        Schedule::create(['semester_id' => $this->f['semester'], 'class_id' => $this->f['classA'], 'subject_id' => $this->f['mtk'], 'teacher_id' => $this->f['teacher'], 'room_id' => $this->f['room'], 'day_of_week' => 1, 'period_start' => 1, 'period_end' => 2]);
    }

    private function specs(string $time, ?int $room = null): array
    {
        return SessionResolver::specs($room ?? $this->f['room'], '2026-11-16 ' . $time);
    }

    public function testPeriodBoundaries(): void
    {
        $this->assertSame([], $this->specs('06:49:59'), 'sebelum masa tenggang 10 menit: tidak ada sesi');
        $s = $this->specs('06:50:00');
        $this->assertCount(1, $s, 'masa tenggang sebelum jam mulai: sesi sudah berlaku');
        $this->assertSame('2026-11-16 07:00:00', $s[0]['starts_at']);
        $this->assertSame('2026-11-16 07:10:00', $s[0]['late_after']);
        $this->assertSame('2026-11-16 08:45:00', $s[0]['ends_at'], 'jam 1–2 berakhir di akhir jam ke-2');
        $this->assertSame('jadwal', $s[0]['kind']);
        $this->assertSame($this->f['classA'], $s[0]['class_id']);
        $this->assertSame($this->f['teacher'], $s[0]['teacher_id']);
        $this->assertCount(1, $this->specs('07:00:00'));
        $this->assertCount(1, $this->specs('08:44:59'));
        $this->assertSame([], $this->specs('08:45:00'), 'tepat di akhir jam: sesi sudah tidak menerima scan');
    }

    public function testPeriodChangeAndCurrentPrecedesUpcoming(): void
    {
        // Jam 3–4 kelas X-B di ruangan yang sama (R-01)
        Schedule::create(['semester_id' => $this->f['semester'], 'class_id' => $this->f['classB'], 'subject_id' => $this->f['mtk'], 'teacher_id' => $this->f['teacher2'], 'room_id' => $this->f['room'], 'day_of_week' => 1, 'period_start' => 3, 'period_end' => 4]);
        $this->assertSame($this->f['classA'], $this->specs('08:40:00')[0]['class_id'], 'jam 1–2 masih berjalan');
        $s = $this->specs('08:50:00');
        $this->assertCount(1, $s);
        $this->assertSame($this->f['classB'], $s[0]['class_id'], 'jeda antar jam: sesi berikutnya (tenggang 10 menit) yang berlaku');
        $this->assertSame($this->f['classB'], $this->specs('09:00:00')[0]['class_id']);
        $this->assertSame($this->f['classB'], $this->specs('10:44:00')[0]['class_id']);
        $this->assertSame([], $this->specs('10:45:00'));
    }

    public function testHolidayWeekendAndOtherDay(): void
    {
        $this->assertSame([], SessionResolver::specs($this->f['room'], '2026-11-15 07:05:00'), 'Minggu (akhir pekan)');
        $this->assertSame([], SessionResolver::specs($this->f['room'], '2026-11-17 07:05:00'), 'Selasa: tidak ada jadwal');
        Master::create('libur', ['holiday_date' => '2026-11-16', 'name' => 'Libur uji']);
        $this->assertSame([], $this->specs('07:05:00'), 'hari libur: tidak ada sesi walau ada jadwal');
    }

    public function testScheduleWithoutRoomFallsBackToClassRoom(): void
    {
        \App\Core\Database::run('DELETE FROM schedules');
        Schedule::create(['semester_id' => $this->f['semester'], 'class_id' => $this->f['classA'], 'subject_id' => $this->f['mtk'], 'teacher_id' => null, 'room_id' => null, 'day_of_week' => 1, 'period_start' => 1, 'period_end' => 1]);
        $this->assertCount(1, $this->specs('07:05:00'), 'jadwal tanpa ruangan memakai ruang kelas (X-A di R-01)');
        $this->assertSame([], $this->specs('07:05:00', $this->room2), 'ruangan lain tidak dapat sesi');
    }

    public function testResolveCreatesSessionOnceAndSkipsCancelled(): void
    {
        $a = SessionResolver::resolve($this->f['room'], '2026-11-16 07:05:00');
        $b = SessionResolver::resolve($this->f['room'], '2026-11-16 07:30:00');
        $this->assertCount(1, $a);
        $this->assertSame($a[0]['id'], $b[0]['id'], 'sesi yang sama dipakai ulang (slot_key)');
        $this->assertSame(1, Session::countOnDate('2026-11-16'));
        Session::setStatus((int) $a[0]['id'], 'cancelled', null, 'Jam kosong');
        $this->assertSame([], SessionResolver::resolve($this->f['room'], '2026-11-16 07:40:00'), 'jam kosong: scanner tidak punya sesi');
        $this->assertSame(1, Session::countOnDate('2026-11-16'), 'tidak dibuat sesi baru untuk slot yang dibatalkan');
    }

    public function testExtraSessionInRoomIsResolved(): void
    {
        $x = Session::createOrGet(['slot_key' => null, 'class_id' => $this->f['classB'], 'room_id' => $this->f['room'], 'session_date' => '2026-11-17',
            'scan_from' => '2026-11-17 13:50:00', 'starts_at' => '2026-11-17 14:00:00', 'late_after' => '2026-11-17 14:10:00', 'ends_at' => '2026-11-17 15:00:00', 'kind' => 'tambahan', 'note' => 'Pramuka', 'opened_by' => $this->f['teacher2']]);
        $r = SessionResolver::resolve($this->f['room'], '2026-11-17 14:05:00');
        $this->assertCount(1, $r);
        $this->assertSame($x['id'], $r[0]['id']);
        $this->assertSame('Pramuka · X-B', SessionResolver::label($r[0]));
        $this->assertSame([], SessionResolver::resolve($this->f['room'], '2026-11-17 15:00:00'));
    }

    public function testDailyModeCheckinAndCheckout(): void
    {
        Setting::setMany(['attendance_mode' => Setting::ATT_MODE_DAILY, 'checkin_start' => '06:00', 'checkin_end' => '07:30', 'late_after' => '07:00', 'checkout_start' => '14:00', 'checkout_end' => '16:00']);
        $this->assertSame([], $this->specs('05:59:00'));
        $s = $this->specs('06:30:00');
        $this->assertCount(1, $s);
        $this->assertSame('harian_masuk', $s[0]['kind']);
        $this->assertSame($this->f['classA'], $s[0]['class_id'], 'kelas yang menempati R-01');
        $this->assertSame('2026-11-16 07:00:00', $s[0]['starts_at']);
        $this->assertSame('2026-11-16 07:10:00', $s[0]['late_after']);
        $this->assertSame([], $this->specs('07:30:00'), 'jendela masuk tutup');
        $p = $this->specs('14:00:00');
        $this->assertSame('harian_pulang', $p[0]['kind']);
        $this->assertSame([], $this->specs('16:00:00'));
        $this->assertSame([], $this->specs('06:30:00', $this->room2), 'tidak ada kelas di R-02');
    }

    public function testBothModeReturnsPeriodAndDaily(): void
    {
        Setting::setMany(['attendance_mode' => Setting::ATT_MODE_BOTH, 'checkin_start' => '06:00', 'checkin_end' => '07:30']);
        $kinds = array_column($this->specs('07:05:00'), 'kind');
        sort($kinds);
        $this->assertSame(['harian_masuk', 'jadwal'], $kinds);
    }
}
