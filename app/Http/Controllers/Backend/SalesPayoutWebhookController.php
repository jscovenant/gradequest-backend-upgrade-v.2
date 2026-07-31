<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\SalesPayoutBatch;
use App\Services\SalesPayoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SalesPayoutWebhookController extends Controller
{
    public function __construct(private SalesPayoutService $payoutService)
    {
    }

    public function handle(Request $request)
    {
        $secret = (string) config('services.paystack.secret');
        $signature = (string) $request->header('x-paystack-signature');
        $payload = $request->getContent();

        if ($secret && ! hash_equals(hash_hmac('sha512', $payload, $secret), $signature)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $event = $request->input('event');
        $data = $request->input('data', []);

        if (! in_array($event, ['transfer.success', 'transfer.failed', 'transfer.reversed'], true)) {
            return response()->json(['status' => 'ignored']);
        }

        $reference = $data['reference'] ?? null;
        $transferCode = $data['transfer_code'] ?? null;
        $transferId = isset($data['id']) ? (string) $data['id'] : null;

        $batch = SalesPayoutBatch::query()
            ->when($reference, fn ($query) => $query->orWhere('reference', $reference))
            ->when($transferCode, fn ($query) => $query->orWhere('paystack_transfer_code', $transferCode))
            ->when($transferId, fn ($query) => $query->orWhere('paystack_transfer_id', $transferId))
            ->first();

        if (! $batch) {
            Log::warning('Sales payout webhook received for unknown transfer', [
                'event' => $event,
                'reference' => $reference,
                'transfer_code' => $transferCode,
                'transfer_id' => $transferId,
            ]);

            return response()->json(['status' => 'unknown_transfer']);
        }

        if ($event === 'transfer.success') {
            $this->payoutService->markSuccessful($batch, $data);
        } else {
            $this->payoutService->markFailed(
                $batch,
                $data['reason'] ?? $data['failures'] ?? $data['status'] ?? 'Transfer was not successful.',
                $data
            );
        }

        return response()->json(['status' => 'ok']);
    }
}
