<?php

namespace App\Console\Commands;

use App\Services\WalletSubscriptionAutoRenewService;
use Illuminate\Console\Command;

class RunWalletSubscriptionAutoRenew extends Command
{
    protected $signature = 'subscriptions:auto-renew-wallet';
    protected $description = 'Auto-renew subscriptions using user wallet balance';

    public function handle(WalletSubscriptionAutoRenewService $service): int
    {
        $result = $service->run();

        $this->info('Processed: ' . $result['processed']);
        $this->info('Renewed: ' . $result['renewed']);
        $this->info('Skipped: ' . $result['skipped']);
        $this->info('Failed: ' . $result['failed']);

        return self::SUCCESS;
    }
}