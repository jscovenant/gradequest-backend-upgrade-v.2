<?php

namespace App\Services;

use App\Models\StudentFee;
use Illuminate\Database\Eloquent\Builder;

class RevenueIntelligenceService
{
    public function metrics(int $schoolId, ?int $sessionId = null, ?int $termId = null, ?int $classId = null): array
    {
        $query = StudentFee::query()
            ->where('school_id', $schoolId)
            ->when($sessionId, fn (Builder $q) => $q->where('session_id', $sessionId))
            ->when($termId, fn (Builder $q) => $q->where('term_id', $termId))
            ->when($classId, fn (Builder $q) => $q->whereHas('student', fn (Builder $students) => $students->where('level_id', $classId)));

        $totals = (clone $query)->selectRaw(
            'COALESCE(SUM(total_amount), 0) expected_revenue, '
            . 'COALESCE(SUM(amount_paid), 0) collected_revenue, '
            . 'COALESCE(SUM(balance), 0) outstanding_revenue, '
            . 'COUNT(DISTINCT student_id) billed_students'
        )->first();

        $overdue = (clone $query)
            ->where('balance', '>', 0)
            ->whereHas('term', fn (Builder $terms) => $terms->whereNotNull('end_date')->whereDate('end_date', '<', today()))
            ->sum('balance');

        $expected = (float) $totals->expected_revenue;
        $collected = (float) $totals->collected_revenue;

        return [
            'expected_revenue' => round($expected, 2),
            'collected_revenue' => round($collected, 2),
            'outstanding_revenue' => round((float) $totals->outstanding_revenue, 2),
            'overdue_revenue' => round((float) $overdue, 2),
            'collection_rate' => $expected > 0 ? round(($collected / $expected) * 100, 2) : 0.0,
            'billed_students' => (int) $totals->billed_students,
        ];
    }
}
