<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gradequest_invoice_payments')) {
            Schema::create('gradequest_invoice_payments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id');
                $table->unsignedBigInteger('invoice_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('reference')->unique();
                $table->decimal('amount', 12, 2);
                $table->string('status')->default('pending');
                $table->string('channel')->nullable();
                $table->string('card_type')->nullable();
                $table->string('last4')->nullable();
                $table->string('paystack_id')->nullable();
                $table->json('paystack_response')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();

                $table->index(['school_id', 'status']);
                $table->index(['invoice_id', 'status']);
                $table->foreign('school_id')->references('id')->on('school_settings')->cascadeOnDelete();
                $table->foreign('invoice_id')->references('id')->on('gradequest_term_invoices')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gradequest_invoice_payments');
    }
};
