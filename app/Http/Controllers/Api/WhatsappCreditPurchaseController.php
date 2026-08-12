<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GradequestBillingPolicy;
use App\Models\SchoolSetting;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WhatsappCreditPurchase;
use App\Services\SubscriptionWhatsappCreditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WhatsappCreditPurchaseController extends Controller
{
    public function __construct(private SubscriptionWhatsappCreditService $credits)
    {
    }

    public function quote(Request $request)
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

    public function buyWithWallet(Request $request)
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
            $reference = 'WA-WALLET-' . strtoupper(Str::random(12));
            $purchase = WhatsappCreditPurchase::create([
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
                'description' => "Purchased {$quantity} WhatsApp credits",
                'reference_id' => $reference,
            ]);

            $usage = $this->grant($user->school_id, $quantity, 'whatsapp-purchase:' . $reference);
            $purchase->update(['subscription_whatsapp_usage_id' => $usage->id]);

            return $purchase->fresh();
        });

        return response()->json([
            'message' => "{$quantity} WhatsApp credits purchased successfully.",
            'purchase' => $purchase,
            'credits' => $this->credits->getCreditSummary($user->school_id),
        ]);
    }

    public function initializeOnline(Request $request)
    {
        $this->assertSchoolAdmin($request);
        $data = $request->validate(['quantity' => 'required|integer|min:1|max:1000000']);
        $user = $request->user();
        $quantity = (int) $data['quantity'];
        $unitPrice = $this->unitPrice();
        $amount = round($quantity * $unitPrice, 2);
        abort_if($amount < 100, 422, 'Paystack requires a minimum payment of N100. Increase the credit quantity or use your wallet.');

        $reference = 'WA-ONLINE-' . strtoupper(Str::random(14));
        $purchase = WhatsappCreditPurchase::create([
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
                'callback_url' => rtrim(config('app.frontend_url'), '/') . '/settings/whatsapp',
                'metadata' => ['purpose' => 'whatsapp_credit_purchase', 'purchase_id' => $purchase->id],
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

    public function verifyOnline(Request $request, string $reference)
    {
        $this->assertSchoolAdmin($request);
        $user = $request->user();
        $purchase = WhatsappCreditPurchase::where('reference', $reference)
            ->where('user_id', $user->id)->firstOrFail();

        if ($purchase->status === 'successful') {
            return response()->json(['message' => 'Payment already verified.', 'credits' => $this->credits->getCreditSummary($user->school_id)]);
        }

        $response = Http::withToken(config('services.paystack.secret'))->acceptJson()
            ->get("https://api.paystack.co/transaction/verify/{$reference}");
        $data = $response->json('data', []);
        $amountPaid = ((int) ($data['amount'] ?? 0)) / 100;

        abort_unless($response->ok() && $response->json('status') && ($data['status'] ?? null) === 'success', 422, 'Payment has not been completed.');
        abort_unless(abs($amountPaid - (float) $purchase->amount) < 0.01, 422, 'Paid amount does not match the credit purchase.');

        DB::transaction(function () use ($purchase, $data) {
            $locked = WhatsappCreditPurchase::lockForUpdate()->findOrFail($purchase->id);
            if ($locked->status === 'successful') return;

            $usage = $this->grant($locked->school_id, $locked->quantity, 'whatsapp-purchase:' . $locked->reference);
            $locked->update([
                'status' => 'successful',
                'paid_at' => now(),
                'subscription_whatsapp_usage_id' => $usage->id,
                'metadata' => $data,
            ]);
        });

        return response()->json([
            'message' => "{$purchase->quantity} WhatsApp credits purchased successfully.",
            'credits' => $this->credits->getCreditSummary($user->school_id),
        ]);
    }

    private function unitPrice(): float
    {
        return (float) (GradequestBillingPolicy::query()->value('whatsapp_credit_unit_price') ?? 10);
    }

    private function assertSchoolAdmin(Request $request): void
    {
        abort_unless(strtolower((string) $request->user()?->role) === 'admin', 403, 'Only the school administrator can purchase WhatsApp credits.');
    }

    private function grant(int $schoolId, int $quantity, string $reference)
    {
        return $this->credits->addPurchasedCredits($schoolId, $quantity, $reference);
    }
}
