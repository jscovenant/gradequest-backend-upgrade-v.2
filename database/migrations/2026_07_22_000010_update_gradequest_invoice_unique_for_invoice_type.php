<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gradequest_term_invoices', function (Blueprint $table) {
            try {
                $table->dropUnique('gq_term_invoice_unique');
            } catch (\Throwable $e) {
                // Existing databases may already have a repaired index.
            }
        });

        Schema::table('gradequest_term_invoices', function (Blueprint $table) {
            try {
                $table->unique(
                    ['school_id', 'session_id', 'term_id', 'billing_mode', 'invoice_type'],
                    'gq_term_invoice_type_unique'
                );
            } catch (\Throwable $e) {
                // Keep migration idempotent across local repair attempts.
            }
        });
    }

    public function down(): void
    {
        Schema::table('gradequest_term_invoices', function (Blueprint $table) {
            try {
                $table->dropUnique('gq_term_invoice_type_unique');
            } catch (\Throwable $e) {
            }
        });

        Schema::table('gradequest_term_invoices', function (Blueprint $table) {
            try {
                $table->unique(['school_id', 'session_id', 'term_id', 'billing_mode'], 'gq_term_invoice_unique');
            } catch (\Throwable $e) {
            }
        });
    }
};
