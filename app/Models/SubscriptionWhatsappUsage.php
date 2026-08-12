<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionWhatsappUsage extends Model
{
    use BelongsToSchool;
    use HasFactory;

       protected $fillable = [
        'subscription_id',
        'school_id',
        'user_id',
        'cycle_start',
        'cycle_end',
        'allocated_credits',
        'used_credits',
    ];

    protected $casts = [
        'cycle_start' => 'date',
        'cycle_end' => 'date',
        'allocated_credits' => 'integer',
        'used_credits' => 'integer',
    ];

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function remainingCredits(): int
    {
        return max(0, (int) $this->allocated_credits - (int) $this->used_credits);
    }
}

