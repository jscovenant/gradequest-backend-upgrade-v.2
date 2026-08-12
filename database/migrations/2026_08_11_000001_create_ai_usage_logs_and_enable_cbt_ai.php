<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_usage_logs')) {
            Schema::create('ai_usage_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->nullable()->constrained('school_settings')->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('feature_key')->index();
                $table->string('provider')->default('openai');
                $table->string('model')->nullable();
                $table->unsignedInteger('input_tokens')->default(0);
                $table->unsignedInteger('output_tokens')->default(0);
                $table->unsignedInteger('total_tokens')->default(0);
                $table->unsignedInteger('items_generated')->default(0);
                $table->string('status')->default('success');
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['school_id', 'feature_key', 'created_at']);
            });
        }

        $this->enableFeatureForPlusPlans();
    }

    public function down(): void
    {
        $featureKeys = ['ai_cbt_question_generator', 'support_ai_cbt_question_generator'];

        if (Schema::hasTable('subscription_plan_features')) {
            DB::table('subscription_plan_features')->whereIn('feature_key', $featureKeys)->delete();
        }

        if (Schema::hasTable('subscription_plans')) {
            DB::table('subscription_plans')
                ->where(function ($query) {
                    $query->where('name', 'like', '%Legacy Plus%')
                        ->orWhere('name', 'like', '%GradeQuest Plus%');
                })
                ->get()
                ->each(function ($plan) use ($featureKeys) {
                    $features = json_decode($plan->features ?? '[]', true);
                    if (! is_array($features)) {
                        return;
                    }

                    $features = array_values(array_filter(
                        $features,
                        fn ($feature) => ! in_array($feature['feature_key'] ?? null, $featureKeys, true)
                    ));

                    DB::table('subscription_plans')->where('id', $plan->id)->update([
                        'features' => json_encode($features),
                        'updated_at' => now(),
                    ]);
                });
        }

        Schema::dropIfExists('ai_usage_logs');
    }

    private function enableFeatureForPlusPlans(): void
    {
        if (! Schema::hasTable('subscription_plans')) {
            return;
        }

        $features = [
            [
                'feature_name' => 'AI CBT Question Generator',
                'feature_key' => 'ai_cbt_question_generator',
                'is_enabled' => true,
                'limit_type' => 'usage',
                'limit_count' => 0,
            ],
            [
                'feature_name' => 'AI CBT Question Generator',
                'feature_key' => 'support_ai_cbt_question_generator',
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
};
