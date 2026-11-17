<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Models\AcademicYear;
use App\Models\Master;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;

final class Validator
{
    private const DATE = '/^\d{4}-\d{2}-\d{2}$/';
    private const TIME = '/^([01]\d|2[0-3]):[0-5]\d$/';

    /** Normalisasi nomor HP: 62xxx / +62xxx → 08xxx, hapus spasi dan tanda hubung. */
    public static function normalizePhone(string $phone): string
    {
        $p = preg_replace('/[^0-9+]/', '', $phone) ?? '';
        if (str_starts_with($p, '+62')) {
            $p = '0' . substr($p, 3);
        } elseif (str_starts_with($p, '62')) {
            $p = '0' . substr($p, 2);
        }
        return $p;
    }

    public static function validDate(string $d): bool
    {
        return (bool) preg_match(self::DATE, $d) && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
    }

    public static function student(array $d, int $exceptId = 0): array
    {
        $e = [];
        $nis = trim($d['nis']);
        if ($nis === '') {
            $e['nis'] = t('NIS wajib diisi.');
        } elseif (!preg_match('/^[A-Za-z0-9.\-\/]{3,30}$/', $nis)) {
            $e['nis'] = t('NIS 3–30 karakter: huruf, angka, titik, strip, atau garis miring.');
        } elseif (Student::nisTaken($nis, $exceptId)) {
            $e['nis'] = t('NIS sudah dipakai siswa lain.');
        }
        $nisn = trim((string) ($d['nisn'] ?? ''));
        if ($nisn !== '' && !preg_match('/^\d{10}$/', $nisn)) {
            $e['nisn'] = t('NISN terdiri dari 10 digit angka.');
        }
        $name = trim($d['full_name']);
        if (mb_strlen($name) < 3) {
            $e['full_name'] = t('Tulis nama lengkap, minimal 3 huruf.');
        } elseif (mb_strlen($name) > 120) {
            $e['full_name'] = t('Nama terlalu panjang (maksimal 120 karakter).');
        }
        if (!isset(Student::GENDERS[$d['gender'] ?? ''])) {
            $e['gender'] = t('Pilih jenis kelamin (L/P).');
        }
        if (($d['birth_date'] ?? '') !== '' && !self::validDate($d['birth_date'])) {
            $e['birth_date'] = t('Format tanggal lahir tidak valid (TTTT-BB-HH).');
        }
        $phone = trim((string) ($d['parent_phone'] ?? ''));
        if ($phone !== '' && !preg_match('/^08\d{8,12}$/', $phone)) {
            $e['parent_phone'] = t('Format nomor HP belum benar. Contoh: 081234567890.');
        }
        if (!in_array($d['status'] ?? 'Aktif', Student::STATUSES, true)) {
            $e['status'] = t('Pilih status siswa.');
        }
        return $e;
    }

    public static function schoolClass(array $d, int $exceptId = 0): array
    {
        $e = [];
        $name = trim($d['name']);
        if ($name === '' || mb_strlen($name) > 30) {
            $e['name'] = t('Nama kelas wajib diisi (maksimal 30 karakter).');
        } elseif (SchoolClass::nameTaken((int) $d['academic_year_id'], $name, $exceptId)) {
            $e['name'] = t('Nama kelas sudah ada di tahun ajaran ini.');
        }
        if (!ctype_digit((string) $d['grade']) || (int) $d['grade'] < 1 || (int) $d['grade'] > 13) {
            $e['grade'] = t('Tingkat berupa angka 1–13 (SMA: 10, 11, 12).');
        }
        if (!AcademicYear::find((int) $d['academic_year_id'])) {
            $e['academic_year_id'] = t('Tahun ajaran tidak ditemukan.');
        }
        if ($d['homeroom_teacher_id']) {
            $u = User::find((int) $d['homeroom_teacher_id']);
            if (!$u || $u['role'] !== Auth::GURU) {
                $e['homeroom_teacher_id'] = t('Wali kelas harus akun berperan Guru.');
            }
        }
        if ($d['room_id'] && !Master::find('ruangan', (int) $d['room_id'])) {
            $e['room_id'] = t('Ruangan tidak ditemukan.');
        }
        return $e;
    }

    public static function academicYear(array $d, int $exceptId = 0): array
    {
        $e = [];
        if (!preg_match('#^\d{4}/\d{4}$#', $d['name'])) {
            $e['name'] = t('Format nama: 2026/2027.');
        } elseif (AcademicYear::nameTaken($d['name'], $exceptId)) {
            $e['name'] = t('Tahun ajaran ini sudah ada.');
        }
        self::dateRange($e, $d);
        return $e;
    }

    public static function semester(array $d): array
    {
        $e = [];
        if (!in_array($d['name'], ['Ganjil', 'Genap'], true)) {
            $e['name'] = t('Pilih Ganjil atau Genap.');
        }
        self::dateRange($e, $d);
        return $e;
    }

    private static function dateRange(array &$e, array $d): void
    {
        if (!self::validDate($d['start_date'] ?? '')) {
            $e['start_date'] = t('Tanggal mulai tidak valid.');
        }
        if (!self::validDate($d['end_date'] ?? '')) {
            $e['end_date'] = t('Tanggal selesai tidak valid.');
        } elseif (!isset($e['start_date']) && $d['end_date'] <= $d['start_date']) {
            $e['end_date'] = t('Tanggal selesai harus setelah tanggal mulai.');
        }
    }

    public static function schedule(array $d, array $periods): array
    {
        $e = [];
        if (!ctype_digit((string) $d['day_of_week']) || (int) $d['day_of_week'] < 1 || (int) $d['day_of_week'] > 7) {
            $e['day_of_week'] = t('Pilih hari.');
        }
        foreach (['period_start' => 'Jam mulai', 'period_end' => 'Jam selesai'] as $k => $label) {
            if (!ctype_digit((string) $d[$k]) || !isset($periods[(int) $d[$k]])) {
                $e[$k] = t(':field harus salah satu jam pelajaran yang terdaftar.', ['field' => t($label)]);
            }
        }
        if (!isset($e['period_start'], $e['period_end']) && (int) $d['period_end'] < (int) $d['period_start']) {
            $e['period_end'] = t('Jam selesai tidak boleh sebelum jam mulai.');
        }
        if (!Master::find('mapel', (int) $d['subject_id'])) {
            $e['subject_id'] = t('Pilih mata pelajaran.');
        }
        if (!empty($d['teacher_id'])) {
            $u = User::find((int) $d['teacher_id']);
            if (!$u || $u['role'] !== Auth::GURU) {
                $e['teacher_id'] = t('Pengampu harus akun berperan Guru.');
            }
        }
        if (!empty($d['room_id']) && !Master::find('ruangan', (int) $d['room_id'])) {
            $e['room_id'] = t('Ruangan tidak ditemukan.');
        }
        return $e;
    }

    public static function account(array $d, bool $isNew = true, int $exceptId = 0): array
    {
        $e = [];
        if (mb_strlen(trim($d['name'])) < 3) {
            $e['name'] = t('Nama wajib diisi, minimal 3 huruf.');
        }
        if (!in_array($d['role'], Auth::ROLES, true)) {
            $e['role'] = t('Pilih peran akun.');
        }
        if ($isNew) {
            if (!preg_match('/^[a-z0-9.]{4,40}$/', $d['username'])) {
                $e['username'] = t('Gunakan 4–40 karakter: huruf kecil, angka, atau titik.');
            } elseif (User::findByUsername($d['username'])) {
                $e['username'] = t('Username sudah dipakai akun lain.');
            }
            if (strlen($d['password']) < 8) {
                $e['password'] = t('Password sementara minimal 8 karakter.');
            }
        }
        $nip = trim((string) ($d['nip'] ?? ''));
        if ($nip !== '' && !preg_match('/^[0-9]{8,30}$/', $nip)) {
            $e['nip'] = t('NIP/NUPTK berupa 8–30 digit angka.');
        }
        $phone = trim((string) ($d['phone'] ?? ''));
        if ($phone !== '' && !preg_match('/^08\d{8,12}$/', $phone)) {
            $e['phone'] = t('Format nomor HP belum benar. Contoh: 081234567890.');
        }
        if (!in_array($d['status'] ?? 'Aktif', ['Aktif', 'Nonaktif'], true)) {
            $e['status'] = t('Pilih status akun.');
        }
        return $e;
    }

    public static function newPassword(string $password, string $confirm): ?string
    {
        if (strlen($password) < 10) {
            return t('Password baru minimal 10 karakter.');
        }
        if (!preg_match('/\d/', $password) || !preg_match('/[a-z]/i', $password)) {
            return t('Gunakan kombinasi huruf dan angka.');
        }
        if ($password !== $confirm) {
            return t('Konfirmasi password tidak sama.');
        }
        return null;
    }

    /** Pengaturan sekolah (PRD 4.3). */
    public static function settings(array $d): array
    {
        $e = [];
        if (!isset(\App\Models\Setting::ATT_MODES[$d['attendance_mode']])) {
            $e['attendance_mode'] = t('Pilih mode absensi.');
        }
        if (!isset(\App\Models\Setting::SCANNER_MODES[$d['scanner_mode']])) {
            $e['scanner_mode'] = t('Pilih jenis perangkat scanner.');
        }
        if (!isset(\App\Models\Setting::WA_MODES[$d['wa_notify']])) {
            $e['wa_notify'] = t('Pilih mode notifikasi.');
        }
        foreach (['checkin_start', 'checkin_end', 'late_after', 'checkout_start', 'checkout_end'] as $k) {
            if (!preg_match(self::TIME, $d[$k])) {
                $e[$k] = t('Format jam JJ:MM.');
            }
        }
        if (!isset($e['checkin_start'], $e['checkin_end']) && $d['checkin_end'] <= $d['checkin_start']) {
            $e['checkin_end'] = t('Harus setelah jam buka absen masuk.');
        }
        if (!isset($e['checkout_start'], $e['checkout_end']) && $d['checkout_end'] <= $d['checkout_start']) {
            $e['checkout_end'] = t('Harus setelah jam buka absen pulang.');
        }
        if (!ctype_digit((string) $d['late_tolerance_minutes']) || (int) $d['late_tolerance_minutes'] > 120) {
            $e['late_tolerance_minutes'] = t('Toleransi 0–120 menit.');
        }
        if (!isset(\App\Models\Setting::WA_PROVIDERS[$d['wa_provider']])) {
            $e['wa_provider'] = t('Pilih penyedia gateway.');
        } elseif ($d['wa_notify'] !== 'off' && $d['wa_provider'] === 'webhook' && !filter_var($d['wa_gateway_url'], FILTER_VALIDATE_URL)) {
            $e['wa_gateway_url'] = t('Isi URL gateway WhatsApp bila notifikasi dinyalakan.');
        }
        if ($d['wa_gateway_url'] !== '' && !preg_match('#^https://#i', $d['wa_gateway_url'])) {
            $e['wa_gateway_url'] = t('URL gateway harus https://');
        }
        foreach (['wa_template_alpa', 'wa_template_late'] as $k) {
            if (mb_strlen($d[$k]) < 10 || mb_strlen($d[$k]) > 600) {
                $e[$k] = t('Isi template pesan 10–600 karakter.');
            }
        }
        if (!in_array($d['portal_enabled'], ['0', '1'], true)) {
            $e['portal_enabled'] = t('Pilih nyala atau mati.');
        }
        if (mb_strlen($d['school_name']) > 120) {
            $e['school_name'] = t('Maksimal 120 karakter.');
        }
        return $e;
    }
}
