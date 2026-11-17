<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    public const ROLES = [
        'admin' => 'Admin',
        'tu' => 'Tata Usaha',
        'kepsek' => 'Kepala Sekolah',
        'guru' => 'Guru',
    ];

    protected $fillable = ['name', 'username', 'password', 'role', 'nip', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function homeroomClasses(): HasMany
    {
        return $this->hasMany(SchoolClass::class, 'homeroom_teacher_id');
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** Boleh mengubah data master (siswa, kelas, kartu). */
    public function canManageData(): bool
    {
        return in_array($this->role, ['admin', 'tu'], true);
    }

    /** Melihat semua kelas (bukan hanya kelas perwalian). */
    public function seesAllClasses(): bool
    {
        return in_array($this->role, ['admin', 'tu', 'kepsek'], true);
    }

    /** ID kelas yang boleh dilihat; null = semua kelas. */
    public function visibleClassIds(): ?array
    {
        return $this->seesAllClasses() ? null : $this->homeroomClasses()->pluck('id')->all();
    }

    public function canViewClass(?int $classId): bool
    {
        $ids = $this->visibleClassIds();

        return $ids === null || ($classId !== null && in_array($classId, $ids, true));
    }

    /** Mengubah status kehadiran dan mencatat izin di kelas ini. */
    public function canEditClass(?int $classId): bool
    {
        if ($this->canManageData()) {
            return true;
        }

        return $this->role === 'guru' && $this->canViewClass($classId);
    }

    /** Kelas yang boleh diabsen per jam pelajaran oleh pengguna ini; null = semua kelas. */
    public function lessonClassIds(): ?array
    {
        if ($this->canManageData() || ($this->role === 'guru' && Setting::get('lesson_access') === 'all')) {
            return null;
        }
        if ($this->role === 'kepsek') {
            return [];
        }

        return $this->homeroomClasses()->pluck('id')->all();
    }

    public function canTakeLesson(?int $classId): bool
    {
        if (! Setting::lessonsEnabled()) {
            return false;
        }
        $ids = $this->lessonClassIds();

        return $ids === null || in_array($classId, $ids, true);
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? $this->role;
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];

        return strtoupper(mb_substr($parts[0] ?? '', 0, 1).mb_substr($parts[1] ?? '', 0, 1));
    }
}
