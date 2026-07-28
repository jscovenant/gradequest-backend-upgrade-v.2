<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sections') && !Schema::hasColumn('sections', 'archived_at')) {
            Schema::table('sections', function (Blueprint $table) {
                $table->timestamp('archived_at')->nullable()->index()->after('updated_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sections') && Schema::hasColumn('sections', 'archived_at')) {
            Schema::table('sections', function (Blueprint $table) {
                $table->dropColumn('archived_at');
            });
        }
    }
};
