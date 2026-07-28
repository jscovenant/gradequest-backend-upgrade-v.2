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

        if (!$student || $student->role !== 'Student') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $schoolId = (int) $student->school_id;
        $studentId = (int) $student->id;

        // =========================
        // Profile / Class / Level
        // =========================
        // If your users table has level_id or class_id, adapt accordingly.
        // We'll attempt to derive "class" from student_classes if your schema uses it.
        $classInfo = DB::table('student_classes')
            ->where('id', $student->level_id ?? null) // adjust if you store class on user
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

        // =========================
        // Attendance (simple: present/absent counts)
        // NOTE: adjust columns if your attendances table differs
        // Common columns: user_id, attendance_status/status/att_status, created_at
        // We'll assume:
        //   attendances.user_id = student_id
        //   attendances.att_status = 'present'|'absent'
        // If your column name differs, update here.
        // =========================
      
        $attendanceAgg = DB::table('attendances')
    ->where('student_id', $studentId)
    ->where('school_id', $schoolId) // good multi-tenant safety
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

// you can decide whether "late" counts as present or not.
// Most schools count late as present:
$effectivePresent = $presentDays + $lateDays;

$attendanceRate = $totalMarked > 0
    ? round(($effectivePresent / $totalMarked) * 100, 1)
    : 0;

        // =========================
        // Result availability / performance chart
        // Using subject_results_v2 as the source of scores.
        // We’ll group by term+session and compute AVG(effective_total or total).
        // Adjust column names if needed:
        // - term, session, effective_total
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
        // =========================
        // "Result checks" access analytics
        // If you log actions in activity_logs, we can chart by weekday.
        // We'll assume activity_logs has: user_id, action, created_at
        // action might be 'result_view' or similar.
        // If you don’t have such logs, frontend can still render with fallback.
        // =========================
      
        $accessRows = DB::table('activity_logs')
    ->where('user_id', $studentId)
    ->where('school_id', $schoolId)
    ->whereIn('action', ['result_view', 'view_result', 'result_checked'])
    ->where('created_at', '>=', now()->subDays(7))
    ->selectRaw("DAYNAME(created_at) as day_name, COUNT(*) as total")
    ->groupBy('day_name')
    ->get();

// Normalize to Mon..Sun
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
        // Unread notifications (Laravel notifications table)
        // columns: notifiable_id, read_at, created_at, data(json)
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
        // Next timetable class (basic)
        // timetables table varies a lot; we try:
        // - school_id
        // - class_id or level_id
        // - day / day_name
        // - start_time
        // - subject_name/subject_id
        // Adjust as needed once you show your timetables columns.
        // =========================
       
        $todayName = now()->format('l'); // Monday, Tuesday...
$classId = $student->level_id ?? null; // your users table uses level_id as class reference

$nextClass = null;

if ($classId) {
    $nextClass = DB::table('timetables')
        ->where('school_id', $schoolId)
        ->where('class_id', $classId)
        ->where('day', $todayName)
        ->orderBy('period_number', 'asc')
        ->first();
}

        // =========================
        // Core stats cards
        // =========================
        $resultsCount = DB::table('student_results_v2')
            ->where('user_id', $studentId)
            ->count();

       $averageAllTime = DB::table('subject_results_v2 as sr')
    ->join('student_results_v2 as r', 'r.id', '=', 'sr.student_result_id')
    ->join('result_batches as b', 'b.id', '=', 'r.batch_id')
    ->where('r.user_id', $studentId)
    ->where('b.school_id', $schoolId)
    ->avg(DB::raw("COALESCE(sr.cumulative_average, COALESCE(NULLIF(sr.total,''),0) + 0)"));

        $averageAllTime = $averageAllTime ? round((float) $averageAllTime, 1) : 0;

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
            } else {
                $currentResult = [
                    'school_id' => $schoolId,
                    'student_id' => $studentId,
                    'class_id' => $classId,
                    'class_name' => $classInfo?->name,
                    'term' => $currentTerm->name,
                    'session' => $currentSession->name,
                    'status' => 'not_started',
                    'is_published' => false,
                    'has_result' => false,
                ];
            }
        }

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

        $latestPublishedResultPayload = $latestPublishedResult ? [
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
        ] : null;

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
