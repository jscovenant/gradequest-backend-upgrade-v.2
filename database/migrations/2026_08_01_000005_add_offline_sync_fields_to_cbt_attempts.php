<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cbt_attempts')) {
            return;
        }

        Schema::table('cbt_attempts', function (Blueprint $table) {
            if (! Schema::hasColumn('cbt_attempts', 'offline_attempt_uuid')) {
                $table->string('offline_attempt_uuid', 80)->nullable()->after('public_access_token');
            }

            if (! Schema::hasColumn('cbt_attempts', 'offline_license_id')) {
                $table->foreignId('offline_license_id')->nullable()->after('offline_attempt_uuid')->constrained('cbt_offline_licenses')->nullOnDelete();
            }

            if (! Schema::hasColumn('cbt_attempts', 'synced_at')) {
                $table->timestamp('synced_at')->nullable()->after('submitted_at');
            }
        });

        Schema::table('cbt_attempts', function (Blueprint $table) {
            $table->unique(['school_id', 'offline_attempt_uuid'], 'cbt_attempt_offline_uuid_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cbt_attempts')) {
            return;
        }

        Schema::table('cbt_attempts', function (Blueprint $table) {
            $table->dropUnique('cbt_attempt_offline_uuid_unique');

            if (Schema::hasColumn('cbt_attempts', 'offline_license_id')) {
                $table->dropConstrainedForeignId('offline_license_id');
            }

            foreach (['synced_at', 'offline_attempt_uuid'] as $column) {
                if (Schema::hasColumn('cbt_attempts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
