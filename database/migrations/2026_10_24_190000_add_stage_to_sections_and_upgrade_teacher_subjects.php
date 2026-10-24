<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add `stage` to `sections` table if not exists
        if (Schema::hasTable('sections') && !Schema::hasColumn('sections', 'stage')) {
            Schema::table('sections', function (Blueprint $table) {
                $table->string('stage', 50)->default('basic')->after('name');
            });

            // Backfill existing sections based on standard naming conventions
            DB::statement("
                UPDATE sections 
                SET stage = CASE 
                    WHEN LOWER(name) REGEXP 'junior|senior|secondary|jss|sss|college|high' THEN 'secondary'
                    WHEN LOWER(name) REGEXP 'nursery|creche|kindergarten|kg|early|toddler|playgroup' THEN 'early_years'
                    ELSE 'basic'
                END
                WHERE stage IS NULL OR stage = '' OR stage = 'basic'
            ");
        }

        // 2. Add `school_id` and `class_id` to `teacher_subjects` table if not exists
        if (Schema::hasTable('teacher_subjects')) {
            Schema::table('teacher_subjects', function (Blueprint $table) {
                if (!Schema::hasColumn('teacher_subjects', 'school_id')) {
                    $table->unsignedBigInteger('school_id')->nullable()->after('id');
                }
                if (!Schema::hasColumn('teacher_subjects', 'class_id')) {
                    $table->unsignedBigInteger('class_id')->nullable()->after('subject_id');
                }
            });

            // Backfill school_id for any existing records from users table (pure safety, zero data loss)
            DB::statement("
                UPDATE teacher_subjects ts
                INNER JOIN users u ON u.id = ts.teacher_id
                SET ts.school_id = u.school_id
                WHERE ts.school_id IS NULL AND u.school_id IS NOT NULL
            ");

            // Add indexes and foreign keys safely
            Schema::table('teacher_subjects', function (Blueprint $table) {
                $table->index(['school_id', 'teacher_id'], 'ts_school_teacher_idx');
                $table->index(['class_id', 'subject_id'], 'ts_class_subject_idx');

                if (Schema::hasTable('school_settings')) {
                    $table->foreign('school_id', 'fk_ts_school_settings')
                        ->references('id')->on('school_settings')
                        ->onDelete('cascade');
                }
                if (Schema::hasTable('student_classes')) {
                    $table->foreign('class_id', 'fk_ts_student_classes')
                        ->references('id')->on('student_classes')
                        ->onDelete('cascade');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('teacher_subjects')) {
            Schema::table('teacher_subjects', function (Blueprint $table) {
                try {
                    $table->dropForeign('fk_ts_school_settings');
                } catch (\Throwable $e) {}
                try {
                    $table->dropForeign('fk_ts_student_classes');
                } catch (\Throwable $e) {}
                try {
                    $table->dropIndex('ts_school_teacher_idx');
                } catch (\Throwable $e) {}
                try {
                    $table->dropIndex('ts_class_subject_idx');
                } catch (\Throwable $e) {}

                if (Schema::hasColumn('teacher_subjects', 'school_id')) {
                    $table->dropColumn('school_id');
                }
                if (Schema::hasColumn('teacher_subjects', 'class_id')) {
                    $table->dropColumn('class_id');
                }
            });
        }

        if (Schema::hasTable('sections') && Schema::hasColumn('sections', 'stage')) {
            Schema::table('sections', function (Blueprint $table) {
                $table->dropColumn('stage');
            });
        }
    }
};
