<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SchoolDomainOrder extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'duration_years' => 'integer',
        'dns_configured' => 'boolean',
        'auto_renew' => 'boolean',
        'nameservers' => 'array',
        'dns_records' => 'array',
        'meta' => 'array',
        'paid_at' => 'datetime',
        'activated_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function school()
    {
        return $this->belongsTo(SchoolSetting::class, 'school_id');
    }
}
