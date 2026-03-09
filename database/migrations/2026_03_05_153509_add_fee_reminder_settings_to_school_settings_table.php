<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('school_settings', function (Blueprint $table) {
            $table->boolean('fee_reminders_enabled')->default(true)->after('background_color');

            // How often to re-remind after first reminder
            $table->unsignedInteger('fee_reminder_interval_days')->default(5)->after('fee_reminders_enabled');

            // Maximum number of reminders (excluding the first send, or including—your choice)
            $table->unsignedInteger('fee_reminder_max_count')->default(6)->after('fee_reminder_interval_days');

            // Channels
            $table->boolean('fee_reminder_send_email')->default(true)->after('fee_reminder_max_count');
            $table->boolean('fee_reminder_send_whatsapp')->default(false)->after('fee_reminder_send_email');

            // Optional: quiet hours (server time)
            $table->string('fee_reminder_quiet_hours_start')->nullable()->after('fee_reminder_send_whatsapp'); // "22:00"
            $table->string('fee_reminder_quiet_hours_end')->nullable()->after('fee_reminder_quiet_hours_start');   // "06:00"
        });
    }

    public function down(): void
    {
        Schema::table('school_settings', function (Blueprint $table) {
            $table->dropColumn([
                'fee_reminders_enabled',
                'fee_reminder_interval_days',
                'fee_reminder_max_count',
                'fee_reminder_send_email',
                'fee_reminder_send_whatsapp',
                'fee_reminder_quiet_hours_start',
                'fee_reminder_quiet_hours_end',
            ]);
        });
    }
};