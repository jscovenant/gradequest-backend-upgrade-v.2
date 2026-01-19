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
        Schema::table('school_settings', function (Blueprint $table) {
             $table->string('primary_color')->default('#0d47a1')->after('website');  
            $table->string('secondary_color')->default('#1976d2')->after('primary_color');
            $table->string('background_color')->default('#e3f2fd')->after('secondary_color');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('school_settings', function (Blueprint $table) {
            $table->dropColumn(['primary_color', 'secondary_color', 'background_color']);
        });
    }
};
