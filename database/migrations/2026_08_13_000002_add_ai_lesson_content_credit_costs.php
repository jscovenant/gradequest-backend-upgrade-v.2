<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gradequest_billing_policies')) {
            Schema::table('gradequest_billing_policies', function (Blueprint $table) {
                if (! Schema::hasColumn('gradequest_billing_policies', 'ai_scheme_work_credit_cost')) {
                    $table->unsignedInteger('ai_scheme_work_credit_cost')->default(4)->after('ai_lesson_plan_credit_cost');
                }
                if (! Schema::hasColumn('gradequest_billing_policies', 'ai_lesson_note_credit_cost')) {
                    $table->unsignedInteger('ai_lesson_note_credit_cost')->default(5)->after('ai_scheme_work_credit_cost');
                }
            });
        }

        if (Schema::hasTable('subscription_plans')) {
            DB::table('subscription_plans')
                ->where(function ($query) {
                    $query->where('name', 'like', '%Plus%')
                        ->orWhere('name', 'like', '%AI%')
                        ->orWhere('name', 'like', '%Legacy%');
                })
                ->orderBy('id')
                ->get()
                ->each(function ($plan) {
                    $features = json_decode($plan->features ?: '[]', true);
                    $features = is_array($features) ? $features : [];
                    $keys = collect($features)->map(fn ($feature) => $feature['feature_key'] ?? null)->filter()->values()->all();
                    foreach ([
                        ['feature_name' => 'AI Scheme of Work Generator', 'feature_key' => 'ai_scheme_work_generator'],
                        ['feature_name' => 'AI Lesson Note Generator', 'feature_key' => 'ai_lesson_note_generator'],
                    ] as $feature) {
                        if (! in_array($feature['feature_key'], $keys, true)) {
                            $feature['enabled'] = true;
                            $features[] = $feature;
                        }
                    }
                    DB::table('subscription_plans')->where('id', $plan->id)->update([
                        'features' => json_encode($features),
                        'updated_at' => now(),
                    ]);
                });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('gradequest_billing_policies')) {
            Schema::table('gradequest_billing_policies', function (Blueprint $table) {
                if (Schema::hasColumn('gradequest_billing_policies', 'ai_lesson_note_credit_cost')) {
                    $table->dropColumn('ai_lesson_note_credit_cost');
                }
                if (Schema::hasColumn('gradequest_billing_policies', 'ai_scheme_work_credit_cost')) {
                    $table->dropColumn('ai_scheme_work_credit_cost');
                }
            });
        }
    }
};