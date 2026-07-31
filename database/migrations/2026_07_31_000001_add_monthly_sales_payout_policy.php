<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_representatives', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_representatives', 'status_reason')) {
                $table->text('status_reason')->nullable()->after('status');
            }
            if (! Schema::hasColumn('sales_representatives', 'status_changed_at')) {
                $table->timestamp('status_changed_at')->nullable()->after('status_reason');
            }
            if (! Schema::hasColumn('sales_representatives', 'closure_requested_at')) {
                $table->timestamp('closure_requested_at')->nullable()->after('payout_verified_at');
            }
            if (! Schema::hasColumn('sales_representatives', 'death_reported_at')) {
                $table->timestamp('death_reported_at')->nullable()->after('closure_requested_at');
            }
            if (! Schema::hasColumn('sales_representatives', 'next_of_kin_name')) {
                $table->string('next_of_kin_name')->nullable()->after('death_reported_at');
            }
            if (! Schema::hasColumn('sales_representatives', 'next_of_kin_phone')) {
                $table->string('next_of_kin_phone', 50)->nullable()->after('next_of_kin_name');
            }
            if (! Schema::hasColumn('sales_representatives', 'next_of_kin_relationship')) {
                $table->string('next_of_kin_relationship', 80)->nullable()->after('next_of_kin_phone');
            }
            if (! Schema::hasColumn('sales_representatives', 'final_settlement_status')) {
                $table->string('final_settlement_status')->nullable()->after('next_of_kin_relationship');
            }
            if (! Schema::hasColumn('sales_representatives', 'final_settlement_notes')) {
                $table->text('final_settlement_notes')->nullable()->after('final_settlement_status');
            }
        });

        Schema::table('sales_commissions', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_commissions', 'payment_id')) {
                $table->unsignedBigInteger('payment_id')->nullable()->after('sub_payment_id')->index();
            }
            if (! Schema::hasColumn('sales_commissions', 'student_id')) {
                $table->unsignedBigInteger('student_id')->nullable()->after('school_id')->index();
            }
            if (! Schema::hasColumn('sales_commissions', 'session_id')) {
                $table->unsignedBigInteger('session_id')->nullable()->after('student_id')->index();
            }
            if (! Schema::hasColumn('sales_commissions', 'term_id')) {
                $table->unsignedBigInteger('term_id')->nullable()->after('session_id')->index();
            }
            if (! Schema::hasColumn('sales_commissions', 'eligible_at')) {
                $table->timestamp('eligible_at')->nullable()->after('earned_at')->index();
            }
            if (! Schema::hasColumn('sales_commissions', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('approved_at');
            }
            if (! Schema::hasColumn('sales_commissions', 'hold_reason')) {
                $table->text('hold_reason')->nullable()->after('reviewed_at');
            }
            if (! Schema::hasColumn('sales_commissions', 'payout_period')) {
                $table->string('payout_period', 7)->nullable()->after('paid_at')->index();
            }
            if (! Schema::hasColumn('sales_commissions', 'metadata')) {
                $table->json('metadata')->nullable()->after('notes');
            }
        });

        try {
            Schema::table('sales_commissions', function (Blueprint $table) {
                $table->unique(
                    ['sales_representative_id', 'school_id', 'student_id', 'session_id', 'term_id', 'source'],
                    'sales_commission_student_period_unique'
                );
            });
        } catch (Throwable $e) {
            // Existing local schemas may already contain equivalent indexes.
        }

        Schema::table('sales_payout_batches', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_payout_batches', 'period_start')) {
                $table->date('period_start')->nullable()->after('reference')->index();
            }
            if (! Schema::hasColumn('sales_payout_batches', 'period_end')) {
                $table->date('period_end')->nullable()->after('period_start')->index();
            }
            if (! Schema::hasColumn('sales_payout_batches', 'payout_month')) {
                $table->string('payout_month', 7)->nullable()->after('period_end')->index();
            }
            if (! Schema::hasColumn('sales_payout_batches', 'batch_type')) {
                $table->string('batch_type')->default('manual')->after('payout_month')->index();
            }
            if (! Schema::hasColumn('sales_payout_batches', 'minimum_payout_amount')) {
                $table->decimal('minimum_payout_amount', 14, 2)->default(0)->after('total_amount');
            }
            if (! Schema::hasColumn('sales_payout_batches', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->after('initiated_by')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('sales_payout_batches', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
            if (! Schema::hasColumn('sales_payout_batches', 'processed_at')) {
                $table->timestamp('processed_at')->nullable()->after('initiated_at');
            }
        });

        if (! Schema::hasTable('sales_payout_policies')) {
            Schema::create('sales_payout_policies', function (Blueprint $table) {
                $table->id();
                $table->decimal('default_commission_rate', 5, 2)->default(5.00);
                $table->decimal('minimum_payout_amount', 14, 2)->default(5000.00);
                $table->unsignedTinyInteger('monthly_payout_day')->default(5);
                $table->unsignedTinyInteger('commission_waiting_days')->default(7);
                $table->boolean('auto_approval_enabled')->default(true);
                $table->boolean('auto_payout_enabled')->default(false);
                $table->decimal('large_commission_review_threshold', 14, 2)->default(50000.00);
                $table->string('currency', 3)->default('NGN');
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        DB::table('sales_payout_policies')->updateOrInsert(
            ['id' => 1],
            [
                'default_commission_rate' => 5.00,
                'minimum_payout_amount' => 5000.00,
                'monthly_payout_day' => 5,
                'commission_waiting_days' => 7,
                'auto_approval_enabled' => true,
                'auto_payout_enabled' => false,
                'large_commission_review_threshold' => 50000.00,
                'currency' => 'NGN',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        if (! Schema::hasTable('sales_rep_status_events')) {
            Schema::create('sales_rep_status_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sales_representative_id')->constrained('sales_representatives')->cascadeOnDelete();
                $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('old_status')->nullable();
                $table->string('new_status');
                $table->text('reason')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_rep_status_events');
        Schema::dropIfExists('sales_payout_policies');

        Schema::table('sales_payout_batches', function (Blueprint $table) {
            foreach (['processed_at', 'approved_at', 'approved_by', 'minimum_payout_amount', 'batch_type', 'payout_month', 'period_end', 'period_start'] as $column) {
                if (Schema::hasColumn('sales_payout_batches', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        try {
            Schema::table('sales_commissions', function (Blueprint $table) {
                $table->dropUnique('sales_commission_student_period_unique');
            });
        } catch (Throwable $e) {
        }

        Schema::table('sales_commissions', function (Blueprint $table) {
            foreach (['metadata', 'payout_period', 'hold_reason', 'reviewed_at', 'eligible_at', 'term_id', 'session_id', 'student_id', 'payment_id'] as $column) {
                if (Schema::hasColumn('sales_commissions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('sales_representatives', function (Blueprint $table) {
            foreach ([
                'final_settlement_notes',
                'final_settlement_status',
                'next_of_kin_relationship',
                'next_of_kin_phone',
                'next_of_kin_name',
                'death_reported_at',
                'closure_requested_at',
                'status_changed_at',
                'status_reason',
            ] as $column) {
                if (Schema::hasColumn('sales_representatives', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
