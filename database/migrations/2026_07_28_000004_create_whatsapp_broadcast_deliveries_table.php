<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_broadcast_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('parent_id')->index();
            $table->unsignedBigInteger('student_id')->nullable()->index();
            $table->unsignedBigInteger('class_id')->nullable()->index();
            $table->string('broadcast_type', 60);
            $table->string('term', 80);
            $table->string('session', 80);
            $table->string('period_key', 180)->index();
            $table->string('recipient_phone')->nullable();
            $table->string('provider')->default('twilio');
            $table->string('provider_sid')->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->text('message_hash')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['school_id', 'parent_id', 'broadcast_type', 'period_key'],
                'whatsapp_delivery_parent_period_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_broadcast_deliveries');
    }
};
