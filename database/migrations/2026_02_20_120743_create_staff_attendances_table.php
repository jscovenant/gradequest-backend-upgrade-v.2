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
        Schema::create('staff_attendances', function (Blueprint $table) {
               $table->id();

            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('user_id');

            // Attendance day (for uniqueness + reporting)
            $table->date('att_date');

            // Check-in / check-out timestamps
            $table->dateTime('check_in_at')->nullable();
            $table->dateTime('check_out_at')->nullable();

            // Status (optional)
            $table->enum('status', ['present', 'late', 'absent', 'on_leave'])->default('present');

            // How it was marked
            $table->string('source')->nullable(); // qr|manual|device etc.
            $table->string('device_id')->nullable();

            // Optional notes
            $table->text('notes')->nullable();

            $table->timestamps();

            // Prevent duplicate record for same staff on same day in same school
            $table->unique(['school_id', 'user_id', 'att_date'], 'uniq_staff_att_school_user_date');

            // Indexes for reporting
            $table->index(['school_id', 'att_date']);
            $table->index(['school_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_attendances');
    }
};
