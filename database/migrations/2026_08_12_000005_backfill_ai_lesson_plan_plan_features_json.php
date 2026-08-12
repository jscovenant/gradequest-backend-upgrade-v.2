<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subscription_plans')) {
            return;
        }

        DB::table('subscription_plans')->orderBy('id')->get()->each(function ($plan) {
            $features = $plan->features;
            for ($i = 0; $i < 3 && is_string($features); $i++) {
                $decoded = json_decode($features, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    break;
                }
                $features = $decoded;
            }

            $features = is_array($features) ? $features : [];
            $keys = collect($features)
                ->map(fn ($feature) => strtolower((string) ($feature['feature_key'] ?? '')))
                ->all();

            $isAiOrPlusPlan = str_contains(strtolower((string) $plan->name), 'plus')
                || in_array('ai_cbt_question_generator', $keys, true)
                || in_array('support_ai_cbt_question_generator', $keys, true)
                || in_array('ai_result_comment_generator', $keys, true)
                || in_array('support_ai_result_comment_generator', $keys, true);

            if (! $isAiOrPlusPlan || in_array('ai_lesson_plan_generator', $keys, true)) {
                return;
            }

            $features[] = [
                'feature_name' => 'AI Lesson Plan Generator',
                'feature_key' => 'ai_lesson_plan_generator',
                'is_enabled' => true,
            ];
            $features[] = [
                'feature_name' => 'Support AI Lesson Plan Generator',
                'feature_key' => 'support_ai_lesson_plan_generator',
                'is_enabled' => true,
            ];

            DB::table('subscription_plans')->where('id', $plan->id)->update([
                'features' => json_encode($features),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('subscription_plans')) {
            return;
        }

        DB::table('subscription_plans')->orderBy('id')->get()->each(function ($plan) {
            $features = $plan->features;
            for ($i = 0; $i < 3 && is_string($features); $i++) {
                $decoded = json_decode($features, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    break;
                }
                $features = $decoded;
            }

            if (! is_array($features)) {
                return;
            }

            $features = collect($features)
                ->reject(fn ($feature) => in_array(strtolower((string) ($feature['feature_key'] ?? '')), [
                    'ai_lesson_plan_generator',
                    'support_ai_lesson_plan_generator',
                ], true))
                ->values()
                ->all();

            DB::table('subscription_plans')->where('id', $plan->id)->update([
                'features' => json_encode($features),
                'updated_at' => now(),
            ]);
        });
    }
};
