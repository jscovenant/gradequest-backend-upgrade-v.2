<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('offline_cbt_bundles')) {
            Schema::create('offline_cbt_bundles', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id')->nullable()->index();
                $table->unsignedBigInteger('license_id')->nullable()->index();
                $table->string('license_key')->nullable()->index();
                $table->string('school_name')->nullable();
                $table->string('bundle_signature')->nullable();
                $table->timestamp('generated_at')->nullable();
                $table->timestamp('imported_at')->nullable();
                $table->boolean('is_active')->default(true);
                $table->json('payload');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('offline_cbt_attempts')) {
            Schema::create('offline_cbt_attempts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('offline_cbt_bundle_id')->constrained('offline_cbt_bundles')->cascadeOnDelete();
                $table->string('offline_attempt_uuid', 80)->unique();
                $table->unsignedBigInteger('school_id')->nullable()->index();
                $table->unsignedBigInteger('license_id')->nullable()->index();
                $table->unsignedBigInteger('exam_id')->index();
                $table->unsignedBigInteger('student_id')->index();
                $table->string('student_reg_no')->nullable()->index();
                $table->enum('status', ['in_progress', 'submitted', 'cancelled'])->default('in_progress');
                $table->timestamp('started_at')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->string('device_name')->nullable();
                $table->string('ip_address')->nullable();
                $table->unsignedInteger('events_count')->default(0);
                $table->json('answers')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['exam_id', 'student_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_cbt_attempts');
        Schema::dropIfExists('offline_cbt_bundles');
    }
};
