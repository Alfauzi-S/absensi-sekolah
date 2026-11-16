<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;
use App\Models\Device;
use App\Models\Setting;
use App\Models\Student;
use App\Services\Photo;

/** Halaman kiosk scanner (tanpa login; identitas lewat token perangkat yang tersimpan di browser kiosk). */
final class KioskController extends Controller
{
    public function index(): void
    {
        View::render('kiosk/index', [
            'title' => t('Scanner'),
            'scannerMode' => Setting::get('scanner_mode'),
            'schoolName' => Setting::get('school_name') ?: config('app_name', 'Absensi Sekolah'),
        ], 'kiosk');
    }

    /** Foto siswa untuk kiosk; akses dibuktikan dengan cookie perangkat (dipasang saat pairing / /api/device/me). */
    public function photo(string $studentId): void
    {
        $device = Device::byToken((string) ($_COOKIE['absensi_device'] ?? ''));
        if (!$device || $device['status'] !== 'active') {
            http_response_code(401);
            exit;
        }
        $s = Student::find((int) $studentId);
        $path = $s ? Photo::path((int) $s['id'], query('kecil') === '1') : '';
        if ($path === '' || !is_file($path)) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: image/jpeg');
        header('Cache-Control: private, max-age=86400');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
    }
}
