<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            if (!Schema::hasColumn('subscription_plans', 'duration_in_days')) {
                $table->integer('duration_in_days')->default(30);
            }

            if (!Schema::hasColumn('subscription_plans', 'max_teachers')) {
                $table->integer('max_teachers')->nullable();
            }

            if (!Schema::hasColumn('subscription_plans', 'max_students')) {
                $table->integer('max_students')->nullable();
            }

            if (!Schema::hasColumn('subscription_plans', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }

            if (!Schema::hasColumn('subscription_plans', 'description')) {
                $table->text('description')->nullable();
            }

            if (!Schema::hasColumn('subscription_plans', 'currency')) {
                $table->string('currency', 10)->default('NGN');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn([
                'duration_in_days',
                'max_teachers',
                'max_students',
                'is_active',
                'description',
                'currency'
            ]);
        });
    }
};
