<?php

namespace App\Http\Controllers;

use App\Mail\PaymentConfirmationMail;
use App\Models\Product;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\SubPayment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use App\Mail\WalletTopupMail;

class WalletController extends Controller
{
    public function initialize(Request $request)
    {
        $user = $request->user();
    
        $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);
    
        $pricePerUnit = 100; // in Naira
        $amountInKobo = $request->quantity * $pricePerUnit * 100; // Paystack uses Kobo
    
        if ($amountInKobo < 10000) {
            return response()->json([
                'error' => 'Minimum payment amount is ₦5000. Please increase the number of students.'
            ], 422);
        }
    
        $reference = Str::uuid();
    
        $response = Http::withToken('sk_live_cada997afaed5b58da25f331104d7d17f98dd3b5')
            ->post('https://api.paystack.co/transaction/initialize', [
                'email' => $user->email,
                'amount' => $amountInKobo,
                'reference' => $reference,
                'callback_url' => url('/payment/callback'),
                'metadata' => [
                    'user_id' => $user->id,
                    'quantity' => $request->quantity,
                    'price_per_unit' => $pricePerUnit,
                ],
            ]);
    
        if (!$response->successful()) {
            return response()->json(['error' => 'Payment initialization failed'], 500);
        }
    
        $data = $response->json();
    
        return response()->json([
            'authorization_url' => $data['data']['authorization_url'],
            'access_code' => $data['data']['access_code'],
            'reference' => $reference,
        ]);
    }

    public function verify($reference)
    {
        $user = Auth::user();
    
        try {
            $response = Http::withToken('sk_live_cada997afaed5b58da25f331104d7d17f98dd3b5')
                ->get("https://api.paystack.co/transaction/verify/{$reference}");
    
            if ($response->failed()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to verify transaction from Paystack.',
                    'error' => $response->json()
                ], 500);
            }
    
            $data = $response->json();
    
            if ($data['data']['status'] !== 'success') {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'Payment not successful.',
                    'data' => $data['data']
                ]);
            }
    
            $meta = $data['data']['metadata'];
            $userId = $meta['user_id'] ?? null;
            $quantity = $meta['quantity'] ?? 1;
            $amountInKobo = $data['data']['amount'];
            $amountInNaira = $amountInKobo / 100;
            $reference = $data['data']['reference'];
    
            if (!$userId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User ID missing in Paystack metadata.'
                ], 422);
            }
    
            $alreadyExists = WalletTransaction::where('reference_id', $reference)->exists();
            if ($alreadyExists) {
                return response()->json([
                    'status' => 'info',
                    'message' => 'Payment already recorded.',
                ]);
            }
    
            DB::beginTransaction();
    
            WalletTransaction::create([
                'user_id' => $userId,
                'type' => 'credit',
                'amount' => $amountInNaira,
                'school_id' => $user->school_id,
                'description' => "Purchased {$quantity} result slot(s)",
                'reference_id' => $reference,
            ]);
    
            $wallet = Wallet::firstOrNew(['user_id' => $userId]);
            $wallet->balance = ($wallet->balance ?? 0) + $amountInNaira;
            $wallet->school_id = $user->school_id;
            $wallet->save();
    
            $payment = (object)[
                'sub_plan' => 'Wallet Top-up',
                'amount' => $amountInNaira,
                'reference' => $reference,
                'monthly_duration' => null,
                'yearly_duration' => null,
                'created_at' => now(),
            ];
    
        Mail::to($user->email)->send(new WalletTopupMail($user, $payment));
        
        // Send system notification
$user->notify(new \App\Notifications\SystemNotification(
    message: "Your wallet has been credited with ₦" . number_format($amountInNaira, 2) . ". Reference: {$reference}.",
    type: 'success',
    actionUrl: url('')
));

    
            DB::commit();
    
            return response()->json([
                'status' => 'success',
                'message' => 'Payment verified and wallet credited.',
                'data' => $data['data']
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
            'price' => $product->price / 100,
        ]);
    }

    public function userTransactions(Request $request)
    {
        $auth = Auth::user();
        $perPage = $request->input('perPage', 10);
        $page = $request->input('page', 1);
    
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
        $ids = $request->input('ids'); 
        WalletTransaction::whereIn('id', $ids)->delete();
        return response()->json(['message' => 'Transactions deleted']);
    }

 

}
