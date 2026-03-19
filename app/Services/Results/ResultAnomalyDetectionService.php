<?php

namespace App\Services\Results;

use App\Models\AcademicAlert;
use App\Models\ResultBatch;
use App\Models\SchoolSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ResultAnomalyDetectionService
{
    public function scanBatch(int $batchId): void
    {
        $batch = ResultBatch::findOrFail($batchId);
        $school = $this->resolveSchoolSettings($batch->school_id);

        if (!$school) {
            Log::warning('Result anomaly scan skipped: school_settings missing', [
                'batch_id' => $batch->id,
                'school_id' => $batch->school_id,
            ]);
            return;
        }

        if (!(bool) $school->enable_result_monitoring) {
            return;
        }

        $this->scanStudentOutliers($batch, $school);
        $this->scanUniformScores($batch, $school);
    }

    private function resolveSchoolSettings(int $schoolId): ?SchoolSetting
    {
        // Primary expectation: school_settings.id == school_id
        $school = SchoolSetting::query()->find($schoolId);

        if ($school) {
            return $school;
        }

        // Fallback: some datasets may have only one settings row for a tenant-like grouping
        // If you later confirm a better relationship, replace this fallback.
        return null;
    }

    private function scanStudentOutliers(ResultBatch $batch, SchoolSetting $school): void
    {
        $currentRows = DB::table('subject_results_v2 as subr')
            ->join('student_results_v2 as sr', 'subr.student_result_id', '=', 'sr.id')
            ->where('sr.batch_id', $batch->id)
            ->whereNotNull('subr.total')
            ->select([
                'subr.id as subject_result_id',
                'subr.subject_id',
                'sr.user_id as student_id',
                DB::raw('CAST(subr.total AS DECIMAL(10,2)) as current_total'),
            ])
            ->get();

        foreach ($currentRows as $row) {
            if ($row->current_total === null) {
                continue;
            }

            $history = DB::table('subject_results_v2 as subr')
                ->join('student_results_v2 as sr', 'subr.student_result_id', '=', 'sr.id')
                ->join('result_batches as rb', 'sr.batch_id', '=', 'rb.id')
                ->where('rb.school_id', $batch->school_id)
                ->where('sr.user_id', $row->student_id)
                ->where('subr.subject_id', $row->subject_id)
                ->where('rb.id', '!=', $batch->id)
                ->whereNotNull('subr.total')
                ->selectRaw('CAST(subr.total AS DECIMAL(10,2)) as total_value')
                ->get()
                ->pluck('total_value')
                ->map(fn ($v) => (float) $v)
                ->filter(fn ($v) => is_numeric($v))
                ->values();

            if ($history->count() < (int) $school->minimum_history_records_for_outlier) {
                $this->resolveOpenAlert(
                    schoolId: $batch->school_id,
                    batchId: $batch->id,
                    type: 'student_outlier',
                    studentId: $row->student_id,
                    subjectId: $row->subject_id
                );
                continue;
            }

            $historicalAverage = round($history->avg(), 2);
            $currentTotal = (float) $row->current_total;
            $drop = round($historicalAverage - $currentTotal, 2);

            $isOutlier = $historicalAverage >= 40
                && $drop >= (float) $school->student_drop_alert_threshold;

            if ($isOutlier) {
                $severity = $drop >= 50 ? 'high' : 'medium';

                $this->createOrUpdateAlert([
                    'school_id' => $batch->school_id,
                    'batch_id' => $batch->id,
                    'class_id' => $batch->class_id,
                    'student_id' => $row->student_id,
                    'subject_id' => $row->subject_id,
                    'type' => 'student_outlier',
                    'severity' => $severity,
                    'title' => 'Unusual score drop detected',
                    'message' => 'A student recorded a significantly lower score than their historical average.',
                    'context_json' => [
                        'historical_average' => $historicalAverage,
                        'current_total' => $currentTotal,
                        'drop' => $drop,
                        'history_count' => $history->count(),
                    ],
                ]);
            } else {
                $this->resolveOpenAlert(
                    schoolId: $batch->school_id,
                    batchId: $batch->id,
                    type: 'student_outlier',
                    studentId: $row->student_id,
                    subjectId: $row->subject_id
                );
            }
        }
    }

    private function scanUniformScores(ResultBatch $batch, SchoolSetting $school): void
    {
        $subjectGroups = DB::table('subject_results_v2 as subr')
            ->join('student_results_v2 as sr', 'subr.student_result_id', '=', 'sr.id')
            ->where('sr.batch_id', $batch->id)
            ->whereNotNull('subr.total')
            ->select([
                'subr.subject_id',
                DB::raw('CAST(subr.total AS DECIMAL(10,2)) as total_value'),
            ])
            ->get()
            ->groupBy('subject_id');

        foreach ($subjectGroups as $subjectId => $rows) {
            $totals = collect($rows)
                ->pluck('total_value')
                ->map(fn ($v) => (float) $v)
                ->filter(fn ($v) => is_numeric($v))
                ->values();

            if ($totals->count() < 10) {
                $this->resolveOpenAlert(
                    schoolId: $batch->school_id,
                    batchId: $batch->id,
                    type: 'uniform_scores',
                    studentId: null,
                    subjectId: (int) $subjectId
                );
                continue;
            }

            $min = (float) $totals->min();
            $max = (float) $totals->max();
            $range = round($max - $min, 2);
            $stddev = round($this->calculateStandardDeviation($totals->all()), 2);

            $isUniform = $range <= (float) $school->uniformity_range_threshold
                && $stddev <= (float) $school->uniformity_stddev_threshold;

            if ($isUniform) {
                $this->createOrUpdateAlert([
                    'school_id' => $batch->school_id,
                    'batch_id' => $batch->id,
                    'class_id' => $batch->class_id,
                    'subject_id' => (int) $subjectId,
                    'type' => 'uniform_scores',
                    'severity' => 'high',
                    'title' => 'Suspiciously uniform scores detected',
                    'message' => 'This subject has an unusually narrow score distribution and should be reviewed before publishing.',
                    'context_json' => [
                        'count' => $totals->count(),
                        'min' => $min,
                        'max' => $max,
                        'range' => $range,
                        'stddev' => $stddev,
                    ],
                ]);
            } else {
                $this->resolveOpenAlert(
                    schoolId: $batch->school_id,
                    batchId: $batch->id,
                    type: 'uniform_scores',
                    studentId: null,
                    subjectId: (int) $subjectId
                );
            }
        }
    }

    private function calculateStandardDeviation(array $values): float
    {
        $count = count($values);
        if ($count <= 1) {
            return 0.0;
        }

        $mean = array_sum($values) / $count;

        $variance = array_sum(
            array_map(fn ($x) => pow($x - $mean, 2), $values)
        ) / $count;

        return sqrt($variance);
    }

    private function createOrUpdateAlert(array $payload): AcademicAlert
    {
        $existing = AcademicAlert::query()
            ->where('school_id', $payload['school_id'])
            ->where('batch_id', $payload['batch_id'] ?? null)
            ->where('type', $payload['type'])
            ->where('student_id', $payload['student_id'] ?? null)
            ->where('subject_id', $payload['subject_id'] ?? null)
            ->whereIn('status', ['open', 'reviewed'])
            ->first();

        if ($existing) {
            $existing->update([
                'severity' => $payload['severity'],
                'title' => $payload['title'],
                'message' => $payload['message'],
                'context_json' => $payload['context_json'] ?? null,
                'status' => 'open',
                'reviewed_by' => null,
                'reviewed_at' => null,
            ]);

            return $existing;
        }

        return AcademicAlert::create([
            'school_id' => $payload['school_id'],
            'batch_id' => $payload['batch_id'] ?? null,
            'class_id' => $payload['class_id'] ?? null,
            'teacher_id' => $payload['teacher_id'] ?? null,
            'student_id' => $payload['student_id'] ?? null,
            'subject_id' => $payload['subject_id'] ?? null,
            'type' => $payload['type'],
            'severity' => $payload['severity'] ?? 'medium',
            'status' => 'open',
            'title' => $payload['title'],
            'message' => $payload['message'],
            'context_json' => $payload['context_json'] ?? null,
        ]);
    }

    private function resolveOpenAlert(
        int $schoolId,
        int $batchId,
        string $type,
        ?int $studentId = null,
        ?int $subjectId = null
    ): void {
        AcademicAlert::query()
            ->where('school_id', $schoolId)
            ->where('batch_id', $batchId)
            ->where('type', $type)
            ->where('student_id', $studentId)
            ->where('subject_id', $subjectId)
            ->whereIn('status', ['open', 'reviewed'])
            ->update([
                'status' => 'resolved',
                'reviewed_at' => now(),
            ]);
    }
}