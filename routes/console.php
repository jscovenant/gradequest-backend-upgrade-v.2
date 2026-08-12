<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\ProcessAutoFeeInvoicesJob;
use App\Models\SchoolSetting;
use App\Models\SalesPayoutPolicy;
use App\Models\SchoolDomain;
use App\Services\SchoolDomainService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();


// Run every day at 7am (change as you like)
Schedule::job(new ProcessAutoFeeInvoicesJob())
    ->everyFifteenMinutes()
    ->name('automatic-fee-reminders')
    ->withoutOverlapping();

Schedule::command('subscriptions:send-reminders')
    ->everyMinute();

Schedule::command('subscriptions:auto-renew-wallet')->everyMinute();


Schedule::command('results:scan-incomplete')->everyMinute();
Schedule::command('results:scan-anomalies')->everyMinute();

Schedule::call(function (SchoolDomainService $domains) {
    SchoolDomain::query()
        ->where('status', 'active')
        ->orderBy('id')
        ->chunkById(100, function ($records) use ($domains) {
            $records->each(fn (SchoolDomain $domain) => $domains->checkHealth($domain));
        });
})->dailyAt('03:30')->name('custom-domain-health-check')->withoutOverlapping();

Schedule::command('sales:review-eligible')
    ->dailyAt('02:00')
    ->name('daily-sales-commission-review')
    ->withoutOverlapping();

Schedule::command('sales:review-activity')
    ->dailyAt('01:30')
    ->name('daily-sales-representative-activity-review')
    ->withoutOverlapping();

Schedule::command('sales:payouts-monthly')
    ->dailyAt('02:00')
    ->when(function () {
        $policy = SalesPayoutPolicy::current();

        return now()->day === (int) $policy->monthly_payout_day;
    })
    ->name('monthly-sales-payouts')
    ->withoutOverlapping();

Schedule::command('sales:payouts-reconcile')
    ->everyTenMinutes()
    ->name('sales-payout-reconciliation')
    ->withoutOverlapping();


Schedule::call(function () {
    SchoolSetting::where('whatsapp_monthly_limit', '>', 0)
        ->update([
            'whatsapp_messages_sent'    => 0,
            'whatsapp_usage_reset_date' => now(),
        ]);
})->monthly()->name('reset-whatsapp-usage')->withoutOverlapping();

