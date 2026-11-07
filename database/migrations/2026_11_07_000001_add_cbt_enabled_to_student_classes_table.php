<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('student_classes')) {
            Schema::table('student_classes', function (Blueprint $table) {
                if (! Schema::hasColumn('student_classes', 'cbt_enabled')) {
                    $table->boolean('cbt_enabled')->default(true)->after('description');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('student_classes')) {
            Schema::table('student_classes', function (Blueprint $table) {
                if (Schema::hasColumn('student_classes', 'cbt_enabled')) {
                    $table->dropColumn('cbt_enabled');
                }
            });
        }
    }
};
