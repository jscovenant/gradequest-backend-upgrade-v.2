<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add category and tech_royalty_fee to school_settings if not present
        if (Schema::hasTable('school_settings')) {
            Schema::table('school_settings', function (Blueprint $table) {
                if (! Schema::hasColumn('school_settings', 'category')) {
                    $table->string('category', 60)->nullable()->after('school_name')
                        ->comment('nursery, primary, junior_secondary, senior_secondary, all_through');
                }
                if (! Schema::hasColumn('school_settings', 'tech_royalty_fee')) {
                    $table->decimal('tech_royalty_fee', 10, 2)->nullable()->default(500.00)->after('platform_fee_bearer')
                        ->comment('School Profit technology royalty fee per payment (e.g. 300 to 500 NGN)');
                }
            });
        }

        // 2. Add flutterwave_subaccount_code to school_bank_accounts if not present
        if (Schema::hasTable('school_bank_accounts')) {
            Schema::table('school_bank_accounts', function (Blueprint $table) {
                if (! Schema::hasColumn('school_bank_accounts', 'flutterwave_subaccount_code')) {
                    $table->string('flutterwave_subaccount_code', 100)->nullable()->after('monnify_subaccount_code');
                }
            });
        }

        // 3. Ensure all 9 SaaS roles exist in the roles table
        if (Schema::hasTable('roles')) {
            $requiredRoles = [
                'Admin',
                'Super-Admin',
                'Teacher',
                'Student',
                'Parent',
                'Bursar',
                'Platform-Staff',
                'proprietor',
                'principal',
                'operator',
                'class_teacher',
                'subject_teacher',
                'super_admin',
            ];

            foreach ($requiredRoles as $roleName) {
                $exists = DB::table('roles')->where('name', $roleName)->where('guard_name', 'web')->exists();
                if (! $exists) {
                    DB::table('roles')->insert([
                        'name' => $roleName,
                        'guard_name' => 'web',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('school_settings')) {
            Schema::table('school_settings', function (Blueprint $table) {
                if (Schema::hasColumn('school_settings', 'category')) {
                    $table->dropColumn('category');
                }
                if (Schema::hasColumn('school_settings', 'tech_royalty_fee')) {
                    $table->dropColumn('tech_royalty_fee');
                }
            });
        }

        if (Schema::hasTable('school_bank_accounts')) {
            Schema::table('school_bank_accounts', function (Blueprint $table) {
                if (Schema::hasColumn('school_bank_accounts', 'flutterwave_subaccount_code')) {
                    $table->dropColumn('flutterwave_subaccount_code');
                }
            });
        }
    }
};
