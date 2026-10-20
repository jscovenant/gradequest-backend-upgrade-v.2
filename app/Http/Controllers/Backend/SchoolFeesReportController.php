<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\StudentFee;
use Illuminate\Support\Facades\Auth;
use App\Models\AcademicSession;
use App\Models\Term;
use App\Models\Section;

class SchoolFeesReportController extends Controller
{
    public function schoolFinancialReport(Request $request)
{
    $user = Auth::user();
    $schoolId = $user->school_id;

    $filters = $request->only(['session_id', 'term_id', 'section_id']);
    $perPage = $request->get('per_page', 10);

    $baseQuery = StudentFee::where('school_id', $schoolId);

    $isBursar = strtolower((string) ($user->role ?? '')) === 'bursar';
    $assignedSectionIds = $isBursar ? $user->getBursarAssignedSectionIds() : [];

    if (!empty($assignedSectionIds)) {
        if (!empty($filters['section_id']) && in_array((int) $filters['section_id'], $assignedSectionIds, true)) {
            $baseQuery->where('section_id', $filters['section_id']);
        } else {
            $baseQuery->whereIn('section_id', $assignedSectionIds);
        }
    } elseif (!empty($filters['section_id'])) {
        $baseQuery->where('section_id', $filters['section_id']);
    }

    // ✅ Totals
    $totalFeesAssigned = (clone $baseQuery)->sum('total_amount');

    // Fully paid fees — full total
    $totalPaid = (clone $baseQuery)
        ->where('status', 'paid')
        ->sum('total_amount');

    // Partial payments — amount actually paid
    $totalPartiallyPaid = (clone $baseQuery)
        ->where('status', 'partial')
        ->sum('amount_paid');

    // ✅ Unpaid fees = full unpaid + remaining balance from partial
    $totalUnpaid = 
        (clone $baseQuery)->where('status', 'unpaid')->sum('total_amount') +
        (clone $baseQuery)->where('status', 'partial')->sum('balance');

    // Total balance (still owed overall)
    $totalBalance = (clone $baseQuery)->sum('balance');

    // Paginate breakdown
    $breakdown = (clone $baseQuery)
        ->with(['student:id,firstname,surname,reg_no', 'feeType:id,name'])
        ->select('id', 'student_id', 'fee_type_id', 'total_amount', 'amount_paid', 'balance', 'status', 'session_id', 'term_id', 'section_id')
        ->orderBy('student_id')
        ->paginate($perPage);

    $report = [
        'school_id' => $schoolId,
        'filters' => $filters,
        'summary' => [
            'total_fees_assigned' => $totalFeesAssigned,
            'total_paid' => $totalPaid,
            'total_partially_paid' => $totalPartiallyPaid,
            'total_unpaid' => $totalUnpaid, // ✅ includes partial balance
            'total_balance_remaining' => $totalBalance,
            'payment_percentage' => $totalFeesAssigned > 0
                ? round((($totalPaid + $totalPartiallyPaid) / $totalFeesAssigned) * 100, 2)
                : 0,
        ],
        'breakdown' => $breakdown,
    ];

    return response()->json([
        'message' => 'Financial report generated successfully',
        'data' => $report,
    ]);
}





public function getFilters()
    {
        $schoolId = Auth::user()->school_id;

        return response()->json([
            'sessions' => AcademicSession::where('school_id', $schoolId)
                ->select('id', 'name')
                ->orderBy('id', 'desc')
                ->get(),
            'terms' => Term::where('school_id', $schoolId)
                ->select('id', 'name')
                ->get(),
            'sections' => Section::where('school_id', $schoolId)
                ->select('id', 'name')
                ->get(),
        ]);
    }
}

