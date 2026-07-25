<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gradequest_billing_policies')) {
            return;
        }

        Schema::table('gradequest_billing_policies', function (Blueprint $table) {
            if (! Schema::hasColumn('gradequest_billing_policies', 'legacy_subscription_honor_enabled')) {
                $table->boolean('legacy_subscription_honor_enabled')->default(true)->after('platform_fee_per_student');
            }

            if (! Schema::hasColumn('gradequest_billing_policies', 'per_student_billing_starts_at')) {
                $table->dateTime('per_student_billing_starts_at')->nullable()->after('legacy_subscription_honor_enabled');
            }
        });

        DB::table('gradequest_billing_policies')
            ->whereNull('per_student_billing_starts_at')
            ->update(['per_student_billing_starts_at' => now()]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('gradequest_billing_policies')) {
            return;
        }

        Schema::table('gradequest_billing_policies', function (Blueprint $table) {
            if (Schema::hasColumn('gradequest_billing_policies', 'per_student_billing_starts_at')) {
                $table->dropColumn('per_student_billing_starts_at');
            }

            if (Schema::hasColumn('gradequest_billing_policies', 'legacy_subscription_honor_enabled')) {
                $table->dropColumn('legacy_subscription_honor_enabled');
            }
        });
    }
};
