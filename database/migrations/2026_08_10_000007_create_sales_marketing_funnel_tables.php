<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_marketing_materials', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type')->default('banner')->index();
            $table->string('asset_path')->nullable();
            $table->string('external_url')->nullable();
            $table->text('share_caption')->nullable();
            $table->string('cta_label')->default('Learn more');
            $table->string('cta_url')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('sales_rep_assignments', function (Blueprint $table) {
            $table->foreignId('marketing_material_id')->nullable()->after('demo_booking_id')->constrained('sales_marketing_materials')->nullOnDelete();
            $table->string('registration_token_hash', 64)->nullable()->unique()->after('source');
            $table->timestamp('registration_token_expires_at')->nullable()->after('registration_token_hash');
            $table->timestamp('attribution_locked_at')->nullable()->after('registration_token_expires_at');
        });

        Schema::create('sales_page_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_representative_id')->constrained('sales_representatives')->cascadeOnDelete();
            $table->foreignId('marketing_material_id')->nullable()->constrained('sales_marketing_materials')->nullOnDelete();
            $table->string('event_type')->index();
            $table->string('visitor_hash', 64)->nullable()->index();
            $table->string('referrer')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['sales_representative_id', 'event_type', 'created_at'], 'sales_page_rep_event_idx');
        });

        DB::table('sales_marketing_materials')->insert([
            'title' => 'Run Your School Better',
            'description' => 'A GradeQuest campaign for school owners who want student records, results, fees, attendance, and parent communication in one platform.',
            'type' => 'banner',
            'external_url' => rtrim((string) config('app.frontend_url'), '/') . '/marketing/gradequest-run-your-school-better.png',
            'share_caption' => "Run your school better with GradeQuest. Manage records, results, fees, attendance, and parent communication from one secure platform.",
            'cta_label' => 'Request a setup',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_page_events');

        Schema::table('sales_rep_assignments', function (Blueprint $table) {
            $table->dropForeign(['marketing_material_id']);
            $table->dropUnique(['registration_token_hash']);
            $table->dropColumn([
                'marketing_material_id',
                'registration_token_hash',
                'registration_token_expires_at',
                'attribution_locked_at',
            ]);
        });

        Schema::dropIfExists('sales_marketing_materials');
    }
};
