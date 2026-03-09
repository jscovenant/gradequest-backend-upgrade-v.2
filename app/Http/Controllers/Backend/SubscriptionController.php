<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Str;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Mail;
use App\Mail\PaymentConfirmationMail;
use Illuminate\Support\Facades\DB;
use App\Models\SubscriptionPlan;
use App\Models\SubPayment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SubscriptionController extends Controller
{
  protected $paystackSecretKey;

    public function __construct()
    {
        $this->paystackSecretKey = config('services.paystack.secret');

        if (!$this->paystackSecretKey) {
            Log::error("PAYSTACK_SECRET_KEY is missing. Set it in .env and clear config cache.");
        }
    }

    /**
     * One-time Paystack payment only
     */
  public function initialize(Request $request)
{
    $request->validate([
        'plan_id' => 'required|exists:subscription_plans,id',
        'email' => 'required|email',
    ]);

    $plan = SubscriptionPlan::findOrFail($request->plan_id);
    $user = User::where('email', $request->email)->firstOrFail();

    $reference = 'trx_' . uniqid();
    $amountInKobo = (int) round($plan->price * 100);

    $payload = [
        'email' => $user->email,
        'amount' => $amountInKobo,
        'reference' => $reference,
        'callback_url' => rtrim(config('app.frontend_url'), '/') . '/checkout',
        'metadata' => [
            'plan_id' => $plan->id,
            'user_id' => $user->id,
            'payment_source' => 'paystack',
            'auto_renew' => 0,
        ],
    ];

    $response = Http::withHeaders([
        'Authorization' => 'Bearer ' . $this->paystackSecretKey,
        'Accept' => 'application/json',
    ])->post('https://api.paystack.co/transaction/initialize', $payload);

    if (!$response->ok() || !$response->json('status')) {
        Log::error('Paystack initialize failed', [
            'response' => $response->json(),
        ]);

        return response()->json([
            'message' => 'Unable to initialize payment.',
            'error' => $response->json(),
        ], 400);
    }

    SubPayment::create([
        'user_id' => $user->id,
        'subscription_plan_id' => $plan->id,
        'reference' => $reference,
        'amount' => $plan->price,
        'status' => 'pending',
    ]);

    return response()->json($response->json('data'));
}

    /**
     * Verify one-time payment and activate subscription locally
     */
    public function verify($reference)
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->paystackSecretKey,
            'Accept' => 'application/json',
        ])->get("https://api.paystack.co/transaction/verify/{$reference}");

        if (!$response->ok() || !$response->json('status')) {
            Log::error('Paystack verification failed', [
                'reference' => $reference,
                'response' => $response->json(),
            ]);

            return response()->json([
                'message' => 'Payment verification failed.',
                'error' => $response->json(),
            ], 400);
        }

        $data = $response->json('data');
        Log::info('Paystack payment verified', ['data' => $data]);

        if (($data['status'] ?? null) !== 'success') {
            return response()->json(['message' => 'Payment was not successful.'], 400);
        }

        $payment = SubPayment::where('reference', $reference)->first();

        if (!$payment) {
            return response()->json(['message' => 'Payment record not found.'], 404);
        }

        $plan = SubscriptionPlan::find($payment->subscription_plan_id);
        if (!$plan) {
            return response()->json(['message' => 'Subscription plan not found.'], 404);
        }

        $userId = $data['metadata']['user_id'] ?? $payment->user_id ?? Auth::id();
        $user = User::find($userId);

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        DB::transaction(function () use ($payment, $data, $plan, $user) {
            $payment->update([
                'status' => 'successful',
                'paystack_id' => $data['id'] ?? null,
                'channel' => $data['channel'] ?? null,
                'card_type' => $data['authorization']['card_type'] ?? null,
                'last4' => $data['authorization']['last4'] ?? null,
                'starts_at' => now(),
            ]);

            $existingSubscription = Subscription::where('user_id', $user->id)->lockForUpdate()->first();

            $baseStart = now();

            // If current subscription is still active, extend from current end date
            if ($existingSubscription && $existingSubscription->ends_at && Carbon::parse($existingSubscription->ends_at)->isFuture()) {
                $baseStart = Carbon::parse($existingSubscription->ends_at);
            }

            $newEnd = Carbon::parse($baseStart)->addDays((int) $plan->duration_in_days);

            Subscription::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'subscription_plan_id' => $plan->id,
                    'status' => 'active',

                    // No Paystack recurring fields
                    'authorization_code' => null,
                    'customer_code' => null,
                    'subscription_code' => null,
                    'email_token' => null,

                    // Paystack should never auto-renew now
                    'auto_renew' => 0,
                    'auto_renew_source' => 'paystack',

                    'starts_at' => now(),
                    'ends_at' => $newEnd,
                    'notified_about_expiry' => 0,
                    'reminder_stage' => 0,
                    'last_reminded_at' => null,
                ]
            );
        });

        return response()->json([
            'message' => 'Payment verified successfully',
        ]);
    }




    /**
     * 🔹 calcel button
     */


    public function cancelSubscription(Request $request)
{
    $user = $request->user();

    $subscription = Subscription::where('user_id', $user->id)
        ->where('status', 'active')
        ->first();

    if (!$subscription) {
        return response()->json(['message' => 'No active subscription found.'], 404);
    }

    $subscription->update([
        'status' => 'cancelled',
        'auto_renew' => false,
        'authorization_code' => null,
        'customer_code' => null,
        'subscription_code' => null,
        'email_token' => null,
    ]);

    try {
        Mail::raw("Your subscription has been successfully cancelled.", function ($m) use ($user) {
            $m->to($user->email)->subject('Subscription Cancelled');
        });
    } catch (\Throwable $e) {
        Log::warning("Mail sending failed for subscription cancel: " . $e->getMessage());
    }

    Log::info("Subscription cancelled for user {$user->id}");

    return response()->json([
        'message' => 'Subscription cancelled successfully.',
        'status' => 'cancelled',
    ]);
}


   /**
     * ✅ WALLET CHARGE FOR SUBSCRIPTION
     */
   public function walletCharge(Request $request)
    {
    $user = Auth::user();

    if (!$user) {
        Log::error('Wallet charge failed: no authenticated user.');
        return response()->json(['success' => false, 'message' => 'User not authenticated'], 401);
    }

    $request->validate([
        'subscription_plan_id' => 'required|exists:subscription_plans,id',
        'auto_renew' => 'boolean',
    ]);

    try {
        DB::beginTransaction();

        $plan = SubscriptionPlan::findOrFail($request->subscription_plan_id);
        $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();

        if (!$wallet || $wallet->balance < $plan->price) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient wallet balance.',
            ], 400);
        }

        // ✅ Deduct funds
        $wallet->balance -= $plan->price;
        $wallet->save();

        // ✅ Generate unique reference
        $reference = 'WALLET-' . strtoupper(Str::random(10));

        // ✅ Create payment record (same table used by Paystack)
        $payment = SubPayment::create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'reference' => $reference,
            'amount' => $plan->price,
            'status' => 'successful',
            'channel' => 'wallet',
            'starts_at' => now(),
        ]);

        // ✅ Create or update subscription (imitate verify() flow)
       $subscription = Subscription::updateOrCreate(
    ['user_id' => $user->id],
    [
        'subscription_plan_id' => $plan->id,
        'status' => 'active',
        'auto_renew' => $request->auto_renew ?? false,
        'auto_renew_source' => 'wallet', 
        'customer_code' => null,
        'subscription_code' => null,
        'starts_at' => now(),
        'ends_at' => now()->addDays((int) $plan->duration_in_days),
    ]
);

        // ✅ Link payment to subscription
        $payment->update(['subscription_plan_id' => $subscription->id]);

        // ✅ Record in wallet transaction table (for audit)
        WalletTransaction::create([
            'user_id' => $user->id,
            'school_id' => $user->school_id,
            'type' => 'debit',
            'amount' => $plan->price,
            'description' => 'Subscribed to ' . $plan->name . ' plan via wallet',
            'reference_id' => $reference,
        ]);

        // ✅ Commit and send email
        DB::commit();
        Mail::to($user->email)->send(
                    new PaymentConfirmationMail($user, $payment, $subscription)
                );

        return response()->json([
            'success' => true,
            'message' => 'Subscription successful via wallet.',
            'reference' => $reference,
            'subscription' => $subscription,
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Wallet charge error: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Something went wrong while processing your subscription.',
            'error' => $e->getMessage(),
        ], 500);
    }
}


    /**
     * 🔹 Fetch user's current subscription
     */
    public function getUserSubscription(Request $request)
    {
        $subscription = Subscription::where('user_id', $request->user()->id)->first();

        if (!$subscription) {
            return response()->json(['message' => 'No active subscription found.'], 404);
        }

        return response()->json([
            'id' => $subscription->id,
            'plan' => $subscription->plan->name ?? null,
            'status' => $subscription->status,
            'auto_renew' => (bool) $subscription->auto_renew,
            'ends_at' => $subscription->ends_at,
        ]);
    }

    /**
     * 🔹 Detailed subscription info
     */
    public function getUserSubscriptionDetails(Request $request)
    {
        $subscription = Subscription::with('plan')
            ->where('user_id', $request->user()->id)
            ->latest('created_at')
            ->first();

        if (!$subscription) {
            return response()->json(['message' => 'No active subscription found.'], 404);
        }

        return response()->json([
            'subscription_type' => $subscription->plan->name ?? 'Unknown Plan',
            'amount' => $subscription->plan->price ?? 0,
            'status' => ucfirst($subscription->status),
            'auto_renew' => (bool) $subscription->auto_renew,
            'start_date' => $subscription->starts_at,
            'end_date' => $subscription->ends_at,
            'duration' => $subscription->plan->duration_in_days ?? null,
         'auto_renew_source' => $subscription->auto_renew_source ?? 'wallet',
        ]);
    }
    
    
public function updateRenewalSource(Request $request)
{
    $request->validate([
        'source' => 'required|in:wallet,paystack',
    ]);

    $user = $request->user();
    $subscription = Subscription::where('user_id', $user->id)->first();

    if (!$subscription) {
        return response()->json(['message' => 'No active subscription found'], 404);
    }

    $source = $request->source;

    $subscription->update([
        'auto_renew_source' => $source,
        // Paystack should never auto-renew
        'auto_renew' => $source === 'paystack' ? 0 : (bool) $subscription->auto_renew,

        // Clear old Paystack recurring fields completely
        'authorization_code' => null,
        'customer_code' => null,
        'subscription_code' => null,
        'email_token' => null,
    ]);

    return response()->json([
        'message' => 'Auto-renewal source updated successfully.',
        'source' => $source,
    ]);
}





    public function profile()
    {
        return response()->json(User::with('schoolsetting')->find(Auth::id()));
    }



    // Add at bottom of SubscriptionController

public function billingHistory(Request $request)
{
    $user = $request->user();

    // Payments history
    $payments = SubPayment::with('plan')
        ->where('user_id', $user->id)
        ->orderByDesc('created_at')
        ->get()
        ->map(function ($p) {
            return [
                'id' => $p->id,
                'reference' => $p->reference,
                'amount' => (float) $p->amount,
                'status' => $p->status,
                'channel' => $p->channel,
                'card_type' => $p->card_type,
                'last4' => $p->last4,
                'starts_at' => $p->starts_at,
                'created_at' => $p->created_at,
                'plan' => [
                    'id' => $p->plan->id ?? null,
                    'name' => $p->plan->name ?? 'Unknown',
                    'price' => $p->plan->price ?? null,
                    'duration_in_days' => $p->plan->duration_in_days ?? null,
                ],
            ];
        });

    // Current subscription details
    $subscription = Subscription::with('plan')
        ->where('user_id', $user->id)
        ->latest('created_at')
        ->first();

    $subPayload = null;
    if ($subscription) {
        $subPayload = [
            'id' => $subscription->id,
            'status' => $subscription->status,
            'auto_renew' => (bool) $subscription->auto_renew,
            'auto_renew_source' => $subscription->auto_renew_source ?? 'wallet',
            'starts_at' => $subscription->starts_at,
            'ends_at' => $subscription->ends_at,
            'plan' => [
                'id' => $subscription->plan->id ?? null,
                'name' => $subscription->plan->name ?? 'Unknown',
                'price' => $subscription->plan->price ?? 0,
                'duration_in_days' => $subscription->plan->duration_in_days ?? null,
            ],
        ];
    }

    return response()->json([
        'subscription' => $subPayload,
        'payments' => $payments,
    ]);
}

}
