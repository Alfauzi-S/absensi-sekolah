<?php
declare(strict_types=1);

// Server bawaan PHP (php -S): biarkan file statis dilayani langsung.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\AcademicYearController;
use App\Controllers\AccountController;
use App\Controllers\AuditController;
use App\Controllers\AuthController;
use App\Controllers\CardController;
use App\Controllers\ClassController;
use App\Controllers\DashboardController;
use App\Controllers\ImportController;
use App\Controllers\InstallController;
use App\Controllers\MasterController;
use App\Controllers\ScheduleController;
use App\Controllers\SettingsController;
use App\Controllers\StudentController;
use App\Core\Router;

$r = new Router();

// Instalasi pertama (otomatis nonaktif setelah Super Admin dibuat)
$r->get('/install', [InstallController::class, 'form']);
$r->post('/install', [InstallController::class, 'install']);

// Halaman depan: aplikasi internal sekolah, langsung ke dashboard atau halaman masuk
$r->get('/', [AuthController::class, 'home']);
$r->get('/masuk', [AuthController::class, 'form']);
$r->post('/masuk', [AuthController::class, 'login']);
$r->post('/keluar', [AuthController::class, 'logout']);

// Dashboard (semua peran; isinya menyesuaikan peran)
$r->get('/admin', [DashboardController::class, 'index']);
$r->post('/admin/migrasi', [DashboardController::class, 'migrate']);

// Pengaturan: profil & password (semua), pengaturan sekolah (Super Admin)
$r->get('/admin/pengaturan', [SettingsController::class, 'index']);
$r->post('/admin/pengaturan/profil', [SettingsController::class, 'profile']);
$r->post('/admin/pengaturan/password', [SettingsController::class, 'password']);
$r->post('/admin/pengaturan/sekolah', [SettingsController::class, 'school']);

// Akun pengguna (Super Admin)
$r->get('/admin/akun', [AccountController::class, 'index']);
$r->get('/admin/akun/baru', [AccountController::class, 'create']);
$r->post('/admin/akun/baru', [AccountController::class, 'store']);
$r->get('/admin/akun/{id}', [AccountController::class, 'show']);
$r->get('/admin/akun/{id}/ubah', [AccountController::class, 'edit']);
$r->post('/admin/akun/{id}/ubah', [AccountController::class, 'update']);
$r->post('/admin/akun/{id}/status', [AccountController::class, 'toggle']);
$r->post('/admin/akun/{id}/reset', [AccountController::class, 'reset']);

// Audit log (Super Admin)
$r->get('/admin/audit', [AuditController::class, 'index']);

// Tahun ajaran & semester (Super Admin, TU)
$r->get('/admin/tahun-ajaran', [AcademicYearController::class, 'index']);
$r->post('/admin/tahun-ajaran/baru', [AcademicYearController::class, 'store']);
$r->post('/admin/tahun-ajaran/{id}/ubah', [AcademicYearController::class, 'update']);
$r->post('/admin/tahun-ajaran/{id}/aktifkan', [AcademicYearController::class, 'activate']);
$r->post('/admin/tahun-ajaran/{id}/hapus', [AcademicYearController::class, 'destroy']);
$r->post('/admin/tahun-ajaran/{id}/semester', [AcademicYearController::class, 'semesterStore']);
$r->post('/admin/semester/{id}/ubah', [AcademicYearController::class, 'semesterUpdate']);
$r->post('/admin/semester/{id}/aktifkan', [AcademicYearController::class, 'semesterActivate']);
$r->post('/admin/semester/{id}/hapus', [AcademicYearController::class, 'semesterDestroy']);

// Kelas (lihat: semua peran sesuai cakupan; kelola: Super Admin, TU)
$r->get('/admin/kelas', [ClassController::class, 'index']);
$r->get('/admin/kelas/baru', [ClassController::class, 'create']);
$r->post('/admin/kelas/baru', [ClassController::class, 'store']);
$r->get('/admin/kelas/{id}', [ClassController::class, 'show']);
$r->get('/admin/kelas/{id}/ubah', [ClassController::class, 'edit']);
$r->post('/admin/kelas/{id}/ubah', [ClassController::class, 'update']);
$r->post('/admin/kelas/{id}/hapus', [ClassController::class, 'destroy']);
$r->post('/admin/kelas/{id}/anggota', [ClassController::class, 'addMember']);
$r->post('/admin/kelas/{id}/anggota/{student}/keluarkan', [ClassController::class, 'removeMember']);

// Siswa
$r->get('/admin/siswa', [StudentController::class, 'index']);
$r->get('/admin/siswa/ekspor', [StudentController::class, 'export']);
$r->get('/admin/siswa/baru', [StudentController::class, 'create']);
$r->post('/admin/siswa/baru', [StudentController::class, 'store']);
$r->get('/admin/siswa/{id}', [StudentController::class, 'show']);
$r->get('/admin/siswa/{id}/ubah', [StudentController::class, 'edit']);
$r->post('/admin/siswa/{id}/ubah', [StudentController::class, 'update']);
$r->post('/admin/siswa/{id}/hapus', [StudentController::class, 'destroy']);
$r->get('/admin/siswa/{id}/foto', [CardController::class, 'photo']);
$r->post('/admin/siswa/{id}/foto/hapus', [StudentController::class, 'deletePhoto']);

// Kartu QR siswa (Super Admin, TU)
$r->get('/admin/kartu', [CardController::class, 'index']);
$r->get('/admin/kartu/cetak', [CardController::class, 'print']);
$r->post('/admin/kartu/terbitkan', [CardController::class, 'issueBulk']);
$r->post('/admin/siswa/{id}/kartu', [CardController::class, 'issue']);
$r->post('/admin/siswa/{id}/kartu/cabut', [CardController::class, 'revoke']);

// Data master sederhana: ruangan, mapel, jam, libur
$r->get('/admin/master/{type}', [MasterController::class, 'index']);
$r->post('/admin/master/{type}/baru', [MasterController::class, 'store']);
$r->post('/admin/master/{type}/{id}/ubah', [MasterController::class, 'update']);
$r->post('/admin/master/{type}/{id}/hapus', [MasterController::class, 'destroy']);

// Jadwal pelajaran
$r->get('/admin/jadwal', [ScheduleController::class, 'index']);
$r->get('/admin/jadwal/baru', [ScheduleController::class, 'create']);
$r->post('/admin/jadwal/baru', [ScheduleController::class, 'store']);
$r->get('/admin/jadwal/{id}/ubah', [ScheduleController::class, 'edit']);
$r->post('/admin/jadwal/{id}/ubah', [ScheduleController::class, 'update']);
$r->post('/admin/jadwal/{id}/hapus', [ScheduleController::class, 'destroy']);

// Impor massal
$r->get('/admin/impor', [ImportController::class, 'index']);
$r->post('/admin/impor/siswa', [ImportController::class, 'students']);
$r->post('/admin/impor/jadwal', [ImportController::class, 'schedules']);
$r->get('/admin/impor/template/{kind}', [ImportController::class, 'template']);

$r->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', Router::currentPath());
