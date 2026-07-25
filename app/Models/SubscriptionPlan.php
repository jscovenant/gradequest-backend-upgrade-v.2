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
        'price' => 'decimal:2',
        'price_per_student' => 'decimal:2',
        'max_students' => 'integer',
        'max_teachers' => 'integer',
        'whatsapp_enabled' => 'boolean',
        'whatsapp_monthly_credits' => 'integer',
        'is_active' => 'boolean',
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
