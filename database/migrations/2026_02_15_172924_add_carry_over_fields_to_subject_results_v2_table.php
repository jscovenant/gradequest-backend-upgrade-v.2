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
        Schema::table('subject_results_v2', function (Blueprint $table) {
            $table->boolean('carry_over_enabled')->default(false)->after('carry_over_json');
        $table->decimal('cumulative_total', 8, 2)->nullable()->after('carry_over_enabled');
        $table->decimal('cumulative_average', 8, 2)->nullable()->after('cumulative_total');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subject_results_v2', function (Blueprint $table) {
            //
        });
    }
};
