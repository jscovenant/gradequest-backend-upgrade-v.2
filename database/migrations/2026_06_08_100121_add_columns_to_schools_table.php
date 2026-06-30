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
            $table->integer('whatsapp_monthly_limit')->default(0);
    // 0 = no access, -1 = unlimited, 200 = 200 msgs/month
    $table->integer('whatsapp_messages_sent')->default(0);
    $table->date('whatsapp_usage_reset_date')->nullable();
            $table->boolean('whatsapp_enabled')->default(false);
        });


        // Also ensure parents table has whatsapp number
            Schema::table('users', function (Blueprint $table) {
                $table->string('whatsapp_number')->nullable();
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            //
        });
    }
};
