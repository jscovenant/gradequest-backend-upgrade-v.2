<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $features = [
        ['feature_key' => 'student_management', 'feature_name' => 'Student Management', 'limit_type' => null, 'limit_count' => 0],
        ['feature_key' => 'teacher_management', 'feature_name' => 'Teacher Management', 'limit_type' => null, 'limit_count' => 0],
        ['feature_key' => 'result_management', 'feature_name' => 'Result Management', 'limit_type' => null, 'limit_count' => 0],
        ['feature_key' => 'support_results_upload', 'feature_name' => 'Result Upload', 'limit_type' => null, 'limit_count' => 0],
        ['feature_key' => 'fee_management', 'feature_name' => 'Fee Management', 'limit_type' => null, 'limit_count' => 0],
        ['feature_key' => 'finance_management', 'feature_name' => 'Finance Management', 'limit_type' => null, 'limit_count' => 0],
        ['feature_key' => 'online_payment', 'feature_name' => 'Online Fee Payment', 'limit_type' => null, 'limit_count' => 0],
        ['feature_key' => 'attendance_management', 'feature_name' => 'Attendance Management', 'limit_type' => null, 'limit_count' => 0],
        ['feature_key' => 'parent_management', 'feature_name' => 'Parent Portal', 'limit_type' => null, 'limit_count' => 0],
        ['feature_key' => 'bursar_management', 'feature_name' => 'Bursar Portal', 'limit_type' => null, 'limit_count' => 0],
        ['feature_key' => 'settings_management', 'feature_name' => 'School Settings', 'limit_type' => null, 'limit_count' => 0],
        ['feature_key' => 'whatsapp_notifications', 'feature_name' => 'WhatsApp Notifications', 'limit_type' => 'usage', 'limit_count' => 200],
        ['feature_key' => 'cbt_online', 'feature_name' => 'Online CBT', 'limit_type' => 'module', 'limit_count' => 0],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('subscription_plans')) {
            return;
        }

        $plans = DB::table('subscription_plans')
            ->where(function ($query) {
                $query->where('name', 'like', '%Legacy Plus%')
                    ->orWhere('name', 'like', '%GradeQuestPlus%')
                    ->orWhere('name', 'like', '%GradeQuest Plus%');
            })
            ->get();

        foreach ($plans as $plan) {
            $features = $this->decodeFeatures($plan->features ?? []);

            foreach ($this->features as $feature) {
                $features = $this->upsertFeature($features, [
                    'feature_name' => $feature['feature_name'],
                    'feature_key' => $feature['feature_key'],
                    'is_enabled' => true,
                    'limit_type' => $feature['limit_type'],
                    'limit_count' => $feature['limit_count'],
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
                            'limit_type' => $feature['limit_type'],
                            'limit_count' => $feature['limit_count'],
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
                    'whatsapp_enabled' => true,
                    'whatsapp_monthly_credits' => max((int) ($plan->whatsapp_monthly_credits ?? 0), 200),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('subscription_plans')) {
            return;
        }

        $planIds = DB::table('subscription_plans')
            ->where(function ($query) {
                $query->where('name', 'like', '%Legacy Plus%')
                    ->orWhere('name', 'like', '%GradeQuestPlus%')
                    ->orWhere('name', 'like', '%GradeQuest Plus%');
            })
            ->pluck('id');

        if ($planIds->isEmpty()) {
            return;
        }

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
