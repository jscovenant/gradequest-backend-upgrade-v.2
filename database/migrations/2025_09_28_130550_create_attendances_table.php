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
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();

            // If students are stored in the users table
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();

            // Class reference (can later be changed to foreignId if needed)
            $table->bigInteger('class_id');

            // School reference
            $table->bigInteger('school_id');

            $table->date('date');
            $table->enum('status', ['present', 'absent', 'late', 'excused'])->default('absent');
            $table->string('remarks')->nullable();

            $table->timestamps();

            $table->unique(['student_id', 'class_id', 'date']); // prevent duplicate attendance
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
