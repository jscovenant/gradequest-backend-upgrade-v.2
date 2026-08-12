<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_fees', function (Blueprint $table) {
            $table->index(['school_id', 'session_id', 'term_id', 'status'], 'student_fees_intelligence_idx');
            $table->index(['school_id', 'student_id', 'balance'], 'student_fees_balance_idx');
        });

        Schema::table('result_batches', function (Blueprint $table) {
            $table->index(['school_id', 'status', 'published_at'], 'result_batches_published_idx');
        });

        Schema::table('student_results_v2', function (Blueprint $table) {
            $table->index(['user_id', 'batch_id'], 'student_results_user_batch_idx');
        });
    }

    public function down(): void
    {
        Schema::table('student_fees', fn (Blueprint $table) => $table->dropIndex('student_fees_intelligence_idx'));
        Schema::table('student_fees', fn (Blueprint $table) => $table->dropIndex('student_fees_balance_idx'));
        Schema::table('result_batches', fn (Blueprint $table) => $table->dropIndex('result_batches_published_idx'));
        Schema::table('student_results_v2', fn (Blueprint $table) => $table->dropIndex('student_results_user_batch_idx'));
    }
};
