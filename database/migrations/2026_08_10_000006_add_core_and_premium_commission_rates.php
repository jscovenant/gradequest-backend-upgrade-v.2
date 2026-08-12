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
            $table->decimal('core_commission_rate', 5, 2)->default(5)->after('commission_rate');
            $table->decimal('premium_commission_rate', 5, 2)->default(5)->after('core_commission_rate');
        });

        DB::table('sales_representatives')->update([
            'core_commission_rate' => DB::raw('commission_rate'),
            'premium_commission_rate' => DB::raw('commission_rate'),
        ]);

        Schema::table('sales_commissions', function (Blueprint $table) {
            $table->unique(['payment_id', 'source'], 'sales_commission_payment_source_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sales_commissions', function (Blueprint $table) {
            $table->dropUnique('sales_commission_payment_source_unique');
        });

        Schema::table('sales_representatives', function (Blueprint $table) {
            $table->dropColumn(['core_commission_rate', 'premium_commission_rate']);
        });
    }
};
