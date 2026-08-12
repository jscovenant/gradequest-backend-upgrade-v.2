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
        if (! Schema::hasTable('subscription_plans')) return;

        $plans = DB::table('subscription_plans')
            ->whereRaw("LOWER(REPLACE(name, ' ', '')) IN (?, ?)", ['core', 'gradequestcore'])
            ->get();

        foreach ($plans as $plan) {
            $configured = json_decode((string) ($plan->features ?? '[]'), true);
            $configured = is_array($configured) ? $configured : [];

            foreach ($this->features as $feature) {
                $configured = array_values(array_filter(
                    $configured,
                    fn ($item) => strtolower((string) ($item['feature_key'] ?? '')) !== $feature['feature_key']
                ));
                $configured[] = $feature + ['is_enabled' => true, 'limit_type' => 'module', 'limit_count' => 0];

                if (Schema::hasTable('subscription_plan_features')) {
                    DB::table('subscription_plan_features')->updateOrInsert(
                        ['subscription_plan_id' => $plan->id, 'feature_key' => $feature['feature_key']],
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

            DB::table('subscription_plans')->where('id', $plan->id)->update([
                'features' => json_encode($configured),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('subscription_plans')) return;

        $keys = collect($this->features)->pluck('feature_key')->all();
        $plans = DB::table('subscription_plans')
            ->whereRaw("LOWER(REPLACE(name, ' ', '')) IN (?, ?)", ['core', 'gradequestcore'])
            ->get();

        foreach ($plans as $plan) {
            $configured = json_decode((string) ($plan->features ?? '[]'), true);
            $configured = is_array($configured) ? $configured : [];
            $configured = array_values(array_filter(
                $configured,
                fn ($item) => ! in_array(strtolower((string) ($item['feature_key'] ?? '')), $keys, true)
            ));

            DB::table('subscription_plans')->where('id', $plan->id)->update([
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
};
