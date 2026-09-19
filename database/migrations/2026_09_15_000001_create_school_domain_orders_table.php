<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('school_domain_orders')) {
            Schema::create('school_domain_orders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('school_settings')->cascadeOnDelete();
                $table->string('domain_name')->index();
                $table->string('tld', 20)->default('.com.ng');
                $table->unsignedInteger('duration_years')->default(1);
                $table->decimal('amount', 12, 2);
                $table->string('payment_gateway')->default('paystack');
                $table->string('payment_reference')->unique();
                $table->string('paystack_transaction_id')->nullable();
                $table->string('status')->default('pending_payment'); // pending_payment, paid, provisioning, active, failed, expired
                $table->boolean('dns_configured')->default(false);
                $table->boolean('auto_renew')->default(true);
                $table->string('registrar_name')->nullable(); // qservers, whogohost, namecheap, manual
                $table->string('registrar_order_id')->nullable();
                $table->json('nameservers')->nullable();
                $table->json('dns_records')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('activated_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->text('failure_reason')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('school_domain_orders');
    }
};
