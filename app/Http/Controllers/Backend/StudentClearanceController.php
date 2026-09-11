<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\Term;
use App\Models\StudentClass;
use App\Models\User;
use App\Services\SchoolBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function initiateOnlineClearance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type'       => 'required|in:term,session',
            'session_id' => 'required|integer',
            'term_id'    => 'nullable|integer',
        ]);

        $schoolId = (int) $request->user()->school_id;
        $actorId = (int) $request->user()->id;

        try {
            $sessionId = (int) $validated['session_id'];

            if ($validated['type'] === 'session') {
                $invoice = $this->billing->generateSessionInvoice($schoolId, $sessionId, $actorId);
            } else {
                $termId = (int) ($validated['term_id'] ?? 0);
                if (! $termId) {
                    [$session, $term] = $this->billing->currentPeriod($schoolId);
                    $termId = (int) $term?->id;
                }
                $invoice = $this->billing->generateOfflineInvoice($schoolId, $sessionId, $termId, $actorId);
            }

            // Generate or retrieve Wema Virtual Account for this invoice
            $wemaService = app(\App\Services\WemaAlatService::class);
            $school = \App\Models\SchoolSetting::find($schoolId);
            $schoolName = $school?->school_name ?? $school?->name ?? 'School';
            $user = $request->user();

            $existingPayment = \App\Models\GradiosEduInvoicePayment::where('invoice_id', $invoice->id)
                ->where('channel', 'wema_virtual_account')
                ->where('status', 'pending')
                ->where('created_at', '>=', now()->subHours(24))
                ->latest()
                ->first();

            if ($existingPayment && ! empty($existingPayment->paystack_response['account_number'])) {
                $virtualAccount = $existingPayment->paystack_response;
                $reference = $existingPayment->reference;
            } else {
                $reference = 'SP_INV_' . strtoupper(\Illuminate\Support\Str::random(12));
                $amount = (float) $invoice->balance;

                $va = $wemaService->generateVirtualAccount([
                    'reference' => $reference,
                    'amount' => $amount,
                    'school_name' => $schoolName,
                    'email' => $user->email ?? $school?->email ?? 'payments@schoolprofit.ng',
                    'phone' => $school?->phone_number ?? $school?->phone ?? '08000000000',
                    'school_code' => 'SCH' . $invoice->school_id,
                ]);

                $payment = \App\Models\GradiosEduInvoicePayment::create([
                    'school_id' => $invoice->school_id,
                    'invoice_id' => $invoice->id,
                    'user_id' => $user->id,
                    'reference' => $reference,
                    'amount' => $amount,
                    'status' => 'pending',
                    'channel' => 'wema_virtual_account',
                    'paystack_response' => [
                        'bank_name' => $va['bank_name'] ?? 'Wema Bank',
                        'account_number' => $va['account_number'] ?? '',
                        'account_name' => $va['account_name'] ?? "SchoolProfit / {$schoolName}",
                        'reference' => $reference,
                        'amount' => $amount,
                        'expires_at' => $va['expires_at'] ?? now()->addHours(24)->toIso8601String(),
                        'mode' => $va['mode'] ?? 'sandbox',
                    ],
                ]);

                $virtualAccount = $payment->paystack_response;
            }

            return response()->json([
                'success' => true,
                'invoice' => $invoice,
                'virtual_account' => $virtualAccount,
                'reference' => $reference,
                'amount' => (float) $invoice->balance,
            ]);
        } catch (\Throwable $e) {
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
