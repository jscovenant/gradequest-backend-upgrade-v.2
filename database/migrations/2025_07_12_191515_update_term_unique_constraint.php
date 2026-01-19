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
        Schema::table('terms', function (Blueprint $table) {
            // Drop the old unique index on name if it exists
            $table->dropUnique(['name']); // If 'name' has a unique constraint

            // Add unique index on name + school_id
            $table->unique(['name', 'school_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('terms', function (Blueprint $table) {
            $table->dropUnique(['name', 'school_id']);
            $table->unique('name');
        });
    }
};
