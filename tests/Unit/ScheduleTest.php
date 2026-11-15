<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Master;
use App\Models\Schedule;
use PHPUnit\Framework\TestCase;

/** Deteksi bentrok jadwal: kelas, guru, dan ruangan pada hari & jam yang beririsan. */
final class ScheduleTest extends TestCase
{
    private array $f;

    protected function setUp(): void
    {
        $this->f = Fixture::base();
    }

    private function row(array $over = []): array
    {
        return $over + ['semester_id' => $this->f['semester'], 'class_id' => $this->f['classA'], 'subject_id' => $this->f['mtk'], 'teacher_id' => $this->f['teacher'], 'room_id' => $this->f['room'], 'day_of_week' => 1, 'period_start' => 1, 'period_end' => 2];
    }

    public function testNoConflictOnDifferentDayOrPeriod(): void
    {
        Schedule::create($this->row());
        self::assertSame([], Schedule::conflicts($this->row(['day_of_week' => 2])));
        self::assertSame([], Schedule::conflicts($this->row(['period_start' => 3, 'period_end' => 4])));
    }

    public function testClassTeacherAndRoomConflicts(): void
    {
        Schedule::create($this->row());
        // Kelas sama, jam beririsan (2–3 memotong 1–2)
        $c = Schedule::conflicts($this->row(['period_start' => 2, 'period_end' => 3, 'teacher_id' => $this->f['teacher2'], 'room_id' => null]));
        self::assertSame(['class'], array_keys($c));
        // Kelas lain, guru sama
        $c = Schedule::conflicts($this->row(['class_id' => $this->f['classB'], 'room_id' => null]));
        self::assertSame(['teacher'], array_keys($c));
        // Kelas lain, guru lain, ruangan sama
        $c = Schedule::conflicts($this->row(['class_id' => $this->f['classB'], 'teacher_id' => $this->f['teacher2']]));
        self::assertSame(['room'], array_keys($c));
        self::assertSame('X-A', $c['room']['class_name']);
    }

    public function testEditingItselfIsNotAConflict(): void
    {
        $id = Schedule::create($this->row());
        self::assertSame([], Schedule::conflicts($this->row(), $id));
        self::assertNotSame([], Schedule::conflicts($this->row()));
    }

    public function testGridSpansPeriods(): void
    {
        Schedule::create($this->row(['period_start' => 2, 'period_end' => 3]));
        $grid = Schedule::grid(Schedule::forClass($this->f['semester'], $this->f['classA']), Master::periods(), [1, 2]);
        self::assertNull($grid[1][1]);
        self::assertTrue($grid[1][2]['first']);
        self::assertFalse($grid[1][3]['first']);
        self::assertNull($grid[2][2]);
    }

    public function testMasterUsageBlocksDelete(): void
    {
        Schedule::create($this->row());
        self::assertSame(1, Master::usage('ruangan', Master::find('ruangan', $this->f['room'])));
        self::assertSame(1, Master::usage('jam', Master::find('jam', 1) ?? ['number' => 1]));
        $other = Master::find('ruangan', $this->f['room'] + 1);
        self::assertSame(0, Master::usage('ruangan', $other));
    }
}
