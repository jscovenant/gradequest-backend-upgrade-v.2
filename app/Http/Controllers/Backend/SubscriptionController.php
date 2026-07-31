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
use App\Services\SalesCommissionService;
use App\Services\WelcomeWalletCreditService;

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

    
    public function availablePlans()
    {
        $user = Auth::user();
        $activeStudents = $user ? $this->activeStudentCountFor($user) : 0;

        $plans = SubscriptionPlan::where('is_active', 1)
            ->orderBy('price_per_student')
            ->get(['id', 'name', 'price', 'price_per_student', 'currency', 'max_students', 'duration_in_days', 'billing_interval', 'description'])
            ->map(function (SubscriptionPlan $plan) use ($activeStudents) {
                $pricePerStudent = (float) ($plan->price_per_student ?? $plan->price ?? 0);
                $studentLimit = (int) ($plan->max_students ?? 0);

                return [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'price' => $pricePerStudent,
                    'price_per_student' => $pricePerStudent,
                    'currency' => $plan->currency,
                    'max_students' => $studentLimit,
                    'duration_in_days' => (int) $plan->duration_in_days,
                    'billing_interval' => $plan->billing_interval ?? 'term',
                    'description' => $plan->description,
                    'active_students' => $activeStudents,
                    'billable_students' => $activeStudents,
                    'current_amount' => $activeStudents * $pricePerStudent,
                    'limit_exceeded' => $studentLimit > 0 && $activeStudents > $studentLimit,
                ];
            });

        return response()->json($plans);
    }

    /**
     * How many of the plan's billing cycles were requested (e.g. 1 for a single
     * cycle, or however many cycles fit in a year for "pay yearly"). Always at
     * least 1 — never trust the client's total amount, only the cycle count.
     */
    protected function resolveCycles(Request $request): int
    {
        $cycles = (int) $request->input('cycles', 1);
        return max(1, $cycles);
    }

    /**
     * Multi-cycle (yearly) purchases get a 2% discount off the subtotal.
     * Returns [total, discount, subtotal].
     */
    protected function computeTotal(float $price, int $cycles): array
    {
        $subtotal = $price * $cycles;
        $discount = $cycles > 1 ? round($subtotal * 0.10, 2) : 0.0;
        $total = round($subtotal - $discount, 2);

        return [$total, $discount, $subtotal];
    }

    protected function activeStudentCountFor(User $user): int
    {
        return User::query()
            ->where('school_id', $user->school_id)
            ->whereRaw('LOWER(role) = ?', ['student'])
            ->where('status', 1)
            ->count();
    }

    protected function planBaseAmount(?SubscriptionPlan $plan, User $user, bool $enforceLimit = true): float
    {
        if (! $plan) {
            return 0.0;
        }

        $students = $this->activeStudentCountFor($user);
        $limit = (int) ($plan->max_students ?? 0);

        abort_if($enforceLimit && $limit > 0 && $students > $limit, 422, "This plan allows {$limit} active students, but this school currently has {$students} active students.");

        return $students * (float) ($plan->price_per_student ?? $plan->price ?? 0);
    }

    protected function subscriptionQuote(SubscriptionPlan $plan, User $user, int $cycles): array
    {
        $current = Subscription::with('plan')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->latest('created_at')
            ->first();

        $hasActiveUnexpired = $current && $current->ends_at && Carbon::parse($current->ends_at)->isFuture();

        if ($hasActiveUnexpired && (int) $current->subscription_plan_id === (int) $plan->id) {
            abort(422, 'This school already has an active subscription on this package. You can renew this same package after it expires, or upgrade to a higher package now.');
        }

        $action = $hasActiveUnexpired ? 'upgrade' : ($current ? 'renewal' : 'purchase');

        if ($hasActiveUnexpired && $current->plan && ! $this->isHigherPlan($plan, $current->plan)) {
            abort(422, 'This school can only change package before expiry by upgrading to a higher package.');
        }

        [$totalBeforeCredit, $discountAmount, $subtotal] = $this->computeTotal($this->planBaseAmount($plan, $user), $cycles);
        $upgradeCredit = $hasActiveUnexpired ? $this->unusedCreditForSubscription($current, $user) : 0.0;
        $payable = max(0, round($totalBeforeCredit - $upgradeCredit, 2));

        return [
            'action' => $action,
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'upgrade_credit_amount' => $upgradeCredit,
            'total_before_credit' => $totalBeforeCredit,
            'payable_amount' => $payable,
            'current_subscription' => $current,
            'remaining_days' => $hasActiveUnexpired ? max(0, now()->diffInDays(Carbon::parse($current->ends_at), false)) : 0,
        ];
    }

    protected function isHigherPlan(SubscriptionPlan $newPlan, ?SubscriptionPlan $currentPlan): bool
    {
        if (! $currentPlan) {
            return true;
        }

        $newPrice = (float) ($newPlan->price_per_student ?? $newPlan->price ?? 0);
        $currentPrice = (float) ($currentPlan->price_per_student ?? $currentPlan->price ?? 0);

        if ($newPrice > $currentPrice) {
            return true;
        }

        $newLimit = (int) ($newPlan->max_students ?? 0);
        $currentLimit = (int) ($currentPlan->max_students ?? 0);
        $newLimitRank = $newLimit === 0 ? PHP_INT_MAX : $newLimit;
        $currentLimitRank = $currentLimit === 0 ? PHP_INT_MAX : $currentLimit;

        return $newPrice >= $currentPrice && $newLimitRank > $currentLimitRank;
    }

    protected function unusedCreditForSubscription(Subscription $subscription, User $user): float
    {
        if (! $subscription->starts_at || ! $subscription->ends_at || ! $subscription->plan) {
            return 0.0;
        }

        $start = Carbon::parse($subscription->starts_at);
        $end = Carbon::parse($subscription->ends_at);
        $totalDays = max(1, $start->diffInDays($end));
        $remainingDays = max(0, now()->diffInDays($end, false));

        if ($remainingDays <= 0) {
            return 0.0;
        }

        $latestPayment = SubPayment::where('user_id', $user->id)
            ->where('subscription_plan_id', $subscription->subscription_plan_id)
            ->whereIn('status', ['successful', 'success', 'paid'])
            ->latest('created_at')
            ->first();

        $paidAmount = (float) ($latestPayment?->amount ?? 0);

        if ($paidAmount <= 0) {
            $cycles = max(1, (int) ($subscription->billing_cycle_count ?? 1));
            [$paidAmount] = $this->computeTotal($this->planBaseAmount($subscription->plan, $user, false), $cycles);
        }

        return round(($paidAmount / $totalDays) * $remainingDays, 2);
    }

    protected function activateSubscription(User $user, SubscriptionPlan $plan, int $cycles, int $durationDays, string $source, bool $autoRenew = false): Subscription
    {
        return Subscription::updateOrCreate(
            ['user_id' => $user->id],
            [
                'subscription_plan_id' => $plan->id,
                'billing_cycle_count' => $cycles,
                'status' => 'active',
                'auto_renew' => $source === 'wallet' ? $autoRenew : 0,
                'auto_renew_source' => $source,
                'authorization_code' => null,
                'customer_code' => null,
                'subscription_code' => null,
                'email_token' => null,
                'starts_at' => now(),
                'ends_at' => now()->addDays($durationDays),
                'notified_about_expiry' => 0,
                'reminder_stage' => 0,
                'last_reminded_at' => null,
            ]
        );
    }

    /**
     * One-time Paystack payment only
     */
  public function initialize(Request $request)
{
    $request->validate([
        'plan_id' => 'required|exists:subscription_plans,id',
        'email' => 'required|email',
        'cycles' => 'nullable|integer|min:1|max:24',
    ]);

    $plan = SubscriptionPlan::findOrFail($request->plan_id);
    $user = User::where('email', $request->email)->firstOrFail();
    $cycles = $this->resolveCycles($request);

    $quote = $this->subscriptionQuote($plan, $user, $cycles);
    $totalAmount = $quote['payable_amount'];
    $discountAmount = $quote['discount_amount'];
    $subtotal = $quote['subtotal'];
    $totalDurationDays = (int) $plan->duration_in_days * $cycles;

    $reference = 'trx_' . uniqid();

    if ($totalAmount <= 0) {
        $payment = SubPayment::create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'previous_subscription_plan_id' => $quote['current_subscription']?->subscription_plan_id,
            'reference' => $reference,
            'amount' => 0,
            'subtotal_amount' => $subtotal,
            'discount_amount' => $discountAmount,
            'upgrade_credit_amount' => $quote['upgrade_credit_amount'],
            'billing_cycle_count' => $cycles,
            'duration_in_days' => $totalDurationDays,
            'active_students' => $this->activeStudentCountFor($user),
            'price_per_student' => (float) ($plan->price_per_student ?? $plan->price ?? 0),
            'status' => 'successful',
            'subscription_action' => $quote['action'],
            'channel' => 'upgrade_credit',
            'starts_at' => now(),
            'previous_subscription_ends_at' => $quote['current_subscription']?->ends_at,
        ]);

        $subscription = $this->activateSubscription($user, $plan, $cycles, $totalDurationDays, 'paystack');
        app(SalesCommissionService::class)->recordSubscriptionCommission($payment, $subscription);

        return response()->json([
            'message' => 'Subscription upgraded using remaining package credit.',
            'requires_payment' => false,
            'reference' => $payment->reference,
            'subscription' => $subscription,
            'quote' => [
                'action' => $quote['action'],
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'upgrade_credit_amount' => $quote['upgrade_credit_amount'],
                'payable_amount' => $totalAmount,
                'remaining_days' => $quote['remaining_days'],
            ],
        ]);
    }

    $amountInKobo = (int) round($totalAmount * 100);

    $payload = [
        'email' => $user->email,
        'amount' => $amountInKobo,
        'reference' => $reference,
        'callback_url' => rtrim(config('app.frontend_url'), '/') . '/checkout',
        'metadata' => [
            'plan_id' => $plan->id,
            'user_id' => $user->id,
            'cycles' => $cycles,
            'active_students' => $this->activeStudentCountFor($user),
            'price_per_student' => (float) ($plan->price_per_student ?? $plan->price ?? 0),
            'subtotal_amount' => $subtotal,
            'upgrade_credit_amount' => $quote['upgrade_credit_amount'],
            'subscription_action' => $quote['action'],
            'previous_subscription_plan_id' => $quote['current_subscription']?->subscription_plan_id,
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
        'previous_subscription_plan_id' => $quote['current_subscription']?->subscription_plan_id,
        'reference' => $reference,
        'amount' => $totalAmount,
        'subtotal_amount' => $subtotal,
        'discount_amount' => $discountAmount,
        'upgrade_credit_amount' => $quote['upgrade_credit_amount'],
        'billing_cycle_count' => $cycles,
        'duration_in_days' => $totalDurationDays,
        'active_students' => $this->activeStudentCountFor($user),
        'price_per_student' => (float) ($plan->price_per_student ?? $plan->price ?? 0),
        'status' => 'pending',
        'subscription_action' => $quote['action'],
        'previous_subscription_ends_at' => $quote['current_subscription']?->ends_at,
    ]);

    return response()->json(array_merge($response->json('data'), [
        'requires_payment' => true,
        'quote' => [
            'action' => $quote['action'],
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'upgrade_credit_amount' => $quote['upgrade_credit_amount'],
            'payable_amount' => $totalAmount,
            'remaining_days' => $quote['remaining_days'],
        ],
    ]));
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

        // Prefer the duration stored on the payment record (set at initialize time);
        // fall back to recomputing from Paystack metadata, then to a single cycle.
        $totalDurationDays = $payment->duration_in_days
            ?? ((int) $plan->duration_in_days * (int) ($data['metadata']['cycles'] ?? 1));

        $cycles = $payment->billing_cycle_count ?? ($data['metadata']['cycles'] ?? 1);

        DB::transaction(function () use ($payment, $data, $plan, $user, $totalDurationDays, $cycles) {
            $payment->update([
                'status' => 'successful',
                'paystack_id' => $data['id'] ?? null,
                'channel' => $data['channel'] ?? null,
                'card_type' => $data['authorization']['card_type'] ?? null,
                'last4' => $data['authorization']['last4'] ?? null,
                'paid_at' => now(),
                'starts_at' => now(),
            ]);

            $subscription = $this->activateSubscription($user, $plan, (int) $cycles, (int) $totalDurationDays, 'paystack');
            app(SalesCommissionService::class)->recordSubscriptionCommission($payment->fresh(), $subscription);
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
        'cycles' => 'nullable|integer|min:1|max:24',
        'auto_renew' => 'boolean',
    ]);

    try {
        DB::beginTransaction();

        $plan = SubscriptionPlan::findOrFail($request->subscription_plan_id);
        $cycles = $this->resolveCycles($request);
        $quote = $this->subscriptionQuote($plan, $user, $cycles);
        $totalAmount = $quote['payable_amount'];
        $discountAmount = $quote['discount_amount'];
        $subtotal = $quote['subtotal'];
        $totalDurationDays = (int) $plan->duration_in_days * $cycles;

        app(WelcomeWalletCreditService::class)->expireUnusedCredits($user);

        $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();

        if (!$wallet || $wallet->balance < $totalAmount) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Insufficient wallet balance.',
            ], 400);
        }

        // ✅ Deduct funds
        $wallet->balance -= $totalAmount;
        $wallet->save();
        app(WelcomeWalletCreditService::class)->consumeWelcomeCredit($user, (float) $totalAmount);

        // ✅ Generate unique reference
        $reference = 'WALLET-' . strtoupper(Str::random(10));

        // ✅ Create payment record (same table used by Paystack)
        $payment = SubPayment::create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'previous_subscription_plan_id' => $quote['current_subscription']?->subscription_plan_id,
            'reference' => $reference,
            'amount' => $totalAmount,
            'subtotal_amount' => $subtotal,
            'discount_amount' => $discountAmount,
            'upgrade_credit_amount' => $quote['upgrade_credit_amount'],
            'billing_cycle_count' => $cycles,
            'duration_in_days' => $totalDurationDays,
            'active_students' => $this->activeStudentCountFor($user),
            'price_per_student' => (float) ($plan->price_per_student ?? $plan->price ?? 0),
            'status' => 'successful',
            'subscription_action' => $quote['action'],
            'channel' => 'wallet',
            'paid_at' => now(),
            'starts_at' => now(),
            'previous_subscription_ends_at' => $quote['current_subscription']?->ends_at,
        ]);

        // ✅ Create or update subscription (imitate verify() flow)
        $subscription = $this->activateSubscription($user, $plan, $cycles, $totalDurationDays, 'wallet', (bool) ($request->auto_renew ?? false));
        app(SalesCommissionService::class)->recordSubscriptionCommission($payment, $subscription);

        // ✅ Record in wallet transaction table (for audit)
        WalletTransaction::create([
            'user_id' => $user->id,
            'school_id' => $user->school_id,
            'type' => 'debit',
            'amount' => $totalAmount,
            'description' => $quote['action'] === 'upgrade'
                ? "Upgraded to {$plan->name} plan via wallet. Upgrade credit applied: {$quote['upgrade_credit_amount']}."
                : ($cycles > 1
                    ? "Subscribed to {$plan->name} plan ({$cycles} cycles / {$totalDurationDays} days, 10% yearly discount applied) via wallet"
                    : "Subscribed to {$plan->name} plan via wallet"),
            'reference_id' => $reference,
        ]);

        // ✅ Commit and send email
        DB::commit();
        Mail::to($user->email)->send(
                    new PaymentConfirmationMail($user, $payment, $subscription)
                );

        return response()->json([
            'success' => true,
            'message' => $quote['action'] === 'upgrade'
                ? 'Subscription upgraded successfully via wallet.'
                : 'Subscription successful via wallet.',
            'reference' => $reference,
            'subscription' => $subscription,
            'quote' => [
                'action' => $quote['action'],
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'upgrade_credit_amount' => $quote['upgrade_credit_amount'],
                'payable_amount' => $totalAmount,
                'remaining_days' => $quote['remaining_days'],
            ],
        ]);

    } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
        DB::rollBack();
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], $e->getStatusCode());
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
            return response()->json([
                'id' => null,
                'plan' => null,
                'status' => 'none',
                'billing_cycle_count' => 0,
                'auto_renew' => false,
                'ends_at' => null,
            ]);
        }

        return response()->json([
            'id' => $subscription->id,
            'plan' => $subscription->plan->name ?? null,
            'status' => $subscription->status,
            'billing_cycle_count' => $subscription->billing_cycle_count,
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
            return response()->json([
                'subscription_type' => null,
                'amount' => 0,
                'price_per_student' => 0,
                'active_students' => $this->activeStudentCountFor($request->user()),
                'billing_cycle_count' => 0,
                'status' => 'None',
                'auto_renew' => false,
                'start_date' => null,
                'end_date' => null,
                'duration' => null,
                'auto_renew_source' => null,
            ]);
        }

        $cycles = $subscription->billing_cycle_count ?? 1;

        return response()->json([
            'subscription_type' => $subscription->plan->name ?? 'Unknown Plan',
            'amount' => $this->planBaseAmount($subscription->plan, $request->user(), false) * $cycles,
            'price_per_student' => (float) ($subscription->plan->price_per_student ?? $subscription->plan->price ?? 0),
            'active_students' => $this->activeStudentCountFor($request->user()),
            'billing_cycle_count' => $cycles,
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
        return response()->json([
            'message' => 'No existing subscription yet. Renewal source will be saved after first payment.',
            'source' => $request->source,
            'has_subscription' => false,
        ]);
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
        'has_subscription' => true,
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
                'discount_amount' => (float) ($p->discount_amount ?? 0),
                'billing_cycle_count' => $p->billing_cycle_count,
                'duration_in_days' => $p->duration_in_days,
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
                    'price_per_student' => $p->plan->price_per_student ?? $p->plan->price ?? null,
                    'duration_in_days' => $p->plan->duration_in_days ?? null,
                    'billing_interval' => $p->plan->billing_interval ?? null,
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
            'billing_cycle_count' => $subscription->billing_cycle_count,
            'auto_renew' => (bool) $subscription->auto_renew,
            'auto_renew_source' => $subscription->auto_renew_source ?? 'wallet',
            'starts_at' => $subscription->starts_at,
            'ends_at' => $subscription->ends_at,
            'plan' => [
                'id' => $subscription->plan->id ?? null,
                'name' => $subscription->plan->name ?? 'Unknown',
                'price' => $subscription->plan->price ?? 0,
                'price_per_student' => $subscription->plan->price_per_student ?? $subscription->plan->price ?? 0,
                'active_students' => $this->activeStudentCountFor($user),
                'current_amount' => $this->planBaseAmount($subscription->plan, $user, false),
                'duration_in_days' => $subscription->plan->duration_in_days ?? null,
                'billing_interval' => $subscription->plan->billing_interval ?? null,
            ],
        ];
    }

    return response()->json([
        'subscription' => $subPayload,
        'payments' => $payments,
    ]);
}

}
