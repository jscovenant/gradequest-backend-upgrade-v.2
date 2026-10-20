<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Investor extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'capital_invested' => 'decimal:2',
        'equity_percentage' => 'decimal:2',
        'recoup_percentage' => 'decimal:2',
        'tenure_years' => 'integer',
        'total_principal_repaid' => 'decimal:2',
        'total_dividends_paid' => 'decimal:2',
        'investment_date' => 'date',
    ];

    protected $appends = [
        'remaining_principal',
        'recoup_progress_percentage',
        'is_recoup_completed',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function disbursements(): HasMany
    {
        return $this->hasMany(InvestorDisbursement::class)->orderBy('payment_date', 'desc');
    }

    public function getRemainingPrincipalAttribute(): float
    {
        $rem = (float) $this->capital_invested - (float) $this->total_principal_repaid;
        return max(0.0, round($rem, 2));
    }

    public function getRecoupProgressPercentageAttribute(): float
    {
        if ((float) $this->capital_invested <= 0) {
            return 100.0;
        }

        $pct = ((float) $this->total_principal_repaid / (float) $this->capital_invested) * 100.0;
        return min(100.0, round($pct, 1));
    }

    public function getIsRecoupCompletedAttribute(): bool
    {
        return $this->remaining_principal <= 0.001;
    }
}
