<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->unsignedInteger('whatsapp_monthly_credits')
                ->default(0)
                ->after('max_students');

            $table->boolean('whatsapp_enabled')
                ->default(false)
                ->after('whatsapp_monthly_credits');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn([
                'whatsapp_monthly_credits',
                'whatsapp_enabled',
            ]);
        });
    }
};