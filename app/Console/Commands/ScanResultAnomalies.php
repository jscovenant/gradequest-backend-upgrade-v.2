<?php

namespace App\Console\Commands;

use App\Models\ResultBatch;
use App\Services\Results\ResultAnomalyDetectionService;
use Illuminate\Console\Command;

class ScanResultAnomalies extends Command
{
    protected $signature = 'results:scan-anomalies {batchId?}';
    protected $description = 'Scan one or more result batches for anomalous scores';

    public function handle(ResultAnomalyDetectionService $service): int
    {
        $batchId = $this->argument('batchId');

        if ($batchId) {
            $service->scanBatch((int) $batchId);
            $this->info("Anomaly scan completed for batch {$batchId}.");

            return self::SUCCESS;
        }

        ResultBatch::query()
            ->whereIn('status', ['draft', 'computed', 'approved'])
            ->chunkById(100, function ($batches) use ($service) {
                foreach ($batches as $batch) {
                    $service->scanBatch($batch->id);
                }
            });

        $this->info('Anomaly scan completed for eligible batches.');

        return self::SUCCESS;
    }
}