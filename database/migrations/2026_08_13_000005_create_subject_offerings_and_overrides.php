<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subject_offerings')) {
            Schema::create('subject_offerings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id')->index();
                $table->unsignedBigInteger('subject_id')->index();
                $table->unsignedBigInteger('level_id')->nullable()->index();
                $table->unsignedBigInteger('section_id')->nullable()->index();
                $table->unsignedBigInteger('department_id')->nullable()->index();
                $table->unsignedBigInteger('academic_session_id')->nullable()->index();
                $table->unsignedBigInteger('term_id')->nullable()->index();
                $table->boolean('is_compulsory')->default(true);
                $table->timestamps();
                $table->unique(['school_id', 'subject_id', 'level_id', 'section_id', 'department_id', 'academic_session_id', 'term_id'], 'subject_offerings_unique_scope');
            });
        }

        if (! Schema::hasTable('student_subject_overrides')) {
            Schema::create('student_subject_overrides', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id')->index();
                $table->unsignedBigInteger('student_id')->index();
                $table->unsignedBigInteger('subject_id')->index();
                $table->string('action', 20)->index();
                $table->unsignedBigInteger('academic_session_id')->nullable()->index();
                $table->unsignedBigInteger('term_id')->nullable()->index();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->string('reason')->nullable();
                $table->timestamps();
                $table->unique(['school_id', 'student_id', 'subject_id', 'academic_session_id', 'term_id'], 'student_subject_override_unique_scope');
            });
        }

        if (Schema::hasTable('subjects') && Schema::hasTable('subject_offerings')) {
            $now = now();
            DB::table('subjects')
                ->whereNull('archived_at')
                ->orderBy('id')
                ->chunkById(200, function ($subjects) use ($now) {
                    foreach ($subjects as $subject) {
                        DB::table('subject_offerings')->updateOrInsert([
                            'school_id' => $subject->school_id,
                            'subject_id' => $subject->id,
                            'level_id' => $subject->class_id ?? null,
                            'section_id' => $subject->section_id ?? null,
                            'department_id' => $subject->department_id ?? null,
                            'academic_session_id' => null,
                            'term_id' => null,
                        ], [
                            'is_compulsory' => true,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_subject_overrides');
        Schema::dropIfExists('subject_offerings');
    }
};