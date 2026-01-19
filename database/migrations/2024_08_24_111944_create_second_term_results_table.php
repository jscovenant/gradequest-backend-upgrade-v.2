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
        Schema::create('second_term_results', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id');
            $table->bigInteger('subject_id');
            $table->string('rollno')->nullable();
            $table->string('ca')->nullable();
            $table->string('exam')->nullable();
            $table->string('firstterm')->nullable();
            $table->string('total')->nullable();
            $table->string('average')->nullable();
            $table->string('grade')->nullable();
            $table->string('remark')->nullable();
            $table->string('comment')->nullable();
            $table->string('signature')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('second_term_results');
    }
};
