<?php

namespace App\Services;

use App\Events\PaymentReceived;
use App\Models\Payment;
use App\Models\SchoolBankAccount;
use App\Models\StudentFee;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class FeePaymentService
{
    public function __construct(
        private PlatformFeeService $platformFeeService,
        private SchoolBillingService $schoolBillingService,
        private SalesCommissionService $salesCommissionService
    )
    {
    }

    /**
     * Initialize a Paystack transaction for a fee installment.
     * The transaction is charged to SchoolProfit, and upon completion,
     * the platform fee is retained and the school's tuition is instantly
     * transferred to the school's registered bank account in real-time.
     */
    public function initialize(
        StudentFee $studentFee,
        int $amountNaira,
        string $payerEmail,
        ?int $parentId = null,
        ?string $callbackUrl = null
    ): array {
        $school = $studentFee->student->school;
        $bankAccount = SchoolBankAccount::where('school_id', $school->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->first();

        if (! $bankAccount) {
            throw new RuntimeException('This school has not completed bank account setup yet.');
        }

        $reference = 'gq_' . Str::uuid()->toString();

        $platformFeeNaira = $this->platformFeeService->resolveCharge($studentFee, $amountNaira, $reference);

        $resolvedCallback = $callbackUrl
            ?: (rtrim((string) (config('app.frontend_url') ?: 'https://schoolprofit.ng'), '/') . '/parent/payments');

        $payload = [
            'reference' => $reference,
            'email' => $payerEmail,
            'amount' => $amountNaira * 100,
            'bearer' => 'customer',
            'callback_url' => $resolvedCallback,
            'metadata' => [
                'school_id' => $school->id,
                'student_fee_id' => $studentFee->id,
                'student_id' => $studentFee->student_id,
            ],
        ];

        $response = Http::withToken(config('services.paystack.secret'))
            ->post('https://api.paystack.co/transaction/initialize', $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Could not initialize payment: ' . $response->body());
        }

        $data = $response->json('data');

        Payment::create([
            'student_fee_id' => $studentFee->id,
            'school_id' => $school->id,
            'amount' => $amountNaira,
            'platform_fee' => $platformFeeNaira,
            'payment_method' => 'paystack',
            'reference' => $reference,
            'status' => 'pending',
            'paid_by' => $parentId,
            'received_by' => null,
        ]);

        return [
            'authorization_url' => $data['authorization_url'],
            'access_code' => $data['access_code'],
            'reference' => $reference,
        ];
    }

    /**
     * Verify a transaction directly with Paystack — used by the frontend
     * callback page as a fallback/confirmation alongside the webhook.
     */
    public function verify(string $reference, ?int $schoolId = null): Payment
    {
        $query = Payment::query()->where('reference', $reference);
        if ($schoolId) {
            $query->where('school_id', $schoolId);
        }
        $payment = $query->firstOrFail();

        $response = Http::withToken(config('services.paystack.secret'))
            ->get("https://api.paystack.co/transaction/verify/{$reference}");

        if (! $response->successful()) {
            throw new RuntimeException('Could not verify payment: ' . $response->body());
        }

        $status = $response->json('data.status');

        $this->applyStatus($payment, $status, $response->json('data'));

        return $payment->fresh();
    }

    /**
     * Handle a Paystack webhook event. Signature must already be
     * verified by the controller before this is called.
     */
    public function handleWebhook(array $event): void
    {
        if (! in_array($event['event'] ?? null, ['charge.success', 'charge.failed'])) {
            return;
        }

        $reference = $event['data']['reference'] ?? null;
        $payment = Payment::where('reference', $reference)->first();

        if (! $payment) {
            return;
        }

        $status = $event['data']['status'] ?? 'failed';

        $this->applyStatus($payment, $status, $event['data']);
    }

    private function applyStatus(Payment $payment, string $paystackStatus, array $rawData): void
    {
        DB::transaction(function () use ($payment, $paystackStatus, $rawData): void {
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $this->applyStatusLocked($lockedPayment, $paystackStatus, $rawData);
        });
    }

    private function applyStatusLocked(Payment $payment, string $paystackStatus, array $rawData): void
    {
        if ($payment->status !== 'pending') {
            return; // already finalized
        }

        if ($paystackStatus === 'success') {
            $expectedKobo = (int) round(((float) $payment->amount) * 100);
            $providerKobo = (int) ($rawData['amount'] ?? 0);

            if ($providerKobo !== $expectedKobo) {
                throw new RuntimeException('Paystack payment amount does not match the expected transaction amount.');
            }

            $payment->update(['status' => 'success', 'paystack_response' => $rawData]);
            $this->applySuccessfulPaymentToStudentFee($payment->fresh());

            if ($payment->platform_fee > 0) {
                $this->platformFeeService->confirmCharge($payment->reference);
                $this->schoolBillingService->markOnlineEntitlementFromPayment($payment->fresh());
                $this->salesCommissionService->recordCoreCommission($payment->fresh('studentFee'));
            }

            $freshPayment = $payment->fresh();

            DB::afterCommit(function () use ($freshPayment) {
                PaymentReceived::dispatch((int) $freshPayment->id, (int) $freshPayment->school_id);
                $this->payoutSchoolInstantly($freshPayment);
            });

        } else {
            $payment->update(['status' => 'failed', 'paystack_response' => $rawData]);

            if ($payment->platform_fee > 0) {
                $this->platformFeeService->releaseCharge($payment->reference);
            }
        }
    }

    private function applySuccessfulPaymentToStudentFee(Payment $payment): void
    {
        $studentFee = StudentFee::query()->lockForUpdate()->find($payment->student_fee_id);

        if (! $studentFee) {
            return;
        }

        $amountPaid = (float) $studentFee->amount_paid + (float) $payment->amount;
        $balance = max(0, (float) $studentFee->total_amount - $amountPaid);

        $studentFee->update([
            'amount_paid' => $amountPaid,
            'balance' => $balance,
            'status' => $balance <= 0 ? 'paid' : 'partial',
        ]);
    }

    /**
     * Executes an instant real-time bank payout to the school's bank account
     * via Paystack Transfer API immediately upon fee payment success.
     */
    public function payoutSchoolInstantly(Payment $payment): void
    {
        $netAmountNaira = (float) $payment->amount - (float) ($payment->platform_fee ?? 0);
        if ($netAmountNaira <= 0) {
            return; // No tuition to disburse (e.g. fee-only transaction)
        }

        $bankAccount = SchoolBankAccount::where('school_id', $payment->school_id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->first();

        if (! $bankAccount) {
            Log::warning('Cannot execute instant school payout: no active bank account found', [
                'school_id' => $payment->school_id,
                'payment_id' => $payment->id,
            ]);
            return;
        }

        try {
            // Ensure Paystack recipient exists
            if (! $bankAccount->paystack_recipient_code) {
                $recipientRes = Http::withToken(config('services.paystack.secret'))
                    ->post('https://api.paystack.co/transferrecipient', [
                        'type' => 'nuban',
                        'name' => $bankAccount->account_name ?: ($bankAccount->school?->school_name ?? 'School'),
                        'account_number' => $bankAccount->account_number,
                        'bank_code' => $bankAccount->bank_code,
                        'currency' => 'NGN',
                    ]);

                if ($recipientRes->successful() && $recipientRes->json('status')) {
                    $recipientCode = $recipientRes->json('data.recipient_code');
                    $bankAccount->update(['paystack_recipient_code' => $recipientCode]);
                    $bankAccount->refresh();
                } else {
                    Log::error('Paystack recipient creation failed for instant school payout', [
                        'school_id' => $payment->school_id,
                        'response' => $recipientRes->json(),
                    ]);
                    return;
                }
            }

            $recipientCode = $bankAccount->paystack_recipient_code;
            $payoutRef = 'pout_' . Str::random(8) . '_' . $payment->id;

            $transferRes = Http::withToken(config('services.paystack.secret'))
                ->post('https://api.paystack.co/transfer', [
                    'source' => 'balance',
                    'amount' => (int) round($netAmountNaira * 100),
                    'recipient' => $recipientCode,
                    'reason' => "School Tuition Payout: Ref {$payment->reference}",
                    'reference' => $payoutRef,
                ]);

            $transferData = $transferRes->json();
            $meta = $payment->meta ?: [];
            $meta['instant_payout'] = [
                'status' => $transferRes->successful() && ($transferData['status'] ?? false) ? 'sent' : 'pending_review',
                'transfer_code' => $transferData['data']['transfer_code'] ?? null,
                'recipient_code' => $recipientCode,
                'amount' => $netAmountNaira,
                'reference' => $payoutRef,
                'response' => $transferData,
                'timestamp' => now()->toIso8601String(),
            ];

            $payment->update(['meta' => $meta]);

            Log::info('Instant school payout executed via Paystack', [
                'school_id' => $payment->school_id,
                'payment_id' => $payment->id,
                'net_amount' => $netAmountNaira,
                'payout_reference' => $payoutRef,
            ]);

        } catch (\Throwable $e) {
            Log::error('Exception during instant school payout: ' . $e->getMessage(), [
                'payment_id' => $payment->id,
                'school_id' => $payment->school_id,
            ]);
        }
    }
}
