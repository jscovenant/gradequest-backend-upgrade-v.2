<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Tracks online payment state; existing manually-recorded rows
            // default to 'success' since they were already received.
            $table->enum('status', ['pending', 'success', 'failed'])
                ->default('success')
                ->after('payment_method');

            // GradeQuest's one-time-per-term cut on this specific transaction,
            // in naira — 0 for transactions that didn't carry the fee.
            $table->decimal('platform_fee', 10, 0)->default(0)->after('amount');

            // Raw Paystack verify/webhook payload, for audit/debugging.
            $table->json('paystack_response')->nullable()->after('reference');

            // The parent/user who initiated an online payment.
            // Distinct from received_by, which is for staff-recorded offline payments.
            $table->unsignedBigInteger('paid_by')->nullable()->after('received_by');
        });

        // received_by was NOT NULL — online, self-service payments have no
        // staff "receiver", so it needs to be nullable. This requires
        // doctrine/dbal (composer require doctrine/dbal). If you'd rather
        // skip that dependency, run this raw statement manually instead
        // and remove the block below:
        // ALTER TABLE payments MODIFY received_by BIGINT UNSIGNED NULL;
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('received_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['status', 'platform_fee', 'paystack_response', 'paid_by']);
            $table->unsignedBigInteger('received_by')->nullable(false)->change();
        });
    }
};