<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('school_settings', function (Blueprint $table) {
            $table->boolean('enable_result_monitoring')->default(true);
            $table->unsignedTinyInteger('submission_reminder_days_before')->default(3);
            $table->unsignedTinyInteger('minimum_history_records_for_outlier')->default(2);
            $table->decimal('student_drop_alert_threshold', 8, 2)->default(35.00);
            $table->decimal('uniformity_stddev_threshold', 8, 2)->default(3.00);
            $table->decimal('uniformity_range_threshold', 8, 2)->default(5.00);
            $table->boolean('block_publish_on_high_alert')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('school_settings', function (Blueprint $table) {
            $table->dropColumn([
                'enable_result_monitoring',
                'submission_reminder_days_before',
                'minimum_history_records_for_outlier',
                'student_drop_alert_threshold',
                'uniformity_stddev_threshold',
                'uniformity_range_threshold',
                'block_publish_on_high_alert',
            ]);
        });
    }
};
