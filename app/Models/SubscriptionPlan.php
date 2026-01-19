<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    use HasFactory;
    
    protected $guarded = [];
    
    protected $casts = [
    'features' => 'array',
     'duration_in_days' => 'integer',
];


        
    public function subscriptions()
{
    return $this->hasMany(Subscription::class, 'plan_id');
}

public function features()
{
    return $this->hasMany(SubscriptionPlanFeature::class);
}
}
