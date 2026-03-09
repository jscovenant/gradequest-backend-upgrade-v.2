<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->nullable(); // null = global
            $table->enum('audience', ['parents', 'admins', 'both'])->default('parents');
            $table->enum('channel', ['email', 'whatsapp', 'both'])->default('email');

            $table->string('subject')->nullable(); // email subject
            $table->text('message')->nullable();   // email body / fallback text

            // WhatsApp template (recommended)
            $table->string('whatsapp_template_name')->nullable();
            $table->string('whatsapp_lang')->default('en_US');
            $table->json('whatsapp_params')->nullable(); // array of strings for template placeholders

            $table->timestamp('scheduled_for')->index();
            $table->enum('status', ['draft', 'scheduled', 'processing', 'sent', 'cancelled'])->default('scheduled');

            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcasts');
    }
};