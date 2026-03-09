<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\AutoSubscriptionReminderService;

class SendSubscriptionReminders extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'subscriptions:send-reminders';

    /**
     * The console command description.
     */
    protected $description = 'Send subscription expiry reminder emails to admin users';

    /**
     * Execute the console command.
     */
    public function handle(AutoSubscriptionReminderService $service): int
    {
        $this->info('Starting subscription reminder check...');

        $result = $service->run();

        $this->line('-----------------------------------');
        $this->info("Checked:  {$result['checked']}");
        $this->info("Notified: {$result['notified']}");
        $this->info("Skipped:  {$result['skipped']}");
        $this->line('-----------------------------------');

        $this->info('Subscription reminder job completed.');

        return self::SUCCESS;
    }
}