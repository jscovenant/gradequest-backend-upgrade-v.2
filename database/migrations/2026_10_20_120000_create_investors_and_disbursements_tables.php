<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('investors')) {
            Schema::create('investors', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('name', 150);
                $table->string('email', 150)->unique();
                $table->string('phone', 30)->nullable();
                $table->string('tier', 20)->default('silver'); // bronze, silver, gold, custom
                $table->decimal('capital_invested', 14, 2);
                $table->decimal('equity_percentage', 5, 2); // e.g. 5.00, 7.00, 10.00
                $table->decimal('recoup_percentage', 5, 2)->default(25.00); // 25% of net collections
                $table->integer('tenure_years')->default(5);
                $table->decimal('total_principal_repaid', 14, 2)->default(0.00);
                $table->decimal('total_dividends_paid', 14, 2)->default(0.00);
                $table->string('current_phase', 30)->default('recoup_phase'); // recoup_phase, dividend_phase, completed
                $table->date('investment_date')->nullable();
                $table->string('bank_name', 100)->nullable();
                $table->string('account_number', 30)->nullable();
                $table->string('account_name', 150)->nullable();
                $table->string('status', 20)->default('active'); // active, paused, completed
                $table->string('contract_ref', 50)->default('ST/SP-INV/2026/01');
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['status', 'current_phase']);
            });
        }

        if (!Schema::hasTable('investor_disbursements')) {
            Schema::create('investor_disbursements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('investor_id')->constrained('investors')->cascadeOnDelete();
                $table->string('reference', 64)->unique();
                $table->string('type', 30)->default('principal_recoup'); // principal_recoup, dividend_payout
                $table->string('academic_session', 30)->nullable();
                $table->string('term', 30)->nullable();
                $table->decimal('amount', 14, 2);
                $table->decimal('platform_term_collection', 14, 2)->nullable();
                $table->string('payment_method', 30)->default('bank_transfer');
                $table->date('payment_date');
                $table->string('bank_reference', 100)->nullable();
                $table->string('status', 20)->default('completed'); // pending, completed, reversed
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['investor_id', 'payment_date']);
                $table->index('reference');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('investor_disbursements');
        Schema::dropIfExists('investors');
    }
};
