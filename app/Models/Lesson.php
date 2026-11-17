<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lesson extends Model
{
    protected $fillable = ['class_id', 'subject_id', 'teacher_id', 'date', 'period_start', 'period_end', 'topic'];

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(LessonAttendance::class);
    }

    public function periodLabel(): string
    {
        return $this->period_start === $this->period_end
            ? 'Jam ke-'.$this->period_start
            : 'Jam ke-'.$this->period_start.'–'.$this->period_end;
    }
}
