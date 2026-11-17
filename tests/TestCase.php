<?php

namespace Tests;

use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::flush();
        // Selasa, 17 Nov 2026 (hari sekolah)
        $this->travelTo(CarbonImmutable::parse('2026-11-17 06:30:00'));
    }

    protected function user(string $role = 'admin', array $attrs = []): User
    {
        static $n = 0;
        $n++;

        return User::create($attrs + ['name' => ucfirst($role).' '.$n, 'username' => $role.$n, 'password' => 'password123', 'role' => $role]);
    }

    protected function classWithStudents(string $name = 'X-1', int $count = 3, ?User $homeroom = null): SchoolClass
    {
        $class = SchoolClass::create(['name' => $name, 'grade' => 10, 'homeroom_teacher_id' => $homeroom?->id]);
        for ($i = 1; $i <= $count; $i++) {
            Student::create(['nis' => $name.'-'.$i, 'name' => "Siswa {$name} {$i}", 'gender' => 'L', 'class_id' => $class->id]);
        }

        return $class;
    }
}
