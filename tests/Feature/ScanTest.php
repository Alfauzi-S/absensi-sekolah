<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\Setting;
use App\Services\AttendanceService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ScanTest extends TestCase
{
    private function svc(): AttendanceService
    {
        return app(AttendanceService::class);
    }

    public function test_on_time_late_duplicate_and_checkout(): void
    {
        [$a, $b] = $this->classWithStudents('X-1', 2)->students->all();
        $day = CarbonImmutable::parse('2026-11-17');

        $r = $this->svc()->scan($a->qr_code, $day->setTime(7, 5));
        $this->assertSame('success', $r['tone']);
        $r = $this->svc()->scan(strtolower(implode('-', str_split($b->qr_code, 4))), $day->setTime(7, 25)); // format kartu lama: huruf kecil + strip
        $this->assertSame('Terlambat 15 menit', $r['title']);
        $r = $this->svc()->scan($a->qr_code, $day->setTime(7, 30));
        $this->assertSame('Sudah tercatat', $r['title']);
        $this->svc()->scan($a->qr_code, $day->setTime(14, 5));

        $rowA = Attendance::where('student_id', $a->id)->first();
        $this->assertSame('H', $rowA->status);
        $this->assertSame('14:05', $rowA->check_out_at->format('H:i'));
        $rowB = Attendance::where('student_id', $b->id)->first();
        $this->assertSame(['T', 15], [$rowB->status, $rowB->late_minutes]);
    }

    public function test_rejects_unknown_card_inactive_student_holiday_and_outside_hours(): void
    {
        $s = $this->classWithStudents()->students->first();
        $day = CarbonImmutable::parse('2026-11-17');
        $this->assertSame('Kartu tidak dikenal', $this->svc()->scan('ZZZZZZZZZZZZ', $day->setTime(7, 0))['title']);
        $this->assertSame('Belum waktunya', $this->svc()->scan($s->qr_code, $day->setTime(5, 30))['title']);
        $this->assertSame('Scan sudah ditutup', $this->svc()->scan($s->qr_code, $day->setTime(18, 0))['title']);
        $this->assertSame('Hari libur', $this->svc()->scan($s->qr_code, CarbonImmutable::parse('2026-11-21 07:00'))['title']); // Sabtu
        Holiday::create(['date' => '2026-11-18', 'name' => 'Libur sekolah']);
        $this->assertSame('Hari libur', $this->svc()->scan($s->qr_code, CarbonImmutable::parse('2026-11-18 07:00'))['title']);
        $s->update(['status' => 'pindah']);
        $this->assertSame('Siswa tidak aktif', $this->svc()->scan($s->qr_code, $day->setTime(7, 0))['title']);
        $this->assertSame(0, Attendance::count());
    }

    public function test_mark_absent_respects_leaves_and_only_runs_after_checkout_start(): void
    {
        [$a, $b, $c] = $this->classWithStudents('X-1', 3)->students->all();
        $day = CarbonImmutable::parse('2026-11-17');
        $this->svc()->scan($a->qr_code, $day->setTime(7, 0));
        $leave = Leave::create(['student_id' => $b->id, 'kind' => 'S', 'date_from' => '2026-11-17', 'date_to' => '2026-11-17', 'reason' => 'Demam']);

        $this->assertSame(0, $this->svc()->markAbsent($day, $day->setTime(12, 0)));
        $this->assertSame(2, $this->svc()->markAbsent($day, $day->setTime(14, 0)));
        $this->assertSame(0, $this->svc()->markAbsent($day, $day->setTime(14, 5))); // idempoten

        $this->assertSame('S', Attendance::where('student_id', $b->id)->value('status'));
        $this->assertSame('A', Attendance::where('student_id', $c->id)->value('status'));

        // Datang terlambat tanpa scan masuk lalu scan pulang: Alpa otomatis menjadi Terlambat.
        $this->svc()->scan($c->qr_code, $day->setTime(14, 10));
        $this->assertSame('T', Attendance::where('student_id', $c->id)->value('status'));
    }

    public function test_priority_correction_over_leave_over_scan(): void
    {
        $s = $this->classWithStudents()->students->first();
        $day = CarbonImmutable::parse('2026-11-17');
        $this->svc()->scan($s->qr_code, $day->setTime(7, 30)); // T
        $leave = Leave::create(['student_id' => $s->id, 'kind' => 'D', 'date_from' => '2026-11-16', 'date_to' => '2026-11-19', 'reason' => 'Lomba']);
        $this->assertSame(4, $this->svc()->applyLeave($leave));
        $row = Attendance::where('student_id', $s->id)->whereDate('date', '2026-11-17')->first();
        $this->assertSame('D', $row->status);
        $this->assertNotNull($row->check_in_at); // jam scan tetap disimpan

        // Hapus surat: kembali ke hasil scan; hari mendatang tanpa scan dihapus.
        $this->travelTo($day->setTime(9, 0));
        $this->svc()->removeLeave($leave);
        $this->assertSame('T', Attendance::where('student_id', $s->id)->whereDate('date', '2026-11-17')->value('status'));
        $this->assertSame('A', Attendance::where('student_id', $s->id)->whereDate('date', '2026-11-16')->value('status'));
        $this->assertFalse(Attendance::where('student_id', $s->id)->whereDate('date', '2026-11-18')->exists());

        // Koreksi manual tidak tertimpa surat baru.
        $this->svc()->correct($s, $day, 'H', 'Sudah dicek wali kelas', null);
        $leave2 = Leave::create(['student_id' => $s->id, 'kind' => 'I', 'date_from' => '2026-11-17', 'date_to' => '2026-11-17', 'reason' => 'x']);
        $this->svc()->applyLeave($leave2);
        $this->assertSame('H', Attendance::where('student_id', $s->id)->whereDate('date', '2026-11-17')->value('status'));
    }

    public function test_kiosk_endpoint_requires_valid_token(): void
    {
        $s = $this->classWithStudents()->students->first();
        $this->travelTo(CarbonImmutable::parse('2026-11-17 06:50'));
        $this->postJson('/kiosk/salah/scan', ['code' => $s->qr_code])->assertNotFound();
        $this->postJson('/kiosk/'.Setting::kioskToken().'/scan', ['code' => $s->qr_code])
            ->assertOk()->assertJson(['ok' => true, 'tone' => 'success', 'student' => ['name' => $s->name], 'stats' => ['in' => 1]]);
        $this->get('/kiosk/'.Setting::kioskToken())->assertOk()->assertSee('Siap memindai');
    }
}
