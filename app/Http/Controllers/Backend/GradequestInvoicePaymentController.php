<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\GradequestInvoicePayment;
use App\Models\GradequestTermInvoice;
use App\Models\SchoolSetting;
use App\Services\SchoolBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GradequestInvoicePaymentController extends Controller
{
    public function __construct(private SchoolBillingService $billing)
    {
    }

    public function show(Request $request, GradequestTermInvoice $invoice)
    {
        $this->authorizeInvoice($request, $invoice);

        return response()->json([
            'invoice' => $invoice->fresh(),
            'school' => SchoolSetting::find($invoice->school_id),
            'payments' => GradequestInvoicePayment::where('invoice_id', $invoice->id)
                ->latest()
                ->get(),
        ]);
    }

    public function initialize(Request $request, GradequestTermInvoice $invoice)
    {
        $this->authorizeInvoice($request, $invoice);

        $invoice = $invoice->fresh();

        if ($invoice->billing_mode !== 'offline') {
            return response()->json(['message' => 'Only offline GradeQuest invoices can be paid here.'], 422);
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

        $response = Http::withToken(config('services.paystack.secret'))
            ->post('https://api.paystack.co/transaction/initialize', [
                'reference' => $reference,
                'email' => $user->email,
                'amount' => (int) round($amount * 100),
                'callback_url' => rtrim((string) config('app.frontend_url'), '/') . '/billing/invoice-payment/' . $invoice->id . '?reference=' . $reference,
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

        GradequestInvoicePayment::create([
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
        $payment = GradequestInvoicePayment::where('reference', $reference)->firstOrFail();
        $invoice = GradequestTermInvoice::findOrFail($payment->invoice_id);
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
            $locked = GradequestInvoicePayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

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

    private function authorizeInvoice(Request $request, GradequestTermInvoice $invoice): void
    {
        $user = $request->user();

        if ($this->isPlatformUser($user)) {
            return;
        }

        abort_unless((int) $user->school_id === (int) $invoice->school_id, 403, 'This invoice does not belong to your school.');
    }

    private function isPlatformUser($user): bool
    {
        $role = strtolower(str_replace(['-', '_', ' '], '', (string) $user->role));

        return in_array($role, ['superadmin', 'platformadmin', 'financeadmin', 'owner'], true);
    }
}
