<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeeInvoiceReminderLog extends Model
{
    protected $fillable = [
        'school_id',
        'fee_invoice_id',
        'event',
        'attempt_no',
        'parents_targeted',
        'parents_sent',
        'sent_email',
        'sent_whatsapp',
        'provider',
        'provider_message_id',
        'status',
        'error_message',
        'error_payload',
        'meta',
        'sent_at',
    ];

    protected $casts = [
        'sent_email' => 'boolean',
        'sent_whatsapp' => 'boolean',
        'error_payload' => 'array',
        'meta' => 'array',
        'sent_at' => 'datetime',
    ];
}