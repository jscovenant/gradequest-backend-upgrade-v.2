<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Create bursar_sections pivot table
        if (!Schema::hasTable('bursar_sections')) {
            Schema::create('bursar_sections', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('school_settings')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('section_id')->constrained('sections')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['user_id', 'section_id'], 'bursar_section_unique');
                $table->index(['school_id', 'user_id']);
                $table->index(['school_id', 'section_id']);
            });
        }

        // 2. Add columns to fee_types
        Schema::table('fee_types', function (Blueprint $table) {
            if (!Schema::hasColumn('fee_types', 'is_discountable')) {
                $table->boolean('is_discountable')->default(true)->after('amount');
            }
            if (!Schema::hasColumn('fee_types', 'is_compulsory')) {
                $table->boolean('is_compulsory')->default(true)->after('is_discountable');
            }
        });

        // 3. Add columns to student_fees
        Schema::table('student_fees', function (Blueprint $table) {
            if (!Schema::hasColumn('student_fees', 'gross_amount')) {
                $table->decimal('gross_amount', 10, 2)->nullable()->after('fee_type_id');
            }
            if (!Schema::hasColumn('student_fees', 'discount_amount')) {
                $table->decimal('discount_amount', 10, 2)->default(0.00)->after('gross_amount');
            }
            if (!Schema::hasColumn('student_fees', 'discount_type')) {
                $table->string('discount_type', 20)->nullable()->after('discount_amount'); // percentage, fixed
            }
            if (!Schema::hasColumn('student_fees', 'discount_rate')) {
                $table->decimal('discount_rate', 8, 2)->nullable()->after('discount_type'); // e.g. 25.00%
            }
            if (!Schema::hasColumn('student_fees', 'discount_reason')) {
                $table->string('discount_reason', 100)->nullable()->after('discount_rate'); // staff_child, sibling, scholarship, hardship, custom
            }
            if (!Schema::hasColumn('student_fees', 'discount_status')) {
                $table->string('discount_status', 20)->default('none')->after('discount_reason'); // none, pending, approved, rejected
            }
            if (!Schema::hasColumn('student_fees', 'discount_approved_by')) {
                $table->foreignId('discount_approved_by')->nullable()->after('discount_status')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('student_fees', 'discount_notes')) {
                $table->text('discount_notes')->nullable()->after('discount_approved_by');
            }
            if (!Schema::hasColumn('student_fees', 'net_amount')) {
                $table->decimal('net_amount', 10, 2)->nullable()->after('discount_notes');
            }
        });

        // 4. Add columns to payments
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'receipt_number')) {
                $table->string('receipt_number', 64)->nullable()->after('reference')->index();
            }
            if (!Schema::hasColumn('payments', 'qr_code_token')) {
                $table->string('qr_code_token', 64)->nullable()->after('receipt_number')->unique();
            }
            if (!Schema::hasColumn('payments', 'current_paid')) {
                $table->decimal('current_paid', 10, 2)->default(0.00)->after('amount');
            }
            if (!Schema::hasColumn('payments', 'arrears_paid')) {
                $table->decimal('arrears_paid', 10, 2)->default(0.00)->after('current_paid');
            }
            if (!Schema::hasColumn('payments', 'payer_name')) {
                $table->string('payer_name', 150)->nullable()->after('received_by');
            }
            if (!Schema::hasColumn('payments', 'payer_phone')) {
                $table->string('payer_phone', 30)->nullable()->after('payer_name');
            }
        });

        // 5. Backfill existing student_fees
        DB::table('student_fees')
            ->whereNull('gross_amount')
            ->update([
                'gross_amount' => DB::raw('total_amount'),
                'net_amount' => DB::raw('total_amount'),
                'discount_amount' => 0.00,
                'discount_status' => 'none',
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bursar_sections');

        Schema::table('fee_types', function (Blueprint $table) {
            if (Schema::hasColumn('fee_types', 'is_compulsory')) {
                $table->dropColumn('is_compulsory');
            }
            if (Schema::hasColumn('fee_types', 'is_discountable')) {
                $table->dropColumn('is_discountable');
            }
        });

        Schema::table('student_fees', function (Blueprint $table) {
            if (Schema::hasColumn('student_fees', 'discount_approved_by')) {
                $table->dropConstrainedForeignId('discount_approved_by');
            }
            $cols = [
                'gross_amount',
                'discount_amount',
                'discount_type',
                'discount_rate',
                'discount_reason',
                'discount_status',
                'discount_notes',
                'net_amount',
            ];
            foreach ($cols as $c) {
                if (Schema::hasColumn('student_fees', $c)) {
                    $table->dropColumn($c);
                }
            }
        });

        Schema::table('payments', function (Blueprint $table) {
            $cols = [
                'receipt_number',
                'qr_code_token',
                'current_paid',
                'arrears_paid',
                'payer_name',
                'payer_phone',
            ];
            foreach ($cols as $c) {
                if (Schema::hasColumn('payments', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
