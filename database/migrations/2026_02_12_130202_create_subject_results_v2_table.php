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
        Schema::create('subject_results_v2', function (Blueprint $table) {
            $table->id();

      $table->foreignId('student_result_id')->constrained('student_results_v2')->cascadeOnDelete();
      $table->unsignedBigInteger('subject_id')->index();

      // legacy pointers (for traceability)
      $table->string('legacy_table', 64)->nullable()->index(); // first_term_results|second_term_results|third_term_results
      $table->unsignedBigInteger('legacy_id')->nullable()->index();

      // keep raw CA
      $table->longText('ca_raw')->nullable(); // legacy ca longtext
      $table->string('exam', 255)->nullable();
      $table->string('total', 255)->nullable();

      $table->string('grade', 255)->nullable();
      $table->string('remark', 255)->nullable();
      $table->string('comment', 255)->nullable();
      $table->string('signature', 255)->nullable();

      // for second/third term carry overs
      $table->json('carry_over_json')->nullable(); // {firstterm, secondterm, average}

      $table->timestamps();

      $table->unique(['student_result_id','subject_id'], 'subject_results_v2_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subject_results_v2');
    }
};
