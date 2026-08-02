<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $features = [
        ['feature_key' => 'support_student_management', 'feature_name' => 'Student Management'],
        ['feature_key' => 'support_teacher_management', 'feature_name' => 'Teacher Management'],
        ['feature_key' => 'support_fee_management', 'feature_name' => 'Fee Management'],
        ['feature_key' => 'support_finance_management', 'feature_name' => 'Finance Management'],
        ['feature_key' => 'support_staff_attendance', 'feature_name' => 'Staff Attendance'],
        ['feature_key' => 'support_parent_management', 'feature_name' => 'Parent Portal'],
        ['feature_key' => 'support_bursar_management', 'feature_name' => 'Bursar Portal'],
        ['feature_key' => 'support_settings_management', 'feature_name' => 'School Settings'],
        ['feature_key' => 'support_whatsapp_notifications', 'feature_name' => 'WhatsApp Notifications'],
        ['feature_key' => 'support_cbt_online', 'feature_name' => 'Online CBT'],
        ['feature_key' => 'cbt_offline', 'feature_name' => 'Offline CBT'],
        ['feature_key' => 'support_cbt_offline', 'feature_name' => 'Offline CBT'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('subscription_plans')) {
            return;
        }

        $plans = $this->plusPlans();

        foreach ($plans as $plan) {
            $features = $this->decodeFeatures($plan->features ?? []);

            foreach ($this->features as $feature) {
                $features = $this->upsertFeature($features, [
                    'feature_name' => $feature['feature_name'],
                    'feature_key' => $feature['feature_key'],
                    'is_enabled' => true,
                    'limit_type' => str_contains($feature['feature_key'], 'cbt') ? 'module' : null,
                    'limit_count' => 0,
                ]);

                if (Schema::hasTable('subscription_plan_features')) {
                    DB::table('subscription_plan_features')->updateOrInsert(
                        [
                            'subscription_plan_id' => $plan->id,
                            'feature_key' => $feature['feature_key'],
                        ],
                        [
                            'feature_name' => $feature['feature_name'],
                            'is_enabled' => true,
                            'limit_type' => str_contains($feature['feature_key'], 'cbt') ? 'module' : null,
                            'limit_count' => 0,
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    );
                }
            }

            DB::table('subscription_plans')
                ->where('id', $plan->id)
                ->update([
                    'features' => json_encode($features),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('subscription_plans')) {
            return;
        }

        $planIds = $this->plusPlans()->pluck('id');
        $keys = collect($this->features)->pluck('feature_key')->all();

        if (Schema::hasTable('subscription_plan_features')) {
            DB::table('subscription_plan_features')
                ->whereIn('subscription_plan_id', $planIds)
                ->whereIn('feature_key', $keys)
                ->delete();
        }

        DB::table('subscription_plans')
            ->whereIn('id', $planIds)
            ->get()
            ->each(function ($plan) use ($keys) {
                $features = collect($this->decodeFeatures($plan->features ?? []))
                    ->reject(fn ($feature) => is_array($feature) && in_array((string) ($feature['feature_key'] ?? ''), $keys, true))
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
