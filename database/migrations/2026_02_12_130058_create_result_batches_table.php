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
        Schema::create('result_batches', function (Blueprint $table) {
             $table->id();
      $table->unsignedBigInteger('school_id')->index();
      $table->integer('class_id')->index();
      $table->string('term', 255)->index();     // keep legacy string
      $table->string('session', 255)->index();  // keep legacy string

      $table->enum('status', ['draft','computed','approved','published'])->default('draft');
      $table->unsignedBigInteger('created_by')->nullable()->index();

      $table->timestamps();

      $table->unique(['school_id','class_id','term','session'], 'result_batches_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('result_batches');
    }
};
