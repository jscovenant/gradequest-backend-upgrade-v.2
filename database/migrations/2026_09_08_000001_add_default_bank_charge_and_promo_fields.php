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
                if (! Schema::hasColumn('gradequest_billing_policies', 'default_bank_charge_amount')) {
                    $table->decimal('default_bank_charge_amount', 12, 2)->default(200.00)->after('annual_session_discount_percent');
                }
                if (! Schema::hasColumn('gradequest_billing_policies', 'promo_target_tier')) {
                    $table->string('promo_target_tier', 50)->default('all')->after('promo_target_plan');
                }
                if (! Schema::hasColumn('gradequest_billing_policies', 'promo_discount_percent')) {
                    $table->decimal('promo_discount_percent', 5, 2)->default(100.00)->after('promo_bonus_days');
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
                    'default_bank_charge_amount',
                    'promo_target_tier',
                    'promo_discount_percent',
                ];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('gradequest_billing_policies', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
