<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchoolStaffAiCreditAllocation extends Model
{
    use HasFactory, BelongsToSchool;

    protected $guarded = [];

    protected $casts = [
        'allocated_credits' => 'integer',
        'used_credits' => 'integer',
        'is_unlimited' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function allocator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allocated_by');
    }

    public function remainingCredits(): int
    {
        if ($this->is_unlimited) {
            return 999999;
        }

        return max(0, (int) $this->allocated_credits - (int) $this->used_credits);
    }

    public function hasSufficientCredits(int $cost): bool
    {
        if ($this->is_unlimited) {
            return true;
        }

        return $this->remainingCredits() >= $cost;
    }
}
