<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Device;
use App\Models\ScanLog;
use App\Models\ScanMetric;

/** Dashboard kecepatan scan (Super Admin): p50/p95 per perangkat dan per hari, perangkat lambat, antrean offline. */
final class SpeedController extends Controller
{
    public const RANGES = ['1' => 'Hari ini', '7' => '7 hari', '30' => '30 hari'];

    public function index(): void
    {
        $this->allow('accounts');
        $days = array_key_exists(query('hari'), self::RANGES) ? (int) query('hari') : 1;
        $since = date('Y-m-d 00:00:00', time() - ($days - 1) * 86400);
        $rows = ScanMetric::since($since);
        $byDevice = [];
        $byDay = [];
        foreach ($rows as $r) {
            $byDevice[(int) $r['device_id']][] = $r;
            $byDay[$r['day']][] = $r;
        }
        $devices = [];
        foreach (Device::all() as $d) {
            $m = ScanMetric::summarize($byDevice[(int) $d['id']] ?? []);
            $sources = array_count_values(array_filter(array_column($byDevice[(int) $d['id']] ?? [], 'source')));
            arsort($sources);
            $main = array_key_first($sources) ?? 'usb';
            $target = $main === 'camera' ? ScanMetric::TARGET_CAMERA : ScanMetric::TARGET_USB;
            $devices[] = $d + ['m' => $m, 'main_source' => $main, 'target' => $target,
                'slow' => $m['feedback']['n'] >= 20 && $m['feedback']['p95'] > $target,
                'stale' => $d['status'] === 'active' && (!$d['last_seen_at'] || strtotime($d['last_seen_at']) < time() - 300)];
        }
        usort($devices, static fn ($a, $b) => [$b['m']['n'], $a['name']] <=> [$a['m']['n'], $b['name']]);
        krsort($byDay);
        $this->render('admin/speed', [
            'title' => t('Kecepatan Scan'), 'active' => 'speed', 'days' => $days, 'devices' => $devices,
            'total' => ScanMetric::summarize($rows), 'byDay' => array_map([ScanMetric::class, 'summarize'], $byDay),
            'server' => ScanLog::serverStats($since), 'rowCount' => count($rows),
        ]);
    }
}
