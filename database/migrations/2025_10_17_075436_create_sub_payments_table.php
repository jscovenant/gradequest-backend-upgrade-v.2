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
        Schema::create('sub_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->bigInteger('subscription_id')->nullable();
            $table->string('reference')->unique();
            $table->string('paystack_id')->unique();
            $table->decimal('amount', 10, 2);
            $table->string('status'); // e.g., successful, failed, pending
            $table->string('channel')->nullable(); // e.g., card, bank
            $table->string('card_type')->nullable();
            $table->string('last4')->nullable();
            $table->timestamp('paid_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sub_payments');
    }
};
