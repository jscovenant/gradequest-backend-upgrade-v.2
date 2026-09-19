<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiSalesConversation extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'messages' => 'array',
        'last_interaction_at' => 'datetime',
        'message_count' => 'integer',
    ];
}
