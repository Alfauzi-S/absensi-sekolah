<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database as DB;

/**
 * Portal orang tua/siswa (PRD Fase 6): masuk dengan NIS + kode akses 8 karakter yang dibuat TU, hanya melihat riwayat kehadiran
 * anak yang kodenya diketahui. Kode disimpan sebagai hash (password_hash), ditampilkan sekali saat dibuat.
 * Satu perangkat boleh membuka beberapa anak (kakak-adik) dengan memasukkan NIS + kode masing-masing.
 */
final class Portal
{
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // tanpa I, O, 0, 1 agar tidak tertukar
    public const MAX_CHILDREN = 6;
    public const SESSION_KEY = 'portal_students';

    public static function generateCode(): string
    {
        $c = '';
        for ($i = 0; $i < 8; $i++) {
            $c .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        return $c;
    }

    /** ABCD2345 → ABCD-2345 */
    public static function pretty(string $code): string
    {
        return substr($code, 0, 4) . '-' . substr($code, 4);
    }

    public static function clean(string $raw): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '');
    }

    /** Buat kode baru untuk satu siswa (kode lama langsung tidak berlaku). @return string kode polos */
    public static function issue(int $studentId): string
    {
        $code = self::generateCode();
        DB::update('students', ['portal_code_hash' => password_hash($code, PASSWORD_DEFAULT), 'portal_code_at' => now()], 'id = ?', [$studentId]);
        return $code;
    }

    /** Siswa aktif dengan NIS dan kode ini; null bila salah. */
    public static function verify(string $nis, string $code): ?array
    {
        $code = self::clean($code);
        $st = DB::one("SELECT id, nis, full_name, portal_code_hash FROM students WHERE nis = ? AND status = 'Aktif'", [trim($nis)]);
        if (!$st || !$st['portal_code_hash'] || strlen($code) !== 8 || !password_verify($code, $st['portal_code_hash'])) {
            return null;
        }
        return $st;
    }

    /** @return int[] siswa yang sudah dibuka di sesi ini */
    public static function children(): array
    {
        $ids = $_SESSION[self::SESSION_KEY] ?? [];
        return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }

    public static function addChild(int $id): void
    {
        $ids = self::children();
        if (!in_array($id, $ids, true)) {
            $ids[] = $id;
        }
        $_SESSION[self::SESSION_KEY] = array_slice($ids, -self::MAX_CHILDREN);
    }

    /** Hanya anak yang dibuka dengan NIS + kode di sesi ini; ganti ID di URL ditolak. */
    public static function canView(int $studentId): bool
    {
        return $studentId > 0 && in_array($studentId, self::children(), true);
    }
}
