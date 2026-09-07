<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GradequestBillingPolicy extends Model
{
    use HasFactory;

    protected $table = 'gradequest_billing_policies';

    protected $guarded = [];

    protected $casts = [
        'online_grace_days' => 'integer',
        'online_minimum_coverage_percent' => 'integer',
        'online_whole_school_block_enabled' => 'boolean',
        'online_student_level_block_enabled' => 'boolean',
        'offline_grace_days' => 'integer',
        'offline_school_block_enabled' => 'boolean',
        'platform_fee_per_student' => 'decimal:2',
        'whatsapp_credit_unit_price' => 'decimal:2',
        'legacy_plus_ai_credits' => 'integer',
        'ai_result_comment_credit_cost' => 'integer',
        'ai_cbt_question_credit_cost' => 'integer',
        'ai_lesson_plan_credit_cost' => 'integer',
        'ai_scheme_work_credit_cost' => 'integer',
        'ai_lesson_note_credit_cost' => 'integer',
        'ai_fee_collection_credit_cost' => 'integer',
        'ai_credit_unit_price' => 'decimal:2',
        'legacy_subscription_honor_enabled' => 'boolean',
        'per_student_billing_starts_at' => 'datetime',
        'temporary_access_min_days' => 'integer',
        'temporary_access_max_days' => 'integer',
        'allowed_blocked_actions' => 'array',
        'promo_enabled' => 'boolean',
        'promo_min_students' => 'integer',
        'promo_bonus_days' => 'integer',
        'promo_starts_at' => 'datetime',
        'promo_ends_at' => 'datetime',
        'promo_max_claims' => 'integer',
        'promo_claims_count' => 'integer',
        'sales_partner_term_1_commission_rate' => 'decimal:2',
        'sales_partner_retention_commission_rate' => 'decimal:2',
        'basic_tier_price_per_student' => 'decimal:2',
        'standard_cbt_tier_price_per_student' => 'decimal:2',
        'annual_full_session_multiplier' => 'decimal:2',
        'annual_session_discount_percent' => 'decimal:2',
    ];
}
