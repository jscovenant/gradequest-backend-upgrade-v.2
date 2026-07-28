<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'teacher_status')) {
                $table->string('teacher_status', 30)->default('active')->after('student_status')->index();
            }
            if (! Schema::hasColumn('users', 'teacher_status_reason')) {
                $table->text('teacher_status_reason')->nullable()->after('teacher_status');
            }
            if (! Schema::hasColumn('users', 'teacher_status_changed_at')) {
                $table->timestamp('teacher_status_changed_at')->nullable()->after('teacher_status_reason');
            }
            if (! Schema::hasColumn('users', 'teacher_status_changed_by')) {
                $table->unsignedBigInteger('teacher_status_changed_by')->nullable()->after('teacher_status_changed_at');
            }
        });

        DB::table('users')
            ->whereRaw('LOWER(role) = ?', ['teacher'])
            ->whereNull('teacher_status')
            ->update(['teacher_status' => 'active']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'teacher_status')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            foreach (['teacher_status_changed_by', 'teacher_status_changed_at', 'teacher_status_reason', 'teacher_status'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
