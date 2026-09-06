<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ParentDashboardController extends Controller
{
    public function dashboard(Request $request)
    {
        $parent = Auth::user();

        if (!$parent || strtolower((string)$parent->role) !== 'parent') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $schoolId = (int) $parent->school_id;
        $parentId = (int) $parent->id;

        // =========================
        // Children list (parent_students -> users)
        // parent_students has: parent_id, student_id, school_id
        // users has: firstname, surname, reg_no, level_id, photo...
        // =========================
        $children = DB::table('parent_students as ps')
            ->join('users as u', 'u.id', '=', 'ps.student_id')
            ->leftJoin('student_classes as sc', 'sc.id', '=', 'u.level_id')
            ->where('ps.parent_id', $parentId)
            ->where('u.school_id', $schoolId)
            ->whereRaw('LOWER(u.role) = ?', ['student'])
            ->where(function ($query) use ($schoolId) {
                $query->where('ps.school_id', $schoolId)
                    ->orWhereNull('ps.school_id');
            })
            ->select([
                'u.id',
                'u.firstname',
                'u.surname',
                'u.reg_no',
                'u.photo',
                'u.level_id',
                'sc.name as class_name',
            ])
            ->orderBy('u.surname')
            ->get();

        $childIds = $children->pluck('id')->map(fn ($v) => (int)$v)->values()->all();

        if (count($childIds) === 0) {
            return response()->json([
                'parent' => [
                    'id' => $parentId,
                    'name' => trim(($parent->surname ?? '') . ' ' . ($parent->firstname ?? '')),
                    'email' => $parent->email,
                ],
                'stats' => [
                    'children' => 0,
                    'total_fees' => 0,
                    'total_paid' => 0,
                    'total_balance' => 0,
                    'unread_notifications' => 0,
                ],
                'children' => [],
                'charts' => [
                    'fee_balance_by_child' => [
                        'labels' => [],
                        'data' => [],
                    ],
                    'attendance_weekly' => [
                        'labels' => ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'],
                        'data' => [0,0,0,0,0,0,0],
                    ],
                ],
                'recent_notifications' => [],
                'message' => 'No children assigned to this parent yet.',
            ]);
        }

        // =========================
        // FEES aggregates (student_fees)
        // student_fees: student_id, total_amount, amount_paid, balance
        // =========================
        $feeAgg = DB::table('student_fees')
            ->whereIn('student_id', $childIds)
            ->selectRaw("
                COALESCE(SUM(total_amount),0) as total_fees,
                COALESCE(SUM(amount_paid),0) as total_paid,
                COALESCE(SUM(balance),0) as total_balance
            ")
            ->first();

        // Fee balance per child (chart)
        $feeByChild = DB::table('student_fees as sf')
            ->join('users as u', 'u.id', '=', 'sf.student_id')
            ->whereIn('sf.student_id', $childIds)
            ->groupBy('sf.student_id', 'u.firstname', 'u.surname')
            ->selectRaw("
                sf.student_id,
                CONCAT(u.surname,' ',u.firstname) as child_name,
                COALESCE(SUM(sf.balance),0) as balance
            ")
            ->orderBy('child_name')
            ->get();

        $feeLabels = $feeByChild->pluck('child_name')->values()->all();
        $feeData   = $feeByChild->pluck('balance')->map(fn($v)=> (float)$v)->values()->all();

        // =========================
        // Attendance weekly for a selected child (first child)
        // attendances table: student_id, school_id, date, status(enum)
        // =========================
        $selectedChildId = (int)($request->query('child_id') ?? $childIds[0]);

        if (!in_array($selectedChildId, $childIds, true)) {
            $selectedChildId = $childIds[0];
        }

        $weeklyRows = DB::table('attendances')
            ->where('school_id', $schoolId)
            ->where('student_id', $selectedChildId)
            ->where('date', '>=', now()->subDays(6)->toDateString())
            ->selectRaw("DAYNAME(date) as day_name, SUM(status IN ('present','late')) as present_like")
            ->groupBy('day_name')
            ->get();

        $dayOrder = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
        $weeklyMap = [];
        foreach ($weeklyRows as $r) {
            $weeklyMap[$r->day_name] = (int)$r->present_like;
        }

        $weeklyLabels = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
        $weeklyData = [];
        foreach ($dayOrder as $dn) {
            $weeklyData[] = $weeklyMap[$dn] ?? 0;
        }

        // =========================
        // Per-child quick summary:
        // - attendance rate (all-time or last 30 days)
        // - fee balance
        // - results count
        // =========================
        $attendance30 = DB::table('attendances')
            ->where('school_id', $schoolId)
            ->whereIn('student_id', $childIds)
            ->where('date', '>=', now()->subDays(30)->toDateString())
            ->groupBy('student_id')
            ->selectRaw("
                student_id,
                SUM(status = 'present') as present_days,
                SUM(status = 'absent') as absent_days,
                SUM(status = 'late') as late_days,
                SUM(status = 'excused') as excused_days
            ")
            ->get()
            ->keyBy('student_id');

        $balanceMap = DB::table('student_fees')
            ->whereIn('student_id', $childIds)
            ->groupBy('student_id')
            ->selectRaw("student_id, COALESCE(SUM(balance),0) as balance")
            ->get()
            ->keyBy('student_id');

        // results count: student_results_v2.user_id links to users.id
        $resultsMap = DB::table('student_results_v2')
            ->whereIn('user_id', $childIds)
            ->groupBy('user_id')
            ->selectRaw("user_id, COUNT(*) as results_count")
            ->get()
            ->keyBy('user_id');

        $childrenOut = $children->map(function ($c) use ($attendance30, $balanceMap, $resultsMap) {
            $sid = (int)$c->id;

            $att = $attendance30->get($sid);
            $p = (int)($att->present_days ?? 0);
            $a = (int)($att->absent_days ?? 0);
            $l = (int)($att->late_days ?? 0);
            $e = (int)($att->excused_days ?? 0);

            $total = $p + $a + $l + $e;
            $effectivePresent = $p + $l; // treat late as present
            $rate = $total > 0 ? round(($effectivePresent / $total) * 100, 1) : 0;

            $bal = (float)($balanceMap->get($sid)->balance ?? 0);
            $rc  = (int)($resultsMap->get($sid)->results_count ?? 0);

            return [
                'id' => $sid,
                'name' => trim(($c->surname ?? '') . ' ' . ($c->firstname ?? '')),
                'reg_no' => $c->reg_no,
                'photo' => $c->photo,
                'class' => $c->class_name,
                'attendance_rate_30d' => $rate,
                'fee_balance' => $bal,
                'results_count' => $rc,
            ];
        })->values();

        // =========================
        // Parent notifications
        // notifications: notifiable_id
        // =========================
        $unreadCount = DB::table('notifications')
            ->where('notifiable_id', $parentId)
            ->whereNull('read_at')
            ->count();

        $recentNotifications = DB::table('notifications')
            ->where('notifiable_id', $parentId)
            ->latest('created_at')
            ->limit(8)
            ->get(['id', 'type', 'data', 'read_at', 'created_at']);

        return response()->json([
            'parent' => [
                'id' => $parentId,
                'name' => trim(($parent->surname ?? '') . ' ' . ($parent->firstname ?? '')),
                'email' => $parent->email,
                'phone' => $parent->phone ?? null,
            ],
            'stats' => [
                'children' => count($childIds),
                'total_fees' => (float)($feeAgg->total_fees ?? 0),
                'total_paid' => (float)($feeAgg->total_paid ?? 0),
                'total_balance' => (float)($feeAgg->total_balance ?? 0),
                'unread_notifications' => (int)$unreadCount,
            ],
            'selected_child_id' => $selectedChildId,
            'children' => $childrenOut,
            'charts' => [
                'fee_balance_by_child' => [
                    'labels' => $feeLabels,
                    'data' => $feeData,
                ],
                'attendance_weekly' => [
                    'labels' => $weeklyLabels,
                    'data' => $weeklyData,
                ],
            ],
            'recent_notifications' => $recentNotifications,
        ]);
    }

    /**
     * Get detailed attendance records and statistics for parent's children.
     */
    public function attendance(Request $request)
    {
        $parent = Auth::user();
        if (!$parent || strtolower((string)$parent->role) !== 'parent') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $schoolId = (int) $parent->school_id;
        $parentId = (int) $parent->id;

        $children = DB::table('parent_students as ps')
            ->join('users as u', 'u.id', '=', 'ps.student_id')
            ->leftJoin('student_classes as sc', 'sc.id', '=', 'u.level_id')
            ->where('ps.parent_id', $parentId)
            ->where('u.school_id', $schoolId)
            ->whereRaw('LOWER(u.role) = ?', ['student'])
            ->where(function ($query) use ($schoolId) {
                $query->where('ps.school_id', $schoolId)
                    ->orWhereNull('ps.school_id');
            })
            ->select([
                'u.id',
                'u.firstname',
                'u.surname',
                'u.reg_no',
                'u.photo',
                'u.level_id',
                'sc.name as class_name',
            ])
            ->orderBy('u.surname')
            ->get();

        if ($children->isEmpty()) {
            return response()->json([
                'children' => [],
                'selected_child' => null,
                'stats' => [
                    'total_days' => 0,
                    'present_days' => 0,
                    'late_days' => 0,
                    'absent_days' => 0,
                    'excused_days' => 0,
                    'attendance_rate' => 0,
                ],
                'records' => [],
                'monthly_breakdown' => [],
            ]);
        }

        $childIds = $children->pluck('id')->map(fn($v) => (int)$v)->values()->all();
        $selectedChildId = (int) ($request->query('child_id') ?? $childIds[0]);
        if (!in_array($selectedChildId, $childIds, true)) {
            $selectedChildId = $childIds[0];
        }

        $selectedChild = $children->firstWhere('id', $selectedChildId);

        $month = $request->query('month');
        $year = $request->query('year') ?? date('Y');

        $query = DB::table('attendances as a')
            ->leftJoin('users as t', 't.id', '=', 'a.teacher_id')
            ->where('a.school_id', $schoolId)
            ->where('a.student_id', $selectedChildId);

        if ($month) {
            $query->whereMonth('a.date', (int)$month)->whereYear('a.date', (int)$year);
        }

        $records = $query->select([
                'a.id',
                'a.date',
                'a.status',
                'a.remarks',
                'a.week_number',
                'a.created_at',
                't.firstname as teacher_firstname',
                't.surname as teacher_surname',
            ])
            ->orderByDesc('a.date')
            ->limit(100)
            ->get()
            ->map(function ($r) {
                return [
                    'id' => $r->id,
                    'date' => $r->date,
                    'day' => date('l', strtotime($r->date)),
                    'status' => strtolower($r->status ?? 'present'),
                    'remarks' => $r->remarks ?? 'Normal class attendance',
                    'week_number' => $r->week_number ?? 1,
                    'marked_by' => trim(($r->teacher_firstname ?? '') . ' ' . ($r->teacher_surname ?? '')) ?: 'Class Teacher',
                ];
            });

        // Summary calculations for the selected child
        $allTime = DB::table('attendances')
            ->where('school_id', $schoolId)
            ->where('student_id', $selectedChildId)
            ->selectRaw("
                COUNT(*) as total_days,
                SUM(status = 'present') as present_days,
                SUM(status = 'late') as late_days,
                SUM(status = 'absent') as absent_days,
                SUM(status = 'excused') as excused_days
            ")
            ->first();

        $totalDays = (int) ($allTime->total_days ?? 0);
        $presentDays = (int) ($allTime->present_days ?? 0);
        $lateDays = (int) ($allTime->late_days ?? 0);
        $absentDays = (int) ($allTime->absent_days ?? 0);
        $excusedDays = (int) ($allTime->excused_days ?? 0);

        $effectivePresent = $presentDays + $lateDays;
        $attendanceRate = $totalDays > 0 ? round(($effectivePresent / $totalDays) * 100, 1) : 100.0;

        // Monthly breakdown
        $monthlyBreakdown = DB::table('attendances')
            ->where('school_id', $schoolId)
            ->where('student_id', $selectedChildId)
            ->where('date', '>=', now()->subMonths(5)->startOfMonth()->toDateString())
            ->selectRaw("
                DATE_FORMAT(date, '%Y-%m') as ym,
                DATE_FORMAT(date, '%b %Y') as month_name,
                COUNT(*) as total,
                SUM(status IN ('present', 'late')) as present_count,
                SUM(status = 'absent') as absent_count
            ")
            ->groupBy('ym', 'month_name')
            ->orderBy('ym')
            ->get()
            ->map(function ($m) {
                $tot = (int) $m->total;
                $pres = (int) $m->present_count;
                $rate = $tot > 0 ? round(($pres / $tot) * 100, 1) : 100;
                return [
                    'month' => $m->month_name,
                    'total' => $tot,
                    'present' => $pres,
                    'absent' => (int) $m->absent_count,
                    'rate' => $rate,
                ];
            });

        return response()->json([
            'children' => $children,
            'selected_child' => $selectedChild,
            'stats' => [
                'total_days' => $totalDays,
                'present_days' => $presentDays,
                'late_days' => $lateDays,
                'absent_days' => $absentDays,
                'excused_days' => $excusedDays,
                'attendance_rate' => $attendanceRate,
            ],
            'records' => $records,
            'monthly_breakdown' => $monthlyBreakdown,
        ]);
    }

    /**
     * Get communications, announcements, and notices for the parent.
     */
    public function communication(Request $request)
    {
        $parent = Auth::user();
        if (!$parent || strtolower((string)$parent->role) !== 'parent') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $schoolId = (int) $parent->school_id;
        $parentId = (int) $parent->id;

        // Direct notifications from notifications table
        $notifications = DB::table('notifications')
            ->where('notifiable_id', $parentId)
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->map(function ($n) {
                $data = is_string($n->data) ? json_decode($n->data, true) : (array)$n->data;
                return [
                    'id' => (string)$n->id,
                    'title' => $data['title'] ?? 'School Notice',
                    'message' => $data['message'] ?? '',
                    'type' => $data['type'] ?? 'general',
                    'category' => $data['category'] ?? 'notice',
                    'sender' => $data['sender'] ?? 'School Administration',
                    'action_url' => $data['action_url'] ?? null,
                    'is_read' => !empty($n->read_at),
                    'date' => date('M d, Y h:i A', strtotime($n->created_at)),
                    'time_ago' => \Carbon\Carbon::parse($n->created_at)->diffForHumans(),
                ];
            });

        // Invoice notifications
        $invoiceNotifs = DB::table('fee_invoice_notifications')
            ->where('school_id', $schoolId)
            ->where('parent_id', $parentId)
            ->latest('created_at')
            ->limit(15)
            ->get()
            ->map(function ($inv) {
                return [
                    'id' => 'inv_' . $inv->id,
                    'title' => 'Fee Invoice & Payment Notice',
                    'message' => $inv->message ?? 'A fee invoice / payment reminder has been issued for your child.',
                    'type' => 'fee',
                    'category' => 'fee_alert',
                    'sender' => 'Bursary Department',
                    'action_url' => '/parent/payments',
                    'is_read' => (bool)$inv->is_read,
                    'date' => date('M d, Y h:i A', strtotime($inv->created_at)),
                    'time_ago' => \Carbon\Carbon::parse($inv->created_at)->diffForHumans(),
                ];
            });

        $combined = $notifications->concat($invoiceNotifs)->sortByDesc('date')->values();
        $unreadCount = $combined->where('is_read', false)->count();

        return response()->json([
            'unread_count' => $unreadCount,
            'communications' => $combined,
        ]);
    }

    /**
     * Get school info, academic calendar, sessions, terms, and events for parent.
     */
    public function schoolInfo(Request $request)
    {
        $parent = Auth::user();
        if (!$parent || strtolower((string)$parent->role) !== 'parent') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $schoolId = (int) $parent->school_id;

        $school = DB::table('users')->where('id', $schoolId)->first();
        $settings = DB::table('settings')->where('school_id', $schoolId)->first();

        $activeSession = DB::table('sessions')
            ->where('school_id', $schoolId)
            ->where('status', 'active')
            ->first();

        $sessions = DB::table('sessions')
            ->where('school_id', $schoolId)
            ->orderByDesc('id')
            ->get();

        $terms = DB::table('terms')
            ->where('school_id', $schoolId)
            ->orderBy('id')
            ->get();

        $activeTerm = $terms->firstWhere('status', 'active') ?? $terms->first();

        return response()->json([
            'school' => [
                'name' => $school->school_name ?? $school->name ?? 'GradiosEdu Academy',
                'email' => $school->email ?? null,
                'phone' => $school->phone ?? null,
                'address' => $settings->address ?? $school->address ?? 'School Campus',
                'logo' => $school->logo ?? $settings->logo ?? null,
                'motto' => $settings->motto ?? 'Excellence in Education',
            ],
            'academic_calendar' => [
                'current_session' => $activeSession->name ?? date('Y') . '/' . (date('Y') + 1),
                'current_term' => $activeTerm->name ?? 'First Term',
                'terms' => $terms,
                'sessions' => $sessions,
            ],
        ]);
    }

    /**
     * Get published academic results & broadsheet summary for a parent's child.
     */
    public function childResults(Request $request)
    {
        $parent = Auth::user();
        if (!$parent || strtolower((string)$parent->role) !== 'parent') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $schoolId = (int) $parent->school_id;
        $parentId = (int) $parent->id;

        // Get linked children
        $children = DB::table('parent_students as ps')
            ->join('users as u', 'u.id', '=', 'ps.student_id')
            ->leftJoin('student_classes as sc', 'sc.id', '=', 'u.level_id')
            ->where('ps.parent_id', $parentId)
            ->where('u.school_id', $schoolId)
            ->whereRaw('LOWER(u.role) = ?', ['student'])
            ->where(function ($query) use ($schoolId) {
                $query->where('ps.school_id', $schoolId)
                    ->orWhereNull('ps.school_id');
            })
            ->select([
                'u.id',
                'u.firstname',
                'u.surname',
                'u.reg_no',
                'u.photo',
                'u.level_id',
                'sc.name as class_name',
            ])
            ->orderBy('u.surname')
            ->get()
            ->map(function ($c) {
                $photo = $c->photo;
                $photoUrl = $photo
                    ? (str_starts_with($photo, 'http') ? $photo : url('uploads/users/' . ltrim($photo, '/')))
                    : null;
                return [
                    'id' => (int)$c->id,
                    'firstname' => $c->firstname,
                    'surname' => $c->surname,
                    'reg_no' => $c->reg_no,
                    'photo' => $photoUrl,
                    'level_id' => $c->level_id,
                    'class_name' => $c->class_name,
                ];
            });

        if ($children->isEmpty()) {
            return response()->json([
                'children' => [],
                'selected_child' => null,
                'results' => [],
            ]);
        }

        $childIds = $children->pluck('id')->map(fn($v) => (int)$v)->values()->all();
        $selectedChildId = (int) ($request->query('student_id') ?? $request->query('child_id') ?? $childIds[0]);
        if (!in_array($selectedChildId, $childIds, true)) {
            $selectedChildId = $childIds[0];
        }

        $selectedChild = $children->firstWhere('id', $selectedChildId);

        // Fetch published results for this student from student_results_v2 and result_batches
        $v2Results = DB::table('student_results_v2 as sr')
            ->join('result_batches as rb', 'rb.id', '=', 'sr.batch_id')
            ->leftJoin('student_classes as sc', 'sc.id', '=', 'rb.class_id')
            ->where('sr.user_id', $selectedChildId)
            ->where('rb.school_id', $schoolId)
            ->where('rb.status', 'published')
            ->select([
                'sr.id as student_result_id',
                'sr.batch_id',
                'sr.total_score',
                'sr.average_score',
                'sr.gpa',
                'sr.position',
                'sr.total_students',
                'sr.class_teacher_comment',
                'sr.principal_comment',
                'sr.status as result_status',
                'rb.term',
                'rb.session',
                'rb.class_id',
                'sc.name as class_name',
            ])
            ->orderByDesc('rb.session')
            ->orderByDesc('rb.term')
            ->get();

        // Attach subject breakdown for each result
        $resultsOut = $v2Results->map(function ($r) use ($schoolId) {
            $subjects = DB::table('subject_results_v2 as sub')
                ->join('subjects as s', 's.id', '=', 'sub.subject_id')
                ->where('sub.student_result_id', $r->student_result_id)
                ->select([
                    'sub.id',
                    'sub.subject_id',
                    's.name as subject_name',
                    'sub.exam_score',
                    'sub.ca_total',
                    'sub.total_score',
                    'sub.grade',
                    'sub.remark',
                    'sub.teacher_comment',
                ])
                ->orderBy('s.name')
                ->get();

            return [
                'batch_id' => $r->batch_id,
                'term' => $r->term,
                'session' => $r->session,
                'class_id' => $r->class_id,
                'class_name' => $r->class_name,
                'total_score' => (float)$r->total_score,
                'average_score' => (float)$r->average_score,
                'position' => $r->position,
                'total_students' => $r->total_students,
                'class_teacher_comment' => $r->class_teacher_comment,
                'principal_comment' => $r->principal_comment,
                'subjects' => $subjects,
                'subjects_count' => $subjects->count(),
            ];
        });

        // Also check if any legacy results exist in case school migrated
        $legacyAverages = DB::table('averages as a')
            ->leftJoin('student_classes as sc', 'sc.id', '=', 'a.class_id')
            ->where('a.user_id', $selectedChildId)
            ->where('a.school_id', $schoolId)
            ->select([
                'a.id',
                'a.term',
                'a.session',
                'a.class_id',
                'a.total_average',
                'a.total_grade',
                'a.position',
                'a.class_size',
                'a.class_teacher_comment',
                'a.principal_comment',
                'sc.name as class_name',
            ])
            ->get();

        $legacyOut = $legacyAverages->map(function ($la) use ($selectedChildId, $schoolId) {
            $legacySubjects = DB::table('first_term_results as ftr')
                ->join('subjects as s', 's.id', '=', 'ftr.subject_id')
                ->where('ftr.user_id', $selectedChildId)
                ->where('ftr.term', $la->term)
                ->where('ftr.session', $la->session)
                ->select([
                    'ftr.id',
                    's.name as subject_name',
                    'ftr.exam',
                    'ftr.ca',
                    'ftr.total',
                    'ftr.grade',
                    'ftr.remark',
                ])
                ->get();

            return [
                'batch_id' => null,
                'term' => $la->term,
                'session' => $la->session,
                'class_id' => $la->class_id,
                'class_name' => $la->class_name,
                'total_score' => 0,
                'average_score' => (float)$la->total_average,
                'position' => $la->position,
                'total_students' => $la->class_size,
                'class_teacher_comment' => $la->class_teacher_comment,
                'principal_comment' => $la->principal_comment,
                'subjects' => $legacySubjects,
                'subjects_count' => $legacySubjects->count(),
            ];
        });

        $combinedResults = $resultsOut->concat($legacyOut)->unique(function ($item) {
            return $item['session'] . '_' . $item['term'];
        })->values();

        return response()->json([
            'children' => $children,
            'selected_child' => $selectedChild,
            'results' => $combinedResults,
        ]);
    }
}
