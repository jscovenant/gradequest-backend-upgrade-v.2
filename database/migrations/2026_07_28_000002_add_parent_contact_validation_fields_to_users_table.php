<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'phone_normalized')) {
                $table->string('phone_normalized')->nullable()->after('phone');
            }

            if (! Schema::hasColumn('users', 'phone_validated_at')) {
                $table->timestamp('phone_validated_at')->nullable()->after('phone_normalized');
            }

            if (! Schema::hasColumn('users', 'whatsapp_verification_code')) {
                $table->string('whatsapp_verification_code')->nullable()->after('whatsapp_verified_at');
            }

            if (! Schema::hasColumn('users', 'whatsapp_verification_expires_at')) {
                $table->timestamp('whatsapp_verification_expires_at')->nullable()->after('whatsapp_verification_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach ([
                'phone_normalized',
                'phone_validated_at',
                'whatsapp_verification_code',
                'whatsapp_verification_expires_at',
            ] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
