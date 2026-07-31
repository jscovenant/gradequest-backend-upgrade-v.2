<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WelcomeWalletCreditService
{
    public const AMOUNT = 5000;
    public const EXPIRY_DAYS = 30;
    public const DESCRIPTION = 'Welcome wallet credit for GradeQuestPlus subscription';

    public function grantToAdmin(User $admin): ?WalletTransaction
    {
        if (strtolower((string) $admin->role) !== 'admin') {
            return null;
        }

        return DB::transaction(function () use ($admin) {
            $existing = WalletTransaction::where('user_id', $admin->id)
                ->where('type', 'credit')
                ->where('description', self::DESCRIPTION)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $wallet = Wallet::firstOrCreate(
                ['user_id' => $admin->id],
                ['school_id' => $admin->school_id, 'balance' => 0]
            );

            $wallet->school_id = $admin->school_id;
            $wallet->balance = (float) ($wallet->balance ?? 0) + self::AMOUNT;
            $wallet->save();

            return WalletTransaction::create([
                'user_id' => $admin->id,
                'school_id' => $admin->school_id,
                'type' => 'credit',
                'amount' => self::AMOUNT,
                'remaining_amount' => self::AMOUNT,
                'description' => self::DESCRIPTION,
                'reference_id' => 'WELCOME-' . strtoupper(Str::random(12)),
                'expires_at' => now()->addDays(self::EXPIRY_DAYS),
                'metadata' => [
                    'purpose' => 'gradequest_plus_subscription_credit',
                    'expires_in_days' => self::EXPIRY_DAYS,
                ],
            ]);
        });
    }

    public function expireUnusedCredits(User $user): float
    {
        return DB::transaction(function () use ($user) {
            $expiredCredits = WalletTransaction::where('user_id', $user->id)
                ->where('type', 'credit')
                ->where('description', self::DESCRIPTION)
                ->whereNull('expired_at')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->where('remaining_amount', '>', 0)
                ->lockForUpdate()
                ->get();

            if ($expiredCredits->isEmpty()) {
                return 0.0;
            }

            $expiredAmount = (float) $expiredCredits->sum('remaining_amount');

            WalletTransaction::whereIn('id', $expiredCredits->pluck('id'))->update([
                'remaining_amount' => 0,
                'expired_at' => now(),
                'updated_at' => now(),
            ]);

            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();
            if ($wallet) {
                $wallet->balance = max(0, (float) ($wallet->balance ?? 0) - $expiredAmount);
                $wallet->save();
            }

            return $expiredAmount;
        });
    }

    public function consumeWelcomeCredit(User $user, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        $remainingDebit = $amount;

        WalletTransaction::where('user_id', $user->id)
            ->where('type', 'credit')
            ->where('description', self::DESCRIPTION)
            ->whereNull('expired_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->where('remaining_amount', '>', 0)
            ->orderBy('expires_at')
            ->lockForUpdate()
            ->get()
            ->each(function (WalletTransaction $credit) use (&$remainingDebit) {
                if ($remainingDebit <= 0) {
                    return;
                }

                $usable = min((float) $credit->remaining_amount, $remainingDebit);
                $credit->remaining_amount = (float) $credit->remaining_amount - $usable;
                $credit->save();

                $remainingDebit -= $usable;
            });
    }
}
