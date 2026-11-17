<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database as DB;

/** Log semua scan, termasuk yang ditolak. scan_id unik → pengiriman ulang dari kiosk mengembalikan hasil yang sama. */
final class ScanLog
{
    public static function findByScanId(string $scanId): ?array
    {
        return DB::one('SELECT * FROM scan_logs WHERE scan_id = ?', [$scanId]);
    }

    /** @return bool false bila scan_id sudah ada (balapan pengiriman ulang) */
    public static function add(array $d): bool
    {
        try {
            DB::insert('scan_logs', [
                'scan_id' => $d['scan_id'] ?? null,
                'device_id' => $d['device_id'] ?? null,
                'code' => isset($d['code']) ? mb_substr((string) $d['code'], 0, 32) : null,
                'session_id' => $d['session_id'] ?? null,
                'student_id' => $d['student_id'] ?? null,
                'result' => mb_substr((string) $d['result'], 0, 20),
                'response' => isset($d['response']) ? json_encode($d['response'], JSON_UNESCAPED_UNICODE) : null,
                'scanned_at' => $d['scanned_at'] ?? null,
                'received_at' => now(),
                't_server_ms' => isset($d['t_server_ms']) ? round((float) $d['t_server_ms'], 2) : null,
                'metrics' => !empty($d['metrics']) ? json_encode($d['metrics'], JSON_UNESCAPED_UNICODE) : null,
            ]);
            return true;
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return false;
            }
            throw $e;
        }
    }

    public static function recent(int $limit = 30, ?int $deviceId = null): array
    {
        $w = $deviceId ? ' WHERE l.device_id = ?' : '';
        return DB::all('SELECT l.*, st.full_name, d.name AS device_name FROM scan_logs l LEFT JOIN students st ON st.id = l.student_id
            LEFT JOIN devices d ON d.id = l.device_id' . $w . ' ORDER BY l.id DESC LIMIT ' . max(1, $limit), $deviceId ? [$deviceId] : []);
    }

    /** Statistik kecepatan server (ms) dalam rentang waktu. */
    public static function serverStats(string $since): array
    {
        $rows = array_map('floatval', array_column(DB::all('SELECT t_server_ms FROM scan_logs WHERE received_at >= ? AND t_server_ms IS NOT NULL ORDER BY t_server_ms', [$since]), 't_server_ms'));
        $n = count($rows);
        if ($n === 0) {
            return ['n' => 0, 'avg' => 0.0, 'p95' => 0.0, 'max' => 0.0];
        }
        return ['n' => $n, 'avg' => round(array_sum($rows) / $n, 1), 'p95' => $rows[max(0, (int) ceil($n * 0.95) - 1)], 'max' => end($rows)];
    }

    public static function purge(int $days = 90): int
    {
        return DB::run('DELETE FROM scan_logs WHERE received_at < ?', [date('Y-m-d H:i:s', time() - $days * 86400)])->rowCount();
    }
}
