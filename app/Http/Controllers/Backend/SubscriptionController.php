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
        $this->paystackSecretKey = config('app.paystack_secret_key');
    }

    /**
     * 🔹 Initialize a Paystack transaction
     */
    public function initialize(Request $request)
    {
        $request->validate([
            'plan_id' => 'required|exists:subscription_plans,id',
            'email' => 'required|email',
            // 'auto_renew' => 'required|boolean',
            
        ]);

        $plan = SubscriptionPlan::findOrFail($request->plan_id);
        $user = User::where('email', $request->email)->firstOrFail();

        if ($request->auto_renew && !$plan->paystack_plan_code) {
            return response()->json(['message' => 'This plan does not support auto-renewal.'], 400);
        }

        $reference = 'trx_' . uniqid();
        $amountInKobo = $plan->price * 100;

        $payload = [
            'email' => $user->email,
            'amount' => $amountInKobo,
            'reference' => $reference,
            // 'callback_url' => config('app.frontend_url') . '/dashboard',
            'metadata' => [
                'plan_id' => $plan->id,
                'user_id' => $user->id,
                'auto_renew' => $request->auto_renew,
            ],
        ];


     

        $response = Http::withToken($this->paystackSecretKey)
            ->post('https://api.paystack.co/transaction/initialize', $payload);

        if ($response->failed() || !$response->json('status')) {
            Log::error('❌ Paystack Initialization Failed', $response->json());
            return response()->json(['message' => 'Could not initialize payment.'], 500);
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
     * 🔹 Verify transaction and create Paystack Subscription
     */


public function verify($reference)
{
    $user = Auth::user();

    $response = Http::withToken($this->paystackSecretKey)
        ->get("https://api.paystack.co/transaction/verify/{$reference}");

    if ($response->failed() || !$response->json('status')) {
        Log::error('❌ Paystack Verification Failed', $response->json());
        return response()->json(['message' => 'Payment verification failed'], 500);
    }

    $data = $response->json('data');
    Log::info('✅ Paystack Payment Verified', [$data]);

    $payment = SubPayment::where('reference', $reference)->first();
    $plan = SubscriptionPlan::find($payment->subscription_plan_id);
    $planCode = $plan->paystack_plan_code ?? null;

    $authorizationCode = $data['authorization']['authorization_code'] ?? null;
    $customerCode = $data['customer']['customer_code'] ?? null;

    if ($data['status'] === 'success' && $planCode && $authorizationCode && $customerCode) {

        // ✅ Step 1: Check for existing subscription
        $existingSub = Subscription::where('user_id', $user->id)
            ->whereNotNull('subscription_code')
            ->where('status', 'active')
            ->first();

        if ($existingSub && $existingSub->subscription_code && $existingSub->email_token) {
            // ✅ Step 2: Disable the existing subscription on Paystack
            $disableResponse = Http::withToken($this->paystackSecretKey)
                ->post('https://api.paystack.co/subscription/disable', [
                    'code' => $existingSub->subscription_code,
                    'token' => $existingSub->email_token,
                ]);

            if ($disableResponse->ok() && $disableResponse->json('status')) {
                $existingSub->update(['status' => 'canceled']);
                Log::info('🛑 Old subscription disabled on Paystack', [
                    'subscription_code' => $existingSub->subscription_code,
                ]);
            } else {
                Log::warning('⚠️ Failed to disable old subscription', [
                    'response' => $disableResponse->json(),
                    'existing' => $existingSub->subscription_code,
                ]);
            }
        }

        // ✅ Step 3: Create a new Paystack subscription
        $subscriptionResponse = Http::withToken($this->paystackSecretKey)
            ->post('https://api.paystack.co/subscription', [
                'customer' => (string) $customerCode,
                'plan' => (string) $planCode,
                'authorization' => (string) $authorizationCode,
            ]);

        if ($subscriptionResponse->ok() && $subscriptionResponse->json('status')) {
            $subData = $subscriptionResponse->json('data');

            Subscription::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'status' => 'active',
                    'subscription_plan_id' => $plan->id,
                    'authorization_code' => $authorizationCode,
                    'customer_code' => $customerCode,
                   'subscription_code' => $subData['subscription_code'] ?? null,
                   'auto_renew' => 1,
                  'auto_renew_source' => "card",
                    'email_token' => $subData['email_token'] ?? null,
                    'starts_at' => now(),
                    'ends_at' => now()->addDays((int) $plan->duration_in_days),
                ]
            );

            Log::info('✅ New subscription created successfully', $subData);
        } else {
            Log::warning('⚠️ Failed to create new subscription', [
                'response' => $subscriptionResponse->json(),
            ]);
        }
    } else {
        Log::warning('⚠️ Missing required fields', [
            'plan_code' => $planCode,
            'authorization_code' => $authorizationCode,
            'customer_code' => $customerCode,
        ]);
    }

    // ✅ Step 4: Update the user's payment record
    $payment->update([
        'status' => 'successful',
        'paystack_id' => $data['id'],
        'channel' => $data['channel'],
        'card_type' => $data['authorization']['card_type'] ?? null,
        'last4' => $data['authorization']['last4'] ?? null,
        'starts_at' => now(),
    ]);

    return response()->json(['message' => 'Payment verified successfully']);
}


public function handleWebhook(Request $request)
{
    $input = $request->getContent(); 
    $paystackSignature = $request->header('x-paystack-signature');
    $computedSignature = hash_hmac('sha512', $input, $this->paystackSecretKey);

    if (!hash_equals($computedSignature, $paystackSignature)) {
        Log::warning('Invalid Paystack Webhook Signature', [
            'received_signature' => $paystackSignature,
            'computed_signature' => $computedSignature,
        ]);
        return response()->json(['message' => 'Invalid signature'], 401);
    }

    $payload = json_decode($input, true);
    Log::info('Paystack Webhook Received:', ['payload' => $payload]);

    $event = $payload['event'] ?? null;
    $data = $payload['data'] ?? [];

    try {
        switch ($event) {
           
            case 'invoice.create':
                $this->handleInvoiceCreate($data);
                break;

            case 'invoice.payment_failed':
                $this->handleInvoiceFailed($data);
                break;

            default:
                Log::info("Unhandled Paystack event: {$event}");
                break;
        }
    } catch (\Exception $e) {
        Log::error("Error handling webhook event [{$event}]: " . $e->getMessage());
    }

    return response()->json(['message' => 'Webhook handled'], 200);
}

private function handleInvoiceFailed($data)
{
    $subscriptionCode = $data['subscription']['subscription_code'] ?? null;
    $email = $data['customer']['email'] ?? null;

    if (!$subscriptionCode || !$email) {
        Log::warning('Invoice failed missing subscription_code or email', $data);
        return;
    }

    $subscription = Subscription::where('subscription_code', $subscriptionCode)->first();

    if (!$subscription) {
        Log::warning("⚠️ Subscription not found for code: {$subscriptionCode}");
        return;
    }

    $user = User::where('email', $email)->first();
    if (!$user) {
        Log::warning("⚠️ User not found for failed invoice: {$email}");
        return;
    }

    // 🔥 Step 1: Cancel subscription on Paystack
    if ($subscription->subscription_code && $subscription->email_token) {
        $response = Http::withToken($this->paystackSecretKey)
            ->post('https://api.paystack.co/subscription/disable', [
                'code' => $subscription->subscription_code,
                'token' => $subscription->email_token,
            ]);

        if ($response->failed() || !$response->json('status')) {
            Log::error('❌ Paystack cancel failed on invoice failure', [
                'response' => $response->json(),
                'subscription_id' => $subscription->id,
            ]);
        }
    }

    // 🔁 Step 2: Update subscription status in DB
    $subscription->update([
        'status' => 'cancelled',
        'auto_renew' => false,
    ]);
$frontendUrl = config('app.frontend_url') . '/subscriptions';
    // 🔔 Step 3: Send notification to user
    try {
        $message = "Your recent subscription payment failed. Your subscription has been cancelled. Please update your payment method to continue using our services.";
        $user->notify(new \App\Notifications\SystemNotification(
            message: $message,
            type: 'error',
            actionUrl: $frontendUrl
            
        ));

        Log::info("📩 Notification sent to user {$user->email} for failed invoice");
    } catch (\Throwable $e) {
        Log::warning("⚠️ Failed to send notification: " . $e->getMessage());
    }

    // 🔚 Step 4: Log the event
    Log::warning("⚠️ Invoice payment failed and subscription cancelled for {$email}");
}




private function handleInvoiceCreate($data)
{
    $subscriptionCode = $data['subscription']['subscription_code'] ?? null;
    $email = $data['customer']['email'] ?? null;
    $amount = isset($data['amount']) ? ($data['amount'] / 100) : 0;
    $reference = $data['transaction']['reference'] ?? Str::random(12);
    $channel = $data['authorization']['channel'] ?? 'card';
    $cardType = $data['authorization']['card_type'] ?? null;
    $last4 = $data['authorization']['last4'] ?? null;

    if (!$subscriptionCode || !$email) {
        Log::warning('Invoice event missing subscription or email', $data);
        return;
    }

    $user = User::where('email', $email)->first();
    $subscription = Subscription::where('subscription_code', $subscriptionCode)->first();

    if (!$user || !$subscription) {
        Log::warning('Invoice event: user or subscription not found', compact('subscriptionCode', 'email'));
        return;
    }

    $plan = SubscriptionPlan::find($subscription->subscription_plan_id);
    $duration = (int) ($plan->duration_in_days ?? 90);

    // ✅ Renew or activate subscription
    $subscription->update([
        'status' => 'active',
        'starts_at' => now(),
        'ends_at' => now()->addDays($duration),
    ]);

    // ✅ Log payment in sub_payments table
    if (!SubPayment::where('reference', $reference)->exists()) {
        SubPayment::create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan ? $plan->id : null,
            'reference' => $reference,
            'amount' => $amount,
            'status' => 'successful',
            'channel' => $channel,
            'card_type' => $cardType,
            'last4' => $last4,
            'starts_at' => now(),
        ]);
    }

    // ✅ Send notification to user
    try {
        $message = "Your subscription renewal of ₦" . number_format($amount, 2) . " for the plan '{$plan->name}' was successful.";
        $frontendUrl = config('app.frontend_url', 'https://app.gradequest.com.ng/dashboard/subscriptions');

        $user->notify(new \App\Notifications\SystemNotification(
            $message,
            'success',
            $frontendUrl
        ));

        Log::info("📩 Renewal notification sent to {$email}");
    } catch (\Throwable $e) {
        Log::error("❌ Failed to send renewal notification to {$email}: " . $e->getMessage());
    }

    Log::info("✅ Invoice successfully processed for {$email}, ref: {$reference}, amount: ₦{$amount}");
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

    // Cancel on Paystack if applicable
    if ($subscription->subscription_code && $subscription->email_token) {
        $response = Http::withToken($this->paystackSecretKey)
            ->post('https://api.paystack.co/subscription/disable', [
                'code' => $subscription->subscription_code,
                'token' => $subscription->email_token,
            ]);

        if ($response->failed() || !$response->json('status')) {
            Log::error('❌ Paystack cancel failed', [
                'response' => $response->json(),
                'subscription_id' => $subscription->id,
            ]);

            return response()->json([
                'message' => 'Failed to cancel subscription on Paystack. Try again later.'
            ], 500);
        }
    }

    // Update subscription in your DB
    $subscription->update([
        'status' => 'cancelled',
        'auto_renew' => false,
    ]);

    // Optionally notify user
    try {
        Mail::raw("Your subscription has been successfully cancelled.", function ($m) use ($user) {
            $m->to($user->email)->subject('Subscription Cancelled');
        });
    } catch (\Throwable $e) {
        Log::warning("Mail sending failed for subscription cancel: " . $e->getMessage());
    }

    Log::info("✅ Subscription cancelled for user {$user->id}");

    return response()->json([
        'message' => 'Subscription cancelled successfully.',
        'status' => 'cancelled'
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
    // Validate input
    $request->validate([
        'source' => 'required|in:wallet,card',
    ]);

    $user = $request->user();
    $subscription = Subscription::where('user_id', $user->id)->first();

    if (!$subscription) {
        return response()->json(['message' => 'No active subscription found'], 404);
    }

    // Check if user is switching to WALLET
    if ($request->source === 'wallet') {
        // Only disable Paystack if:
        // 1️⃣ There's a subscription_code (so Paystack knows it), and
        // 2️⃣ The previous auto_renew_source was 'card' (means Paystack was being used)
        if ($subscription->subscription_code && $subscription->auto_renew_source !== 'wallet') {
            try {
                $response = Http::withToken($this->paystackSecretKey)->post(
                    'https://api.paystack.co/subscription/disable',
                    [
                        'code' => $subscription->subscription_code,
                        'token' => $subscription->email_token,
                    ]
                );

                if ($response->failed() || !$response->json('status')) {
                    Log::error('Failed to disable Paystack subscription', [
                        'response' => $response->json(),
                        'subscription_id' => $subscription->id,
                    ]);

                    return response()->json([
                        'message' => 'Could not disable Paystack subscription at this time.'
                    ], 500);
                }

                Log::info("Paystack subscription disabled for {$user->email}");
            } catch (\Exception $e) {
                Log::error('Error disabling Paystack subscription: ' . $e->getMessage());
                return response()->json(['message' => 'Error disabling Paystack subscription.'], 500);
            }
        } else {
            Log::info("Skipping Paystack disable — already using wallet or no Paystack subscription found.", [
                'subscription_id' => $subscription->id,
            ]);
        }
    }

    // ✅ Update local subscription record
    $subscription->update([
        'auto_renew_source' => $request->source,
    ]);

    return response()->json([
        'message' => 'Auto-renewal source updated successfully.',
        'source' => $request->source,
    ]);
}





    public function profile()
    {
        return response()->json(User::with('schoolsetting')->find(Auth::id()));
    }
}
