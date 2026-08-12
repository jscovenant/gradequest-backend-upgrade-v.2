<?php

namespace App\Console\Commands;

use App\Models\SalesPayoutBatch;
use App\Services\SalesPayoutService;
use Illuminate\Console\Command;
use RuntimeException;

class ReconcileSalesPayouts extends Command
{
    protected $signature = 'sales:payouts-reconcile';
    protected $description = 'Reconcile non-conclusive sales payouts with Paystack.';

    public function handle(SalesPayoutService $payouts): int
    {
        SalesPayoutBatch::query()
            ->whereIn('status', ['processing', 'requires_otp', 'awaiting_approval'])
            ->where('initiated_at', '<=', now()->subMinutes(5))
            ->orderBy('id')
            ->chunkById(50, function ($batches) use ($payouts) {
                foreach ($batches as $batch) {
                    try {
                        $payouts->reconcilePaystackTransfer($batch);
                    } catch (RuntimeException $e) {
                        $this->warn("Payout {$batch->reference}: {$e->getMessage()}");
                    }
                }
            });

        return self::SUCCESS;
    }
}
