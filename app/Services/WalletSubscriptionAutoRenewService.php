<?php

namespace App\Services;

use App\Models\SubPayment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WalletSubscriptionAutoRenewService
{
    public function run(): array
    {
        $now = now();

        $subscriptions = Subscription::query()
            ->where('status', 'active')
            ->where(function ($q) {
                $q->where('auto_renew', 1)
                  ->orWhere('auto_renew', '1')
                  ->orWhere('auto_renew', true)
                  ->orWhere('auto_renew', 'true');
            })
            ->where('auto_renew_source', 'wallet')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', $now)
            ->get();

        $processed = 0;
        $renewed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($subscriptions as $subscription) {
            $processed++;

            try {
                $result = $this->renewSingle($subscription->id);

                if ($result === true) {
                    $renewed++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $failed++;

                Log::error('Wallet auto-renew failed', [
                    'subscription_id' => $subscription->id,
                    'user_id' => $subscription->user_id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return [
            'processed' => $processed,
            'renewed' => $renewed,
            'skipped' => $skipped,
            'failed' => $failed,
        ];
    }

    protected function renewSingle(int $subscriptionId): bool
    {
        return DB::transaction(function () use ($subscriptionId) {
            $subscription = Subscription::where('id', $subscriptionId)
                ->lockForUpdate()
                ->first();

            if (!$subscription) {
                return false;
            }

            // Reconfirm eligibility inside transaction
            if (!$this->isEligibleForWalletAutoRenew($subscription)) {
                return false;
            }

            if (!$subscription->ends_at || Carbon::parse($subscription->ends_at)->isFuture()) {
                return false;
            }

            $plan = SubscriptionPlan::find($subscription->subscription_plan_id);
            if (!$plan) {
                Log::warning('Wallet auto-renew skipped: plan not found', [
                    'subscription_id' => $subscription->id,
                    'plan_id' => $subscription->subscription_plan_id,
                ]);
                return false;
            }

            $wallet = Wallet::where('user_id', $subscription->user_id)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                Log::warning('Wallet auto-renew skipped: wallet not found', [
                    'subscription_id' => $subscription->id,
                    'user_id' => $subscription->user_id,
                ]);
                return false;
            }

            $amount = (float) $plan->price;
            $balance = (float) $wallet->balance;

            if ($balance < $amount) {
                Log::info('Wallet auto-renew skipped: insufficient balance', [
                    'subscription_id' => $subscription->id,
                    'user_id' => $subscription->user_id,
                    'wallet_balance' => $balance,
                    'required_amount' => $amount,
                ]);

                return false;
            }

            $reference = 'WALLET-AUTO-' . strtoupper(Str::random(12));

            // Debit wallet
            $wallet->balance = $balance - $amount;
            $wallet->save();

            // Log wallet transaction
            WalletTransaction::create([
                'user_id' => $subscription->user_id,
                'school_id' => $wallet->school_id,
                'amount' => $amount,
                'type' => 'debit',
                'description' => 'Subscription auto-renewal via wallet for ' . $plan->name,
                'reference_id' => $reference,
            ]);

            // Log subscription payment
            SubPayment::create([
                'user_id' => $subscription->user_id,
                'subscription_plan_id' => $plan->id,
                'reference' => $reference,
                'amount' => $amount,
                'status' => 'successful',
                'channel' => 'wallet',
                'starts_at' => now(),
            ]);

            // Extend subscription
            $baseStart = Carbon::parse($subscription->ends_at);
            $newEnd = Carbon::parse($baseStart)->addDays((int) $plan->duration_in_days);

            $subscription->update([
                'status' => 'active',
                'starts_at' => $baseStart,
                'ends_at' => $newEnd,
                'auto_renew' => 1,
                'auto_renew_source' => 'wallet',
                'notified_about_expiry' => 0,
                'reminder_stage' => 0,
                'last_reminded_at' => null,
                'authorization_code' => null,
                'customer_code' => null,
                'subscription_code' => null,
                'email_token' => null,
            ]);

            Log::info('Wallet auto-renew successful', [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'reference' => $reference,
                'amount' => $amount,
                'new_end' => $newEnd->toDateTimeString(),
            ]);

            return true;
        });
    }

    protected function isEligibleForWalletAutoRenew(Subscription $subscription): bool
    {
        $autoRenew = strtolower((string) $subscription->auto_renew);
        $source = strtolower((string) $subscription->auto_renew_source);

        $enabled = in_array($autoRenew, ['1', 'true'], true) || $subscription->auto_renew === 1 || $subscription->auto_renew === true;
        $walletSource = $source === 'wallet';

        return $enabled && $walletSource;
    }
}