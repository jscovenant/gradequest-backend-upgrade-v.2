<?php

namespace App\Jobs;

use App\Services\AutoFeeInvoiceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessAutoFeeInvoicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $cooldownHours = 24) {}

    public function handle(AutoFeeInvoiceService $svc): void
    {
        $svc->run($this->cooldownHours);
    }
}