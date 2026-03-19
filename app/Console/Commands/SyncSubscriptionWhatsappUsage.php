<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionWhatsappCreditService;
use Illuminate\Console\Command;

class SyncSubscriptionWhatsappUsage extends Command
{
    protected $signature = 'whatsapp:sync-subscription-usage {--school_id=}';
    protected $description = 'Create or sync current WhatsApp subscription usage rows for active school subscriptions';

    public function handle(SubscriptionWhatsappCreditService $service): int
    {
        $schoolIdFilter = $this->option('school_id');

        $query = Subscription::query()
            ->with(['user', 'plan'])
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('ends_at')
                  ->orWhere('ends_at', '>=', now());
            });

        if ($schoolIdFilter) {
            $query->whereHas('user', function ($q) use ($schoolIdFilter) {
                $q->where('school_id', (int) $schoolIdFilter)
                  ->where('role', 'Admin');
            });
        } else {
            $query->whereHas('user', function ($q) {
                $q->where('role', 'Admin');
            });
        }

        $subscriptions = $query->get();

        $processed = 0;
        $createdOrSynced = 0;
        $skipped = 0;

        foreach ($subscriptions as $subscription) {
            $processed++;

            $admin = $subscription->user;
            if (!$admin instanceof User) {
                $skipped++;
                $this->warn("Skipped subscription {$subscription->id}: admin user missing.");
                continue;
            }

            $schoolId = (int) ($admin->school_id ?? 0);
            if (!$schoolId) {
                $skipped++;
                $this->warn("Skipped subscription {$subscription->id}: school_id missing.");
                continue;
            }

            if (!$subscription->plan) {
                $skipped++;
                $this->warn("Skipped subscription {$subscription->id}: plan missing.");
                continue;
            }

            if (!(bool) $subscription->plan->whatsapp_enabled) {
                $skipped++;
                $this->line("Skipped school {$schoolId}: WhatsApp disabled on plan.");
                continue;
            }

            $usage = $service->getOrCreateCurrentCycleUsage($schoolId);

            $createdOrSynced++;

            $this->info(
                "School {$schoolId} synced. " .
                "Allocated: {$usage->allocated_credits}, " .
                "Used: {$usage->used_credits}, " .
                "Cycle: {$usage->cycle_start} to {$usage->cycle_end}"
            );
        }

        $this->newLine();
        $this->info("Processed: {$processed}");
        $this->info("Synced: {$createdOrSynced}");
        $this->info("Skipped: {$skipped}");

        return self::SUCCESS;
    }
}