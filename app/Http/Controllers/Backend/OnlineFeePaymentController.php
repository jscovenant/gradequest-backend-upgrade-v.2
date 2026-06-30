<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StudentFee;
use App\Services\FeePaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OnlineFeePaymentController extends Controller
{
    public function __construct(private FeePaymentService $feePaymentService)
    {
    }

    /**
     * POST /api/fees/initialize
     * Parent kicks off a fee payment (full amount or one installment).
     */
    public function initialize(Request $request)
    {
        $validated = $request->validate([
            'student_fee_id' => 'required|integer',
            'amount' => 'required|integer|min:100', // naira
        ]);

        $studentFee = StudentFee::findOrFail($validated['student_fee_id']);

        try {
            $result = $this->feePaymentService->initialize(
                $studentFee,
                $validated['amount'],
                $request->user()->email,
                $request->user()->id
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    /**
     * GET /api/fees/verify/{reference}
     * Frontend callback/return page hits this to confirm payment status.
     */
    public function verify(string $reference)
    {
        $payment = $this->feePaymentService->verify($reference);

        return response()->json([
            'status' => $payment->status,
            'reference' => $payment->reference,
            'amount' => $payment->amount,
            'platform_fee' => $payment->platform_fee,
        ]);
    }

    /**
     * POST /api/paystack/webhook
     * Paystack's server-to-server notification — not user-authenticated;
     * verified instead via the x-paystack-signature header.
     */
    public function webhook(Request $request)
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