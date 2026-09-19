<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\GradiosEduInvoicePayment;
use App\Models\SchoolSetting;
use App\Models\StudentClass;
use App\Models\Term;
use App\Models\User;
use App\Services\SchoolBillingService;
use App\Services\WemaAlatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class StudentClearanceController extends Controller
{
    public function __construct(private readonly SchoolBillingService $billing)
    {
    }

    public function summary(Request $request): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;
        $sessionId = $request->query('session_id') ? (int) $request->query('session_id') : null;
        $termId = $request->query('term_id') ? (int) $request->query('term_id') : null;

        $summary = $this->billing->clearanceSummary($schoolId, $sessionId, $termId);

        return response()->json($summary);
    }

    public function studentsList(Request $request): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;
        $sessionId = $request->query('session_id') ? (int) $request->query('session_id') : null;
        $termId = $request->query('term_id') ? (int) $request->query('term_id') : null;
        $classId = $request->query('class_id') ? (int) $request->query('class_id') : null;
        $search = $request->query('search') ? trim((string) $request->query('search')) : null;
        $statusFilter = $request->query('status') ? trim((string) $request->query('status')) : 'all';
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(200, max(10, (int) $request->query('per_page', 50)));

        $data = $this->billing->studentClearanceRoster(
            $schoolId,
            $sessionId,
            $termId,
            $classId,
            $search,
            $statusFilter,
            $page,
            $perPage
        );

        return response()->json($data);
    }

    public function clearSelected(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_ids'     => 'required|array|min:1',
            'student_ids.*'   => 'required|integer',
            'session_id'      => 'required|integer',
            'term_id'         => 'nullable|integer',
            'is_full_session' => 'nullable|boolean',
        ]);

        $schoolId = (int) $request->user()->school_id;
        $actorId = (int) $request->user()->id;

        try {
            $result = $this->billing->clearSelectedStudentsFromWallet(
                $schoolId,
                $validated['student_ids'],
                (int) $validated['session_id'],
                isset($validated['term_id']) ? (int) $validated['term_id'] : null,
                (bool) ($validated['is_full_session'] ?? false),
                $actorId
            );

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function clearStudent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => 'required|integer',
            'session_id' => 'required|integer',
            'term_id'    => 'required|integer',
        ]);

        $schoolId = (int) $request->user()->school_id;
        $actorId = (int) $request->user()->id;

        try {
            $result = $this->billing->clearStudentFromWallet(
                $schoolId,
                (int) $validated['student_id'],
                (int) $validated['session_id'],
                (int) $validated['term_id'],
                $actorId
            );

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function clearClass(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'class_id'   => 'required|integer',
            'session_id' => 'required|integer',
            'term_id'    => 'required|integer',
        ]);

        $schoolId = (int) $request->user()->school_id;
        $actorId = (int) $request->user()->id;

        try {
            $result = $this->billing->clearClassFromWallet(
                $schoolId,
                (int) $validated['class_id'],
                (int) $validated['session_id'],
                (int) $validated['term_id'],
                $actorId
            );

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function clearSchoolTerm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => 'required|integer',
            'term_id'    => 'required|integer',
        ]);

        $schoolId = (int) $request->user()->school_id;
        $actorId = (int) $request->user()->id;

        try {
            $result = $this->billing->clearSchoolTermFromWallet(
                $schoolId,
                (int) $validated['session_id'],
                (int) $validated['term_id'],
                $actorId
            );

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function clearSchoolSession(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => 'required|integer',
        ]);

        $schoolId = (int) $request->user()->school_id;
        $actorId = (int) $request->user()->id;

        try {
            $result = $this->billing->clearSchoolSessionFromWallet(
                $schoolId,
                (int) $validated['session_id'],
                $actorId
            );

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Initiate Paystack checkout for single, selected, term, or full session student clearances.
     */
    public function initiatePaystackClearance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type'            => 'required|in:selected,single,term,session,all',
            'session_id'      => 'required|integer',
            'term_id'         => 'nullable|integer',
            'student_ids'     => 'nullable|array',
            'student_ids.*'   => 'integer',
            'is_full_session' => 'nullable|boolean',
            'callback_url'    => 'nullable|string',
        ]);

        $schoolId = (int) $request->user()->school_id;
        $actorId = (int) $request->user()->id;
        $user = $request->user();
        $isFullSession = (bool) ($validated['is_full_session'] ?? ($validated['type'] === 'session'));
        $sessionId = (int) $validated['session_id'];
        $termId = isset($validated['term_id']) ? (int) $validated['term_id'] : null;

        try {
            $feePerStudent = (float) $this->billing->pricePerStudentForSchool($schoolId);
            $session = AcademicSession::where('school_id', $schoolId)->findOrFail($sessionId);
            $terms = Term::where('school_id', $schoolId)->whereNull('archived_at')->orderBy('id')->get();

            if ($validated['type'] === 'selected' || $validated['type'] === 'single') {
                $rawIds = array_values(array_unique(array_filter(array_map('intval', $validated['student_ids'] ?? []))));
                if (empty($rawIds)) {
                    return response()->json(['success' => false, 'message' => 'No students selected for clearance.'], 422);
                }

                $students = User::where('school_id', $schoolId)
                    ->whereIn('id', $rawIds)
                    ->whereRaw('LOWER(role) = ?', ['student'])
                    ->where('status', 1)
                    ->get();
            } else {
                $students = User::where('school_id', $schoolId)
                    ->whereRaw('LOWER(role) = ?', ['student'])
                    ->where('status', 1)
                    ->get();
            }

            if ($students->isEmpty()) {
                return response()->json(['success' => false, 'message' => 'No active students found.'], 422);
            }

            $unpaidSlots = [];
            if ($isFullSession) {
                foreach ($terms as $t) {
                    foreach ($students as $student) {
                        $ent = $this->billing->ensureEntitlement($schoolId, $student->id, $sessionId, $t->id);
                        if (!in_array($ent->status, ['paid', 'override'], true)) {
                            $unpaidSlots[] = ['student_id' => $student->id, 'term_id' => $t->id];
                        }
                    }
                }
            } else {
                if (!$termId) {
                    [$currSess, $currTerm] = $this->billing->currentPeriod($schoolId);
                    $termId = (int) $currTerm?->id;
                }
                $targetTerm = $terms->firstWhere('id', $termId) ?? Term::where('school_id', $schoolId)->findOrFail($termId);

                foreach ($students as $student) {
                    $ent = $this->billing->ensureEntitlement($schoolId, $student->id, $sessionId, $targetTerm->id);
                    if (!in_array($ent->status, ['paid', 'override'], true)) {
                        $unpaidSlots[] = ['student_id' => $student->id, 'term_id' => $targetTerm->id];
                    }
                }
            }

            $slotCount = count($unpaidSlots);
            if ($slotCount === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'All specified students are already cleared.',
                    'already_cleared' => true,
                ], 422);
            }

            $totalFee = $slotCount * $feePerStudent;
            $studentIdsToClear = $students->pluck('id')->values()->all();

            // Ensure invoice exists for foreign key
            if ($isFullSession) {
                $invoice = $this->billing->generateSessionInvoice($schoolId, $sessionId, $actorId);
            } else {
                $invoice = $this->billing->generateOfflineInvoice($schoolId, $sessionId, $termId, $actorId);
            }

            $reference = 'SP_CLR_' . strtoupper(Str::random(14));

            $origin = $validated['callback_url']
                ?: $request->header('origin')
                ?: ($request->header('referer') ? rtrim(parse_url($request->header('referer'), PHP_URL_SCHEME) . '://' . parse_url($request->header('referer'), PHP_URL_HOST), '/') : null)
                ?: rtrim((string) (config('app.frontend_url') ?: 'https://schoolprofit.ng'), '/');

            $callbackUrl = rtrim($origin, '/') . '/billing?reference=' . $reference;

            $paystackSecret = trim((string) config('services.paystack.secret'));
            if (empty($paystackSecret)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Paystack secret key is not configured on the server.',
                ], 422);
            }

            $metadata = [
                'source' => 'student_clearance_paystack',
                'school_id' => $schoolId,
                'user_id' => $actorId,
                'type' => $validated['type'],
                'student_ids' => $studentIdsToClear,
                'session_id' => $sessionId,
                'term_id' => $termId,
                'is_full_session' => $isFullSession,
                'slot_count' => $slotCount,
                'fee_per_student' => $feePerStudent,
                'total_fee' => $totalFee,
            ];

            $response = Http::withToken($paystackSecret)
                ->timeout(25)
                ->post('https://api.paystack.co/transaction/initialize', [
                    'reference' => $reference,
                    'email' => $user->email ?: 'school@schoolprofit.ng',
                    'amount' => (int) round($totalFee * 100), // in kobo
                    'callback_url' => $callbackUrl,
                    'metadata' => $metadata,
                ]);

            if (!$response->successful() || !$response->json('status')) {
                Log::error('Paystack clearance initialize error', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => $response->json('message') ?: 'Unable to initialize Paystack checkout.',
                ], 422);
            }

            $data = $response->json('data') ?: [];

            GradiosEduInvoicePayment::create([
                'school_id' => $schoolId,
                'invoice_id' => $invoice->id,
                'user_id' => $actorId,
                'reference' => $reference,
                'amount' => $totalFee,
                'status' => 'pending',
                'channel' => 'paystack',
                'paystack_response' => array_merge($metadata, $data),
            ]);

            return response()->json([
                'success' => true,
                'authorization_url' => $data['authorization_url'] ?? null,
                'access_code' => $data['access_code'] ?? null,
                'reference' => $reference,
                'total_fee' => $totalFee,
                'slot_count' => $slotCount,
                'student_count' => count($studentIdsToClear),
            ]);
        } catch (\Throwable $e) {
            Log::error('initiatePaystackClearance error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Verify Paystack clearance transaction upon callback return.
     */
    public function verifyPaystackClearance(Request $request, string $reference): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;
        $actorId = (int) $request->user()->id;

        $payment = GradiosEduInvoicePayment::where('reference', $reference)
            ->where('school_id', $schoolId)
            ->first();

        if ($payment && $payment->status === 'successful') {
            return response()->json([
                'success' => true,
                'message' => 'Payment has already been verified and student clearance applied.',
                'payment' => $payment,
            ]);
        }

        $paystackSecret = trim((string) config('services.paystack.secret'));
        if (empty($paystackSecret)) {
            return response()->json(['success' => false, 'message' => 'Paystack secret is not configured.'], 422);
        }

        try {
            $response = Http::withToken($paystackSecret)
                ->timeout(25)
                ->get("https://api.paystack.co/transaction/verify/{$reference}");

            if (!$response->successful() || !$response->json('status')) {
                return response()->json([
                    'success' => false,
                    'message' => $response->json('message') ?: 'Verification lookup failed on Paystack.',
                ], 422);
            }

            $data = $response->json('data') ?: [];
            $status = $data['status'] ?? 'abandoned';

            if ($status !== 'success') {
                return response()->json([
                    'success' => false,
                    'message' => "Payment status is {$status}. Clearance not applied.",
                    'status' => $status,
                ], 422);
            }

            $metadata = $data['metadata'] ?? [];
            $metaSchoolId = (int) ($metadata['school_id'] ?? 0);

            // Enforce tenant isolation
            if ($metaSchoolId && $metaSchoolId !== $schoolId) {
                return response()->json(['success' => false, 'message' => 'School tenant mismatch.'], 403);
            }

            $type = (string) ($metadata['type'] ?? 'selected');
            $studentIds = (array) ($metadata['student_ids'] ?? []);
            $sessionId = (int) ($metadata['session_id'] ?? 0);
            $termId = isset($metadata['term_id']) ? (int) $metadata['term_id'] : null;
            $isFullSession = (bool) ($metadata['is_full_session'] ?? false);

            $result = $this->billing->applyOnlineClearance(
                $schoolId,
                $type,
                $studentIds,
                $sessionId,
                $termId,
                $isFullSession,
                $reference,
                'paystack',
                $actorId,
                $data
            );

            if ($payment) {
                $payment->update([
                    'status' => 'successful',
                    'channel' => $data['channel'] ?? 'paystack',
                    'card_type' => $data['authorization']['card_type'] ?? null,
                    'last4' => $data['authorization']['last4'] ?? null,
                    'paystack_id' => $data['id'] ?? null,
                    'paystack_response' => $data,
                    'paid_at' => now(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => $result['message'] ?? 'Payment verified successfully and clearance applied.',
                'cleared_students_count' => $result['cleared_students_count'] ?? 0,
                'cleared_slots' => $result['cleared_slots'] ?? 0,
                'total_fee' => $result['total_fee'] ?? 0,
            ]);
        } catch (\Throwable $e) {
            Log::error('verifyPaystackClearance error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function initiateOnlineClearance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type'            => 'required|in:selected,single,term,session,all',
            'session_id'      => 'required|integer',
            'term_id'         => 'nullable|integer',
            'student_ids'     => 'nullable|array',
            'student_ids.*'   => 'integer',
            'is_full_session' => 'nullable|boolean',
        ]);

        $schoolId = (int) $request->user()->school_id;
        $actorId = (int) $request->user()->id;
        $user = $request->user();
        $isFullSession = (bool) ($validated['is_full_session'] ?? ($validated['type'] === 'session'));
        $sessionId = (int) $validated['session_id'];
        $termId = isset($validated['term_id']) ? (int) $validated['term_id'] : null;

        try {
            $feePerStudent = (float) $this->billing->pricePerStudentForSchool($schoolId);
            $session = AcademicSession::where('school_id', $schoolId)->findOrFail($sessionId);
            $terms = Term::where('school_id', $schoolId)->whereNull('archived_at')->orderBy('id')->get();

            if ($validated['type'] === 'selected' || $validated['type'] === 'single') {
                $rawIds = array_values(array_unique(array_filter(array_map('intval', $validated['student_ids'] ?? []))));
                if (empty($rawIds)) {
                    return response()->json(['success' => false, 'message' => 'No students selected for clearance.'], 422);
                }

                $students = User::where('school_id', $schoolId)
                    ->whereIn('id', $rawIds)
                    ->whereRaw('LOWER(role) = ?', ['student'])
                    ->where('status', 1)
                    ->get();
            } else {
                $students = User::where('school_id', $schoolId)
                    ->whereRaw('LOWER(role) = ?', ['student'])
                    ->where('status', 1)
                    ->get();
            }

            if ($students->isEmpty()) {
                return response()->json(['success' => false, 'message' => 'No active students found.'], 422);
            }

            $unpaidSlots = [];
            if ($isFullSession) {
                foreach ($terms as $t) {
                    foreach ($students as $student) {
                        $ent = $this->billing->ensureEntitlement($schoolId, $student->id, $sessionId, $t->id);
                        if (!in_array($ent->status, ['paid', 'override'], true)) {
                            $unpaidSlots[] = ['student_id' => $student->id, 'term_id' => $t->id];
                        }
                    }
                }
            } else {
                if (!$termId) {
                    [$currSess, $currTerm] = $this->billing->currentPeriod($schoolId);
                    $termId = (int) $currTerm?->id;
                }
                $targetTerm = $terms->firstWhere('id', $termId) ?? Term::where('school_id', $schoolId)->findOrFail($termId);

                foreach ($students as $student) {
                    $ent = $this->billing->ensureEntitlement($schoolId, $student->id, $sessionId, $targetTerm->id);
                    if (!in_array($ent->status, ['paid', 'override'], true)) {
                        $unpaidSlots[] = ['student_id' => $student->id, 'term_id' => $targetTerm->id];
                    }
                }
            }

            $slotCount = count($unpaidSlots);
            if ($slotCount === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'All specified students are already cleared.',
                    'already_cleared' => true,
                ], 422);
            }

            $amount = $slotCount * $feePerStudent;

            if ($isFullSession) {
                $invoice = $this->billing->generateSessionInvoice($schoolId, $sessionId, $actorId);
            } else {
                $invoice = $this->billing->generateOfflineInvoice($schoolId, $sessionId, $termId, $actorId);
            }

            // Generate or retrieve Wema Virtual Account for this invoice & specific amount
            $wemaService = app(WemaAlatService::class);
            $school = SchoolSetting::find($schoolId);
            $schoolName = trim($school?->school_name ?? $school?->name ?? 'School');

            $reference = 'SP_INV_' . strtoupper(Str::random(12));

            $va = $wemaService->generateVirtualAccount([
                'reference' => $reference,
                'amount' => $amount,
                'school_name' => $schoolName,
                'email' => $user->email ?? $school?->email ?? 'payments@schoolprofit.ng',
                'phone' => $school?->phone_number ?? $school?->phone ?? '08000000000',
                'school_code' => 'SCH' . $invoice->school_id,
            ]);

            $payment = GradiosEduInvoicePayment::create([
                'school_id' => $invoice->school_id,
                'invoice_id' => $invoice->id,
                'user_id' => $user->id,
                'reference' => $reference,
                'amount' => $amount,
                'status' => 'pending',
                'channel' => 'wema_virtual_account',
                'paystack_response' => [
                    'bank_name' => $va['bank_name'] ?? 'Wema Bank',
                    'account_number' => $va['account_number'] ?? null,
                    'account_name' => $va['account_name'] ?? 'Samaritan Technologies',
                    'reference' => $reference,
                    'amount' => $amount,
                    'expires_at' => $va['expires_at'] ?? now()->addHours(24)->toIso8601String(),
                    'mode' => $va['mode'] ?? 'live',
                    'error' => $va['error'] ?? null,
                    'metadata' => [
                        'school_id' => $schoolId,
                        'session_id' => $sessionId,
                        'term_id' => $termId,
                        'type' => $validated['type'],
                        'student_ids' => $students->pluck('id')->all(),
                        'is_full_session' => $isFullSession,
                        'channel' => 'student_clearance_wema',
                        'invoice_id' => $invoice->id,
                    ],
                ],
            ]);

            return response()->json([
                'success' => true,
                'invoice' => $invoice,
                'virtual_account' => $payment->paystack_response,
                'reference' => $reference,
                'amount' => $amount,
                'slot_count' => $slotCount,
                'student_count' => count($students),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Verify Wema Bank Virtual Account transfer and activate student clearance.
     */
    public function verifyWemaClearance(Request $request, string $reference): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;
        $actorId = (int) $request->user()->id;
        $cleanRef = trim($reference);

        try {
            $payment = GradiosEduInvoicePayment::where('reference', $cleanRef)
                ->where('school_id', $schoolId)
                ->first();

            if (! $payment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment reference not found for this school.',
                ], 404);
            }

            if ($payment->status === 'successful') {
                return response()->json([
                    'success' => true,
                    'message' => 'Payment already verified and clearance activated.',
                    'already_verified' => true,
                    'cleared_students_count' => count($payment->paystack_response['metadata']['student_ids'] ?? []),
                    'total_fee' => (float) $payment->amount,
                ]);
            }

            $wemaService = app(WemaAlatService::class);
            $verification = $wemaService->verifyTransaction($cleanRef);

            if (! ($verification['verified'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transfer is still processing in bank settlement queue. Please click again once your bank completes the debit.',
                    'status' => 'pending',
                ], 200);
            }

            $meta = $payment->paystack_response['metadata'] ?? [];
            $type = (string) ($meta['type'] ?? 'selected');
            $studentIds = (array) ($meta['student_ids'] ?? []);
            $sessionId = (int) ($meta['session_id'] ?? 0);
            $termId = isset($meta['term_id']) ? (int) $meta['term_id'] : null;
            $isFullSession = (bool) ($meta['is_full_session'] ?? false);

            if (empty($sessionId)) {
                [$currSess, $currTerm] = $this->billing->currentPeriod($schoolId);
                $sessionId = (int) $currSess?->id;
                $termId = $termId ?: (int) $currTerm?->id;
            }

            $result = $this->billing->applyOnlineClearance(
                $schoolId,
                $type,
                $studentIds,
                $sessionId,
                $termId,
                $isFullSession,
                $cleanRef,
                'wema_virtual_account',
                $actorId,
                $verification['raw'] ?? null
            );

            $payment->update([
                'status' => 'successful',
                'channel' => 'wema_virtual_account',
                'paid_at' => now(),
                'paystack_response' => array_merge(
                    is_array($payment->paystack_response) ? $payment->paystack_response : [],
                    ['settlement' => $verification['raw'] ?? [], 'settled_at' => now()->toIso8601String()]
                ),
            ]);

            return response()->json([
                'success' => true,
                'message' => $result['message'] ?? 'Payment verified successfully and student clearance activated.',
                'cleared_students_count' => $result['cleared_students_count'] ?? count($studentIds),
                'cleared_slots' => $result['cleared_slots'] ?? 1,
                'total_fee' => (float) ($result['total_fee'] ?? $payment->amount),
            ]);
        } catch (\Throwable $e) {
            Log::error('verifyWemaClearance error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function studentStatus(Request $request, int $studentId): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;
        $clearance = $this->billing->studentAcademicClearanceStatus($schoolId, $studentId);

        return response()->json($clearance);
    }
}

