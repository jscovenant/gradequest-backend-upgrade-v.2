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
                if (! Schema::hasColumn($tableName, 'academic_session_id')) {
                    $table->unsignedBigInteger('academic_session_id')->nullable()->after('subject_id')->index();
                }

                if (! Schema::hasColumn($tableName, 'term_id')) {
                    $table->unsignedBigInteger('term_id')->nullable()->after('academic_session_id')->index();
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
                foreach (['term_id', 'academic_session_id'] as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
