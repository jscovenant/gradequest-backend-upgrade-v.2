<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_representatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('code')->unique();
            $table->string('region')->nullable()->index();
            $table->string('status')->default('active')->index();
            $table->decimal('commission_rate', 5, 2)->default(5.00);
            $table->decimal('monthly_target_amount', 14, 2)->default(0);
            $table->unsignedInteger('monthly_target_schools')->default(0);
            $table->date('joined_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_rep_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_representative_id')->constrained('sales_representatives')->cascadeOnDelete();
            $table->foreignId('demo_booking_id')->nullable()->constrained('demo_bookings')->nullOnDelete();
            $table->foreignId('school_id')->nullable()->constrained('school_settings')->nullOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('stage')->default('lead')->index();
            $table->string('source')->default('manual')->index();
            $table->decimal('pipeline_value', 14, 2)->default(0);
            $table->date('expected_close_date')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['sales_representative_id', 'stage'], 'sales_rep_stage_idx');
            $table->index(['school_id', 'admin_user_id'], 'sales_assignment_school_admin_idx');
        });

        Schema::create('sales_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_representative_id')->constrained('sales_representatives')->cascadeOnDelete();
            $table->foreignId('school_id')->nullable()->constrained('school_settings')->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignId('sub_payment_id')->nullable()->constrained('sub_payments')->nullOnDelete();
            $table->decimal('commissionable_amount', 14, 2)->default(0);
            $table->decimal('commission_rate', 5, 2)->default(0);
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('status')->default('pending')->index();
            $table->timestamp('earned_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['sales_representative_id', 'status'], 'sales_commission_rep_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_commissions');
        Schema::dropIfExists('sales_rep_assignments');
        Schema::dropIfExists('sales_representatives');
    }
};
