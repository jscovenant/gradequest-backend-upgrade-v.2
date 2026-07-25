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
        Schema::table('school_bank_accounts', function (Blueprint $table) {
            $table->boolean('online_payment_enabled')
                ->default(false)
                ->after('paystack_subaccount_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('school_bank_accounts', function (Blueprint $table) {
            $table->dropColumn('online_payment_enabled');
        });
    }
};
