<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_whatsapp_accounts', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('school_id')->unique();
            $table->unsignedBigInteger('admin_user_id')->nullable();

          
            $table->string('phone_number_id')->unique();
            $table->string('display_phone_number')->nullable();
            $table->string('verified_name')->nullable();

            $table->enum('status', [
                'pending',
                'active',
                'disconnected',
                'suspended',
            ])->default('pending');

            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_health_check_at')->nullable();
            $table->json('meta_payload')->nullable();

            $table->timestamps();

            $table->index('admin_user_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_whatsapp_accounts');
    }
};