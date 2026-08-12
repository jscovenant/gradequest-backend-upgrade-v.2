<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\AiCreditPurchaseController;
use App\Models\AiCreditPurchase;
use App\Models\Payment;
use App\Models\PublicFeePaymentIntent;
use App\Models\SalesPayoutBatch;
use App\Models\SubPayment;
use App\Services\FeePaymentService;
use App\Services\SalesPayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaystackWebhookController extends Controller
{
    public function __construct(
        private FeePaymentService $feePayments,
        private SalesPayoutService $salesPayouts,
        private SubscriptionController $subscriptions,
        private PublicFeePaymentController $publicFeePayments,
        private AiCreditPurchaseController $aiCreditPurchases,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $secret = trim((string) config('services.paystack.secret'));
        $signature = (string) $request->header('x-paystack-signature');

        if ($secret === '' || $signature === '' || ! hash_equals(hash_hmac('sha512', $request->getContent(), $secret), $signature)) {
            Log::warning('Paystack webhook rejected because its signature was invalid.');
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = $request->json()->all();
        $event = (string) ($payload['event'] ?? '');
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        try {
            if (str_starts_with($event, 'transfer.')) {
                return $this->handleTransfer($event, $data);
            }

            if (in_array($event, ['charge.success', 'charge.failed'], true)) {
                return $this->handleCharge($payload, $data);
            }

            return response()->json(['status' => 'ignored']);
        } catch (Throwable $exception) {
            Log::error('Paystack webhook processing failed.', [
                'event' => $event,
                'reference' => $data['reference'] ?? null,
                'message' => $exception->getMessage(),
            ]);

            // A non-2xx response makes Paystack retry the event.
            return response()->json(['message' => 'Webhook processing failed.'], 500);
        }
    }

    private function handleCharge(array $payload, array $data): JsonResponse
    {
        $reference = (string) ($data['reference'] ?? '');
        if ($reference === '') return response()->json(['message' => 'Payment reference is required.'], 422);

        if (Payment::where('reference', $reference)->exists()) {
            $this->feePayments->handleWebhook($payload);
            return response()->json(['status' => 'ok', 'type' => 'school_fee']);
        }

        if (PublicFeePaymentIntent::where('reference', $reference)->exists()) {
            $this->publicFeePayments->verify($reference);
            return response()->json(['status' => 'ok', 'type' => 'public_school_fee']);
        }

        if (SubPayment::where('reference', $reference)->exists()) {
            $this->subscriptions->handleVerifiedWebhook($payload);
            return response()->json(['status' => 'ok', 'type' => 'subscription']);
        }

        if (AiCreditPurchase::where('reference', $reference)->exists()) {
            if (($data['status'] ?? null) !== 'success') {
                AiCreditPurchase::where('reference', $reference)
                    ->where('status', 'pending')
                    ->update(['status' => 'failed', 'metadata' => $data]);

                return response()->json(['status' => 'ok', 'type' => 'ai_credit_purchase_failed']);
            }

            $this->aiCreditPurchases->handleVerifiedWebhook($reference);
            return response()->json(['status' => 'ok', 'type' => 'ai_credit_purchase']);
        }

        Log::notice('Paystack charge webhook did not match a registered payment.', ['reference' => $reference]);
        return response()->json(['status' => 'unknown_payment']);
    }

    private function handleTransfer(string $event, array $data): JsonResponse
    {
        if (! in_array($event, ['transfer.success', 'transfer.failed', 'transfer.reversed'], true)) {
            return response()->json(['status' => 'ignored']);
        }

        $reference = $data['reference'] ?? null;
        $transferCode = $data['transfer_code'] ?? null;
        $transferId = isset($data['id']) ? (string) $data['id'] : null;
        if (! $reference && ! $transferCode && ! $transferId) return response()->json(['message' => 'Transfer identifier is required.'], 422);

        $batch = $reference
            ? SalesPayoutBatch::where('reference', $reference)->first()
            : ($transferCode
                ? SalesPayoutBatch::where('paystack_transfer_code', $transferCode)->first()
                : SalesPayoutBatch::where('paystack_transfer_id', $transferId)->first());

        if (! $batch) return response()->json(['status' => 'unknown_transfer']);

        $expectedAmount = (int) round(((float) $batch->total_amount) * 100);
        if (isset($data['amount']) && (int) $data['amount'] !== $expectedAmount) {
            Log::critical('Paystack transfer amount did not match the payout batch.', ['batch_id' => $batch->id]);
            return response()->json(['message' => 'Transfer amount mismatch.'], 422);
        }

        match ($event) {
            'transfer.success' => $this->salesPayouts->markSuccessful($batch, $data),
            'transfer.reversed' => $this->salesPayouts->markFailed($batch, 'Transfer reversed by Paystack.', $data, 'reversed'),
            default => $this->salesPayouts->markFailed($batch, (string) ($data['reason'] ?? $data['status'] ?? 'Transfer failed.'), $data),
        };

        return response()->json(['status' => 'ok', 'type' => 'sales_payout']);
    }
}



