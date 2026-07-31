<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesPayoutPolicy extends Model
{
    protected $guarded = [];

    protected $casts = [
        'default_commission_rate' => 'decimal:2',
        'minimum_payout_amount' => 'decimal:2',
        'monthly_payout_day' => 'integer',
        'commission_waiting_days' => 'integer',
        'auto_approval_enabled' => 'boolean',
        'auto_payout_enabled' => 'boolean',
        'large_commission_review_threshold' => 'decimal:2',
        'last_review_at' => 'datetime',
        'last_review_approved_count' => 'integer',
        'last_review_held_count' => 'integer',
        'metadata' => 'array',
    ];

    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['id' => 1],
            [
                'default_commission_rate' => 5,
                'minimum_payout_amount' => 5000,
                'monthly_payout_day' => 5,
                'commission_waiting_days' => 7,
                'auto_approval_enabled' => true,
                'auto_payout_enabled' => false,
                'large_commission_review_threshold' => 50000,
                'currency' => 'NGN',
            ]
        );
    }
}
