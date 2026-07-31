<?php

namespace App\Console\Commands;

use App\Services\SalesPayoutService;
use Illuminate\Console\Command;

class ReviewEligibleSalesCommissions extends Command
{
    protected $signature = 'sales:review-eligible';

    protected $description = 'Automatically review eligible sales commissions after the waiting period.';

    public function handle(SalesPayoutService $payoutService): int
    {
        $result = $payoutService->approveEligibleCommissions();

        $this->info('Eligible sales commissions reviewed.');
        $this->line('Approved: ' . ($result['approved'] ?? 0));
        $this->line('Held: ' . ($result['held'] ?? 0));

        if (! empty($result['message'])) {
            $this->line($result['message']);
        }

        return self::SUCCESS;
    }
}
