<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sub_payments')) {
            return;
        }

        Schema::table('sub_payments', function (Blueprint $table) {
            if (! Schema::hasColumn('sub_payments', 'active_students')) {
                $table->unsignedInteger('active_students')->nullable()->after('duration_in_days');
            }

            if (! Schema::hasColumn('sub_payments', 'price_per_student')) {
                $table->decimal('price_per_student', 12, 2)->nullable()->after('active_students');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sub_payments')) {
            return;
        }

        Schema::table('sub_payments', function (Blueprint $table) {
            if (Schema::hasColumn('sub_payments', 'price_per_student')) {
                $table->dropColumn('price_per_student');
            }

            if (Schema::hasColumn('sub_payments', 'active_students')) {
                $table->dropColumn('active_students');
            }
        });
    }
};
