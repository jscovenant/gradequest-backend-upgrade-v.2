<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\SubscriptionWhatsappUsage;
use App\Models\User;
use App\Models\WhatsappCreditTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubscriptionWhatsappCreditService
{
    public function getActiveSchoolSubscription(int $schoolId): ?Subscription
    {
        $admin = User::query()
            ->where('school_id', $schoolId)
            ->where('role', 'Admin')
            ->first();

        if (!$admin) {
            return null;
        }

        return Subscription::query()
            ->with('plan')
            ->where('user_id', $admin->id)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('ends_at')
                  ->orWhere('ends_at', '>=', now());
            })
            ->latest('id')
            ->first();
    }

    public function getOrCreateCurrentCycleUsage(int $schoolId): SubscriptionWhatsappUsage
    {
        $subscription = $this->getActiveSchoolSubscription($schoolId);

        if ($subscription) {
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

        // Free Core Pay-As-You-Go: school-level WhatsApp usage record
        return DB::transaction(function () use ($schoolId) {
            $usage = SubscriptionWhatsappUsage::query()
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

            return SubscriptionWhatsappUsage::query()->create([
                'subscription_id' => null,
                'school_id' => $schoolId,
                'user_id' => $admin?->id ?: 0,
                'cycle_start' => now()->startOfYear()->toDateString(),
                'cycle_end' => now()->addYear()->endOfYear()->toDateString(),
                'allocated_credits' => 0,
                'used_credits' => 0,
            ]);
        });
    }

    public function assertCreditsAvailable(int $schoolId, int $cost = 1): SubscriptionWhatsappUsage
    {
        $usage = $this->getOrCreateCurrentCycleUsage($schoolId);
        $remaining = $usage->remainingCredits();

        if ($remaining < $cost) {
            throw ValidationException::withMessages([
                'credit' => 'Insufficient WhatsApp messaging credits. Please top up your school WhatsApp bundle.',
            ]);
        }

        return $usage;
    }

    public function consumeCredits(int $schoolId, int $cost = 1, ?string $reference = null, ?int $messageId = null): SubscriptionWhatsappUsage
    {
        return DB::transaction(function () use ($schoolId, $cost, $reference, $messageId) {
            if ($reference && WhatsappCreditTransaction::query()->where('reference', $reference)->exists()) {
                return $this->getOrCreateCurrentCycleUsage($schoolId);
            }

            $usage = $this->getOrCreateCurrentCycleUsage($schoolId);
            $usage = SubscriptionWhatsappUsage::query()->lockForUpdate()->findOrFail($usage->id);

            $usage->increment('used_credits', $cost);

            WhatsappCreditTransaction::query()->create([
                'school_id' => $schoolId,
                'subscription_whatsapp_usage_id' => $usage->id,
                'whatsapp_message_id' => $messageId,
                'type' => 'consumption',
                'credits' => -$cost,
                'reference' => $reference ?: 'consume:' . bin2hex(random_bytes(16)),
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
            'cycle_start' => optional($usage->cycle_start)->toDateString() ?: now()->startOfYear()->toDateString(),
            'cycle_end' => optional($usage->cycle_end)->toDateString() ?: now()->addYear()->toDateString(),
            'subscription_id' => (int) ($usage->subscription_id ?? 0),
        ];
    }

    public function addPurchasedCredits(int $schoolId, int $quantity, ?string $reference = null): SubscriptionWhatsappUsage
    {
        return DB::transaction(function () use ($schoolId, $quantity, $reference) {
            if ($reference && WhatsappCreditTransaction::query()->where('reference', $reference)->exists()) {
                return $this->getOrCreateCurrentCycleUsage($schoolId);
            }

            $usage = $this->getOrCreateCurrentCycleUsage($schoolId);
            $usage = SubscriptionWhatsappUsage::query()->lockForUpdate()->findOrFail($usage->id);
            $usage->increment('allocated_credits', $quantity);

            WhatsappCreditTransaction::query()->create([
                'school_id' => $schoolId,
                'subscription_whatsapp_usage_id' => $usage->id,
                'type' => 'purchase',
                'credits' => $quantity,
                'reference' => $reference ?: 'purchase:' . bin2hex(random_bytes(16)),
            ]);

            return $usage->fresh();
        });
    }

    public function refundCredits(int $schoolId, int $cost, string $reference, ?int $messageId = null): SubscriptionWhatsappUsage
    {
        return DB::transaction(function () use ($schoolId, $cost, $reference, $messageId) {
            if (WhatsappCreditTransaction::query()->where('reference', $reference)->exists()) {
                return $this->getOrCreateCurrentCycleUsage($schoolId);
            }

            $usage = $this->getOrCreateCurrentCycleUsage($schoolId);
            $usage = SubscriptionWhatsappUsage::query()->lockForUpdate()->findOrFail($usage->id);
            $usage->update(['used_credits' => max(0, (int) $usage->used_credits - $cost)]);

            WhatsappCreditTransaction::query()->create([
                'school_id' => $schoolId,
                'subscription_whatsapp_usage_id' => $usage->id,
                'whatsapp_message_id' => $messageId,
                'type' => 'refund',
                'credits' => $cost,
                'reference' => $reference,
            ]);

            return $usage->fresh();
        });
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

        return [$startsAt->startOfDay(), $endsAt->endOfDay()];
    }
}
