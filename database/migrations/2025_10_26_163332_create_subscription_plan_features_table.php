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
        Schema::create('subscription_plan_features', function (Blueprint $table) {
          $table->id();
            $table->foreignId('subscription_plan_id')
                ->constrained('subscription_plans')
                ->onDelete('cascade');
            $table->string('feature_key', 100);
            $table->string('feature_name');
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            // ✅ shorter unique index name
            $table->unique(['subscription_plan_id', 'feature_key'], 'plan_feature_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_plan_features');
    }
};
