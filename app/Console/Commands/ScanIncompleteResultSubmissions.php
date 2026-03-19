<?php

namespace App\Console\Commands;

use App\Models\ResultBatch;
use App\Services\Results\ResultSubmissionMonitorService;
use Illuminate\Console\Command;

class ScanIncompleteResultSubmissions extends Command
{
    protected $signature = 'results:scan-incomplete';
    protected $description = 'Refresh progress monitors for active result batches';

    public function handle(ResultSubmissionMonitorService $monitorService): int
    {
        ResultBatch::query()
            ->whereIn('status', ['draft', 'computed', 'approved'])
            ->chunkById(100, function ($batches) use ($monitorService) {
                foreach ($batches as $batch) {
                    $monitorService->refreshBatch($batch->id);
                }
            });

        $this->info('Result submission monitors refreshed successfully.');

        return self::SUCCESS;
    }
}