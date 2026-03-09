<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SubscriptionExpiryReminderNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutoSubscriptionReminderService
{
    /**
     * Reminder stages:
     * 1 => 21 days before expiry
     * 2 => 14 days before expiry
     * 3 => 7 days before expiry
     * 4 => 1 day before expiry
     */
    private array $stages = [
        1 => 21,
        2 => 14,
        3 => 7,
        4 => 1,
    ];

    public function run(): array
    {
        $checked = 0;
        $notified = 0;
        $skipped = 0;

        $subs = Subscription::query()
            ->whereNotNull('ends_at')
            ->whereIn('status', ['active'])
            ->get();

        foreach ($subs as $subscription) {
            $checked++;

            if (!$subscription->user_id || !$subscription->ends_at) {
                $skipped++;
                continue;
            }

            $daysLeft = now()->startOfDay()->diffInDays(
                \Carbon\Carbon::parse($subscription->ends_at)->startOfDay(),
                false
            );

            if ($daysLeft < 0) {
                $skipped++;
                continue;
            }

            $nextStage = $this->resolveStageForDaysLeft($daysLeft);

            if (!$nextStage) {
                $skipped++;
                continue;
            }

            if ((int) $subscription->reminder_stage >= $nextStage) {
                $skipped++;
                continue;
            }

            $adminUsers = User::query()
                ->where('role', 'Admin')
                ->where(function ($q) use ($subscription) {
                    $q->where('id', $subscription->user_id)
                      ->orWhere('school_id', $subscription->user_id);
                })
                ->get();

            if ($adminUsers->isEmpty()) {
                $owner = User::find($subscription->user_id);
                if ($owner && strtolower((string) $owner->role) === 'admin') {
                    $adminUsers = collect([$owner]);
                }
            }

            if ($adminUsers->isEmpty()) {
                Log::warning('Subscription reminder skipped: no admin users found', [
                    'subscription_id' => $subscription->id,
                    'user_id' => $subscription->user_id,
                ]);
                $skipped++;
                continue;
            }

            $planName = $this->getPlanName((int) $subscription->subscription_plan_id);
            $renewUrl = rtrim((string) config('app.frontend_url'), '/') . '/billing/subscription';

            $payload = [
                'subscription_id' => (int) $subscription->id,
                'school_id' => (int) $subscription->user_id,
                'plan_name' => $planName,
                'status' => (string) $subscription->status,
                'days_left' => $daysLeft,
                'starts_at' => optional($subscription->starts_at)?->toDateTimeString() ?? 'N/A',
                'ends_at' => optional($subscription->ends_at)?->toDateTimeString() ?? 'N/A',
                'renew_url' => $renewUrl,
                'stage' => $nextStage,
            ];

            try {
                foreach ($adminUsers as $admin) {
                    if (!empty($admin->email)) {
                        $admin->notify(new SubscriptionExpiryReminderNotification($payload));
                        $notified++;
                    }
                }

                $subscription->reminder_stage = $nextStage;
                $subscription->last_reminded_at = now();

                if ($nextStage >= 4) {
                    $subscription->notified_about_expiry = 1;
                }

                $subscription->save();

                Log::info('Subscription expiry reminder sent', [
                    'subscription_id' => $subscription->id,
                    'stage' => $nextStage,
                    'days_left' => $daysLeft,
                    'admins_count' => $adminUsers->count(),
                ]);
            } catch (\Throwable $e) {
                Log::error('Subscription expiry reminder failed', [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return compact('checked', 'notified', 'skipped');
    }
 
    private function resolveStageForDaysLeft(int $daysLeft): ?int
    {
        foreach ($this->stages as $stage => $days) {
            if ($daysLeft === $days) {
                return $stage;
            }
        }

        return null;
    }

    private function getPlanName(int $planId): string
    {
        $row = DB::table('subscription_plans')
            ->where('id', $planId)
            ->select('name')
            ->first();

        return $row->name ?? 'Subscription Plan';
    }
}