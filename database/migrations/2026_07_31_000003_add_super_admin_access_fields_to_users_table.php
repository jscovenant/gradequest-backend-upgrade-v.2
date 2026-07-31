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
            if (! Schema::hasColumn('users', 'super_admin_type')) {
                $table->string('super_admin_type')->nullable()->after('role');
            }

            if (! Schema::hasColumn('users', 'super_admin_permissions')) {
                $table->json('super_admin_permissions')->nullable()->after('super_admin_type');
            }
        });

        DB::table('users')
            ->whereRaw("LOWER(REPLACE(REPLACE(role, '-', ''), ' ', '')) = 'superadmin'")
            ->whereNull('super_admin_type')
            ->update(['super_admin_type' => 'owner']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'super_admin_permissions')) {
                $table->dropColumn('super_admin_permissions');
            }

            if (Schema::hasColumn('users', 'super_admin_type')) {
                $table->dropColumn('super_admin_type');
            }
        });
    }
};
