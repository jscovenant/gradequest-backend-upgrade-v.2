<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiCreditPurchase;
use App\Models\GradequestBillingPolicy;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\SubscriptionAiCreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AiCreditPurchaseController extends Controller
{
    public function __construct(private SubscriptionAiCreditService $credits)
    {
    }

    public function quote(Request $request): JsonResponse
    {
        $this->assertSchoolAdmin($request);
        $quantity = max(1, (int) $request->query('quantity', 1));
        $unitPrice = $this->unitPrice();

        return response()->json([
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'total_amount' => round($quantity * $unitPrice, 2),
            'currency' => 'NGN',
            'wallet_balance' => (float) (Wallet::where('user_id', $request->user()->id)->value('balance') ?? 0),
        ]);
    }

    public function buyWithWallet(Request $request): JsonResponse
    {
        $this->assertSchoolAdmin($request);
        $data = $request->validate(['quantity' => 'required|integer|min:1|max:1000000']);
        $user = $request->user();
        $quantity = (int) $data['quantity'];
        $unitPrice = $this->unitPrice();
        $amount = round($quantity * $unitPrice, 2);

        $purchase = DB::transaction(function () use ($user, $quantity, $unitPrice, $amount) {
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();
            abort_if(! $wallet || (float) $wallet->balance < $amount, 422, 'Insufficient wallet balance.');

            $wallet->decrement('balance', $amount);
            $reference = 'AI-WALLET-' . strtoupper(Str::random(12));

            $purchase = AiCreditPurchase::create([
                'school_id' => $user->school_id,
                'user_id' => $user->id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'amount' => $amount,
                'payment_method' => 'wallet',
                'status' => 'successful',
                'reference' => $reference,
                'paid_at' => now(),
            ]);

            WalletTransaction::create([
                'user_id' => $user->id,
                'school_id' => $user->school_id,
                'type' => 'debit',
                'amount' => $amount,
                'description' => "Purchased {$quantity} AI credits",
                'reference_id' => $reference,
            ]);

            $usage = $this->credits->addPurchasedCredits((int) $user->school_id, $quantity, 'ai-purchase:' . $reference);
            $purchase->update(['subscription_ai_usage_id' => $usage->id]);

            return $purchase->fresh();
        });

        return response()->json([
            'message' => "{$quantity} AI credits purchased successfully.",
            'purchase' => $purchase,
            'credits' => $this->credits->getCreditSummary((int) $user->school_id),
        ]);
    }

    public function initializeOnline(Request $request): JsonResponse
    {
        $this->assertSchoolAdmin($request);
        $data = $request->validate(['quantity' => 'required|integer|min:1|max:1000000']);
        $user = $request->user();
        $quantity = (int) $data['quantity'];
        $unitPrice = $this->unitPrice();
        $amount = round($quantity * $unitPrice, 2);

        abort_if($amount < 100, 422, 'Paystack requires a minimum payment of N100. Increase the credit quantity or use your wallet.');

        $reference = 'AI-ONLINE-' . strtoupper(Str::random(14));
        $purchase = AiCreditPurchase::create([
            'school_id' => $user->school_id,
            'user_id' => $user->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'amount' => $amount,
            'payment_method' => 'paystack',
            'status' => 'pending',
            'reference' => $reference,
        ]);

        $response = Http::withToken(config('services.paystack.secret'))->acceptJson()
            ->post('https://api.paystack.co/transaction/initialize', [
                'email' => $user->email,
                'amount' => (int) round($amount * 100),
                'reference' => $reference,
                'callback_url' => $this->callbackUrl($request, $reference),
                'metadata' => ['purpose' => 'ai_credit_purchase', 'purchase_id' => $purchase->id],
            ]);

        if ($response->failed() || ! $response->json('status')) {
            $purchase->update(['status' => 'failed', 'metadata' => $response->json()]);
            return response()->json(['message' => 'Unable to initialize Paystack payment.'], 502);
        }

        return response()->json([
            'authorization_url' => $response->json('data.authorization_url'),
            'reference' => $reference,
            'purchase' => $purchase,
        ]);
    }

    public function verifyOnline(Request $request, string $reference): JsonResponse
    {
        $this->assertSchoolAdmin($request);
        $user = $request->user();
        $purchase = AiCreditPurchase::where('reference', $reference)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->verifyAndGrant($purchase);

        return response()->json([
            'message' => "{$purchase->quantity} AI credits purchased successfully.",
            'credits' => $this->credits->getCreditSummary((int) $user->school_id),
        ]);
    }

    public function handleVerifiedWebhook(string $reference): void
    {
        $purchase = AiCreditPurchase::where('reference', $reference)->firstOrFail();
        $this->verifyAndGrant($purchase);
    }

    private function verifyAndGrant(AiCreditPurchase $purchase): void
    {
        if ($purchase->status === 'successful') {
            return;
        }

        $response = Http::withToken(config('services.paystack.secret'))->acceptJson()
            ->get('https://api.paystack.co/transaction/verify/' . urlencode($purchase->reference));
        $data = $response->json('data', []);
        $amountPaid = ((int) ($data['amount'] ?? 0)) / 100;

        abort_unless($response->ok() && $response->json('status') && ($data['status'] ?? null) === 'success', 422, 'Payment has not been completed.');
        abort_unless(abs($amountPaid - (float) $purchase->amount) < 0.01, 422, 'Paid amount does not match the AI credit purchase.');

        DB::transaction(function () use ($purchase, $data) {
            $locked = AiCreditPurchase::lockForUpdate()->findOrFail($purchase->id);
            if ($locked->status === 'successful') {
                return;
            }

            $usage = $this->credits->addPurchasedCredits((int) $locked->school_id, (int) $locked->quantity, 'ai-purchase:' . $locked->reference);
            $locked->update([
                'status' => 'successful',
                'paid_at' => now(),
                'subscription_ai_usage_id' => $usage->id,
                'metadata' => $data,
            ]);
        });
    }

    private function callbackUrl(Request $request, string $reference): string
    {
        $origin = trim((string) $request->headers->get('origin'));
        $referer = trim((string) $request->headers->get('referer'));

        $baseUrl = $origin !== ''
            ? $origin
            : ($referer !== '' ? preg_replace('#/[^/]*$#', '', $referer) : '');

        if ($baseUrl === '') {
            $baseUrl = (string) (config('app.frontend_url') ?: config('app.url'));
        }

        return rtrim($baseUrl, '/') . '/settings/ai-credits?reference=' . urlencode($reference);
    }
    private function unitPrice(): float
    {
        return (float) (GradequestBillingPolicy::query()->value('ai_credit_unit_price') ?? 25);
    }

    private function assertSchoolAdmin(Request $request): void
    {
        abort_unless(strtolower((string) $request->user()?->role) === 'admin', 403, 'Only the school administrator can purchase AI credits.');
    }
}

