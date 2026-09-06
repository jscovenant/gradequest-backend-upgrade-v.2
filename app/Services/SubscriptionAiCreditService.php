<?php

namespace App\Services;

use App\Models\AiCreditTransaction;
use App\Models\SchoolProfitBillingPolicy;
use App\Models\Subscription;
use App\Models\SubscriptionAiUsage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubscriptionAiCreditService
{
    public function getActiveSchoolSubscription(int $schoolId): ?Subscription
    {
        $admin = User::query()
            ->where('school_id', $schoolId)
            ->where('role', 'Admin')
            ->first();

        if (! $admin) {
            return null;
        }

        return Subscription::query()
            ->with('plan')
            ->where('user_id', $admin->id)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->latest('id')
            ->first();
    }

    public function getOrCreateCurrentCycleUsage(int $schoolId): SubscriptionAiUsage
    {
        $subscription = $this->getActiveSchoolSubscription($schoolId);

        if ($subscription) {
            [$cycleStart, $cycleEnd] = $this->resolveCycleDates($subscription);
            return DB::transaction(function () use ($subscription, $schoolId, $cycleStart, $cycleEnd) {
                return $this->getOrCreateUsageForSubscription($subscription, $schoolId, $cycleStart, $cycleEnd);
            });
        }

        // Free Core Pay-As-You-Go: school-level AI usage record
        return DB::transaction(function () use ($schoolId) {
            $usage = SubscriptionAiUsage::query()
                ->where('school_id', $schoolId)
                ->latest('id')
                ->first();

            if ($usage) {
                return $usage;
            }

            $admin = User::query()
                ->where('school_id', $schoolId)
                ->where('role', 'Admin')
                ->first();

            return SubscriptionAiUsage::query()->create([
                'subscription_id' => null,
                'school_id' => $schoolId,
                'user_id' => $admin?->id ?: 0,
                'cycle_start' => now()->startOfYear()->toDateString(),
                'cycle_end' => now()->addYear()->endOfYear()->toDateString(),
                'allocated_credits' => 50, // Starter complimentary credits for new schools
                'used_credits' => 0,
            ]);
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
            'ai_scheme_work_generator' => max(1, (int) ($policy->ai_scheme_work_credit_cost ?? 4)),
            'ai_lesson_note_generator' => max(1, (int) ($policy->ai_lesson_note_credit_cost ?? 5)),
            'ai_fee_collection_assistant' => max(1, (int) ($policy->ai_fee_collection_credit_cost ?? 2)),
            default => 1,
        };
    }

    public function assertCreditsAvailable(int $schoolId, string $featureKey, ?int $cost = null, ?User $user = null): SubscriptionAiUsage
    {
        $usage = $this->getOrCreateCurrentCycleUsage($schoolId);
        $cost = $cost ?? $this->costForFeature($featureKey);

        if ($usage->remainingCredits() < $cost) {
            throw ValidationException::withMessages([
                'ai_credit' => 'Insufficient school AI credits. Please top up AI credits in Settings -> AI Credits to continue generating.',
            ]);
        }

        if ($user && ! in_array(strtolower((string) ($user->role ?? '')), ['admin', 'principal', 'super-admin'], true)) {
            $allocation = \App\Models\SchoolStaffAiCreditAllocation::query()
                ->where('school_id', $schoolId)
                ->where('user_id', $user->id)
                ->first();

            if ($allocation) {
                if (! $allocation->hasSufficientCredits($cost)) {
                    throw ValidationException::withMessages([
                        'ai_credit' => "You have insufficient allocated AI credits (Remaining: {$allocation->remainingCredits()} credits, Required: {$cost} credits). Please contact your school administrator to allocate more credits.",
                    ]);
                }
            }
        }

        return $usage;
    }

    public function consumeCredits(int $schoolId, string $featureKey, ?int $cost = null, ?string $reference = null, array $metadata = [], ?User $user = null): SubscriptionAiUsage
    {
        $cost = $cost ?? $this->costForFeature($featureKey);

        return DB::transaction(function () use ($schoolId, $featureKey, $cost, $reference, $metadata, $user) {
            if ($reference && AiCreditTransaction::query()->where('reference', $reference)->exists()) {
                return $this->getOrCreateCurrentCycleUsage($schoolId);
            }

            $usage = $this->getOrCreateCurrentCycleUsage($schoolId);
            $usage = SubscriptionAiUsage::query()->lockForUpdate()->findOrFail($usage->id);

            if ($usage->remainingCredits() < $cost) {
                throw ValidationException::withMessages([
                    'ai_credit' => 'Insufficient school AI credits. Please top up your school AI credits to continue.',
                ]);
            }

            if ($user && ! in_array(strtolower((string) ($user->role ?? '')), ['admin', 'principal', 'super-admin'], true)) {
                $allocation = \App\Models\SchoolStaffAiCreditAllocation::query()
                    ->where('school_id', $schoolId)
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if ($allocation) {
                    if (! $allocation->hasSufficientCredits($cost)) {
                        throw ValidationException::withMessages([
                            'ai_credit' => "You have insufficient allocated AI credits (Remaining: {$allocation->remainingCredits()} credits, Required: {$cost} credits).",
                        ]);
                    }
                    $allocation->increment('used_credits', $cost);
                }
            }

            $usage->update(['used_credits' => (int) $usage->used_credits + $cost]);

            AiCreditTransaction::query()->create([
                'school_id' => $schoolId,
                'subscription_ai_usage_id' => $usage->id,
                'user_id' => $user?->id,
                'feature_key' => $featureKey,
                'type' => 'consumption',
                'credits' => -$cost,
                'reference' => $reference ?: 'ai-consume:' . bin2hex(random_bytes(16)),
                'metadata' => array_merge($metadata, [
                    'user_id' => $user?->id,
                    'user_role' => $user?->role,
                ]),
            ]);

            return $usage->fresh();
        });
    }

    public function getStaffAllocations(int $schoolId): array
    {
        $staffMembers = User::query()
            ->where('school_id', $schoolId)
            ->whereIn('role', ['Teacher', 'teacher', 'Staff', 'staff', 'Principal', 'principal', 'Admin', 'admin'])
            ->get();

        $allocations = \App\Models\SchoolStaffAiCreditAllocation::query()
            ->where('school_id', $schoolId)
            ->get()
            ->keyBy('user_id');

        $result = [];
        $totalAllocatedToStaff = 0;
        $totalUsedByStaff = 0;

        foreach ($staffMembers as $staff) {
            $alloc = $allocations->get($staff->id);
            $allocated = (int) ($alloc->allocated_credits ?? 0);
            $used = (int) ($alloc->used_credits ?? 0);
            $isUnlimited = (bool) ($alloc->is_unlimited ?? false);
            $remaining = $isUnlimited ? 999999 : max(0, $allocated - $used);

            $totalAllocatedToStaff += $allocated;
            $totalUsedByStaff += $used;

            $result[] = [
                'user_id' => $staff->id,
                'name' => $staff->name,
                'email' => $staff->email,
                'role' => $staff->role,
                'username' => $staff->username,
                'has_allocation' => $alloc !== null,
                'allocated_credits' => $allocated,
                'used_credits' => $used,
                'remaining_credits' => $remaining,
                'is_unlimited' => $isUnlimited,
                'notes' => $alloc?->notes,
                'last_updated_at' => optional($alloc?->updated_at)->toDateTimeString(),
            ];
        }

        usort($result, fn ($a, $b) => strcasecmp((string) $a['name'], (string) $b['name']));

        return [
            'staff' => $result,
            'summary' => [
                'total_staff_count' => count($result),
                'allocated_staff_count' => $allocations->count(),
                'total_credits_allocated' => $totalAllocatedToStaff,
                'total_credits_used_by_staff' => $totalUsedByStaff,
            ],
        ];
    }

    public function allocateStaffCredits(int $schoolId, int $userId, int $credits, int $allocatedBy, bool $isUnlimited = false, ?string $notes = null): \App\Models\SchoolStaffAiCreditAllocation
    {
        return DB::transaction(function () use ($schoolId, $userId, $credits, $allocatedBy, $isUnlimited, $notes) {
            $user = User::query()->where('school_id', $schoolId)->findOrFail($userId);

            $allocation = \App\Models\SchoolStaffAiCreditAllocation::query()
                ->firstOrNew([
                    'school_id' => $schoolId,
                    'user_id' => $user->id,
                ]);

            $allocation->allocated_credits = max(0, $credits);
            $allocation->is_unlimited = $isUnlimited;
            $allocation->allocated_by = $allocatedBy;
            $allocation->notes = $notes;
            $allocation->save();

            return $allocation->fresh(['user']);
        });
    }

    public function bulkAllocateStaffCredits(int $schoolId, array $userIds, int $credits, int $allocatedBy, bool $isUnlimited = false): int
    {
        return DB::transaction(function () use ($schoolId, $userIds, $credits, $allocatedBy, $isUnlimited) {
            $count = 0;
            $users = User::query()->where('school_id', $schoolId)->whereIn('id', $userIds)->get();

            foreach ($users as $user) {
                $this->allocateStaffCredits($schoolId, $user->id, $credits, $allocatedBy, $isUnlimited);
                $count++;
            }

            return $count;
        });
    }

    public function revokeStaffAllocation(int $schoolId, int $userId): bool
    {
        return (bool) \App\Models\SchoolStaffAiCreditAllocation::query()
            ->where('school_id', $schoolId)
            ->where('user_id', $userId)
            ->delete();
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

    public function getCreditSummary(int $schoolId, ?User $user = null): array
    {
        $usage = $this->getOrCreateCurrentCycleUsage($schoolId);
        $policy = $this->policy();

        $userAllocation = null;
        if ($user && ! in_array(strtolower((string) ($user->role ?? '')), ['admin', 'principal', 'super-admin'], true)) {
            $alloc = \App\Models\SchoolStaffAiCreditAllocation::query()
                ->where('school_id', $schoolId)
                ->where('user_id', $user->id)
                ->first();

            if ($alloc) {
                $userAllocation = [
                    'allocated_credits' => (int) $alloc->allocated_credits,
                    'used_credits' => (int) $alloc->used_credits,
                    'remaining_credits' => $alloc->remainingCredits(),
                    'is_unlimited' => (bool) $alloc->is_unlimited,
                ];
            }
        }

        $packageName = (string) ($usage->subscription?->plan?->name ?? 'SchoolProfit Free Core');
        $isPlusActive = true;

        return [
            'allocated_credits' => (int) $usage->allocated_credits,
            'used_credits' => (int) $usage->used_credits,
            'remaining_credits' => $usage->remainingCredits(),
            'user_allocation' => $userAllocation,
            'is_plus_active' => $isPlusActive,
            'is_plus_package' => true,
            'cycle_start' => optional($usage->cycle_start)->toDateString() ?: now()->startOfYear()->toDateString(),
            'cycle_end' => optional($usage->cycle_end)->toDateString() ?: now()->addYear()->toDateString(),
            'wallet_valid_from' => optional($usage->cycle_start)->toDateString() ?: now()->startOfYear()->toDateString(),
            'access_valid_until' => optional($usage->cycle_end)->toDateString() ?: now()->addYear()->toDateString(),
            'credits_given_with_current_plan' => (int) $usage->allocated_credits,
            'current_package' => 'SchoolProfit Free Core (Pay-As-You-Go)',
            'subscription_id' => (int) ($usage->subscription_id ?? 0),
            'ai_result_comment_credit_cost' => (int) $policy->ai_result_comment_credit_cost,
            'ai_cbt_question_credit_cost' => (int) $policy->ai_cbt_question_credit_cost,
            'ai_lesson_plan_credit_cost' => (int) $policy->ai_lesson_plan_credit_cost,
            'ai_scheme_work_credit_cost' => (int) ($policy->ai_scheme_work_credit_cost ?? 4),
            'ai_lesson_note_credit_cost' => (int) ($policy->ai_lesson_note_credit_cost ?? 5),
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
            'allocated_credits' => $this->allocatedCreditsForPlan($subscription->plan),
            'used_credits' => 0,
        ]);
    }

    public function allocatedCreditsForPlan(?\App\Models\SubscriptionPlan $plan): int
    {
        $planName = strtolower(trim((string) ($plan?->name ?? '')));
        if (str_contains($planName, 'prime')) {
            return 1000;
        }
        if (str_contains($planName, 'growth')) {
            return 300;
        }
        return (int) ($this->policy()->legacy_plus_ai_credits ?: 100);
    }

    private function policy(): SchoolProfitBillingPolicy
    {
        return SchoolProfitBillingPolicy::query()->firstOrCreate([], [
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
            'ai_scheme_work_credit_cost' => 4,
            'ai_lesson_note_credit_cost' => 5,
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
