<?php

namespace App\Services\Results;

use App\Models\ResultBatch;
use App\Models\ResultSubmissionMonitor;
use App\Models\TeacherEnrollment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ResultSubmissionMonitorService
{
    public function __construct(
        private SubjectService $subjectService
    ) {}

    public function refreshBatch(int $batchId): ResultSubmissionMonitor
    {
        $batch = ResultBatch::findOrFail($batchId);

        $teacherId = TeacherEnrollment::query()
            ->where('school_id', $batch->school_id)
            ->where('level_id', $batch->class_id)
            ->where('enroll', 1)
            ->value('user_id');

        $students = User::query()
            ->where('school_id', $batch->school_id)
            ->where('role', 'Student')
            ->where('level_id', $batch->class_id)
            ->where('status', 1)
            ->get();

        $expectedStudents = $students->count();
        $completedStudents = 0;
        $expectedSubjectRows = 0;
        $completedSubjectRows = 0;

        $studentBreakdown = [];

        foreach ($students as $student) {
            $expectedSubjects = $this->getExpectedSubjects($batch->school_id, $student->department_id);
            $expectedCount = $expectedSubjects->count();
            $expectedSubjectRows += $expectedCount;

            $studentResult = DB::table('student_results_v2')
                ->where('batch_id', $batch->id)
                ->where('user_id', $student->id)
                ->first();

            if (!$studentResult) {
                $studentBreakdown[] = [
                    'student_id' => $student->id,
                    'student_name' => trim(($student->firstname ?? '') . ' ' . ($student->surname ?? '')),
                    'expected_subjects' => $expectedCount,
                    'filled_subjects' => 0,
                    'complete' => false,
                    'reason' => 'student_result_missing',
                ];
                continue;
            }

            $subjectRows = DB::table('subject_results_v2')
                ->where('student_result_id', $studentResult->id)
                ->get();

            $filledRows = $subjectRows->filter(function ($row) {
                return $this->rowHasMeaningfulScore($row);
            });

            $filledCount = $filledRows->count();
            $completedSubjectRows += $filledCount;

            $isComplete = $expectedCount > 0 && $filledCount >= $expectedCount;

            if ($isComplete) {
                $completedStudents++;
            }

            $studentBreakdown[] = [
                'student_id' => $student->id,
                'student_name' => trim(($student->firstname ?? '') . ' ' . ($student->surname ?? '')),
                'expected_subjects' => $expectedCount,
                'filled_subjects' => $filledCount,
                'complete' => $isComplete,
                'reason' => $isComplete ? null : 'subject_rows_incomplete',
            ];
        }

        $pendingStudents = max(0, $expectedStudents - $completedStudents);

        $status = 'pending';
        if ($expectedStudents > 0 && $completedStudents === $expectedStudents) {
            $status = 'complete';
        } elseif ($completedStudents > 0) {
            $status = 'partial';
        }

        if ($batch->submission_deadline && now()->toDateString() > $batch->submission_deadline && $status !== 'complete') {
            $status = 'overdue';
        }

        return ResultSubmissionMonitor::updateOrCreate(
            ['batch_id' => $batch->id],
            [
                'school_id' => $batch->school_id,
                'class_id' => $batch->class_id,
                'teacher_id' => $teacherId,
                'term' => $batch->term,
                'session' => $batch->session,
                'expected_students_count' => $expectedStudents,
                'completed_students_count' => $completedStudents,
                'pending_students_count' => $pendingStudents,
                'expected_subject_rows_count' => $expectedSubjectRows,
                'completed_subject_rows_count' => $completedSubjectRows,
                'submission_deadline' => $batch->submission_deadline,
                'status' => $status,
                'last_scanned_at' => now(),
                'meta_json' => [
                    'batch_status' => $batch->status,
                    'student_breakdown' => $studentBreakdown,
                ],
            ]
        );
    }

    private function getExpectedSubjects(int $schoolId, ?int $departmentId): Collection
    {
        if (!$departmentId) {
            return collect();
        }

        $subjects = $this->subjectService->subjectsForDepartment($schoolId, $departmentId);

        return $subjects instanceof Collection ? $subjects : collect($subjects);
    }

    private function rowHasMeaningfulScore(object $row): bool
    {
        $hasExam = $this->hasNumericLikeValue($row->exam ?? null);
        $hasTotal = $this->hasNumericLikeValue($row->total ?? null);
        $hasCa = !empty($row->ca_raw);

        return $hasExam || $hasTotal || $hasCa;
    }

    private function hasNumericLikeValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return false;
        }

        return is_numeric($value);
    }
}