<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceSetting extends Model
{
    use HasFactory;

     protected $fillable = [
        'school_id',
        'staff_checkin_time',
        'grace_minutes',
        'staff_checkout_time',
        'absent_after_time',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'grace_minutes' => 'integer',
    ];
}
