<?php

namespace App\Services;

use App\Models\AiCreditTransaction;
use App\Models\GradequestBillingPolicy;
use App\Models\Subscription;
use App\Models\SubscriptionAiUsage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubscriptionAiCreditService
{
    public function getActiveSchoolSubscription(int $schoolId): Subscription
    {
        $admin = User::query()
            ->where('school_id', $schoolId)
            ->where('role', 'Admin')
            ->first();

        if (! $admin) {
            throw ValidationException::withMessages(['school' => 'No admin found for this school.']);
        }

        $subscription = Subscription::query()
            ->with('plan')
            ->where('user_id', $admin->id)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->latest('id')
            ->first();

        if (! $subscription) {
            throw ValidationException::withMessages(['subscription' => 'No active subscription found for this school.']);
        }

        if (! $subscription->plan) {
            throw ValidationException::withMessages(['subscription_plan' => 'Subscription plan not found.']);
        }

        return $subscription;
    }

    public function getOrCreateCurrentCycleUsage(int $schoolId): SubscriptionAiUsage
    {
        $subscription = $this->getActiveSchoolSubscription($schoolId);
        [$cycleStart, $cycleEnd] = $this->resolveCycleDates($subscription);

        return DB::transaction(function () use ($subscription, $schoolId, $cycleStart, $cycleEnd) {
            return $this->getOrCreateUsageForSubscription($subscription, $schoolId, $cycleStart, $cycleEnd);
        });
    }

    public function allocateForSubscription(Subscription $subscription, ?string $reference = null): ?SubscriptionAiUsage
    {
        $schoolId = (int) optional($subscription->user)->school_id;
        if ($schoolId <= 0) {
            $schoolId = (int) User::query()->whereKey($subscription->user_id)->value('school_id');
        }

        if ($schoolId <= 0) {
            return null;
        }

        [$cycleStart, $cycleEnd] = $this->resolveCycleDates($subscription);

        return DB::transaction(function () use ($subscription, $schoolId, $cycleStart, $cycleEnd, $reference) {
            $usage = $this->getOrCreateUsageForSubscription($subscription, $schoolId, $cycleStart, $cycleEnd);

            if ($reference && ! AiCreditTransaction::query()->where('reference', $reference)->exists()) {
                AiCreditTransaction::query()->create([
                    'school_id' => $schoolId,
                    'subscription_ai_usage_id' => $usage->id,
                    'feature_key' => 'subscription_allocation',
                    'type' => 'allocation',
                    'credits' => (int) $usage->allocated_credits,
                    'reference' => $reference,
                    'metadata' => [
                        'subscription_id' => $subscription->id,
                        'subscription_plan_id' => $subscription->subscription_plan_id,
                    ],
                ]);
            }

            return $usage->fresh();
        });
    }

    public function costForFeature(string $featureKey): int
    {
        $policy = $this->policy();

        return match ($featureKey) {
            'ai_cbt_question_generator' => max(1, (int) $policy->ai_cbt_question_credit_cost),
            'ai_result_comment_generator' => max(1, (int) $policy->ai_result_comment_credit_cost),
            'ai_lesson_plan_generator' => max(1, (int) $policy->ai_lesson_plan_credit_cost),
            'ai_fee_collection_assistant' => max(1, (int) ($policy->ai_fee_collection_credit_cost ?? 2)),
            default => 1,
        };
    }

    public function assertCreditsAvailable(int $schoolId, string $featureKey, ?int $cost = null): SubscriptionAiUsage
    {
        $usage = $this->getOrCreateCurrentCycleUsage($schoolId);
        $cost = $cost ?? $this->costForFeature($featureKey);

        if ($usage->remainingCredits() < $cost) {
            throw ValidationException::withMessages([
                'ai_credit' => 'Insufficient AI credits. Please contact the school administrator to renew or purchase more AI credits.',
            ]);
        }

        return $usage;
    }

    public function consumeCredits(int $schoolId, string $featureKey, ?int $cost = null, ?string $reference = null, array $metadata = []): SubscriptionAiUsage
    {
        $cost = $cost ?? $this->costForFeature($featureKey);

        return DB::transaction(function () use ($schoolId, $featureKey, $cost, $reference, $metadata) {
            if ($reference && AiCreditTransaction::query()->where('reference', $reference)->exists()) {
                return $this->getOrCreateCurrentCycleUsage($schoolId);
            }

            $usage = $this->getOrCreateCurrentCycleUsage($schoolId);
            $usage = SubscriptionAiUsage::query()->lockForUpdate()->findOrFail($usage->id);

            if ($usage->remainingCredits() < $cost) {
                throw ValidationException::withMessages([
                    'ai_credit' => 'Insufficient AI credits. Please contact the school administrator to renew or purchase more AI credits.',
                ]);
            }

            $usage->update(['used_credits' => (int) $usage->used_credits + $cost]);

            AiCreditTransaction::query()->create([
                'school_id' => $schoolId,
                'subscription_ai_usage_id' => $usage->id,
                'feature_key' => $featureKey,
                'type' => 'consumption',
                'credits' => -$cost,
                'reference' => $reference ?: 'ai-consume:' . bin2hex(random_bytes(16)),
                'metadata' => $metadata,
            ]);

            return $usage->fresh();
        });
    }

    public function addPurchasedCredits(int $schoolId, int $quantity, ?string $reference = null): SubscriptionAiUsage
    {
        return DB::transaction(function () use ($schoolId, $quantity, $reference) {
            if ($reference && AiCreditTransaction::query()->where('reference', $reference)->exists()) {
                return $this->getOrCreateCurrentCycleUsage($schoolId);
            }

            $usage = $this->getOrCreateCurrentCycleUsage($schoolId);
            $usage = SubscriptionAiUsage::query()->lockForUpdate()->findOrFail($usage->id);
            $usage->increment('allocated_credits', $quantity);

            AiCreditTransaction::query()->create([
                'school_id' => $schoolId,
                'subscription_ai_usage_id' => $usage->id,
                'feature_key' => 'ai_credit_purchase',
                'type' => 'purchase',
                'credits' => $quantity,
                'reference' => $reference ?: 'ai-purchase:' . bin2hex(random_bytes(16)),
                'metadata' => ['quantity' => $quantity],
            ]);

            return $usage->fresh();
        });
    }
    public function getCreditSummary(int $schoolId): array
    {
        $usage = $this->getOrCreateCurrentCycleUsage($schoolId);
        $policy = $this->policy();

        return [
            'allocated_credits' => (int) $usage->allocated_credits,
            'used_credits' => (int) $usage->used_credits,
            'remaining_credits' => $usage->remainingCredits(),
            'cycle_start' => optional($usage->cycle_start)->toDateString(),
            'cycle_end' => optional($usage->cycle_end)->toDateString(),
            'wallet_valid_from' => optional($usage->cycle_start)->toDateString(),
            'access_valid_until' => optional($usage->cycle_end)->toDateString(),
            'credits_given_with_current_plan' => (int) $usage->allocated_credits,
            'current_package' => $usage->subscription?->plan?->name,
            'subscription_id' => (int) $usage->subscription_id,
            'ai_result_comment_credit_cost' => (int) $policy->ai_result_comment_credit_cost,
            'ai_cbt_question_credit_cost' => (int) $policy->ai_cbt_question_credit_cost,
            'ai_lesson_plan_credit_cost' => (int) $policy->ai_lesson_plan_credit_cost,
            'ai_fee_collection_credit_cost' => (int) ($policy->ai_fee_collection_credit_cost ?? 2),
            'ai_credit_unit_price' => (float) $policy->ai_credit_unit_price,
        ];
    }

    private function getOrCreateUsageForSubscription(Subscription $subscription, int $schoolId, Carbon $cycleStart, Carbon $cycleEnd): SubscriptionAiUsage
    {
        $start = $cycleStart->toDateString();
        $end = $cycleEnd->toDateString();

        $usage = SubscriptionAiUsage::query()
            ->where('subscription_id', $subscription->id)
            ->whereDate('cycle_start', $start)
            ->whereDate('cycle_end', $end)
            ->first();

        if ($usage) {
            return $usage;
        }

        $existingUsage = SubscriptionAiUsage::query()
            ->where('subscription_id', $subscription->id)
            ->latest('id')
            ->first();

        if ($existingUsage) {
            $existingUsage->update([
                'school_id' => $schoolId,
                'user_id' => $subscription->user_id,
                'cycle_start' => $start,
                'cycle_end' => $end,
            ]);

            return $existingUsage->fresh();
        }

        return SubscriptionAiUsage::query()->create([
            'subscription_id' => $subscription->id,
            'school_id' => $schoolId,
            'user_id' => $subscription->user_id,
            'cycle_start' => $start,
            'cycle_end' => $end,
            'allocated_credits' => $this->policy()->legacy_plus_ai_credits,
            'used_credits' => 0,
        ]);
    }
    private function policy(): GradequestBillingPolicy
    {
        return GradequestBillingPolicy::query()->firstOrCreate([], [
            'online_grace_days' => 14,
            'online_minimum_coverage_percent' => 70,
            'online_whole_school_block_enabled' => true,
            'online_student_level_block_enabled' => true,
            'offline_grace_days' => 7,
            'offline_school_block_enabled' => true,
            'platform_fee_per_student' => 1000,
            'whatsapp_credit_unit_price' => 10,
            'legacy_plus_ai_credits' => 100,
            'ai_result_comment_credit_cost' => 1,
            'ai_cbt_question_credit_cost' => 5,
            'ai_lesson_plan_credit_cost' => 3,
            'ai_credit_unit_price' => 25,
            'legacy_subscription_honor_enabled' => true,
            'per_student_billing_starts_at' => now(),
            'temporary_access_min_days' => 3,
            'temporary_access_max_days' => 7,
        ]);
    }

    private function resolveCycleDates(Subscription $subscription): array
    {
        $startsAt = $subscription->starts_at ? Carbon::parse($subscription->starts_at) : now();
        $endsAt = $subscription->ends_at
            ? Carbon::parse($subscription->ends_at)
            : $startsAt->copy()->addDays(max(1, (int) ($subscription->plan->duration_in_days ?? 30)));

        return [$startsAt->startOfDay(), $endsAt->endOfDay()];
    }
}







