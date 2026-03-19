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
        Schema::create('school_domains', function (Blueprint $table) {
              $table->id();
                $table->foreignId('school_id')->constrained("school_settings")->cascadeOnDelete();
                $table->string('domain')->unique();         
                $table->string('type')->default('custom');   
                $table->string('status')->default('pending');
                $table->string('verification_token')->nullable(); 
                $table->timestamp('verified_at')->nullable();
                $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('school_domains');
    }
};
