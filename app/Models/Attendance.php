<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    public const STATUSES = [
        'H' => 'Hadir',
        'T' => 'Terlambat',
        'S' => 'Sakit',
        'I' => 'Izin',
        'D' => 'Dispensasi',
        'A' => 'Alpa',
    ];

    protected $fillable = [
        'student_id', 'class_id', 'date', 'status', 'check_in_at', 'check_out_at', 'late_minutes',
        'source', 'leave_id', 'corrected', 'note', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'corrected' => 'boolean',
            'late_minutes' => 'integer',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function leave(): BelongsTo
    {
        return $this->belongsTo(Leave::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
