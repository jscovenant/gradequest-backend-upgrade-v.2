<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('subscription_plans', 'whatsapp_monthly_credits')) {
            return;
        }

        DB::table('subscription_plans')
            ->where(function ($query) {
                $query->whereRaw('LOWER(name) LIKE ?', ['%legacy plus%'])
                    ->orWhereRaw('LOWER(name) LIKE ?', ['%gradequestplus%']);
            })
            ->update([
                'whatsapp_enabled' => true,
                'whatsapp_monthly_credits' => 500,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Do not remove credits from schools if this deployment is rolled back.
    }
};
