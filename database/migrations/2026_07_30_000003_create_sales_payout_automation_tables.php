<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_representatives', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_representatives', 'bank_name')) {
                $table->string('bank_name')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('sales_representatives', 'bank_code')) {
                $table->string('bank_code', 20)->nullable()->after('bank_name');
            }
            if (! Schema::hasColumn('sales_representatives', 'account_number')) {
                $table->string('account_number', 20)->nullable()->after('bank_code');
            }
            if (! Schema::hasColumn('sales_representatives', 'account_name')) {
                $table->string('account_name')->nullable()->after('account_number');
            }
            if (! Schema::hasColumn('sales_representatives', 'paystack_recipient_code')) {
                $table->string('paystack_recipient_code')->nullable()->after('account_name');
            }
            if (! Schema::hasColumn('sales_representatives', 'payout_verified_at')) {
                $table->timestamp('payout_verified_at')->nullable()->after('paystack_recipient_code');
            }
        });

        if (! Schema::hasTable('sales_payout_batches')) {
            Schema::create('sales_payout_batches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sales_representative_id')->constrained('sales_representatives')->cascadeOnDelete();
                $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('reference')->unique();
                $table->decimal('total_amount', 14, 2)->default(0);
                $table->unsignedInteger('commission_count')->default(0);
                $table->string('status')->default('pending')->index();
                $table->string('paystack_transfer_code')->nullable()->index();
                $table->string('paystack_transfer_id')->nullable()->index();
                $table->string('paystack_recipient_code')->nullable();
                $table->json('paystack_response')->nullable();
                $table->text('failure_reason')->nullable();
                $table->timestamp('initiated_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sales_payout_items')) {
            Schema::create('sales_payout_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sales_payout_batch_id')->constrained('sales_payout_batches')->cascadeOnDelete();
                $table->foreignId('sales_commission_id')->unique()->constrained('sales_commissions')->cascadeOnDelete();
                $table->decimal('amount', 14, 2)->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_payout_items');
        Schema::dropIfExists('sales_payout_batches');

        Schema::table('sales_representatives', function (Blueprint $table) {
            foreach ([
                'payout_verified_at',
                'paystack_recipient_code',
                'account_name',
                'account_number',
                'bank_code',
                'bank_name',
            ] as $column) {
                if (Schema::hasColumn('sales_representatives', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
