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
        $eventData = $payload['data'] ?? $payload['Data'] ?? $payload;
        $reference = $eventData['reference'] 
            ?? $eventData['paymentReference'] 
            ?? $eventData['transactionReference'] 
            ?? $eventData['orderId'] 
            ?? $eventData['OrderId'] 
            ?? $eventData['TransactionReference']
            ?? $eventData['PaymentReference']
            ?? $eventData['Id']
            ?? null;
        $amountPaid = (float) ($eventData['amount'] ?? $eventData['Amount'] ?? $eventData['amountPaid'] ?? $eventData['AmountPaid'] ?? 0);
        $status = strtolower((string) ($eventData['status'] ?? $eventData['Status'] ?? $eventData['paymentStatus'] ?? $eventData['PaymentStatus'] ?? 'successful'));
        $accountNumber = $eventData['accountNumber'] 
            ?? $eventData['AccountNumber'] 
            ?? $eventData['staticAccountResponse']['accountNumber'] 
            ?? $eventData['staticAccountResponse']['AccountNumber'] 
            ?? $eventData['StaticAccountResponse']['AccountNumber'] 
            ?? null;

        if (! $reference && ! $accountNumber) {
            return response()->json(['message' => 'No reference or account number found in webhook payload.'], 400);
        }

        if (! in_array($status, ['successful', 'paid', 'success', 'completed', 'settled', '1', 1])) {
            Log::info("ALATPay/Wema Webhook skipped for non-successful status: {$status} on reference: {$reference}");
            return response()->json(['message' => 'Webhook received for non-success event.'], 200);
        }

        // ==========================================
        // BRANCH A: School Term / Platform Clearance & Invoice Payment
        // ==========================================
        $invPayment = GradiosEduInvoicePayment::where('reference', $reference)
            ->orWhere('paystack_response->account_number', $accountNumber)
            ->orWhere('paystack_response->account_number', $eventData['virtualBankAccountNumber'] ?? '')
            ->orWhere('paystack_response->account_number', $eventData['ngnVirtualBankAccountNumber'] ?? '')
            ->latest()
            ->first();

        if ($invPayment) {
            if ($invPayment->status === 'successful') {
                return response()->json(['message' => 'Invoice/clearance payment already fulfilled.'], 200);
            }

            DB::beginTransaction();
            try {
                $invPayment->update([
                    'status' => 'successful',
                    'channel' => 'wema_virtual_account',
                    'paid_at' => now(),
                    'paystack_response' => array_merge(
                        is_array($invPayment->paystack_response) ? $invPayment->paystack_response : [],
                        ['webhook_event' => $payload, 'settled_via' => 'Wema Bank Virtual Account']
                    ),
                ]);

                $meta = $invPayment->paystack_response['metadata'] ?? [];
                if (! empty($meta['student_ids'])) {
                    $this->schoolBillingService->applyOnlineClearance(
                        (int) ($meta['school_id'] ?? $invPayment->school_id),
                        (string) ($meta['type'] ?? 'selected'),
                        (array) $meta['student_ids'],
                        (int) ($meta['session_id'] ?? 0),
                        isset($meta['term_id']) ? (int) $meta['term_id'] : null,
                        (bool) ($meta['is_full_session'] ?? false),
                        $invPayment->reference,
                        'wema_virtual_account',
                        (int) ($invPayment->user_id ?? 1),
                        $payload
                    );
                }

                $invoice = GradiosEduTermInvoice::find($invPayment->invoice_id);
                if ($invoice) {
                    $effectiveAmount = $amountPaid > 0 ? $amountPaid : (float) $invPayment->amount;
                    $this->schoolBillingService->applyOnlineInvoicePayment(
                        $invoice,
                        $effectiveAmount,
                        (int) ($invPayment->user_id ?? 1),
                        $invPayment->reference
                    );

                    Log::info("Wema Virtual Account payment settled for invoice #{$invoice->id}, School: {$invoice->school_id}, Amount: ₦{$effectiveAmount}");
                }

                DB::commit();

                return response()->json([
                    'status' => 'success',
                    'message' => 'Clearance/Invoice payment processed successfully via Wema Virtual Account.',
                    'reference' => $invPayment->reference,
                ]);
            } catch (\Throwable $invErr) {
                DB::rollBack();
                Log::error('Error settling invoice payment from Wema Webhook: ' . $invErr->getMessage(), ['trace' => $invErr->getTraceAsString()]);
                return response()->json(['message' => 'Internal server error processing invoice webhook.'], 500);
            }
        }

        // ==========================================
        // BRANCH B: Online Admission Application Fee (Wema Instant Split Settlement)
        // ==========================================
        $admissionPayment = \App\Models\SchoolAdmissionPayment::where('reference', $reference)->first();
        if ($admissionPayment) {
            if ($admissionPayment->status === 'successful') {
                return response()->json(['message' => 'Admission payment already settled.'], 200);
            }

            DB::beginTransaction();
            try {
                $admissionPayment->update([
                    'status' => 'successful',
                    'paid_at' => now(),
                    'settled_at' => now(),
                    'gateway_response' => array_merge(
                        is_array($admissionPayment->gateway_response) ? $admissionPayment->gateway_response : [],
                        ['webhook_event' => $payload, 'settled_via' => 'Wema Bank Virtual Account']
                    ),
                ]);

                $application = \App\Models\SchoolAdmissionApplication::find($admissionPayment->application_id);
                if ($application) {
                    $application->update(['payment_status' => 'paid']);

                    $setting = \App\Models\SchoolAdmissionSetting::where('school_id', $application->school_id)->first();
                    if ($setting && $setting->auto_admit) {
                        $application->update(['status' => 'admitted']);
                    }
                }

                DB::commit();

                Log::info("Wema Admission Payment settled for application {$application?->application_number}, School: {$admissionPayment->school_id}, Total: ₦{$admissionPayment->total_amount}, School Share: ₦{$admissionPayment->school_amount}, Platform Fee: ₦{$admissionPayment->platform_fee}");

                return response()->json([
                    'status' => 'success',
                    'type' => 'school_admission_payment',
                    'reference' => $reference,
                ], 200);
            } catch (\Throwable $admErr) {
                DB::rollBack();
                Log::error('Wema webhook admission settlement error: ' . $admErr->getMessage());
                return response()->json(['message' => 'Admission webhook settlement error: ' . $admErr->getMessage()], 500);
            }
        }

        // ==========================================
        // BRANCH C: Student Tuition / Fee Intent Payment
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
