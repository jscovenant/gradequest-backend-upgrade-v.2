<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesRepresentative extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'commission_rate' => 'decimal:2',
        'core_commission_rate' => 'decimal:2',
        'premium_commission_rate' => 'decimal:2',
        'monthly_target_amount' => 'decimal:2',
        'monthly_target_schools' => 'integer',
        'joined_at' => 'date',
        'payout_verified_at' => 'datetime',
        'status_changed_at' => 'datetime',
        'reactivated_at' => 'datetime',
        'auto_disabled_at' => 'datetime',
        'closure_requested_at' => 'datetime',
        'death_reported_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(SalesRepAssignment::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(SalesCommission::class);
    }

    public function pageEvents(): HasMany
    {
        return $this->hasMany(SalesPageEvent::class, 'sales_representative_id');
    }

    public function payoutBatches(): HasMany
    {
        return $this->hasMany(SalesPayoutBatch::class);
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(SalesRepStatusEvent::class);
    }
}
