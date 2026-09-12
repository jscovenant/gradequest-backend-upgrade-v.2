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
    public const DESCRIPTION = 'Welcome bonus credit (expires in 30 days unless wallet is funded)';
    public const ACTIVATED_DESCRIPTION = 'Welcome bonus credit (Permanently Activated)';

    public function grantToAdmin(User $admin): ?WalletTransaction
    {
        if (self::AMOUNT <= 0) {
            return null;
        }

        if (strtolower((string) $admin->role) !== 'admin') {
            return null;
        }

        return DB::transaction(function () use ($admin) {
            $existing = WalletTransaction::where(function ($q) use ($admin) {
                    $q->where('user_id', $admin->id);
                    if ($admin->school_id) {
                        $q->orWhere('school_id', $admin->school_id);
                    }
                })
                ->where('type', 'credit')
                ->where(function ($q) {
                    $q->where('description', self::DESCRIPTION)
                      ->orWhere('description', self::ACTIVATED_DESCRIPTION)
                      ->orWhere('description', 'like', '%Welcome%');
                })
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $wallet = Wallet::firstOrCreate(
                ['school_id' => $admin->school_id],
                ['user_id' => $admin->id, 'balance' => 0]
            );

            $wallet->balance = (float) ($wallet->balance ?? 0) + self::AMOUNT;
            $wallet->user_id = $admin->id;
            $wallet->school_id = $admin->school_id;
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
                    'purpose' => 'welcome_bonus',
                    'activated' => false,
                    'expires_in_days' => self::EXPIRY_DAYS,
                    'granted_at' => now()->toIso8601String(),
                ],
            ]);
        });
    }

    public function activateBonusOnDeposit(int $schoolId, float $depositAmount, string $depositReference): ?WalletTransaction
    {
        if ($schoolId <= 0 || $depositAmount <= 0) {
            return null;
        }

        return DB::transaction(function () use ($schoolId, $depositAmount, $depositReference) {
            $bonusTx = WalletTransaction::where('school_id', $schoolId)
                ->where('type', 'credit')
                ->where(function ($q) {
                    $q->where('description', self::DESCRIPTION)
                      ->orWhere('description', 'like', '%Welcome%');
                })
                ->whereNull('expired_at')
                ->where('remaining_amount', '>', 0)
                ->lockForUpdate()
                ->first();

            if (! $bonusTx) {
                return null;
            }

            $meta = is_array($bonusTx->metadata) ? $bonusTx->metadata : [];
            if (!empty($meta['activated'])) {
                return $bonusTx;
            }

            $meta['activated'] = true;
            $meta['activated_at'] = now()->toIso8601String();
            $meta['deposit_reference'] = $depositReference;
            $meta['deposit_amount'] = $depositAmount;

            $bonusTx->metadata = $meta;
            $bonusTx->expires_at = null; // Remove expiration — now permanent!
            $bonusTx->description = self::ACTIVATED_DESCRIPTION;
            $bonusTx->save();

            return $bonusTx;
        });
    }

    public function expireUnusedCredits(User $user): float
    {
        $schoolId = (int) ($user->school_id ?: 0);
        if ($schoolId <= 0) {
            return 0.0;
        }

        return DB::transaction(function () use ($user, $schoolId) {
            $expiredCredits = WalletTransaction::where('school_id', $schoolId)
                ->where('type', 'credit')
                ->where(function ($q) {
                    $q->where('description', self::DESCRIPTION)
                      ->orWhere('description', 'like', '%Welcome%');
                })
                ->whereNull('expired_at')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->where('remaining_amount', '>', 0)
                ->lockForUpdate()
                ->get();

            if ($expiredCredits->isEmpty()) {
                return 0.0;
            }

            $expiredAmount = 0.0;
            foreach ($expiredCredits as $credit) {
                $meta = is_array($credit->metadata) ? $credit->metadata : [];
                // If activated by a deposit, do NOT expire!
                if (!empty($meta['activated'])) {
                    continue;
                }

                $amount = (float) $credit->remaining_amount;
                $expiredAmount += $amount;

                $credit->remaining_amount = 0;
                $credit->expired_at = now();
                $credit->save();
            }

            if ($expiredAmount > 0) {
                $wallet = Wallet::where('school_id', $schoolId)->lockForUpdate()->first();
                if ($wallet) {
                    $wallet->balance = max(0, (float) ($wallet->balance ?? 0) - $expiredAmount);
                    $wallet->save();
                }
            }

            return $expiredAmount;
        });
    }

    public function getBonusStatus(int $schoolId): array
    {
        if ($schoolId <= 0) {
            return [
                'has_bonus' => false,
                'amount' => 0,
                'remaining_amount' => 0,
                'is_activated' => false,
                'is_expired' => false,
                'expires_at' => null,
                'days_remaining' => 0,
            ];
        }

        $bonusTx = WalletTransaction::where('school_id', $schoolId)
            ->where('type', 'credit')
            ->where(function ($q) {
                $q->where('description', self::DESCRIPTION)
                  ->orWhere('description', self::ACTIVATED_DESCRIPTION)
                  ->orWhere('description', 'like', '%Welcome%');
            })
            ->latest('id')
            ->first();

        if (! $bonusTx) {
            return [
                'has_bonus' => false,
                'amount' => 0,
                'remaining_amount' => 0,
                'is_activated' => false,
                'is_expired' => false,
                'expires_at' => null,
                'days_remaining' => 0,
            ];
        }

        $meta = is_array($bonusTx->metadata) ? $bonusTx->metadata : [];
        $isActivated = !empty($meta['activated']) || $bonusTx->description === self::ACTIVATED_DESCRIPTION;
        $isExpired = !empty($bonusTx->expired_at) || (! $isActivated && $bonusTx->expires_at && now()->isAfter($bonusTx->expires_at));

        $daysRemaining = 0;
        if (! $isActivated && ! $isExpired && $bonusTx->expires_at) {
            $daysRemaining = max(0, (int) ceil(now()->diffInDays($bonusTx->expires_at, false)));
        }

        return [
            'has_bonus' => true,
            'amount' => (float) $bonusTx->amount,
            'remaining_amount' => (float) $bonusTx->remaining_amount,
            'is_activated' => $isActivated,
            'is_expired' => $isExpired,
            'expires_at' => $bonusTx->expires_at?->toIso8601String(),
            'days_remaining' => $daysRemaining,
        ];
    }

    public function consumeWelcomeCredit(User $user, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        $remainingDebit = $amount;
        $schoolId = (int) ($user->school_id ?: 0);

        WalletTransaction::where(function ($q) use ($user, $schoolId) {
                $q->where('user_id', $user->id);
                if ($schoolId > 0) {
                    $q->orWhere('school_id', $schoolId);
                }
            })
            ->where('type', 'credit')
            ->where(function ($q) {
                $q->where('description', self::DESCRIPTION)
                  ->orWhere('description', self::ACTIVATED_DESCRIPTION)
                  ->orWhere('description', 'like', '%Welcome%');
            })
            ->whereNull('expired_at')
            ->where('remaining_amount', '>', 0)
            ->orderBy('id')
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
