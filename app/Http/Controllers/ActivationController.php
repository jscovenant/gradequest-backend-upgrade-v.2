<?php

namespace App\Http\Controllers;

use App\Models\AcademicSession;
use App\Models\Term;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\Subscription;
use Illuminate\Support\Facades\Log;
use App\Mail\WelcomeBonusMail;
use Illuminate\Support\Facades\Mail;

class ActivationController extends Controller
{


    
public function verifyEmailCode(Request $request)
{
    $request->validate([
        'code' => 'required|digits:5',
    ]);

    $user = User::find(Auth::id());

    Log::info("Verification attempt", [
        'user_id' => $user?->id,
        'submitted_code' => $request->code,
        'actual_code' => $user?->email_verification_code
    ]);


    if (!$user) {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }

    if ($user->email_verification_code	 == $request->code) {
        $user->email_verified_at = now();
        $user->email_verification_code = null; 
        $user->save();

        return response()->json(['message' => 'Email verified successfully.']);
    }

    return response()->json(['message' => 'Invalid verification code.'], 422);

}


public function resendEmailCode()
{
    $user = Auth::user();

    if (!$user) {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }

    // Generate a new 5-digit code
    $user->email_verification_code = rand(10000, 99999);
    $user->save();

    // Send the email using the existing Mailable
    \Illuminate\Support\Facades\Mail::to($user->email)->send(new \App\Mail\VerifyEmailCode($user));

    return response()->json(['message' => 'Verification code resent successfully.']);
}





public function activateBonus()
{
    $user = User::find(Auth::id());

    if ($user->bonus_given) {
        return response()->json(['message' => 'Bonus already claimed'], 409);
    }

    $session = AcademicSession::where('school_id', $user->school_id)
        ->where('status', 'Active')
        ->first();
    $terms = Term::where('school_id', $user->school_id)->count();

    if (!$user->email_verified_at || !$session || $terms < 3) {
        return response()->json(['message' => 'Complete activation steps first'], 422);
    }

    // Wallet
    $wallet = Wallet::firstOrCreate(
        ['user_id' => $user->id],
        ['school_id' => $user->school_id, 'balance' => 0]
    );

    $wallet->balance += 500;
    $wallet->save();

    WalletTransaction::create([
        'user_id' => $user->id,
        'amount' => 500,
        'type' => 'credit',
        'school_id' => $user->school_id,
        'description' => '₦500 Welcome Bonus',
        'reference_id' => Str::uuid(),
    ]);

    // Mark bonus
    $user->bonus_given = true;
    $user->status = 1;
    $user->save();

    // =============================
    // AUTO-SUBSCRIBE USER TO FREE PLAN
    // =============================
    $freePlanId = 7; 

    Subscription::where('user_id', $user->id)
        ->where('subscription_plan_id', $freePlanId)
        ->delete();

    Subscription::create([
        'user_id' => $user->id,
        'subscription_plan_id' => $freePlanId,
        'authorization_code' => null,
        'auto_renew' => false,
        'auto_renew_source' => null,
        'customer_code' => null,
        'email_token' => null,
        'subscription_code' => Str::uuid(),
        'status' => 'active',
        'notified_about_expiry' => false,
        'starts_at' => now(),
        'ends_at' => now()->addDays(30), // Free plan duration
    ]);

    // EMAIL
    try {
        Mail::to($user->email)->send(new WelcomeBonusMail($user));
    } catch (\Exception $e) {
        \Log::error("Welcome email failed for user {$user->id}: " . $e->getMessage());
    }

    return response()->json([
        'message' => '₦500 bonus credited successfully. Free plan activated!',
        'bonus_amount' => 500,
        'user' => $user->only(['name', 'email']),
    ]);
}


public function onboardingStatus()
{
    $user = Auth::user();

    if (!$user) {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }

    // Check if active session exists
    $session = AcademicSession::where('school_id', $user->school_id)
        ->where('status', 'Active')
        ->first();

    // Count terms for the school
    $termCount = Term::where('school_id', $user->school_id)->count();

    return response()->json([
        'email_verified' => !is_null($user->email_verified_at),
        'current_session' => (bool) $session,
        'all_terms_exist' => $termCount >= 3,
        'bonus_given' => (bool) $user->bonus_given,
    ]);
}




}
