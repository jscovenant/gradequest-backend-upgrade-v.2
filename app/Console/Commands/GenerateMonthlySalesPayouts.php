<?php

namespace App\Console\Commands;

use App\Services\SalesPayoutService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GenerateMonthlySalesPayouts extends Command
{
    protected $signature = 'sales:payouts-monthly {--period-start=} {--period-end=}';

    protected $description = 'Review eligible sales commissions and create monthly payout batches.';

    public function handle(SalesPayoutService $payoutService): int
    {
        $periodStart = $this->option('period-start')
            ? Carbon::parse((string) $this->option('period-start'))
            : null;

        $periodEnd = $this->option('period-end')
            ? Carbon::parse((string) $this->option('period-end'))
            : null;

        $result = $payoutService->createMonthlyPayoutBatches($periodStart, $periodEnd);

        $this->info('Monthly sales payout review completed.');
        $this->line('Period: ' . $result['period_start'] . ' to ' . $result['period_end']);
        $this->line('Batches created: ' . $result['created_count']);
        $this->line('Carried forward: ' . count($result['carried_forward']));
        $this->line('Held: ' . count($result['held']));

        return self::SUCCESS;
    }
}
