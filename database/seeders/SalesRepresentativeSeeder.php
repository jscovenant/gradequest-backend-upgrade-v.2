<?php

namespace Database\Seeders;

use App\Models\SalesRepAssignment;
use App\Models\SalesRepresentative;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SalesRepresentativeSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'adewale.sales@gradequest.test'],
            [
                'firstname' => 'Adewale',
                'surname' => 'Johnson',
                'phone' => '+2348034567890',
                'username' => 'SR00001',
                'reg_no' => 'SR00001',
                'role' => 'Sales-Representative',
                'status' => 1,
                'password' => Hash::make('password123'),
                'default_password' => 'password123',
            ]
        );

        $rep = SalesRepresentative::updateOrCreate(
            ['user_id' => $user->id],
            [
                'code' => 'SR00001',
                'region' => 'Lagos and South West',
                'status' => 'active',
                'commission_rate' => 5,
                'monthly_target_amount' => 2500000,
                'monthly_target_schools' => 10,
                'joined_at' => now()->toDateString(),
                'notes' => 'Dummy representative for testing the sales workflow.',
            ]
        );

        SalesRepAssignment::updateOrCreate(
            [
                'sales_representative_id' => $rep->id,
                'source' => 'dummy',
            ],
            [
                'stage' => 'demo_booked',
                'pipeline_value' => 2750000,
                'expected_close_date' => now()->addDays(14)->toDateString(),
                'notes' => 'Dummy lead for frontend and backend workflow testing.',
            ]
        );
    }
}
