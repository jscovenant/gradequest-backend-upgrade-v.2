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
                if (! Schema::hasColumn('gradequest_billing_policies', 'ai_lesson_plan_credit_cost')) {
                    $table->unsignedInteger('ai_lesson_plan_credit_cost')->default(3)->after('ai_cbt_question_credit_cost');
                }
            });
        }

        if (! Schema::hasTable('subscription_plan_features')) {
            return;
        }

        DB::table('subscription_plan_features')->whereIn('feature_key', [
            'ai_result_comment_generator',
            'support_ai_result_comment_generator',
            'ai_cbt_question_generator',
            'support_ai_cbt_question_generator',
        ])->get(['subscription_plan_id'])->unique('subscription_plan_id')->each(function ($row) {
            foreach ([
                ['feature_name' => 'AI Lesson Plan Generator', 'feature_key' => 'ai_lesson_plan_generator'],
                ['feature_name' => 'Support AI Lesson Plan Generator', 'feature_key' => 'support_ai_lesson_plan_generator'],
            ] as $feature) {
                DB::table('subscription_plan_features')->updateOrInsert(
                    [
                        'subscription_plan_id' => $row->subscription_plan_id,
                        'feature_key' => $feature['feature_key'],
                    ],
                    [
                        'feature_name' => $feature['feature_name'],
                        'is_enabled' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('subscription_plan_features')) {
            if (! Schema::hasTable('subscription_plan_features')) {
            return;
        }

        DB::table('subscription_plan_features')->whereIn('feature_key', [
                'ai_lesson_plan_generator',
                'support_ai_lesson_plan_generator',
            ])->delete();
        }

        if (Schema::hasTable('gradequest_billing_policies') && Schema::hasColumn('gradequest_billing_policies', 'ai_lesson_plan_credit_cost')) {
            Schema::table('gradequest_billing_policies', function (Blueprint $table) {
                $table->dropColumn('ai_lesson_plan_credit_cost');
            });
        }
    }
};

