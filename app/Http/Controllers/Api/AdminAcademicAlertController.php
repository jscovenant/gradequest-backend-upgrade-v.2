<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicAlert;
use App\Models\ResultSubmissionMonitor;
use App\Models\StudentClass;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAcademicAlertController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $auth = $request->user();
        $schoolId = (int) ($auth->school_id ?? 0);

        if (!$schoolId) {
            return response()->json([
                'message' => 'School not found for this user.'
            ], 422);
        }

        if (($auth->role ?? null) !== 'Admin') {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        // =========================
        // Academic alerts
        // =========================
        $alerts = AcademicAlert::query()
            ->where('school_id', $schoolId)
            ->where('status', 'open')
            ->latest()
            ->limit(8)
            ->get();

        $alertClassIds = $alerts->pluck('class_id')->filter()->unique()->values();
        $subjectIds = $alerts->pluck('subject_id')->filter()->unique()->values();
        $studentIds = $alerts->pluck('student_id')->filter()->unique()->values();

        // =========================
        // Result submission monitors
        // =========================
        $monitors = ResultSubmissionMonitor::query()
            ->where('school_id', $schoolId)
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->orderByRaw("
                CASE status
                    WHEN 'overdue' THEN 1
                    WHEN 'partial' THEN 2
                    WHEN 'pending' THEN 3
                    ELSE 4
                END
            ")
            ->orderBy('submission_deadline', 'asc')
            ->limit(8)
            ->get();

        $monitorClassIds = $monitors->pluck('class_id')->filter()->unique()->values();
        $teacherIds = $monitors->pluck('teacher_id')->filter()->unique()->values();

        // Merge class ids from both alerts and monitors
        $classIds = $alertClassIds
            ->merge($monitorClassIds)
            ->filter()
            ->unique()
            ->values();

        $classes = StudentClass::query()
            ->whereIn('id', $classIds)
            ->pluck('name', 'id');

        $subjects = Subject::query()
            ->whereIn('id', $subjectIds)
            ->pluck('name', 'id');

        $students = User::query()
            ->whereIn('id', $studentIds)
            ->get(['id', 'firstname', 'surname'])
            ->mapWithKeys(fn ($u) => [
                $u->id => trim(($u->firstname ?? '') . ' ' . ($u->surname ?? ''))
            ]);

        $teachers = User::query()
            ->whereIn('id', $teacherIds)
            ->get(['id', 'firstname', 'surname'])
            ->mapWithKeys(fn ($u) => [
                $u->id => trim(($u->firstname ?? '') . ' ' . ($u->surname ?? ''))
            ]);

        $alertItems = $alerts->map(function ($a) use ($classes, $subjects, $students) {
            return [
                'id' => $a->id,
                'type' => $a->type,
                'severity' => $a->severity,
                'status' => $a->status,
                'title' => $a->title,
                'message' => $a->message,
                'class_id' => $a->class_id,
                'class_name' => $a->class_id ? ($classes[$a->class_id] ?? null) : null,
                'subject_id' => $a->subject_id,
                'subject_name' => $a->subject_id ? ($subjects[$a->subject_id] ?? null) : null,
                'student_id' => $a->student_id,
                'student_name' => $a->student_id ? ($students[$a->student_id] ?? null) : null,
                'created_at' => optional($a->created_at)?->toDateTimeString(),
            ];
        });

        $monitorItems = $monitors->map(function ($m) use ($classes, $teachers) {
            return [
                'id' => $m->id,
                'batch_id' => $m->batch_id,
                'class_id' => $m->class_id,
                'class_name' => $m->class_id ? ($classes[$m->class_id] ?? 'Unknown Class') : 'Unknown Class',
                'teacher_id' => $m->teacher_id,
                'teacher_name' => $m->teacher_id ? ($teachers[$m->teacher_id] ?? null) : null,
                'expected_students_count' => $m->expected_students_count,
                'completed_students_count' => $m->completed_students_count,
                'pending_students_count' => $m->pending_students_count,
                'status' => $m->status,
                'submission_deadline' => optional($m->submission_deadline)?->toDateString(),
                'last_scanned_at' => optional($m->last_scanned_at)?->toDateTimeString(),
            ];
        });

        return response()->json([
            'data' => $alertItems,
            'submission_monitors' => $monitorItems,
            'counts' => [
                'open_total' => AcademicAlert::query()
                    ->where('school_id', $schoolId)
                    ->where('status', 'open')
                    ->count(),

                'high' => AcademicAlert::query()
                    ->where('school_id', $schoolId)
                    ->where('status', 'open')
                    ->where('severity', 'high')
                    ->count(),

                'medium' => AcademicAlert::query()
                    ->where('school_id', $schoolId)
                    ->where('status', 'open')
                    ->where('severity', 'medium')
                    ->count(),

                'low' => AcademicAlert::query()
                    ->where('school_id', $schoolId)
                    ->where('status', 'open')
                    ->where('severity', 'low')
                    ->count(),

                'submission_open_total' => ResultSubmissionMonitor::query()
                    ->where('school_id', $schoolId)
                    ->whereIn('status', ['pending', 'partial', 'overdue'])
                    ->count(),

                'submission_overdue_total' => ResultSubmissionMonitor::query()
                    ->where('school_id', $schoolId)
                    ->where('status', 'overdue')
                    ->count(),
            ],
        ]);
    }
}