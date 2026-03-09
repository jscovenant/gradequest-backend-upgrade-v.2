<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;


class ParentStudentFeesController extends Controller
{
    /**
     * Parent: view a child's fee details (summary + line items).
     * Query optional: term_id, session_id
     */
    public function show(Request $request, $studentId)
    {
        $parentId = $request->user()->id;

        // ✅ Ensure this student is assigned to this parent
        $link = DB::table('parent_students')
            ->where('parent_id', $parentId)
            ->where('student_id', $studentId)
            ->first();

        if (!$link) {
            return response()->json(['message' => 'You are not authorized to view this student.'], 403);
        }

        $schoolId = $link->school_id;

        // Determine current session/term if not provided
        $sessionId = $request->query('session_id');
        $termId = $request->query('term_id');

        if (!$sessionId) {
            $currentSession = DB::table('academic_sessions')
                ->where('school_id', $schoolId)
                ->where('is_current', 1)
                ->orderByDesc('id')
                ->first();

            $sessionId = $currentSession?->id;
        }

        if (!$termId) {
            $currentTerm = DB::table('terms')
                ->where('school_id', $schoolId)
                ->where('status', 'Active')
                ->orderBy('sort_order')
                ->orderByDesc('id')
                ->first();

            $termId = $currentTerm?->id;
        }

        // Student basics
        $student = DB::table('users')
            ->where('id', $studentId)
            ->select('id', 'firstname', 'surname', 'reg_no', 'photo', 'school_id')
            ->first();

        if (!$student || (int)$student->school_id !== (int)$schoolId) {
            return response()->json(['message' => 'Student not found in your school context.'], 404);
        }

        // Fee rows: student_fees joined with fee_types
        $rows = DB::table('student_fees as sf')
            ->leftJoin('fee_types as ft', 'ft.id', '=', 'sf.fee_type_id')
            ->where('sf.school_id', $schoolId)
            ->where('sf.student_id', $studentId)
            ->when($sessionId, fn ($q) => $q->where('sf.session_id', $sessionId))
            ->when($termId, fn ($q) => $q->where('sf.term_id', $termId))
            ->orderBy('ft.name')
            ->select([
                'sf.id',
                'sf.fee_type_id',
                DB::raw('COALESCE(ft.name, "Fee") as fee_name'),
                'sf.total_amount',
                'sf.amount_paid',
                'sf.balance',
                'sf.status',
                'sf.created_at',
                'sf.updated_at',
            ])
            ->get();

        $total = (float) $rows->sum('total_amount');
        $paid = (float) $rows->sum('amount_paid');
        $balance = (float) $rows->sum('balance');

        // Term/session names for UI header
        $term = null;
        if ($termId) {
            $term = DB::table('terms')->where('id', $termId)->select('id', 'name')->first();
        }

        $session = null;
        if ($sessionId) {
            $session = DB::table('academic_sessions')->where('id', $sessionId)->select('id', 'name')->first();
        }

        // Overall status (simple)
        $overallStatus =
            $balance <= 0 && $total > 0 ? 'paid' :
            ($paid > 0 ? 'partial' : 'unpaid');

        return response()->json([
            'student' => $student,
            'school_id' => $schoolId,
            'filters' => [
                'term' => $term,
                'session' => $session,
                'term_id' => $termId ? (int)$termId : null,
                'session_id' => $sessionId ? (int)$sessionId : null,
            ],
            'summary' => [
                'total' => round($total, 2),
                'paid' => round($paid, 2),
                'balance' => round($balance, 2),
                'status' => $overallStatus,
            ],
            'items' => $rows,
        ]);
    }

    /**
     * Parent: get active bank accounts for the child’s school.
     */
    public function bankAccounts(Request $request, $studentId)
    {
        $parentId = $request->user()->id;

        $link = DB::table('parent_students')
            ->where('parent_id', $parentId)
            ->where('student_id', $studentId)
            ->first();

        if (!$link) {
            return response()->json(['message' => 'You are not authorized to view this student.'], 403);
        }

        $schoolId = $link->school_id;

        $items = DB::table('school_bank_accounts')
            ->where('school_id', $schoolId)
            ->where('is_active', 1)
            ->orderBy('sort_order')
            ->select('id','bank_name','bank_code','account_name','account_number','currency')
            ->get();

        return response()->json([
            'school_id' => $schoolId,
            'accounts' => $items,
        ]);
    }



   private function resolveStudentOrFail(string $regNo, int $schoolId)
    {
        $student = DB::table('users')
            ->where('school_id', $schoolId)
            ->where('reg_no', $regNo)
            ->select('id', 'reg_no', 'firstname', 'surname')
            ->first();

        if (!$student) {
            abort(response()->json(['message' => 'Student not found for this Reg No.'], 404));
        }

        return $student;
    }

    private function assertParentOwnsStudent(int $parentId, int $studentId)
    {
        $link = DB::table('parent_students')
            ->where('parent_id', $parentId)
            ->where('student_id', $studentId)
            ->first();

        if (!$link) {
            abort(response()->json(['message' => 'You are not authorized to view this student.'], 403));
        }
    }

    /**
     * GET /parent/payments/summary?reg_no=REG123
     * Totals + breakdown by term/session + fee type names.
     */
    public function summary(Request $request)
    {
        $request->validate([
            'reg_no' => 'required|string|max:50',
        ]);

        $user = Auth::user();
        $schoolId = (int) $user->school_id;
        $regNo = trim($request->reg_no);

        $student = $this->resolveStudentOrFail($regNo, $schoolId);

        $isParent = strtolower((string)($user->role ?? '')) === 'Parent';
        if ($isParent) {
            $this->assertParentOwnsStudent((int)$user->id, (int)$student->id);
        }

        // Totals from student_fees
        $totals = DB::table('student_fees')
            ->where('school_id', $schoolId)
            ->where('student_id', (int)$student->id)
            ->selectRaw('
                COALESCE(SUM(total_amount),0) as total_due,
                COALESCE(SUM(amount_paid),0) as total_paid_ledger,
                COALESCE(SUM(balance),0) as total_balance
            ')
            ->first();

        // Fee status breakdown
        $feeStatusBreakdown = DB::table('student_fees')
            ->where('school_id', $schoolId)
            ->where('student_id', (int)$student->id)
            ->selectRaw('
                COALESCE(status, "unknown") as status,
                COUNT(*) as count,
                COALESCE(SUM(total_amount),0) as due,
                COALESCE(SUM(amount_paid),0) as paid,
                COALESCE(SUM(balance),0) as bal
            ')
            ->groupBy('status')
            ->orderBy('status')
            ->get();

        /**
         * Breakdown by Session + Term (names)
         * academic_sessions.school_id is nullable in your schema,
         * so we allow (school_id = this school) OR NULL to match older shared data.
         */
        $bySessionTerm = DB::table('student_fees as sf')
            ->leftJoin('academic_sessions as s', function ($j) use ($schoolId) {
                $j->on('s.id', '=', 'sf.session_id')
                  ->where(function ($q) use ($schoolId) {
                      $q->where('s.school_id', '=', $schoolId)
                        ->orWhereNull('s.school_id');
                  });
            })
            ->leftJoin('terms as t', function ($j) use ($schoolId) {
                $j->on('t.id', '=', 'sf.term_id')
                  ->where('t.school_id', '=', $schoolId);
            })
            ->where('sf.school_id', $schoolId)
            ->where('sf.student_id', (int)$student->id)
            ->selectRaw("
                sf.session_id,
                COALESCE(s.name, CONCAT('Session #', sf.session_id)) as session_name,
                sf.term_id,
                COALESCE(t.name, CONCAT('Term #', sf.term_id)) as term_name,
                COALESCE(SUM(sf.total_amount),0) as due,
                COALESCE(SUM(sf.amount_paid),0) as paid,
                COALESCE(SUM(sf.balance),0) as bal,
                COUNT(*) as items
            ")
            ->groupBy('sf.session_id', 'session_name', 'sf.term_id', 'term_name')
            ->orderBy('sf.session_id', 'desc')
            ->orderBy('sf.term_id', 'desc')
            ->get();

        /**
         * Breakdown by Fee Type (names)
         * fee_types has: section_id, session_id, term_id, school_id (nullable)
         * We join primarily on sf.fee_type_id -> ft.id, but also keep tenant strict:
         * (ft.school_id = this school) OR ft.school_id IS NULL
         */
        $byFeeType = DB::table('student_fees as sf')
            ->leftJoin('fee_types as ft', function ($j) use ($schoolId) {
                $j->on('ft.id', '=', 'sf.fee_type_id')
                  ->where(function ($q) use ($schoolId) {
                      $q->where('ft.school_id', '=', $schoolId)
                        ->orWhereNull('ft.school_id');
                  });
            })
            ->where('sf.school_id', $schoolId)
            ->where('sf.student_id', (int)$student->id)
            ->selectRaw("
                sf.fee_type_id,
                COALESCE(ft.name, CONCAT('Fee Type #', sf.fee_type_id)) as fee_type_name,
                COALESCE(SUM(sf.total_amount),0) as due,
                COALESCE(SUM(sf.amount_paid),0) as paid,
                COALESCE(SUM(sf.balance),0) as bal,
                COUNT(*) as items
            ")
            ->groupBy('sf.fee_type_id', 'fee_type_name')
            ->orderBy('sf.fee_type_id', 'asc')
            ->get();

        return response()->json([
            'student' => [
                'id' => (int)$student->id,
                'reg_no' => $student->reg_no,
                'name' => trim(($student->firstname ?? '').' '.($student->surname ?? '')),
            ],
            'totals' => [
                'total_due' => (float)($totals->total_due ?? 0),
                'total_paid' => (float)($totals->total_paid_ledger ?? 0),
                'total_balance' => (float)($totals->total_balance ?? 0),
            ],
            'breakdown' => [
                'by_fee_status' => $feeStatusBreakdown,
                'by_session_term' => $bySessionTerm,
                'by_fee_type' => $byFeeType,
            ],
        ]);
    }

    /**
     * GET /parent/payments/history?reg_no=REG123&term_id=&session_id=&fee_status=
     * Paginated payment history with session/term/fee-type names.
     */
    public function history(Request $request)
    {
        $request->validate([
            'reg_no' => 'required|string|max:50',
            'term_id' => 'nullable|integer',
            'session_id' => 'nullable|integer',
            'fee_status' => 'nullable|string|max:255',
        ]);

        $user = Auth::user();
        $schoolId = (int) $user->school_id;
        $regNo = trim($request->reg_no);

        $student = $this->resolveStudentOrFail($regNo, $schoolId);

        $isParent = strtolower((string)($user->role ?? '')) === 'parent';
        if ($isParent) {
            $this->assertParentOwnsStudent((int)$user->id, (int)$student->id);
        }

        $termId = $request->term_id;
        $sessionId = $request->session_id;
        $feeStatus = $request->fee_status;

        $q = DB::table('payments as p')
            ->join('student_fees as sf', 'sf.id', '=', 'p.student_fee_id')

            ->leftJoin('academic_sessions as s', function ($j) use ($schoolId) {
                $j->on('s.id', '=', 'sf.session_id')
                  ->where(function ($q) use ($schoolId) {
                      $q->where('s.school_id', '=', $schoolId)
                        ->orWhereNull('s.school_id');
                  });
            })
            ->leftJoin('terms as t', function ($j) use ($schoolId) {
                $j->on('t.id', '=', 'sf.term_id')
                  ->where('t.school_id', '=', $schoolId);
            })
            ->leftJoin('fee_types as ft', function ($j) use ($schoolId) {
                $j->on('ft.id', '=', 'sf.fee_type_id')
                  ->where(function ($q) use ($schoolId) {
                      $q->where('ft.school_id', '=', $schoolId)
                        ->orWhereNull('ft.school_id');
                  });
            })

            ->where('sf.school_id', $schoolId)
            ->where('sf.student_id', (int)$student->id)
            ->select([
                'p.id',
                'p.student_fee_id',
                'p.amount',
                'p.payment_method',
                'p.reference',
                'p.received_by',
                'p.created_at',
                'p.updated_at',

                'sf.section_id',
                'sf.fee_type_id',
                DB::raw("COALESCE(ft.name, CONCAT('Fee Type #', sf.fee_type_id)) as fee_type_name"),

                'sf.term_id',
                DB::raw("COALESCE(t.name, CONCAT('Term #', sf.term_id)) as term_name"),

                'sf.session_id',
                DB::raw("COALESCE(s.name, CONCAT('Session #', sf.session_id)) as session_name"),

                'sf.total_amount',
                'sf.amount_paid',
                'sf.balance',
                'sf.status as fee_status',
            ])
            ->orderByDesc('p.id');

        if ($termId) $q->where('sf.term_id', (int)$termId);
        if ($sessionId) $q->where('sf.session_id', (int)$sessionId);
        if ($feeStatus) $q->where('sf.status', $feeStatus);

        $rows = $q->paginate(25);

        return response()->json([
            'student' => [
                'id' => (int)$student->id,
                'reg_no' => $student->reg_no,
                'name' => trim(($student->firstname ?? '').' '.($student->surname ?? '')),
            ],
            'payments' => $rows,
        ]);
    }

}