<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('result_submission_monitors', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('batch_id')->index();
            $table->unsignedBigInteger('class_id')->index();
            $table->unsignedBigInteger('teacher_id')->nullable()->index();

            $table->string('term');
            $table->string('session');

            $table->unsignedInteger('expected_students_count')->default(0);
            $table->unsignedInteger('completed_students_count')->default(0);
            $table->unsignedInteger('pending_students_count')->default(0);

            $table->unsignedInteger('expected_subject_rows_count')->default(0);
            $table->unsignedInteger('completed_subject_rows_count')->default(0);

            $table->date('submission_deadline')->nullable()->index();

            $table->enum('status', ['pending', 'partial', 'complete', 'overdue'])->default('pending')->index();

            $table->timestamp('last_teacher_reminder_sent_at')->nullable();
            $table->timestamp('last_admin_reminder_sent_at')->nullable();
            $table->timestamp('last_scanned_at')->nullable();

            $table->json('meta_json')->nullable();

            $table->timestamps();

            $table->unique(['batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('result_submission_monitors');
    }
};
