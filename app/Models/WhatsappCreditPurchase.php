<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappCreditPurchase extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'metadata' => 'array',
    ];
}
