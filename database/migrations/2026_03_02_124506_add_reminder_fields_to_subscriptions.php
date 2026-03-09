<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('subscriptions', 'reminder_stage')) {
                $table->unsignedTinyInteger('reminder_stage')->default(0)->after('notified_about_expiry');
            }
            if (!Schema::hasColumn('subscriptions', 'last_reminded_at')) {
                $table->timestamp('last_reminded_at')->nullable()->after('reminder_stage');
            }
            if (!Schema::hasColumn('subscriptions', 'grace_days')) {
                $table->unsignedSmallInteger('grace_days')->default(0)->after('ends_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('subscriptions', 'grace_days')) $table->dropColumn('grace_days');
            if (Schema::hasColumn('subscriptions', 'last_reminded_at')) $table->dropColumn('last_reminded_at');
            if (Schema::hasColumn('subscriptions', 'reminder_stage')) $table->dropColumn('reminder_stage');
        });
    }
};