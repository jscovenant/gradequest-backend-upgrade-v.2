<?php

namespace App\Services\Results;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ResultComputeService
{
    public function computeBatch(int $batchId): array
    {
        $batch = DB::table('result_batches')->where('id', $batchId)->first();
        if (! $batch) {
            throw ValidationException::withMessages(['batch' => 'Result batch not found.']);
        }

        $studentResults = DB::table('student_results_v2 as sr')
            ->join('users as u', 'u.id', '=', 'sr.user_id')
            ->leftJoin('sections as sec', 'sec.id', '=', 'u.section_id')
            ->where('sr.batch_id', $batchId)
            ->select([
                'sr.id',
                'sr.user_id',
                'sr.general_remark',
                'u.section_id',
                'sec.name as section_name',
            ])
            ->get();

        if ($studentResults->isEmpty()) {
            DB::table('result_batches')
                ->where('id', $batchId)
                ->update(['status' => 'computed', 'updated_at' => now()]);

            return [
                'batch_id' => $batchId,
                'status' => 'computed',
                'class_size' => 0,
                'computed_students' => 0,
                'computed_subject_rows' => 0,
            ];
        }

        $subjectRows = DB::table('subject_results_v2')
            ->whereIn('student_result_id', $studentResults->pluck('id')->all())
            ->get();

        $assessmentScores = $subjectRows->isEmpty()
            ? collect()
            : DB::table('assessment_scores_v2')
                ->whereIn('subject_result_id', $subjectRows->pluck('id')->all())
                ->select('subject_result_id', DB::raw('SUM(score) as ca_total'))
                ->groupBy('subject_result_id')
                ->pluck('ca_total', 'subject_result_id');

        $gradingRules = $this->gradingRules((int) $batch->school_id);
        $studentsByResultId = $studentResults->keyBy('id');
        $totalsByStudentResult = [];
        $subjectScores = [];
        $computedSubjectRows = 0;

        DB::transaction(function () use (
            $batch,
            $studentResults,
            $assessmentScores,
            $subjectRows,
            $gradingRules,
            $studentsByResultId,
            &$totalsByStudentResult,
            &$subjectScores,
            &$computedSubjectRows
        ) {
            foreach ($subjectRows as $row) {
                $student = $studentsByResultId->get($row->student_result_id);
                $caTotal = $assessmentScores->has($row->id)
                    ? $this->toFloat($assessmentScores->get($row->id))
                    : $this->sumCaRaw($row->ca_raw);

                $exam = $this->toFloat($row->exam);
                $total = round($caTotal + $exam, 2);
                $grade = $this->resolveGrade($total, $student?->section_name, $gradingRules);

                DB::table('subject_results_v2')
                    ->where('id', $row->id)
                    ->update([
                        'ca_total' => $caTotal,
                        'exam' => $exam,
                        'total' => $total,
                        'grade' => $grade['grade'],
                        'remark' => $grade['remark'],
                        'computed_at' => now(),
                        'updated_at' => now(),
                    ]);

                $totalsByStudentResult[$row->student_result_id][] = $total;
                $subjectScores[(int) $row->subject_id][$row->id] = $total;
                $computedSubjectRows++;
            }

            $classSize = $this->classSize((int) $batch->school_id, (int) $batch->class_id);
            $overallScores = [];

            foreach ($studentResults as $studentResult) {
                $scores = $totalsByStudentResult[$studentResult->id] ?? [];
                $totalScore = round(array_sum($scores), 2);
                $average = count($scores) > 0 ? round($totalScore / count($scores), 2) : 0.0;
                $grade = $this->resolveGrade($average, $studentResult->section_name, $gradingRules);

                $overallScores[$studentResult->id] = $average;

                DB::table('student_results_v2')
                    ->where('id', $studentResult->id)
                    ->update([
                        'class_size' => (string) $classSize,
                        'total_score' => $totalScore,
                        'total_average' => $average,
                        'total_grade' => $grade['grade'],
                        'general_remark' => $studentResult->general_remark ?: $grade['remark'],
                        'computed_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            foreach ($this->rankScores($overallScores) as $studentResultId => $position) {
                DB::table('student_results_v2')
                    ->where('id', $studentResultId)
                    ->update([
                        'position' => (string) $position,
                        'updated_at' => now(),
                    ]);
            }

            foreach ($subjectScores as $scores) {
                foreach ($this->rankScores($scores) as $subjectResultId => $position) {
                    DB::table('subject_results_v2')
                        ->where('id', $subjectResultId)
                        ->update([
                            'subject_position' => (string) $position,
                            'updated_at' => now(),
                        ]);
                }
            }

            DB::table('result_batches')
                ->where('id', $batch->id)
                ->update([
                    'status' => 'computed',
                    'updated_at' => now(),
                ]);
        });

        return [
            'batch_id' => $batchId,
            'status' => 'computed',
            'class_size' => $this->classSize((int) $batch->school_id, (int) $batch->class_id),
            'computed_students' => $studentResults->count(),
            'computed_subject_rows' => $computedSubjectRows,
        ];
    }

    private function gradingRules(int $schoolId): array
    {
        return [
            'junior' => $this->rulesFromTable('grading_for_juniors', $schoolId),
            'senior' => $this->rulesFromTable('grading_for_seniors', $schoolId),
            'default' => $this->rulesFromTable('grade_settings'),
        ];
    }

    private function rulesFromTable(string $table, ?int $schoolId = null): Collection
    {
        if (! Schema::hasTable($table)) {
            return collect();
        }

        return DB::table($table)
            ->when($schoolId !== null && Schema::hasColumn($table, 'school_id'), fn ($query) => $query->where('school_id', $schoolId))
            ->orderByRaw('CAST(min AS DECIMAL(8,2)) DESC')
            ->get();
    }

    private function resolveGrade(float $score, ?string $sectionName, array $gradingRules): array
    {
        $section = strtolower((string) $sectionName);
        $rules = str_contains($section, 'senior')
            ? $gradingRules['senior']
            : (str_contains($section, 'junior') ? $gradingRules['junior'] : collect());

        if ($rules instanceof Collection && $rules->isEmpty()) {
            $rules = $gradingRules['default'];
        }

        foreach ($rules as $rule) {
            $min = $this->toFloat($rule->min);
            $max = $this->toFloat($rule->max);

            if ($score >= $min && $score <= $max) {
                return [
                    'grade' => (string) $rule->grade,
                    'remark' => (string) $rule->remark,
                ];
            }
        }

        return $this->defaultGrade($score);
    }

    private function defaultGrade(float $score): array
    {
        return match (true) {
            $score >= 70 => ['grade' => 'A', 'remark' => 'Excellent'],
            $score >= 60 => ['grade' => 'B', 'remark' => 'Very Good'],
            $score >= 50 => ['grade' => 'C', 'remark' => 'Good'],
            $score >= 45 => ['grade' => 'D', 'remark' => 'Fair'],
            $score >= 40 => ['grade' => 'E', 'remark' => 'Pass'],
            default => ['grade' => 'F', 'remark' => 'Fail'],
        };
    }

    private function sumCaRaw($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_numeric($value)) {
            return round((float) $value, 2);
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (! is_array($decoded)) {
            return 0.0;
        }

        return round(collect($decoded)->flatten()->filter(fn ($score) => is_numeric($score))->sum(fn ($score) => (float) $score), 2);
    }

    private function toFloat($value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function classSize(int $schoolId, int $classId): int
    {
        return DB::table('users')
            ->where('school_id', $schoolId)
            ->where('role', 'Student')
            ->where('level_id', $classId)
            ->count();
    }

    private function rankScores(array $scores): array
    {
        arsort($scores, SORT_NUMERIC);

        $positions = [];
        $rowNumber = 0;
        $rank = 0;
        $lastScore = null;

        foreach ($scores as $id => $score) {
            $rowNumber++;
            if ($lastScore === null || (float) $score !== (float) $lastScore) {
                $rank = $rowNumber;
            }

            $positions[$id] = $rank;
            $lastScore = $score;
        }

        return $positions;
    }
}
