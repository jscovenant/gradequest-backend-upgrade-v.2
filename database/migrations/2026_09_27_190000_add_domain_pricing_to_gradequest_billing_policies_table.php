<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gradequest_billing_policies') && ! Schema::hasColumn('gradequest_billing_policies', 'domain_pricing')) {
            Schema::table('gradequest_billing_policies', function (Blueprint $table) {
                $table->json('domain_pricing')->nullable()->after('promo_discount_percent');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('gradequest_billing_policies') && Schema::hasColumn('gradequest_billing_policies', 'domain_pricing')) {
            Schema::table('gradequest_billing_policies', function (Blueprint $table) {
                $table->dropColumn('domain_pricing');
            });
        }
    }
};
