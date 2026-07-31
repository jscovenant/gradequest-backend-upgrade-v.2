<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_payout_policies', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_payout_policies', 'last_review_at')) {
                $table->timestamp('last_review_at')->nullable()->after('large_commission_review_threshold');
            }

            if (! Schema::hasColumn('sales_payout_policies', 'last_review_approved_count')) {
                $table->unsignedInteger('last_review_approved_count')->default(0)->after('last_review_at');
            }

            if (! Schema::hasColumn('sales_payout_policies', 'last_review_held_count')) {
                $table->unsignedInteger('last_review_held_count')->default(0)->after('last_review_approved_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_payout_policies', function (Blueprint $table) {
            foreach (['last_review_held_count', 'last_review_approved_count', 'last_review_at'] as $column) {
                if (Schema::hasColumn('sales_payout_policies', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
