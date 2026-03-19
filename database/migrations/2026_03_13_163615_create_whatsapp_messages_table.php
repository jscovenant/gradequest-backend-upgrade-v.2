<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->unsignedBigInteger('parent_user_id')->nullable();
            $table->unsignedBigInteger('student_user_id')->nullable();
            $table->unsignedBigInteger('school_whatsapp_account_id');

            $table->string('to_phone');
            $table->string('normalized_phone');
            $table->string('template_name')->nullable();
            $table->string('template_lang')->default('en');

            $table->enum('status', [
                'queued',
                'sent',
                'delivered',
                'read',
                'failed',
            ])->default('queued');

            $table->string('meta_message_id')->nullable()->index();
            $table->unsignedInteger('credit_cost')->default(1);

            $table->json('payload')->nullable();
            $table->json('meta_response')->nullable();
            $table->text('failure_reason')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            $table->index(['school_id', 'parent_user_id']);
            $table->index(['school_id', 'student_user_id']);
            $table->index(['school_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};