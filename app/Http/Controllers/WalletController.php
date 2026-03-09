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

        $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $pricePerUnit = 100; // Naira per slot
        $amountInKobo = (int) ($request->quantity * $pricePerUnit * 100);

        // ₦5,000 minimum => 500,000 kobo
        if ($amountInKobo < 500000) {
            return response()->json([
                'error' => 'Minimum payment amount is ₦5000. Please increase the number of students.'
            ], 422);
        }

        $reference = (string) Str::uuid();

        $payload = [
            'email' => $user->email,
            'amount' => $amountInKobo,
            'reference' => $reference,
            // If you have a frontend verify page, set it there:
            // 'callback_url' => config('app.frontend_url') . '/wallet/verify',
            'metadata' => [
                'user_id' => $user->id,
                'quantity' => (int) $request->quantity,
                'price_per_unit' => $pricePerUnit,
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
                'error' => 'Payment initialization failed'
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
            $userId = $meta['user_id'] ?? null;
            $quantity = (int) ($meta['quantity'] ?? 1);

            $amountInKobo = (int) ($data['amount'] ?? 0);
            $amountInNaira = $amountInKobo / 100;

            $paystackRef = $data['reference'] ?? $reference;

            if (!$userId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User ID missing in Paystack metadata.'
                ], 422);
            }

            // Prevent double-credit
            if (WalletTransaction::where('reference_id', $paystackRef)->exists()) {
                return response()->json([
                    'status' => 'info',
                    'message' => 'Payment already recorded.'
                ]);
            }

            DB::beginTransaction();

            WalletTransaction::create([
                'user_id' => $userId,
                'type' => 'credit',
                'amount' => $amountInNaira,
                'school_id' => $user->school_id,
                'description' => "Purchased {$quantity} result slot(s)",
                'reference_id' => $paystackRef,
            ]);

            // wallet keyed by user_id in your code
            $wallet = Wallet::firstOrNew(['user_id' => $userId]);
            $wallet->balance = (float) ($wallet->balance ?? 0) + (float) $amountInNaira;
            $wallet->school_id = $user->school_id;
            $wallet->save();

            // Email receipt object used by your mailable
            $payment = (object) [
                'sub_plan' => 'Wallet Top-up',
                'amount' => $amountInNaira,
                'reference' => $paystackRef,
                'created_at' => now(),
            ];

            Mail::to($user->email)->send(new WalletTopupMail($user, $payment));

            // System notification
           $user->notify(new SystemNotification(
                "Your wallet has been credited with ₦" . number_format($amountInNaira, 2) . ". Reference: {$paystackRef}.",
                url(''),
                'success'
            ));


            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Payment verified and wallet credited.',
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
        $wallet = Wallet::where('school_id', $user->school_id)->first();

        return response()->json([
            'balance' => $wallet ? $wallet->balance : 0
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
