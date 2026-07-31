<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CbtAttempt extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'score' => 'decimal:2',
        'total_marks' => 'decimal:2',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'expires_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(CbtExam::class, 'exam_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(SchoolSetting::class, 'school_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(CbtAnswer::class, 'attempt_id');
    }
}
