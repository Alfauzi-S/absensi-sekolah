<?php
declare(strict_types=1);

namespace App\Services;

/** Nomor WhatsApp Indonesia: 0812…, +62 812…, 62812…, 812… → 62812… (hanya angka). */
final class Phone
{
    public static function normalize(?string $raw): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $raw) ?? '';
        if ($d === '') {
            return null;
        }
        if (str_starts_with($d, '0')) {
            $d = '62' . substr($d, 1);
        } elseif (str_starts_with($d, '8')) {
            $d = '62' . $d;
        }
        return preg_match('/^628\d{7,12}$/', $d) ? $d : null;
    }

    /** 6281234567890 → 0812****7890 (tampilan di layar admin). */
    public static function mask(?string $phone): string
    {
        if (!$phone) {
            return '—';
        }
        $local = str_starts_with($phone, '62') ? '0' . substr($phone, 2) : $phone;
        return strlen($local) > 8 ? substr($local, 0, 4) . str_repeat('*', strlen($local) - 8) . substr($local, -4) : $local;
    }
}
