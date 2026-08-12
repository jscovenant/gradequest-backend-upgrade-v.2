<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gradequest_billing_policies')) {
            Schema::table('gradequest_billing_policies', function (Blueprint $table) {
                if (! Schema::hasColumn('gradequest_billing_policies', 'ai_fee_collection_credit_cost')) {
                    $table->unsignedInteger('ai_fee_collection_credit_cost')->default(2)->after('ai_lesson_plan_credit_cost');
                }
            });
        }

        if (Schema::hasTable('subscription_plan_features')) {
            DB::table('subscription_plan_features')->whereIn('feature_key', [
                'ai_result_comment_generator',
                'support_ai_result_comment_generator',
                'ai_cbt_question_generator',
                'support_ai_cbt_question_generator',
                'ai_lesson_plan_generator',
                'support_ai_lesson_plan_generator',
            ])->get(['subscription_plan_id'])->unique('subscription_plan_id')->each(function ($row) {
                foreach ([
                    ['feature_name' => 'AI Fee Collection Assistant', 'feature_key' => 'ai_fee_collection_assistant'],
                    ['feature_name' => 'Support AI Fee Collection Assistant', 'feature_key' => 'support_ai_fee_collection_assistant'],
                ] as $feature) {
                    DB::table('subscription_plan_features')->updateOrInsert(
                        [
                            'subscription_plan_id' => $row->subscription_plan_id,
                            'feature_key' => $feature['feature_key'],
                        ],
                        [
                            'feature_name' => $feature['feature_name'],
                            'is_enabled' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }
            });
        }

        if (Schema::hasTable('subscription_plans') && Schema::hasColumn('subscription_plans', 'features')) {
            DB::table('subscription_plans')->orderBy('id')->get(['id', 'name', 'features'])->each(function ($plan) {
                $features = $plan->features;
                for ($i = 0; $i < 3 && is_string($features); $i++) {
                    $decoded = json_decode($features, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $decoded = [];
                    }
                    $features = $decoded;
                }
                $features = is_array($features) ? array_values($features) : [];
                $keys = collect($features)->map(fn ($feature) => strtolower((string) ($feature['feature_key'] ?? '')))->filter()->values();
                $isAiOrPlusPlan = str_contains(strtolower((string) $plan->name), 'plus')
                    || $keys->contains('ai_result_comment_generator')
                    || $keys->contains('ai_cbt_question_generator')
                    || $keys->contains('ai_lesson_plan_generator')
                    || $keys->contains('gradequest_plus');

                if (! $isAiOrPlusPlan) {
                    return;
                }

                foreach ([
                    ['feature_name' => 'AI Fee Collection Assistant', 'feature_key' => 'ai_fee_collection_assistant', 'is_enabled' => true],
                    ['feature_name' => 'Support AI Fee Collection Assistant', 'feature_key' => 'support_ai_fee_collection_assistant', 'is_enabled' => true],
                ] as $feature) {
                    if (! $keys->contains($feature['feature_key'])) {
                        $features[] = $feature;
                        $keys->push($feature['feature_key']);
                    }
                }

                DB::table('subscription_plans')->where('id', $plan->id)->update([
                    'features' => json_encode($features),
                    'updated_at' => now(),
                ]);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('subscription_plan_features')) {
            DB::table('subscription_plan_features')->whereIn('feature_key', [
                'ai_fee_collection_assistant',
                'support_ai_fee_collection_assistant',
            ])->delete();
        }

        if (Schema::hasTable('gradequest_billing_policies') && Schema::hasColumn('gradequest_billing_policies', 'ai_fee_collection_credit_cost')) {
            Schema::table('gradequest_billing_policies', function (Blueprint $table) {
                $table->dropColumn('ai_fee_collection_credit_cost');
            });
        }
    }
};