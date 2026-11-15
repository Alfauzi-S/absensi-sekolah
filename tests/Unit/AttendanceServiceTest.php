<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database as DB;
use App\Models\Event;
use App\Models\Participant;
use App\Models\User;
use App\Services\AttendanceService;
use PHPUnit\Framework\TestCase;

/**
 * Aturan inti presensi yang dipertahankan dari repo asal dan akan dibawa ke modul absensi siswa:
 * check-in atomik, tidak ada catatan ganda, status final tidak bisa ditimpa scan.
 */
final class AttendanceServiceTest extends TestCase
{
    private array $event;
    private array $user;

    protected function setUp(): void
    {
        DB::run('DELETE FROM status_history');
        DB::run('DELETE FROM participants');
        DB::run('DELETE FROM users');
        DB::run('DELETE FROM events');
        $id = Event::create([
            'name' => 'Event Uji', 'description' => '', 'organizer' => 'TU', 'location' => 'Aula',
            'event_date' => today(), 'start_time' => '07:00', 'end_time' => '12:00',
            'reg_start' => null, 'reg_end' => null, 'att_start' => '06:30', 'att_end' => '08:00',
            'status' => 'Aktif', 'reg_status' => 'Ditutup', 'att_status' => 'open',
        ]);
        $this->event = Event::find($id);
        $uid = User::create(['name' => 'Petugas', 'username' => 'petugas', 'password' => 'Rahasia12345', 'role' => 'admin', 'event_id' => $id, 'status' => 'Aktif']);
        $this->user = User::find($uid);
    }

    private function participant(): array
    {
        return Participant::create((int) $this->event['id'], [
            'identity_number' => '2401' . random_int(1000, 9999), 'profession' => 'Pelajar', 'full_name' => 'Siswa Uji',
            'phone' => '08123456789', 'institution' => 'SMA Uji', 'field_of_study' => 'Lainnya',
        ]);
    }

    public function testExtractCodeAcceptsCodeAndTicketUrl(): void
    {
        self::assertSame('SC7KX29QDA', Participant::extractCode('sc7kx29qda'));
        self::assertSame('SC7KX29QDA', Participant::extractCode('https://contoh.sch.id/t/SC7KX29QDA?x=1'));
        self::assertNull(Participant::extractCode('bukan-kode'));
    }

    public function testValidateThenCheckInSucceedsOnce(): void
    {
        $p = $this->participant();
        $v = AttendanceService::validate($this->event, $p['code']);
        self::assertSame('valid', $v['state']);

        $first = AttendanceService::checkIn($this->event, (int) $p['id'], $this->user);
        self::assertSame('success', $first['state']);
        self::assertSame('hadir', $first['participant']['status']);

        $second = AttendanceService::checkIn($this->event, (int) $p['id'], $this->user);
        self::assertSame('dup', $second['state'], 'scan kedua tidak boleh membuat catatan baru');
        self::assertSame(1, (int) DB::value('SELECT COUNT(*) FROM participants WHERE id = ? AND status = ?', [$p['id'], 'hadir']));
    }

    public function testScanCannotOverrideFinalAbsentStatus(): void
    {
        $p = $this->participant();
        DB::run("UPDATE participants SET status = 'tidak' WHERE id = ?", [$p['id']]);
        self::assertSame('locked', AttendanceService::validate($this->event, $p['code'])['state']);
        self::assertSame('locked', AttendanceService::checkIn($this->event, (int) $p['id'], $this->user)['state']);
    }

    public function testCheckInRefusedWhenAttendanceClosed(): void
    {
        $p = $this->participant();
        $closed = array_merge($this->event, ['att_status' => 'closed']);
        self::assertSame('closed', AttendanceService::checkIn($closed, (int) $p['id'], $this->user)['state']);
        self::assertSame('belum', Participant::find((int) $p['id'])['status']);
    }

    public function testUnknownCodeIsNotFound(): void
    {
        self::assertSame('notfound', AttendanceService::validate($this->event, 'SCZZZZZZZZ')['state']);
    }

    public function testCheckInRejectsParticipantFromOtherEvent(): void
    {
        $other = Event::create([
            'name' => 'Event Lain', 'description' => '', 'organizer' => 'TU', 'location' => 'Lab',
            'event_date' => today(), 'start_time' => '07:00', 'end_time' => '12:00',
            'reg_start' => null, 'reg_end' => null, 'att_start' => '06:30', 'att_end' => '08:00',
            'status' => 'Aktif', 'reg_status' => 'Ditutup', 'att_status' => 'open',
        ]);
        $p = Participant::create($other, [
            'identity_number' => '9999', 'profession' => 'Pelajar', 'full_name' => 'Siswa Lain',
            'phone' => '0812', 'institution' => 'SMA Lain', 'field_of_study' => 'Lainnya',
        ]);
        self::assertSame('notfound', AttendanceService::validate($this->event, $p['code'])['state']);
        self::assertSame('notfound', AttendanceService::checkIn($this->event, (int) $p['id'], $this->user)['state']);
    }
}
