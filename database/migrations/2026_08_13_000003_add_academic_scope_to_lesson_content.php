<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['lesson_schemes', 'generated_lesson_plans', 'lesson_notes'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'level_id')) {
                    $table->unsignedBigInteger('level_id')->nullable()->after('created_by')->index();
                }
                if (! Schema::hasColumn($tableName, 'section_id')) {
                    $table->unsignedBigInteger('section_id')->nullable()->after('level_id')->index();
                }
                if (! Schema::hasColumn($tableName, 'department_id')) {
                    $table->unsignedBigInteger('department_id')->nullable()->after('section_id')->index();
                }
                if (! Schema::hasColumn($tableName, 'subject_id')) {
                    $table->unsignedBigInteger('subject_id')->nullable()->after('department_id')->index();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['lesson_schemes', 'generated_lesson_plans', 'lesson_notes'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                foreach (['subject_id', 'department_id', 'section_id', 'level_id'] as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};