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
                $query->where('name', 'like', '%Premium%')
                    ->orWhere('name', 'like', '%Plus%')
                    ->orWhere('name', 'like', '%Enterprise%');
            })
            ->get();

        foreach ($plans as $plan) {
            DB::table('subscription_plans')
                ->where('id', $plan->id)
                ->update([
                    'whatsapp_enabled' => true,
                    'whatsapp_monthly_credits' => max((int) ($plan->whatsapp_monthly_credits ?? 0), 200),
                    'updated_at' => now(),
                ]);

            $features = $this->decodeFeatures($plan->features ?? []);
            $features = $this->upsertFeature($features);

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
                        'feature_key' => 'whatsapp_notifications',
                    ],
                    [
                        'feature_name' => 'WhatsApp Notifications',
                        'is_enabled' => true,
                        'limit_type' => 'usage',
                        'limit_count' => max((int) ($plan->whatsapp_monthly_credits ?? 0), 200),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('subscription_plan_features')) {
            return;
        }

        DB::table('subscription_plan_features')
            ->where('feature_key', 'whatsapp_notifications')
            ->delete();
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

    private function upsertFeature(array $features): array
    {
        $found = false;

        foreach ($features as &$feature) {
            if (! is_array($feature)) {
                continue;
            }

            $key = strtolower(trim((string) ($feature['feature_key'] ?? '')));

            if ($key === 'whatsapp_notifications' || $key === 'support_whatsapp_notifications') {
                $feature['feature_key'] = 'whatsapp_notifications';
                $feature['feature_name'] = $feature['feature_name'] ?? 'WhatsApp Notifications';
                $feature['is_enabled'] = true;
                $feature['limit_type'] = 'usage';
                $feature['limit_count'] = max((int) ($feature['limit_count'] ?? 0), 200);
                $found = true;
            }
        }

        unset($feature);

        if (! $found) {
            $features[] = [
                'feature_name' => 'WhatsApp Notifications',
                'feature_key' => 'whatsapp_notifications',
                'is_enabled' => true,
                'limit_type' => 'usage',
                'limit_count' => 200,
            ];
        }

        return $features;
    }
};
