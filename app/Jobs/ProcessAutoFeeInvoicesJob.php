<?php

namespace App\Jobs;

use App\Services\AutoFeeInvoiceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessAutoFeeInvoicesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 1800;

    public function __construct(public ?int $schoolId = null) {}

    public function handle(AutoFeeInvoiceService $svc): void
    {
        $svc->run($this->schoolId);
    }
}
