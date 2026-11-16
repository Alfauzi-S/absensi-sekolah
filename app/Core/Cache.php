<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Cache kecil untuk jalur scan (Fase 4): memakai APCu bila ekstensi tersedia (dibagi antar request),
 * selain itu hanya berlaku dalam satu request. Aplikasi tetap benar tanpa APCu, hanya sedikit lebih banyak query.
 * Nilai disimpan singkat (detik) sehingga perubahan jadwal/perangkat cepat terlihat tanpa invalidasi rumit.
 */
final class Cache
{
    /** @var array<string, array{0:int, 1:mixed}> */
    private static array $local = [];
    private static ?bool $apcu = null;

    public static function shared(): bool
    {
        return self::$apcu ??= function_exists('apcu_fetch') && (PHP_SAPI !== 'cli' || (bool) ini_get('apc.enable_cli')) && (bool) ini_get('apc.enabled');
    }

    private static function key(string $key): string
    {
        return 'absensi:' . md5(ROOT_PATH) . ':' . $key;
    }

    /** @template T @param callable():T $fn @return T */
    public static function remember(string $key, int $ttl, callable $fn): mixed
    {
        $now = time();
        if (isset(self::$local[$key]) && self::$local[$key][0] > $now) {
            return self::$local[$key][1];
        }
        if (self::shared()) {
            $hit = false;
            $v = apcu_fetch(self::key($key), $hit);
            if ($hit) {
                self::$local[$key] = [$now + 1, $v];
                return $v;
            }
        }
        $v = $fn();
        self::$local[$key] = [$now + $ttl, $v];
        if (self::shared()) {
            apcu_store(self::key($key), $v, $ttl);
        }
        return $v;
    }

    public static function forget(string $key): void
    {
        unset(self::$local[$key]);
        if (self::shared()) {
            apcu_delete(self::key($key));
        }
    }

    /** Hapus semua entri dengan awalan tertentu (mis. "sessions:" saat sesi ditutup/dibatalkan). */
    public static function forgetPrefix(string $prefix): void
    {
        foreach (array_keys(self::$local) as $k) {
            if (str_starts_with($k, $prefix)) {
                unset(self::$local[$k]);
            }
        }
        if (self::shared() && class_exists(\APCUIterator::class)) {
            apcu_delete(new \APCUIterator('/^' . preg_quote(self::key($prefix), '/') . '/'));
        }
    }
}
