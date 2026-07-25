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
        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'student_fee_id')) {
                $table->foreignId('student_fee_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('student_fees')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('payments', 'payment_method')) {
                $table->string('payment_method')->nullable()->after('amount');
            }

            if (! Schema::hasColumn('payments', 'received_by')) {
                $table->foreignId('received_by')
                    ->nullable()
                    ->after('reference')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'student_fee_id')) {
                $table->dropConstrainedForeignId('student_fee_id');
            }

            if (Schema::hasColumn('payments', 'received_by')) {
                $table->dropConstrainedForeignId('received_by');
            }

            if (Schema::hasColumn('payments', 'payment_method')) {
                $table->dropColumn('payment_method');
            }
        });
    }
};
