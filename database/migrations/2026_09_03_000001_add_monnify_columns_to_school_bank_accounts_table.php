<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_bank_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('school_bank_accounts', 'monnify_subaccount_code')) {
                $table->string('monnify_subaccount_code')->nullable()->after('paystack_subaccount_code');
            }
            if (! Schema::hasColumn('school_bank_accounts', 'preferred_gateway')) {
                $table->string('preferred_gateway', 30)->default('monnify')->after('monnify_subaccount_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('school_bank_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('school_bank_accounts', 'monnify_subaccount_code')) {
                $table->dropColumn('monnify_subaccount_code');
            }
            if (Schema::hasColumn('school_bank_accounts', 'preferred_gateway')) {
                $table->dropColumn('preferred_gateway');
            }
        });
    }
};
