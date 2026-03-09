<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payment_gateways', function (Blueprint $table) {
            // Core gateway identity
            $table->string('provider', 50)->nullable()->after('school_id'); 
            // examples: 'paystack', 'flutterwave', 'stripe', 'manual_bank'

            $table->enum('mode', ['test', 'live'])->default('test')->after('provider');

            // Credentials (some gateways only need some of these)
            $table->string('public_key', 255)->nullable()->after('mode');
            $table->string('secret_key', 255)->nullable()->after('public_key');
            $table->string('webhook_secret', 255)->nullable()->after('secret_key');
            $table->string('merchant_email', 255)->nullable()->after('webhook_secret');

            // Capabilities / behavior
            $table->json('channels')->nullable()->after('merchant_email');
            // e.g. ["card","bank_transfer","ussd"]

            $table->string('currency', 10)->default('NGN')->after('channels');
            $table->string('country', 2)->default('NG')->after('currency');

            $table->boolean('is_default')->default(false)->after('is_active');

            // Flexible config storage (provider-specific)
            $table->json('config')->nullable()->after('is_default');
            // e.g. { "paystack_subaccount_code": "...", "split_code": "...", "callback_url": "..." }

            // Tracking / observability
            $table->timestamp('last_verified_at')->nullable()->after('config');
            $table->string('last_error', 255)->nullable()->after('last_verified_at');
        });

        // Helpful index/uniques
        Schema::table('payment_gateways', function (Blueprint $table) {
            $table->index(['school_id', 'provider', 'mode'], 'pg_school_provider_mode_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payment_gateways', function (Blueprint $table) {
            $table->dropIndex('pg_school_provider_mode_idx');

            $table->dropColumn([
                'provider',
                'mode',
                'public_key',
                'secret_key',
                'webhook_secret',
                'merchant_email',
                'channels',
                'currency',
                'country',
                'is_default',
                'config',
                'last_verified_at',
                'last_error',
            ]);
        });
    }
};