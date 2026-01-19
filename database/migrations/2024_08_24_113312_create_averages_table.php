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
        Schema::create('averages', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('first_term_result_id');
            $table->bigInteger('second_term_result_id');
            $table->bigInteger('third_term_result_id');
            $table->string('grade')->nullable();
            $table->string('principal_comment')->nullable();
            $table->string('class_teacher_comment')->nullable();
            $table->string('total_average')->nullable();
            $table->string('school_open')->nullable();
            $table->string('school_close')->nullable();
            $table->string('no_present')->nullable();
            $table->string('no_absent')->nullable();
            $table->string('general_remark')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('averages');
    }
};
