<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereRaw("LOWER(REPLACE(REPLACE(role, '-', ''), ' ', '')) = 'superadmin'")
            ->whereNotNull('super_admin_type')
            ->where('super_admin_type', '!=', 'owner')
            ->update(['role' => 'Platform-Staff']);

        $ownerIds = DB::table('users')
            ->whereRaw("LOWER(REPLACE(REPLACE(role, '-', ''), ' ', '')) = 'superadmin'")
            ->orderBy('id')
            ->pluck('id');

        if ($ownerIds->count() > 1) {
            $keepOwnerId = $ownerIds->first();

            DB::table('users')
                ->whereIn('id', $ownerIds->skip(1)->values()->all())
                ->update([
                    'role' => 'Platform-Staff',
                    'super_admin_type' => 'operations',
                ]);

            DB::table('users')
                ->where('id', $keepOwnerId)
                ->update(['super_admin_type' => 'owner']);
        }
    }

    public function down(): void
    {
        DB::table('users')
            ->where('role', 'Platform-Staff')
            ->whereNotNull('super_admin_type')
            ->update(['role' => 'Super-Admin']);
    }
};
