<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gradequest_billing_policies')) {
            Schema::create('gradequest_billing_policies', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('online_grace_days')->default(14);
                $table->unsignedInteger('online_minimum_coverage_percent')->default(70);
                $table->boolean('online_whole_school_block_enabled')->default(true);
                $table->boolean('online_student_level_block_enabled')->default(true);
                $table->unsignedInteger('offline_grace_days')->default(7);
                $table->boolean('offline_school_block_enabled')->default(true);
                $table->decimal('platform_fee_per_student', 12, 2)->default(1000);
                $table->unsignedInteger('temporary_access_min_days')->default(3);
                $table->unsignedInteger('temporary_access_max_days')->default(7);
                $table->json('allowed_blocked_actions')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gradequest_billing_policies');
    }
};
