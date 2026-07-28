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
        if (! Schema::hasTable('school_settings')) {
            return;
        }

        Schema::table('school_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('school_settings', 'primary_color')) {
                $table->string('primary_color')->default('#0d47a1')->after('website');
            }
            if (! Schema::hasColumn('school_settings', 'secondary_color')) {
                $table->string('secondary_color')->default('#1976d2')->after('primary_color');
            }
            if (! Schema::hasColumn('school_settings', 'background_color')) {
                $table->string('background_color')->default('#e3f2fd')->after('secondary_color');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('school_settings')) {
            return;
        }

        Schema::table('school_settings', function (Blueprint $table) {
            $columns = array_values(array_filter(
                ['primary_color', 'secondary_color', 'background_color'],
                fn ($column) => Schema::hasColumn('school_settings', $column)
            ));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
