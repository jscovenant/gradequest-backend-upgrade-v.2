<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('staff_attendance_sessions')) {
            Schema::create('staff_attendance_sessions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id')->index();
                $table->string('token_hash', 64)->unique();
                $table->enum('mode', ['auto', 'checkin', 'checkout'])->default('auto');
                $table->dateTime('expires_at')->index();
                $table->dateTime('closed_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('attendance_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('attendance_settings', 'school_latitude')) {
                $table->decimal('school_latitude', 10, 7)->nullable()->after('absent_after_time');
            }

            if (! Schema::hasColumn('attendance_settings', 'school_longitude')) {
                $table->decimal('school_longitude', 10, 7)->nullable()->after('school_latitude');
            }

            if (! Schema::hasColumn('attendance_settings', 'allowed_radius_meters')) {
                $table->unsignedInteger('allowed_radius_meters')->default(100)->after('school_longitude');
            }

            if (! Schema::hasColumn('attendance_settings', 'qr_expires_seconds')) {
                $table->unsignedInteger('qr_expires_seconds')->default(60)->after('allowed_radius_meters');
            }

            if (! Schema::hasColumn('attendance_settings', 'require_location_verification')) {
                $table->boolean('require_location_verification')->default(true)->after('qr_expires_seconds');
            }
        });

        Schema::table('staff_attendances', function (Blueprint $table) {
            if (! Schema::hasColumn('staff_attendances', 'attendance_session_id')) {
                $table->unsignedBigInteger('attendance_session_id')->nullable()->after('user_id')->index();
            }

            if (! Schema::hasColumn('staff_attendances', 'check_in_latitude')) {
                $table->decimal('check_in_latitude', 10, 7)->nullable()->after('check_in_at');
            }

            if (! Schema::hasColumn('staff_attendances', 'check_in_longitude')) {
                $table->decimal('check_in_longitude', 10, 7)->nullable()->after('check_in_latitude');
            }

            if (! Schema::hasColumn('staff_attendances', 'check_in_distance_meters')) {
                $table->unsignedInteger('check_in_distance_meters')->nullable()->after('check_in_longitude');
            }

            if (! Schema::hasColumn('staff_attendances', 'check_out_latitude')) {
                $table->decimal('check_out_latitude', 10, 7)->nullable()->after('check_out_at');
            }

            if (! Schema::hasColumn('staff_attendances', 'check_out_longitude')) {
                $table->decimal('check_out_longitude', 10, 7)->nullable()->after('check_out_latitude');
            }

            if (! Schema::hasColumn('staff_attendances', 'check_out_distance_meters')) {
                $table->unsignedInteger('check_out_distance_meters')->nullable()->after('check_out_longitude');
            }

            if (! Schema::hasColumn('staff_attendances', 'location_verified')) {
                $table->boolean('location_verified')->default(false)->after('device_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('staff_attendances', function (Blueprint $table) {
            foreach ([
                'attendance_session_id',
                'check_in_latitude',
                'check_in_longitude',
                'check_in_distance_meters',
                'check_out_latitude',
                'check_out_longitude',
                'check_out_distance_meters',
                'location_verified',
            ] as $column) {
                if (Schema::hasColumn('staff_attendances', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('attendance_settings', function (Blueprint $table) {
            foreach ([
                'school_latitude',
                'school_longitude',
                'allowed_radius_meters',
                'qr_expires_seconds',
                'require_location_verification',
            ] as $column) {
                if (Schema::hasColumn('attendance_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('staff_attendance_sessions');
    }
};
