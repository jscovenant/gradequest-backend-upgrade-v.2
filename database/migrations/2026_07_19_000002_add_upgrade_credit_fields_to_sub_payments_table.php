<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sub_payments')) {
            return;
        }

        Schema::table('sub_payments', function (Blueprint $table) {
            if (! Schema::hasColumn('sub_payments', 'subtotal_amount')) {
                $table->decimal('subtotal_amount', 12, 2)->default(0)->after('amount');
            }

            if (! Schema::hasColumn('sub_payments', 'upgrade_credit_amount')) {
                $table->decimal('upgrade_credit_amount', 12, 2)->default(0)->after('discount_amount');
            }

            if (! Schema::hasColumn('sub_payments', 'subscription_action')) {
                $table->string('subscription_action')->default('purchase')->after('status');
            }

            if (! Schema::hasColumn('sub_payments', 'previous_subscription_plan_id')) {
                $table->foreignId('previous_subscription_plan_id')->nullable()->after('subscription_plan_id')->constrained('subscription_plans')->nullOnDelete();
            }

            if (! Schema::hasColumn('sub_payments', 'previous_subscription_ends_at')) {
                $table->timestamp('previous_subscription_ends_at')->nullable()->after('starts_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sub_payments')) {
            return;
        }

        Schema::table('sub_payments', function (Blueprint $table) {
            if (Schema::hasColumn('sub_payments', 'previous_subscription_plan_id')) {
                $table->dropForeign(['previous_subscription_plan_id']);
            }

            foreach ([
                'subtotal_amount',
                'upgrade_credit_amount',
                'subscription_action',
                'previous_subscription_plan_id',
                'previous_subscription_ends_at',
            ] as $column) {
                if (Schema::hasColumn('sub_payments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
