<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subscription_plans')) {
            return;
        }

        $features = json_encode([
            ['feature_name' => 'Result Upload', 'feature_key' => 'support_results_upload', 'is_enabled' => true],
            ['feature_name' => 'Student Management', 'feature_key' => 'support_student_management', 'is_enabled' => true],
            ['feature_name' => 'Staff Attendance', 'feature_key' => 'support_staff_attendance', 'is_enabled' => true],
            ['feature_name' => 'Finance', 'feature_key' => 'support_finance_management', 'is_enabled' => true],
            ['feature_name' => 'Parent Management', 'feature_key' => 'support_parent_management', 'is_enabled' => true],
            ['feature_name' => 'School Fees', 'feature_key' => 'support_fee_management', 'is_enabled' => true],
            ['feature_name' => 'Teacher Management', 'feature_key' => 'support_teacher_management', 'is_enabled' => true],
            ['feature_name' => 'Bursar', 'feature_key' => 'support_bursar_management', 'is_enabled' => true],
        ]);

        $plans = [
            1 => ['name' => 'Legacy Starter', 'price' => 0, 'price_per_student' => 0, 'max_students' => 0],
            2 => ['name' => 'Legacy Standard', 'price' => 0, 'price_per_student' => 0, 'max_students' => 0],
            3 => ['name' => 'Legacy Basic', 'price' => 0, 'price_per_student' => 0, 'max_students' => 0],
            4 => ['name' => 'Legacy Premium', 'price' => 0, 'price_per_student' => 0, 'max_students' => 0],
            5 => ['name' => 'Legacy Enterprise', 'price' => 0, 'price_per_student' => 0, 'max_students' => 0],
            6 => ['name' => 'Legacy Core', 'price' => 0, 'price_per_student' => 0, 'max_students' => 0],
            10 => ['name' => 'Legacy Plus', 'price' => 0, 'price_per_student' => 0, 'max_students' => 0],
        ];

        foreach ($plans as $id => $plan) {
            DB::table('subscription_plans')->updateOrInsert(
                ['id' => $id],
                array_merge($plan, [
                    'paystack_plan_code' => null,
                    'duration_in_days' => 0,
                    'billing_interval' => 'legacy',
                    'description' => 'Restored legacy package used by historical subscriptions and payments.',
                    'features' => $features,
                    'max_teachers' => 0,
                    'whatsapp_monthly_credits' => 0,
                    'whatsapp_enabled' => false,
                    'currency' => 'NGN',
                    'is_active' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('subscription_plans')) {
            return;
        }

        DB::table('subscription_plans')
            ->whereIn('id', [1, 2, 3, 4, 5, 6, 10])
            ->where('billing_interval', 'legacy')
            ->delete();
    }
};
