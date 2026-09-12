<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use App\Mail\WalletTopupMail;
use App\Notifications\SystemNotification;
use App\Services\WelcomeWalletCreditService;

class WalletController extends Controller
{
    protected string $paystackSecretKey;

    public function __construct()
    {
        $this->paystackSecretKey = (string) config('services.paystack.secret');

        if (!$this->paystackSecretKey) {
            Log::error("❌ PAYSTACK_SECRET_KEY is missing. Check config/services.php and .env, then run: php artisan config:clear");
        }
    }

    public function initialize(Request $request)
    {
        $user = $request->user();

        // Support direct monetary amount in Naira, or fallback to quantity for backward compatibility
        $amount = $request->input('amount');
        $quantity = $request->input('quantity');

        if ($amount !== null && $amount !== '') {
            $amountInNaira = (float) $amount;
        } elseif ($quantity !== null && $quantity !== '') {
            $pricePerUnit = 100; // Naira per unit
            $amountInNaira = (float) ($quantity * $pricePerUnit);
        } else {
            return response()->json([
                'error' => 'Please provide a valid top-up amount in Naira.'
            ], 422);
        }

        if ($amountInNaira < 100) {
            return response()->json([
                'error' => 'Minimum wallet top-up amount is ₦100.'
            ], 422);
        }

        $amountInKobo = (int) round($amountInNaira * 100);
        $reference = (string) Str::uuid();

        $origin = $request->input('callback_url')
            ?: $request->header('origin')
            ?: ($request->header('referer') ? rtrim(parse_url($request->header('referer'), PHP_URL_SCHEME) . '://' . parse_url($request->header('referer'), PHP_URL_HOST), '/') : null)
            ?: rtrim((string) (config('app.frontend_url') ?: 'https://gradequest.com.ng'), '/');

        $callbackUrl = str_ends_with($origin, '/wallet') ? $origin : rtrim($origin, '/') . '/wallet';

        $payload = [
            'email' => $user->email,
            'amount' => $amountInKobo,
            'reference' => $reference,
            'callback_url' => $callbackUrl,
            'metadata' => [
                'user_id' => $user->id,
                'school_id' => $user->school_id,
                'amount_in_naira' => $amountInNaira,
                'quantity' => $quantity ? (int) $quantity : null,
                'purpose' => 'wallet_topup',
            ],
        ];

        $response = Http::withToken($this->paystackSecretKey)
            ->acceptJson()
            ->post('https://api.paystack.co/transaction/initialize', $payload);

        if ($response->failed() || !$response->json('status')) {
            Log::error('❌ Paystack Wallet Initialization Failed', [
                'response' => $response->json(),
                'status_code' => $response->status(),
            ]);

            return response()->json([
                'error' => 'Payment initialization failed. Please try again.'
            ], 500);
        }

        $data = $response->json('data');

        return response()->json([
            'authorization_url' => $data['authorization_url'] ?? null,
            'access_code' => $data['access_code'] ?? null,
            'reference' => $reference,
        ]);
    }

    public function verify(string $reference)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not authenticated.'
            ], 401);
        }

        try {
            $response = Http::withToken($this->paystackSecretKey)
                ->acceptJson()
                ->get("https://api.paystack.co/transaction/verify/{$reference}");

            if ($response->failed() || !$response->json('status')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to verify transaction from Paystack.',
                    'error' => $response->json()
                ], 500);
            }

            $data = $response->json('data');

            if (($data['status'] ?? null) !== 'success') {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'Payment not successful.',
                    'data' => $data
                ], 400);
            }

            $meta = $data['metadata'] ?? [];
            $userId = (int) ($meta['user_id'] ?? $user->id);
            $schoolId = (int) ($meta['school_id'] ?? $user->school_id);
            $quantity = isset($meta['quantity']) && $meta['quantity'] ? (int) $meta['quantity'] : null;

            $amountInKobo = (int) ($data['amount'] ?? 0);
            $amountInNaira = $amountInKobo / 100;

            $paystackRef = $data['reference'] ?? $reference;

            // Prevent double-credit
            if (WalletTransaction::where('reference_id', $paystackRef)->exists()) {
                $wallet = Wallet::where('school_id', $schoolId)->first();
                return response()->json([
                    'status' => 'success',
                    'message' => 'Payment already verified and recorded.',
                    'balance' => $wallet ? $wallet->balance : 0,
                    'data' => $data
                ]);
            }

            DB::beginTransaction();

            $description = $quantity
                ? "Purchased {$quantity} result slot(s) (₦" . number_format($amountInNaira, 2) . ")"
                : "Wallet Top-up of ₦" . number_format($amountInNaira, 2);

            WalletTransaction::create([
                'user_id' => $userId,
                'type' => 'credit',
                'amount' => $amountInNaira,
                'school_id' => $schoolId,
                'description' => $description,
                'reference_id' => $paystackRef,
            ]);

            // wallet keyed by school_id
            $wallet = Wallet::firstOrNew(['school_id' => $schoolId]);
            $wallet->balance = (float) ($wallet->balance ?? 0) + (float) $amountInNaira;
            $wallet->user_id = $userId;
            $wallet->school_id = $schoolId;
            $wallet->save();

            // Activate welcome bonus if pending
            try {
                app(WelcomeWalletCreditService::class)->activateBonusOnDeposit($schoolId, (float) $amountInNaira, (string) $paystackRef);
            } catch (\Throwable $e) {
                Log::warning('Welcome bonus activation on topup error: ' . $e->getMessage());
            }

            // Email receipt object used by your mailable
            $payment = (object) [
                'sub_plan' => 'Wallet Top-up',
                'amount' => $amountInNaira,
                'reference' => $paystackRef,
                'created_at' => now(),
            ];

            try {
                Mail::to($user->email)->send(new WalletTopupMail($user, $payment));
            } catch (\Throwable $e) {
                Log::warning('Wallet top-up email sending failed: ' . $e->getMessage());
            }

            // System notification
            try {
                $user->notify(new SystemNotification(
                    "Your wallet has been credited with ₦" . number_format($amountInNaira, 2) . ". Reference: {$paystackRef}.",
                    url('/wallet'),
                    'success'
                ));
            } catch (\Throwable $e) {
                Log::warning('Wallet top-up notification failed: ' . $e->getMessage());
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Payment verified and wallet credited.',
                'balance' => $wallet->balance,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Paystack verify error: " . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Something went wrong during verification.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function verifyFromWebhook(array $data): bool
    {
        $reference = (string) ($data['reference'] ?? '');
        if ($reference === '') return false;

        if (WalletTransaction::where('reference_id', $reference)->exists()) {
            return true;
        }

        $meta = $data['metadata'] ?? [];
        $userId = (int) ($meta['user_id'] ?? 0);
        $schoolId = (int) ($meta['school_id'] ?? 0);
        $quantity = isset($meta['quantity']) && $meta['quantity'] ? (int) $meta['quantity'] : null;

        $amountInKobo = (int) ($data['amount'] ?? 0);
        $amountInNaira = $amountInKobo / 100;

        if (!$schoolId && $userId) {
            $u = \App\Models\User::find($userId);
            $schoolId = $u ? (int) $u->school_id : 0;
        }

        if (!$schoolId) {
            Log::warning('Paystack wallet webhook could not determine school_id', ['reference' => $reference]);
            return false;
        }

        DB::beginTransaction();
        try {
            $description = $quantity
                ? "Purchased {$quantity} result slot(s) (₦" . number_format($amountInNaira, 2) . ")"
                : "Wallet Top-up of ₦" . number_format($amountInNaira, 2);

            WalletTransaction::create([
                'user_id' => $userId ?: null,
                'type' => 'credit',
                'amount' => $amountInNaira,
                'school_id' => $schoolId,
                'description' => $description,
                'reference_id' => $reference,
            ]);

            $wallet = Wallet::firstOrNew(['school_id' => $schoolId]);
            $wallet->balance = (float) ($wallet->balance ?? 0) + (float) $amountInNaira;
            if ($userId) $wallet->user_id = $userId;
            $wallet->school_id = $schoolId;
            $wallet->save();

            // Activate welcome bonus if pending
            try {
                app(WelcomeWalletCreditService::class)->activateBonusOnDeposit($schoolId, (float) $amountInNaira, (string) $reference);
            } catch (\Throwable $e) {
                Log::warning('Welcome bonus activation on webhook topup error: ' . $e->getMessage());
            }

            DB::commit();
            return true;
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Wallet webhook verification error: ' . $e->getMessage());
            return false;
        }
    }

    public function getStudentProduct()
    {
        $product = Product::where('name', 'Student Slot')->first();

        if (!$product) {
            return response()->json(['error' => 'Product not found'], 404);
        }

        return response()->json([
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            // Your DB seems stored in kobo, so divide by 100
            'price' => $product->price / 100,
        ]);
    }

    public function userTransactions(Request $request)
    {
        $auth = Auth::user();

        $perPage = (int) $request->input('perPage', 10);
        $page = (int) $request->input('page', 1);

        $transactions = WalletTransaction::where('school_id', $auth->school_id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'transactions' => $transactions,
        ]);
    }

    public function getUserBalance()
    {
        $user = Auth::user();
        if ($user) {
            app(WelcomeWalletCreditService::class)->expireUnusedCredits($user);
        }
        $wallet = Wallet::where('school_id', $user->school_id)->first();
        $bonusStatus = app(WelcomeWalletCreditService::class)->getBonusStatus((int) $user->school_id);

        return response()->json([
            'balance' => $wallet ? (float) $wallet->balance : 0,
            'welcome_bonus' => $bonusStatus,
        ]);
    }

    public function singleTransactionSummary($id)
    {
        $user = Auth::user();

        $transaction = WalletTransaction::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$transaction) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaction not found.'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'transaction' => $transaction
        ]);
    }

    public function destroy($id)
    {
        WalletTransaction::where('id', $id)->delete();
        return response()->json(['message' => 'Transaction deleted']);
    }

    public function destroyBulk(Request $request)
    {
        $ids = $request->input('ids', []);
        WalletTransaction::whereIn('id', $ids)->delete();
        return response()->json(['message' => 'Transactions deleted']);
    }
}
