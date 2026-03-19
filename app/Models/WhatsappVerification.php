<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsappVerification extends Model
{
    use HasFactory;

     protected $fillable = [
        'school_id',
        'user_id',
        'actor_type',
        'phone',
        'normalized_phone',
        'code_hash',
        'channel',
        'expires_at',
        'verified_at',
        'attempts',
        'status',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'attempts' => 'integer',
    ];
}


