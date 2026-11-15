<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Card;
use App\Models\Student;
use App\Models\SchoolClass;
use PHPUnit\Framework\TestCase;

/** Kartu QR (PRD Fase 2): kode unik tanpa karakter mirip, versi naik saat diganti, kartu lama ditolak. */
final class CardTest extends TestCase
{
    private array $f;

    protected function setUp(): void
    {
        $this->f = Fixture::base();
    }

    private function student(string $nis, ?int $classId = null): int
    {
        $id = Student::create(['nis' => $nis, 'nisn' => '', 'full_name' => 'Siswa ' . $nis, 'gender' => 'L', 'birth_date' => '', 'parent_name' => '', 'parent_phone' => '', 'status' => 'Aktif']);
        if ($classId) {
            SchoolClass::addMember($classId, $id, $this->f['year']);
        }
        return $id;
    }

    public function testGenerate100kCodesWithoutCollisionOrAmbiguousChars(): void
    {
        $seen = [];
        $badLength = 0;
        for ($i = 0; $i < 100000; $i++) {
            $c = Card::generate();
            $badLength += strlen($c) === 12 ? 0 : 1;
            $seen[$c] = true;
        }
        self::assertSame(0, $badLength);
        self::assertCount(100000, $seen, 'tabrakan kode');
        $all = implode('', array_keys($seen));
        self::assertSame(0, preg_match('/[01OIL]/', $all), 'karakter mirip (0/O, 1/I/L) tidak boleh muncul');
        self::assertTrue(Card::isValidFormat(Card::generate()));
        self::assertFalse(Card::isValidFormat('ABCDEFGHJKL0'));
    }

    public function testNormalizeAndPretty(): void
    {
        self::assertSame('ABCDEFGHJKMN', Card::normalize(" abcd-efgh jkmn\n"));
        self::assertSame('ABCD-EFGH-JKMN', Card::pretty('ABCDEFGHJKMN'));
    }

    public function testIssueThenReplaceRevokesOldCard(): void
    {
        $sid = $this->student('24001');
        self::assertNull(Card::activeFor($sid));
        $first = Card::issue($sid, $this->f['tu']);
        self::assertSame(1, $first['version']);
        self::assertFalse($first['replaced']);
        self::assertSame($first['code'], Card::activeFor($sid)['code']);

        $second = Card::issue($sid, $this->f['tu'], 'Hilang');
        self::assertSame(2, $second['version']);
        self::assertTrue($second['replaced']);
        self::assertNotSame($first['code'], $second['code']);
        $old = Card::findByCode($first['code']);
        self::assertSame('revoked', $old['status'], 'kartu lama harus langsung ditolak');
        self::assertSame('Hilang', $old['revoke_reason']);
        self::assertSame('active', Card::findByCode($second['code'])['status']);
        self::assertSame('24001', Card::findByCode($second['code'])['nis']);
        self::assertCount(2, Card::history($sid));
        self::assertNull(Card::findByCode('TIDAKADA1234'));
    }

    public function testRevokeWithoutReplacement(): void
    {
        $sid = $this->student('24002');
        $c = Card::issue($sid, null);
        Card::revoke($c['id'], 'Siswa keluar');
        self::assertNull(Card::activeFor($sid));
        $next = Card::issue($sid, null);
        self::assertSame(2, $next['version'], 'versi tetap naik meski kartu sebelumnya sudah dicabut');
    }

    public function testBulkIssueOnlyForActiveStudentsWithoutCard(): void
    {
        $a = $this->student('1', $this->f['classA']);
        $b = $this->student('2', $this->f['classA']);
        $c = $this->student('3', $this->f['classB']);
        $this->student('4'); // tanpa kelas: tidak ikut
        $out = $this->student('5', $this->f['classB']);
        Student::update($out, ['nis' => '5', 'full_name' => 'Keluar', 'gender' => 'L', 'status' => 'Keluar']);
        Card::issue($b, null);
        self::assertSame([$a], Card::missingStudentIds($this->f['year'], $this->f['classA']));
        self::assertSame([$a, $c], Card::missingStudentIds($this->f['year']));
        $t = Card::totals($this->f['year']);
        self::assertSame(['students' => 3, 'with_card' => 1, 'missing' => 2], array_intersect_key($t, ['students' => 1, 'with_card' => 1, 'missing' => 1]));
        $stats = Card::statsByClass($this->f['year']);
        self::assertSame(['X-A' => [2, 1], 'X-B' => [2, 0]], array_combine(array_column($stats, 'name'), array_map(static fn ($r) => [(int) $r['students'], (int) $r['with_card']], $stats)));
    }

    public function testMarkPrinted(): void
    {
        $sid = $this->student('24003', $this->f['classA']);
        $c = Card::issue($sid, null);
        self::assertNull(Card::forClass($this->f['classA'])[0]['printed_at']);
        Card::markPrinted([$c['id']]);
        self::assertNotNull(Card::forClass($this->f['classA'])[0]['printed_at']);
    }
}
