<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesCommission extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope('commissionable_revenue', function ($query) {
            $query->whereIn('source', ['subscription', 'core_platform_fee']);
        });
    }

    protected $guarded = [];

    protected $casts = [
        'commissionable_amount' => 'decimal:2',
        'commission_rate' => 'decimal:2',
        'amount' => 'decimal:2',
        'earned_at' => 'datetime',
        'eligible_at' => 'datetime',
        'approved_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'paid_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function representative(): BelongsTo
    {
        return $this->belongsTo(SalesRepresentative::class, 'sales_representative_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(SchoolSetting::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function subPayment(): BelongsTo
    {
        return $this->belongsTo(SubPayment::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payoutItem()
    {
        return $this->hasOne(SalesPayoutItem::class);
    }
}
