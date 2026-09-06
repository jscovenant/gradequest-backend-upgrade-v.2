<?php

// App\Services\WalletService.php
namespace App\Services;

use Illuminate\Support\Facades\DB;

class WalletService
{
    public function debitSchoolWalletOrFail(
        int $schoolId,
        float $amount,
        int $actorUserId,
        string $description,
        string $referenceId
    ): void {
        DB::transaction(function () use ($schoolId, $amount, $actorUserId, $description, $referenceId) {
            // ✅ idempotency guard
            $exists = DB::table('wallet_transactions')
                ->where('school_id', $schoolId)
                ->where('reference_id', $referenceId)
                ->where('type', 'debit')
                ->exists();

            if ($exists) return;

            $wallet = DB::table('wallets')
                ->where('school_id', $schoolId)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                throw new \RuntimeException("Wallet not found for school_id={$schoolId}");
            }

            $balance = (float) $wallet->balance;
            if ($balance < $amount) {
                throw new \RuntimeException("Insufficient wallet balance for school_id={$schoolId}");
            }

            DB::table('wallets')->where('id', $wallet->id)->update([
                'balance' => $balance - $amount,
                'updated_at' => now(),
            ]);

            DB::table('wallet_transactions')->insert([
                'user_id' => $actorUserId,
                'amount' => $amount,
                'type' => 'debit',
                'description' => $description,
                'reference_id' => $referenceId,
                'school_id' => $schoolId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function creditSchoolWallet(
        int $schoolId,
        int $userId,
        float $amount,
        string $description,
        string $referenceId
    ): void {
        DB::transaction(function () use ($schoolId, $userId, $amount, $description, $referenceId) {
            // Idempotency guard: prevent duplicate credit
            $exists = DB::table('wallet_transactions')
                ->where('reference_id', $referenceId)
                ->where('type', 'credit')
                ->exists();

            if ($exists) return;

            $wallet = DB::table('wallets')
                ->where('school_id', $schoolId)
                ->lockForUpdate()
                ->first();

            if ($wallet) {
                DB::table('wallets')->where('id', $wallet->id)->update([
                    'balance' => ((float) $wallet->balance) + $amount,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('wallets')->insert([
                    'school_id' => $schoolId,
                    'user_id' => $userId,
                    'balance' => $amount,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('wallet_transactions')->insert([
                'user_id' => $userId,
                'amount' => $amount,
                'type' => 'credit',
                'description' => $description,
                'reference_id' => $referenceId,
                'school_id' => $schoolId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }
}