<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbt_exams', function (Blueprint $table) {
            if (! Schema::hasColumn('cbt_exams', 'access_code_required')) {
                $table->boolean('access_code_required')->default(false)->after('show_result_after_submit');
            }

            if (! Schema::hasColumn('cbt_exams', 'access_code')) {
                $table->string('access_code', 80)->nullable()->after('access_code_required');
            }

            if (! Schema::hasColumn('cbt_exams', 'calculator_enabled')) {
                $table->boolean('calculator_enabled')->default(false)->after('access_code');
            }
        });

        Schema::table('cbt_attempts', function (Blueprint $table) {
            if (! Schema::hasColumn('cbt_attempts', 'public_access_token')) {
                $table->string('public_access_token', 100)->nullable()->unique()->after('attempt_number');
            }
        });

        Schema::create('cbt_exam_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('cbt_exams')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('school_settings')->cascadeOnDelete();
            $table->date('exam_date');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('venue')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'exam_date']);
            $table->index(['exam_id', 'exam_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cbt_exam_schedules');

        Schema::table('cbt_attempts', function (Blueprint $table) {
            if (Schema::hasColumn('cbt_attempts', 'public_access_token')) {
                $table->dropUnique(['public_access_token']);
                $table->dropColumn('public_access_token');
            }
        });

        Schema::table('cbt_exams', function (Blueprint $table) {
            foreach (['calculator_enabled', 'access_code', 'access_code_required'] as $column) {
                if (Schema::hasColumn('cbt_exams', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
