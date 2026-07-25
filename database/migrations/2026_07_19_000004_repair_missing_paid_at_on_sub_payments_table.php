<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sub_payments') && ! Schema::hasColumn('sub_payments', 'paid_at')) {
            Schema::table('sub_payments', function (Blueprint $table) {
                $table->timestamp('paid_at')->nullable()->after('last4');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sub_payments') && Schema::hasColumn('sub_payments', 'paid_at')) {
            Schema::table('sub_payments', function (Blueprint $table) {
                $table->dropColumn('paid_at');
            });
        }
    }
};
