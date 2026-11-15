<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Schedule;
use App\Services\Access;
use PHPUnit\Framework\TestCase;

/** Matriks akses per peran (PRD 4.2): deny-by-default, guru hanya kelasnya. */
final class AccessTest extends TestCase
{
    private array $f;

    protected function setUp(): void
    {
        $this->f = Fixture::base();
    }

    public function testSuperHasEverything(): void
    {
        $u = Fixture::user($this->f['super']);
        foreach (['master', 'accounts', 'settings', 'audit', 'reports', 'class:view', 'class:manage'] as $p) {
            self::assertTrue(Access::can($u, $p, $this->f['classB']), $p);
        }
        self::assertNull(Access::classIds($u));
    }

    public function testTuManagesDataButNotAccountsOrSettings(): void
    {
        $u = Fixture::user($this->f['tu']);
        self::assertTrue(Access::can($u, 'master'));
        self::assertTrue(Access::can($u, 'reports'));
        self::assertTrue(Access::can($u, 'class:view', $this->f['classB']));
        self::assertTrue(Access::can($u, 'class:manage', $this->f['classB']));
        self::assertFalse(Access::can($u, 'accounts'));
        self::assertFalse(Access::can($u, 'settings'));
        self::assertFalse(Access::can($u, 'audit'));
    }

    public function testKepsekOnlyViews(): void
    {
        $u = Fixture::user($this->f['kepsek']);
        self::assertTrue(Access::can($u, 'reports'));
        self::assertTrue(Access::can($u, 'class:view', $this->f['classB']));
        self::assertFalse(Access::can($u, 'master'));
        self::assertFalse(Access::can($u, 'class:manage', $this->f['classA']));
        self::assertFalse(Access::can($u, 'accounts'));
    }

    public function testHomeroomTeacherSeesAndManagesOwnClassOnly(): void
    {
        $u = Fixture::user($this->f['teacher']);
        self::assertTrue(Access::can($u, 'class:view', $this->f['classA']));
        self::assertTrue(Access::can($u, 'class:manage', $this->f['classA']));
        self::assertFalse(Access::can($u, 'class:view', $this->f['classB']), 'kelas lain harus ditolak (IDOR)');
        self::assertFalse(Access::can($u, 'class:manage', $this->f['classB']));
        self::assertFalse(Access::can($u, 'master'));
        self::assertFalse(Access::can($u, 'reports'));
        self::assertSame([$this->f['classA']], Access::classIds($u));
        self::assertTrue(Access::isHomeroom($u));
    }

    public function testSubjectTeacherSeesTaughtClassButCannotManageIt(): void
    {
        $u = Fixture::user($this->f['teacher2']);
        self::assertSame([], Access::classIds($u));
        self::assertFalse(Access::isHomeroom($u));
        Schedule::create(['semester_id' => $this->f['semester'], 'class_id' => $this->f['classB'], 'subject_id' => $this->f['mtk'], 'teacher_id' => $this->f['teacher2'], 'room_id' => null, 'day_of_week' => 1, 'period_start' => 1, 'period_end' => 2]);
        Access::forget();
        self::assertSame([$this->f['classB']], Access::classIds($u));
        self::assertTrue(Access::can($u, 'class:view', $this->f['classB']));
        self::assertFalse(Access::can($u, 'class:manage', $this->f['classB']), 'guru mapel bukan wali kelas');
        self::assertFalse(Access::can($u, 'class:view', $this->f['classA']));
    }

    public function testUnknownPermissionIsDenied(): void
    {
        self::assertFalse(Access::can(Fixture::user($this->f['tu']), 'tidak-ada'));
        self::assertTrue(Access::can(Fixture::user($this->f['super']), 'tidak-ada'), 'super admin selalu boleh');
    }
}
