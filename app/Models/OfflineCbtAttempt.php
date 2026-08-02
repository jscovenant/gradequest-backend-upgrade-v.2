<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflineCbtAttempt extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'answers' => 'array',
        'metadata' => 'array',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(OfflineCbtBundle::class, 'offline_cbt_bundle_id');
    }
}
