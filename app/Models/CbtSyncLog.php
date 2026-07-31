<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CbtSyncLog extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'summary' => 'array',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(SchoolSetting::class, 'school_id');
    }

    public function offlineLicense(): BelongsTo
    {
        return $this->belongsTo(CbtOfflineLicense::class, 'offline_license_id');
    }
}
