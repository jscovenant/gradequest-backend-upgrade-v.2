<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('gradequest_billing_policies')) {
            Schema::table('gradequest_billing_policies', function (Blueprint $table) {
                if (! Schema::hasColumn('gradequest_billing_policies', 'basic_tier_price_per_student')) {
                    $table->decimal('basic_tier_price_per_student', 12, 2)->default(300.00)->after('platform_fee_per_student');
                }
                if (! Schema::hasColumn('gradequest_billing_policies', 'standard_cbt_tier_price_per_student')) {
                    $table->decimal('standard_cbt_tier_price_per_student', 12, 2)->default(500.00)->after('basic_tier_price_per_student');
                }
                if (! Schema::hasColumn('gradequest_billing_policies', 'annual_full_session_multiplier')) {
                    $table->decimal('annual_full_session_multiplier', 5, 2)->default(3.00)->after('standard_cbt_tier_price_per_student');
                }
                if (! Schema::hasColumn('gradequest_billing_policies', 'annual_session_discount_percent')) {
                    $table->decimal('annual_session_discount_percent', 5, 2)->default(0.00)->after('annual_full_session_multiplier');
                }
            });
        }

        if (Schema::hasTable('school_settings')) {
            Schema::table('school_settings', function (Blueprint $table) {
                if (! Schema::hasColumn('school_settings', 'active_edition_tier')) {
                    $table->string('active_edition_tier', 40)->default('standard_cbt')->after('platform_fee_bearer');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('gradequest_billing_policies')) {
            Schema::table('gradequest_billing_policies', function (Blueprint $table) {
                $columns = [
                    'basic_tier_price_per_student',
                    'standard_cbt_tier_price_per_student',
                    'annual_full_session_multiplier',
                    'annual_session_discount_percent',
                ];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('gradequest_billing_policies', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('school_settings')) {
            Schema::table('school_settings', function (Blueprint $table) {
                if (Schema::hasColumn('school_settings', 'active_edition_tier')) {
                    $table->dropColumn('active_edition_tier');
                }
            });
        }
    }
};
