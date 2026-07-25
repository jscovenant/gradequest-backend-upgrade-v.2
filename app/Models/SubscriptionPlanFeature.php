<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPlanFeature extends Model
{
    use HasFactory;
    
    protected $guarded = [];

    protected $casts = [
        'is_enabled' => 'boolean',
        'limit_count' => 'integer',
    ];
    
    
      public function plan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }
}
