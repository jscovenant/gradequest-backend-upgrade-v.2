<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_schemes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->string('subject', 150);
            $table->string('class_name', 150);
            $table->string('term', 80)->nullable();
            $table->string('curriculum', 120)->nullable();
            $table->string('source', 30)->default('manual');
            $table->string('title', 180);
            $table->json('topics')->nullable();
            $table->longText('content')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->timestamps();
        });

        Schema::create('generated_lesson_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('scheme_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->string('subject', 150);
            $table->string('class_name', 150);
            $table->string('topic', 180);
            $table->unsignedInteger('duration_minutes')->default(40);
            $table->json('plan')->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->timestamps();
        });

        Schema::create('lesson_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('scheme_id')->nullable()->index();
            $table->unsignedBigInteger('lesson_plan_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('published_by')->nullable()->index();
            $table->string('subject', 150);
            $table->string('class_name', 150);
            $table->string('topic', 180);
            $table->string('title', 180);
            $table->json('content')->nullable();
            $table->json('youtube_videos')->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_notes');
        Schema::dropIfExists('generated_lesson_plans');
        Schema::dropIfExists('lesson_schemes');
    }
};