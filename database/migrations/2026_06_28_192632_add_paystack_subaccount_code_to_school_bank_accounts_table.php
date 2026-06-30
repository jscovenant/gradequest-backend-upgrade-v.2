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
            //
              $table->string('paystack_subaccount_code')
                ->nullable()
                ->after('account_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('school_bank_accounts', function (Blueprint $table) {
            //
        });
    }
};
