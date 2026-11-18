<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Leave extends Model
{
    public const KINDS = ['S' => 'Sakit', 'I' => 'Izin', 'D' => 'Dispensasi'];

    protected $fillable = ['student_id', 'kind', 'date_from', 'date_to', 'reason', 'attachment', 'created_by'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }
}
