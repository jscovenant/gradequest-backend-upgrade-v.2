<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('subscription_plans', 'price_per_student')) {
                $table->decimal('price_per_student', 10, 2)->default(0)->after('price');
            }

            if (! Schema::hasColumn('subscription_plans', 'billing_interval')) {
                $table->string('billing_interval', 30)->default('term')->after('duration_in_days');
            }
        });

        DB::table('subscription_plans')
            ->where(function ($query) {
                $query->whereNull('price_per_student')->orWhere('price_per_student', 0);
            })
            ->update(['price_per_student' => DB::raw('price')]);
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            if (Schema::hasColumn('subscription_plans', 'billing_interval')) {
                $table->dropColumn('billing_interval');
            }

            if (Schema::hasColumn('subscription_plans', 'price_per_student')) {
                $table->dropColumn('price_per_student');
            }
        });
    }
};
