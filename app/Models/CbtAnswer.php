<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CbtAnswer extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'selected_option_ids' => 'array',
        'is_correct' => 'boolean',
        'score' => 'decimal:2',
        'answered_at' => 'datetime',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(CbtAttempt::class, 'attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(CbtQuestion::class, 'question_id');
    }
}
