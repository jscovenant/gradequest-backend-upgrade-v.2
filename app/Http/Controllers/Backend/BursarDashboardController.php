<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BursarDashboardController extends Controller
{
    public function summary()
    {
        $auth = Auth::user();

        $summary = DB::table('student_fees')
            ->where('school_id', $auth->school_id)
            ->selectRaw('
                COALESCE(SUM(total_amount), 0) as total_billed,
                COALESCE(SUM(amount_paid), 0) as total_paid,
                COALESCE(SUM(balance), 0) as total_outstanding,
                COUNT(*) as total_fee_rows,
                COUNT(DISTINCT CASE WHEN COALESCE(amount_paid,0) > 0 THEN student_id END) as paid_students_count,
                COUNT(DISTINCT CASE WHEN COALESCE(balance,0) > 0 THEN student_id END) as owing_students_count
            ')
            ->first();

        $transactions = DB::table('payments')
            ->where('school_id', $auth->school_id)
            ->count();

        return response()->json([
            'summary' => [
                'total_billed' => (float) ($summary->total_billed ?? 0),
                'total_paid' => (float) ($summary->total_paid ?? 0),
                'total_outstanding' => (float) ($summary->total_outstanding ?? 0),
                'total_transactions' => (int) $transactions,
                'paid_students_count' => (int) ($summary->paid_students_count ?? 0),
                'owing_students_count' => (int) ($summary->owing_students_count ?? 0),
            ],
        ]);
    }

    public function recentPayments(Request $request)
    {
        $auth = Auth::user();
        $limit = (int) $request->get('limit', 6);
        $page = max((int) $request->get('page', 1), 1);
        $offset = ($page - 1) * $limit;

        $baseQuery = DB::table('payments as p')
            ->join('student_fees as sf', 'p.student_fee_id', '=', 'sf.id')
            ->join('users as s', 'sf.student_id', '=', 's.id')
            ->where('p.school_id', $auth->school_id);

        $total = (clone $baseQuery)->count();

        $rows = $baseQuery
            ->select(
                'p.id',
                'p.reference',
                'p.amount',
                'p.payment_method',
                'p.created_at',
                'p.received_by',
                's.firstname',
                's.surname',
                's.reg_no'
            )
            ->orderByDesc('p.created_at')
            ->offset($offset)
            ->limit($limit)
            ->get();

        $data = $rows->map(function ($r) {
            return [
                'id' => $r->id,
                'reference' => $r->reference,
                'amount' => (float) $r->amount,
                'payment_method' => $r->payment_method,
                'created_at' => $r->created_at,
                'received_by' => $r->received_by,
                'student_name' => trim(($r->firstname ?? '') . ' ' . ($r->surname ?? '')),
                'admission_no' => $r->reg_no,
            ];
        });

        return response()->json([
            'data' => $data,
            'total' => $total,
        ]);
    }

    public function paymentMethodBreakdown()
    {
        $auth = Auth::user();

        $rows = DB::table('payments')
            ->where('school_id', $auth->school_id)
            ->selectRaw('
                COALESCE(payment_method, "Unknown") as method,
                COUNT(*) as total_count,
                COALESCE(SUM(amount), 0) as total_amount
            ')
            ->groupBy('payment_method')
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