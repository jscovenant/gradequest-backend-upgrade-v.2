<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('school_settings') && !Schema::hasColumn('school_settings', 'online_payment_enabled')) {
            Schema::table('school_settings', function (Blueprint $table) {
                $table->boolean('online_payment_enabled')->default(true)->after('active_payment_gateway');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('school_settings') && Schema::hasColumn('school_settings', 'online_payment_enabled')) {
            Schema::table('school_settings', function (Blueprint $table) {
                $table->dropColumn('online_payment_enabled');
            });
        }
    }
};
