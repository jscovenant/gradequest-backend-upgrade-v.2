<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_fee_charges', function (Blueprint $table) {
            $table->id();
            // One row per student_fee — since student_fee_id already represents
            // a specific student's fee for a specific term/session, a single
            // unique column is enough to enforce "charge once per term".
            $table->unsignedBigInteger('student_fee_id')->unique();
            $table->enum('status', ['pending', 'confirmed'])->default('pending');
            $table->string('paystack_reference')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_fee_charges');
    }
};