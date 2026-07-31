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

        $plans = DB::table('subscription_plans')
            ->where(function ($query) {
                $query->where('name', 'like', '%Legacy Plus%')
                    ->orWhere('name', 'like', '%GradeQuestPlus%');
            })
            ->get();

        foreach ($plans as $plan) {
            $features = $this->decodeFeatures($plan->features ?? []);
            $features = $this->upsertFeature($features, [
                'feature_name' => 'Online CBT',
                'feature_key' => 'cbt_online',
                'is_enabled' => true,
            ]);

            DB::table('subscription_plans')
                ->where('id', $plan->id)
                ->update([
                    'features' => json_encode($features),
                    'updated_at' => now(),
                ]);

            if (Schema::hasTable('subscription_plan_features')) {
                DB::table('subscription_plan_features')->updateOrInsert(
                    [
                        'subscription_plan_id' => $plan->id,
                        'feature_key' => 'cbt_online',
                    ],
                    [
                        'feature_name' => 'Online CBT',
                        'is_enabled' => true,
                        'limit_type' => 'module',
                        'limit_count' => 0,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('subscription_plan_features')) {
            DB::table('subscription_plan_features')
                ->where('feature_key', 'cbt_online')
                ->whereIn('subscription_plan_id', function ($query) {
                    $query->select('id')
                        ->from('subscription_plans')
                        ->where(function ($plans) {
                            $plans->where('name', 'like', '%Legacy Plus%')
                                ->orWhere('name', 'like', '%GradeQuestPlus%');
                        });
                })
                ->delete();
        }

        if (! Schema::hasTable('subscription_plans')) {
            return;
        }

        DB::table('subscription_plans')
            ->where(function ($query) {
                $query->where('name', 'like', '%Legacy Plus%')
                    ->orWhere('name', 'like', '%GradeQuestPlus%');
            })
            ->get()
            ->each(function ($plan) {
                $features = collect($this->decodeFeatures($plan->features ?? []))
                    ->reject(fn ($feature) => is_array($feature) && strtolower((string) ($feature['feature_key'] ?? '')) === 'cbt_online')
                    ->values()
                    ->all();

                DB::table('subscription_plans')
                    ->where('id', $plan->id)
                    ->update([
                        'features' => json_encode($features),
                        'updated_at' => now(),
                    ]);
            });
    }

    private function decodeFeatures(mixed $features): array
    {
        for ($attempt = 0; $attempt < 3 && is_string($features); $attempt++) {
            $decoded = json_decode($features, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return [];
            }

            $features = $decoded;
        }

        return is_array($features) ? array_values($features) : [];
    }

    private function upsertFeature(array $features, array $newFeature): array
    {
        $found = false;

        foreach ($features as &$feature) {
            if (! is_array($feature)) {
                continue;
            }

            if (strtolower((string) ($feature['feature_key'] ?? '')) === strtolower($newFeature['feature_key'])) {
                $feature = array_merge($feature, $newFeature);
                $found = true;
            }
        }

        unset($feature);

        if (! $found) {
            $features[] = $newFeature;
        }

        return array_values($features);
    }
};
