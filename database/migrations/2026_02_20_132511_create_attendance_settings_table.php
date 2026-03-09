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
        Schema::create('attendance_settings', function (Blueprint $table) {
           $table->id();
            $table->unsignedBigInteger('school_id')->unique()->index();

            // Staff policy (per school)
            $table->time('staff_checkin_time')->default('08:00:00'); // expected time
            $table->unsignedInteger('grace_minutes')->default(10);   // late after checkin + grace

            // Optional extras
            $table->time('staff_checkout_time')->nullable();
            $table->time('absent_after_time')->nullable(); // for future job: mark absent automatically

            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_settings');
    }
};
