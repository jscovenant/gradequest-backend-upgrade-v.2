<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\ProcessAutoFeeInvoicesJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();


// Run every day at 7am (change as you like)
Schedule::job(new ProcessAutoFeeInvoicesJob(24))->everyMinute();

Schedule::command('subscriptions:send-reminders')
    ->everyMinute();

Schedule::command('subscriptions:auto-renew-wallet')->everyMinute();


Schedule::command('results:scan-incomplete')->everyMinute();
Schedule::command('results:scan-incomplete')->everyMinute();
Schedule::command('results:scan-anomalies')->everyMinute();