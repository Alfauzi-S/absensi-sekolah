<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Card;
use App\Services\CardPdf;
use PHPUnit\Framework\TestCase;

/** PDF kartu: valid, berisi kode, dan satu kelas (36 kartu) selesai < 5 detik (PRD Fase 2). */
final class CardPdfTest extends TestCase
{
    protected function setUp(): void
    {
        Fixture::base();
    }

    public function testOneClassUnderFiveSeconds(): void
    {
        $cards = [];
        for ($i = 1; $i <= 36; $i++) {
            $cards[] = ['full_name' => 'Siswa Nomor ' . $i . ' Dengan Nama Panjang Sekali', 'nis' => sprintf('26%04d', $i), 'class_name' => 'X-1', 'code' => Card::generate(), 'version' => 1, 'photo' => null];
        }
        $t0 = microtime(true);
        $pdf = CardPdf::build($cards, 'Tes');
        $dt = microtime(true) - $t0;
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(10000, strlen($pdf));
        self::assertSame(4, preg_match_all('/\/Type\s*\/Page[^s]/', $pdf), '36 kartu = 4 halaman (10 per halaman)');
        self::assertLessThan(5.0, $dt, sprintf('PDF 36 kartu memakan %.2f detik', $dt));
    }

    public function testEmptyListStillProducesPdf(): void
    {
        self::assertStringStartsWith('%PDF-', CardPdf::build([], 'Kosong'));
    }
}
