<?php

namespace App\Console\Commands;

use App\Notifications\FeeInvoiceReminderNotification;
use App\Services\AutoFeeInvoiceService;
use App\Services\FeeInvoiceReminderEngine;
use Illuminate\Console\Command;

class RunFeeInvoiceReminders extends Command
{
    protected $signature = 'fee-invoices:remind {--school_id=}';
    protected $description = 'Re-send fee invoice reminders based on per-school settings and invoice schedule';

    public function handle(AutoFeeInvoiceService $engine): int
    {
        $schoolId = $this->option('school_id') ? (int)$this->option('school_id') : null;
        $res = $engine->run($schoolId);

        $this->info(json_encode($res, JSON_PRETTY_PRINT));
        return self::SUCCESS;
    }
}