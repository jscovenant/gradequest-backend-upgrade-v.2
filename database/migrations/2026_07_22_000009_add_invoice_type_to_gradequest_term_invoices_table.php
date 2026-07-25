<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gradequest_term_invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('gradequest_term_invoices', 'invoice_type')) {
                $table->string('invoice_type')->default('term_invoice')->after('billing_mode');
                $table->index(['school_id', 'invoice_type', 'status'], 'gq_invoice_type_status_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('gradequest_term_invoices', function (Blueprint $table) {
            if (Schema::hasColumn('gradequest_term_invoices', 'invoice_type')) {
                $table->dropIndex('gq_invoice_type_status_idx');
                $table->dropColumn('invoice_type');
            }
        });
    }
};
