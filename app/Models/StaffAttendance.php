<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StaffAttendance extends Model
{
    use BelongsToSchool;
    use HasFactory;

     protected $fillable = [
        'school_id',
        'user_id',
        'attendance_session_id',
        'att_date',
        'check_in_at',
        'check_in_latitude',
        'check_in_longitude',
        'check_in_distance_meters',
        'check_out_at',
        'check_out_latitude',
        'check_out_longitude',
        'check_out_distance_meters',
        'status',
        'source',
        'device_id',
        'location_verified',
        'notes',
    ];

    protected $casts = [
        'att_date' => 'date',
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
        'check_in_latitude' => 'decimal:7',
        'check_in_longitude' => 'decimal:7',
        'check_out_latitude' => 'decimal:7',
        'check_out_longitude' => 'decimal:7',
        'check_in_distance_meters' => 'integer',
        'check_out_distance_meters' => 'integer',
        'location_verified' => 'boolean',
    ];

    public function staff()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
