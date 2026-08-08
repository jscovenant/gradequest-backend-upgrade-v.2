<?php

namespace App\Console\Commands;

use App\Services\SalesRepresentativeActivityService;
use Illuminate\Console\Command;

class ReviewSalesRepresentativeActivity extends Command
{
    protected $signature = 'sales:review-activity';

    protected $description = 'Flag inactive sales representatives and disable accounts with one year of login inactivity.';

    public function handle(SalesRepresentativeActivityService $activityService): int
    {
        $result = $activityService->enforceAll();

        $this->info('Sales representative activity review completed.');
        $this->line('Flagged: ' . $result['flagged']);
        $this->line('Automatically disabled: ' . $result['disabled']);

        return self::SUCCESS;
    }
}
