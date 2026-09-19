<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SchoolAdmissionPayment extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'school_amount' => 'decimal:2',
        'platform_fee' => 'decimal:2',
        'paid_at' => 'datetime',
        'settled_at' => 'datetime',
        'gateway_response' => 'array',
    ];

    public function school()
    {
        return $this->belongsTo(SchoolSetting::class, 'school_id');
    }

    public function application()
    {
        return $this->belongsTo(SchoolAdmissionApplication::class, 'application_id');
    }
}
