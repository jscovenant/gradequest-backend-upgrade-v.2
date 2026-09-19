<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. School Admission Settings
        if (! Schema::hasTable('school_admission_settings')) {
            Schema::create('school_admission_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->unique()->constrained('school_settings')->cascadeOnDelete();
                $table->boolean('is_open')->default(true);
                $table->string('admission_session_name')->nullable(); // e.g. 2026/2027 Academic Session
                $table->decimal('application_fee', 12, 2)->default(5000.00); // School fee portion
                $table->decimal('platform_fee', 12, 2)->default(1000.00);    // SchoolProfit platform charge
                $table->boolean('require_payment')->default(true);
                $table->json('available_classes')->nullable();               // ['JSS 1', 'JSS 2', 'SS 1 Science', etc.]
                $table->longText('instructions')->nullable();
                $table->json('requirements')->nullable();                   // ['Birth Certificate', 'Primary School Testimonial', etc.]
                $table->date('start_date')->nullable();
                $table->date('closing_date')->nullable();
                $table->string('contact_email')->nullable();
                $table->string('contact_phone')->nullable();
                $table->boolean('auto_admit')->default(false);
                $table->timestamps();
            });
        }

        // 2. School Admission Applications
        if (! Schema::hasTable('school_admission_applications')) {
            Schema::create('school_admission_applications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('school_settings')->cascadeOnDelete();
                $table->string('application_number')->unique()->index(); // e.g. ADM-2026-0042
                $table->unsignedBigInteger('session_id')->nullable();
                $table->unsignedBigInteger('level_id')->nullable();
                $table->unsignedBigInteger('department_id')->nullable();
                $table->string('applied_class_name')->nullable();

                // Candidate Details
                $table->string('firstname');
                $table->string('surname');
                $table->string('other_names')->nullable();
                $table->date('dob')->nullable();
                $table->string('gender', 10)->default('Male');
                $table->string('blood_group', 10)->nullable();
                $table->string('religion')->nullable();
                $table->string('nationality')->default('Nigerian');
                $table->string('state_of_origin')->nullable();
                $table->string('lga_of_origin')->nullable();
                $table->text('home_address')->nullable();
                $table->string('passport_photo')->nullable();

                // Academic Background
                $table->string('previous_school')->nullable();
                $table->string('previous_class')->nullable();
                $table->string('last_grade_average')->nullable();

                // Parent / Guardian Details
                $table->string('parent_name');
                $table->string('parent_phone');
                $table->string('parent_email')->nullable();
                $table->text('parent_address')->nullable();
                $table->string('parent_occupation')->nullable();
                $table->string('parent_relationship', 30)->default('Parent'); // Father, Mother, Guardian

                // Status & Enrollment
                $table->string('status', 30)->default('submitted'); // submitted, under_review, admitted, rejected, enrolled
                $table->string('payment_status', 30)->default('pending'); // pending, paid, waived
                $table->unsignedBigInteger('enrolled_user_id')->nullable()->index(); // Link to User ID when 1-click enrolled
                $table->unsignedBigInteger('enrolled_parent_id')->nullable()->index();
                $table->string('assigned_reg_no')->nullable();
                $table->timestamp('enrolled_at')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->text('reviewer_notes')->nullable();
                $table->json('documents')->nullable();
                $table->timestamps();
            });
        }

        // 3. School Admission Payments (Processed via Wema Bank)
        if (! Schema::hasTable('school_admission_payments')) {
            Schema::create('school_admission_payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('school_settings')->cascadeOnDelete();
                $table->foreignId('application_id')->constrained('school_admission_applications')->cascadeOnDelete();
                $table->string('reference')->unique()->index();
                $table->decimal('total_amount', 12, 2);
                $table->decimal('school_amount', 12, 2);
                $table->decimal('platform_fee', 12, 2);
                $table->string('gateway', 30)->default('wema_alat');
                $table->string('channel', 40)->default('wema_virtual_account'); // wema_virtual_account, alat_card, transfer
                $table->string('wema_virtual_account_number')->nullable();
                $table->string('wema_account_name')->nullable();
                $table->string('wema_reference')->nullable();
                $table->string('status', 30)->default('pending'); // pending, successful, failed
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('settled_at')->nullable();
                $table->json('gateway_response')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('school_admission_payments');
        Schema::dropIfExists('school_admission_applications');
        Schema::dropIfExists('school_admission_settings');
    }
};
