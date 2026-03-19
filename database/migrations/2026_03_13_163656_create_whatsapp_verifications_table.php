<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_verifications', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('user_id');
            $table->enum('actor_type', ['admin', 'parent']);
            $table->string('phone');
            $table->string('normalized_phone');
            $table->string('code_hash');
            $table->string('channel')->default('mixed');
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->enum('status', [
                'pending',
                'verified',
                'expired',
                'failed',
            ])->default('pending');

            $table->timestamps();

            $table->index(['school_id', 'user_id', 'actor_type']);
            $table->index(['normalized_phone', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_verifications');
    }
};