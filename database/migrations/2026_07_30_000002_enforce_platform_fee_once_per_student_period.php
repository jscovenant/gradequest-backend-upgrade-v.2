<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_fee_charges')) {
            return;
        }

        Schema::table('platform_fee_charges', function (Blueprint $table) {
            if (! Schema::hasColumn('platform_fee_charges', 'school_id')) {
                $table->unsignedBigInteger('school_id')->nullable()->after('student_fee_id');
            }

            if (! Schema::hasColumn('platform_fee_charges', 'student_id')) {
                $table->unsignedBigInteger('student_id')->nullable()->after('school_id');
            }

            if (! Schema::hasColumn('platform_fee_charges', 'session_id')) {
                $table->unsignedBigInteger('session_id')->nullable()->after('student_id');
            }

            if (! Schema::hasColumn('platform_fee_charges', 'term_id')) {
                $table->unsignedBigInteger('term_id')->nullable()->after('session_id');
            }
        });

        DB::table('platform_fee_charges as p')
            ->join('student_fees as sf', 'sf.id', '=', 'p.student_fee_id')
            ->update([
                'p.school_id' => DB::raw('sf.school_id'),
                'p.student_id' => DB::raw('sf.student_id'),
                'p.session_id' => DB::raw('sf.session_id'),
                'p.term_id' => DB::raw('sf.term_id'),
            ]);

        $this->removeDuplicatePeriodClaims();

        Schema::table('platform_fee_charges', function (Blueprint $table) {
            try {
                $table->dropUnique('platform_fee_charges_student_fee_id_unique');
            } catch (Throwable $e) {
                // Older local databases may already have this unique index removed.
            }

            try {
                $table->unique(
                    ['school_id', 'student_id', 'session_id', 'term_id'],
                    'platform_fee_student_period_unique'
                );
            } catch (Throwable $e) {
                // Keep migration idempotent for databases where the index already exists.
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('platform_fee_charges')) {
            return;
        }

        Schema::table('platform_fee_charges', function (Blueprint $table) {
            try {
                $table->dropUnique('platform_fee_student_period_unique');
            } catch (Throwable $e) {
                // Ignore when the index is absent.
            }

            try {
                $table->unique('student_fee_id', 'platform_fee_charges_student_fee_id_unique');
            } catch (Throwable $e) {
                // Ignore if duplicate historical rows prevent restoring the old rule.
            }

            foreach (['term_id', 'session_id', 'student_id', 'school_id'] as $column) {
                if (Schema::hasColumn('platform_fee_charges', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function removeDuplicatePeriodClaims(): void
    {
        $rows = DB::table('platform_fee_charges')
            ->whereNotNull('school_id')
            ->whereNotNull('student_id')
            ->whereNotNull('session_id')
            ->whereNotNull('term_id')
            ->orderByRaw("CASE WHEN status = 'confirmed' THEN 0 ELSE 1 END")
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        $seen = [];

        foreach ($rows as $row) {
            $key = implode(':', [$row->school_id, $row->student_id, $row->session_id, $row->term_id]);

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                continue;
            }

            DB::table('platform_fee_charges')->where('id', $row->id)->delete();
        }
    }
};
