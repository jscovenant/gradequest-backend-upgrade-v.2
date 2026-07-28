<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['subjects', 'terms', 'academic_sessions'] as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'archived_at')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->timestamp('archived_at')->nullable()->index()->after('updated_at');
            });
        }
    }

    public function down(): void
    {
        foreach (['subjects', 'terms', 'academic_sessions'] as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'archived_at')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('archived_at');
            });
        }
    }
};
