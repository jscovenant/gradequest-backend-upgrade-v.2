<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\SubscriptionWhatsappUsage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubscriptionWhatsappCreditService
{
    public function getActiveSchoolSubscription(int $schoolId): Subscription
    {
        $admin = User::query()
            ->where('school_id', $schoolId)
            ->where('role', 'Admin')
            ->first();

        if (!$admin) {
            throw ValidationException::withMessages([
                'school' => 'No admin found for this school.',
            ]);
        }

        $subscription = Subscription::query()
            ->with('plan')
            ->where('user_id', $admin->id)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('ends_at')
                  ->orWhere('ends_at', '>=', now());
            })
            ->latest('id')
            ->first();

        if (!$subscription) {
            throw ValidationException::withMessages([
                'subscription' => 'No active subscription found for this school.',
            ]);
        }

        if (!$subscription->plan) {
            throw ValidationException::withMessages([
                'subscription_plan' => 'Subscription plan not found.',
            ]);
        }

        if (!(bool) $subscription->plan->whatsapp_enabled) {
            throw ValidationException::withMessages([
                'whatsapp' => 'WhatsApp is not enabled for this subscription plan.',
            ]);
        }

        return $subscription;
    }

    public function getOrCreateCurrentCycleUsage(int $schoolId): SubscriptionWhatsappUsage
    {
        $subscription = $this->getActiveSchoolSubscription($schoolId);

        [$cycleStart, $cycleEnd] = $this->resolveCycleDates($subscription);

        return DB::transaction(function () use ($subscription, $schoolId, $cycleStart, $cycleEnd) {
            return SubscriptionWhatsappUsage::query()->firstOrCreate(
                [
                    'subscription_id' => $subscription->id,
                    'cycle_start' => $cycleStart->toDateString(),
                    'cycle_end' => $cycleEnd->toDateString(),
                ],
                [
                    'school_id' => $schoolId,
                    'user_id' => $subscription->user_id,
                    'allocated_credits' => (int) ($subscription->plan->whatsapp_monthly_credits ?? 0),
                    'used_credits' => 0,
                ]
            );
        });
    }

    public function assertCreditsAvailable(int $schoolId, int $cost = 1): SubscriptionWhatsappUsage
    {
        $usage = $this->getOrCreateCurrentCycleUsage($schoolId);

        $remaining = $usage->remainingCredits();

        if ($remaining < $cost) {
            throw ValidationException::withMessages([
                'credit' => 'Insufficient WhatsApp subscription credits.',
            ]);
        }

        return $usage;
    }

    public function consumeCredits(int $schoolId, int $cost = 1): SubscriptionWhatsappUsage
    {
        return DB::transaction(function () use ($schoolId, $cost) {
            $usage = $this->getOrCreateCurrentCycleUsage($schoolId);

            $usage = SubscriptionWhatsappUsage::query()
                ->lockForUpdate()
                ->findOrFail($usage->id);

            $remaining = $usage->remainingCredits();

            if ($remaining < $cost) {
                throw ValidationException::withMessages([
                    'credit' => 'Insufficient WhatsApp subscription credits.',
                ]);
            }

            $usage->update([
                'used_credits' => $usage->used_credits + $cost,
            ]);

            return $usage->fresh();
        });
    }

    public function getCreditSummary(int $schoolId): array
    {
        $usage = $this->getOrCreateCurrentCycleUsage($schoolId);

        return [
            'allocated_credits' => (int) $usage->allocated_credits,
            'used_credits' => (int) $usage->used_credits,
            'remaining_credits' => $usage->remainingCredits(),
            'cycle_start' => optional($usage->cycle_start)->toDateString(),
            'cycle_end' => optional($usage->cycle_end)->toDateString(),
            'subscription_id' => (int) $usage->subscription_id,
        ];
    }

    private function resolveCycleDates(Subscription $subscription): array
    {
        $startsAt = $subscription->starts_at
            ? Carbon::parse($subscription->starts_at)
            : now();

        $endsAt = $subscription->ends_at
            ? Carbon::parse($subscription->ends_at)
            : $startsAt->copy()->addDays(
                max(1, (int) ($subscription->plan->duration_in_days ?? 30))
            );

        $now = now();

        $cycleStart = $startsAt->copy();
        $cycleEnd = min(
            $cycleStart->copy()->addMonth()->subDay(),
            $endsAt->copy()
        );

        while ($now->gt($cycleEnd) && $cycleStart->lt($endsAt)) {
            $cycleStart = $cycleStart->copy()->addMonth();
            $cycleEnd = min(
                $cycleStart->copy()->addMonth()->subDay(),
                $endsAt->copy()
            );
        }

        return [$cycleStart->startOfDay(), $cycleEnd->endOfDay()];
    }
}