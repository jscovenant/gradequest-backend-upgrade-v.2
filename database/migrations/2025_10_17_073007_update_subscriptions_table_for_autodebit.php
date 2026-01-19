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
        Schema::table('subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('subscriptions', 'plan_name')) {
        $table->string('plan_name')->nullable()->after('subscription_plan_id');
    }
    if (!Schema::hasColumn('subscriptions', 'plan_code')) {
        $table->string('plan_code')->nullable()->after('plan_name');
    }
    if (!Schema::hasColumn('subscriptions', 'reference')) {
        $table->string('reference')->nullable()->after('plan_code');
    }
    if (!Schema::hasColumn('subscriptions', 'authorization_code')) {
        $table->string('authorization_code')->nullable()->after('reference');
    }
    if (!Schema::hasColumn('subscriptions', 'email')) {
        $table->string('email')->nullable()->after('authorization_code');
    }
    if (!Schema::hasColumn('subscriptions', 'amount')) {
        $table->decimal('amount', 10, 2)->nullable()->after('email');
    }
    if (!Schema::hasColumn('subscriptions', 'currency')) {
        $table->string('currency', 10)->default('NGN')->after('amount');
    }
    if (!Schema::hasColumn('subscriptions', 'auto_renew')) {
        $table->boolean('auto_renew')->default(false)->after('currency');
    }
    if (!Schema::hasColumn('subscriptions', 'status')) {
        $table->string('status')->default('inactive')->after('auto_renew');
    }
    if (!Schema::hasColumn('subscriptions', 'start_date')) {
        $table->dateTime('start_date')->nullable()->after('status');
    }
    if (!Schema::hasColumn('subscriptions', 'end_date')) {
        $table->dateTime('end_date')->nullable()->after('start_date');
    }
    if (!Schema::hasColumn('subscriptions', 'next_billing_date')) {
        $table->dateTime('next_billing_date')->nullable()->after('end_date');
    }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
          Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn([
                'plan_name',
                'plan_code',
                'reference',
                'authorization_code',
                'email',
                'amount',
                'currency',
                'auto_renew',
                'status',
                'start_date',
                'end_date',
                'next_billing_date',
            ]);
        });
    }
};
