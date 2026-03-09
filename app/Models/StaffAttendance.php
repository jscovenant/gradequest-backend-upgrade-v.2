<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StaffAttendance extends Model
{
    use HasFactory;

     protected $fillable = [
        'school_id',
        'user_id',
        'att_date',
        'check_in_at',
        'check_out_at',
        'status',
        'source',
        'device_id',
        'notes',
    ];

    protected $casts = [
        'att_date' => 'date',
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
    ];

    public function staff()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
