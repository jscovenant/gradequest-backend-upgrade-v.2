<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('subscriptions') && !Schema::hasColumn('subscriptions', 'billing_cycle_count')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->unsignedInteger('billing_cycle_count')->default(1)->after('subscription_plan_id');
            });
        }

        if (Schema::hasTable('sub_payments')) {
            Schema::table('sub_payments', function (Blueprint $table) {
                if (!Schema::hasColumn('sub_payments', 'billing_cycle_count')) {
                    $table->unsignedInteger('billing_cycle_count')->default(1)->after('subscription_plan_id');
                }

                if (!Schema::hasColumn('sub_payments', 'duration_in_days')) {
                    $table->unsignedInteger('duration_in_days')->nullable()->after('billing_cycle_count');
                }

                if (!Schema::hasColumn('sub_payments', 'discount_amount')) {
                    $table->decimal('discount_amount', 12, 2)->default(0)->after('amount');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('subscriptions') && Schema::hasColumn('subscriptions', 'billing_cycle_count')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->dropColumn('billing_cycle_count');
            });
        }

        if (Schema::hasTable('sub_payments')) {
            Schema::table('sub_payments', function (Blueprint $table) {
                if (Schema::hasColumn('sub_payments', 'billing_cycle_count')) {
                    $table->dropColumn('billing_cycle_count');
                }

                if (Schema::hasColumn('sub_payments', 'duration_in_days')) {
                    $table->dropColumn('duration_in_days');
                }

                if (Schema::hasColumn('sub_payments', 'discount_amount')) {
                    $table->dropColumn('discount_amount');
                }
            });
        }
    }
};