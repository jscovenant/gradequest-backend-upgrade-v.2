<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('public_fee_payment_intents')) {
            return;
        }

        Schema::create('public_fee_payment_intents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('school_settings')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->string('school_code', 80)->index();
            $table->string('student_reg_no', 80)->index();
            $table->string('reference')->unique();
            $table->string('payer_email')->nullable();
            $table->string('payer_name')->nullable();
            $table->string('payer_phone', 40)->nullable();
            $table->decimal('amount', 12, 2);
            $table->decimal('platform_fee', 12, 2)->default(0);
            $table->json('allocations')->nullable();
            $table->enum('status', ['pending', 'success', 'failed'])->default('pending');
            $table->json('paystack_response')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'student_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_fee_payment_intents');
    }
};
