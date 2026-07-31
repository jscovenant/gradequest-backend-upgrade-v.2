<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesPayoutBatch extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'minimum_payout_amount' => 'decimal:2',
        'commission_count' => 'integer',
        'paystack_response' => 'array',
        'period_start' => 'date',
        'period_end' => 'date',
        'approved_at' => 'datetime',
        'initiated_at' => 'datetime',
        'processed_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function representative(): BelongsTo
    {
        return $this->belongsTo(SalesRepresentative::class, 'sales_representative_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesPayoutItem::class);
    }
}
