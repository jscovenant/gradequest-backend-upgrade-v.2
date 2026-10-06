<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StudentDashboardController extends Controller
{
    public function dashboard(Request $request)
    {
        $student = Auth::user();

        if (!$student || (!$student->isStudent() && strcasecmp((string) $student->role, 'student') !== 0)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $schoolId = (int) $student->school_id;
        $studentId = (int) $student->id;

        // =========================
        // Profile / Class / Level
        // =========================
        $classInfo = DB::table('student_classes')
            ->where('id', $student->level_id ?? null)
            ->first();

        // =========================
        // Fees Summary (from student_fees)
        // =========================
        $feeAgg = DB::table('student_fees')
            ->where('student_id', $studentId)
            ->selectRaw('
                COALESCE(SUM(total_amount), 0) as total_fees,
                COALESCE(SUM(amount_paid), 0) as total_paid,
                COALESCE(SUM(balance), 0) as balance
            ')
            ->first();

        $lastPaymentDate = DB::table('student_fees')
            ->where('student_id', $studentId)
            ->latest('updated_at')
            ->value('updated_at');

        // =========================
        // Subjects Enrolled Count
        // =========================
        $subjectsCount = DB::table('subject_enrolls')
            ->where('user_id', $studentId)
            ->count();

        if ($subjectsCount === 0) {
            try {
                $subjects = app(\App\Services\Results\SubjectService::class)->subjectsForStudent($student);
                $subjectsCount = $subjects->count();
            } catch (\Throwable $e) {
                if ($student->department_id) {
                    $subjectsCount = DB::table('subjects')
                        ->where('school_id', $schoolId)
                        ->where('department_id', $student->department_id)
                        ->count();
                }
                if ($subjectsCount === 0) {
                    $subjectsCount = DB::table('subjects')
                        ->where('school_id', $schoolId)
                        ->count();
                }
            }
        }

        // =========================
        // Attendance
        // =========================
        $attendanceAgg = DB::table('attendances')
            ->where('student_id', $studentId)
            ->where('school_id', $schoolId)
            ->selectRaw("
                SUM(status = 'present') as present_days,
                SUM(status = 'absent') as absent_days,
                SUM(status = 'late') as late_days,
                SUM(status = 'excused') as excused_days
            ")
            ->first();

        $presentDays = (int) ($attendanceAgg->present_days ?? 0);
        $absentDays  = (int) ($attendanceAgg->absent_days ?? 0);
        $lateDays    = (int) ($attendanceAgg->late_days ?? 0);
        $excusedDays = (int) ($attendanceAgg->excused_days ?? 0);

        $totalMarked = $presentDays + $absentDays + $lateDays + $excusedDays;
        $effectivePresent = $presentDays + $lateDays;

        $attendanceRate = $totalMarked > 0
            ? round(($effectivePresent / $totalMarked) * 100, 1)
            : 0;

        // Fallback to averages attendance if attendances table is empty
        if ($attendanceRate == 0) {
            $latestAvgAttendance = DB::table('averages')
                ->where('user_id', $studentId)
                ->where('school_id', $schoolId)
                ->orderByDesc('id')
                ->first();
            if ($latestAvgAttendance) {
                $present = (float) ($latestAvgAttendance->no_present ?? 0);
                $absent = (float) ($latestAvgAttendance->no_absent ?? 0);
                $open = (float) ($latestAvgAttendance->school_open ?? ($present + $absent));
                if ($open > 0) {
                    $attendanceRate = round(($present / $open) * 100, 1);
                } elseif (($present + $absent) > 0) {
                    $attendanceRate = round(($present / ($present + $absent)) * 100, 1);
                }
            }
        }

        // =========================
        // Result availability / performance chart
        // =========================
        $perfRows = DB::table('subject_results_v2 as sr')
            ->join('student_results_v2 as r', 'r.id', '=', 'sr.student_result_id')
            ->join('result_batches as b', 'b.id', '=', 'r.batch_id')
            ->where('r.user_id', $studentId)
            ->selectRaw("
                CONCAT(b.session, ' - ', b.term) as label,
                AVG(COALESCE(NULLIF(sr.total, ''), 0) + 0) as average
            ")
            ->groupBy('label')
            ->orderByRaw('MIN(r.created_at) DESC')
            ->limit(6)
            ->get()
            ->reverse()
            ->values();

        $legacyAverages = DB::table('averages')
            ->where('user_id', $studentId)
            ->where('school_id', $schoolId)
            ->orderBy('id', 'asc')
            ->get();

        $legacyPerf = $legacyAverages->map(function ($a) {
            return [
                'label' => trim(($a->session ?? '') . ' - ' . ($a->term ?? '')),
                'average' => (float) ($a->total_average ?? 0),
            ];
        })->filter(fn($x) => !empty($x['label']))->values();

        if ($perfRows->isEmpty() && $legacyPerf->isNotEmpty()) {
            $perfRows = $legacyPerf;
        }

        // =========================
        // Result checks access analytics
        // =========================
        $accessRows = DB::table('activity_logs')
            ->where('user_id', $studentId)
            ->where('school_id', $schoolId)
            ->whereIn('action', ['result_view', 'view_result', 'result_checked'])
            ->where('created_at', '>=', now()->subDays(7))
            ->selectRaw("DAYNAME(created_at) as day_name, COUNT(*) as total")
            ->groupBy('day_name')
            ->get();

        $dayOrder = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
        $accessMap = [];
        foreach ($accessRows as $r) {
            $accessMap[$r->day_name] = (int) $r->total;
        }

        $accessLabels = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
        $accessData = [];
        foreach ($dayOrder as $dayName) {
            $accessData[] = $accessMap[$dayName] ?? 0;
        }

        // =========================
        // Unread notifications
        // =========================
        $unreadCount = DB::table('notifications')
            ->where('notifiable_id', $studentId)
            ->whereNull('read_at')
            ->count();

        $recentNotifications = DB::table('notifications')
            ->where('notifiable_id', $studentId)
            ->latest('created_at')
            ->limit(5)
            ->get(['id', 'type', 'data', 'read_at', 'created_at']);

        // =========================
        // Next timetable class
        // =========================
        $classId = (int) ($student->level_id ?? 0);
        $todayName = now()->format('l');
        $schoolDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        $targetDay = in_array($todayName, $schoolDays) ? $todayName : 'Monday';

        $nextClass = null;
        if ($classId) {
            $tt = DB::table('timetables')
                ->where('school_id', $schoolId)
                ->where('class_id', $classId)
                ->where('day', $targetDay)
                ->orderBy('period_number', 'asc')
                ->first();
            if ($tt) {
                $nextClass = [
                    'id' => $tt->id,
                    'subject_name' => $tt->subject,
                    'subject' => $tt->subject,
                    'period_number' => $tt->period_number,
                    'day' => $tt->day,
                    'start_time' => 'Period ' . $tt->period_number . ($todayName !== $targetDay ? ' (' . $tt->day . ')' : ''),
                    'end_time' => '',
                    'venue' => $classInfo?->name ?? 'Classroom',
                ];
            }
        }

        // =========================
        // Core stats cards
        // =========================
        $resultsCount = DB::table('student_results_v2')
            ->where('user_id', $studentId)
            ->count();

        if ($resultsCount === 0) {
            $resultsCount = $legacyAverages->count();
        }

        $averageAllTime = DB::table('subject_results_v2 as sr')
            ->join('student_results_v2 as r', 'r.id', '=', 'sr.student_result_id')
            ->join('result_batches as b', 'b.id', '=', 'r.batch_id')
            ->where('r.user_id', $studentId)
            ->where('b.school_id', $schoolId)
            ->avg(DB::raw("COALESCE(sr.cumulative_average, COALESCE(NULLIF(sr.total,''),0) + 0)"));

        $averageAllTime = $averageAllTime ? round((float) $averageAllTime, 1) : 0;

        if ($averageAllTime == 0 && $legacyAverages->isNotEmpty()) {
            $averageAllTime = round((float) $legacyAverages->avg('total_average'), 1);
        }

        $currentSession = DB::table('academic_sessions')
            ->where('school_id', $schoolId)
            ->where('is_current', 1)
            ->orderByDesc('id')
            ->first();

        $currentTerm = DB::table('terms')
            ->where('school_id', $schoolId)
            ->where('status', 'Active')
            ->orderByRaw('COALESCE(sort_order, 999999) ASC')
            ->orderBy('id')
            ->first();

        $currentResult = null;
        if ($currentSession && $currentTerm && $classId) {
            $batch = DB::table('result_batches')
                ->where('school_id', $schoolId)
                ->where('class_id', $classId)
                ->where('session', $currentSession->name)
                ->where('term', $currentTerm->name)
                ->first();

            if ($batch) {
                $studentResult = DB::table('student_results_v2')
                    ->where('batch_id', $batch->id)
                    ->where('user_id', $studentId)
                    ->first();

                $currentResult = [
                    'batch_id' => $batch->id,
                    'school_id' => $schoolId,
                    'student_id' => $studentId,
                    'class_id' => $classId,
                    'class_name' => $classInfo?->name,
                    'term' => $currentTerm->name,
                    'session' => $currentSession->name,
                    'status' => $batch->status,
                    'is_published' => strtolower((string) $batch->status) === 'published',
                    'has_result' => (bool) $studentResult,
                    'average' => $studentResult?->total_average,
                    'grade' => $studentResult?->total_grade,
                    'position' => $studentResult?->position,
                    'updated_at' => $studentResult?->updated_at ?? $batch->updated_at,
                ];
            }
        }

        // Fallback to legacy averages if no v2 current result
        if (!$currentResult || !$currentResult['has_result']) {
            $legacyCurrent = null;
            if ($currentSession && $currentTerm) {
                $legacyCurrent = DB::table('averages')
                    ->where('school_id', $schoolId)
                    ->where('user_id', $studentId)
                    ->where('session', $currentSession->name)
                    ->where('term', $currentTerm->name)
                    ->first();
            }
            if (!$legacyCurrent) {
                $legacyCurrent = DB::table('averages')
                    ->where('school_id', $schoolId)
                    ->where('user_id', $studentId)
                    ->orderByDesc('id')
                    ->first();
            }

            if ($legacyCurrent) {
                $currentResult = [
                    'batch_id' => null,
                    'school_id' => $schoolId,
                    'student_id' => $studentId,
                    'class_id' => (int) ($legacyCurrent->class_id ?? $classId),
                    'class_name' => $classInfo?->name,
                    'term' => $legacyCurrent->term ?? ($currentTerm?->name ?? 'Current Term'),
                    'session' => $legacyCurrent->session ?? ($currentSession?->name ?? 'Current Session'),
                    'status' => 'published',
                    'is_published' => true,
                    'has_result' => true,
                    'average' => $legacyCurrent->total_average,
                    'grade' => $legacyCurrent->total_grade,
                    'position' => $legacyCurrent->position,
                    'updated_at' => $legacyCurrent->updated_at,
                ];
            } else {
                $currentResult = [
                    'school_id' => $schoolId,
                    'student_id' => $studentId,
                    'class_id' => $classId,
                    'class_name' => $classInfo?->name,
                    'term' => $currentTerm?->name ?? 'Current Term',
                    'session' => $currentSession?->name ?? 'Current Session',
                    'status' => 'not_started',
                    'is_published' => false,
                    'has_result' => false,
                ];
            }
        }

        // =========================
        // Latest published result
        // =========================
        $latestPublishedResultPayload = null;
        $latestPublishedResultQuery = DB::table('student_results_v2 as sr')
            ->join('result_batches as b', 'b.id', '=', 'sr.batch_id')
            ->where('sr.user_id', $studentId)
            ->where('b.school_id', $schoolId)
            ->whereRaw('LOWER(COALESCE(b.status, "")) = ?', ['published'])
            ->orderByDesc(DB::raw('COALESCE(b.published_at, b.updated_at)'))
            ->orderByDesc('b.id');

        if (Schema::hasTable('student_classes')) {
            $latestPublishedResultQuery->leftJoin('student_classes as sc', 'sc.id', '=', 'b.class_id')
                ->select(
                    'b.id as batch_id',
                    'b.school_id',
                    'sr.user_id as student_id',
                    'b.class_id',
                    'sc.name as class_name',
                    'b.term',
                    'b.session',
                    'b.status',
                    'b.published_at',
                    'b.updated_at',
                    'sr.total_average as average',
                    'sr.total_grade as grade',
                    'sr.position'
                );
        } else {
            $latestPublishedResultQuery->select(
                'b.id as batch_id',
                'b.school_id',
                'sr.user_id as student_id',
                'b.class_id',
                'b.term',
                'b.session',
                'b.status',
                'b.published_at',
                'b.updated_at',
                'sr.total_average as average',
                'sr.total_grade as grade',
                'sr.position'
            );
        }

        $latestPublishedResult = $latestPublishedResultQuery->first();

        if ($latestPublishedResult) {
            $latestPublishedResultPayload = [
                'batch_id' => $latestPublishedResult->batch_id,
                'school_id' => $latestPublishedResult->school_id,
                'student_id' => $latestPublishedResult->student_id,
                'class_id' => $latestPublishedResult->class_id,
                'class_name' => $latestPublishedResult->class_name ?? null,
                'term' => $latestPublishedResult->term,
                'session' => $latestPublishedResult->session,
                'status' => $latestPublishedResult->status,
                'is_published' => true,
                'has_result' => true,
                'average' => $latestPublishedResult->average,
                'grade' => $latestPublishedResult->grade,
                'position' => $latestPublishedResult->position,
                'updated_at' => $latestPublishedResult->published_at ?? $latestPublishedResult->updated_at,
            ];
        } elseif ($legacyAverages->isNotEmpty()) {
            $latestLegacy = $legacyAverages->last();
            $latestPublishedResultPayload = [
                'batch_id' => null,
                'school_id' => $schoolId,
                'student_id' => $studentId,
                'class_id' => (int) ($latestLegacy->class_id ?? $classId),
                'class_name' => $classInfo?->name,
                'term' => $latestLegacy->term,
                'session' => $latestLegacy->session,
                'status' => 'published',
                'is_published' => true,
                'has_result' => true,
                'average' => $latestLegacy->total_average,
                'grade' => $latestLegacy->total_grade,
                'position' => $latestLegacy->position,
                'updated_at' => $latestLegacy->updated_at,
            ];
        }

        return response()->json([
            'student' => [
                'id' => $studentId,
                'name' => trim(($student->surname ?? '') . ' ' . ($student->firstname ?? '')),
                'reg_no' => $student->reg_no,
                'photo' => $student->photo ?? null,
                'class' => $classInfo?->name ?? null,
            ],
            'stats' => [
                'subjects' => $subjectsCount,
                'attendance_rate' => $attendanceRate,     // percentage
                'fee_balance' => (float) ($feeAgg->balance ?? 0),
                'unread_notifications' => $unreadCount,
                'results_count' => $resultsCount,
                'avg_score' => $averageAllTime,
            ],
            'fees' => [
                'total_fees' => (float) ($feeAgg->total_fees ?? 0),
                'total_paid' => (float) ($feeAgg->total_paid ?? 0),
                'balance' => (float) ($feeAgg->balance ?? 0),
                'last_payment_date' => $lastPaymentDate,
            ],
            'charts' => [
                'performance' => $perfRows, // [{label, average}]
                'access' => [
                    'labels' => $accessLabels,
                    'data' => $accessData
                ],
            ],
            'next_class' => $nextClass,
            'recent_notifications' => $recentNotifications,
            'current_result' => $currentResult,
            'latest_published_result' => $latestPublishedResultPayload,
        ]);
    }
}
