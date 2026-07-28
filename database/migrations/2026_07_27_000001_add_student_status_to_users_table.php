<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'student_status')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('student_status', 30)->default('active')->after('status')->index();
            $table->timestamp('student_status_changed_at')->nullable()->after('student_status');
            $table->unsignedBigInteger('student_status_changed_by')->nullable()->after('student_status_changed_at')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'student_status')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            foreach (['student_status_changed_by', 'student_status_changed_at', 'student_status'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
