<?php

namespace App\Console\Commands;

use App\Services\WelcomeSchoolCreditService;
use Illuminate\Console\Command;

class GrantWelcomeSchoolCreditsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'schoolprofit:grant-welcome-credits {--school_id= : Optional specific school ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Grant free 50 AI + 15 WhatsApp welcome credits to registered schools';

    /**
     * Execute the console command.
     */
    public function handle(WelcomeSchoolCreditService $service)
    {
        $schoolId = (int) $this->option('school_id');

        if ($schoolId > 0) {
            $this->info("Granting welcome credits to school ID: {$schoolId}...");
            $res = $service->grantWelcomePackage($schoolId);
            $this->info("Result: AI Granted: {$res['ai_granted']} (Balance: {$res['ai_total']}), WhatsApp Granted: {$res['whatsapp_granted']} (Balance: {$res['whatsapp_total']})");
            return Command::SUCCESS;
        }

        $this->info("Granting welcome credits to all registered schools...");
        $res = $service->grantToAllExistingSchools();

        $this->info("Total Schools: {$res['total_schools']}");
        $this->info("Newly Credited Schools: {$res['granted_schools']}");

        $this->table(
            ['School ID', 'School Name', 'AI Added', 'WA Added', 'AI Bal', 'WA Bal'],
            array_map(function ($s) {
                return [
                    $s['school_id'],
                    $s['school_name'],
                    $s['ai_granted'],
                    $s['whatsapp_granted'],
                    $s['ai_balance'],
                    $s['whatsapp_balance'],
                ];
            }, $res['summary'])
        );

        return Command::SUCCESS;
    }
}
