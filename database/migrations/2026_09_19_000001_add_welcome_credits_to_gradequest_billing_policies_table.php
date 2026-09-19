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
                if (!Schema::hasColumn('gradequest_billing_policies', 'welcome_ai_credits')) {
                    $table->unsignedInteger('welcome_ai_credits')->default(50)->after('ai_credit_unit_price');
                }
                if (!Schema::hasColumn('gradequest_billing_policies', 'welcome_whatsapp_credits')) {
                    $table->unsignedInteger('welcome_whatsapp_credits')->default(15)->after('welcome_ai_credits');
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
                if (Schema::hasColumn('gradequest_billing_policies', 'welcome_ai_credits')) {
                    $table->dropColumn('welcome_ai_credits');
                }
                if (Schema::hasColumn('gradequest_billing_policies', 'welcome_whatsapp_credits')) {
                    $table->dropColumn('welcome_whatsapp_credits');
                }
            });
        }
    }
};
