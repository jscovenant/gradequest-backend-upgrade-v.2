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
        Schema::table('qr_codes', function (Blueprint $table) {
            // Add the column
    if (!Schema::hasColumn('qr_codes', 'biometric_id')) {
        $table->unsignedBigInteger('biometric_id')->nullable()->after('id');
    }
});

// Add foreign key in a separate step
Schema::table('qr_codes', function (Blueprint $table) {
    $table->foreign('biometric_id')
          ->references('id')
          ->on('biometric_ids')
          ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('qr_codes', function (Blueprint $table) {
            //
        });
    }
};
