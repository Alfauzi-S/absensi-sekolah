<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database as DB;

/**
 * Perangkat scanner (kiosk) yang terikat ke satu ruangan (PRD Fase 3).
 * Alur: Super Admin membuat perangkat → kode pasangan 6 digit (15 menit) → kiosk memasukkan kode sekali
 * → menerima token panjang yang disimpan di perangkat. Server hanya menyimpan hash token.
 */
final class Device
{
    public const PAIR_TTL = 900; // detik

    private const SELECT = 'SELECT d.*, r.code AS room_code, r.name AS room_name, u.name AS created_by_name
        FROM devices d LEFT JOIN rooms r ON r.id = d.room_id LEFT JOIN users u ON u.id = d.created_by';

    public static function all(): array
    {
        return DB::all(self::SELECT . " ORDER BY CASE d.status WHEN 'active' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END, r.code, d.name");
    }

    public static function find(int $id): ?array
    {
        return DB::one(self::SELECT . ' WHERE d.id = ?', [$id]);
    }

    public static function create(string $name, ?int $roomId, ?int $userId): array
    {
        $id = DB::insert('devices', [
            'name' => $name, 'room_id' => $roomId, 'status' => 'pending', 'created_by' => $userId, 'created_at' => now(),
        ]);
        return self::newPairCode($id);
    }

    public static function update(int $id, string $name, ?int $roomId): void
    {
        self::forgetToken($id);
        DB::update('devices', ['name' => $name, 'room_id' => $roomId], 'id = ?', [$id]);
    }

    /** Kode pasangan baru. Token lama (bila ada) langsung tidak berlaku. @return array{id:int, code:string, expires_at:string} */
    public static function newPairCode(int $id): array
    {
        do {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (DB::value("SELECT id FROM devices WHERE pair_code = ? AND status = 'pending'", [$code]));
        $expires = date('Y-m-d H:i:s', time() + self::PAIR_TTL);
        self::forgetToken($id);
        DB::update('devices', [
            'pair_code' => $code, 'pair_expires_at' => $expires, 'status' => 'pending', 'token_hash' => null, 'paired_at' => null, 'revoked_at' => null,
        ], 'id = ?', [$id]);
        return ['id' => $id, 'code' => $code, 'expires_at' => $expires];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Tukar kode pasangan dengan token. @return array{token:string, device:array}|null */
    public static function pair(string $code, string $ip, string $appVersion = ''): ?array
    {
        $row = DB::one("SELECT * FROM devices WHERE pair_code = ? AND status = 'pending'", [$code]);
        if (!$row || $row['pair_expires_at'] < now()) {
            return null;
        }
        $token = bin2hex(random_bytes(32));
        DB::update('devices', [
            'token_hash' => self::hash($token), 'pair_code' => null, 'pair_expires_at' => null, 'status' => 'active',
            'paired_at' => now(), 'last_seen_at' => now(), 'last_ip' => $ip, 'app_version' => mb_substr($appVersion, 0, 20) ?: null,
        ], 'id = ?', [(int) $row['id']]);
        return ['token' => $token, 'device' => self::find((int) $row['id'])];
    }

    /** Perangkat berdasarkan token (apa pun statusnya; pemanggil memeriksa status). */
    public static function byToken(string $token): ?array
    {
        if ($token === '' || strlen($token) > 128) {
            return null;
        }
        $hash = self::hash($token);
        // Cache 30 detik: jalur scan tidak perlu query perangkat tiap request. Cabut/pasang ulang menghapus cache.
        return \App\Core\Cache::remember('device:' . $hash, 30, static fn () => DB::one(self::SELECT . ' WHERE d.token_hash = ?', [$hash]));
    }

    private static function forgetToken(int $id): void
    {
        $hash = DB::value('SELECT token_hash FROM devices WHERE id = ?', [$id]);
        if ($hash) {
            \App\Core\Cache::forget('device:' . $hash);
        }
    }

    /** Catat kondisi kiosk: ukuran antrean offline dan selisih jam perangkat (ms). */
    public static function report(int $id, string $ip, ?int $queueSize, ?int $clockOffsetMs): void
    {
        $data = ['last_seen_at' => now(), 'last_ip' => $ip];
        if ($queueSize !== null) {
            $data['queue_size'] = max(0, min(100000, $queueSize));
        }
        if ($clockOffsetMs !== null) {
            $data['clock_offset_ms'] = max(-2000000000, min(2000000000, $clockOffsetMs));
        }
        DB::update('devices', $data, 'id = ?', [$id]);
    }

    public static function touch(int $id, string $ip): void
    {
        DB::update('devices', ['last_seen_at' => now(), 'last_ip' => $ip], 'id = ?', [$id]);
    }

    public static function revoke(int $id): void
    {
        self::forgetToken($id);
        DB::update('devices', ['status' => 'revoked', 'token_hash' => null, 'pair_code' => null, 'pair_expires_at' => null, 'revoked_at' => now()], 'id = ?', [$id]);
    }

    public static function delete(int $id): void
    {
        self::forgetToken($id);
        DB::run('DELETE FROM devices WHERE id = ?', [$id]);
    }

    public static function totals(): array
    {
        $out = ['active' => 0, 'pending' => 0, 'revoked' => 0];
        foreach (DB::all('SELECT status, COUNT(*) AS n FROM devices GROUP BY status') as $r) {
            $out[$r['status']] = (int) $r['n'];
        }
        return $out;
    }
}
