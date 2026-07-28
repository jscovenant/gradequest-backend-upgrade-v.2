<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('school_settings') || Schema::hasColumn('school_settings', 'fee_access_policy')) {
            return;
        }

        Schema::table('school_settings', function (Blueprint $table) {
            $table->json('fee_access_policy')->nullable()->after('fee_reminder_quiet_hours_end');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('school_settings') || ! Schema::hasColumn('school_settings', 'fee_access_policy')) {
            return;
        }

        Schema::table('school_settings', function (Blueprint $table) {
            $table->dropColumn('fee_access_policy');
        });
    }
};
