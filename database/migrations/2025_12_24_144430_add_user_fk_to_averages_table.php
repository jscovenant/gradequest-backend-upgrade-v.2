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
        if (! Schema::hasTable('averages')) {
            return;
        }

        Schema::table('averages', function (Blueprint $table) {
            if (! Schema::hasColumn('averages', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('id');
            } else {
                $table->unsignedBigInteger('user_id')->nullable()->change();
            }

            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('averages') || ! Schema::hasColumn('averages', 'user_id')) {
            return;
        }

        Schema::table('averages', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
