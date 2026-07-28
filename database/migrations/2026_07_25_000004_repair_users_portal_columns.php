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

        $this->makeLegacyIdentityColumnsNullable();

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'firstname')) {
                $table->string('firstname')->nullable();
            }
            if (! Schema::hasColumn('users', 'surname')) {
                $table->string('surname')->nullable();
            }
            if (! Schema::hasColumn('users', 'third_name')) {
                $table->string('third_name')->nullable();
            }
            if (! Schema::hasColumn('users', 'reg_no')) {
                $table->string('reg_no')->nullable()->unique();
            }
            if (! Schema::hasColumn('users', 'school_id')) {
                $table->unsignedBigInteger('school_id')->nullable()->index();
            }
            if (! Schema::hasColumn('users', 'dob')) {
                $table->date('dob')->nullable();
            }
            if (! Schema::hasColumn('users', 'address')) {
                $table->string('address')->nullable();
            }
            if (! Schema::hasColumn('users', 'sex')) {
                $table->string('sex', 20)->nullable();
            }
            if (! Schema::hasColumn('users', 'level_id')) {
                $table->unsignedBigInteger('level_id')->nullable()->index();
            }
            if (! Schema::hasColumn('users', 'section_id')) {
                $table->unsignedBigInteger('section_id')->nullable()->index();
            }
            if (! Schema::hasColumn('users', 'department_id')) {
                $table->unsignedBigInteger('department_id')->nullable()->index();
            }
            if (! Schema::hasColumn('users', 'default_password')) {
                $table->text('default_password')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            foreach ([
                'default_password',
                'department_id',
                'section_id',
                'level_id',
                'sex',
                'address',
                'dob',
                'school_id',
                'reg_no',
                'third_name',
                'surname',
                'firstname',
            ] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function makeLegacyIdentityColumnsNullable(): void
    {
        foreach (['name', 'email', 'username'] as $column) {
            if (Schema::hasColumn('users', $column)) {
                DB::statement("ALTER TABLE `users` MODIFY `{$column}` VARCHAR(255) NULL");
            }
        }
    }
};
