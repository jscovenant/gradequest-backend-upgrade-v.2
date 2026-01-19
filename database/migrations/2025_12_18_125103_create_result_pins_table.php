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
        Schema::create('result_pins', function (Blueprint $table) {
           $table->id();
    $table->foreignId('school_id')->constrained('school_settings')->cascadeOnDelete();
    $table->string('pin')->unique();
    $table->string('term');             
    $table->string('session');           
    $table->integer('max_uses')->default(1);
    $table->integer('used_count')->default(0);
    $table->boolean('is_active')->default(true);
    $table->timestamp('expires_at')->nullable();
    $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('result_pins');
    }
};
