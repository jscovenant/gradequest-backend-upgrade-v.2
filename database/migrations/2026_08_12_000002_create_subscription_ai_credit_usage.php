<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gradequest_billing_policies')) {
            Schema::table('gradequest_billing_policies', function (Blueprint $table) {
                if (! Schema::hasColumn('gradequest_billing_policies', 'legacy_plus_ai_credits')) {
                    $table->unsignedInteger('legacy_plus_ai_credits')->default(100)->after('whatsapp_credit_unit_price');
                }

                if (! Schema::hasColumn('gradequest_billing_policies', 'ai_result_comment_credit_cost')) {
                    $table->unsignedInteger('ai_result_comment_credit_cost')->default(1)->after('legacy_plus_ai_credits');
                }

                if (! Schema::hasColumn('gradequest_billing_policies', 'ai_cbt_question_credit_cost')) {
                    $table->unsignedInteger('ai_cbt_question_credit_cost')->default(5)->after('ai_result_comment_credit_cost');
                }

                if (! Schema::hasColumn('gradequest_billing_policies', 'ai_credit_unit_price')) {
                    $table->decimal('ai_credit_unit_price', 12, 2)->default(25)->after('ai_cbt_question_credit_cost');
                }
            });
        }

        if (! Schema::hasTable('subscription_ai_usages')) {
            Schema::create('subscription_ai_usages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
                $table->foreignId('school_id')->nullable()->constrained('school_settings')->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->date('cycle_start');
                $table->date('cycle_end');
                $table->unsignedInteger('allocated_credits')->default(0);
                $table->unsignedInteger('used_credits')->default(0);
                $table->timestamps();

                $table->unique(['subscription_id', 'cycle_start', 'cycle_end'], 'sub_ai_usage_cycle_unique');
                $table->index(['school_id', 'cycle_start', 'cycle_end'], 'sub_ai_usage_school_cycle_idx');
            });
        }

        if (! Schema::hasTable('ai_credit_transactions')) {
            Schema::create('ai_credit_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->nullable()->constrained('school_settings')->nullOnDelete();
                $table->foreignId('subscription_ai_usage_id')->constrained('subscription_ai_usages')->cascadeOnDelete();
                $table->string('feature_key')->index();
                $table->string('type', 30);
                $table->integer('credits');
                $table->string('reference')->unique();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['school_id', 'feature_key', 'created_at'], 'ai_credit_school_feature_idx');
            });
        }

        if (Schema::hasTable('ai_usage_logs')) {
            Schema::table('ai_usage_logs', function (Blueprint $table) {
                if (! Schema::hasColumn('ai_usage_logs', 'subscription_ai_usage_id')) {
                    $table->unsignedBigInteger('subscription_ai_usage_id')->nullable()->index()->after('user_id');
                }

                if (! Schema::hasColumn('ai_usage_logs', 'credits_charged')) {
                    $table->unsignedInteger('credits_charged')->default(0)->after('items_generated');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_usage_logs')) {
            Schema::table('ai_usage_logs', function (Blueprint $table) {
                if (Schema::hasColumn('ai_usage_logs', 'credits_charged')) {
                    $table->dropColumn('credits_charged');
                }

                if (Schema::hasColumn('ai_usage_logs', 'subscription_ai_usage_id')) {
                    $table->dropColumn('subscription_ai_usage_id');
                }
            });
        }

        Schema::dropIfExists('ai_credit_transactions');
        Schema::dropIfExists('subscription_ai_usages');

        if (Schema::hasTable('gradequest_billing_policies')) {
            Schema::table('gradequest_billing_policies', function (Blueprint $table) {
                foreach (['ai_credit_unit_price', 'ai_cbt_question_credit_cost', 'ai_result_comment_credit_cost', 'legacy_plus_ai_credits'] as $column) {
                    if (Schema::hasColumn('gradequest_billing_policies', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
