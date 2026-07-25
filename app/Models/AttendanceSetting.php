<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceSetting extends Model
{
    use BelongsToSchool;
    use HasFactory;

     protected $fillable = [
        'school_id',
        'staff_checkin_time',
        'grace_minutes',
        'staff_checkout_time',
        'absent_after_time',
        'school_latitude',
        'school_longitude',
        'allowed_radius_meters',
        'qr_expires_seconds',
        'require_location_verification',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'grace_minutes' => 'integer',
        'school_latitude' => 'decimal:7',
        'school_longitude' => 'decimal:7',
        'allowed_radius_meters' => 'integer',
        'qr_expires_seconds' => 'integer',
        'require_location_verification' => 'boolean',
    ];
}
