<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cbt_exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('school_settings')->cascadeOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained('subjects')->nullOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('student_classes')->nullOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('term_id')->nullable()->constrained('terms')->nullOnDelete();
            $table->foreignId('academic_session_id')->nullable()->constrained('academic_sessions')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('exam_code')->nullable();
            $table->enum('delivery_mode', ['online', 'offline', 'hybrid'])->default('online');
            $table->enum('status', ['draft', 'published', 'closed', 'archived'])->default('draft');
            $table->unsignedInteger('duration_minutes')->default(60);
            $table->decimal('total_marks', 8, 2)->default(0);
            $table->decimal('pass_mark', 8, 2)->default(0);
            $table->unsignedInteger('max_attempts')->default(1);
            $table->boolean('shuffle_questions')->default(true);
            $table->boolean('shuffle_options')->default(true);
            $table->boolean('show_result_after_submit')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->longText('general_instructions')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'status']);
            $table->index(['school_id', 'delivery_mode']);
        });

        Schema::create('cbt_exam_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('cbt_exams')->cascadeOnDelete();
            $table->string('title');
            $table->longText('instructions')->nullable();
            $table->unsignedInteger('sort_order')->default(1);
            $table->decimal('default_marks', 8, 2)->default(1);
            $table->boolean('shuffle_questions')->nullable();
            $table->timestamps();
        });

        Schema::create('cbt_question_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('cbt_exams')->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('cbt_exam_sections')->nullOnDelete();
            $table->enum('group_type', ['instruction', 'comprehension', 'case_study'])->default('instruction');
            $table->string('title')->nullable();
            $table->longText('instructions')->nullable();
            $table->longText('passage')->nullable();
            $table->unsignedInteger('sort_order')->default(1);
            $table->timestamps();
        });

        Schema::create('cbt_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('cbt_exams')->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('cbt_exam_sections')->nullOnDelete();
            $table->foreignId('question_group_id')->nullable()->constrained('cbt_question_groups')->nullOnDelete();
            $table->enum('question_type', ['single_choice', 'multiple_choice', 'true_false', 'fill_blank', 'theory'])->default('single_choice');
            $table->longText('question_text');
            $table->longText('instructions')->nullable();
            $table->longText('explanation')->nullable();
            $table->decimal('marks', 8, 2)->default(1);
            $table->unsignedInteger('sort_order')->default(1);
            $table->string('difficulty')->nullable();
            $table->json('correct_answer')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('cbt_question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('cbt_questions')->cascadeOnDelete();
            $table->string('label', 10)->nullable();
            $table->longText('option_text');
            $table->boolean('is_correct')->default(false);
            $table->unsignedInteger('sort_order')->default(1);
            $table->timestamps();
        });

        Schema::create('cbt_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('cbt_exams')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('school_settings')->cascadeOnDelete();
            $table->enum('delivery_mode', ['online', 'offline'])->default('online');
            $table->enum('status', ['in_progress', 'submitted', 'auto_submitted', 'cancelled'])->default('in_progress');
            $table->unsignedInteger('attempt_number')->default(1);
            $table->decimal('score', 8, 2)->default(0);
            $table->decimal('total_marks', 8, 2)->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('device_name')->nullable();
            $table->string('ip_address')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['exam_id', 'student_id', 'attempt_number'], 'cbt_attempt_student_number_unique');
            $table->index(['school_id', 'status']);
        });

        Schema::create('cbt_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained('cbt_attempts')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('cbt_questions')->cascadeOnDelete();
            $table->json('selected_option_ids')->nullable();
            $table->longText('answer_text')->nullable();
            $table->boolean('is_correct')->nullable();
            $table->decimal('score', 8, 2)->default(0);
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->unique(['attempt_id', 'question_id']);
        });

        Schema::create('cbt_offline_licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('school_settings')->cascadeOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('license_key')->unique();
            $table->json('allowed_features')->nullable();
            $table->unsignedInteger('max_students')->default(0);
            $table->unsignedInteger('max_exams')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->enum('status', ['active', 'revoked', 'expired'])->default('active');
            $table->text('signature');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'status']);
        });

        Schema::create('cbt_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('school_settings')->cascadeOnDelete();
            $table->foreignId('offline_license_id')->nullable()->constrained('cbt_offline_licenses')->nullOnDelete();
            $table->string('sync_reference')->unique();
            $table->enum('direction', ['to_offline_server', 'from_offline_server']);
            $table->enum('status', ['pending', 'successful', 'failed'])->default('pending');
            $table->unsignedInteger('records_count')->default(0);
            $table->json('summary')->nullable();
            $table->longText('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cbt_sync_logs');
        Schema::dropIfExists('cbt_offline_licenses');
        Schema::dropIfExists('cbt_answers');
        Schema::dropIfExists('cbt_attempts');
        Schema::dropIfExists('cbt_question_options');
        Schema::dropIfExists('cbt_questions');
        Schema::dropIfExists('cbt_question_groups');
        Schema::dropIfExists('cbt_exam_sections');
        Schema::dropIfExists('cbt_exams');
    }
};
