<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GradequestBillingPolicy extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'online_grace_days' => 'integer',
        'online_minimum_coverage_percent' => 'integer',
        'online_whole_school_block_enabled' => 'boolean',
        'online_student_level_block_enabled' => 'boolean',
        'offline_grace_days' => 'integer',
        'offline_school_block_enabled' => 'boolean',
        'platform_fee_per_student' => 'decimal:2',
        'legacy_subscription_honor_enabled' => 'boolean',
        'per_student_billing_starts_at' => 'datetime',
        'temporary_access_min_days' => 'integer',
        'temporary_access_max_days' => 'integer',
        'allowed_blocked_actions' => 'array',
    ];
}
