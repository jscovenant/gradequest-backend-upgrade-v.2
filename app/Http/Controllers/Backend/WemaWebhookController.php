<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\GradiosEduInvoicePayment;
use App\Models\GradiosEduTermInvoice;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\PublicFeePaymentIntent;
use App\Models\SchoolBankAccount;
use App\Models\SchoolSetting;
use App\Models\StudentFee;
use App\Models\User;
use App\Mail\SchoolFeePaymentReceiptMail;
use App\Services\PlatformFeeService;
use App\Services\SchoolBillingService;
use App\Services\SalesCommissionService;
use App\Services\WemaAlatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class WemaWebhookController extends Controller
{
    public function __construct(
        private WemaAlatService $wemaService,
        private PlatformFeeService $platformFeeService,
        private SchoolBillingService $schoolBillingService,
        private SalesCommissionService $salesCommissionService
    ) {
    }

    /**
     * Handle incoming Wema Bank Webhooks for Virtual Accounts and ALAT Pay.
     */
    public function handle(Request $request)
    {
        Log::info('Wema Bank Webhook Received: ', $request->all());

        if (! $this->wemaService->verifyWebhookSignature($request)) {
            Log::warning('Wema Webhook signature verification failed.');
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = $request->all();
        $eventData = $payload['data'] ?? $payload;
        $reference = $eventData['reference'] ?? $eventData['paymentReference'] ?? $eventData['transactionReference'] ?? null;
        $amountPaid = (float) ($eventData['amount'] ?? $eventData['amountPaid'] ?? 0);
        $status = strtolower((string) ($eventData['status'] ?? $eventData['paymentStatus'] ?? 'successful'));

        if (! $reference) {
            return response()->json(['message' => 'No reference found in webhook payload.'], 400);
        }

        if (! in_array($status, ['successful', 'paid', 'success', 'completed'])) {
            Log::info("Wema Webhook skipped for non-successful status: {$status} on reference: {$reference}");
            return response()->json(['message' => 'Webhook received for non-success event.'], 200);
        }

        // ==========================================
        // BRANCH A: School Term / Platform Invoice Payment
        // ==========================================
        $invPayment = GradiosEduInvoicePayment::where('reference', $reference)->first();
        if ($invPayment) {
            if ($invPayment->status === 'successful') {
                return response()->json(['message' => 'Invoice payment already fulfilled.'], 200);
            }

            DB::beginTransaction();
            try {
                $invoice = GradiosEduTermInvoice::find($invPayment->invoice_id);
                if ($invoice) {
                    $invPayment->update([
                        'status' => 'successful',
                        'channel' => 'wema_virtual_account',
                        'paid_at' => now(),
                        'paystack_response' => array_merge(
                            is_array($invPayment->paystack_response) ? $invPayment->paystack_response : [],
                            ['webhook_event' => $payload, 'settled_via' => 'Wema Bank Virtual Account']
                        ),
                    ]);

                    $effectiveAmount = $amountPaid > 0 ? $amountPaid : (float) $invPayment->amount;
                    $this->schoolBillingService->applyOnlineInvoicePayment(
                        $invoice,
                        $effectiveAmount,
                        (int) ($invPayment->user_id ?? 1),
                        $reference
                    );

                    Log::info("Wema Virtual Account payment settled for invoice #{$invoice->id}, School: {$invoice->school_id}, Amount: ₦{$effectiveAmount}");
                }

                DB::commit();

                return response()->json([
                    'status' => 'success',
                    'message' => 'Invoice payment processed successfully via Wema Virtual Account.',
                    'reference' => $reference,
                ]);
            } catch (\Throwable $invErr) {
                DB::rollBack();
                Log::error('Error settling invoice payment from Wema Webhook: ' . $invErr->getMessage(), ['trace' => $invErr->getTraceAsString()]);
                return response()->json(['message' => 'Internal server error processing invoice webhook.'], 500);
            }
        }

        // ==========================================
        // BRANCH B: Student Tuition / Fee Intent Payment
        // ==========================================
        $intent = PublicFeePaymentIntent::where('reference', $reference)
            ->orWhere('payment_reference', $reference)
            ->first();

        if (! $intent) {
            Log::info("No matching payment intent or invoice payment for Wema reference: {$reference}");
            return response()->json(['message' => 'Transaction not registered or already processed.'], 200);
        }

        if ($intent->status === 'paid') {
            return response()->json(['message' => 'Transaction already fulfilled.'], 200);
        }

        DB::beginTransaction();
        try {
            $studentFee = StudentFee::find($intent->student_fee_id);
            $schoolId = (int) $intent->school_id;
            $schoolAdmin = User::where('school_id', $schoolId)->whereHas('roles', fn ($q) => $q->where('name', 'Admin'))->first();
            $schoolSetting = SchoolSetting::where('school_id', $schoolId)->first();

            // Surcharge & fee calculation
            $bankChargeBearer = $schoolSetting->bank_charge_bearer ?? 'parent';
            $bankChargeAmount = (float) ($schoolSetting->bank_charge_amount ?? 200.00);
            $platformFeeAmount = (float) $this->platformFeeService->feeAmountNaira($studentFee);
            $platformFeeBearer = $schoolSetting->platform_fee_bearer ?? 'school';

            // Calculate exact net payout for the school
            $tuitionPortion = (float) ($intent->amount ?? $amountPaid);
            $netPayoutToSchool = $tuitionPortion;

            if ($platformFeeBearer === 'school') {
                $netPayoutToSchool = max(0, $netPayoutToSchool - $platformFeeAmount);
            }
            if ($bankChargeBearer === 'school') {
                $netPayoutToSchool = max(0, $netPayoutToSchool - $bankChargeAmount);
            }

            // Update Intent
            $intent->status = 'paid';
            $intent->paid_at = now();
            $intent->gateway_response = $payload;
            $intent->save();

            // Create Payment Record
            $payment = Payment::create([
                'school_id' => $schoolId,
                'student_id' => $intent->student_id,
                'student_fee_id' => $intent->student_fee_id,
                'amount_paid' => $tuitionPortion,
                'payment_method' => 'Bank Transfer (Wema Bank Virtual Account)',
                'payment_reference' => $reference,
                'payment_date' => now(),
                'status' => 'paid',
                'term' => $studentFee->term ?? 'Current Term',
                'session' => $studentFee->academic_session ?? 'Current Session',
            ]);

            // Update student fee balance
            if ($studentFee) {
                $studentFee->paid_amount = (float) $studentFee->paid_amount + $tuitionPortion;
                $studentFee->balance = max(0, (float) $studentFee->total_amount - (float) $studentFee->paid_amount);
                $studentFee->status = $studentFee->balance <= 0 ? 'paid' : 'partial';
                $studentFee->save();
            }

            // Generate Receipt
            $receiptNumber = 'REC-WEMA-' . strtoupper(Str::random(8));
            $receipt = PaymentReceipt::create([
                'payment_id' => $payment->id,
                'school_id' => $schoolId,
                'student_id' => $intent->student_id,
                'student_fee_id' => $intent->student_fee_id,
                'receipt_number' => $receiptNumber,
                'amount' => $tuitionPortion,
                'receipt_date' => now(),
                'payment_method' => 'Wema Bank Virtual Account',
                'status' => 'approved',
                'notes' => "Automated settlement via Wema Bank Virtual Account. NIBSS Ref: {$reference}",
            ]);

            // Automatic Same-Minute Pass-Through Sweep to School Bank Account
            $schoolBank = SchoolBankAccount::where('school_id', $schoolId)->where('is_active', true)->first();
            if ($schoolBank && ! empty($schoolBank->account_number) && $netPayoutToSchool > 0) {
                $payoutRef = 'SWEEP_' . strtoupper(Str::random(12));
                $payoutResult = $this->wemaService->singleTransfer([
                    'amount' => $netPayoutToSchool,
                    'destination_bank_code' => $schoolBank->bank_code ?? '035',
                    'destination_account_number' => $schoolBank->account_number,
                    'destination_account_name' => $schoolBank->account_name ?? 'School Account',
                    'narration' => "SchoolProfit Tuition Sweep: {$intent->student_name} - Inv #{$intent->id}",
                    'reference' => $payoutRef,
                ]);

                Log::info("Automated Wema tuition sweep completed for School {$schoolId}: Net ₦{$netPayoutToSchool}", $payoutResult);
            }

            // Send Email Confirmation
            if (! empty($intent->payer_email)) {
                try {
                    Mail::to($intent->payer_email)->send(new SchoolFeePaymentReceiptMail($receipt, $payment, $studentFee));
                } catch (\Throwable $e) {
                    Log::warning('Could not send automated receipt email: ' . $e->getMessage());
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Wema payment processed and school sweep dispatched successfully.',
                'reference' => $reference,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error processing Wema Webhook: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Internal server error processing webhook.'], 500);
        }
    }
}
