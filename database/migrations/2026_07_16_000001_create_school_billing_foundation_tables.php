<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('school_billing_settings')) {
            Schema::create('school_billing_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id')->unique();
                $table->enum('payment_mode', ['online', 'offline'])->default('offline');
                $table->unsignedInteger('grace_days')->default(7);
                $table->decimal('platform_fee_per_student', 10, 2)->default(1000);
                $table->boolean('block_results_when_unpaid')->default(true);
                $table->timestamp('switched_at')->nullable();
                $table->unsignedBigInteger('switched_by')->nullable();
                $table->timestamps();

                $table->foreign('school_id')->references('id')->on('school_settings')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('gradequest_term_invoices')) {
            Schema::create('gradequest_term_invoices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id');
                $table->unsignedBigInteger('session_id');
                $table->unsignedBigInteger('term_id');
                $table->string('invoice_no')->unique();
                $table->enum('billing_mode', ['online', 'offline'])->default('offline');
                $table->unsignedInteger('active_students_count')->default(0);
                $table->decimal('amount_due', 12, 2)->default(0);
                $table->decimal('amount_paid', 12, 2)->default(0);
                $table->decimal('balance', 12, 2)->default(0);
                $table->enum('status', ['draft', 'issued', 'partial', 'paid', 'overdue', 'cancelled'])->default('issued');
                $table->date('issued_at')->nullable();
                $table->date('due_date')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->unique(['school_id', 'session_id', 'term_id', 'billing_mode'], 'gq_term_invoice_unique');
                $table->index(['school_id', 'status']);
                $table->foreign('school_id')->references('id')->on('school_settings')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('student_billing_entitlements')) {
            Schema::create('student_billing_entitlements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id');
                $table->unsignedBigInteger('student_id');
                $table->unsignedBigInteger('session_id');
                $table->unsignedBigInteger('term_id');
                $table->enum('billing_mode', ['online', 'offline'])->default('offline');
                $table->enum('status', ['unpaid', 'grace', 'paid', 'waived', 'override'])->default('unpaid');
                $table->enum('source', ['online_fee', 'offline_invoice', 'manual_waiver', 'admin_override', 'system'])->default('system');
                $table->unsignedBigInteger('student_fee_id')->nullable();
                $table->unsignedBigInteger('invoice_id')->nullable();
                $table->timestamp('covered_at')->nullable();
                $table->timestamp('grace_until')->nullable();
                $table->unsignedBigInteger('acted_by')->nullable();
                $table->string('reason')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->unique(['school_id', 'student_id', 'session_id', 'term_id'], 'student_entitlement_unique');
                $table->index(['school_id', 'session_id', 'term_id', 'status'], 'student_entitlement_status_idx');
                $table->foreign('school_id')->references('id')->on('school_settings')->cascadeOnDelete();
                $table->foreign('student_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('school_billing_audit_logs')) {
            Schema::create('school_billing_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id');
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('action');
                $table->string('auditable_type')->nullable();
                $table->unsignedBigInteger('auditable_id')->nullable();
                $table->json('before')->nullable();
                $table->json('after')->nullable();
                $table->string('reason')->nullable();
                $table->timestamps();

                $table->index(['school_id', 'action']);
                $table->foreign('school_id')->references('id')->on('school_settings')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('school_billing_audit_logs');
        Schema::dropIfExists('student_billing_entitlements');
        Schema::dropIfExists('gradequest_term_invoices');
        Schema::dropIfExists('school_billing_settings');
    }
};
