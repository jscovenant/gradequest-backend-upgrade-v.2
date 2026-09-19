<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiSalesOutreachLog extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'dispatched_at' => 'datetime',
        'replied_at' => 'datetime',
        'converted_at' => 'datetime',
    ];

    public function school()
    {
        return $this->belongsTo(SchoolSetting::class, 'school_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
