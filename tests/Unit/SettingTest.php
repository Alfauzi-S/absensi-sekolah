<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Setting;
use PHPUnit\Framework\TestCase;

final class SettingTest extends TestCase
{
    protected function setUp(): void
    {
        Fixture::reset();
    }

    public function testDefaultsAndOverrides(): void
    {
        self::assertSame('masuk_pulang', Setting::get('attendance_mode'));
        self::assertSame([6, 7], Setting::weekendDays());
        Setting::setMany(['attendance_mode' => 'per_jam', 'weekend_days' => '7', 'late_tolerance_minutes' => '15']);
        self::assertSame('per_jam', Setting::get('attendance_mode'));
        self::assertSame([7], Setting::weekendDays());
        self::assertSame(15, Setting::int('late_tolerance_minutes'));
        $this->expectException(\InvalidArgumentException::class);
        Setting::set('bukan_kunci', 'x');
    }

    public function testSetOverwritesSameKey(): void
    {
        Setting::set('school_name', 'SMA A');
        Setting::set('school_name', 'SMA B');
        self::assertSame('SMA B', Setting::get('school_name'));
        self::assertSame(1, (int) \App\Core\Database::value("SELECT COUNT(*) FROM settings WHERE skey = 'school_name'"));
    }
}
