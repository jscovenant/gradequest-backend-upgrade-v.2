<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('academic_alerts', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('batch_id')->nullable()->index();
            $table->unsignedBigInteger('class_id')->nullable()->index();
            $table->unsignedBigInteger('teacher_id')->nullable()->index();
            $table->unsignedBigInteger('student_id')->nullable()->index();
            $table->unsignedBigInteger('subject_id')->nullable()->index();

            $table->string('type')->index();       // incomplete_submission, student_outlier, uniform_scores
            $table->string('severity')->default('medium')->index(); // low, medium, high
            $table->string('status')->default('open')->index();     // open, reviewed, dismissed, resolved

            $table->string('title');
            $table->text('message');
            $table->json('context_json')->nullable();

            $table->unsignedBigInteger('reviewed_by')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_alerts');
    }
};
