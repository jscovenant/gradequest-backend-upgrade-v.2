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
        Schema::table('gradequest_billing_policies', function (Blueprint $table) {
            $table->boolean('promo_enabled')->default(false)->after('allowed_blocked_actions');
            $table->string('promo_title')->default('Buy 1 Year, Get +1 Year Free Promo')->after('promo_enabled');
            $table->text('promo_description')->nullable()->after('promo_title');
            $table->string('promo_target_plan')->default('GradeQuest Plus')->after('promo_description');
            $table->unsignedInteger('promo_min_students')->default(100)->after('promo_target_plan');
            $table->unsignedInteger('promo_bonus_days')->default(365)->after('promo_min_students');
            $table->dateTime('promo_starts_at')->nullable()->after('promo_bonus_days');
            $table->dateTime('promo_ends_at')->nullable()->after('promo_starts_at');
            $table->unsignedInteger('promo_max_claims')->nullable()->after('promo_ends_at');
            $table->unsignedInteger('promo_claims_count')->default(0)->after('promo_max_claims');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gradequest_billing_policies', function (Blueprint $table) {
            $table->dropColumn([
                'promo_enabled',
                'promo_title',
                'promo_description',
                'promo_target_plan',
                'promo_min_students',
                'promo_bonus_days',
                'promo_starts_at',
                'promo_ends_at',
                'promo_max_claims',
                'promo_claims_count',
            ]);
        });
    }
};
