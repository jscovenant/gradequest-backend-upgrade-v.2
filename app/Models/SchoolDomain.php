<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SchoolDomain extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'verified_at' => 'datetime',
        'ownership_verified_at' => 'datetime',
        'routing_verified_at' => 'datetime',
        'activated_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'consecutive_health_failures' => 'integer',
    ];

    public function school()
    {
        return $this->belongsTo(SchoolSetting::class, 'school_id');
    }
}
