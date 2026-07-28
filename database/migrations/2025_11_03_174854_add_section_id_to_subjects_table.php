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
        if (! Schema::hasTable('subjects') || Schema::hasColumn('subjects', 'section_id')) {
            return;
        }

        Schema::table('subjects', function (Blueprint $table) {
            $column = $table->unsignedBigInteger('section_id')->nullable();

            if (Schema::hasColumn('subjects', 'department_id')) {
                $column->after('department_id');
            }

            $table->foreign('section_id')->references('id')->on('sections')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('subjects') || ! Schema::hasColumn('subjects', 'section_id')) {
            return;
        }

        Schema::table('subjects', function (Blueprint $table) {
            $table->dropForeign(['section_id']);
            $table->dropColumn('section_id');
        });
    }
};
