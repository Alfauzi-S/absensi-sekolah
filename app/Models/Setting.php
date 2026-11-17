<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database as DB;

/**
 * Pengaturan sekolah yang dipilih pengurus (PRD 4.3). Disimpan sebagai pasangan kunci-nilai,
 * dibaca sekali per request dan di-cache.
 */
final class Setting
{
    public const ATT_MODE_PERIOD = 'per_jam';
    public const ATT_MODE_DAILY = 'masuk_pulang';
    public const ATT_MODE_BOTH = 'keduanya';

    public const ATT_MODES = [
        self::ATT_MODE_DAILY => 'Masuk dan pulang',
        self::ATT_MODE_PERIOD => 'Per jam pelajaran',
        self::ATT_MODE_BOTH => 'Keduanya',
    ];

    public const SCANNER_MODES = ['usb' => 'Scanner USB/Bluetooth', 'camera' => 'Kamera', 'both' => 'Keduanya'];
    public const WA_MODES = ['off' => 'Mati', 'alpa' => 'Hanya Alpa', 'terlambat' => 'Hanya Terlambat', 'both' => 'Alpa dan Terlambat'];
    public const WA_PROVIDERS = ['sandbox' => 'Sandbox (uji, tidak benar-benar mengirim)', 'fonnte' => 'Fonnte', 'webhook' => 'Webhook JSON (gateway lain)'];
    public const WA_TEMPLATE_ALPA = 'Assalamualaikum Bapak/Ibu, kami informasikan {nama} ({kelas}) tidak hadir tanpa keterangan (Alpa) pada {tanggal}. Mohon konfirmasi ke wali kelas. — {sekolah}';
    public const WA_TEMPLATE_LATE = 'Assalamualaikum Bapak/Ibu, kami informasikan {nama} ({kelas}) terlambat {menit} menit pada {tanggal} (scan pukul {jam}). — {sekolah}';

    /** Nilai bawaan; kunci yang tidak ada di sini tidak bisa disimpan. */
    public const DEFAULTS = [
        'school_name' => '',
        'school_npsn' => '',
        'school_address' => '',
        'headmaster_name' => '',
        'attendance_mode' => self::ATT_MODE_DAILY,
        'checkin_start' => '06:00',
        'checkin_end' => '07:30',
        'late_after' => '07:00',
        'checkout_start' => '14:00',
        'checkout_end' => '16:00',
        'late_tolerance_minutes' => '10',
        'scanner_mode' => 'both',
        'wa_notify' => 'off',
        'wa_gateway_url' => '',
        'wa_gateway_token' => '',
        'wa_provider' => 'sandbox',
        'wa_template_alpa' => self::WA_TEMPLATE_ALPA,
        'wa_template_late' => self::WA_TEMPLATE_LATE,
        'portal_enabled' => '0',
        'weekend_days' => '6,7',
    ];

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $rows = DB::tableExists('settings') ? DB::all('SELECT skey, svalue FROM settings') : [];
        $out = self::DEFAULTS;
        foreach ($rows as $r) {
            if (array_key_exists($r['skey'], self::DEFAULTS)) {
                $out[$r['skey']] = (string) $r['svalue'];
            }
        }
        return self::$cache = $out;
    }

    public static function get(string $key, ?string $default = null): string
    {
        return self::all()[$key] ?? ($default ?? (self::DEFAULTS[$key] ?? ''));
    }

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    /** @return int[] hari libur mingguan (ISO 1–7) */
    public static function weekendDays(): array
    {
        return array_values(array_filter(array_map('intval', explode(',', self::get('weekend_days'))), static fn (int $d) => $d >= 1 && $d <= 7));
    }

    public static function set(string $key, string $value): void
    {
        if (!array_key_exists($key, self::DEFAULTS)) {
            throw new \InvalidArgumentException('Pengaturan tidak dikenal: ' . $key);
        }
        $exists = DB::value('SELECT skey FROM settings WHERE skey = ?', [$key]);
        if ($exists) {
            DB::update('settings', ['svalue' => $value, 'updated_at' => now()], 'skey = ?', [$key]);
        } else {
            DB::insert('settings', ['skey' => $key, 'svalue' => $value, 'updated_at' => now()]);
        }
        self::$cache = null;
    }

    /** @param array<string,string> $values */
    public static function setMany(array $values): void
    {
        foreach ($values as $k => $v) {
            self::set($k, $v);
        }
    }

    public static function forget(): void
    {
        self::$cache = null;
    }
}
