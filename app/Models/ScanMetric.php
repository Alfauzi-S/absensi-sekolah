<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database as DB;

/**
 * Metrik kecepatan scan yang diukur kiosk (PRD 5.1), dikirim menumpang request berikutnya:
 * t_decode (kamera/ketikan → kode), t_feedback (kode → tanda di layar), t_roundtrip (kirim → jawaban server),
 * t_server (waktu proses server, dari scan_logs), t_total (kode → hasil final tampil).
 */
final class ScanMetric
{
    public const TARGET_USB = 300;    // ms, p95 t_feedback/t_total scanner USB
    public const TARGET_CAMERA = 800; // ms, kamera

    /** @param array<int, mixed> $items @return int jumlah baris tersimpan */
    public static function addMany(int $deviceId, array $items): int
    {
        $n = 0;
        $ms = static fn (mixed $v): ?int => is_numeric($v) && $v >= 0 ? (int) min(600000, round((float) $v)) : null;
        foreach ($items as $m) {
            if (!is_array($m)) {
                continue;
            }
            $scanId = is_string($m['scan_id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $m['scan_id']) ? $m['scan_id'] : null;
            $tServer = $scanId !== null ? DB::value('SELECT t_server_ms FROM scan_logs WHERE scan_id = ?', [$scanId]) : null;
            try {
                DB::insert('scan_metrics', [
                    'scan_id' => $scanId, 'device_id' => $deviceId,
                    'source' => in_array($m['source'] ?? '', ['usb', 'camera', 'manual'], true) ? $m['source'] : null,
                    'result' => is_string($m['result'] ?? null) ? mb_substr($m['result'], 0, 20) : null,
                    't_decode_ms' => $ms($m['t_decode'] ?? null), 't_feedback_ms' => $ms($m['t_feedback'] ?? null),
                    't_roundtrip_ms' => $ms($m['t_roundtrip'] ?? null), 't_server_ms' => $tServer !== null ? (float) $tServer : null,
                    't_total_ms' => $ms($m['t_total'] ?? null), 'local_hit' => !empty($m['local']) ? 1 : 0,
                    'queued' => !empty($m['queued']) ? 1 : 0, 'queue_wait_ms' => $ms($m['queue_wait'] ?? null), 'created_at' => now(),
                ]);
                $n++;
            } catch (\PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }
        return $n;
    }

    public static function percentile(array $sorted, float $p): float
    {
        $n = count($sorted);
        return $n === 0 ? 0.0 : (float) $sorted[max(0, min($n - 1, (int) ceil($n * $p / 100) - 1))];
    }

    /** Ringkasan satu kumpulan baris: n, p50/p95 per metrik, persen dari antrean offline. */
    public static function summarize(array $rows): array
    {
        $out = ['n' => count($rows), 'queued' => 0, 'local' => 0];
        foreach (['t_feedback_ms', 't_total_ms', 't_roundtrip_ms', 't_server_ms', 't_decode_ms'] as $col) {
            // Waktu sampai tersimpan dan bolak-balik hanya bermakna untuk scan yang langsung terkirim, bukan yang menunggu di antrean offline
            $src = in_array($col, ['t_total_ms', 't_roundtrip_ms'], true) ? array_filter($rows, static fn ($r) => empty($r['queued'])) : $rows;
            $v = array_map('floatval', array_values(array_filter(array_column($src, $col), static fn ($x) => $x !== null)));
            sort($v);
            $key = substr($col, 2, -3);
            $out[$key] = $v ? ['n' => count($v), 'p50' => self::percentile($v, 50), 'p95' => self::percentile($v, 95), 'p99' => self::percentile($v, 99)] : ['n' => 0, 'p50' => null, 'p95' => null, 'p99' => null];
        }
        foreach ($rows as $r) {
            $out['queued'] += (int) $r['queued'];
            $out['local'] += (int) $r['local_hit'];
        }
        return $out;
    }

    /** Baris metrik sejak waktu tertentu (dibatasi agar halaman tetap ringan). */
    public static function since(string $since, int $limit = 50000): array
    {
        return DB::all('SELECT device_id, source, t_decode_ms, t_feedback_ms, t_roundtrip_ms, t_server_ms, t_total_ms, local_hit, queued, substr(created_at, 1, 10) AS day
            FROM scan_metrics WHERE created_at >= ? ORDER BY id DESC LIMIT ' . $limit, [$since]);
    }

    public static function purge(int $days = 90): int
    {
        return DB::run('DELETE FROM scan_metrics WHERE created_at < ?', [date('Y-m-d H:i:s', time() - $days * 86400)])->rowCount();
    }
}
