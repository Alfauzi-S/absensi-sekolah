<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolClass extends Model
{
    protected $table = 'school_classes';

    protected $fillable = ['name', 'grade', 'homeroom_teacher_id'];

    public function homeroomTeacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'homeroom_teacher_id');
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'class_id');
    }

    public function activeStudents(): HasMany
    {
        return $this->students()->where('status', 'aktif')->orderBy('name');
    }

    /** Kelas yang boleh dilihat pengguna, urut nama. */
    public static function visibleTo(User $user)
    {
        $ids = $user->visibleClassIds();

        return static::query()
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('grade')->orderBy('name');
    }
}
