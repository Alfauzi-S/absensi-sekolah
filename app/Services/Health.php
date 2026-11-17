<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database as DB;
use App\Core\Migrator;
use App\Models\ScanMetric;
use App\Models\Setting;

/**
 * Pemeriksaan kesehatan sistem untuk pemantauan minggu pertama go-live (PRD Fase 7).
 * Setiap pemeriksaan: key, label, level (ok|warn|bad), value (ringkas), detail (kalimat), list (opsional).
 */
final class Health
{
    public const SCHEDULER_WARN_MIN = 15;
    public const SCHEDULER_BAD_MIN = 60;
    public const BACKUP_BAD_HOURS = 26;
    public const DEVICE_STALE_MIN = 10;

    /** @return array<int, array{key:string,label:string,level:string,value:string,detail:string,list?:array}> */
    public static function checks(?string $now = null): array
    {
        $now ??= now();
        $out = [];
        foreach (['database', 'migrations', 'scheduler', 'backup', 'disk', 'sessions', 'devices', 'speed', 'whatsapp'] as $k) {
            try {
                $out[] = ['key' => $k] + self::$k($now);
            } catch (\Throwable $e) {
                $out[] = ['key' => $k, 'label' => $k, 'level' => 'bad', 'value' => t('Gagal diperiksa'), 'detail' => mb_substr($e->getMessage(), 0, 200)];
            }
        }
        return $out;
    }

    /** Ringkasan untuk /healthz (tanpa data pribadi). */
    public static function summary(?string $now = null): array
    {
        $now ??= now();
        $db = false;
        try {
            $db = (int) DB::value('SELECT 1') === 1;
        } catch (\Throwable) {
        }
        $sched = $db ? Setting::get('scheduler_last_at') : '';
        $backup = $db ? Setting::get('backup_last_at') : '';
        return [
            'ok' => $db, 'db' => $db, 'time' => $now,
            'scheduler_age_min' => $sched !== '' ? (int) floor((strtotime($now) - strtotime($sched)) / 60) : null,
            'backup_age_hours' => $backup !== '' ? round((strtotime($now) - strtotime($backup)) / 3600, 1) : null,
        ];
    }

    private static function database(string $now): array
    {
        $t = hrtime(true);
        DB::value('SELECT COUNT(*) FROM students');
        $ms = (hrtime(true) - $t) / 1e6;
        if (DB::driver() === 'sqlite') {
            $size = (int) @filesize((string) config('db.sqlite_path'));
            $ver = 'SQLite ' . DB::value('SELECT sqlite_version()');
        } else {
            $size = (int) DB::value('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE()');
            $ver = 'MariaDB/MySQL ' . DB::value('SELECT VERSION()');
        }
        return ['label' => t('Database'), 'level' => $ms > 200 ? 'warn' : 'ok', 'value' => self::bytes($size),
            'detail' => t(':v · kueri uji :ms ms', ['v' => $ver, 'ms' => number_format($ms, 1, ',', '.')])];
    }

    private static function migrations(string $now): array
    {
        $p = Migrator::pending();
        return ['label' => t('Migrasi database'), 'level' => $p ? 'bad' : 'ok', 'value' => $p ? t(':n tertunda', ['n' => count($p)]) : t('Terbaru'),
            'detail' => $p ? t('Jalankan php bin/migrate.php --yes setelah membuat cadangan.') : t('Skema database sesuai versi aplikasi.')];
    }

    private static function scheduler(string $now): array
    {
        $last = Setting::get('scheduler_last_at');
        if ($last === '') {
            return ['label' => t('Penjadwal (cron)'), 'level' => 'warn', 'value' => t('Belum pernah jalan'),
                'detail' => t('Jalankan bin/close-sessions.php tiap 5 menit lewat cron, atau service scheduler di Docker. Tanpa ini sesi hanya ditutup saat kiosk menyala dan cadangan harian tidak dibuat.')];
        }
        $age = (int) floor((strtotime($now) - strtotime($last)) / 60);
        $level = $age >= self::SCHEDULER_BAD_MIN ? 'bad' : ($age >= self::SCHEDULER_WARN_MIN ? 'warn' : 'ok');
        $rep = json_decode(Setting::get('scheduler_last_report'), true);
        $errs = [];
        foreach (is_array($rep) ? $rep : [] as $k => $v) {
            if (is_array($v) && isset($v['error'])) {
                $errs[] = $k . ': ' . $v['error'];
                $level = 'bad';
            }
        }
        return ['label' => t('Penjadwal (cron)'), 'level' => $level, 'value' => t(':n menit lalu', ['n' => $age]),
            'detail' => $errs ? t('Putaran terakhir ada yang gagal.') : t('Terakhir jalan :t. Normalnya tiap 5 menit.', ['t' => datetime_id($last)]), 'list' => $errs];
    }

    private static function backup(string $now): array
    {
        $b = Backup::latest();
        if (!$b) {
            return ['label' => t('Cadangan harian'), 'level' => 'bad', 'value' => t('Belum ada'), 'detail' => t('Buat cadangan sekarang, lalu pastikan penjadwal berjalan agar cadangan dibuat tiap malam.')];
        }
        $hours = (strtotime($now) - $b['time']) / 3600;
        return ['label' => t('Cadangan harian'), 'level' => $hours > self::BACKUP_BAD_HOURS ? 'bad' : 'ok', 'value' => t(':n jam lalu', ['n' => number_format($hours, 1, ',', '.')]),
            'detail' => t(':f (:s) · :c cadangan tersimpan di storage/backups. Salin juga ke tempat lain (disk lain/awan).', ['f' => $b['name'], 's' => self::bytes($b['size']), 'c' => count(Backup::list())])];
    }

    private static function disk(string $now): array
    {
        $free = (float) @disk_free_space(Backup::storage());
        $total = (float) @disk_total_space(Backup::storage());
        $level = $free < 200 * 1048576 ? 'bad' : ($free < 1024 * 1048576 ? 'warn' : 'ok');
        return ['label' => t('Ruang disk'), 'level' => $total > 0 ? $level : 'warn', 'value' => self::bytes((int) $free) . ' ' . t('kosong'),
            'detail' => $total > 0 ? t('dari :t (:p% terpakai)', ['t' => self::bytes((int) $total), 'p' => (int) round(($total - $free) / $total * 100)]) : t('Tidak terbaca')];
    }

    private static function sessions(string $now): array
    {
        $limit = date('Y-m-d H:i:s', strtotime($now) - 30 * 60);
        $rows = DB::all("SELECT s.id, s.kind, s.ends_at, c.name AS class_name FROM sessions s JOIN classes c ON c.id = s.class_id WHERE s.status = 'open' AND s.ends_at < ? ORDER BY s.ends_at LIMIT 10", [$limit]);
        $n = (int) DB::value("SELECT COUNT(*) FROM sessions WHERE status = 'open' AND ends_at < ?", [$limit]);
        $today = (int) DB::value('SELECT COUNT(*) FROM sessions WHERE session_date = ?', [substr($now, 0, 10)]);
        return ['label' => t('Sesi absensi'), 'level' => $n > 0 ? 'bad' : 'ok', 'value' => $n > 0 ? t(':n belum ditutup', ['n' => $n]) : t(':n sesi hari ini', ['n' => $today]),
            'detail' => $n > 0 ? t('Sesi yang sudah lewat > 30 menit masih terbuka: penjadwal kemungkinan tidak berjalan.') : t('Semua sesi yang sudah lewat sudah ditutup.'),
            'list' => array_map(static fn ($r) => $r['class_name'] . ' · ' . $r['ends_at'], $rows)];
    }

    private static function devices(string $now): array
    {
        $rows = DB::all("SELECT d.name, d.last_seen_at, d.queue_size, d.clock_offset_ms, r.code AS room_code FROM devices d LEFT JOIN rooms r ON r.id = d.room_id WHERE d.status = 'active' ORDER BY d.name");
        $school = self::schoolHours($now);
        $stale = $queued = $clock = [];
        foreach ($rows as $d) {
            $label = $d['name'] . ($d['room_code'] ? ' (' . $d['room_code'] . ')' : '');
            if (!$d['last_seen_at'] || strtotime($d['last_seen_at']) < strtotime($now) - self::DEVICE_STALE_MIN * 60) {
                $stale[] = $label . ' · ' . ($d['last_seen_at'] ? t('terakhir :t', ['t' => substr($d['last_seen_at'], 0, 16)]) : t('belum pernah'));
            }
            if ((int) $d['queue_size'] > 0) {
                $queued[] = $label . ' · ' . t(':n scan menunggu terkirim', ['n' => $d['queue_size']]);
            }
            if ($d['clock_offset_ms'] !== null && abs((int) $d['clock_offset_ms']) > 120000) {
                $clock[] = $label . ' · ' . t('jam selisih :n menit', ['n' => round((int) $d['clock_offset_ms'] / 60000)]);
            }
        }
        $level = 'ok';
        if (($stale && $school) || $queued || $clock) {
            $level = 'warn';
        }
        if ($rows && $school && count($stale) === count($rows)) {
            $level = 'bad';
        }
        return ['label' => t('Perangkat scanner'), 'level' => $rows ? $level : 'warn',
            'value' => t(':on dari :n aktif', ['on' => count($rows) - count($stale), 'n' => count($rows)]),
            'detail' => !$rows ? t('Belum ada perangkat yang dipasangkan.') : ($school ? t('Perangkat dianggap mati bila tidak melapor > :m menit pada jam sekolah.', ['m' => self::DEVICE_STALE_MIN]) : t('Di luar jam sekolah perangkat mati itu wajar.')),
            'list' => array_merge($school ? $stale : [], $queued, $clock)];
    }

    private static function speed(string $now): array
    {
        $rows = DB::all("SELECT source, t_feedback_ms, t_total_ms, t_roundtrip_ms, t_server_ms, t_decode_ms, local_hit, queued FROM scan_metrics WHERE created_at >= ? AND (source = 'usb' OR source IS NULL)",
            [substr($now, 0, 10) . ' 00:00:00']);
        $m = ScanMetric::summarize($rows);
        $p95 = $m['total']['p95'];
        if ($p95 === null) {
            return ['label' => t('Kecepatan scan USB hari ini'), 'level' => 'ok', 'value' => '—', 'detail' => t('Belum ada scan hari ini.')];
        }
        return ['label' => t('Kecepatan scan USB hari ini'), 'level' => $p95 > ScanMetric::TARGET_USB ? 'warn' : 'ok',
            'value' => 'p95 ' . number_format($p95, 0, ',', '.') . ' ms', 'detail' => t(':n scan · target p95 ≤ :t ms (kode → hasil tampil)', ['n' => $m['total']['n'], 't' => ScanMetric::TARGET_USB])];
    }

    private static function whatsapp(string $now): array
    {
        if (Setting::get('wa_notify', 'off') === 'off') {
            return ['label' => t('Notifikasi WhatsApp'), 'level' => 'ok', 'value' => t('Mati'), 'detail' => t('Tidak dipakai.')];
        }
        $today = substr($now, 0, 10);
        $failed = (int) DB::value("SELECT COUNT(*) FROM notifications WHERE status = 'failed' AND notify_date >= ?", [date('Y-m-d', strtotime($today . ' -6 days'))]);
        $stuck = (int) DB::value("SELECT COUNT(*) FROM notifications WHERE status = 'pending' AND created_at < ?", [date('Y-m-d H:i:s', strtotime($now) - 3600)]);
        $sent = (int) DB::value("SELECT COUNT(*) FROM notifications WHERE status = 'sent' AND notify_date = ?", [$today]);
        return ['label' => t('Notifikasi WhatsApp'), 'level' => $stuck > 0 || $failed > 0 ? 'warn' : 'ok', 'value' => t(':n terkirim hari ini', ['n' => $sent]),
            'detail' => t('Gagal 7 hari: :f · menunggu > 1 jam: :s', ['f' => $failed, 's' => $stuck])];
    }

    /** Hari sekolah (bukan akhir pekan/libur) antara jam absen masuk dibuka dan absen pulang ditutup. */
    public static function schoolHours(string $now): bool
    {
        $date = substr($now, 0, 10);
        if (in_array((int) date('N', strtotime($date)), Setting::weekendDays(), true) || Recap::holidays($date, $date)) {
            return false;
        }
        $hm = substr($now, 11, 5);
        return $hm >= Setting::get('checkin_start', '06:00') && $hm <= Setting::get('checkout_end', '16:00');
    }

    public static function bytes(int $b): string
    {
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $i => $u) {
            if ($b < 1024 ** ($i + 1) || $u === 'TB') {
                return number_format($b / 1024 ** $i, $i ? 1 : 0, ',', '.') . ' ' . $u;
            }
        }
        return $b . ' B';
    }
}
