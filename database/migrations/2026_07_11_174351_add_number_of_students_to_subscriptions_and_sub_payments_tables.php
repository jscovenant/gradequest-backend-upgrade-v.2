<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('subscriptions') && !Schema::hasColumn('subscriptions', 'number_of_students')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->unsignedInteger('number_of_students')->nullable()->after('subscription_plan_id');
            });
        }

        if (Schema::hasTable('sub_payments') && !Schema::hasColumn('sub_payments', 'number_of_students')) {
            Schema::table('sub_payments', function (Blueprint $table) {
                $table->unsignedInteger('number_of_students')->nullable()->after('subscription_plan_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('subscriptions') && Schema::hasColumn('subscriptions', 'number_of_students')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->dropColumn('number_of_students');
            });
        }

        if (Schema::hasTable('sub_payments') && Schema::hasColumn('sub_payments', 'number_of_students')) {
            Schema::table('sub_payments', function (Blueprint $table) {
                $table->dropColumn('number_of_students');
            });
        }
    }
};