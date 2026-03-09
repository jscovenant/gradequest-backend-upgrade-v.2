<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('combined_fee_reminder_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('combined_fee_reminder_logs', 'is_read')) {
                $table->boolean('is_read')->default(false)->after('payload');
            }

            if (!Schema::hasColumn('combined_fee_reminder_logs', 'read_at')) {
                $table->timestamp('read_at')->nullable()->after('is_read');
            }
        });
    }

    public function down(): void
    {
        Schema::table('combined_fee_reminder_logs', function (Blueprint $table) {
            if (Schema::hasColumn('combined_fee_reminder_logs', 'read_at')) {
                $table->dropColumn('read_at');
            }

            if (Schema::hasColumn('combined_fee_reminder_logs', 'is_read')) {
                $table->dropColumn('is_read');
            }
        });
    }
};