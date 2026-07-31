<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CbtOfflineLicense extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'allowed_features' => 'array',
        'payload' => 'array',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(SchoolSetting::class, 'school_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
