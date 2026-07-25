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
            if (! Schema::hasColumn('school_settings', 'whatsapp_monthly_limit')) {
                $table->integer('whatsapp_monthly_limit')->default(0);
            }

            if (! Schema::hasColumn('school_settings', 'whatsapp_messages_sent')) {
                $table->integer('whatsapp_messages_sent')->default(0);
            }

            if (! Schema::hasColumn('school_settings', 'whatsapp_usage_reset_date')) {
                $table->date('whatsapp_usage_reset_date')->nullable();
            }

            if (! Schema::hasColumn('school_settings', 'whatsapp_enabled')) {
                $table->boolean('whatsapp_enabled')->default(false);
            }
        });


        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'whatsapp_number')) {
                $table->string('whatsapp_number')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('school_settings', function (Blueprint $table) {
            $columns = [
                'whatsapp_monthly_limit',
                'whatsapp_messages_sent',
                'whatsapp_usage_reset_date',
                'whatsapp_enabled',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('school_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'whatsapp_number')) {
                $table->dropColumn('whatsapp_number');
            }
        });
    }
};
