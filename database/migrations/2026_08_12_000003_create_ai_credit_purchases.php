<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_credit_purchases')) {
            Schema::create('ai_credit_purchases', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->nullable()->constrained('school_settings')->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('subscription_ai_usage_id')->nullable()->constrained('subscription_ai_usages')->nullOnDelete();
                $table->unsignedInteger('quantity');
                $table->decimal('unit_price', 12, 2);
                $table->decimal('amount', 12, 2);
                $table->string('payment_method', 20);
                $table->string('status', 20)->default('pending')->index();
                $table->string('reference')->unique();
                $table->timestamp('paid_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['school_id', 'status', 'created_at'], 'ai_credit_purchase_school_status_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_credit_purchases');
    }
};
