<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_representatives', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_representatives', 'term_1_commission_rate')) {
                $table->decimal('term_1_commission_rate', 5, 2)->default(30.00)->after('commission_rate');
            }
            if (! Schema::hasColumn('sales_representatives', 'retention_commission_rate')) {
                $table->decimal('retention_commission_rate', 5, 2)->default(12.00)->after('term_1_commission_rate');
            }
        });

        // Set existing reps with default 5% to new tiered defaults if unset
        DB::table('sales_representatives')
            ->whereNull('term_1_commission_rate')
            ->orWhere('term_1_commission_rate', 0)
            ->update([
                'term_1_commission_rate' => 30.00,
                'retention_commission_rate' => 12.00,
            ]);

        Schema::table('sales_payout_policies', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_payout_policies', 'default_term_1_rate')) {
                $table->decimal('default_term_1_rate', 5, 2)->default(30.00)->after('default_commission_rate');
            }
            if (! Schema::hasColumn('sales_payout_policies', 'default_retention_rate')) {
                $table->decimal('default_retention_rate', 5, 2)->default(12.00)->after('default_term_1_rate');
            }
            if (! Schema::hasColumn('sales_payout_policies', 'max_commission_terms')) {
                $table->unsignedTinyInteger('max_commission_terms')->default(3)->after('default_retention_rate');
            }
        });

        DB::table('sales_payout_policies')
            ->where('id', 1)
            ->update([
                'default_commission_rate' => 30.00,
                'default_term_1_rate' => 30.00,
                'default_retention_rate' => 12.00,
                'max_commission_terms' => 3,
            ]);

        Schema::table('sales_commissions', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_commissions', 'term_number')) {
                $table->unsignedTinyInteger('term_number')->default(1)->after('term_id')->index();
            }
            if (! Schema::hasColumn('sales_commissions', 'invoice_id')) {
                $table->unsignedBigInteger('invoice_id')->nullable()->after('sub_payment_id')->index();
            }
            if (! Schema::hasColumn('sales_commissions', 'invoice_payment_id')) {
                $table->unsignedBigInteger('invoice_payment_id')->nullable()->after('invoice_id')->index();
            }
        });

        Schema::table('sales_rep_assignments', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_rep_assignments', 'commission_term_count')) {
                $table->unsignedTinyInteger('commission_term_count')->default(0)->after('stage');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_rep_assignments', function (Blueprint $table) {
            if (Schema::hasColumn('sales_rep_assignments', 'commission_term_count')) {
                $table->dropColumn('commission_term_count');
            }
        });

        Schema::table('sales_commissions', function (Blueprint $table) {
            foreach (['invoice_payment_id', 'invoice_id', 'term_number'] as $column) {
                if (Schema::hasColumn('sales_commissions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('sales_payout_policies', function (Blueprint $table) {
            foreach (['max_commission_terms', 'default_retention_rate', 'default_term_1_rate'] as $column) {
                if (Schema::hasColumn('sales_payout_policies', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('sales_representatives', function (Blueprint $table) {
            foreach ([
                'retention_commission_rate',
                'term_1_commission_rate',
            ] as $column) {
                if (Schema::hasColumn('sales_representatives', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
