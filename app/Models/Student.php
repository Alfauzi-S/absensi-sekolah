<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    public const STATUSES = ['aktif' => 'Aktif', 'lulus' => 'Lulus', 'pindah' => 'Pindah', 'keluar' => 'Keluar'];

    /** Kode QR: 12 karakter tanpa huruf/angka yang mirip (0/O, 1/I/L). Sama dengan versi lama agar kartu lama tetap berlaku. */
    public const QR_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public const QR_LENGTH = 12;

    protected $fillable = ['nis', 'nisn', 'name', 'gender', 'class_id', 'qr_code', 'parent_phone', 'status'];

    protected static function booted(): void
    {
        static::creating(function (Student $s) {
            $s->qr_code ??= static::newQrCode();
        });
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(Leave::class);
    }

    public static function newQrCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < self::QR_LENGTH; $i++) {
                $code .= self::QR_ALPHABET[random_int(0, strlen(self::QR_ALPHABET) - 1)];
            }
        } while (static::where('qr_code', $code)->exists());

        return $code;
    }

    /** Hasil scan/ketikan dibuat seragam: huruf besar, tanpa spasi dan strip. */
    public static function normalizeCode(string $raw): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '');
    }

    public function prettyCode(): string
    {
        return implode('-', str_split($this->qr_code, 4));
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];

        return strtoupper(mb_substr($parts[0] ?? '', 0, 1).mb_substr($parts[1] ?? '', 0, 1));
    }
}
