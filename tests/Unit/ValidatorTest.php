<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Master;
use App\Models\Student;
use App\Services\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    private array $f;

    protected function setUp(): void
    {
        $this->f = Fixture::base();
    }

    public function testNormalizePhone(): void
    {
        self::assertSame('081234567890', Validator::normalizePhone('+62 812-3456-7890'));
        self::assertSame('081234567890', Validator::normalizePhone('62812 3456 7890'));
        self::assertSame('081234567890', Validator::normalizePhone('0812.3456.7890'));
        self::assertSame('', Validator::normalizePhone('   '));
    }

    public function testStudentRules(): void
    {
        $ok = ['nis' => '24001', 'nisn' => '0081234567', 'full_name' => 'Aisyah Putri', 'gender' => 'P', 'birth_date' => '2010-05-14', 'parent_phone' => '081234567890', 'status' => 'Aktif'];
        self::assertSame([], Validator::student($ok));
        $bad = Validator::student(['nis' => '', 'nisn' => '12', 'full_name' => 'A', 'gender' => 'X', 'birth_date' => '31-02-2010', 'parent_phone' => '12345', 'status' => 'Hilang']);
        self::assertSame(['nis', 'nisn', 'full_name', 'gender', 'birth_date', 'parent_phone', 'status'], array_keys($bad));
    }

    public function testStudentNisMustBeUnique(): void
    {
        $id = Student::create(['nis' => '24001', 'nisn' => '', 'full_name' => 'Aisyah', 'gender' => 'P', 'birth_date' => '', 'parent_name' => '', 'parent_phone' => '', 'status' => 'Aktif']);
        self::assertArrayHasKey('nis', Validator::student(['nis' => '24001', 'full_name' => 'Lain', 'gender' => 'L', 'status' => 'Aktif']));
        self::assertArrayNotHasKey('nis', Validator::student(['nis' => '24001', 'full_name' => 'Aisyah', 'gender' => 'P', 'status' => 'Aktif'], $id), 'ubah data sendiri tidak dianggap duplikat');
    }

    public function testClassNameUniquePerYear(): void
    {
        $e = Validator::schoolClass(['academic_year_id' => $this->f['year'], 'name' => 'X-A', 'grade' => '10', 'homeroom_teacher_id' => '', 'room_id' => '']);
        self::assertArrayHasKey('name', $e);
        $e = Validator::schoolClass(['academic_year_id' => $this->f['year'], 'name' => 'X-C', 'grade' => '10', 'homeroom_teacher_id' => (string) $this->f['tu'], 'room_id' => '']);
        self::assertArrayHasKey('homeroom_teacher_id', $e, 'wali kelas harus akun guru');
    }

    public function testScheduleRules(): void
    {
        $p = Master::periods();
        $ok = ['semester_id' => $this->f['semester'], 'class_id' => $this->f['classA'], 'subject_id' => (string) $this->f['mtk'], 'teacher_id' => (string) $this->f['teacher'], 'room_id' => '', 'day_of_week' => '1', 'period_start' => '1', 'period_end' => '2'];
        self::assertSame([], Validator::schedule($ok, $p));
        self::assertArrayHasKey('period_end', Validator::schedule(['period_start' => '3', 'period_end' => '2', 'day_of_week' => '1', 'subject_id' => (string) $this->f['mtk']] + $ok, $p));
        self::assertArrayHasKey('period_start', Validator::schedule(['period_start' => '9'] + $ok, $p), 'jam di luar daftar');
        self::assertArrayHasKey('teacher_id', Validator::schedule(['teacher_id' => (string) $this->f['tu']] + $ok, $p));
        self::assertArrayHasKey('day_of_week', Validator::schedule(['day_of_week' => '8'] + $ok, $p));
    }

    public function testAcademicYearAndSemester(): void
    {
        self::assertSame([], Validator::academicYear(['name' => '2027/2028', 'start_date' => '2027-07-12', 'end_date' => '2028-06-25']));
        self::assertArrayHasKey('name', Validator::academicYear(['name' => '2026/2027', 'start_date' => '2027-07-12', 'end_date' => '2028-06-25']), 'nama ganda');
        self::assertArrayHasKey('end_date', Validator::academicYear(['name' => '2027/2028', 'start_date' => '2027-07-12', 'end_date' => '2027-01-01']));
        self::assertArrayHasKey('name', Validator::semester(['name' => 'Tengah', 'start_date' => '2027-01-01', 'end_date' => '2027-06-01']));
    }

    public function testAccountAndPassword(): void
    {
        $d = ['name' => 'Budi', 'username' => 'budi.guru', 'password' => 'rahasia12345', 'role' => 'guru', 'nip' => '', 'phone' => '', 'status' => 'Aktif'];
        self::assertSame([], Validator::account($d, true));
        self::assertArrayHasKey('username', Validator::account(['username' => 'tu'] + $d, true), 'username sudah dipakai');
        self::assertArrayHasKey('password', Validator::account(['password' => 'pendek'] + $d, true));
        self::assertArrayHasKey('role', Validator::account(['role' => 'admin'] + $d, true));
        self::assertNull(Validator::newPassword('passwordbaru1', 'passwordbaru1'));
        self::assertNotNull(Validator::newPassword('passwordbaru1', 'beda'));
        self::assertNotNull(Validator::newPassword('hanyahuruf', 'hanyahuruf'));
    }

    public function testSettings(): void
    {
        $d = \App\Models\Setting::DEFAULTS;
        self::assertSame([], Validator::settings($d));
        self::assertArrayHasKey('checkin_end', Validator::settings(['checkin_end' => '05:00'] + $d));
        self::assertArrayHasKey('attendance_mode', Validator::settings(['attendance_mode' => 'x'] + $d));
        self::assertArrayHasKey('late_tolerance_minutes', Validator::settings(['late_tolerance_minutes' => '999'] + $d));
    }
}
