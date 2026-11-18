<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['key', 'value'];

    public const DEFAULTS = [
        'school_name' => 'Nama Sekolah',
        'school_address' => '',
        'headmaster_name' => '',
        'headmaster_nip' => '',
        'checkin_start' => '06:00',
        'late_after' => '07:10',
        'checkout_start' => '14:00',
        'checkout_end' => '17:00',
        'weekend_days' => '6,7',
        'kiosk_token' => '',
        // Absensi per jam pelajaran: off | manual (guru mengisi) | qr (guru mengisi + memindai kartu dengan kamera)
        'lesson_mode' => 'manual',
        // Siapa yang boleh mengabsen per jam: all (semua guru, semua kelas) | homeroom (hanya wali kelas di kelasnya)
        'lesson_access' => 'all',
    ];

    public const LESSON_MODES = ['off' => 'Mati', 'manual' => 'Diisi guru', 'qr' => 'Diisi guru + scan kartu'];

    public const LESSON_ACCESS = ['all' => 'Semua guru, semua kelas', 'homeroom' => 'Hanya wali kelas, di kelasnya'];

    public static function lessonsEnabled(): bool
    {
        return self::get('lesson_mode') !== 'off';
    }

    private static ?array $cache = null;

    public static function allValues(): array
    {
        if (self::$cache === null) {
            self::$cache = self::DEFAULTS;
            foreach (static::query()->get() as $row) {
                self::$cache[$row->key] = (string) $row->value;
            }
        }

        return self::$cache;
    }

    public static function get(string $key): string
    {
        return (string) (self::allValues()[$key] ?? '');
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrInsert(['key' => $key], ['value' => $value]);
        self::$cache = null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    /** Token tautan kiosk; dibuat otomatis saat pertama kali diminta. */
    public static function kioskToken(): string
    {
        $token = self::get('kiosk_token');
        if ($token === '') {
            $token = Str::random(32);
            self::put('kiosk_token', $token);
        }

        return $token;
    }

    /** @return int[] hari libur mingguan, 1 = Senin ... 7 = Minggu */
    public static function weekendDays(): array
    {
        return array_values(array_filter(array_map('intval', explode(',', self::get('weekend_days')))));
    }
}
