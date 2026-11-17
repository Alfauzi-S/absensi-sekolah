<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database as DB;
use App\Services\Portal;
use PHPUnit\Framework\TestCase;

/** Fase 6: portal orang tua. Uji PRD: orang tua membuka data anak lain lewat ganti ID → ditolak. */
final class PortalTest extends TestCase
{
    private array $f;

    protected function setUp(): void
    {
        $this->f = Fixture::base();
        $_SESSION = [];
    }

    private function student(string $nis, string $status = 'Aktif'): int
    {
        return DB::insert('students', ['nis' => $nis, 'full_name' => 'Siswa ' . $nis, 'gender' => 'L', 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function testCodeFormatAndHashOnly(): void
    {
        $id = $this->student('9001');
        $code = Portal::issue($id);
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $code);
        $hash = (string) DB::value('SELECT portal_code_hash FROM students WHERE id = ?', [$id]);
        $this->assertStringNotContainsString($code, $hash, 'kode tidak disimpan polos');
        $this->assertNotNull(Portal::verify('9001', Portal::pretty($code)), 'format ABCD-EFGH diterima');
        $this->assertNotNull(Portal::verify(' 9001 ', strtolower($code)), 'huruf kecil diterima');
        $this->assertNull(Portal::verify('9001', 'AAAAAAAA'));
        $this->assertNull(Portal::verify('9002', $code), 'NIS lain');
        $new = Portal::issue($id);
        $this->assertNull(Portal::verify('9001', $code), 'kode lama tidak berlaku setelah dibuat ulang');
        $this->assertNotNull(Portal::verify('9001', $new));
    }

    public function testInactiveOrNoCodeRejected(): void
    {
        $a = $this->student('9101', 'Pindah');
        $code = Portal::issue($a);
        $this->assertNull(Portal::verify('9101', $code), 'siswa tidak aktif');
        $this->student('9102');
        $this->assertNull(Portal::verify('9102', ''), 'belum punya kode');
    }

    public function testOnlyOpenedChildrenVisible(): void
    {
        $a = $this->student('9201');
        $b = $this->student('9202');
        $c = $this->student('9203');
        $this->assertFalse(Portal::canView($a), 'belum masuk');
        Portal::addChild($a);
        $this->assertTrue(Portal::canView($a));
        $this->assertFalse(Portal::canView($b), 'ganti ID ke anak lain ditolak');
        Portal::addChild($b);
        Portal::addChild($b);
        $this->assertSame([$a, $b], Portal::children(), 'kakak-adik, tanpa duplikat');
        $this->assertFalse(Portal::canView($c));
        $this->assertFalse(Portal::canView(0));
    }
}
