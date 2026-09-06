<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_fee_payment_intents', function (Blueprint $table) {
            if (! Schema::hasColumn('public_fee_payment_intents', 'gateway')) {
                $table->string('gateway', 30)->default('monnify')->after('allocations');
            }
            if (! Schema::hasColumn('public_fee_payment_intents', 'monnify_response')) {
                $table->json('monnify_response')->nullable()->after('paystack_response');
            }
        });
    }

    public function down(): void
    {
        Schema::table('public_fee_payment_intents', function (Blueprint $table) {
            if (Schema::hasColumn('public_fee_payment_intents', 'gateway')) {
                $table->dropColumn('gateway');
            }
            if (Schema::hasColumn('public_fee_payment_intents', 'monnify_response')) {
                $table->dropColumn('monnify_response');
            }
        });
    }
};
