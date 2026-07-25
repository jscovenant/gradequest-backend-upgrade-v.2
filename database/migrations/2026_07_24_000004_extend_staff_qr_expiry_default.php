<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('attendance_settings', 'qr_expires_seconds')) {
            return;
        }

        DB::table('attendance_settings')
            ->where(function ($query) {
                $query->whereNull('qr_expires_seconds')
                    ->orWhere('qr_expires_seconds', '<', 300);
            })
            ->update(['qr_expires_seconds' => 300]);
    }

    public function down(): void
    {
        //
    }
};
