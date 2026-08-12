<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subjects')) {
            return;
        }

        if (! Schema::hasColumn('subjects', 'department_id')) {
            Schema::table('subjects', function ($table) {
                $table->unsignedBigInteger('department_id')->nullable()->index()->after('section_id');
            });

            return;
        }

        $column = collect(DB::select("SHOW COLUMNS FROM subjects LIKE 'department_id'"))->first();

        if (! $column || strtoupper((string) ($column->Null ?? '')) === 'YES') {
            return;
        }

        $type = (string) ($column->Type ?? 'bigint(20) unsigned');
        $default = property_exists($column, 'Default') && $column->Default !== null
            ? " DEFAULT '" . addslashes((string) $column->Default) . "'"
            : '';

        DB::statement("ALTER TABLE subjects MODIFY department_id {$type} NULL{$default}");
    }

    public function down(): void
    {
        // Intentionally not reverting to NOT NULL because existing general
        // subjects depend on NULL department_id.
    }
};
