<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\PublicFeePaymentIntent;
use App\Models\SchoolBankAccount;
use App\Models\SchoolSetting;
use App\Models\StudentFee;
use App\Models\User;
use App\Mail\SchoolFeePaymentReceiptMail;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Services\MonnifyService;
use App\Services\PlatformFeeService;
use App\Services\SchoolBillingService;
use App\Services\SalesCommissionService;
use App\Services\SchoolFeeAccessPolicyService;
use App\Services\WemaAlatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class PublicFeePaymentController extends Controller
{
    public function __construct(
        private PlatformFeeService $platformFeeService,
        private SchoolBillingService $schoolBillingService,
        private SalesCommissionService $salesCommissionService,
        private SchoolFeeAccessPolicyService $feeAccessPolicyService,
        private MonnifyService $monnifyService,
        private WemaAlatService $wemaService
    ) {
    }

    public function school(Request $request)
    {
        $data = $request->validate([
            'school_code' => 'required|string|max:80',
        ]);

        $admin = $this->resolveSchoolAdmin($data['school_code']);

        if (! $admin) {
            return response()->json(['message' => 'School code not found.'], 404);
        }

        return response()->json([
            'school' => $this->schoolPayload($admin),
        ]);
    }

    public function student(Request $request)
    {
        $data = $request->validate([
            'school_code' => 'required|string|max:80',
            'student_reg_no' => 'required|string|max:80',
        ]);

        $admin = $this->resolveSchoolAdmin($data['school_code']);

        if (! $admin) {
            return response()->json(['message' => 'School code not found.'], 404);
        }

        $student = $this->resolveStudent($admin->school_id, $data['student_reg_no']);

        if (! $student) {
            return response()->json(['message' => 'Student admission number was not found for this school.'], 404);
        }

        return response()->json($this->studentFeePayload($admin, $student));
    }

    public function initialize(Request $request)
    {
        $data = $request->validate([
            'school_code' => 'required|string|max:80',
            'student_reg_no' => 'required|string|max:80',
            'amount' => 'required|numeric|min:100',
            'payer_email' => 'nullable|email|max:190',
            'payer_name' => 'nullable|string|max:190',
            'payer_phone' => 'nullable|string|max:40',
            'gateway' => 'nullable|string|in:wema_alat,monnify,paystack',
        ]);

        $admin = $this->resolveSchoolAdmin($data['school_code']);

        if (! $admin) {
            return response()->json(['message' => 'School code not found.'], 404);
        }

        $student = $this->resolveStudent($admin->school_id, $data['student_reg_no']);

        if (! $student) {
            return response()->json(['message' => 'Student admission number was not found for this school.'], 404);
        }

        $amount = round((float) $data['amount'], 2);
        $outstanding = $this->outstandingFees($admin->school_id, $student->id);
        $totalBalance = round((float) $outstanding->sum('balance'), 2);

        if ($totalBalance <= 0) {
            return response()->json(['message' => 'This student does not have an outstanding fee balance.'], 422);
        }

        if ($amount > $totalBalance) {
            return response()->json([
                'message' => 'Amount exceeds the student outstanding balance.',
                'balance' => $totalBalance,
            ], 422);
        }

        $currentTermFee = $outstanding->first(function (StudentFee $fee) use ($admin) {
            [$session, $term] = $this->schoolBillingService->currentPeriod($admin->school_id);
            return $fee->session_id == $session?->id && $fee->term_id == $term?->id;
        }) ?: $outstanding->first();

        $totalTermAmount = (float) ($currentTermFee?->total_amount ?? $totalBalance);
        $totalTermPaid = (float) ($currentTermFee?->amount_paid ?? 0);

        $installmentPlan = $this->feeAccessPolicyService->calculateInstallmentPlan(
            $admin->school_id,
            $totalTermAmount,
            $totalTermPaid,
            $totalBalance
        );

        if ($installmentPlan['enabled'] && $amount < $installmentPlan['min_payable_now']) {
            return response()->json([
                'message' => $installmentPlan['message'] ?: ('Minimum installment payment required is ₦' . number_format($installmentPlan['min_payable_now'], 2)),
                'min_payable_now' => $installmentPlan['min_payable_now'],
                'installment_plan' => $installmentPlan,
            'charge_policy' => [
                'bank_charge_bearer' => $this->feeAccessPolicyService->policyForSchool($admin->school_id)['bank_charge_bearer'] ?? 'parent',
                'bank_charge_amount' => (float) ($this->feeAccessPolicyService->policyForSchool($admin->school_id)['bank_charge_amount'] ?? 200.0),
                'platform_fee_bearer' => $this->feeAccessPolicyService->policyForSchool($admin->school_id)['platform_fee_bearer'] ?? 'school',
                'platform_fee_amount' => (float) ($this->feeAccessPolicyService->policyForSchool($admin->school_id)['platform_fee_amount'] ?? 500.0),
                'active_gateway' => $this->feeAccessPolicyService->policyForSchool($admin->school_id)['active_payment_gateway'] ?? 'wema_alat',
            ],
            ], 422);
        }

        $bankAccount = SchoolBankAccount::where('school_id', $admin->school_id)
            ->where('is_active', true)
            ->where('online_payment_enabled', true)
            ->orderBy('sort_order')
            ->first();

        if (! $bankAccount) {
            return response()->json(['message' => 'This school has not enabled online fee payment yet.'], 422);
        }

        $reference = 'gq_fee_' . Str::uuid()->toString();
        $allocations = $this->buildAllocations($outstanding, $amount);

        if (empty($allocations)) {
            return response()->json(['message' => 'No payable fee record was found for this student.'], 422);
        }

        try {
            $platformFee = $this->attachPlatformFeesToAllocations($outstanding, $allocations, $reference);
        } catch (RuntimeException $e) {
            $this->platformFeeService->releaseCharge($reference);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $payerEmail = $data['payer_email']
            ?? $student->email
            ?? $admin->email
            ?? ('payments+' . Str::lower(Str::random(10)) . '@gradequest.local');

        $origin = $request->input('callback_url')
            ?: $request->header('origin')
            ?: ($request->header('referer') ? rtrim(parse_url($request->header('referer'), PHP_URL_SCHEME) . '://' . parse_url($request->header('referer'), PHP_URL_HOST), '/') : null)
            ?: rtrim((string) (config('app.frontend_url') ?: 'https://gradequest.com.ng'), '/');

        $callbackUrl = rtrim($origin, '/') . '/pay-fees?reference=' . $reference;

        $policy = $this->feeAccessPolicyService->policyForSchool((int) $admin->school_id);
        $bankChargeBearer = $policy['bank_charge_bearer'] ?? 'parent';
        $bankChargeAmount = (float) ($policy['bank_charge_amount'] ?? 200.00);
        $platformFeeBearer = $policy['platform_fee_bearer'] ?? 'school';
        $platformFeeAmount = (float) $platformFee;

        $surcharge = 0;
        if ($bankChargeBearer === 'parent') {
            $surcharge += $bankChargeAmount;
        }
        if ($platformFeeBearer === 'parent') {
            $surcharge += $platformFeeAmount;
        }
        $totalPayableByParent = round($amount + $surcharge, 2);

        $chosenGateway = $data['gateway'] ?? ($policy['active_payment_gateway'] ?? ($bankAccount->preferred_gateway ?: 'wema_alat'));

        // ── 0. WEMA BANK (ALAT / Dedicated Virtual Account) ──
        if ($chosenGateway === 'wema_alat' || empty($chosenGateway)) {
            $wemaVirtualAcc = $this->wemaService->generateVirtualAccount([
                'amount' => $totalPayableByParent,
                'student_name' => trim(($student->firstname ?? '') . ' ' . ($student->surname ?? '')) ?: 'Student',
                'student_id' => $student->id,
                'school_id' => $admin->school_id,
                'school_code' => $data['school_code'],
                'reference' => $reference,
                'email' => $payerEmail,
                'phone' => $data['payer_phone'] ?? '08000000000',
            ]);

            $wemaAlatRes = $this->wemaService->initializePayment([
                'amount' => $totalPayableByParent,
                'email' => $payerEmail,
                'reference' => $reference,
                'callback_url' => $callbackUrl,
                'metadata' => [
                    'student_reg_no' => $student->reg_no,
                    'school_id' => $admin->school_id,
                    'tuition_amount' => $amount,
                    'surcharge' => $surcharge,
                ],
            ]);

            PublicFeePaymentIntent::create([
                'school_id' => $admin->school_id,
                'student_id' => $student->id,
                'school_code' => trim($data['school_code']),
                'student_reg_no' => trim($data['student_reg_no']),
                'reference' => $reference,
                'payer_email' => $payerEmail,
                'payer_name' => $data['payer_name'] ?? null,
                'payer_phone' => $data['payer_phone'] ?? null,
                'amount' => $amount,
                'platform_fee' => $platformFee,
                'allocations' => $allocations,
                'gateway' => 'wema_alat',
                'status' => 'pending',
            ]);

            return response()->json([
                'status' => 'success',
                'gateway' => 'wema_alat',
                'reference' => $reference,
                'tuition_amount' => $amount,
                'bank_charge' => $bankChargeBearer === 'parent' ? $bankChargeAmount : 0,
                'platform_fee' => $platformFeeBearer === 'parent' ? $platformFeeAmount : 0,
                'total_payable' => $totalPayableByParent,
                'virtual_account' => $wemaVirtualAcc,
                'authorization_url' => $wemaAlatRes['checkout_url'] ?? null,
                'checkout_url' => $wemaAlatRes['checkout_url'] ?? null,
                'message' => 'Wema Bank Virtual Account generated successfully.',
            ]);
        }

        $hasMonnifyKeys = (bool) config('services.monnify.contract_code');

        // ── 1. MONNIFY GATEWAY (Instant Settlement) ──
        if ($chosenGateway === 'monnify' && $hasMonnifyKeys) {
            // Ensure Monnify subaccount exists for school
            if (! $bankAccount->monnify_subaccount_code && $bankAccount->account_number && $bankAccount->bank_code) {
                try {
                    $monCode = $this->monnifyService->createOrUpdateSubaccount(
                        (int) $admin->school_id,
                        $bankAccount->account_number,
                        $bankAccount->bank_code,
                        $bankAccount->account_name,
                        $admin->email ?? null,
                        100.0
                    );
                    $bankAccount->update(['monnify_subaccount_code' => $monCode]);
                } catch (\Throwable $subErr) {
                    Log::warning("Could not auto-register Monnify subaccount for school {$admin->school_id}: " . $subErr->getMessage());
                }
            }

            try {
                $monnifyRes = $this->monnifyService->initializeTransaction([
                    'amount' => $amount,
                    'customer_name' => $data['payer_name'] ?? trim(($student->firstname ?? '') . ' ' . ($student->surname ?? '')) ?: 'Parent/Sponsor',
                    'customer_email' => $payerEmail,
                    'payment_reference' => $reference,
                    'payment_description' => "School Fee Payment for {$student->reg_no} ({$student->firstname})",
                    'redirect_url' => $callbackUrl,
                    'subaccount_code' => $bankAccount->monnify_subaccount_code,
                    'split_percentage' => 100.0,
                ]);

                PublicFeePaymentIntent::create([
                    'school_id' => $admin->school_id,
                    'student_id' => $student->id,
                    'school_code' => trim($data['school_code']),
                    'student_reg_no' => trim($data['student_reg_no']),
                    'reference' => $reference,
                    'payer_email' => $payerEmail,
                    'payer_name' => $data['payer_name'] ?? null,
                    'payer_phone' => $data['payer_phone'] ?? null,
                    'amount' => $amount,
                    'platform_fee' => $platformFee,
                    'allocations' => $allocations,
                    'gateway' => 'monnify',
                    'monnify_response' => $monnifyRes['raw_response'] ?? null,
                    'status' => 'pending',
                ]);

                return response()->json([
                    'status' => 'success',
                    'gateway' => 'monnify',
                    'reference' => $reference,
                    'authorization_url' => $monnifyRes['checkout_url'],
                    'checkout_url' => $monnifyRes['checkout_url'],
                    'message' => 'Monnify payment initialized successfully.',
                ]);
            } catch (\Throwable $monInitErr) {
                Log::error("Monnify payment initialization failed: " . $monInitErr->getMessage(), ['trace' => $monInitErr->getTraceAsString()]);
                // If Monnify fails and Paystack secret exists, fallback to Paystack gracefully
                if (! config('services.paystack.secret')) {
                    $this->platformFeeService->releaseCharge($reference);
                    return response()->json(['message' => 'Monnify checkout error: ' . $monInitErr->getMessage()], 422);
                }
            }
        }

        // ── 2. PAYSTACK GATEWAY (Fallback or Selected) ──
        $payload = [
            'reference' => $reference,
            'email' => $payerEmail,
            'amount' => (int) round($amount * 100),
            'subaccount' => $bankAccount->paystack_subaccount_code,
            'bearer' => 'customer',
            'callback_url' => $callbackUrl,
            'metadata' => [
                'source' => 'public_fee_payment',
                'school_id' => $admin->school_id,
                'student_id' => $student->id,
                'student_reg_no' => $student->reg_no,
            ],
        ];

        if ($platformFee > 0) {
            $payload['transaction_charge'] = $platformFee * 100;
        }

        $response = Http::withToken(config('services.paystack.secret'))
            ->post('https://api.paystack.co/transaction/initialize', $payload);

        if (! $response->successful() || ! $response->json('status')) {
            $this->platformFeeService->releaseCharge($reference);

            return response()->json([
                'message' => $response->json('message') ?: 'Could not initialize payment gateway.',
            ], 422);
        }

        PublicFeePaymentIntent::create([
            'school_id' => $admin->school_id,
            'student_id' => $student->id,
            'school_code' => trim($data['school_code']),
            'student_reg_no' => trim($data['student_reg_no']),
            'reference' => $reference,
            'payer_email' => $payerEmail,
            'payer_name' => $data['payer_name'] ?? null,
            'payer_phone' => $data['payer_phone'] ?? null,
            'amount' => $amount,
            'platform_fee' => $platformFee,
            'allocations' => $allocations,
            'gateway' => 'paystack',
            'status' => 'pending',
        ]);

        return response()->json([
            'status' => 'success',
            'gateway' => 'paystack',
            'reference' => $reference,
            'authorization_url' => $response->json('data.authorization_url'),
            'access_code' => $response->json('data.access_code'),
        ]);
    }

    public function verify(string $reference)
    {
        $intent = PublicFeePaymentIntent::with(['school', 'student'])
            ->where('reference', $reference)
            ->first();

        if (! $intent) {
            return response()->json(['message' => 'Payment transaction reference not found.'], 404);
        }

        if ($intent->status === 'success') {
            return response()->json([
                'status' => 'success',
                'reference' => $intent->reference,
                'amount' => (float) $intent->amount,
                'student' => $intent->student ? $this->studentPayload($intent->student) : null,
                'receipt' => $this->receiptPayload($intent),
                'pdf_download_url' => url('/api/public/fee-payment/receipt/' . urlencode($intent->reference) . '/pdf'),
            ]);
        }

        $gateway = $intent->gateway ?: (str_starts_with($reference, 'gq_mon_') ? 'monnify' : (str_starts_with($reference, 'gq_fee_') ? 'wema_alat' : 'paystack'));

        // ── 0. VERIFY WITH WEMA ALAT (Dedicated Virtual Account / ALAT Web Checkout) ──
        if ($gateway === 'wema_alat') {
            try {
                $wemaQuery = $this->wemaService->verifyTransaction($reference);
                $isLocalDev = app()->environment('local', 'testing');
                $isVerified = !empty($wemaQuery['verified']);

                if ($isVerified || ($isLocalDev && config('services.wema_alat.sandbox', false))) {
                    $this->finalizeSuccessfulPaymentIntent($intent, 'wema_alat', $wemaQuery['raw'] ?? ['mode' => 'sandbox_verified']);
                    $intent = $intent->fresh(['student']);

                    return response()->json([
                        'status' => 'success',
                        'reference' => $intent->reference,
                        'amount' => (float) $intent->amount,
                        'student' => $intent->student ? $this->studentPayload($intent->student) : null,
                        'receipt' => $this->receiptPayload($intent),
                        'pdf_download_url' => url('/api/public/fee-payment/receipt/' . urlencode($intent->reference) . '/pdf'),
                    ]);
                }

                return response()->json([
                    'status' => 'pending',
                    'reference' => $reference,
                    'message' => 'Interbank bank transfer is pending clearing. If you have sent funds, please allow 30-60 seconds for Wema NIP settlement.',
                ], 422);
            } catch (\Throwable $wemaErr) {
                Log::error("Wema verify failed: " . $wemaErr->getMessage());
                return response()->json(['message' => 'Could not verify Wema payment: ' . $wemaErr->getMessage()], 422);
            }
        }

        // ── 1. VERIFY WITH MONNIFY ──
        if ($gateway === 'monnify') {
            try {
                $monnifyQuery = $this->monnifyService->verifyTransaction($reference);

                if ($monnifyQuery['is_paid']) {
                    $this->finalizeSuccessfulPaymentIntent($intent, 'monnify', $monnifyQuery['raw_response']);
                    $intent = $intent->fresh(['student']);

                    return response()->json([
                        'status' => 'success',
                        'reference' => $intent->reference,
                        'amount' => (float) $intent->amount,
                        'student' => $intent->student ? $this->studentPayload($intent->student) : null,
                        'receipt' => $this->receiptPayload($intent),
                        'pdf_download_url' => url('/api/public/fee-payment/receipt/' . urlencode($intent->reference) . '/pdf'),
                    ]);
                }

                return response()->json([
                    'status' => $monnifyQuery['status'],
                    'reference' => $reference,
                    'message' => 'Payment is pending or was not completed on Monnify.',
                ], 422);
            } catch (\Throwable $monErr) {
                Log::error("Monnify verify failed: " . $monErr->getMessage());
                return response()->json(['message' => 'Could not verify Monnify payment: ' . $monErr->getMessage()], 422);
            }
        }

        // ── 2. VERIFY WITH PAYSTACK ──
        $response = Http::withToken(config('services.paystack.secret'))
            ->get("https://api.paystack.co/transaction/verify/{$reference}");

        if (! $response->successful()) {
            return response()->json(['message' => 'Could not verify Paystack payment.'], 422);
        }

        $status = $response->json('data.status');

        if ($status !== 'success') {
            $intent->update([
                'status' => 'failed',
                'paystack_response' => $response->json('data'),
            ]);
            $this->platformFeeService->releaseCharge($reference);

            return response()->json([
                'status' => 'failed',
                'reference' => $reference,
                'message' => 'Payment was not successful.',
            ], 422);
        }

        $this->finalizeSuccessfulPaymentIntent($intent, 'paystack', $response->json('data') ?: []);

        $intent = $intent->fresh(['student']);
        $receipt = $this->receiptPayload($intent);

        return response()->json([
            'status' => 'success',
            'reference' => $intent->reference,
            'amount' => (float) $intent->amount,
            'student' => $intent->student ? $this->studentPayload($intent->student) : null,
            'receipt' => $receipt,
            'pdf_download_url' => url('/api/public/fee-payment/receipt/' . urlencode($intent->reference) . '/pdf'),
        ]);
    }

    /**
     * Monnify Server-to-Server Webhook listener
     */
    public function monnifyWebhook(Request $request)
    {
        $signature = $request->header('monnify-signature');
        $rawContent = $request->getContent();

        if (! $this->monnifyService->validateWebhookSignature($rawContent, $signature)) {
            Log::warning('Monnify webhook signature mismatch', ['signature' => $signature]);
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $payload = $request->json()->all();
        $eventType = $payload['eventType'] ?? '';
        $eventData = $payload['eventData'] ?? [];

        Log::info('Monnify Webhook received', ['eventType' => $eventType, 'paymentReference' => $eventData['paymentReference'] ?? null]);

        if ($eventType === 'SUCCESSFUL_TRANSACTION' || strtoupper((string) ($eventData['paymentStatus'] ?? '')) === 'PAID') {
            $paymentReference = $eventData['paymentReference'] ?? null;
            $transactionReference = $eventData['transactionReference'] ?? null;

            $intent = PublicFeePaymentIntent::where('reference', $paymentReference)
                ->orWhere('reference', $transactionReference)
                ->first();

            if ($intent && $intent->status === 'pending') {
                $this->finalizeSuccessfulPaymentIntent($intent, 'monnify', $eventData);
            }
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Paystack Server-to-Server Webhook listener
     */
    public function paystackWebhook(Request $request)
    {
        $signature = $request->header('x-paystack-signature');
        $secret = config('services.paystack.secret');
        $expected = hash_hmac('sha512', $request->getContent(), (string) $secret);

        if (! $signature || ! hash_equals($expected, $signature)) {
            Log::warning('Paystack webhook signature mismatch');
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $payload = $request->json()->all();
        $event = $payload['event'] ?? '';
        $data = $payload['data'] ?? [];

        if ($event === 'charge.success' && ($data['status'] ?? '') === 'success') {
            $reference = $data['reference'] ?? null;
            $intent = PublicFeePaymentIntent::where('reference', $reference)->first();

            if ($intent && $intent->status === 'pending') {
                $this->finalizeSuccessfulPaymentIntent($intent, 'paystack', $data);
            }
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Reusable transaction settlement & student fee deduction logic
     */
    private function finalizeSuccessfulPaymentIntent(PublicFeePaymentIntent $intent, string $method, array $gatewayResponse): void
    {
        DB::transaction(function () use ($intent, $method, $gatewayResponse) {
            $locked = PublicFeePaymentIntent::whereKey($intent->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                return;
            }

            $remaining = (float) $locked->amount;
            $allocations = $locked->allocations ?: [];
            $reference = $locked->reference;

            foreach ($allocations as $index => $allocation) {
                if ($remaining <= 0) {
                    break;
                }

                $studentFee = StudentFee::where('school_id', $locked->school_id)
                    ->where('student_id', $locked->student_id)
                    ->where('id', $allocation['student_fee_id'] ?? null)
                    ->lockForUpdate()
                    ->first();

                if (! $studentFee || (float) $studentFee->balance <= 0) {
                    continue;
                }

                $amount = min((float) ($allocation['amount'] ?? 0), (float) $studentFee->balance, $remaining);

                if ($amount <= 0) {
                    continue;
                }

                $paymentReference = $index === 0 ? $reference : $reference . '-' . ($index + 1);

                $paymentData = [
                    'student_fee_id' => $studentFee->id,
                    'school_id' => $locked->school_id,
                    'amount' => $amount,
                    'platform_fee' => (float) ($allocation['platform_fee'] ?? 0),
                    'payment_method' => $method,
                    'reference' => $paymentReference,
                    'status' => 'success',
                    'paid_by' => null,
                    'received_by' => null,
                    'email' => $locked->payer_email,
                ];

                if ($method === 'monnify') {
                    $paymentData['paystack_response'] = $gatewayResponse; // stores gateway raw json
                } else {
                    $paymentData['paystack_response'] = $gatewayResponse;
                }

                $payment = Payment::create($paymentData);

                PaymentReceipt::create([
                    'student_id' => $locked->student_id,
                    'school_id' => $locked->school_id,
                    'payment_id' => $payment->id,
                    'payment_method' => $method,
                    'status' => 'approved',
                    'notes' => 'Online fee payment (' . strtoupper($method) . '): ' . $paymentReference,
                ]);

                $amountPaid = (float) $studentFee->amount_paid + $amount;
                $balance = max(0, (float) $studentFee->total_amount - $amountPaid);

                $studentFee->update([
                    'amount_paid' => $amountPaid,
                    'balance' => $balance,
                    'status' => $balance <= 0 ? 'paid' : 'partial',
                ]);

                if ((float) ($allocation['platform_fee'] ?? 0) > 0) {
                    $this->schoolBillingService->markOnlineEntitlementFromPayment($payment->fresh());
                    $this->salesCommissionService->recordCoreCommission($payment->fresh('studentFee'));
                }

                $remaining = round($remaining - $amount, 2);
            }

            if ((float) $locked->platform_fee > 0) {
                $this->platformFeeService->confirmCharge($reference);
            }

            $updateData = [
                'status' => 'success',
                'paid_at' => now(),
            ];

            if ($method === 'monnify') {
                $updateData['monnify_response'] = $gatewayResponse;
            } else {
                $updateData['paystack_response'] = $gatewayResponse;
            }

            $locked->update($updateData);
        });

        // Send official school fee receipts via email
        try {
            $intent = $intent->fresh(['student']);
            $receipt = $this->receiptPayload($intent);
            $student = $intent->student;
            $admin = User::where('school_id', $intent->school_id)
                ->where(function ($q) {
                    $q->whereRaw('LOWER(role) = ?', ['admin'])
                      ->orWhereRaw('LOWER(role) = ?', ['owner'])
                      ->orWhereRaw('LOWER(role) = ?', ['proprietor']);
                })
                ->first();

            // 1. Send receipt to payer
            if (!empty($intent->payer_email) && filter_var($intent->payer_email, FILTER_VALIDATE_EMAIL)) {
                Mail::to($intent->payer_email)->send(new SchoolFeePaymentReceiptMail($receipt, false));
            }

            // 2. Send receipt to student if different from payer
            if ($student && !empty($student->email) && filter_var($student->email, FILTER_VALIDATE_EMAIL) && strtolower((string) $student->email) !== strtolower((string) $intent->payer_email)) {
                Mail::to($student->email)->send(new SchoolFeePaymentReceiptMail($receipt, false));
            }

            // 3. Send notification copy to school admin
            if ($admin && !empty($admin->email) && filter_var($admin->email, FILTER_VALIDATE_EMAIL) && strtolower((string) $admin->email) !== strtolower((string) $intent->payer_email)) {
                Mail::to($admin->email)->send(new SchoolFeePaymentReceiptMail($receipt, true));
            }
        } catch (\Throwable $mailErr) {
            Log::error('Failed to send fee payment receipt email: ' . $mailErr->getMessage());
        }
    }

    public function downloadPdfReceipt(string $reference)
    {
        $intent = PublicFeePaymentIntent::where('reference', $reference)
            ->where('status', 'success')
            ->firstOrFail();

        $payload = $this->receiptPayload($intent);

        $filename = 'receipt-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($intent->reference)) . '.pdf';

        $response = Pdf::loadView('pdf.fee-payment-receipt', $payload)
            ->setPaper('a4', 'portrait')
            ->download($filename);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }

    public function receiptPayload(PublicFeePaymentIntent $intent): array
    {
        $school = SchoolSetting::find($intent->school_id);
        $student = User::with(['level:id,name', 'section:id,name'])->find($intent->student_id);

        $allocations = $intent->allocations ?: [];
        $feeIds = collect($allocations)->pluck('student_fee_id')->filter()->all();
        $studentFees = StudentFee::with(['feeType:id,name', 'session:id,name', 'term:id,name'])
            ->whereIn('id', $feeIds)
            ->get()
            ->keyBy('id');

        $items = [];
        foreach ($allocations as $alloc) {
            $feeId = $alloc['student_fee_id'] ?? null;
            $fee = $studentFees->get($feeId);
            $items[] = [
                'name' => $fee?->feeType?->name ?? 'School Fee',
                'session' => $fee?->session?->name ?? '',
                'term' => $fee?->term?->name ?? '',
                'amount' => (float) ($alloc['amount'] ?? 0),
            ];
        }

        $remainingBalance = StudentFee::where('school_id', $intent->school_id)
            ->where('student_id', $intent->student_id)
            ->sum('balance');

        $logoBase64 = $this->schoolLogoDataUri($school?->logo);

        return [
            'school' => $school,
            'student' => $student,
            'reference' => $intent->reference,
            'receipt_no' => 'REC-' . strtoupper(substr(md5($intent->reference), 0, 8)),
            'paid_at' => $intent->paid_at ?? $intent->updated_at ?? now(),
            'payer_name' => $intent->payer_name,
            'payer_email' => $intent->payer_email,
            'amount' => (float) $intent->amount,
            'remaining_balance' => (float) $remainingBalance,
            'items' => $items,
            'logoBase64' => $logoBase64,
        ];
    }

    private function schoolLogoDataUri(?string $logo): ?string
    {
        if (! $logo) {
            return null;
        }

        $relativePath = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $logo), DIRECTORY_SEPARATOR);
        $path = public_path($relativePath);
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $mime = mime_content_type($path) ?: 'image/png';
        return 'data:'.$mime.';base64,'.base64_encode(file_get_contents($path));
    }

    private function resolveSchoolAdmin(string $schoolCode): ?User
    {
        $code = trim($schoolCode);
        return User::with('school')
            ->where(function ($q) {
                $q->whereRaw('LOWER(role) = ?', ['admin'])
                  ->orWhereRaw('LOWER(role) = ?', ['owner'])
                  ->orWhereRaw('LOWER(role) = ?', ['proprietor']);
            })
            ->where(function ($q) use ($code) {
                $q->where('reg_no', $code)
                  ->orWhere('id', is_numeric($code) ? (int) $code : 0)
                  ->orWhereHas('school', function ($sq) use ($code) {
                      $sq->where('id', is_numeric($code) ? (int) $code : 0)
                         ->orWhere('school_name', 'like', '%'.$code.'%');
                  });
            })
            ->whereNotNull('school_id')
            ->first();
    }

    private function resolveStudent(int $schoolId, string $studentRegNo): ?User
    {
        return User::with(['level:id,name', 'section:id,name'])
            ->where('school_id', $schoolId)
            ->whereRaw('LOWER(role) = ?', ['student'])
            ->where('reg_no', trim($studentRegNo))
            ->first();
    }

    private function outstandingFees(int $schoolId, int $studentId)
    {
        $fees = StudentFee::with(['feeType:id,name', 'session:id,name', 'term:id,name'])
            ->where('school_id', $schoolId)
            ->where('student_id', $studentId)
            ->where('balance', '>', 0)
            ->get();

        [$currentSession, $currentTerm] = $this->schoolBillingService->currentPeriod($schoolId);
        $currentSessionId = $currentSession?->id ?? 0;
        $currentTermId = $currentTerm?->id ?? 0;

        // Current active session and term gets Priority 0 (highest), followed by past or future terms
        return $fees->sortBy(function (StudentFee $fee) use ($currentSessionId, $currentTermId) {
            $isCurrent = ($fee->session_id == $currentSessionId && $fee->term_id == $currentTermId) ? 0 : 1;
            return sprintf('%d_%08d_%08d_%08d', $isCurrent, $fee->session_id, $fee->term_id, $fee->id);
        })->values();
    }

    private function buildAllocations($fees, float $amount): array
    {
        $remaining = $amount;
        $allocations = [];

        foreach ($fees as $fee) {
            if ($remaining <= 0) {
                break;
            }

            $payable = min((float) $fee->balance, $remaining);

            if ($payable <= 0) {
                continue;
            }

            $allocations[] = [
                'student_fee_id' => (int) $fee->id,
                'amount' => round($payable, 2),
            ];

            $remaining = round($remaining - $payable, 2);
        }

        return $allocations;
    }

    private function attachPlatformFeesToAllocations($fees, array &$allocations, string $reference): int
    {
        $platformFee = 0;

        foreach ($allocations as &$allocation) {
            $fee = $fees->firstWhere('id', $allocation['student_fee_id'] ?? null);

            if (! $fee) {
                $allocation['platform_fee'] = 0;
                continue;
            }

            $charge = $this->platformFeeService->resolveCharge(
                $fee,
                (int) round((float) ($allocation['amount'] ?? 0)),
                $reference
            );

            $allocation['platform_fee'] = $charge;
            $platformFee += $charge;
        }

        unset($allocation);

        return $platformFee;
    }

    private function studentFeePayload(User $admin, User $student): array
    {
        $fees = $this->outstandingFees($admin->school_id, $student->id);

        $currentTermFee = $fees->first(function (StudentFee $fee) use ($admin) {
            [$session, $term] = $this->schoolBillingService->currentPeriod($admin->school_id);
            return $fee->session_id == $session?->id && $fee->term_id == $term?->id;
        }) ?: $fees->first();

        $totalTermAmount = (float) ($currentTermFee?->total_amount ?? $fees->sum('total_amount'));
        $totalTermPaid = (float) ($currentTermFee?->amount_paid ?? $fees->sum('amount_paid'));
        $totalBalance = (float) $fees->sum('balance');

        $installmentPlan = $this->feeAccessPolicyService->calculateInstallmentPlan(
            $admin->school_id,
            $totalTermAmount,
            $totalTermPaid,
            $totalBalance
        );

        $policy = $this->feeAccessPolicyService->policyForSchool((int) $admin->school_id);
        $activePlatformFee = (float) ($policy['platform_fee_amount'] ?? $this->platformFeeService->feeAmountNaira($currentTermFee));

        return [
            'school' => $this->schoolPayload($admin),
            'student' => $this->studentPayload($student),
            'summary' => [
                'total_amount' => round((float) $fees->sum('total_amount'), 2),
                'amount_paid' => round((float) $fees->sum('amount_paid'), 2),
                'balance' => round((float) $fees->sum('balance'), 2),
                'outstanding_items' => $fees->count(),
            ],
            'installment_plan' => $installmentPlan,
            'charge_policy' => [
                'bank_charge_bearer' => $policy['bank_charge_bearer'] ?? 'parent',
                'bank_charge_amount' => (float) ($policy['bank_charge_amount'] ?? 200.0),
                'platform_fee_bearer' => $policy['platform_fee_bearer'] ?? 'school',
                'platform_fee_amount' => $activePlatformFee,
                'active_edition_tier' => $policy['active_edition_tier'] ?? 'standard_cbt',
                'active_gateway' => $policy['active_payment_gateway'] ?? 'wema_alat',
            ],
            'fees' => $fees->map(fn (StudentFee $fee) => [
                'id' => $fee->id,
                'name' => $fee->feeType?->name ?? 'School Fee',
                'session' => $fee->session?->name,
                'term' => $fee->term?->name,
                'total_amount' => (float) $fee->total_amount,
                'amount_paid' => (float) $fee->amount_paid,
                'balance' => (float) $fee->balance,
                'status' => $fee->status,
            ])->values(),
        ];
    }

    private function schoolPayload(User $admin): array
    {
        $school = $admin->school;

        return [
            'id' => (int) $admin->school_id,
            'code' => $admin->reg_no ?: (string) $admin->school_id,
            'name' => $school?->school_name ?? $school?->name ?? $admin->name,
            'email' => $school?->email ?? $admin->email,
            'phone' => $school?->phone_number ?? $school?->phone ?? $admin->phone_number,
            'address' => $school?->address,
            'logo' => $school?->logo ? url($school->logo) : null,
        ];
    }

    private function studentPayload(User $student): array
    {
        return [
            'id' => (int) $student->id,
            'name' => trim(($student->firstname ?? '') . ' ' . ($student->surname ?? '')),
            'reg_no' => $student->reg_no,
            'class' => $student->level?->name,
            'section' => $student->section?->name,
        ];
    }
}
