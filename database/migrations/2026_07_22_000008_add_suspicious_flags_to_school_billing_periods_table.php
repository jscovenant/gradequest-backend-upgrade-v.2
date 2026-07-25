<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_billing_periods', function (Blueprint $table) {
            if (! Schema::hasColumn('school_billing_periods', 'term_activated_at')) {
                $table->dateTime('term_activated_at')->nullable()->after('billing_grace_ends_at');
            }

            if (! Schema::hasColumn('school_billing_periods', 'first_protected_activity_at')) {
                $table->dateTime('first_protected_activity_at')->nullable()->after('term_activated_at');
            }

            if (! Schema::hasColumn('school_billing_periods', 'suspicious_flags')) {
                $table->json('suspicious_flags')->nullable()->after('reason');
            }

            if (! Schema::hasColumn('school_billing_periods', 'flagged_at')) {
                $table->dateTime('flagged_at')->nullable()->after('suspicious_flags');
            }
        });
    }

    public function down(): void
    {
        Schema::table('school_billing_periods', function (Blueprint $table) {
            foreach (['flagged_at', 'suspicious_flags', 'first_protected_activity_at', 'term_activated_at'] as $column) {
                if (Schema::hasColumn('school_billing_periods', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
