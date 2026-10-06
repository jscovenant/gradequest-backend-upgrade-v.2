<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Average;
use App\Models\ResultBatch;
use App\Models\ResultSubmissionMonitor;
use App\Models\StudentClass;
use App\Models\StudentResultV2;
use App\Models\TeacherEnrollment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class AdminResultDeadlineController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.'
            ], 401);
        }

        $role = strtolower(str_replace([' ', '-'], '_', trim((string) ($user->role ?? ''))));
        $allowedRoles = [
            'admin', 'owner', 'proprietor', 'school_owner', 'school_admin',
            'principal', 'head_teacher', 'headmaster', 'headmistress',
            'operator', 'registrar', 'secretary', 'admin_officer',
            'super_admin', 'platform_staff',
            'teacher', 'class_teacher', 'subject_teacher'
        ];

        if (!in_array($role, $allowedRoles, true)) {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        $schoolId = (int) ($user->school_id ?? 0);

        if (!$schoolId) {
            return response()->json([
                'message' => 'School not found for this user.'
            ], 422);
        }

        $term = $request->filled('term') ? $request->string('term')->toString() : null;
        $session = $request->filled('session') ? $request->string('session')->toString() : null;

        // Auto-ensure result batches exist for any classes that have records in averages table
        if ($term && $session) {
            $classesWithResults = Average::where('school_id', $schoolId)
                ->where('session', $session)
                ->where('term', $term)
                ->select('class_id')
                ->distinct()
                ->pluck('class_id');

            foreach ($classesWithResults as $cId) {
                if ($cId) {
                    ResultBatch::firstOrCreate(
                        [
                            'school_id' => $schoolId,
                            'class_id' => $cId,
                            'term' => $term,
                            'session' => $session,
                        ],
                        [
                            'status' => 'approved',
                        ]
                    );
                }
            }
        }

        $query = ResultBatch::query()
            ->where('school_id', $schoolId);

        // If teacher, strictly restrict to their assigned classes
        $isTeacher = in_array($role, ['teacher', 'class_teacher', 'subject_teacher'], true);
        if ($isTeacher) {
            $assignedClassIds = TeacherEnrollment::where('school_id', $schoolId)
                ->where(function ($q) use ($user) {
                    $q->where('user_id', $user->id);
                    if (Schema::hasColumn('teacher_enrollments', 'teacher_id')) {
                        $q->orWhere('teacher_id', $user->id);
                    }
                })
                ->where('enroll', '1')
                ->pluck('level_id')
                ->filter()
                ->unique()
                ->values()
                ->all();

            $query->whereIn('class_id', $assignedClassIds);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($term) {
            $query->where('term', $term);
        }

        if ($session) {
            $query->where('session', $session);
        }

        $batches = $query
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get([
                'id',
                'school_id',
                'class_id',
                'term',
                'session',
                'status',
                'submission_deadline',
                'created_at',
                'updated_at',
            ]);

        $classIds = $batches->pluck('class_id')->filter()->unique()->values();

        $classMap = StudentClass::query()
            ->whereIn('id', $classIds)
            ->pluck('name', 'id');

        $items = $batches->map(function ($batch) use ($classMap, $schoolId) {
            $totalStudents = User::where('school_id', $schoolId)
                ->where('level_id', $batch->class_id)
                ->whereRaw('LOWER(role) = ?', ['student'])
                ->count();

            $completedStudents = Average::where('school_id', $schoolId)
                ->where('class_id', $batch->class_id)
                ->where('session', $batch->session)
                ->where('term', $batch->term)
                ->count();

            if ($completedStudents === 0) {
                $completedStudents = StudentResultV2::where('batch_id', $batch->id)->count();
            }

            return [
                'id' => $batch->id,
                'school_id' => $batch->school_id,
                'class_id' => $batch->class_id,
                'class_name' => $classMap[$batch->class_id] ?? "Class {$batch->class_id}",
                'term' => $batch->term,
                'session' => $batch->session,
                'status' => $batch->status ?: ($completedStudents > 0 ? 'approved' : 'draft'),
                'submission_deadline' => optional($batch->submission_deadline)?->toDateString(),
                'created_at' => optional($batch->created_at)?->toDateTimeString(),
                'updated_at' => optional($batch->updated_at)?->toDateTimeString(),
                'review' => [
                    'total_students' => $totalStudents,
                    'completed_students' => $completedStudents,
                ],
            ];
        });

        return response()->json([
            'data' => $items,
        ]);
    }

    public function setDeadline(Request $request, int $batch): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.'
            ], 401);
        }

        $role = strtolower(str_replace([' ', '-'], '_', trim((string) ($user->role ?? ''))));
        $adminRoles = ['admin', 'owner', 'proprietor', 'school_owner', 'school_admin', 'principal', 'head_teacher', 'super_admin', 'platform_staff'];

        if (!in_array($role, $adminRoles, true)) {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        $data = $request->validate([
            'submission_deadline' => ['required', 'date', 'after:today']
        ]);

        $batch = ResultBatch::query()
            ->where('id', $batch)
            ->where('school_id', $user->school_id)
            ->firstOrFail();

        $deadline = Carbon::parse($data['submission_deadline'])->endOfDay();

        $batch->update([
            'submission_deadline' => $deadline,
        ]);

        $monitor = ResultSubmissionMonitor::updateOrCreate(
            [
                'school_id' => $batch->school_id,
                'batch_id' => $batch->id,
                'class_id' => $batch->class_id,
            ],
            [
                'teacher_id' => $batch->created_by,
                'term' => $batch->term,
                'session' => $batch->session,
                'submission_deadline' => $deadline,
                'status' => 'pending',
            ]
        );

        return response()->json([
            'message' => 'Submission deadline updated successfully.',
            'data' => [
                'batch_id' => $batch->id,
                'class_id' => $batch->class_id,
                'submission_deadline' => $deadline->toDateString(),
                'monitor_id' => $monitor->id,
            ]
        ]);
    }
}
