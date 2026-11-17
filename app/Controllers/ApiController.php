<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\RateLimiter;
use App\Models\Device;
use App\Models\Setting;
use App\Services\Roster;
use App\Services\ScanService;
use App\Services\SessionCloser;
use App\Services\SessionResolver;

/**
 * Endpoint JSON untuk kiosk/scanner. Autentikasi: header X-Device-Token (token perangkat), bukan sesi login,
 * sehingga tidak memakai token CSRF (rute didaftarkan dengan csrf = false).
 */
final class ApiController extends Controller
{
    /** Perangkat aktif dari header; 401 bila tidak ada/salah, 403 bila dicabut. */
    private function device(): array
    {
        $token = (string) ($_SERVER['HTTP_X_DEVICE_TOKEN'] ?? '');
        $device = Device::byToken($token);
        if (!$device) {
            json_out(['ok' => false, 'state' => 'auth', 'message' => t('Perangkat belum terdaftar. Masukkan kode pasangan.')], 401);
        }
        if ($device['status'] !== 'active') {
            json_out(['ok' => false, 'state' => 'revoked', 'message' => t('Perangkat ini dicabut. Hubungi Super Admin.')], 403);
        }
        return $device;
    }

    private function cookie(string $token): void
    {
        // Cookie khusus /kiosk agar foto siswa bisa dimuat lewat <img> tanpa header; token tetap dikirim lewat header untuk API.
        setcookie('absensi_device', $token, [
            'expires' => time() + 365 * 86400, 'path' => (BASE_PATH === '' ? '' : BASE_PATH) . '/kiosk',
            'secure' => str_starts_with(FULL_URL, 'https://'), 'httponly' => true, 'samesite' => 'Strict',
        ]);
    }

    /** Kirim JSON, selesaikan request ke klien, lalu kerjakan yang tertunda (log scan, metrik) tanpa membuat kiosk menunggu. */
    private function respondThenFlush(array $out): never
    {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        if (function_exists('fastcgi_finish_request')) {
            session_write_close();
            fastcgi_finish_request();
        }
        ScanService::flushDeferred();
        exit;
    }

    /** POST /api/device/pair {code} → token. Dibatasi 10 percobaan / 10 menit per IP. */
    public function pair(): void
    {
        $body = json_body();
        $key = 'pair:' . client_ip();
        if (RateLimiter::tooMany($key, 10, 600)) {
            json_out(['ok' => false, 'state' => 'ratelimit', 'message' => t('Terlalu banyak percobaan. Coba lagi 10 menit lagi.')], 429);
        }
        $code = preg_replace('/\D/', '', (string) ($body['code'] ?? '')) ?? '';
        $res = strlen($code) === 6 ? Device::pair($code, client_ip(), (string) ($body['app_version'] ?? '')) : null;
        if (!$res) {
            RateLimiter::hit($key);
            json_out(['ok' => false, 'state' => 'badcode', 'message' => t('Kode pasangan salah atau sudah kedaluwarsa.')], 400);
        }
        RateLimiter::clear($key);
        \App\Models\AuditLog::add(null, 'device.pair', null, 'device', $res['device']['id'], ['name' => $res['device']['name'], 'room' => $res['device']['room_code']]);
        $this->cookie($res['token']);
        json_out(['ok' => true, 'token' => $res['token'], 'device' => $this->deviceOut($res['device'])]);
    }

    /**
     * GET /api/device/me?q=<antrean>&t=<jam perangkat ISO> → identitas perangkat, pengaturan scanner, sesi yang sedang berlaku.
     * Dipanggil kiosk saat mulai dan tiap 30 detik; sekaligus melaporkan ukuran antrean offline dan selisih jam perangkat.
     */
    public function me(): void
    {
        $device = $this->device();
        $now = now();
        $queue = isset($_GET['q']) && ctype_digit((string) $_GET['q']) ? (int) $_GET['q'] : null;
        $offset = query('t') !== '' && strtotime(query('t')) !== false ? (strtotime($now) - strtotime(query('t'))) * 1000 : null;
        Device::report((int) $device['id'], client_ip(), $queue, $offset);
        $this->cookie((string) $_SERVER['HTTP_X_DEVICE_TOKEN']);
        SessionCloser::maybeRun();
        $sessions = $device['room_id'] ? SessionResolver::resolve((int) $device['room_id']) : [];
        json_out([
            'ok' => true,
            'device' => $this->deviceOut($device),
            'settings' => ['scanner_mode' => Setting::get('scanner_mode'), 'attendance_mode' => Setting::get('attendance_mode'), 'school_name' => Setting::get('school_name')],
            'sessions' => array_map([ScanService::class, 'sessionOut'], $sessions),
            'server_time' => $now,
            'server_ms' => (int) round(microtime(true) * 1000),
        ]);
    }

    /** GET /api/roster?v=<versi> → kartu + sesi hari ini untuk ruangan perangkat; {unchanged:true} bila versi sama. */
    public function roster(): void
    {
        $device = $this->device();
        $r = $device['room_id'] ? Roster::forRoom((int) $device['room_id']) : ['date' => today(), 'mode' => Setting::get('attendance_mode'), 'sessions' => [], 'students' => [], 'classes' => [], 'version' => 'empty'];
        $meta = ['ok' => true, 'server_time' => now(), 'server_ms' => (int) round(microtime(true) * 1000), 'version' => $r['version']];
        if (query('v') === $r['version']) {
            json_out($meta + ['unchanged' => true]);
        }
        json_out($meta + $r);
    }

    /** POST /api/scan {scan_id, code, scanned_at, sent_at, metrics?} → hasil. Satu request, atomik, idempoten. */
    public function scan(): void
    {
        $device = $this->device();
        $body = json_body();
        $out = ScanService::scan($device, $body, null, true);
        $this->piggyback($device, $body);
        $this->respondThenFlush($out);
    }

    /** POST /api/scan/batch {sent_at, scans:[…], metrics:[…], queue_size} → hasil per scan. Untuk antrean offline; idempoten. */
    public function batch(): void
    {
        $device = $this->device();
        $body = json_body();
        $out = ScanService::batch($device, $body, null, true);
        if (isset($body['queue_size']) && is_numeric($body['queue_size'])) {
            Device::report((int) $device['id'], client_ip(), (int) $body['queue_size'], $out['clock_offset_ms']);
        }
        $this->respondThenFlush($out);
    }

    /** Metrik scan sebelumnya yang menumpang pada request scan tunggal (prev_metrics). */
    private function piggyback(array $device, array $body): void
    {
        if (!empty($body['prev_metrics']) && is_array($body['prev_metrics'])) {
            ScanService::batch($device, ['metrics' => $body['prev_metrics']], null, true);
        }
    }

    private function deviceOut(array $d): array
    {
        return ['id' => (int) $d['id'], 'name' => $d['name'], 'room' => $d['room_code'] ? $d['room_code'] . ' · ' . $d['room_name'] : null, 'room_id' => $d['room_id'] ? (int) $d['room_id'] : null];
    }
}
