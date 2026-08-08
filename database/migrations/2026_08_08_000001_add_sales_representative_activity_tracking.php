<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->index()->after('status');
            }
        });

        Schema::table('sales_representatives', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_representatives', 'reactivated_at')) {
                $table->timestamp('reactivated_at')->nullable()->after('status_changed_at');
            }

            if (! Schema::hasColumn('sales_representatives', 'auto_disabled_at')) {
                $table->timestamp('auto_disabled_at')->nullable()->after('reactivated_at');
            }
        });

        // Historical logins were not tracked before this release. Start the
        // inactivity clock at deployment to avoid falsely disabling active reps.
        DB::table('users')
            ->whereNull('last_login_at')
            ->whereIn('id', DB::table('sales_representatives')->select('user_id'))
            ->update(['last_login_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('sales_representatives', function (Blueprint $table) {
            foreach (['auto_disabled_at', 'reactivated_at'] as $column) {
                if (Schema::hasColumn('sales_representatives', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'last_login_at')) {
                $table->dropColumn('last_login_at');
            }
        });
    }
};
