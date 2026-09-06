<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE `student_billing_entitlements` MODIFY COLUMN `source` ENUM('online_fee','offline_invoice','subscription_payment','manual_waiver','admin_override','wallet','system') NOT NULL DEFAULT 'system'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE `student_billing_entitlements` MODIFY COLUMN `source` ENUM('online_fee','offline_invoice','subscription_payment','manual_waiver','admin_override','system') NOT NULL DEFAULT 'system'");
    }
};
