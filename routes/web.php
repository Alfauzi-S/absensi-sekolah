<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClassController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\KioskController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\LessonController;
use App\Http\Controllers\RecapController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/setup', [AuthController::class, 'showSetup'])->name('setup');
    Route::post('/setup', [AuthController::class, 'setup']);
});

// Kiosk scanner: dibuka di tablet/HP gerbang dengan tautan rahasia (tanpa login).
Route::prefix('kiosk/{token}')->group(function () {
    Route::get('/', [KioskController::class, 'show'])->name('kiosk');
    Route::post('/scan', [KioskController::class, 'scan'])->middleware('throttle:240,1')->name('kiosk.scan');
    Route::get('/ringkasan', [KioskController::class, 'stats'])->name('kiosk.stats');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/terbaru', [DashboardController::class, 'feed'])->name('dashboard.feed');

    Route::get('/akun', [AccountController::class, 'edit'])->name('account');
    Route::put('/akun', [AccountController::class, 'update']);

    Route::get('/absensi', [AttendanceController::class, 'index'])->name('attendance');
    Route::post('/absensi/koreksi', [AttendanceController::class, 'correct'])->name('attendance.correct');

    Route::get('/izin', [LeaveController::class, 'index'])->name('leaves.index');
    Route::get('/izin/baru', [LeaveController::class, 'create'])->name('leaves.create');
    Route::post('/izin', [LeaveController::class, 'store'])->name('leaves.store');
    Route::delete('/izin/{leave}', [LeaveController::class, 'destroy'])->name('leaves.destroy');
    Route::get('/izin/{leave}/lampiran', [LeaveController::class, 'attachment'])->name('leaves.attachment');

    Route::get('/pelajaran', [LessonController::class, 'index'])->name('lessons.index');
    Route::get('/pelajaran/baru', [LessonController::class, 'create'])->name('lessons.create');
    Route::post('/pelajaran', [LessonController::class, 'store'])->name('lessons.store');
    Route::get('/pelajaran/rekap', [LessonController::class, 'recap'])->name('lessons.recap');
    Route::get('/pelajaran/rekap/unduh', [LessonController::class, 'download'])->name('lessons.download');
    Route::get('/pelajaran/{lesson}', [LessonController::class, 'show'])->name('lessons.show');
    Route::post('/pelajaran/{lesson}/tandai', [LessonController::class, 'mark'])->name('lessons.mark');
    Route::post('/pelajaran/{lesson}/scan', [LessonController::class, 'scan'])->middleware('throttle:240,1')->name('lessons.scan');
    Route::delete('/pelajaran/{lesson}', [LessonController::class, 'destroy'])->name('lessons.destroy');

    Route::get('/rekap', [RecapController::class, 'monthly'])->name('recap');
    Route::get('/rekap/unduh', [RecapController::class, 'download'])->name('recap.download');
    Route::get('/rekap/ringkasan', [RecapController::class, 'summary'])->middleware('role:admin,tu,kepsek')->name('recap.summary');
    Route::get('/siswa/{student}', [StudentController::class, 'show'])->name('students.show')->whereNumber('student');

    Route::middleware('role:admin,tu')->group(function () {
        Route::get('/siswa', [StudentController::class, 'index'])->name('students.index');
        Route::get('/siswa/baru', [StudentController::class, 'create'])->name('students.create');
        Route::post('/siswa', [StudentController::class, 'store'])->name('students.store');
        Route::get('/siswa/{student}/ubah', [StudentController::class, 'edit'])->name('students.edit');
        Route::put('/siswa/{student}', [StudentController::class, 'update'])->name('students.update');
        Route::delete('/siswa/{student}', [StudentController::class, 'destroy'])->name('students.destroy');
        Route::post('/siswa/{student}/kartu-baru', [StudentController::class, 'reissue'])->name('students.reissue');
        Route::get('/impor', [StudentController::class, 'importForm'])->name('students.import');
        Route::post('/impor', [StudentController::class, 'import']);
        Route::get('/impor/template', [StudentController::class, 'template'])->name('students.template');
        Route::get('/kartu', [StudentController::class, 'cards'])->name('students.cards');

        Route::get('/kelas', [ClassController::class, 'index'])->name('classes.index');
        Route::get('/kelas/baru', [ClassController::class, 'create'])->name('classes.create');
        Route::post('/kelas', [ClassController::class, 'store'])->name('classes.store');
        Route::get('/kelas/{class}/ubah', [ClassController::class, 'edit'])->name('classes.edit');
        Route::put('/kelas/{class}', [ClassController::class, 'update'])->name('classes.update');
        Route::delete('/kelas/{class}', [ClassController::class, 'destroy'])->name('classes.destroy');
        Route::post('/kelas/{class}/pindah', [ClassController::class, 'move'])->name('classes.move');
    });

    Route::middleware('role:admin')->group(function () {
        Route::get('/pengguna', [UserController::class, 'index'])->name('users.index');
        Route::get('/pengguna/baru', [UserController::class, 'create'])->name('users.create');
        Route::post('/pengguna', [UserController::class, 'store'])->name('users.store');
        Route::get('/pengguna/{user}/ubah', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/pengguna/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/pengguna/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        Route::get('/pengaturan', [SettingsController::class, 'edit'])->name('settings');
        Route::put('/pengaturan', [SettingsController::class, 'update']);
        Route::post('/pengaturan/libur', [SettingsController::class, 'addHoliday'])->name('settings.holiday');
        Route::delete('/pengaturan/libur/{holiday}', [SettingsController::class, 'removeHoliday'])->name('settings.holiday.remove');
        Route::post('/pengaturan/mapel', [SettingsController::class, 'addSubject'])->name('settings.subject');
        Route::delete('/pengaturan/mapel/{subject}', [SettingsController::class, 'removeSubject'])->name('settings.subject.remove');
        Route::post('/pengaturan/jam', [SettingsController::class, 'addPeriod'])->name('settings.period');
        Route::delete('/pengaturan/jam/{period}', [SettingsController::class, 'removePeriod'])->name('settings.period.remove');
        Route::post('/pengaturan/kiosk', [SettingsController::class, 'resetKiosk'])->name('settings.kiosk');
    });
});
