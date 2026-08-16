<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['lesson_schemes', 'generated_lesson_plans', 'lesson_notes'] as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'archived_at')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->timestamp('archived_at')->nullable()->index()->after('status');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['lesson_schemes', 'generated_lesson_plans', 'lesson_notes'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'archived_at')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropColumn('archived_at');
                });
            }
        }
    }
};