<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('school_bank_accounts') && !Schema::hasColumn('school_bank_accounts', 'paystack_recipient_code')) {
            Schema::table('school_bank_accounts', function (Blueprint $table) {
                $table->string('paystack_recipient_code')->nullable()->after('paystack_subaccount_code');
            });
        }

        if (Schema::hasTable('payments') && !Schema::hasColumn('payments', 'meta')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->json('meta')->nullable()->after('paystack_response');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('school_bank_accounts') && Schema::hasColumn('school_bank_accounts', 'paystack_recipient_code')) {
            Schema::table('school_bank_accounts', function (Blueprint $table) {
                $table->dropColumn('paystack_recipient_code');
            });
        }

        if (Schema::hasTable('payments') && Schema::hasColumn('payments', 'meta')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropColumn('meta');
            });
        }
    }
};
