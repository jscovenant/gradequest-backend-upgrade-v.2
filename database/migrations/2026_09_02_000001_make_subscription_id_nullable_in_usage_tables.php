<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (Schema::hasTable('subscription_ai_usages')) {
            DB::statement("ALTER TABLE `subscription_ai_usages` MODIFY `subscription_id` BIGINT UNSIGNED NULL DEFAULT NULL");
        }

        if (Schema::hasTable('subscription_whatsapp_usages')) {
            DB::statement("ALTER TABLE `subscription_whatsapp_usages` MODIFY `subscription_id` BIGINT UNSIGNED NULL DEFAULT NULL");
        }
    }

    public function down(): void
    {
        // Safe no-op
    }
};
