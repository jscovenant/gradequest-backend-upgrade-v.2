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
        if (Schema::hasTable('school_settings')) {
            Schema::table('school_settings', function (Blueprint $table) {
                if (! Schema::hasColumn('school_settings', 'bank_charge_bearer')) {
                    $table->string('bank_charge_bearer', 20)->default('parent');
                }
                if (! Schema::hasColumn('school_settings', 'bank_charge_amount')) {
                    $table->decimal('bank_charge_amount', 12, 2)->default(200.00);
                }
                if (! Schema::hasColumn('school_settings', 'platform_fee_bearer')) {
                    $table->string('platform_fee_bearer', 20)->default('school');
                }
                if (! Schema::hasColumn('school_settings', 'active_payment_gateway')) {
                    $table->string('active_payment_gateway', 30)->default('wema_alat');
                }
                if (! Schema::hasColumn('school_settings', 'wema_account_number')) {
                    $table->string('wema_account_number', 30)->nullable();
                }
                if (! Schema::hasColumn('school_settings', 'wema_account_name')) {
                    $table->string('wema_account_name', 120)->nullable();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('school_settings')) {
            Schema::table('school_settings', function (Blueprint $table) {
                $columns = [
                    'bank_charge_bearer',
                    'bank_charge_amount',
                    'platform_fee_bearer',
                    'active_payment_gateway',
                    'wema_account_number',
                    'wema_account_name',
                ];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('school_settings', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
