<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'force_password_change')) {
                $table->boolean('force_password_change')->default(false)->after('default_password');
            }

            if (! Schema::hasColumn('users', 'password_changed_at')) {
                $table->timestamp('password_changed_at')->nullable()->after('force_password_change');
            }
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('wallet_transactions', 'remaining_amount')) {
                $table->decimal('remaining_amount', 12, 2)->nullable()->after('amount');
            }

            if (! Schema::hasColumn('wallet_transactions', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('reference_id');
            }

            if (! Schema::hasColumn('wallet_transactions', 'expired_at')) {
                $table->timestamp('expired_at')->nullable()->after('expires_at');
            }

            if (! Schema::hasColumn('wallet_transactions', 'metadata')) {
                $table->json('metadata')->nullable()->after('expired_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            foreach (['metadata', 'expired_at', 'expires_at', 'remaining_amount'] as $column) {
                if (Schema::hasColumn('wallet_transactions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('users', function (Blueprint $table) {
            foreach (['password_changed_at', 'force_password_change'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
