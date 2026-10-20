<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Investor;
use App\Models\InvestorDisbursement;
use Illuminate\Support\Facades\Hash;

class SeedDemoInvestorCommand extends Command
{
    protected $signature = 'investor:seed-demo';
    protected $description = 'Seed a realistic demo strategic investor into the SchoolProfit system';

    public function handle()
    {
        $email = 'demo.investor@schoolprofit.com';
        $password = 'Invest@2026';

        $this->info("Seeding demo investor: {$email}...");

        // 1. Create or update user account
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'first_name' => 'Tunde',
                'last_name' => 'Babalola',
                'phone' => '08012345678',
                'role' => User::ROLE_INVESTOR,
                'password' => Hash::make($password),
                'default_password' => $password,
                'force_password_change' => false,
            ]
        );

        // 2. Create or update Investor profile
        $investor = Investor::updateOrCreate(
            ['email' => $email],
            [
                'user_id' => $user->id,
                'name' => 'Engr. Tunde Babalola',
                'phone' => '08012345678',
                'tier' => 'silver',
                'capital_invested' => 1000000.00,
                'equity_percentage' => 7.00,
                'recoup_percentage' => 25.00,
                'tenure_years' => 5,
                'total_principal_repaid' => 350000.00,
                'total_dividends_paid' => 0.00,
                'current_phase' => 'recoup_phase',
                'investment_date' => '2026-10-20',
                'bank_name' => 'Guaranty Trust Bank',
                'account_number' => '0123456789',
                'account_name' => 'Engr. Tunde Babalola',
                'contract_ref' => 'ST/SP-INV/2026/01',
                'status' => 'active',
                'notes' => 'Official Silver Tier Strategic Investor under Samaritan Technologies SchoolProfit Seed Expansion.',
            ]
        );

        // 3. Create sample disbursement
        InvestorDisbursement::updateOrCreate(
            ['reference' => 'ST-RECP-20261020-001'],
            [
                'investor_id' => $investor->id,
                'type' => 'principal_recoup',
                'academic_session' => '2026/2027',
                'term' => 'First Term',
                'amount' => 350000.00,
                'platform_term_collection' => 2940000.00,
                'payment_method' => 'Direct NIP Bank Transfer',
                'payment_date' => '2026-10-20',
                'bank_reference' => 'NIP/GTB/20261020/998210',
                'status' => 'completed',
                'notes' => 'First term 25% collections capital recoup disbursement under ST/SP-INV/2026/01.',
            ]
        );

        $this->info(" Demo investor seeded successfully!");
        $this->table(
            ['Field', 'Details'],
            [
                ['Login Email', $email],
                ['Password', $password],
                ['Role', 'investor'],
                ['Tier', 'SILVER (₦1,000,000 @ 7% Equity Profit-Share for 5 Years)'],
                ['Recouped So Far', '₦350,000 (35%)'],
                ['Remaining Balance', '₦650,000'],
                ['Sample Receipt Ref', 'ST-RECP-20261020-001'],
            ]
        );

        return 0;
    }
}
