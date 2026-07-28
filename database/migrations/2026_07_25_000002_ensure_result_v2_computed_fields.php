<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('subject_results_v2')) {
            Schema::table('subject_results_v2', function (Blueprint $table) {
                if (! Schema::hasColumn('subject_results_v2', 'ca_total')) {
                    $table->decimal('ca_total', 8, 2)->nullable()->after('ca_raw');
                }

                if (! Schema::hasColumn('subject_results_v2', 'subject_position')) {
                    $table->string('subject_position', 20)->nullable()->after('remark');
                }

                if (! Schema::hasColumn('subject_results_v2', 'computed_at')) {
                    $table->timestamp('computed_at')->nullable()->after('signature');
                }
            });
        }

        if (Schema::hasTable('student_results_v2')) {
            Schema::table('student_results_v2', function (Blueprint $table) {
                if (! Schema::hasColumn('student_results_v2', 'total_score')) {
                    $table->decimal('total_score', 10, 2)->nullable()->after('class_size');
                }

                if (! Schema::hasColumn('student_results_v2', 'computed_at')) {
                    $table->timestamp('computed_at')->nullable()->after('meta_json');
                }
            });
        }
    }

    public function down(): void
    {
        //
    }
};
