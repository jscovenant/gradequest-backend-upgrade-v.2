<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('student_results_v2', function (Blueprint $table) {
          $table->id();

      $table->foreignId('batch_id')->constrained('result_batches')->cascadeOnDelete();
      $table->unsignedBigInteger('user_id')->index(); // student user id

      // Link to legacy header (averages.id)
      $table->unsignedBigInteger('average_legacy_id')->nullable()->index();

      // fields similar to averages
      $table->string('rollno', 255)->nullable();
      $table->string('department', 255)->nullable();
      $table->integer('section_id')->nullable();

      $table->string('position', 20)->nullable();
      $table->string('class_teacher', 255)->nullable();
      $table->string('class_size', 255)->nullable();

      $table->string('total_grade', 255)->nullable();
      $table->string('total_average', 255)->nullable();

      $table->string('principal_comment', 255)->nullable();
      $table->string('class_teacher_comment', 255)->nullable();
      $table->string('general_remark', 255)->nullable();

      $table->json('meta_json')->nullable(); // school_open, close, present, absent, resumption_date

      $table->timestamps();

      $table->unique(['batch_id','user_id'], 'student_results_v2_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_results_v2');
    }
};
