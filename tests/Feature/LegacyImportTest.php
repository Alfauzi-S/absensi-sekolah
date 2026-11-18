<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Leave;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Data dari aplikasi versi lama (tabel tanpa awalan di database yang sama) dipindahkan ke versi Laravel. */
class LegacyImportTest extends TestCase
{
    public function test_imports_old_data_and_old_cards_still_scan(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec(file_get_contents(base_path('tests/fixtures/legacy-schema.sqlite.sql')));
        $now = '2026-11-10 08:00:00';
        $ins = fn ($table, $row) => DB::connection()->withoutTablePrefix(fn ($c) => $c->table($table)->insert($row));

        $ins('settings', [['skey' => 'school_name', 'svalue' => 'SMA Lama', 'updated_at' => $now], ['skey' => 'late_after', 'svalue' => '07:00', 'updated_at' => $now],
            ['skey' => 'late_tolerance_minutes', 'svalue' => '15', 'updated_at' => $now]]);
        $ins('users', [
            ['id' => 1, 'name' => 'Super', 'username' => 'super', 'password_hash' => password_hash('lama12345', PASSWORD_DEFAULT), 'role' => 'super', 'status' => 'Aktif', 'created_at' => $now],
            ['id' => 2, 'name' => 'Wali', 'username' => 'wali', 'password_hash' => password_hash('x', PASSWORD_DEFAULT), 'role' => 'guru', 'status' => 'Nonaktif', 'created_at' => $now],
        ]);
        $ins('academic_years', [['id' => 1, 'name' => '2025/2026', 'start_date' => '2025-07-01', 'end_date' => '2026-06-30', 'is_active' => 0, 'created_at' => $now],
            ['id' => 2, 'name' => '2026/2027', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_active' => 1, 'created_at' => $now]]);
        $ins('classes', [['id' => 5, 'academic_year_id' => 1, 'name' => 'X-1', 'grade' => 10, 'homeroom_teacher_id' => null, 'created_at' => $now],
            ['id' => 9, 'academic_year_id' => 2, 'name' => 'XI-1', 'grade' => 11, 'homeroom_teacher_id' => 2, 'created_at' => $now]]);
        $ins('students', [
            ['id' => 11, 'nis' => '111', 'full_name' => 'Ani', 'gender' => 'P', 'status' => 'Aktif', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 12, 'nis' => '112', 'full_name' => 'Budi', 'gender' => 'L', 'status' => 'Pindah', 'created_at' => $now, 'updated_at' => $now],
        ]);
        $ins('class_members', [['class_id' => 5, 'student_id' => 11, 'academic_year_id' => 1, 'created_at' => $now], ['class_id' => 9, 'student_id' => 11, 'academic_year_id' => 2, 'created_at' => $now]]);
        $ins('cards', [['student_id' => 11, 'code' => 'ABCDEFGHJKMN', 'version' => 2, 'status' => 'active', 'issued_at' => $now],
            ['student_id' => 11, 'code' => 'PQRSTUVWXYZ2', 'version' => 1, 'status' => 'revoked', 'issued_at' => $now]]);
        $ins('leaves', [['id' => 3, 'student_id' => 11, 'class_id' => 9, 'kind' => 'S', 'date_from' => '2026-11-11', 'date_to' => '2026-11-11', 'reason' => 'Demam', 'status' => 'active', 'created_at' => $now]]);
        $ins('sessions', [
            ['id' => 1, 'class_id' => 9, 'session_date' => '2026-11-10', 'scan_from' => $now, 'starts_at' => $now, 'late_after' => $now, 'ends_at' => $now, 'kind' => 'harian_masuk', 'status' => 'closed', 'created_at' => $now],
            ['id' => 2, 'class_id' => 9, 'session_date' => '2026-11-10', 'scan_from' => $now, 'starts_at' => $now, 'late_after' => $now, 'ends_at' => $now, 'kind' => 'harian_pulang', 'status' => 'closed', 'created_at' => $now],
            ['id' => 3, 'class_id' => 9, 'session_date' => '2026-11-11', 'scan_from' => $now, 'starts_at' => $now, 'late_after' => $now, 'ends_at' => $now, 'kind' => 'harian_masuk', 'status' => 'closed', 'created_at' => $now],
        ]);
        $ins('attendances', [
            ['session_id' => 1, 'student_id' => 11, 'class_id' => 9, 'status' => 'T', 'scanned_at' => '2026-11-10 07:20:00', 'late_minutes' => 20, 'method' => 'scan', 'leave_id' => null, 'created_at' => $now],
            ['session_id' => 2, 'student_id' => 11, 'class_id' => 9, 'status' => 'H', 'scanned_at' => '2026-11-10 14:02:00', 'late_minutes' => 0, 'method' => 'scan', 'leave_id' => null, 'created_at' => $now],
            ['session_id' => 3, 'student_id' => 11, 'class_id' => 9, 'status' => 'S', 'scanned_at' => null, 'late_minutes' => 0, 'method' => 'auto', 'leave_id' => 3, 'created_at' => $now],
        ]);

        $this->artisan('absensi:impor-lama')->assertSuccessful();
        Setting::flush();

        $this->assertSame('SMA Lama', Setting::get('school_name'));
        $this->assertSame('07:15', Setting::get('late_after'));
        $this->assertSame(['admin', false], [User::find(1)->role, User::find(2)->is_active]);
        $this->assertSame(['XI-1'], SchoolClass::pluck('name')->all()); // hanya tahun ajaran aktif
        $this->assertSame(2, SchoolClass::find(9)->homeroom_teacher_id);
        $ani = Student::find(11);
        $this->assertSame(['Ani', 9, 'ABCDEFGHJKMN'], [$ani->name, $ani->class_id, $ani->qr_code]);
        $this->assertSame('pindah', Student::find(12)->status);
        $this->assertSame(1, Leave::count());

        $d10 = Attendance::where('student_id', 11)->whereDate('date', '2026-11-10')->first();
        $this->assertSame(['T', 20, '07:20', '14:02'], [$d10->status, $d10->late_minutes, $d10->check_in_at->format('H:i'), $d10->check_out_at->format('H:i')]);
        $this->assertSame(['S', 3], [Attendance::whereDate('date', '2026-11-11')->value('status'), Attendance::whereDate('date', '2026-11-11')->value('leave_id')]);

        // Password lama tetap berlaku, kartu yang sudah dicetak tetap bisa dipakai.
        $this->post('/login', ['username' => 'super', 'password' => 'lama12345'])->assertRedirect('/');
        $r = app(AttendanceService::class)->scan('ABCD-EFGH-JKMN', CarbonImmutable::parse('2026-11-17 07:00'));
        $this->assertTrue($r['ok']);

        // Dijalankan lagi: dilewati, tidak ada data ganda.
        $this->artisan('absensi:impor-lama')->expectsOutputToContain('dilewati')->assertSuccessful();
        $this->assertSame(2, User::count());
    }

    public function test_fresh_install_without_legacy_data_skips(): void
    {
        $this->artisan('absensi:impor-lama')->expectsOutputToContain('tidak ditemukan')->assertSuccessful();
    }
}
