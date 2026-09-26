<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('gradequest_term_invoices')) {
            // Modify status column to varchar(30) to allow 'waived', 'cancelled', etc.
            DB::statement("ALTER TABLE `gradequest_term_invoices` MODIFY COLUMN `status` VARCHAR(30) NOT NULL DEFAULT 'issued'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('gradequest_term_invoices')) {
            DB::statement("ALTER TABLE `gradequest_term_invoices` MODIFY COLUMN `status` ENUM('draft','issued','partial','paid','overdue','cancelled') NOT NULL DEFAULT 'issued'");
        }
    }
};
