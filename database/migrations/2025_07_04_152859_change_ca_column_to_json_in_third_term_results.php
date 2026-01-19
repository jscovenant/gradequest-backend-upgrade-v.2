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
        Schema::table('third_term_results', function (Blueprint $table) {
            Schema::table('third_term_results', function (Blueprint $table) {
                $table->json('ca')->change();
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('third_term_results', function (Blueprint $table) {
            //
        });
    }
};
