<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gradequest_billing_policies', function (Blueprint $table) {
            if (!Schema::hasColumn('gradequest_billing_policies', 'sales_partner_term_1_commission_rate')) {
                $table->decimal('sales_partner_term_1_commission_rate', 8, 2)->default(30.00)->after('support_whatsapp');
            }
            if (!Schema::hasColumn('gradequest_billing_policies', 'sales_partner_retention_commission_rate')) {
                $table->decimal('sales_partner_retention_commission_rate', 8, 2)->default(12.00)->after('sales_partner_term_1_commission_rate');
            }
        });
    }

    public function down(): void
    {
        Schema::table('gradequest_billing_policies', function (Blueprint $table) {
            if (Schema::hasColumn('gradequest_billing_policies', 'sales_partner_retention_commission_rate')) {
                $table->dropColumn('sales_partner_retention_commission_rate');
            }
            if (Schema::hasColumn('gradequest_billing_policies', 'sales_partner_term_1_commission_rate')) {
                $table->dropColumn('sales_partner_term_1_commission_rate');
            }
        });
    }
};
