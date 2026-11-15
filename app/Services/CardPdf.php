<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Card;
use App\Models\Setting;

/**
 * PDF kartu siswa ukuran CR80 (85,6 × 54 mm), 10 kartu per halaman A4 dengan garis potong.
 * QR level koreksi M, ukuran 26 mm, berisi kode kartu saja agar cepat terbaca scanner USB maupun kamera.
 */
final class CardPdf
{
    public const CARD_W = 85.6;
    public const CARD_H = 54.0;
    private const COLS = 2;
    private const ROWS = 5;
    private const GAP = 3.0;
    private const QR_SIZE = 26.0;

    /**
     * @param array<int, array{full_name:string, nis:string, class_name:string, code:string, version:int, photo?:string|null}> $cards
     */
    public static function build(array $cards, string $title = 'Kartu Siswa'): string
    {
        require_once ROOT_PATH . '/vendor/autoload.php';
        $school = Setting::get('school_name') ?: config('app_name', 'Absensi Sekolah');
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator(config('app_name', 'Absensi Sekolah'));
        $pdf->SetTitle($title);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->setFontSubsetting(true);

        $pageW = 210.0;
        $pageH = 297.0;
        $gridW = self::COLS * self::CARD_W + (self::COLS - 1) * self::GAP;
        $gridH = self::ROWS * self::CARD_H + (self::ROWS - 1) * self::GAP;
        $x0 = ($pageW - $gridW) / 2;
        $y0 = ($pageH - $gridH) / 2;
        $perPage = self::COLS * self::ROWS;
        $qrStyle = ['border' => false, 'padding' => 0, 'fgcolor' => [0, 0, 0], 'bgcolor' => false];

        foreach (array_values($cards) as $i => $c) {
            $slot = $i % $perPage;
            if ($slot === 0) {
                $pdf->AddPage();
                self::cropMarks($pdf, $x0, $y0);
            }
            $x = $x0 + ($slot % self::COLS) * (self::CARD_W + self::GAP);
            $y = $y0 + intdiv($slot, self::COLS) * (self::CARD_H + self::GAP);
            self::card($pdf, $x, $y, $c, $school, $qrStyle);
        }
        if (!$cards) {
            $pdf->AddPage();
            $pdf->SetFont('dejavusans', '', 11);
            $pdf->Text(20, 30, t('Tidak ada kartu untuk dicetak.'));
        }
        return $pdf->Output('', 'S');
    }

    private static function card(\TCPDF $pdf, float $x, float $y, array $c, string $school, array $qrStyle): void
    {
        $w = self::CARD_W;
        $h = self::CARD_H;
        // Bingkai kartu dan pita atas
        $pdf->SetDrawColor(200, 206, 214);
        $pdf->SetLineWidth(0.2);
        $pdf->RoundedRect($x, $y, $w, $h, 2.5, '1111', 'D');
        $pdf->SetFillColor(11, 30, 56);
        $pdf->RoundedRect($x, $y, $w, 9, 2.5, '1100', 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('dejavusansb', '', 7.5);
        $pdf->SetXY($x + 3, $y + 1.2);
        $pdf->Cell($w - 6, 4, mb_strtoupper($school), 0, 0, 'L', false, '', 1);
        $pdf->SetFont('dejavusans', '', 5.5);
        $pdf->SetXY($x + 3, $y + 5);
        $pdf->Cell($w - 6, 3, t('KARTU SISWA · ABSENSI QR'), 0, 0, 'L', false, '', 1);

        // Foto (3:4) atau kotak inisial
        $photoW = 20;
        $photoH = 26.7;
        $px = $x + 3;
        $py = $y + 12;
        $photo = $c['photo'] ?? null;
        if ($photo && is_file($photo)) {
            $pdf->Image($photo, $px, $py, $photoW, $photoH, 'JPG', '', '', false, 300, '', false, false, 0, 'CM');
        } else {
            $pdf->SetFillColor(232, 237, 243);
            $pdf->Rect($px, $py, $photoW, $photoH, 'F');
            $pdf->SetTextColor(90, 104, 122);
            $pdf->SetFont('dejavusansb', '', 11);
            $pdf->SetXY($px, $py + 8);
            $pdf->Cell($photoW, 6, initials($c['full_name']), 0, 0, 'C');
        }

        // QR di kanan: level M, 22 mm (PRD: minimal 2 cm)
        $qx = $x + $w - self::QR_SIZE - 3;
        $qy = $y + 11.5;
        $pdf->write2DBarcode($c['code'], 'QRCODE,M', $qx, $qy, self::QR_SIZE, self::QR_SIZE, $qrStyle, 'N');
        $pdf->SetTextColor(60, 70, 84);
        $pdf->SetFont('courier', 'B', 7);
        $pdf->SetXY($qx - 2, $qy + self::QR_SIZE + 0.3);
        $pdf->Cell(self::QR_SIZE + 4, 3, Card::pretty($c['code']), 0, 0, 'C');

        // Identitas di tengah
        $tx = $px + $photoW + 2.5;
        $tw = $qx - $tx - 2;
        $pdf->SetTextColor(15, 23, 42);
        $pdf->SetFont('dejavusansb', '', 9);
        $pdf->SetXY($tx, $py);
        $pdf->MultiCell($tw, 4.3, $c['full_name'], 0, 'L', false, 1, '', '', true, 0, false, true, 9, 'T', false);
        $pdf->SetFont('dejavusans', '', 6.5);
        $pdf->SetTextColor(90, 104, 122);
        $pdf->SetXY($tx, $py + 11);
        $pdf->Cell($tw, 3.2, t('NIS'), 0, 0, 'L');
        $pdf->SetTextColor(15, 23, 42);
        $pdf->SetFont('dejavusansb', '', 8);
        $pdf->SetXY($tx, $py + 14);
        $pdf->Cell($tw, 3.5, (string) $c['nis'], 0, 0, 'L', false, '', 1);
        $pdf->SetFont('dejavusans', '', 6.5);
        $pdf->SetTextColor(90, 104, 122);
        $pdf->SetXY($tx, $py + 19);
        $pdf->Cell($tw, 3.2, t('Kelas'), 0, 0, 'L');
        $pdf->SetTextColor(15, 23, 42);
        $pdf->SetFont('dejavusansb', '', 8);
        $pdf->SetXY($tx, $py + 22);
        $pdf->Cell($tw, 3.5, (string) $c['class_name'], 0, 0, 'L', false, '', 1);

        // Kaki kartu
        $pdf->SetFont('dejavusans', '', 5);
        $pdf->SetTextColor(140, 150, 165);
        $pdf->SetXY($x + 3, $y + $h - 5.5);
        $pdf->Cell($w - 6, 3, t('Kartu versi :v · Bila hilang, lapor ke TU agar kartu lama dinonaktifkan.', ['v' => $c['version']]), 0, 0, 'L', false, '', 1);
    }

    private static function cropMarks(\TCPDF $pdf, float $x0, float $y0): void
    {
        $pdf->SetDrawColor(170, 170, 170);
        $pdf->SetLineWidth(0.1);
        $len = 3;
        $xEnd = $x0 + self::COLS * self::CARD_W + (self::COLS - 1) * self::GAP;
        $yEnd = $y0 + self::ROWS * self::CARD_H + (self::ROWS - 1) * self::GAP;
        $xs = [$x0, $xEnd];
        for ($col = 1; $col < self::COLS; $col++) {
            $xs[] = $x0 + $col * self::CARD_W + ($col - 1) * self::GAP;
            $xs[] = $x0 + $col * (self::CARD_W + self::GAP);
        }
        $ys = [$y0, $yEnd];
        for ($row = 1; $row < self::ROWS; $row++) {
            $ys[] = $y0 + $row * self::CARD_H + ($row - 1) * self::GAP;
            $ys[] = $y0 + $row * (self::CARD_H + self::GAP);
        }
        foreach ($xs as $gx) {
            $pdf->Line($gx, $y0 - $len - 1, $gx, $y0 - 1);
            $pdf->Line($gx, $yEnd + 1, $gx, $yEnd + $len + 1);
        }
        foreach ($ys as $gy) {
            $pdf->Line($x0 - $len - 1, $gy, $x0 - 1, $gy);
            $pdf->Line($xEnd + 1, $gy, $xEnd + $len + 1, $gy);
        }
    }
}
