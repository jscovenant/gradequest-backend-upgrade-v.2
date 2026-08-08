<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $features = [
        ['feature_key' => 'report_card_designer', 'feature_name' => 'Report Card Designer'],
        ['feature_key' => 'support_report_card_designer', 'feature_name' => 'Report Card Designer'],
    ];

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

            foreach ($this->features as $feature) {
                $features = $this->upsertFeature($features, [
                    'feature_name' => $feature['feature_name'],
                    'feature_key' => $feature['feature_key'],
                    'is_enabled' => true,
                    'limit_type' => 'module',
                    'limit_count' => 0,
                ]);

                if (Schema::hasTable('subscription_plan_features')) {
                    DB::table('subscription_plan_features')->updateOrInsert(
                        ['subscription_plan_id' => $plan->id, 'feature_key' => $feature['feature_key']],
                        [
                            'feature_name' => $feature['feature_name'],
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
                ->update(['features' => json_encode($features), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('subscription_plans')) {
            return;
        }

        $keys = collect($this->features)->pluck('feature_key')->all();
        if (Schema::hasTable('subscription_plan_features')) {
            DB::table('subscription_plan_features')->whereIn('feature_key', $keys)->delete();
        }

        DB::table('subscription_plans')->orderBy('id')->each(function ($plan) use ($keys) {
            $features = collect($this->decodeFeatures($plan->features ?? []))
                ->reject(fn ($feature) => in_array((string) ($feature['feature_key'] ?? ''), $keys, true))
                ->values()
                ->all();
            DB::table('subscription_plans')->where('id', $plan->id)->update([
                'features' => json_encode($features),
                'updated_at' => now(),
            ]);
        });
    }

    private function decodeFeatures($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function upsertFeature(array $features, array $newFeature): array
    {
        foreach ($features as $index => $feature) {
            if (strtolower((string) ($feature['feature_key'] ?? '')) === strtolower($newFeature['feature_key'])) {
                $features[$index] = array_merge($feature, $newFeature);
                return array_values($features);
            }
        }

        $features[] = $newFeature;
        return array_values($features);
    }
};
