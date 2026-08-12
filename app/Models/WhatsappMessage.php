<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsappMessage extends Model
{
    use BelongsToSchool;
    use HasFactory;


       protected $fillable = [
        'school_id',
        'subscription_id',
        'parent_user_id',
        'student_user_id',
        'school_whatsapp_account_id',
        'to_phone',
        'normalized_phone',
        'template_name',
        'template_lang',
        'status',
        'meta_message_id',
        'credit_cost',
        'payload',
        'meta_response',
        'failure_reason',
        'sent_at',
        'delivered_at',
        'read_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'meta_response' => 'array',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'credit_cost' => 'integer',
    ];
}

