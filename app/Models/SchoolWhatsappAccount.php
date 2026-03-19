<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SchoolWhatsappAccount extends Model
{
    use HasFactory;

      protected $guarded = [];

    protected $casts = [
        'connected_at' => 'datetime',
        'last_health_check_at' => 'datetime',
        'meta_payload' => 'array',
    ];
}

