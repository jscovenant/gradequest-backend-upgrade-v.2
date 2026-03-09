<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('school_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('school_settings', 'whatsapp_enabled')) {
                $table->tinyInteger('whatsapp_enabled')->default(0)->after('auto_admission');
            }
            if (!Schema::hasColumn('school_settings', 'whatsapp_fee_reminders')) {
                $table->tinyInteger('whatsapp_fee_reminders')->default(0)->after('whatsapp_enabled');
            }
            if (!Schema::hasColumn('school_settings', 'whatsapp_activity_notices')) {
                $table->tinyInteger('whatsapp_activity_notices')->default(0)->after('whatsapp_fee_reminders');
            }
            if (!Schema::hasColumn('school_settings', 'whatsapp_subscription_reminders')) {
                $table->tinyInteger('whatsapp_subscription_reminders')->default(0)->after('whatsapp_activity_notices');
            }
        });
    }

    public function down(): void
    {
        Schema::table('school_settings', function (Blueprint $table) {
            foreach ([
                'whatsapp_subscription_reminders',
                'whatsapp_activity_notices',
                'whatsapp_fee_reminders',
                'whatsapp_enabled',
            ] as $col) {
                if (Schema::hasColumn('school_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};