<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        foreach (['Super-Admin', 'Platform-Staff'] as $role) {
            $exists = DB::table('roles')
                ->where('name', $role)
                ->where('guard_name', 'web')
                ->exists();

            if (! $exists) {
                DB::table('roles')->insert([
                    'name' => $role,
                    'guard_name' => 'web',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        //
    }
};
