<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('student_billing_entitlements')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE student_billing_entitlements MODIFY source ENUM('online_fee', 'offline_invoice', 'subscription_payment', 'manual_waiver', 'admin_override', 'system') NOT NULL DEFAULT 'system'");
    }

    public function down(): void
    {
        if (! Schema::hasTable('student_billing_entitlements')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("UPDATE student_billing_entitlements SET source = 'online_fee' WHERE source = 'subscription_payment'");
        DB::statement("ALTER TABLE student_billing_entitlements MODIFY source ENUM('online_fee', 'offline_invoice', 'manual_waiver', 'admin_override', 'system') NOT NULL DEFAULT 'system'");
    }
};
