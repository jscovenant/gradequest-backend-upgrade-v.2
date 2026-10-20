<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\Section;

class BursarDashboardController extends Controller
{
    /**
     * Resolve accessible section IDs for the current user and requested filter.
     */
    protected function resolveScopedSectionIds($user, $requestedSectionId = null): array
    {
        $schoolId = (int) $user->school_id;
        $normRole = \App\Models\User::normalizeRole($user->role);
        $isBursar = $normRole === 'bursar';
        $isPrincipal = $normRole === 'principal' || (method_exists($user, 'isPrincipal') && $user->isPrincipal());

        if ($isBursar) {
            $assigned = $user->getBursarAssignedSectionIds();
            if (!empty($assigned)) {
                if ($requestedSectionId && in_array((int) $requestedSectionId, $assigned, true)) {
                    return [(int) $requestedSectionId];
                }
                return $assigned;
            }

            if (!empty($user->section_id)) {
                return [(int) $user->section_id];
            }

            // Bursar has no assigned section: strictly isolate to prevent cross-section access
            return [-1];
        }

        if ($isPrincipal) {
            $assigned = $user->getPrincipalAssignedSectionIds();
            if (!empty($assigned)) {
                if ($requestedSectionId && in_array((int) $requestedSectionId, $assigned, true)) {
                    return [(int) $requestedSectionId];
                }
                return $assigned;
            }

            if (!empty($user->section_id)) {
                return [(int) $user->section_id];
            }

            // If school has sections defined, isolate if principal has no assigned section
            $hasSections = Section::where('school_id', $schoolId)->whereNull('archived_at')->exists();
            if ($hasSections) {
                return [-1];
            }
            return [];
        }

        // For school owners / admins / chief bursars
        if ($requestedSectionId) {
            return [(int) $requestedSectionId];
        }

        return []; // empty means all sections in school
    }

    public function summary(Request $request)
    {
        $auth = Auth::user();
        $schoolId = (int) $auth->school_id;
        $requestedSectionId = $request->query('section_id');

        $scopedSectionIds = $this->resolveScopedSectionIds($auth, $requestedSectionId);

        // All sections belonging to this school
        $allSchoolSections = Section::where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->select('id', 'name')
            ->get();

        // Accessible sections for this user
        $normRole = \App\Models\User::normalizeRole($auth->role);
        $isBursar = $normRole === 'bursar';
        $isPrincipal = $normRole === 'principal' || (method_exists($auth, 'isPrincipal') && $auth->isPrincipal());

        $assignedSectionIds = [];
        if ($isBursar) {
            $assignedSectionIds = $auth->getBursarAssignedSectionIds();
        } elseif ($isPrincipal) {
            $assignedSectionIds = $auth->getPrincipalAssignedSectionIds();
        }

        $availableSections = !empty($assignedSectionIds)
            ? $allSchoolSections->whereIn('id', $assignedSectionIds)->values()
            : (($isBursar || $isPrincipal) ? collect([]) : $allSchoolSections);

        $activeSection = null;
        if (!empty($scopedSectionIds) && count($scopedSectionIds) === 1 && $scopedSectionIds[0] !== -1) {
            $activeSection = $allSchoolSections->firstWhere('id', $scopedSectionIds[0]);
        }

        // Student fees query
        $feesQuery = DB::table('student_fees')->where('school_id', $schoolId);
        if (!empty($scopedSectionIds)) {
            $feesQuery->whereIn('section_id', $scopedSectionIds);
        }

        $summary = $feesQuery->selectRaw('
            COALESCE(SUM(COALESCE(gross_amount, total_amount)), 0) as total_gross_billed,
            COALESCE(SUM(COALESCE(discount_amount, 0)), 0) as total_discounts_granted,
            COALESCE(SUM(COALESCE(net_amount, total_amount)), 0) as total_billed,
            COALESCE(SUM(amount_paid), 0) as total_paid,
            COALESCE(SUM(balance), 0) as total_outstanding,
            COUNT(*) as total_fee_rows,
            COUNT(DISTINCT CASE WHEN COALESCE(amount_paid,0) > 0 THEN student_id END) as paid_students_count,
            COUNT(DISTINCT CASE WHEN COALESCE(balance,0) > 0 THEN student_id END) as owing_students_count
        ')->first();

        // Payments query
        $paymentsQuery = DB::table('payments as p')
            ->join('student_fees as sf', 'p.student_fee_id', '=', 'sf.id')
            ->where('p.school_id', $schoolId);

        if (!empty($scopedSectionIds)) {
            $paymentsQuery->whereIn('sf.section_id', $scopedSectionIds);
        }

        $transactions = $paymentsQuery->count();

        $grossBilled = (float) ($summary->total_gross_billed ?? 0);
        $discounts = (float) ($summary->total_discounts_granted ?? 0);
        $netBilled = (float) ($summary->total_billed ?? 0);
        $totalPaid = (float) ($summary->total_paid ?? 0);
        $totalOutstanding = (float) ($summary->total_outstanding ?? 0);
        $paidCount = (int) ($summary->paid_students_count ?? 0);
        $owingCount = (int) ($summary->owing_students_count ?? 0);
        $totalStudents = $paidCount + $owingCount;
        $collectionRate = $netBilled > 0 ? round(($totalPaid / $netBilled) * 100, 1) : 0;

        $metricsData = [
            'gross_billed' => $grossBilled,
            'discounts_granted' => $discounts,
            'net_billed' => $netBilled,
            'total_collected' => $totalPaid,
            'outstanding_balance' => $totalOutstanding,
            'total_transactions' => (int) $transactions,
            'collection_rate' => $collectionRate,
            'total_students' => $totalStudents,
            'paid_students_count' => $paidCount,
            'owing_students_count' => $owingCount,
            'total_gross_billed' => $grossBilled,
            'total_discounts_granted' => $discounts,
            'total_billed' => $netBilled,
            'total_paid' => $totalPaid,
            'total_outstanding' => $totalOutstanding,
            'paidAmount' => $totalPaid,
            'outstandingAmount' => $totalOutstanding,
        ];

        return response()->json([
            'summary' => $metricsData,
            'metrics' => $metricsData,
            'active_section' => $activeSection,
            'available_sections' => $availableSections,
            'is_scoped_bursar' => $isBursar,
            'is_scoped_principal' => $isPrincipal,
            'is_scoped_user' => ($isBursar || $isPrincipal),
        ]);
    }

    public function recentPayments(Request $request)
    {
        $auth = Auth::user();
        $schoolId = (int) $auth->school_id;
        $requestedSectionId = $request->query('section_id');
        $scopedSectionIds = $this->resolveScopedSectionIds($auth, $requestedSectionId);

        $limit = (int) $request->get('limit', 10);
        $page = max((int) $request->get('page', 1), 1);
        $offset = ($page - 1) * $limit;

        $baseQuery = DB::table('payments as p')
            ->join('student_fees as sf', 'p.student_fee_id', '=', 'sf.id')
            ->join('users as s', 'sf.student_id', '=', 's.id')
            ->leftJoin('sections as sec', 'sf.section_id', '=', 'sec.id')
            ->leftJoin('fee_types as ft', 'sf.fee_type_id', '=', 'ft.id')
            ->where('p.school_id', $schoolId);

        if (!empty($scopedSectionIds)) {
            $baseQuery->whereIn('sf.section_id', $scopedSectionIds);
        }

        $total = (clone $baseQuery)->count();

        $rows = $baseQuery
            ->select(
                'p.id',
                'p.reference',
                'p.receipt_number',
                'p.amount',
                'p.current_paid',
                'p.arrears_paid',
                'p.payment_method',
                'p.payer_name',
                'p.payer_phone',
                'p.created_at',
                'p.received_by',
                's.firstname',
                's.surname',
                's.reg_no',
                'sec.name as section_name',
                'ft.name as fee_type_name'
            )
            ->orderByDesc('p.created_at')
            ->offset($offset)
            ->limit($limit)
            ->get();

        $data = $rows->map(function ($r) {
            return [
                'id' => $r->id,
                'reference' => $r->reference,
                'receipt_number' => $r->receipt_number ?: $r->reference,
                'amount' => (float) $r->amount,
                'current_paid' => (float) ($r->current_paid ?? $r->amount),
                'arrears_paid' => (float) ($r->arrears_paid ?? 0),
                'payment_method' => $r->payment_method,
                'payer_name' => $r->payer_name,
                'payer_phone' => $r->payer_phone,
                'created_at' => $r->created_at,
                'received_by' => $r->received_by,
                'student_name' => trim(($r->firstname ?? '') . ' ' . ($r->surname ?? '')),
                'admission_no' => $r->reg_no,
                'section_name' => $r->section_name ?? 'N/A',
                'fee_type_name' => $r->fee_type_name ?? 'School Fee',
            ];
        });

        return response()->json([
            'data' => $data,
            'total' => $total,
        ]);
    }

    public function paymentMethodBreakdown(Request $request)
    {
        $auth = Auth::user();
        $schoolId = (int) $auth->school_id;
        $requestedSectionId = $request->query('section_id');
        $scopedSectionIds = $this->resolveScopedSectionIds($auth, $requestedSectionId);

        $query = DB::table('payments as p')
            ->join('student_fees as sf', 'p.student_fee_id', '=', 'sf.id')
            ->where('p.school_id', $schoolId);

        if (!empty($scopedSectionIds)) {
            $query->whereIn('sf.section_id', $scopedSectionIds);
        }

        $rows = $query
            ->selectRaw('
                COALESCE(p.payment_method, "Unknown") as method,
                COUNT(*) as total_count,
                COALESCE(SUM(p.amount), 0) as total_amount
            ')
            ->groupBy('p.payment_method')
            ->orderByDesc('total_amount')
            ->get();

        return response()->json([
            'data' => $rows->map(function ($r) {
                return [
                    'method' => $r->method,
                    'total_count' => (int) $r->total_count,
                    'total_amount' => (float) $r->total_amount,
                ];
            }),
        ]);
    }

    public function sectionBreakdown(Request $request)
    {
        $auth = Auth::user();
        $schoolId = (int) $auth->school_id;
        $requestedSectionId = $request->query('section_id');

        $scopedSectionIds = $this->resolveScopedSectionIds($auth, $requestedSectionId);

        $sectionsQuery = Section::where('school_id', $schoolId)
            ->whereNull('archived_at');

        if (!empty($scopedSectionIds)) {
            $sectionsQuery->whereIn('id', $scopedSectionIds);
        }

        $sections = $sectionsQuery->get(['id', 'name']);

        $breakdown = [];
        foreach ($sections as $sec) {
            $fees = DB::table('student_fees')
                ->where('school_id', $schoolId)
                ->where('section_id', $sec->id)
                ->selectRaw('
                    COALESCE(SUM(COALESCE(gross_amount, total_amount)), 0) as gross_billed,
                    COALESCE(SUM(COALESCE(discount_amount, 0)), 0) as discounts,
                    COALESCE(SUM(COALESCE(net_amount, total_amount)), 0) as net_billed,
                    COALESCE(SUM(amount_paid), 0) as paid,
                    COALESCE(SUM(balance), 0) as balance,
                    COUNT(DISTINCT student_id) as total_students,
                    COUNT(DISTINCT CASE WHEN COALESCE(balance, 0) > 0 THEN student_id END) as owing_students
                ')
                ->first();

            $breakdown[] = [
                'section_id' => $sec->id,
                'section_name' => $sec->name,
                'gross_billed' => (float) ($fees->gross_billed ?? 0),
                'discounts' => (float) ($fees->discounts ?? 0),
                'net_billed' => (float) ($fees->net_billed ?? 0),
                'paid' => (float) ($fees->paid ?? 0),
                'balance' => (float) ($fees->balance ?? 0),
                'total_students' => (int) ($fees->total_students ?? 0),
                'owing_students' => (int) ($fees->owing_students ?? 0),
                'collection_rate' => ($fees->net_billed > 0) ? round(($fees->paid / $fees->net_billed) * 100, 1) : 0,
            ];
        }

        return response()->json([
            'sections' => $breakdown,
        ]);
    }

    public function revenueTrend(Request $request)
    {
        $auth = Auth::user();
        $schoolId = (int) $auth->school_id;
        $requestedSectionId = $request->query('section_id');
        $scopedSectionIds = $this->resolveScopedSectionIds($auth, $requestedSectionId);

        $query = DB::table('payments as p')
            ->join('student_fees as sf', 'p.student_fee_id', '=', 'sf.id')
            ->where('p.school_id', $schoolId);

        if (!empty($scopedSectionIds)) {
            $query->whereIn('sf.section_id', $scopedSectionIds);
        }

        // Daily trend
        $rows = (clone $query)
            ->selectRaw('DATE(p.created_at) as pay_date, SUM(p.amount) as daily_total, COUNT(*) as count')
            ->groupBy(DB::raw('DATE(p.created_at)'))
            ->orderBy('pay_date', 'asc')
            ->get();

        $cumulative = 0;
        $points = [];
        foreach ($rows as $r) {
            $amt = (float) $r->daily_total;
            $cumulative += $amt;
            $points[] = [
                'date' => $r->pay_date,
                'label' => date('d M', strtotime($r->pay_date)),
                'amount' => $amt,
                'cumulative' => $cumulative,
                'count' => (int) $r->count,
            ];
        }

        // Breakdown by section
        $sectionQuery = DB::table('payments as p')
            ->join('student_fees as sf', 'p.student_fee_id', '=', 'sf.id')
            ->leftJoin('sections as sec', 'sf.section_id', '=', 'sec.id')
            ->where('p.school_id', $schoolId);

        if (!empty($scopedSectionIds)) {
            $sectionQuery->whereIn('sf.section_id', $scopedSectionIds);
        }

        $bySection = $sectionQuery
            ->selectRaw('COALESCE(sec.name, "General") as section_name, SUM(p.amount) as section_total, COUNT(*) as count')
            ->groupBy('sec.name')
            ->orderByDesc('section_total')
            ->get();

        return response()->json([
            'trend' => $points,
            'total_recovered' => $cumulative,
            'by_section' => $bySection,
        ]);
    }

    public function bankDetails()
    {
        $auth = Auth::user();

        $rows = DB::table('payment_gateways')
            ->where('school_id', $auth->school_id)
            ->where(function ($q) {
                $q->whereNotNull('bank_name')
                  ->orWhereNotNull('account_number')
                  ->orWhereNotNull('account_name');
            })
            ->select(
                'bank_name',
                'account_number',
                'account_name',
                'provider',
                'is_default',
                'is_active'
            )
            ->orderByDesc('is_default')
            ->orderByDesc('is_active')
            ->get();

        return response()->json([
            'data' => $rows,
        ]);
    }
}