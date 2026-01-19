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
        Schema::create('online_admissions', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('level_id');
            $table->bigInteger('department_id');
            $table->string('firstname')->nullable();
            $table->string('surname')->nullable();
            $table->string('dob')->nullable();
            $table->string('photo')->nullable();
            $table->string('reference')->nullable();
            $table->string('sex')->nullable();
            $table->string('address')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('status')->nullable();
            $table->string('parent_first_name')->nullable();
            $table->string('parent_last_name')->nullable();
            $table->string('parent_address')->nullable();
            $table->string('parent_phone')->nullable();
            $table->string('parent_photo')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('online_admissions');
    }
};
