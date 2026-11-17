<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\Access;
use App\Services\Validator;

/** Pengaturan: profil dan password (semua peran) + pengaturan sekolah (Super Admin, PRD 4.3). */
final class SettingsController extends Controller
{
    public function index(): void
    {
        $user = Auth::require();
        $this->render('admin/settings', [
            'title' => t('Pengaturan'), 'active' => 'settings',
            'canSchool' => Access::can($user, 'settings'),
            's' => Setting::all(), 'errors' => $_SESSION['settings_errors'] ?? [],
        ]);
        unset($_SESSION['settings_errors']);
    }

    public function profile(): void
    {
        $user = Auth::require();
        $name = trim(input('name'));
        $phone = Validator::normalizePhone(input('phone'));
        if (mb_strlen($name) < 3) {
            flash('bad', 'Nama minimal 3 karakter');
        } elseif ($phone !== '' && !preg_match('/^08\d{8,12}$/', $phone)) {
            flash('bad', 'Format nomor HP belum benar');
        } else {
            User::update((int) $user['id'], ['name' => mb_substr($name, 0, 120), 'phone' => $phone ?: null]);
            flash('ok', 'Profil disimpan');
        }
        redirect('admin/pengaturan');
    }

    public function password(): void
    {
        $user = Auth::require();
        $this->changeOwnPassword($user, 'admin/pengaturan');
    }

    public function school(): void
    {
        $user = $this->allow('settings');
        $d = [];
        foreach (Setting::DEFAULTS as $k => $default) {
            if (in_array($k, Setting::SYSTEM_KEYS, true)) {
                continue;
            }
            $d[$k] = trim(input($k, $default));
        }
        $d['weekend_days'] = implode(',', array_map('intval', array_filter((array) ($_POST['weekend_days'] ?? []), 'is_numeric')));
        $errors = Validator::settings($d);
        if ($errors) {
            $_SESSION['settings_errors'] = $errors;
            flash('bad', 'Pengaturan belum disimpan', t('Periksa kolom yang ditandai.'));
            redirect('admin/pengaturan');
        }
        if ($d['wa_gateway_token'] === '' ) {
            unset($d['wa_gateway_token']); // kosong = jangan timpa token yang ada
        }
        $before = Setting::all();
        Setting::setMany($d);
        $changed = array_keys(array_filter($d, static fn ($v, $k) => ($before[$k] ?? null) !== $v && $k !== 'wa_gateway_token', ARRAY_FILTER_USE_BOTH));
        AuditLog::add($user, 'settings.update', null, 'settings', null, ['changed' => $changed]);
        flash('ok', 'Pengaturan sekolah disimpan', $changed ? t('Berlaku untuk data mulai hari berikutnya.') : t('Tidak ada perubahan.'));
        redirect('admin/pengaturan');
    }
}
