<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_credit_transactions')) {
            return;
        }

        Schema::create('whatsapp_credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('school_settings')->cascadeOnDelete();
            $table->foreignId('subscription_whatsapp_usage_id');
            $table->foreignId('whatsapp_message_id')->nullable()->constrained('whatsapp_messages')->nullOnDelete();
            $table->string('type', 30);
            $table->integer('credits');
            $table->string('reference')->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'type', 'created_at'], 'wa_credit_school_type_created_idx');
            $table->foreign('subscription_whatsapp_usage_id', 'wa_credit_usage_fk')->references('id')->on('subscription_whatsapp_usages')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_credit_transactions');
    }
};


