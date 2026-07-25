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

}
