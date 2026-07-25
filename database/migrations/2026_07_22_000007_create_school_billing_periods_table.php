<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('school_billing_periods')) {
            Schema::create('school_billing_periods', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id');
                $table->unsignedBigInteger('session_id');
                $table->unsignedBigInteger('term_id');
                $table->date('academic_start_date')->nullable();
                $table->dateTime('billing_started_at');
                $table->dateTime('billing_grace_ends_at')->nullable();
                $table->string('status')->default('active');
                $table->string('source')->default('system');
                $table->dateTime('locked_at')->nullable();
                $table->unsignedBigInteger('locked_by')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->string('reason')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->unique(['school_id', 'session_id', 'term_id'], 'school_billing_period_unique');
                $table->index(['school_id', 'status'], 'school_billing_period_status_idx');
                $table->foreign('school_id')->references('id')->on('school_settings')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('school_billing_periods');
    }
};
