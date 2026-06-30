<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\ProcessAutoFeeInvoicesJob;
use App\Models\SchoolSetting;

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


Schedule::call(function () {
    SchoolSetting::where('whatsapp_monthly_limit', '>', 0)
        ->update([
            'whatsapp_messages_sent'    => 0,
            'whatsapp_usage_reset_date' => now(),
        ]);
})->monthly()->name('reset-whatsapp-usage')->withoutOverlapping();









