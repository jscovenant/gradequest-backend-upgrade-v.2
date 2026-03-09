<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\{FeeType, StudentFee, Payment, PaymentReceipt, User, Section};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FeePaymentController extends Controller
{
    // ✅ Get fee structure by section, session & term
    public function getFeeStructure($sectionId, $sessionId, $termId)
    {
        $schoolId = Auth::user()->school_id;

        $feeTypes = FeeType::where('section_id', $sectionId)
            ->where('session_id', $sessionId)
            ->where('term_id', $termId)
            ->where('school_id', $schoolId)
            ->get();

        return response()->json($feeTypes);
    }

    // ✅ Fetch available fee types after selecting student, section, session, and term
  public function fetchFeeTypes(Request $request)
{
    $request->validate([
        'student_id' => 'required|exists:users,id',
        'section_id' => 'required|exists:sections,id',
        'session_id' => 'required|exists:academic_sessions,id',
        'term_id' => 'required|exists:terms,id',
    ]);

    $schoolId = Auth::user()->school_id;

    // ✅ Ensure the student belongs to the same school
    $student = User::where('school_id', $schoolId)->find($request->student_id);
    if (!$student) {
        return response()->json(['message' => 'Student not found or unauthorized'], 404);
    }

    // ✅ Fetch all fee types for that section/session/term/school
    $feeTypes = FeeType::where('school_id', $schoolId)
        ->where('section_id', $request->section_id)
        ->where('session_id', $request->session_id)
        ->where('term_id', $request->term_id)
        ->select('id', 'name', 'amount')
        ->get();

    // ✅ Return clear response if no fee types exist
    if ($feeTypes->isEmpty()) {
        return response()->json([
            'message' => 'No fee types found for the selected section, session, and term.',
            'student' => [
                'id' => $student->id,
                'name' => "{$student->firstname} {$student->surname}",
                'reg_no' => $student->reg_no,
            ],
            'fee_types' => [],
        ], 404);
    }

    // ✅ Normal success response
    return response()->json([
        'message' => 'Fee types retrieved successfully.',
        'student' => [
            'id' => $student->id,
            'name' => "{$student->firstname} {$student->surname}",
            'reg_no' => $student->reg_no,
        ],
        'fee_types' => $feeTypes,
    ]);
}


 /**
     * Display all fees assigned to a specific student.
     */
    public function showAssignedFees($studentId)
    {
        $student = User::with(['studentFees.feeType'])->findOrFail($studentId);

        return response()->json([
            'student' => $student->name,
            'fees' => $student->studentFees->map(function ($fee) {
                return [
                    'id' => $fee->id,
                    'type' => $fee->feeType->name ?? 'N/A',
                    'amount' => $fee->total_amount,
                    'status' => $fee->status, // e.g. 'paid' or 'unpaid'
                    'session' => $fee->session->name ?? 'N/A',
                ];
            }),
        ]);
    }

    /**
     * Remove an assigned fee if not yet paid.
     */
    public function removeAssignedFee($studentFeeId)
    {
        $studentFee = StudentFee::findOrFail($studentFeeId);

        if ($studentFee->status === 'paid') {
            return response()->json([
                'message' => 'You cannot delete a fee that has already been paid.'
            ], 403);
        }

        $studentFee->delete();

        return response()->json([
            'message' => 'Fee assignment removed successfully.'
        ]);
    }

    // ✅ Assign one or more fees to a student
 public function assignStudentFee(Request $request)
{
    $schoolId = Auth::user()->school_id;

    $request->validate([
        'student_id' => 'required|exists:users,id',
        'section_id' => 'required|exists:sections,id',
        'session_id' => 'required|exists:academic_sessions,id',
        'term_id' => 'required|exists:terms,id',
        'fee_type_ids' => 'required|array|min:1',
        'fee_type_ids.*' => 'exists:fee_types,id',
    ]);

    // ✅ Ensure the student belongs to the same school
    $student = User::where('school_id', $schoolId)->find($request->student_id);
    if (!$student) {
        return response()->json(['message' => 'Student not found or unauthorized'], 404);
    }

    // ✅ Fetch selected fee types belonging to the school
    $feeTypes = FeeType::whereIn('id', $request->fee_type_ids)
        ->where('school_id', $schoolId)
        ->get();

    if ($feeTypes->isEmpty()) {
        return response()->json(['message' => 'No valid fee types found'], 404);
    }

    $alreadyAssigned = [];
    $newlyAssigned = [];

    foreach ($feeTypes as $fee) {
        // ✅ Check if this fee has already been assigned for same session, term, section
        $exists = StudentFee::where([
            'school_id'   => $schoolId,
            'student_id'  => $student->id,
            'section_id'  => $request->section_id,
            'session_id'  => $request->session_id,
            'term_id'     => $request->term_id,
            'fee_type_id' => $fee->id,
        ])->exists();

        if ($exists) {
            // Record that this fee was already assigned
            $alreadyAssigned[] = $fee->name;
            continue;
        }

        // ✅ Create new record only if not already assigned
        $record = StudentFee::create([
            'school_id'   => $schoolId,
            'student_id'  => $student->id,
            'section_id'  => $request->section_id,
            'session_id'  => $request->session_id,
            'term_id'     => $request->term_id,
            'fee_type_id' => $fee->id,
            'total_amount' => $fee->amount,
            'balance'      => $fee->amount,
            'status'       => 'unpaid',
        ]);

        $newlyAssigned[] = $record;
    }

    // ✅ Build a clear response
    $message = 'Fee(s) assigned successfully.';
    if (count($alreadyAssigned) > 0) {
        $message .= ' However, the following fee(s) were already assigned: ' . implode(', ', $alreadyAssigned);
    }

    return response()->json([
        'message' => $message,
        'newly_assigned' => $newlyAssigned,
        'already_assigned' => $alreadyAssigned,
        'total_new_amount' => collect($newlyAssigned)->sum('total_amount'),
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



public function studentFeeDetails(Request $request)
{
    $schoolId = Auth::user()->school_id;
    $regNo = $request->query('reg_no');
    $sessionId = $request->query('session_id');
    $termId = $request->query('term_id');

    if (!$regNo) {
        return response()->json(['message' => 'Registration number is required'], 400);
    }

    // ✅ Get student info with section and class relationships
    $student = \App\Models\User::with(['section:id,name', 'level:id,name'])
        ->where('school_id', $schoolId)
        ->where('reg_no', $regNo)
        ->first();

    if (!$student) {
        return response()->json(['message' => 'Student not found or unauthorized'], 404);
    }

    // ✅ Fetch fees (filter by session/term if provided)
    $fees = \App\Models\StudentFee::with([
            'feeType:id,name,amount',
            'session:id,name',
            'term:id,name'
        ])
        ->where('student_id', $student->id)
        ->where('school_id', $schoolId)
        ->when($sessionId, fn($q) => $q->where('session_id', $sessionId))
        ->when($termId, fn($q) => $q->where('term_id', $termId))
        ->get([
            'id',
            'student_id',
            'school_id',
            'section_id',
            'fee_type_id',
            'term_id',
            'session_id',
            'total_amount',
            'amount_paid',
            'balance',
        ]);

    return response()->json([
        'student' => [
            'id' => $student->id,
            'name' => "{$student->firstname} {$student->surname}",
            'reg_no' => $student->reg_no,
            'section' => optional($student->section)->name ?? 'N/A',
            'class' => optional($student->level)->name ?? 'N/A',
        ],
        'fees' => $fees,
    ]);
}





}
