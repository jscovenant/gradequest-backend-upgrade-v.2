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
    Schema::table('subscription_plan_features', function (Blueprint $table) {
        $table->string('limit_type')->nullable()->after('feature_name');
        $table->integer('limit_count')->default(0)->after('limit_type');
    });
}

public function down(): void
{
    Schema::table('subscription_plan_features', function (Blueprint $table) {
        $table->dropColumn(['limit_type', 'limit_count']);
    });
}

};
