<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use HasFactory;
    
    protected $guarded = [];
    

public function plan()
{
    return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
}

public function user()
{
    return $this->belongsTo(User::class);
}

public function school()
{
    return $this->belongsTo(SchoolSetting::class);
}
}
