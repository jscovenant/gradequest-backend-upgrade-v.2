<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesPayoutItem extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(SalesPayoutBatch::class, 'sales_payout_batch_id');
    }

    public function commission(): BelongsTo
    {
        return $this->belongsTo(SalesCommission::class, 'sales_commission_id');
    }
}
