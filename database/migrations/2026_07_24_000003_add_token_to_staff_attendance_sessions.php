<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_attendance_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('staff_attendance_sessions', 'token')) {
                $table->string('token', 96)->nullable()->after('token_hash');
            }
        });
    }

    public function down(): void
    {
        Schema::table('staff_attendance_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('staff_attendance_sessions', 'token')) {
                $table->dropColumn('token');
            }
        });
    }
};
