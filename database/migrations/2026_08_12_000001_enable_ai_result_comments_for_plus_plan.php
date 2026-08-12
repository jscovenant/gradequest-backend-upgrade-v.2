<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $featureKeys = [
        'ai_result_comment_generator',
        'support_ai_result_comment_generator',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('subscription_plans')) {
            return;
        }

        $features = [
            [
                'feature_name' => 'AI Result Comment Generator',
                'feature_key' => 'ai_result_comment_generator',
                'is_enabled' => true,
                'limit_type' => 'usage',
                'limit_count' => 0,
            ],
            [
                'feature_name' => 'AI Result Comment Generator',
                'feature_key' => 'support_ai_result_comment_generator',
                'is_enabled' => true,
                'limit_type' => 'usage',
                'limit_count' => 0,
            ],
        ];

        $plans = DB::table('subscription_plans')
            ->where(function ($query) {
                $query->where('name', 'like', '%Legacy Plus%')
                    ->orWhere('name', 'like', '%GradeQuest Plus%');
            })
            ->get();

        foreach ($plans as $plan) {
            $existing = json_decode($plan->features ?? '[]', true);
            $existing = is_array($existing) ? $existing : [];
            $keys = collect($existing)->pluck('feature_key')->filter()->all();

            foreach ($features as $feature) {
                if (! in_array($feature['feature_key'], $keys, true)) {
                    $existing[] = $feature;
                }
            }

            DB::table('subscription_plans')->where('id', $plan->id)->update([
                'features' => json_encode(array_values($existing)),
                'updated_at' => now(),
            ]);

            if (Schema::hasTable('subscription_plan_features')) {
                foreach ($features as $feature) {
                    DB::table('subscription_plan_features')->updateOrInsert(
                        [
                            'subscription_plan_id' => $plan->id,
                            'feature_key' => $feature['feature_key'],
                        ],
                        [
                            'feature_name' => $feature['feature_name'],
                            'is_enabled' => true,
                            'limit_type' => $feature['limit_type'],
                            'limit_count' => $feature['limit_count'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('subscription_plan_features')) {
            DB::table('subscription_plan_features')->whereIn('feature_key', $this->featureKeys)->delete();
        }

        if (! Schema::hasTable('subscription_plans')) {
            return;
        }

        DB::table('subscription_plans')
            ->where(function ($query) {
                $query->where('name', 'like', '%Legacy Plus%')
                    ->orWhere('name', 'like', '%GradeQuest Plus%');
            })
            ->get()
            ->each(function ($plan) {
                $features = json_decode($plan->features ?? '[]', true);
                if (! is_array($features)) {
                    return;
                }

                $features = array_values(array_filter(
                    $features,
                    fn ($feature) => ! in_array($feature['feature_key'] ?? null, $this->featureKeys, true)
                ));

                DB::table('subscription_plans')->where('id', $plan->id)->update([
                    'features' => json_encode($features),
                    'updated_at' => now(),
                ]);
            });
    }
};
