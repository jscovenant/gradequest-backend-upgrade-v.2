<?php

namespace App\Services;

use App\Events\PaymentReceived;
use App\Models\Payment;
use App\Models\SchoolBankAccount;
use App\Models\StudentFee;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
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
     * Initialize a Paystack transaction for a fee installment, splitting
     * the payout between the school's subaccount and GradeQuest's main
     * account (platform fee, charged at most once per student_fee record).
     */
    public function initialize(
        StudentFee $studentFee,
        int $amountNaira,
        string $payerEmail,
        ?int $parentId = null
    ): array {
        $school = $studentFee->student->school;
        $bankAccount = SchoolBankAccount::where('school_id', $school->id)
            ->where('is_active', true)
            ->where('online_payment_enabled', true)
            ->whereNotNull('paystack_subaccount_code')
            ->orderBy('sort_order')
            ->first();

        if (! $bankAccount) {
            throw new RuntimeException('This school has not completed payment setup yet.');
        }

        $reference = 'gq_' . Str::uuid()->toString();

        // Fee logic works in naira to match your payments.amount column;
        // Paystack itself always wants amounts in kobo (subunits).
        $platformFeeNaira = $this->platformFeeService->resolveCharge($studentFee, $amountNaira, $reference);

        $payload = [
            'reference' => $reference,
            'email' => $payerEmail,
            'amount' => $amountNaira * 100,
            'subaccount' => $bankAccount->paystack_subaccount_code,
            'bearer' => 'account', // GradeQuest absorbs Paystack's processing fee
            'metadata' => [
                'school_id' => $school->id,
                'student_fee_id' => $studentFee->id,
            ],
        ];

        if ($platformFeeNaira > 0) {
            $payload['transaction_charge'] = $platformFeeNaira * 100;
        }

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
            'received_by' => null, // no staff receiver for self-service online payments
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
    public function verify(string $reference, int $schoolId): Payment
    {
        $payment = Payment::query()
            ->where('school_id', $schoolId)
            ->where('reference', $reference)
            ->firstOrFail();

        $response = Http::withToken(config('services.paystack.secret'))
            ->get("https://api.paystack.co/transaction/verify/{$reference}");

        if (! $response->successful()) {
            throw new RuntimeException('Could not verify payment: ' . $response->body());
        }

        $status = $response->json('data.status'); // 'success', 'failed', 'abandoned'

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
            return; // not a charge event we care about
        }

        $reference = $event['data']['reference'] ?? null;
        $payment = Payment::where('reference', $reference)->first();

        if (! $payment) {
            return; // not one of ours
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
            return; // already finalized — avoid double-processing from both webhook and verify
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

            DB::afterCommit(fn () => PaymentReceived::dispatch((int) $payment->id, (int) $payment->school_id));

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
}
