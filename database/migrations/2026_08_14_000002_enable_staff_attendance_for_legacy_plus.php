<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $features = [
        ['feature_key' => 'staff_attendance', 'feature_name' => 'Staff Attendance'],
        ['feature_key' => 'support_staff_attendance', 'feature_name' => 'Staff Attendance'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('subscription_plans')) {
            return;
        }

        $plans = $this->plusPlans();

        foreach ($plans as $plan) {
            $configured = $this->decodeFeatures($plan->features ?? []);

            foreach ($this->features as $feature) {
                $configured = $this->upsertFeature($configured, $feature + [
                    'is_enabled' => true,
                    'limit_type' => 'module',
                    'limit_count' => 0,
                ]);

                if (Schema::hasTable('subscription_plan_features')) {
                    DB::table('subscription_plan_features')->updateOrInsert(
                        [
                            'subscription_plan_id' => $plan->id,
                            'feature_key' => $feature['feature_key'],
                        ],
                        $feature + [
                            'is_enabled' => true,
                            'limit_type' => 'module',
                            'limit_count' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }
            }

            DB::table('subscription_plans')
                ->where('id', $plan->id)
                ->update([
                    'features' => json_encode($configured),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('subscription_plans')) {
            return;
        }

        $keys = collect($this->features)->pluck('feature_key')->all();
        $plans = $this->plusPlans();

        foreach ($plans as $plan) {
            $configured = collect($this->decodeFeatures($plan->features ?? []))
                ->reject(fn ($feature) => is_array($feature) && in_array(strtolower((string) ($feature['feature_key'] ?? '')), $keys, true))
                ->values()
                ->all();

            DB::table('subscription_plans')
                ->where('id', $plan->id)
                ->update([
                    'features' => json_encode($configured),
                    'updated_at' => now(),
                ]);

            if (Schema::hasTable('subscription_plan_features')) {
                DB::table('subscription_plan_features')
                    ->where('subscription_plan_id', $plan->id)
                    ->whereIn('feature_key', $keys)
                    ->delete();
            }
        }
    }

    private function plusPlans()
    {
        return DB::table('subscription_plans')
            ->where(function ($query) {
                $query->where('name', 'like', '%Legacy Plus%')
                    ->orWhere('name', 'like', '%GradeQuestPlus%')
                    ->orWhere('name', 'like', '%GradeQuest Plus%');
            })
            ->get();
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