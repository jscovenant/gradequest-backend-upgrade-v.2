<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\{FeeType, StudentFee, Payment, PaymentReceipt, User, Section, StudentClass};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\FeePaymentService;
use App\Services\SchoolFeeAccessPolicyService;
use Illuminate\Support\Facades\Log;

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

    /**
     * Get classes with their sections and enrolled students for collective fee assignment
     */
    public function getClassesWithStudents(Request $request)
    {
        $schoolId = Auth::user()->school_id;

        $classes = StudentClass::where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->with('section:id,name')
            ->with(['students' => function ($q) use ($schoolId) {
                $q->where('school_id', $schoolId)
                  ->whereRaw('LOWER(role) = ?', ['student'])
                  ->select('id', 'firstname', 'surname', 'reg_no', 'level_id', 'section_id')
                  ->orderBy('firstname')
                  ->orderBy('surname');
            }])
            ->orderBy('name')
            ->get();

        return response()->json([
            'classes' => $classes,
        ]);
    }

    /**
     * Bulk assign fee types collectively to all or selected students in a class
     */
    public function assignClassFees(Request $request)
    {
        $schoolId = Auth::user()->school_id;

        $request->validate([
            'class_id' => 'required|exists:student_classes,id',
            'section_id' => 'nullable|exists:sections,id',
            'session_id' => 'required|exists:academic_sessions,id',
            'term_id' => 'required|exists:terms,id',
            'fee_type_ids' => 'required|array|min:1',
            'fee_type_ids.*' => 'exists:fee_types,id',
            'student_ids' => 'nullable|array',
            'student_ids.*' => 'exists:users,id',
        ]);

        $class = StudentClass::where('school_id', $schoolId)->findOrFail($request->class_id);
        $sectionId = $request->section_id ?: $class->section_id;

        $studentsQuery = User::where('school_id', $schoolId)
            ->where('level_id', $class->id)
            ->whereRaw('LOWER(role) = ?', ['student']);

        if (!empty($request->student_ids) && is_array($request->student_ids)) {
            $studentsQuery->whereIn('id', $request->student_ids);
        }

        $students = $studentsQuery->get();

        if ($students->isEmpty()) {
            return response()->json(['message' => 'No active students found in this class to assign fees.'], 404);
        }

        $feeTypes = FeeType::whereIn('id', $request->fee_type_ids)
            ->where('school_id', $schoolId)
            ->get();

        if ($feeTypes->isEmpty()) {
            return response()->json(['message' => 'No valid fee types found.'], 404);
        }

        $createdCount = 0;
        $skippedCount = 0;
        $billedStudentCount = 0;

        foreach ($students as $student) {
            $assignedForStudent = false;
            $studentSecId = $sectionId ?: $student->section_id;

            foreach ($feeTypes as $fee) {
                $exists = StudentFee::where([
                    'school_id'   => $schoolId,
                    'student_id'  => $student->id,
                    'session_id'  => $request->session_id,
                    'term_id'     => $request->term_id,
                    'fee_type_id' => $fee->id,
                ])->exists();

                if ($exists) {
                    $skippedCount++;
                    continue;
                }

                StudentFee::create([
                    'school_id'    => $schoolId,
                    'student_id'   => $student->id,
                    'section_id'   => $studentSecId ?: $fee->section_id,
                    'session_id'   => $request->session_id,
                    'term_id'      => $request->term_id,
                    'fee_type_id'  => $fee->id,
                    'total_amount' => $fee->amount,
                    'amount_paid'  => 0,
                    'balance'      => $fee->amount,
                    'status'       => 'unpaid',
                ]);

                $createdCount++;
                $assignedForStudent = true;
            }

            if ($assignedForStudent) {
                $billedStudentCount++;
            }
        }

        $msg = "Successfully assigned fees to {$billedStudentCount} student(s) in {$class->name} ({$createdCount} fee records created).";
        if ($skippedCount > 0) {
            $msg .= " Note: {$skippedCount} existing fee assignments were preserved to prevent duplicates.";
        }

        return response()->json([
            'status' => 'success',
            'message' => $msg,
            'created_count' => $createdCount,
            'skipped_count' => $skippedCount,
            'students_count' => $students->count(),
            'billed_students' => $billedStudentCount,
        ]);
    }

    // ✅ Fetch available fee types after selecting student/class, section, session, and term
    public function fetchFeeTypes(Request $request)
    {
        $request->validate([
            'student_id' => 'nullable|exists:users,id',
            'section_id' => 'nullable|exists:sections,id',
            'class_id' => 'nullable|exists:student_classes,id',
            'session_id' => 'required|exists:academic_sessions,id',
            'term_id' => 'required|exists:terms,id',
        ]);

        $schoolId = Auth::user()->school_id;

        $student = null;
        if ($request->filled('student_id')) {
            $student = User::where('school_id', $schoolId)->find($request->student_id);
        }

        $sectionId = $request->section_id;
        if (!$sectionId && $request->filled('class_id')) {
            $sectionId = StudentClass::where('school_id', $schoolId)->where('id', $request->class_id)->value('section_id');
        }

        $query = FeeType::where('school_id', $schoolId)
            ->where('session_id', $request->session_id)
            ->where('term_id', $request->term_id);

        if ($sectionId) {
            $query->where(function ($q) use ($sectionId) {
                $q->where('section_id', $sectionId)
                  ->orWhereNull('section_id')
                  ->orWhere('section_id', 0);
            });
        }

        $feeTypes = $query->select('id', 'name', 'amount', 'section_id')->get();

        return response()->json([
            'message' => 'Fee types retrieved successfully.',
            'fee_types' => $feeTypes,
            'student' => $student ? [
                'id' => $student->id,
                'name' => "{$student->firstname} {$student->surname}",
                'reg_no' => $student->reg_no,
            ] : null,
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
        'amount' => 'required|numeric|min:0.01',
        'payment_method' => 'required|string|max:50',
        'reference' => 'nullable|string|max:100',
        'apply_full_discount' => 'nullable|boolean',
    ]);

    // ✅ Get the fee record with student info
    $studentFee = StudentFee::where('school_id', $schoolId)
        ->with(['feeType', 'term', 'session', 'student'])
        ->findOrFail($request->student_fee_id);

    // ✅ Prevent duplicate full payment if already settled
    if ($studentFee->balance <= 0 && $studentFee->status === 'paid') {
        return response()->json([
            'message' => 'This fee has already been fully paid for this term and session.',
        ], 422);
    }

    $applyDiscount = (bool) $request->input('apply_full_discount', false);
    $discountAmount = 0.0;

    if ($applyDiscount) {
        $fullDiscount = $this->feeAccessPolicyService->calculateFullPaymentDiscount(
            (int) $schoolId,
            (float) $studentFee->total_amount,
            (float) $studentFee->amount_paid,
            (float) $studentFee->balance
        );
        if ($fullDiscount['enabled']) {
            $discountAmount = (float) $fullDiscount['discount_amount'];
        }
    }

    $payableBalance = max(0, (float) $studentFee->balance - $discountAmount);

    // ✅ Prevent overpayment
    if ($request->amount > ($studentFee->balance + 0.01) && ! ($applyDiscount && abs($request->amount - $payableBalance) < 0.01)) {
        return response()->json([
            'message' => 'Amount exceeds remaining balance of ₦' . number_format($studentFee->balance, 2),
            'balance' => $studentFee->balance,
        ], 422);
    }

    $ref = $request->filled('reference') && trim($request->reference) !== ''
        ? trim($request->reference)
        : uniqid('GQ-PAY-');

    // ✅ Record payment directly
    $payment = Payment::create([
        'student_fee_id' => $studentFee->id,
        'amount' => $request->amount,
        'payment_method' => $request->payment_method,
        'school_id' => $schoolId,
        'reference' => $ref,
        'received_by' => $user->id,
    ]);

    $receiptNotes = 'Offline fee payment (' . strtoupper($request->payment_method) . '): ' . $ref;
    if ($discountAmount > 0) {
        $receiptNotes .= ' (Full Payment Discount of ₦' . number_format($discountAmount, 2) . ' applied)';
    }

    // ✅ If an uploaded receipt exists for this student and payment method, approve it
    $receipt = PaymentReceipt::where('student_id', $studentFee->student_id)
        ->where('school_id', $schoolId)
        ->where('payment_method', $request->payment_method)
        ->where('status', 'pending')
        ->latest()
        ->first();

    if ($receipt) {
        $receipt->status = 'approved';
        $receipt->approved_by = $user->id;
        $receipt->notes = $receiptNotes;
        $receipt->save();
    } else {
        PaymentReceipt::create([
            'student_id' => $studentFee->student_id,
            'school_id' => $schoolId,
            'payment_id' => $payment->id,
            'payment_method' => $request->payment_method,
            'status' => 'approved',
            'notes' => $receiptNotes,
        ]);
    }

    // ✅ Update totals & status
    $effectiveAmount = (float) $request->amount + $discountAmount;
    $studentFee->amount_paid += $effectiveAmount;
    $studentFee->balance = max(0, (float) $studentFee->total_amount - (float) $studentFee->amount_paid);

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
        'message' => 'Payment recorded successfully.' . ($discountAmount > 0 ? ' Full payment discount of ₦' . number_format($discountAmount, 2) . ' applied.' : ''),
        'payment' => $payment,
        'discount_amount' => $discountAmount,
        'balance' => $studentFee->balance,
        'amount_paid' => $studentFee->amount_paid,
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

        $totalTermAmount = (float) $fees->sum('total_amount');
        $totalTermPaid = (float) $fees->sum('amount_paid');
        $totalBalance = (float) $fees->sum('balance');

        $installmentPlan = $this->feeAccessPolicyService->calculateInstallmentPlan(
            (int) $schoolId,
            $totalTermAmount,
            $totalTermPaid,
            $totalBalance
        );

    return response()->json([
        'student' => [
            'id' => $student->id,
            'name' => "{$student->firstname} {$student->surname}",
            'reg_no' => $student->reg_no,
            'section' => optional($student->section)->name ?? 'N/A',
            'class' => optional($student->level)->name ?? 'N/A',
        ],
        'installment_plan' => $installmentPlan,
        'full_payment_discount' => $installmentPlan['full_payment_discount'] ?? null,
        'fees' => $fees,
    ]);
}



  public function __construct(
      private FeePaymentService $feePaymentService,
      private SchoolFeeAccessPolicyService $feeAccessPolicyService
  )
    {
    }

    // ... your existing methods stay exactly as they are ...

    /**
     * ✅ Parent-initiated online payment via Paystack split.
     */
  public function initializeOnlinePayment(Request $request)
{
    $request->validate([
        'student_fee_id' => 'required|exists:student_fees,id',
        'amount' => 'required|integer|min:100', 
        'email' => 'nullable|email',
    ]);

    $schoolId = Auth::user()->school_id;
    $studentFee = StudentFee::where('school_id', $schoolId)->findOrFail($request->student_fee_id);

    if ($request->amount > $studentFee->balance) {
        return response()->json([
            'message' => 'Amount exceeds remaining balance.',
            'balance' => $studentFee->balance,
        ], 422);
    }

    $payerEmail = $request->email ?: Auth::user()->email;

    $origin = $request->input('callback_url')
        ?: $request->header('origin')
        ?: ($request->header('referer') ? rtrim(parse_url($request->header('referer'), PHP_URL_SCHEME) . '://' . parse_url($request->header('referer'), PHP_URL_HOST), '/') : null)
        ?: rtrim((string) (config('app.frontend_url') ?: 'https://gradequest.com.ng'), '/');

    $callbackUrl = str_contains($origin, '/parent') ? $origin : rtrim($origin, '/') . '/parent/payments';

    try {
        $result = $this->feePaymentService->initialize(
            $studentFee,
            (int) $request->amount,
            $payerEmail,
            Auth::id(),
            $callbackUrl
        );
    } catch (\RuntimeException $e) {
        return response()->json(['message' => $e->getMessage()], 422);
    }

    return response()->json($result);
}

    /**
     * ✅ Frontend callback page hits this to confirm payment status.
     */
    public function verifyOnlinePayment(string $reference)
    {
        $payment = $this->feePaymentService->verify($reference, (int) Auth::user()->school_id);

        return response()->json([
            'status' => $payment->status,
            'reference' => $payment->reference,
            'amount' => $payment->amount,
            'platform_fee' => $payment->platform_fee,
        ]);
    }

    /**
     * ✅ Paystack server-to-server webhook — not user-authenticated,
     * verified via the x-paystack-signature header instead.
     */
    public function paystackWebhook(Request $request)
    {
        $signature = $request->header('x-paystack-signature');
        $secret = config('services.paystack.secret');
        $expected = hash_hmac('sha512', $request->getContent(), $secret);

        if (! $signature || ! hash_equals($expected, $signature)) {
            Log::warning('Paystack webhook signature mismatch');
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $this->feePaymentService->handleWebhook($request->json()->all());

        return response()->json(['status' => 'ok']);
    }


}
