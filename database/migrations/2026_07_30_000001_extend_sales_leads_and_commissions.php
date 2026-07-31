<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_rep_assignments', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_rep_assignments', 'prospect_school_name')) {
                $table->string('prospect_school_name')->nullable()->after('admin_user_id');
            }
            if (! Schema::hasColumn('sales_rep_assignments', 'contact_name')) {
                $table->string('contact_name')->nullable()->after('prospect_school_name');
            }
            if (! Schema::hasColumn('sales_rep_assignments', 'contact_email')) {
                $table->string('contact_email')->nullable()->after('contact_name');
            }
            if (! Schema::hasColumn('sales_rep_assignments', 'contact_phone')) {
                $table->string('contact_phone')->nullable()->after('contact_email');
            }
            if (! Schema::hasColumn('sales_rep_assignments', 'location')) {
                $table->string('location')->nullable()->after('contact_phone');
            }
            if (! Schema::hasColumn('sales_rep_assignments', 'expected_students')) {
                $table->unsignedInteger('expected_students')->nullable()->after('location');
            }
        });

        Schema::table('sales_commissions', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_commissions', 'source')) {
                $table->string('source')->default('subscription')->after('sub_payment_id');
            }
            if (! Schema::hasColumn('sales_commissions', 'reference')) {
                $table->string('reference')->nullable()->after('source');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_commissions', function (Blueprint $table) {
            foreach (['reference', 'source'] as $column) {
                if (Schema::hasColumn('sales_commissions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('sales_rep_assignments', function (Blueprint $table) {
            foreach ([
                'expected_students',
                'location',
                'contact_phone',
                'contact_email',
                'contact_name',
                'prospect_school_name',
            ] as $column) {
                if (Schema::hasColumn('sales_rep_assignments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
