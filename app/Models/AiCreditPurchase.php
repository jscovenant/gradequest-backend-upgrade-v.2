<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class AiCreditPurchase extends Model
{
    use BelongsToSchool;

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function usage()
    {
        return $this->belongsTo(SubscriptionAiUsage::class, 'subscription_ai_usage_id');
    }
}
