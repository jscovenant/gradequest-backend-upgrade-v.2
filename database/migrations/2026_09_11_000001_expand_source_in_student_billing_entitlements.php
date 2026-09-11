<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('student_billing_entitlements')) {
            DB::statement("ALTER TABLE `student_billing_entitlements` MODIFY COLUMN `source` VARCHAR(64) NOT NULL DEFAULT 'system'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('student_billing_entitlements')) {
            DB::statement("ALTER TABLE `student_billing_entitlements` MODIFY COLUMN `source` ENUM('online_fee','offline_invoice','subscription_payment','manual_waiver','admin_override','wallet','system') NOT NULL DEFAULT 'system'");
        }
    }
};
