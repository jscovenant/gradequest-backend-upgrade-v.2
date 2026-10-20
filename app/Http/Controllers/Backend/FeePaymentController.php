<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\{FeeType, StudentFee, Payment, PaymentReceipt, User, Section, StudentClass, AcademicSession, Term, SchoolSetting};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\FeePaymentService;
use App\Services\SchoolFeeAccessPolicyService;
use Illuminate\Support\Facades\Log;

class FeePaymentController extends Controller
{
    public function __construct(
        private FeePaymentService $feePaymentService,
        private SchoolFeeAccessPolicyService $feeAccessPolicyService
    ) {
    }

    /**
     * Resolve accessible section IDs for the authenticated bursar.
     * Returns empty array if user has unrestricted access (Admin, Proprietor, Chief Bursar).
     */
    protected function scopedSectionIds($user): array
    {
        $role = strtolower((string) ($user->role ?? ''));
        if ($role === 'bursar') {
            return $user->getBursarAssignedSectionIds();
        }
        return [];
    }

    /**
     * Generate unique sequential receipt number for a school this year.
     * Format: SP/REC/{YEAR}/{00001}
     */
    protected function generateReceiptNumber(int $schoolId): string
    {
        $year = date('Y');
        $count = Payment::where('school_id', $schoolId)
            ->whereYear('created_at', $year)
            ->count() + 1;
        return sprintf('SP/REC/%s/%05d', $year, $count);
    }

    /**
     * Generate secure verification token for QR code.
     */
    protected function generateQrToken(): string
    {
        return Str::random(32);
    }

    // ✅ Get fee structure by section, session & term
    public function getFeeStructure($sectionId, $sessionId, $termId)
    {
        $user = Auth::user();
        $schoolId = $user->school_id;
        $scoped = $this->scopedSectionIds($user);

        if (!empty($scoped) && !in_array((int) $sectionId, $scoped, true)) {
            return response()->json(['message' => 'Unauthorized section access.'], 403);
        }

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
        $user = Auth::user();
        $schoolId = $user->school_id;
        $scoped = $this->scopedSectionIds($user);
        $sectionIdFilter = $request->query('section_id');

        $query = StudentClass::where('school_id', $schoolId)
            ->whereNull('archived_at');

        if (!empty($scoped)) {
            $query->whereIn('section_id', $scoped);
        } elseif ($sectionIdFilter) {
            $query->where('section_id', (int) $sectionIdFilter);
        }

        $classes = $query->with('section:id,name')
            ->with(['students' => function ($q) use ($schoolId) {
                $q->where('school_id', $schoolId)
                  ->whereRaw('LOWER(role) = ?', ['student'])
                  ->where('student_status', 'active')
                  ->select('id', 'firstname', 'surname', 'reg_no', 'level_id', 'section_id')
                  ->orderBy('firstname')
                  ->orderBy('surname');
            }])
            ->orderBy('name')
            ->get();

        return response()->json([
            'classes' => $classes,
            'scoped_sections' => $scoped,
        ]);
    }

    /**
     * Bulk assign fee types collectively to all or selected students in a class
     */
    public function assignClassFees(Request $request)
    {
        $user = Auth::user();
        $schoolId = $user->school_id;

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

        $scoped = $this->scopedSectionIds($user);
        if (!empty($scoped) && !in_array((int) $sectionId, $scoped, true)) {
            return response()->json(['message' => 'Unauthorized: You are not assigned to this class section.'], 403);
        }

        $studentsQuery = User::where('school_id', $schoolId)
            ->where('level_id', $class->id)
            ->whereRaw('LOWER(role) = ?', ['student'])
            ->where('student_status', 'active');

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

                $gross = (float) $fee->amount;

                StudentFee::create([
                    'school_id'       => $schoolId,
                    'student_id'      => $student->id,
                    'section_id'      => $studentSecId ?: $fee->section_id,
                    'session_id'      => $request->session_id,
                    'term_id'         => $request->term_id,
                    'fee_type_id'     => $fee->id,
                    'gross_amount'    => $gross,
                    'discount_amount' => 0.00,
                    'discount_status' => 'none',
                    'net_amount'      => $gross,
                    'total_amount'    => $gross,
                    'amount_paid'     => 0,
                    'balance'         => $gross,
                    'status'          => 'unpaid',
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

        $user = Auth::user();
        $schoolId = $user->school_id;

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

        $feeTypes = $query->select('id', 'name', 'amount', 'section_id', 'is_discountable', 'is_compulsory')->get();

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
        $user = Auth::user();
        $schoolId = $user->school_id;
        $student = User::where('school_id', $schoolId)->with(['studentFees.feeType'])->findOrFail($studentId);

        return response()->json([
            'student' => $student->name,
            'fees' => $student->studentFees->map(function ($fee) {
                return [
                    'id' => $fee->id,
                    'type' => $fee->feeType->name ?? 'N/A',
                    'gross_amount' => (float) ($fee->gross_amount ?: $fee->total_amount),
                    'discount_amount' => (float) ($fee->discount_amount ?? 0),
                    'discount_status' => $fee->discount_status ?? 'none',
                    'net_amount' => (float) ($fee->net_amount ?: $fee->total_amount),
                    'amount' => (float) ($fee->net_amount ?: $fee->total_amount),
                    'amount_paid' => (float) $fee->amount_paid,
                    'balance' => (float) $fee->balance,
                    'status' => $fee->status,
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
        $user = Auth::user();
        $studentFee = StudentFee::where('school_id', $user->school_id)->findOrFail($studentFeeId);

        if ($studentFee->amount_paid > 0 || $studentFee->status === 'paid') {
            return response()->json([
                'message' => 'You cannot delete a fee that has already received payments.'
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
        $user = Auth::user();
        $schoolId = $user->school_id;

        $request->validate([
            'student_id' => 'required|exists:users,id',
            'section_id' => 'required|exists:sections,id',
            'session_id' => 'required|exists:academic_sessions,id',
            'term_id' => 'required|exists:terms,id',
            'fee_type_ids' => 'required|array|min:1',
            'fee_type_ids.*' => 'exists:fee_types,id',
        ]);

        $student = User::where('school_id', $schoolId)->find($request->student_id);
        if (!$student) {
            return response()->json(['message' => 'Student not found or unauthorized'], 404);
        }

        $feeTypes = FeeType::whereIn('id', $request->fee_type_ids)
            ->where('school_id', $schoolId)
            ->get();

        if ($feeTypes->isEmpty()) {
            return response()->json(['message' => 'No valid fee types found'], 404);
        }

        $alreadyAssigned = [];
        $newlyAssigned = [];

        foreach ($feeTypes as $fee) {
            $exists = StudentFee::where([
                'school_id'   => $schoolId,
                'student_id'  => $student->id,
                'section_id'  => $request->section_id,
                'session_id'  => $request->session_id,
                'term_id'     => $request->term_id,
                'fee_type_id' => $fee->id,
            ])->exists();

            if ($exists) {
                $alreadyAssigned[] = $fee->name;
                continue;
            }

            $gross = (float) $fee->amount;

            $record = StudentFee::create([
                'school_id'       => $schoolId,
                'student_id'      => $student->id,
                'section_id'      => $request->section_id,
                'session_id'      => $request->session_id,
                'term_id'         => $request->term_id,
                'fee_type_id'     => $fee->id,
                'gross_amount'    => $gross,
                'discount_amount' => 0.00,
                'discount_status' => 'none',
                'net_amount'      => $gross,
                'total_amount'    => $gross,
                'balance'         => $gross,
                'status'          => 'unpaid',
            ]);

            $newlyAssigned[] = $record;
        }

        $message = 'Fee(s) assigned successfully.';
        if (count($alreadyAssigned) > 0) {
            $message .= ' Note: already assigned fee(s) were preserved: ' . implode(', ', $alreadyAssigned);
        }

        return response()->json([
            'message' => $message,
            'newly_assigned' => $newlyAssigned,
            'already_assigned' => $alreadyAssigned,
            'total_new_amount' => collect($newlyAssigned)->sum('total_amount'),
        ]);
    }

    /**
     * ✅ Apply a Concession / Discount to a student's fee
     */
    public function applyConcession(Request $request)
    {
        $user = Auth::user();
        $schoolId = $user->school_id;

        $request->validate([
            'student_fee_id' => 'required|exists:student_fees,id',
            'discount_type' => 'required|in:percentage,fixed',
            'discount_value' => 'required|numeric|min:0.01',
            'discount_reason' => 'required|string|max:100', // staff_child, sibling, scholarship, hardship, custom
            'discount_notes' => 'nullable|string|max:500',
        ]);

        $fee = StudentFee::where('school_id', $schoolId)
            ->with(['feeType', 'student'])
            ->findOrFail($request->student_fee_id);

        $scoped = $this->scopedSectionIds($user);
        if (!empty($scoped) && !in_array((int) $fee->section_id, $scoped, true)) {
            return response()->json(['message' => 'Unauthorized: This fee record belongs to another section.'], 403);
        }

        if ($fee->feeType && isset($fee->feeType->is_discountable) && !$fee->feeType->is_discountable) {
            return response()->json([
                'message' => "The fee item '{$fee->feeType->name}' is strictly marked non-discountable (e.g. books, uniform, external levies).",
            ], 422);
        }

        $gross = (float) ($fee->gross_amount ?: $fee->total_amount);
        $val = (float) $request->discount_value;

        if ($request->discount_type === 'percentage') {
            if ($val > 100) {
                return response()->json(['message' => 'Percentage discount cannot exceed 100%.'], 422);
            }
            $discountAmount = round(($gross * $val) / 100, 2);
            $discountRate = $val;
        } else {
            if ($val > $gross) {
                return response()->json(['message' => 'Fixed discount cannot exceed the gross fee of ₦' . number_format($gross, 2)], 422);
            }
            $discountAmount = $val;
            $discountRate = ($gross > 0) ? round(($val / $gross) * 100, 2) : 0;
        }

        $userRole = strtolower((string) ($user->role ?? ''));
        $isAdminOrOwner = in_array($userRole, ['admin', 'super_admin', 'superadmin', 'proprietor', 'director'], true);

        if ($isAdminOrOwner) {
            // Instant approval by School Owner / Admin
            $netAmount = max(0, $gross - $discountAmount);
            $newBalance = max(0, $netAmount - (float) $fee->amount_paid);

            $fee->update([
                'gross_amount' => $gross,
                'discount_amount' => $discountAmount,
                'discount_type' => $request->discount_type,
                'discount_rate' => $discountRate,
                'discount_reason' => $request->discount_reason,
                'discount_status' => 'approved',
                'discount_approved_by' => $user->id,
                'discount_notes' => $request->discount_notes,
                'net_amount' => $netAmount,
                'total_amount' => $netAmount,
                'balance' => $newBalance,
                'status' => ($newBalance <= 0 && $fee->amount_paid > 0) ? 'paid' : (($fee->amount_paid > 0) ? 'partial' : 'unpaid'),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Concession applied and approved immediately (Gross: ₦' . number_format($gross, 2) . ', Discount: ₦' . number_format($discountAmount, 2) . ', Net Payable: ₦' . number_format($netAmount, 2) . ').',
                'fee' => $fee->fresh(['feeType', 'discountApprover']),
            ]);
        }

        // Bursar application -> requires Admin/Proprietor approval
        $fee->update([
            'gross_amount' => $gross,
            'discount_amount' => $discountAmount,
            'discount_type' => $request->discount_type,
            'discount_rate' => $discountRate,
            'discount_reason' => $request->discount_reason,
            'discount_status' => 'pending',
            'discount_approved_by' => null,
            'discount_notes' => $request->discount_notes,
        ]);

        return response()->json([
            'status' => 'pending',
            'message' => 'Concession request submitted successfully and queued for School Owner / Admin approval.',
            'fee' => $fee->fresh(['feeType']),
        ]);
    }

    /**
     * ✅ Approve a pending Concession (Admin/Proprietor only)
     */
    public function approveConcession(Request $request, $id)
    {
        $user = Auth::user();
        $schoolId = $user->school_id;

        $fee = StudentFee::where('school_id', $schoolId)->findOrFail($id);

        if ($fee->discount_status !== 'pending') {
            return response()->json(['message' => 'This fee does not have a pending concession request.'], 422);
        }

        $gross = (float) ($fee->gross_amount ?: $fee->total_amount);
        $discountAmount = (float) $fee->discount_amount;
        $netAmount = max(0, $gross - $discountAmount);
        $newBalance = max(0, $netAmount - (float) $fee->amount_paid);

        $fee->update([
            'discount_status' => 'approved',
            'discount_approved_by' => $user->id,
            'net_amount' => $netAmount,
            'total_amount' => $netAmount,
            'balance' => $newBalance,
            'status' => ($newBalance <= 0 && $fee->amount_paid > 0) ? 'paid' : (($fee->amount_paid > 0) ? 'partial' : 'unpaid'),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Concession approved successfully.',
            'fee' => $fee->fresh(['feeType', 'discountApprover']),
        ]);
    }

    /**
     * ✅ Reject a pending Concession (Admin/Proprietor only)
     */
    public function rejectConcession(Request $request, $id)
    {
        $user = Auth::user();
        $schoolId = $user->school_id;

        $fee = StudentFee::where('school_id', $schoolId)->findOrFail($id);

        $gross = (float) ($fee->gross_amount ?: $fee->total_amount);
        $newBalance = max(0, $gross - (float) $fee->amount_paid);

        $fee->update([
            'discount_status' => 'rejected',
            'discount_amount' => 0.00,
            'discount_approved_by' => $user->id,
            'discount_notes' => $request->input('notes', 'Rejected by administration.'),
            'net_amount' => $gross,
            'total_amount' => $gross,
            'balance' => $newBalance,
            'status' => ($newBalance <= 0 && $fee->amount_paid > 0) ? 'paid' : (($fee->amount_paid > 0) ? 'partial' : 'unpaid'),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Concession request rejected.',
            'fee' => $fee->fresh(['feeType']),
        ]);
    }

    /**
     * ✅ Pending Concessions list for Admin/Proprietor
     */
    public function pendingConcessions(Request $request)
    {
        $user = Auth::user();
        $schoolId = $user->school_id;

        $status = $request->query('status', 'pending');

        $query = StudentFee::where('school_id', $schoolId);

        if ($status === 'all') {
            $query->whereIn('discount_status', ['pending', 'approved', 'rejected']);
        } elseif ($status) {
            $query->where('discount_status', $status);
        } else {
            $query->where('discount_status', 'pending');
        }

        $items = $query->with([
                'student:id,firstname,surname,reg_no,level_id,section_id',
                'feeType:id,name,amount',
                'session:id,name',
                'term:id,name',
                'section:id,name',
                'discountApprover:id,firstname,surname'
            ])
            ->latest('updated_at')
            ->paginate($request->integer('per_page', 15));

        $pendingCount = StudentFee::where('school_id', $schoolId)->where('discount_status', 'pending')->count();

        return response()->json([
            'data' => $items->items(),
            'current_page' => $items->currentPage(),
            'last_page' => $items->lastPage(),
            'total' => $items->total(),
            'pending_count' => $pendingCount,
        ]);
    }

    /**
     * ✅ Record offline fee payment with serialized receipt number and verification token
     */
    public function payFee(Request $request)
    {
        $user = Auth::user();
        $schoolId = $user->school_id;

        $request->validate([
            'student_fee_id' => 'required|exists:student_fees,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string|max:50',
            'reference' => 'nullable|string|max:100',
            'payer_name' => 'nullable|string|max:150',
            'payer_phone' => 'nullable|string|max:30',
        ]);

        $studentFee = StudentFee::where('school_id', $schoolId)
            ->with(['feeType', 'term', 'session', 'student', 'section'])
            ->findOrFail($request->student_fee_id);

        $scoped = $this->scopedSectionIds($user);
        if (!empty($scoped) && !in_array((int) $studentFee->section_id, $scoped, true)) {
            return response()->json(['message' => 'Unauthorized: This fee record belongs to another section.'], 403);
        }

        $gross = (float) ($studentFee->gross_amount ?: $studentFee->total_amount);
        $discount = ($studentFee->discount_status === 'approved') ? (float) $studentFee->discount_amount : 0.00;
        $net = max(0, $gross - $discount);
        $currentPaid = (float) $studentFee->amount_paid;
        $remainingBalance = max(0, $net - $currentPaid);

        if ($remainingBalance <= 0) {
            return response()->json([
                'message' => 'This fee has already been fully settled for this term.',
            ], 422);
        }

        $payAmount = (float) $request->amount;
        if ($payAmount > ($remainingBalance + 0.01)) {
            return response()->json([
                'message' => 'Amount exceeds remaining balance of ₦' . number_format($remainingBalance, 2),
                'balance' => $remainingBalance,
            ], 422);
        }

        $ref = $request->filled('reference') && trim($request->reference) !== ''
            ? trim($request->reference)
            : 'SP-PAY-' . strtoupper(Str::random(10));

        $receiptNo = $this->generateReceiptNumber($schoolId);
        $qrToken = $this->generateQrToken();

        // 1. Create Payment record
        $payment = Payment::create([
            'student_fee_id' => $studentFee->id,
            'amount'         => $payAmount,
            'current_paid'   => $payAmount,
            'arrears_paid'   => 0.00,
            'payment_method' => $request->payment_method,
            'school_id'      => $schoolId,
            'reference'      => $ref,
            'receipt_number' => $receiptNo,
            'qr_code_token'  => $qrToken,
            'received_by'    => $user->id,
            'payer_name'     => $request->payer_name,
            'payer_phone'    => $request->payer_phone,
        ]);

        // 2. Update StudentFee totals (Double-entry: ONLY actual cash received increments amount_paid)
        $newPaid = $currentPaid + $payAmount;
        $newBalance = max(0, $net - $newPaid);
        $newStatus = ($newBalance <= 0) ? 'paid' : (($newPaid > 0) ? 'partial' : 'unpaid');

        $studentFee->update([
            'gross_amount' => $gross,
            'net_amount'   => $net,
            'total_amount' => $net,
            'amount_paid'  => $newPaid,
            'balance'      => $newBalance,
            'status'       => $newStatus,
        ]);

        // 3. Auto-create approved PaymentReceipt log
        $receiptNotes = 'Official SchoolProfit offline receipt (' . strtoupper($request->payment_method) . ') ' . $receiptNo;
        PaymentReceipt::create([
            'student_id'     => $studentFee->student_id,
            'school_id'      => $schoolId,
            'payment_id'     => $payment->id,
            'payment_method' => $request->payment_method,
            'status'         => 'approved',
            'notes'          => $receiptNotes,
        ]);

        $school = SchoolSetting::find($schoolId);

        $origin = $request->header('origin') 
            ?: ($request->header('referer') ? rtrim(parse_url($request->header('referer'), PHP_URL_SCHEME) . '://' . parse_url($request->header('referer'), PHP_URL_HOST), '/') : null) 
            ?: $request->root();
        $verifyUrl = rtrim($origin, '/') . '/receipt/verify/' . $qrToken;

        return response()->json([
            'message' => 'Payment recorded successfully.',
            'receipt' => [
                'receipt_number'   => $receiptNo,
                'qr_code_token'    => $qrToken,
                'qr_verify_url'    => $verifyUrl,
                'payment_id'       => $payment->id,
                'payment_method'   => $request->payment_method,
                'amount_paid'      => $payAmount,
                'gross_amount'     => $gross,
                'discount_amount'  => $discount,
                'net_amount'       => $net,
                'balance'          => $newBalance,
                'status'           => $newStatus,
                'reference'        => $ref,
                'payer_name'       => $request->payer_name,
                'payer_phone'      => $request->payer_phone,
                'date'             => $payment->created_at->format('d M, Y h:i A'),
                'bursar_name'      => trim(($user->firstname ?? '') . ' ' . ($user->surname ?? '')),
                'student_name'     => trim(($studentFee->student->firstname ?? '') . ' ' . ($studentFee->student->surname ?? '')),
                'admission_no'     => $studentFee->student->reg_no ?? 'N/A',
                'section_name'     => $studentFee->section->name ?? 'N/A',
                'fee_name'         => $studentFee->feeType->name ?? 'School Fee',
                'session_name'     => $studentFee->session->name ?? 'N/A',
                'term_name'        => $studentFee->term->name ?? 'N/A',
                'school_name'      => $school->school_name ?? 'SchoolProfit School',
                'school_logo'      => $school->logo ? asset($school->logo) : null,
                'school_address'   => $school->address ?? '',
                'school_phone'     => $school->phone_number ?? '',
            ],
            'payment' => $payment,
            'balance' => $newBalance,
            'amount_paid' => $newPaid,
            'status' => $newStatus,
        ]);
    }

    /**
     * ✅ Record Consolidated / Pay All Fees at once for a student
     */
    public function payBulkFees(Request $request)
    {
        $user = Auth::user();
        $schoolId = $user->school_id;

        $request->validate([
            'student_id'     => 'required|exists:users,id',
            'amount'         => 'required|numeric|min:1',
            'payment_method' => 'required|string',
            'reference'      => 'nullable|string|max:100',
            'payer_name'     => 'nullable|string|max:255',
            'payer_phone'    => 'nullable|string|max:50',
            'items'          => 'nullable|array',
            'items.*.student_fee_id' => 'required_with:items|exists:student_fees,id',
            'items.*.amount'         => 'required_with:items|numeric|min:0.01',
        ]);

        $student = User::where('school_id', $schoolId)->findOrFail($request->student_id);

        $scoped = $this->scopedSectionIds($user);
        if (!empty($scoped) && !in_array((int) $student->section_id, $scoped, true)) {
            return response()->json(['message' => 'Unauthorized: This student belongs to another section.'], 403);
        }

        $totalPayAmount = (float) $request->amount;
        $allocations = [];

        // Find all unpaid or partially paid fees for this student
        $unpaidFees = StudentFee::where('school_id', $schoolId)
            ->where('student_id', $student->id)
            ->where('balance', '>', 0)
            ->with(['feeType', 'section', 'session', 'term'])
            ->orderBy('id', 'asc')
            ->get();

        if ($unpaidFees->isEmpty()) {
            return response()->json(['message' => 'All assigned fees for this student are already fully settled.'], 422);
        }

        $totalOutstanding = (float) $unpaidFees->sum('balance');
        if ($totalPayAmount > ($totalOutstanding + 0.05)) {
            return response()->json([
                'message' => 'Payment amount exceeds student total outstanding balance of ₦' . number_format($totalOutstanding, 2),
                'total_outstanding' => $totalOutstanding,
            ], 422);
        }

        if (!empty($request->items) && is_array($request->items)) {
            $sumAllocated = 0.0;
            $feeMap = $unpaidFees->keyBy('id');

            foreach ($request->items as $item) {
                $sfId = (int) $item['student_fee_id'];
                $itemAmt = (float) $item['amount'];
                if ($itemAmt <= 0) continue;

                if (!$feeMap->has($sfId)) {
                    return response()->json(['message' => "Fee record #{$sfId} is not an outstanding fee for this student."], 422);
                }

                $sf = $feeMap->get($sfId);
                if ($itemAmt > ($sf->balance + 0.01)) {
                    $feeName = $sf->feeType->name ?? "Fee #{$sf->id}";
                    return response()->json(['message' => "Allocated amount for '{$feeName}' exceeds remaining balance of ₦" . number_format($sf->balance, 2)], 422);
                }

                $allocations[] = [
                    'fee' => $sf,
                    'amount' => $itemAmt,
                ];
                $sumAllocated += $itemAmt;
            }

            if (abs($sumAllocated - $totalPayAmount) > 0.05) {
                return response()->json([
                    'message' => 'The sum of allocated item amounts (₦' . number_format($sumAllocated, 2) . ') does not match total payment amount (₦' . number_format($totalPayAmount, 2) . ').'
                ], 422);
            }
        } else {
            // Auto-allocate sequentially
            $remainingToAllocate = $totalPayAmount;

            foreach ($unpaidFees as $sf) {
                if ($remainingToAllocate <= 0) break;

                $alloc = min($remainingToAllocate, (float) $sf->balance);
                if ($alloc > 0) {
                    $allocations[] = [
                        'fee' => $sf,
                        'amount' => $alloc,
                    ];
                    $remainingToAllocate -= $alloc;
                }
            }
        }

        if (empty($allocations)) {
            return response()->json(['message' => 'No fee allocations were determined for this payment.'], 422);
        }

        $receiptNo = $this->generateReceiptNumber($schoolId);
        $primaryQrToken = $this->generateQrToken();
        $groupRef = $request->filled('reference') && trim($request->reference) !== ''
            ? trim($request->reference)
            : 'SP-BULK-' . strtoupper(Str::random(10));

        $paymentsCreated = [];
        $receiptBreakdown = [];
        $totalGross = 0.0;
        $totalDiscount = 0.0;
        $totalNet = 0.0;
        $totalRealized = 0.0;

        DB::transaction(function () use (
            $allocations, $schoolId, $user, $request, $receiptNo, $primaryQrToken, $groupRef,
            &$paymentsCreated, &$receiptBreakdown, &$totalGross, &$totalDiscount, &$totalNet, &$totalRealized
        ) {
            $isFirst = true;

            foreach ($allocations as $index => $alloc) {
                /** @var StudentFee $sf */
                $sf = $alloc['fee'];
                $payAmt = $alloc['amount'];

                $gross = (float) ($sf->gross_amount ?: $sf->total_amount);
                $discount = ($sf->discount_status === 'approved') ? (float) $sf->discount_amount : 0.00;
                $net = max(0, $gross - $discount);
                $currentPaid = (float) $sf->amount_paid;

                $itemRef = $groupRef . ($index > 0 ? "-{$index}" : '');
                $itemQrToken = $isFirst ? $primaryQrToken : $this->generateQrToken();
                $isFirst = false;

                // 1. Payment record
                $payment = Payment::create([
                    'student_fee_id' => $sf->id,
                    'amount'         => $payAmt,
                    'current_paid'   => $payAmt,
                    'arrears_paid'   => 0.00,
                    'payment_method' => $request->payment_method,
                    'school_id'      => $schoolId,
                    'reference'      => $itemRef,
                    'receipt_number' => $receiptNo,
                    'qr_code_token'  => $itemQrToken,
                    'received_by'    => $user->id,
                    'payer_name'     => $request->payer_name,
                    'payer_phone'    => $request->payer_phone,
                ]);

                // 2. Update StudentFee
                $newPaid = $currentPaid + $payAmt;
                $newBalance = max(0, $net - $newPaid);
                $newStatus = ($newBalance <= 0) ? 'paid' : (($newPaid > 0) ? 'partial' : 'unpaid');

                $sf->update([
                    'gross_amount' => $gross,
                    'net_amount'   => $net,
                    'total_amount' => $net,
                    'amount_paid'  => $newPaid,
                    'balance'      => $newBalance,
                    'status'       => $newStatus,
                ]);

                // 3. PaymentReceipt
                PaymentReceipt::create([
                    'student_id'     => $sf->student_id,
                    'school_id'      => $schoolId,
                    'payment_id'     => $payment->id,
                    'payment_method' => $request->payment_method,
                    'status'         => 'approved',
                    'notes'          => 'Consolidated SchoolProfit receipt (' . strtoupper($request->payment_method) . ') ' . $receiptNo,
                ]);

                $feeName = $sf->feeType->name ?? "Fee #{$sf->fee_type_id}";

                $receiptBreakdown[] = [
                    'student_fee_id' => $sf->id,
                    'fee_name'       => $feeName,
                    'gross_amount'   => $gross,
                    'discount_amount'=> $discount,
                    'net_amount'     => $net,
                    'amount_paid'    => $payAmt,
                    'balance'        => $newBalance,
                ];

                $totalGross += $gross;
                $totalDiscount += $discount;
                $totalNet += $net;
                $totalRealized += $payAmt;
                $paymentsCreated[] = $payment;
            }
        });

        $newOverallBalance = (float) StudentFee::where('school_id', $schoolId)
            ->where('student_id', $student->id)
            ->sum('balance');

        $school = SchoolSetting::find($schoolId);
        $origin = $request->header('origin') 
            ?: ($request->header('referer') ? rtrim(parse_url($request->header('referer'), PHP_URL_SCHEME) . '://' . parse_url($request->header('referer'), PHP_URL_HOST), '/') : null) 
            ?: $request->root();
        $verifyUrl = rtrim($origin, '/') . '/receipt/verify/' . $primaryQrToken;

        $firstFee = $allocations[0]['fee'];

        return response()->json([
            'message' => 'Consolidated payment of ₦' . number_format($totalRealized, 2) . ' successfully recorded for ' . count($receiptBreakdown) . ' fee item(s).',
            'receipt' => [
                'receipt_number'   => $receiptNo,
                'qr_code_token'    => $primaryQrToken,
                'qr_verify_url'    => $verifyUrl,
                'payment_id'       => $paymentsCreated[0]->id ?? 0,
                'payment_method'   => $request->payment_method,
                'amount_paid'      => $totalRealized,
                'gross_amount'     => $totalGross,
                'discount_amount'  => $totalDiscount,
                'net_amount'       => $totalNet,
                'balance'          => $newOverallBalance,
                'status'           => $newOverallBalance <= 0 ? 'paid' : 'partial',
                'reference'        => $groupRef,
                'payer_name'       => $request->payer_name,
                'payer_phone'      => $request->payer_phone,
                'date'             => now()->format('d M, Y h:i A'),
                'bursar_name'      => trim(($user->firstname ?? '') . ' ' . ($user->surname ?? '')),
                'student_name'     => trim(($student->firstname ?? '') . ' ' . ($student->surname ?? '')),
                'admission_no'     => $student->reg_no ?? 'N/A',
                'section_name'     => $firstFee->section->name ?? 'N/A',
                'fee_name'         => count($receiptBreakdown) === 1 ? $receiptBreakdown[0]['fee_name'] : 'Consolidated Fees (' . count($receiptBreakdown) . ' items)',
                'session_name'     => $firstFee->session->name ?? 'N/A',
                'term_name'        => $firstFee->term->name ?? 'N/A',
                'school_name'      => $school->school_name ?? 'SchoolProfit School',
                'school_logo'      => $school->logo ? asset($school->logo) : null,
                'school_address'   => $school->address ?? '',
                'school_phone'     => $school->phone_number ?? '',
                'items'            => $receiptBreakdown,
            ]
        ]);
    }

    /**
     * ✅ Student fee details with Past Arrears roll-forward and gross-to-net accounting breakdown
     */
    public function studentFeeDetails(Request $request)
    {
        $user = Auth::user();
        $schoolId = $user->school_id;
        $regNo = $request->query('reg_no');
        $sessionId = $request->query('session_id');
        $termId = $request->query('term_id');

        if (!$regNo) {
            return response()->json(['message' => 'Registration number is required'], 400);
        }

        $student = User::with(['section:id,name', 'level:id,name'])
            ->where('school_id', $schoolId)
            ->where('reg_no', $regNo)
            ->first();

        if (!$student) {
            return response()->json(['message' => 'Student not found in this school.'], 404);
        }

        // Section isolation check for bursars
        $scoped = $this->scopedSectionIds($user);
        if (!empty($scoped) && !in_array((int) $student->section_id, $scoped, true)) {
            return response()->json(['message' => 'Unauthorized: This student belongs to another section.'], 403);
        }

        // Current period fees
        $fees = StudentFee::with([
                'feeType:id,name,amount,is_discountable',
                'session:id,name',
                'term:id,name',
                'discountApprover:id,firstname,surname'
            ])
            ->where('student_id', $student->id)
            ->where('school_id', $schoolId)
            ->when($sessionId, fn($q) => $q->where('session_id', $sessionId))
            ->when($termId, fn($q) => $q->where('term_id', $termId))
            ->get();

        // Calculate Past Arrears (unpaid fees from previous sessions or terms)
        $pastArrearsQuery = StudentFee::where('student_id', $student->id)
            ->where('school_id', $schoolId)
            ->where('balance', '>', 0)
            ->with(['feeType:id,name', 'session:id,name', 'term:id,name']);

        if ($sessionId && $termId) {
            $pastArrearsQuery->where(function ($q) use ($sessionId, $termId) {
                $q->where('session_id', '<', $sessionId)
                  ->orWhere(function ($sub) use ($sessionId, $termId) {
                      $sub->where('session_id', '=', $sessionId)
                          ->where('term_id', '<', $termId);
                  });
            });
        }

        $pastArrearsFees = $pastArrearsQuery->get();
        $pastArrearsTotal = (float) $pastArrearsFees->sum('balance');

        $grossTotal = (float) $fees->sum(fn($f) => (float) ($f->gross_amount ?: $f->total_amount));
        $discountTotal = (float) $fees->where('discount_status', 'approved')->sum('discount_amount');
        $netTotal = max(0, $grossTotal - $discountTotal);
        $totalPaid = (float) $fees->sum('amount_paid');
        $currentBalance = (float) $fees->sum('balance');
        $totalDueIncludingArrears = $currentBalance + $pastArrearsTotal;

        $installmentPlan = $this->feeAccessPolicyService->calculateInstallmentPlan(
            (int) $schoolId,
            $netTotal,
            $totalPaid,
            $currentBalance
        );

        return response()->json([
            'student' => [
                'id'       => $student->id,
                'name'     => "{$student->firstname} {$student->surname}",
                'reg_no'   => $student->reg_no,
                'section'  => optional($student->section)->name ?? 'N/A',
                'class'    => optional($student->level)->name ?? 'N/A',
            ],
            'financial_summary' => [
                'gross_total'                  => $grossTotal,
                'discount_total'               => $discountTotal,
                'net_total'                    => $netTotal,
                'total_paid'                   => $totalPaid,
                'current_balance'              => $currentBalance,
                'past_arrears_balance'         => $pastArrearsTotal,
                'total_due_including_arrears'  => $totalDueIncludingArrears,
                'has_past_arrears'             => $pastArrearsTotal > 0,
            ],
            'past_arrears' => $pastArrearsFees->map(function ($p) {
                $fName = $p->feeType->name ?? 'Fee';
                $fObj = $p->feeType ? [
                    'id'              => $p->feeType->id,
                    'name'            => $fName,
                    'amount'          => (float) ($p->feeType->amount ?? 0),
                    'is_discountable' => (bool) ($p->feeType->is_discountable ?? true),
                ] : null;

                return [
                    'id'           => $p->id,
                    'fee_name'     => $fName,
                    'feeType'      => $fObj,
                    'fee_type'     => $fObj,
                    'session_name' => $p->session->name ?? 'N/A',
                    'term_name'    => $p->term->name ?? 'N/A',
                    'balance'      => (float) $p->balance,
                ];
            }),
            'installment_plan' => $installmentPlan,
            'full_payment_discount' => $installmentPlan['full_payment_discount'] ?? null,
            'fees' => $fees->map(function ($f) {
                $gross = (float) ($f->gross_amount ?: $f->total_amount);
                $disc = ($f->discount_status === 'approved') ? (float) $f->discount_amount : 0.00;
                $net = (float) ($f->net_amount ?: max(0, $gross - $disc));
                $fName = $f->feeType->name ?? 'School Fee';
                $fObj = $f->feeType ? [
                    'id'              => $f->feeType->id,
                    'name'            => $fName,
                    'amount'          => (float) ($f->feeType->amount ?? 0),
                    'is_discountable' => (bool) ($f->feeType->is_discountable ?? true),
                ] : [
                    'id'              => $f->fee_type_id,
                    'name'            => $fName,
                    'amount'          => $gross,
                    'is_discountable' => true,
                ];

                return [
                    'id'               => $f->id,
                    'student_id'       => $f->student_id,
                    'school_id'        => $f->school_id,
                    'section_id'       => $f->section_id,
                    'fee_type_id'      => $f->fee_type_id,
                    'fee_name'         => $fName,
                    'feeType'          => $fObj,
                    'fee_type'         => $fObj,
                    'is_discountable'  => (bool) ($f->feeType->is_discountable ?? true),
                    'gross_amount'     => $gross,
                    'discount_amount'  => (float) ($f->discount_amount ?? 0),
                    'discount_type'    => $f->discount_type,
                    'discount_rate'    => (float) ($f->discount_rate ?? 0),
                    'discount_reason'  => $f->discount_reason,
                    'discount_status'  => $f->discount_status ?? 'none',
                    'discount_notes'   => $f->discount_notes,
                    'discount_approver'=> $f->discountApprover ? "{$f->discountApprover->firstname} {$f->discountApprover->surname}" : null,
                    'net_amount'       => $net,
                    'total_amount'     => $net,
                    'amount_paid'      => (float) $f->amount_paid,
                    'balance'          => (float) $f->balance,
                    'status'           => $f->status,
                    'term_id'          => $f->term_id,
                    'session_id'       => $f->session_id,
                    'term'             => $f->term,
                    'session'          => $f->session,
                ];
            }),
        ]);
    }

    /**
     * ✅ Fetch full payment receipt for instant printing or modal
     */
    public function getReceiptDetails(Request $request, $paymentId)
    {
        $user = Auth::user();
        $schoolId = $user->school_id;

        $payment = Payment::where('school_id', $schoolId)
            ->with(['studentFee.student', 'studentFee.feeType', 'studentFee.section', 'studentFee.session', 'studentFee.term', 'receiver'])
            ->findOrFail($paymentId);

        $sf = $payment->studentFee;
        $school = SchoolSetting::find($schoolId);

        $gross = (float) ($sf->gross_amount ?: $sf->total_amount);
        $discount = ($sf->discount_status === 'approved') ? (float) $sf->discount_amount : 0.00;
        $net = (float) ($sf->net_amount ?: max(0, $gross - $discount));

        $origin = $request->header('origin') 
            ?: ($request->header('referer') ? rtrim(parse_url($request->header('referer'), PHP_URL_SCHEME) . '://' . parse_url($request->header('referer'), PHP_URL_HOST), '/') : null) 
            ?: $request->root();
        $verifyToken = $payment->qr_code_token ?: $payment->receipt_number ?: $payment->reference;
        $verifyUrl = rtrim($origin, '/') . '/receipt/verify/' . $verifyToken;

        return response()->json([
            'receipt' => [
                'receipt_number' => $payment->receipt_number ?: $payment->reference,
                'qr_code_token'  => $payment->qr_code_token,
                'qr_verify_url'  => $verifyUrl,
                'reference'      => $payment->reference,
                'amount_paid'    => (float) $payment->amount,
                'payment_method' => $payment->payment_method,
                'date'           => $payment->created_at->format('d M, Y h:i A'),
                'payer_name'     => $payment->payer_name,
                'payer_phone'    => $payment->payer_phone,
                'bursar_name'    => $payment->receiver ? trim("{$payment->receiver->firstname} {$payment->receiver->surname}") : 'Bursary Office',
                'student_name'   => $sf->student ? trim("{$sf->student->firstname} {$sf->student->surname}") : 'N/A',
                'admission_no'   => $sf->student->reg_no ?? 'N/A',
                'class_name'     => optional(User::find($sf->student_id)->level)->name ?? 'N/A',
                'section_name'   => $sf->section->name ?? 'N/A',
                'fee_name'       => $sf->feeType->name ?? 'School Fee',
                'gross_amount'   => $gross,
                'discount_amount'=> $discount,
                'discount_reason'=> $sf->discount_reason,
                'net_amount'     => $net,
                'remaining_balance' => (float) $sf->balance,
                'session_name'   => $sf->session->name ?? 'N/A',
                'term_name'      => $sf->term->name ?? 'N/A',
                'school_name'    => $school->school_name ?? 'SchoolProfit School',
                'school_logo'    => $school->logo ? asset($school->logo) : null,
                'school_address' => $school->address ?? '',
                'school_phone'   => $school->phone_number ?? '',
            ]
        ]);
    }

    /**
     * ✅ Public Receipt Verification Endpoint (tamper-proof QR code scan)
     */
    public function verifyReceiptPublic($token)
    {
        $token = trim($token);
        $payment = Payment::withoutGlobalScopes()
            ->where(function ($q) use ($token) {
                $q->where('qr_code_token', $token)
                  ->orWhere('receipt_number', $token)
                  ->orWhere('reference', $token)
                  ->orWhere('id', $token);
            })
            ->with([
                'studentFee' => fn($q) => $q->withoutGlobalScopes()->with([
                    'student', 'feeType', 'section', 'session', 'term'
                ]),
                'school',
                'receiver'
            ])
            ->first();

        if (!$payment) {
            return response()->json([
                'status'   => 'invalid',
                'is_valid' => false,
                'message'  => 'Invalid or unrecognized receipt verification token.',
            ], 404);
        }

        $sf = $payment->studentFee;
        $school = $payment->school ?: SchoolSetting::find($payment->school_id);
        $schoolName = $school ? ($school->school_name ?? $school->name ?? 'SchoolProfit Partner School') : 'SchoolProfit Partner School';

        $studentName = 'N/A';
        $regNo = 'N/A';
        $sectionName = 'General';
        $feeTypeName = 'School Fee';
        $sessionName = 'N/A';
        $termName = 'N/A';
        $termSession = 'Current Term';
        $gross = (float) $payment->amount;
        $discount = 0.00;
        $net = (float) $payment->amount;
        $balance = 0.00;
        $status = 'PAID';
        $paymentAmount = (float) $payment->amount;

        if ($sf) {
            if ($sf->student) {
                $studentName = trim("{$sf->student->firstname} {$sf->student->surname}");
                $regNo = $sf->student->reg_no ?? 'N/A';
            }
            if ($sf->section) {
                $sectionName = $sf->section->name;
            }
            if ($sf->feeType) {
                $feeTypeName = $sf->feeType->name;
            }
            if ($sf->session) {
                $sessionName = $sf->session->name;
            }
            if ($sf->term) {
                $termName = $sf->term->name;
            }
            $termSession = trim("{$termName} {$sessionName}") ?: 'Current Term';

            $gross = (float) ($sf->gross_amount ?: $sf->total_amount);
            $discount = ($sf->discount_status === 'approved') ? (float) $sf->discount_amount : 0.00;
            $net = (float) ($sf->net_amount ?: max(0, $gross - $discount));
            $balance = (float) $sf->balance;
            $status = strtoupper($sf->status ?? 'PAID');
        }

        // Check if multiple sibling payments share this receipt number (consolidated payment)
        $items = [];
        if ($payment->receipt_number) {
            $siblingPayments = Payment::withoutGlobalScopes()
                ->where('school_id', $payment->school_id)
                ->where('receipt_number', $payment->receipt_number)
                ->with([
                    'studentFee' => fn($q) => $q->withoutGlobalScopes()->with([
                        'student', 'feeType', 'section', 'session', 'term'
                    ])
                ])
                ->get();

            if ($siblingPayments->count() > 1) {
                $totalGross = 0.0;
                $totalDiscount = 0.0;
                $totalNet = 0.0;
                $totalPaid = 0.0;

                foreach ($siblingPayments as $sib) {
                    $sFee = $sib->studentFee;
                    $g = $sFee ? (float) ($sFee->gross_amount ?: $sFee->total_amount) : (float) $sib->amount;
                    $d = ($sFee && $sFee->discount_status === 'approved') ? (float) $sFee->discount_amount : 0.00;
                    $n = max(0, $g - $d);
                    $amt = (float) $sib->amount;

                    $items[] = [
                        'fee_name'       => $sFee && $sFee->feeType ? $sFee->feeType->name : "Fee #{$sib->student_fee_id}",
                        'gross_amount'   => $g,
                        'discount_amount'=> $d,
                        'net_amount'     => $n,
                        'amount_paid'    => $amt,
                        'balance'        => $sFee ? (float) $sFee->balance : 0.00,
                    ];

                    $totalGross += $g;
                    $totalDiscount += $d;
                    $totalNet += $n;
                    $totalPaid += $amt;
                }

                $gross = $totalGross;
                $discount = $totalDiscount;
                $net = $totalNet;
                $paymentAmount = $totalPaid;
                $feeTypeName = 'Consolidated Fees (' . count($items) . ' items)';
            }
        }

        $bursarName = $payment->receiver 
            ? trim("{$payment->receiver->firstname} {$payment->receiver->surname}") 
            : 'Bursary Office';

        $receiptPayload = [
            'receipt_number' => $payment->receipt_number ?: $payment->reference,
            'qr_code_token'  => $payment->qr_code_token ?: $token,
            'reference'      => $payment->reference,
            'date'           => $payment->created_at ? $payment->created_at->format('d M, Y h:i A') : 'N/A',
            'amount_paid'    => $paymentAmount,
            'gross_amount'   => $gross,
            'discount_amount'=> $discount,
            'net_amount'     => $net,
            'balance'        => $balance,
            'payment_method' => strtoupper($payment->payment_method ?? 'OFFLINE'),
            'payer_name'     => $payment->payer_name,
            'payer_phone'    => $payment->payer_phone,
            'bursar_name'    => $bursarName,
            'student_name'   => $studentName,
            'admission_no'   => $regNo,
            'section_name'   => $sectionName,
            'fee_name'       => $feeTypeName,
            'session_name'   => $sessionName,
            'term_name'      => $termName,
            'school_name'    => $schoolName,
            'school_logo'    => $school && $school->logo ? asset($school->logo) : null,
            'status'         => $status,
            'items'          => $items,
            'issued_at'      => $payment->created_at ? $payment->created_at->format('d M, Y h:i A') : 'N/A',
            'class_section'  => $sectionName,
            'school_verified'=> true,
            'fee_type'       => $feeTypeName,
            'term_session'   => $termSession,
        ];

        return response()->json([
            'status'       => 'verified',
            'is_valid'     => true,
            'receipt'      => $receiptPayload,
            'verification' => $receiptPayload,
        ]);
    }

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
