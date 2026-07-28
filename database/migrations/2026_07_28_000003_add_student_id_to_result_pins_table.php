<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('result_pins', function (Blueprint $table) {
            if (! Schema::hasColumn('result_pins', 'student_id')) {
                $table->unsignedBigInteger('student_id')->nullable()->after('school_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('result_pins', function (Blueprint $table) {
            if (Schema::hasColumn('result_pins', 'student_id')) {
                $table->dropColumn('student_id');
            }
        });
    }
};
