<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subscription_plan_features')) {
            Schema::create('subscription_plan_features', function (Blueprint $table) {
                $table->id();
                $table->foreignId('subscription_plan_id')
                    ->constrained('subscription_plans')
                    ->cascadeOnDelete();
                $table->string('feature_key', 100);
                $table->string('feature_name');
                $table->string('limit_type')->nullable();
                $table->integer('limit_count')->default(0);
                $table->boolean('is_enabled')->default(true);
                $table->timestamps();

                $table->unique(['subscription_plan_id', 'feature_key'], 'plan_feature_unique');
            });
        }
    }

    public function down(): void
    {
        // This is a repair migration. Do not drop the table on rollback because
        // older migrations also own this schema when the database is healthy.
    }
};
