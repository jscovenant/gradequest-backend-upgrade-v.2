<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_bookings', function (Blueprint $table) {
            $table->id();

            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->index();
            $table->string('phone');

            $table->string('role');
            $table->string('school_name')->index();
            $table->string('school_type');
            $table->string('student_count');

            $table->date('preferred_date');
            $table->string('preferred_time');

            $table->text('message')->nullable();

            $table->string('status')->default('pending'); // pending, confirmed, cancelled, completed
            $table->string('source')->default('website');

            $table->timestamps();

            $table->index(['preferred_date', 'preferred_time']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_bookings');
    }
};