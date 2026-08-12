<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_domains', function (Blueprint $table) {
            $table->timestamp('ownership_verified_at')->nullable()->after('verified_at');
            $table->timestamp('routing_verified_at')->nullable()->after('ownership_verified_at');
            $table->timestamp('activated_at')->nullable()->after('routing_verified_at');
            $table->timestamp('last_checked_at')->nullable()->after('activated_at');
            $table->text('last_error')->nullable()->after('last_checked_at');
            $table->index(['status', 'last_checked_at'], 'school_domains_health_idx');
        });
    }

    public function down(): void
    {
        Schema::table('school_domains', function (Blueprint $table) {
            $table->dropIndex('school_domains_health_idx');
            $table->dropColumn([
                'ownership_verified_at',
                'routing_verified_at',
                'activated_at',
                'last_checked_at',
                'last_error',
            ]);
        });
    }
};
