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
        Schema::create('assessment_scores_v2', function (Blueprint $table) {
            $table->id();

      $table->foreignId('subject_result_id')->constrained('subject_results_v2')->cascadeOnDelete();
      $table->string('component_key', 32); // ca0, ca1, ca2, ca3
      $table->decimal('score', 8, 2)->default(0);

      $table->timestamps();

      $table->unique(['subject_result_id','component_key'], 'assessment_scores_v2_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assessment_scores_v2');
    }
};
