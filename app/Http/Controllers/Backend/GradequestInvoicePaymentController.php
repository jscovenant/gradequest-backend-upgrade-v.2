<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\GradiosEduInvoicePayment;
use App\Models\GradiosEduTermInvoice;
use App\Models\SchoolSetting;
use App\Services\SchoolBillingService;
use App\Services\WemaAlatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GradiosEduInvoicePaymentController extends Controller
{
    public function __construct(
        private SchoolBillingService $billing,
        private WemaAlatService $wemaService
    ) {
    }

    public function generateVirtualAccount(Request $request, GradiosEduTermInvoice $invoice)
    {
        $this->authorizeInvoice($request, $invoice);

        $invoice = $invoice->fresh();

        if ($invoice->billing_mode !== 'offline') {
            return response()->json(['message' => 'Only offline SchoolProfit invoices can be settled via direct bank transfer.'], 422);
        }

        if ((float) $invoice->balance <= 0) {
            return response()->json(['message' => 'This invoice is already fully paid.'], 422);
        }

        $validated = $request->validate([
            'amount' => 'nullable|numeric|min:100',
        ]);

        $amount = (float) ($validated['amount'] ?? $invoice->balance);
        $amount = min($amount, (float) $invoice->balance);

        $user = $request->user();
        $school = SchoolSetting::find($invoice->school_id);
        $schoolName = $school?->school_name ?? $school?->name ?? 'School';

        // Check if there is an existing active pending virtual account payment for this invoice
        $existingPayment = GradiosEduInvoicePayment::where('invoice_id', $invoice->id)
            ->where('channel', 'wema_virtual_account')
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subHours(24))
            ->latest()
            ->first();

        if ($existingPayment && ! empty($existingPayment->paystack_response['account_number'])) {
            return response()->json([
                'status' => true,
                'message' => 'Active Wema Bank Virtual Account retrieved.',
                'reference' => $existingPayment->reference,
                'virtual_account' => $existingPayment->paystack_response,
                'amount' => (float) $existingPayment->amount,
                'invoice' => $invoice,
            ]);
        }

        $reference = 'SP_INV_' . strtoupper(Str::random(12));

        $virtualAccount = $this->wemaService->generateVirtualAccount([
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
                'bank_name' => $virtualAccount['bank_name'] ?? 'Wema Bank',
                'account_number' => $virtualAccount['account_number'] ?? '',
                'account_name' => $virtualAccount['account_name'] ?? "SchoolProfit / {$schoolName}",
                'reference' => $reference,
                'amount' => $amount,
                'expires_at' => $virtualAccount['expires_at'] ?? now()->addHours(24)->toIso8601String(),
                'mode' => $virtualAccount['mode'] ?? 'sandbox',
            ],
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Unique Wema Bank Virtual Account generated successfully.',
            'reference' => $reference,
            'virtual_account' => $payment->paystack_response,
            'amount' => $amount,
            'invoice' => $invoice,
        ]);
    }

    public function show(Request $request, GradiosEduTermInvoice $invoice)
    {
        $this->authorizeInvoice($request, $invoice);

        return response()->json([
            'invoice' => $invoice->fresh(),
            'school' => SchoolSetting::find($invoice->school_id),
            'payments' => GradiosEduInvoicePayment::where('invoice_id', $invoice->id)
                ->latest()
                ->get(),
        ]);
    }

    public function initialize(Request $request, GradiosEduTermInvoice $invoice)
    {
        $this->authorizeInvoice($request, $invoice);

        $invoice = $invoice->fresh();

        if ($invoice->billing_mode !== 'offline') {
            return response()->json(['message' => 'Only offline GradiosEdu invoices can be paid here.'], 422);
        }

        if ((float) $invoice->balance <= 0) {
            return response()->json(['message' => 'This invoice is already fully paid.'], 422);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:100',
        ]);

        $amount = min((float) $validated['amount'], (float) $invoice->balance);
        $user = $request->user();
        $reference = 'gq_invoice_' . Str::uuid()->toString();

        $origin = $request->input('callback_url')
            ?: $request->header('origin')
            ?: ($request->header('referer') ? rtrim(parse_url($request->header('referer'), PHP_URL_SCHEME) . '://' . parse_url($request->header('referer'), PHP_URL_HOST), '/') : null)
            ?: rtrim((string) (config('app.frontend_url') ?: 'https://gradequest.com.ng'), '/');

        $callbackUrl = rtrim($origin, '/') . '/billing/invoice-payment/' . $invoice->id . '?reference=' . $reference;

        $response = Http::withToken(config('services.paystack.secret'))
            ->post('https://api.paystack.co/transaction/initialize', [
                'reference' => $reference,
                'email' => $user->email,
                'amount' => (int) round($amount * 100),
                'callback_url' => $callbackUrl,
                'metadata' => [
                    'source' => 'gradequest_invoice_payment',
                    'school_id' => $invoice->school_id,
                    'invoice_id' => $invoice->id,
                    'user_id' => $user->id,
                ],
            ]);

        if (! $response->successful() || ! $response->json('status')) {
            return response()->json([
                'message' => $response->json('message') ?: 'Could not initialize Paystack payment.',
            ], 422);
        }

        GradiosEduInvoicePayment::create([
            'school_id' => $invoice->school_id,
            'invoice_id' => $invoice->id,
            'user_id' => $user->id,
            'reference' => $reference,
            'amount' => $amount,
            'status' => 'pending',
        ]);

        return response()->json([
            'authorization_url' => $response->json('data.authorization_url'),
            'access_code' => $response->json('data.access_code'),
            'reference' => $reference,
        ]);
    }

    public function verify(Request $request, string $reference)
    {
        $payment = GradiosEduInvoicePayment::where('reference', $reference)->firstOrFail();
        $invoice = GradiosEduTermInvoice::findOrFail($payment->invoice_id);
        $this->authorizeInvoice($request, $invoice);

        if ($payment->status === 'successful') {
            return response()->json([
                'message' => 'Payment already verified.',
                'payment' => $payment,
                'invoice' => $invoice->fresh(),
            ]);
        }

        $response = Http::withToken(config('services.paystack.secret'))
            ->get("https://api.paystack.co/transaction/verify/{$reference}");

        if (! $response->successful() || ! $response->json('status')) {
            return response()->json(['message' => 'Payment verification failed.'], 422);
        }

        $data = $response->json('data');

        if (($data['status'] ?? null) !== 'success') {
            $payment->update([
                'status' => 'failed',
                'paystack_response' => $data,
            ]);

            return response()->json(['message' => 'Payment was not successful.'], 422);
        }

        DB::transaction(function () use ($payment, $invoice, $data, $request, $reference) {
            $locked = GradiosEduInvoicePayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'successful') {
                return;
            }

            $locked->update([
                'status' => 'successful',
                'channel' => $data['channel'] ?? null,
                'card_type' => $data['authorization']['card_type'] ?? null,
                'last4' => $data['authorization']['last4'] ?? null,
                'paystack_id' => $data['id'] ?? null,
                'paystack_response' => $data,
                'paid_at' => now(),
            ]);

            $this->billing->applyOnlineInvoicePayment($invoice, (float) $locked->amount, (int) $request->user()->id, $reference);
        });

        return response()->json([
            'message' => 'Invoice payment verified successfully.',
            'payment' => $payment->fresh(),
            'invoice' => $invoice->fresh(),
        ]);
    }

    private function authorizeInvoice(Request $request, GradiosEduTermInvoice $invoice): void
    {
        $user = $request->user();

        if ($this->isPlatformUser($user)) {
            return;
        }

        abort_unless((int) $user->school_id === (int) $invoice->school_id, 403, 'This invoice does not belong to your school.');
    }

    private function isPlatformUser($user): bool
    {
        return $user && $user->isSuperAdminUser();
    }
}
