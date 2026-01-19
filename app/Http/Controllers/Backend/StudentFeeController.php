<?php

namespace App\Http\Controllers\Backend;


use App\Http\Controllers\Controller;
use App\Models\StudentFee;
use App\Models\PaymentReceipt;
use App\Models\Payment;
use App\Models\FeeType;
use App\Models\Term;
use App\Models\AcademicSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StudentFeeController extends Controller
{
    
 public function searchByRegNo(Request $request, $reg_no)
{
    $schoolId = Auth::user()->school_id;

    $term = $request->query('term'); // e.g., "First Term"
    $session_id = $request->query('session_id');

    $student = User::where('school_id', $schoolId)
        ->where('reg_no', $reg_no)
        ->first();

    if (!$student) {
        return response()->json(['message' => 'Student not found'], 404);
    }

    // Only fetch fees for the assigned term & session
    $fees = StudentFee::with('feeType:id,name', 'term:id,name')
        ->where('student_id', $student->id)
        ->where('school_id', $schoolId)
        ->whereHas('term', function ($q) use ($term) {
            $q->where('name', $term);
        })
        ->where('session_id', $session_id)
        ->get();

    if ($fees->isEmpty()) {
        return response()->json([
            'student' => $student,
            'fees' => [],
            'message' => 'No fees assigned for this student in the selected term/session'
        ]);
    }

    return response()->json([
        'student' => $student,
        'fees' => $fees
    ]);
}


public function feeInfo($schoolId)
{
    $feeTypes = FeeType::where('school_id', $schoolId)
        ->get(['id', 'name', 'term_id', 'session_id', 'amount']); // include term_id & session_id

    $terms = Term::where('school_id', $schoolId)->get(['id', 'name']);
    $sessions = AcademicSession::where('school_id', $schoolId)->get(['id', 'name']);

    return response()->json([
        'fee_types' => $feeTypes,
        'terms' => $terms,
        'sessions' => $sessions,
    ]);
}


public function MyFee(Request $request)
{
    $student = Auth::user();

    if (strtolower($student->role) !== 'student') {
        return response()->json(['message' => 'Unauthorized'], 403);
    }

    // ✅ Eager-load feeType relationship
    $fees = StudentFee::with('feeType')
        ->where('student_id', $student->id)
        ->orderBy('created_at', 'desc')
        ->paginate(10);

    $totalFees = StudentFee::where('student_id', $student->id)->sum('total_amount');
    $totalPaid = StudentFee::where('student_id', $student->id)->sum('amount_paid');
    $balance = StudentFee::where('student_id', $student->id)->sum('balance');

    $lastPaymentDate = StudentFee::where('student_id', $student->id)
        ->latest('updated_at')
        ->value('updated_at');

    return response()->json([
        'student' => [
            'name' => "{$student->surname} {$student->firstname}",
            'reg_no' => $student->reg_no,
        ],
        'summary' => [
            'total_fees' => $totalFees,
            'total_paid' => $totalPaid,
            'balance' => $balance,
            'last_payment_date' => $lastPaymentDate,
        ],
        'fees' => $fees,
    ]);
}




    // âœ… Get all student fees (with related student info)
    public function index()
    {
        $schoolId = Auth::user()->school_id;

        $studentFees = StudentFee::with('student:id,firstname,surname,reg_no')
            ->where('school_id', $schoolId)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'message' => 'Student fees fetched successfully',
            'data' => $studentFees
        ]);
    }


public function payFee(Request $request)
{
    $user = Auth::user();
    $schoolId = $user->school_id;

    $request->validate([
        'student_fee_id' => 'required|exists:student_fees,id',
        'amount' => 'required|numeric|min:1',
        'payment_method' => 'required|string|max:50',
    ]);

    // ✅ Get the fee record with student info
    $studentFee = StudentFee::where('school_id', $schoolId)
        ->with(['feeType', 'term', 'session', 'student'])
        ->findOrFail($request->student_fee_id);

    // ✅ Check if there is a receipt uploaded for this payment
    $receipt = PaymentReceipt::where('student_id', $studentFee->student_id)
        ->where('school_id', $schoolId)
        ->where('payment_method', $request->payment_method)
        ->latest()
        ->first();

    if (!$receipt) {
        return response()->json([
            'message' => 'No payment receipt uploaded for this fee.',
        ], 422);
    }

    // ✅ Check the status of the receipt
    if ($receipt->status === 'rejected') {
        return response()->json([
            'message' => 'Payment was rejected by the school. Please upload a valid receipt.',
        ], 422);
    } elseif ($receipt->status !== 'approved') {
        return response()->json([
            'message' => 'Payment is pending school approval. Cannot mark as paid yet.',
        ], 422);
    }

    // ✅ Prevent duplicate full payment for same fee type, term & session
    $alreadyPaid = StudentFee::where('student_id', $studentFee->student_id)
        ->where('fee_type_id', $studentFee->fee_type_id)
        ->where('term_id', $studentFee->term_id)
        ->where('session_id', $studentFee->session_id)
        ->where('status', 'paid')
        ->exists();

    if ($alreadyPaid) {
        return response()->json([
            'message' => 'This fee type has already been fully paid for this term and session.',
        ], 422);
    }

    // ✅ Prevent overpayment
    if ($request->amount > $studentFee->balance) {
        return response()->json([
            'message' => 'Amount exceeds remaining balance.',
            'balance' => $studentFee->balance,
        ], 422);
    }

    // ✅ Record payment
    $payment = Payment::create([
        'student_fee_id' => $studentFee->id,
        'amount' => $request->amount,
        'payment_method' => $request->payment_method,
        'school_id' => $schoolId,
        'reference' => uniqid('PAY-'),
        'received_by' => $user->id,
    ]);

    // ✅ Update totals & status
    $studentFee->amount_paid += $request->amount;
    $studentFee->balance = max(0, $studentFee->total_amount - $studentFee->amount_paid);

    if ($studentFee->balance <= 0) {
        $studentFee->status = 'paid';
        $studentFee->balance = 0;
    } elseif ($studentFee->amount_paid > 0 && $studentFee->balance > 0) {
        $studentFee->status = 'partial';
    } else {
        $studentFee->status = 'unpaid';
    }

    $studentFee->save();

    return response()->json([
        'message' => 'Payment successful and approved by school.',
        'payment' => $payment,
        'balance' => $studentFee->balance,
        'status' => $studentFee->status,
    ]);
}







}
