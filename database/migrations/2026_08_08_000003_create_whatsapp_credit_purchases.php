<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gradequest_billing_policies', function (Blueprint $table) {
            if (! Schema::hasColumn('gradequest_billing_policies', 'whatsapp_credit_unit_price')) {
                $table->decimal('whatsapp_credit_unit_price', 12, 2)->default(10)->after('platform_fee_per_student');
            }
        });

        if (! Schema::hasTable('whatsapp_credit_purchases')) {
            Schema::create('whatsapp_credit_purchases', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id')->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('subscription_whatsapp_usage_id')->nullable()->index();
                $table->unsignedInteger('quantity');
                $table->decimal('unit_price', 12, 2);
                $table->decimal('amount', 12, 2);
                $table->string('payment_method', 20);
                $table->string('status', 20)->default('pending')->index();
                $table->string('reference')->unique();
                $table->timestamp('paid_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_credit_purchases');

        Schema::table('gradequest_billing_policies', function (Blueprint $table) {
            if (Schema::hasColumn('gradequest_billing_policies', 'whatsapp_credit_unit_price')) {
                $table->dropColumn('whatsapp_credit_unit_price');
            }
        });
    }
};
