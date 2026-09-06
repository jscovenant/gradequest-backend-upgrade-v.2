<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gradequest_billing_policies', function (Blueprint $table) {
            if (!Schema::hasColumn('gradequest_billing_policies', 'support_whatsapp')) {
                $table->string('support_whatsapp', 50)->nullable()->default('08165748374')->after('platform_fee_per_student');
            }
        });
    }

    public function down(): void
    {
        Schema::table('gradequest_billing_policies', function (Blueprint $table) {
            if (Schema::hasColumn('gradequest_billing_policies', 'support_whatsapp')) {
                $table->dropColumn('support_whatsapp');
            }
        });
    }
};
