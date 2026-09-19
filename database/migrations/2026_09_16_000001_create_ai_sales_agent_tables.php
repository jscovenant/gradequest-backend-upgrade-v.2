<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. AI Sales Agent Configurations
        if (! Schema::hasTable('ai_sales_agent_configs')) {
            Schema::create('ai_sales_agent_configs', function (Blueprint $table) {
                $table->id();
                $table->string('openai_api_key')->nullable();
                $table->string('model', 60)->default('gpt-4o-mini');
                $table->decimal('temperature', 3, 2)->default(0.70);
                $table->string('agent_name', 100)->default('Sarah - SchoolProfit Growth Consultant');
                $table->longText('custom_instructions')->nullable();
                $table->longText('vision_statement')->nullable();
                $table->integer('inactivity_threshold_days')->default(14);
                $table->boolean('auto_scan_enabled')->default(true);
                $table->boolean('whatsapp_outreach_enabled')->default(true);
                $table->integer('max_daily_outreach')->default(50);
                $table->string('support_whatsapp_number', 50)->nullable()->default('+2348000000000');
                $table->string('demo_booking_url', 255)->nullable()->default('https://schoolprofit.ng/book-demo');
                $table->string('signup_url', 255)->nullable()->default('https://schoolprofit.ng/onboarding');
                $table->string('pricing_url', 255)->nullable()->default('https://schoolprofit.ng/school-plans');
                $table->timestamps();
            });
        }

        // 2. AI Sales Conversations & Lead Tracking
        if (! Schema::hasTable('ai_sales_conversations')) {
            Schema::create('ai_sales_conversations', function (Blueprint $table) {
                $table->id();
                $table->string('session_id', 100)->unique()->index();
                $table->string('channel', 40)->default('web_sandbox'); // web_sandbox, public_widget, whatsapp
                $table->string('prospect_name')->nullable();
                $table->string('school_name')->nullable();
                $table->string('phone_number', 50)->nullable()->index();
                $table->string('email')->nullable();
                $table->string('estimated_students', 50)->nullable();
                $table->string('current_pain_point')->nullable();
                $table->string('lead_status', 30)->default('inquiry'); // inquiry, qualified, demo_booked, signup_initiated, closed_won, cold
                $table->json('messages')->nullable();
                $table->text('summary_notes')->nullable();
                $table->integer('message_count')->default(0);
                $table->timestamp('last_interaction_at')->nullable();
                $table->timestamps();
            });
        }

        // 3. Inactive School Re-engagement Outreach Logs
        if (! Schema::hasTable('ai_sales_outreach_logs')) {
            Schema::create('ai_sales_outreach_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('school_settings')->cascadeOnDelete();
                $table->unsignedBigInteger('user_id')->nullable(); // School owner / admin
                $table->string('school_name');
                $table->string('contact_person')->nullable();
                $table->string('phone_number', 50);
                $table->string('inactivity_reason'); // e.g. 0_students_uploaded, no_login_30_days, trial_expiring, abandoned_fee_setup
                $table->longText('generated_message');
                $table->string('status', 30)->default('staged'); // staged, sent, delivered, replied, converted, opted_out
                $table->string('delivery_channel', 30)->default('whatsapp');
                $table->timestamp('dispatched_at')->nullable();
                $table->timestamp('replied_at')->nullable();
                $table->timestamp('converted_at')->nullable();
                $table->text('response_notes')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_sales_outreach_logs');
        Schema::dropIfExists('ai_sales_conversations');
        Schema::dropIfExists('ai_sales_agent_configs');
    }
};
