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
use App\Services\WelcomeWalletCreditService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use App\Models\SchoolTermsAcceptance;

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

/**
     * Route::post('/set-current-session', [ActivationController::class, 'setCurrentSession']);
     *
     * Creates session if not exists (per school), updates dates/status,
     * and if make_current=true sets it as current (school scoped).
     */
    public function setCurrentSession(Request $request)
    {
        $auth = Auth::user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'make_current' => 'nullable|boolean',
        ]);

        return DB::transaction(function () use ($auth, $validated) {

            // Find existing session for this school+name (avoid duplicates)
            $session = AcademicSession::where('school_id', $auth->school_id)
                ->where('name', $validated['name'])
                ->whereNull('archived_at')
                ->first();

            if (!$session) {
                $session = new AcademicSession();
                $session->school_id = $auth->school_id;
                $session->name = $validated['name'];
            }

            // Update optional fields
            if (array_key_exists('start_date', $validated)) $session->start_date = $validated['start_date'];
            if (array_key_exists('end_date', $validated)) $session->end_date = $validated['end_date'];

            // Mark active (you already have status column)
            $session->status = 'Active';
            $session->save();

            if (!empty($validated['make_current'])) {
                // turn off current for all sessions in the school
                AcademicSession::where('school_id', $auth->school_id)->whereNull('archived_at')->update(['is_current' => 0]);

                // set this session current
                $session->is_current = 1;
                $session->save();
            }

            return response()->json([
                'message' => !empty($validated['make_current'])
                    ? 'Session saved and set as current.'
                    : 'Session saved successfully.',
                'session' => $session,
            ], 201);
        });
    }

    /**
     * Route::post('/create-all-terms', [ActivationController::class, 'createAllTerms']);
     *
     * Creates default terms if not exist and optionally sets one as active.
     */
    public function createAllTerms(Request $request)
    {
        $auth = Auth::user();

        $validated = $request->validate([
            'terms' => 'nullable|array',
            'terms.*' => 'string|max:255',
            'make_current' => 'nullable|boolean',
            'current_term' => 'nullable|string|max:255',
        ]);

        $termsToCreate = $validated['terms'] ?? ['1st Term', '2nd Term', '3rd Term'];

        return DB::transaction(function () use ($auth, $validated, $termsToCreate) {

            $created = [];

            foreach ($termsToCreate as $name) {
                $term = Term::where('school_id', $auth->school_id)->where('name', $name)->whereNull('archived_at')->first();
                if (!$term) {
                    $term = new Term();
                    $term->school_id = $auth->school_id;
                    $term->name = $name;
                    $term->status = 'Inactive'; // default
                    $term->save();
                    $created[] = $term;
                }
            }

            if (!empty($validated['make_current'])) {
                $currentName = $validated['current_term'] ?? '1st Term';

                // deactivate all terms for school
                Term::where('school_id', $auth->school_id)->whereNull('archived_at')->update(['status' => 'Inactive']);

                // activate selected
                $term = Term::where('school_id', $auth->school_id)->where('name', $currentName)->whereNull('archived_at')->first();

                if (!$term) {
                    return response()->json([
                        'message' => "Cannot set current term. '{$currentName}' does not exist.",
                    ], 404);
                }

                $term->status = 'Active';
                $term->save();
            }

            return response()->json([
                'message' => !empty($validated['make_current'])
                    ? 'Terms created and current term set.'
                    : 'Terms created successfully.',
                'created' => $created,
            ], 201);
        });
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





public function acceptTerms(Request $request)
{
    $user = $request->user();

    abort_unless($user && $user->role === 'Admin' && $user->school_id, 403, 'Only a school administrator can accept the Terms and Conditions.');

    $validated = $request->validate([
        'accepted' => ['required', 'accepted'],
    ]);

    $acceptance = SchoolTermsAcceptance::firstOrCreate(
        [
            'school_id' => $user->school_id,
            'terms_version' => SchoolTermsAcceptance::CURRENT_VERSION,
        ],
        [
            'accepted_by' => $user->id,
            'accepted_at' => now(),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
        ]
    );

    return response()->json([
        'message' => 'Terms and Conditions accepted successfully.',
        'terms_accepted' => true,
        'terms_version' => $acceptance->terms_version,
        'accepted_at' => $acceptance->accepted_at,
    ]);
}

public function activateBonus()
{
    $user = User::find(Auth::id());

    if ($user->bonus_given) {
        return response()->json(['message' => 'Welcome credit already claimed'], 409);
    }

    $session = AcademicSession::where('school_id', $user->school_id)
        ->whereNull('archived_at')
        ->where('status', 'Active')
        ->first();
    $terms = Term::where('school_id', $user->school_id)->whereNull('archived_at')->count();
    $termsAccepted = SchoolTermsAcceptance::where('school_id', $user->school_id)
        ->where('terms_version', SchoolTermsAcceptance::CURRENT_VERSION)
        ->exists();

    if (!$user->email_verified_at || !$session || $terms < 3 || !$termsAccepted) {
        return response()->json(['message' => 'Complete activation steps first'], 422);
    }

    $credit = app(WelcomeWalletCreditService::class)->grantToAdmin($user);

    $user->bonus_given = true;
    $user->status = 1;
    $user->save();

    try {
        Mail::to($user->email)->send(new WelcomeBonusMail($user));
    } catch (\Exception $e) {
        Log::error("Welcome email failed for user {$user->id}: " . $e->getMessage());
    }

    return response()->json([
        'message' => 'Welcome wallet credit added successfully. Use it within 30 days to subscribe to GradeQuestPlus.',
        'bonus_amount' => WelcomeWalletCreditService::AMOUNT,
        'expires_at' => $credit?->expires_at,
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
        ->whereNull('archived_at')
        ->where('status', 'Active')
        ->first();

    // Count terms for the school
    $termCount = Term::where('school_id', $user->school_id)->whereNull('archived_at')->count();
    $termsAcceptance = SchoolTermsAcceptance::where('school_id', $user->school_id)
        ->where('terms_version', SchoolTermsAcceptance::CURRENT_VERSION)
        ->first();

    return response()->json([
        'email_verified' => !is_null($user->email_verified_at),
        'current_session' => (bool) $session,
        'all_terms_exist' => $termCount >= 3,
        'terms_accepted' => (bool) $termsAcceptance,
        'terms_version' => SchoolTermsAcceptance::CURRENT_VERSION,
        'terms_accepted_at' => $termsAcceptance?->accepted_at,
        'bonus_given' => (bool) $user->bonus_given,
    ]);
}




}

