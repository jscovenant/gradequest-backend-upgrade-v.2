<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\SchoolSetting;
use App\Models\ActivityLog;
use App\Mail\ForgotPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Auth\Events\Registered;
use Carbon\Carbon;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    // throttle key for login attempts
    protected function throttleKey(Request $request)
    {
        return Str::lower($request->input('identifier')).'|'.$request->ip();
    }

    /**
     * Login
     */
    public function login(Request $request)
    {
        // Basic request validation
        $request->validate([
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = $this->throttleKey($request);

        // Rate limit login attempts (customize attempts & decay)
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return response()->json([
                'message' => 'Too many login attempts. Please try again later.'
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        // Find user by email or reg_no
        $user = User::where('email', $request->identifier)
                    ->orWhere('reg_no', $request->identifier)
                    ->first();

        // Generic failure message (do not reveal whether user exists)
        $badCredsResponse = response()->json([
            'message' => 'Invalid credentials.'
        ], Response::HTTP_UNPROCESSABLE_ENTITY);

        if (! $user || ! Hash::check($request->password, $user->password)) {
            RateLimiter::hit($throttleKey, 60); // count attempt, decay 60s (adjust as needed)
            return $badCredsResponse;
        }

        // Prevent inactive accounts from logging in (but allow Super-Admin via role check on DB)
       $userRole = $user->role ?? null;
        if ((int)$user->status !== 1 && $userRole !== 'Super-Admin') {
            return response()->json([
                'message' => 'Account is inactive.'
            ], Response::HTTP_FORBIDDEN);
        }

        // Everything OK -> clear rate limiter for this key
        RateLimiter::clear($throttleKey);

        // Create personal access token with minimal abilities
        // Abilities should reflect what the token can do; avoid storing roles as ability directly.
        $abilities = ['basic-auth']; // add more abilities if required for API usage

        // Prefer issuing short-lived tokens or use cookie-based session for SPAs.
        // Here we create a token and set an expiration in DB meta (you can enforce expirations via scheduled job).
        $tokenResult = $user->createToken('access_token', $abilities);
        $plainToken = $tokenResult->plainTextToken;

        // Audit login
        try {
            ActivityLog::create([
                'user_id' => $user->id,
                'school_id' => $user->school_id,
                'action' => 'login',
                'description' => 'User logged in',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Could not write activity log on login: '.$e->getMessage());
            // do not fail login if log fails
        }

        // Minimal user payload to return (avoid exposing sensitive fields)
        $safeUser = [
            'id' => $user->id,
            'firstname' => $user->firstname,
            'email' => $user->email,
            'reg_no' => $user->reg_no,
            'school_id' => $user->school_id,
            'photo_url' => $user->photo ? asset('uploads/users/'.$user->photo) : asset('img/profile.png'),
            'role' => $userRole, 
        ];

        return response()->json([
            'message' => 'Login successful',
            'access_token' => $plainToken,
            'token_type' => 'Bearer',
            'user' => $safeUser,
        ], Response::HTTP_OK);
    }

    /**
     * Register (creates school + user + assigns role)
     */

public function register(Request $request)
{
 $validated = $request->validate([
    'firstname' => 'required|string|max:255',
    'surname' => 'required|string|max:255',
    'email' => 'nullable|email|unique:users,email',
    'password' => ['required', Password::min(8)->mixedCase()->numbers()->symbols(), 'confirmed'],
    'phone' => 'required|string|max:20',
    'school_name' => 'required|string|max:255',
    'address' => 'required|string|max:255',
    'school_subdomain' => 'required|alpha_dash|unique:school_settings,school_subdomain',
]);


    $role = 'Admin';
    $reg_no = 'R' . random_int(100000, 999999);

    DB::beginTransaction();

    try {
        // Generate 5-digit email verification code
        $verificationCode = random_int(10000, 99999);

        // Create school
        $school = SchoolSetting::create([
            'school_name' => $request->school_name,
            'address' => $request->address,
            'school_subdomain' => $request->school_subdomain,
            'phone' => $request->phone,
        ]);

        // Create user
        $user = User::create([
            'firstname' => $request->firstname,
            'surname' => $request->surname,
            'reg_no' => $reg_no,
            'email' => $request->email,
            'role' => $role,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'school_id' => $school->id,
            'status' => 1,
            'email_verification_code' => $verificationCode,
        ]);

        // Assign role
        $user->assignRole($role);

        // Link school to user
        $school->user_id = $user->id;
        $school->save();

        // 🔹 Send verification email if email exists
        if ($user->email) {
          Mail::to($user->email)->send(new \App\Mail\VerifyEmailCode($user));
          
        }

        event(new Registered($user));

        // Create auth token
        $token = $user->createToken('auth_token', ['basic-auth'])->plainTextToken;

        DB::commit();

        $safeUser = [
            'id' => $user->id,
            'firstname' => $user->firstname,
            'email' => $user->email,
            'reg_no' => $user->reg_no,
            'school_id' => $user->school_id,
            'role' => $role,
        ];

        return response()->json([
            'message' => 'User registered. Check your email for a verification code.',
            'token' => $token,
            'user' => $safeUser,
        ], Response::HTTP_CREATED);

    } catch (\Throwable $e) {
        DB::rollBack();
        Log::error('Registration error: ' . $e->getMessage());

        return response()->json([
            'message' => 'Registration failed.'
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}


    /**
     * Check subdomain availability
     */
    public function checkSubdomain($subdomain)
    {
        $exists = SchoolSetting::where('school_subdomain', $subdomain)->exists();
        return response()->json(['available' => !$exists]);
    }

    /**
     * Logout current token
     */
    public function logout(Request $request)
    {
        $user = $request->user();
        if ($user && $request->user()->currentAccessToken()) {
            $request->user()->currentAccessToken()->delete();
        }
        return response()->json(['message' => 'Successfully logged out.']);
    }

    /**
     * Toggle user status (admin only - make sure route uses appropriate policy/middleware)
     */
    public function toggleStatus(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $user->status = $user->status == 1 ? 0 : 1;
        $user->save();

        return response()->json(['message' => 'User status updated successfully']);
    }

    /**
     * Send admin reset link / code (secure)
     */
  public function sendAdminResetLink(Request $request)
{
    $request->validate([
        'email' => ['required', 'email'],
    ]);

    // Check if user exists AND is an Admin (role stored directly in users table)
    $user = User::where('email', $request->email)
                ->where('role', 'Admin')
                ->first();

    if (! $user) {
        return response()->json([
            'message' => 'If an account exists, a reset code has been sent.'
        ]);
    }

    // Generate 6-digit OTP
    $code = random_int(100000, 999999);

    $user->password_reset_code = Hash::make((string) $code);
    $user->password_reset_expires_at = now()->addMinutes(15);
    $user->save();

    // Send email
   Mail::to($user->email)->send(new ForgotPassword($user, $code));

    return response()->json([
        'message' => 'If an account exists, a reset code has been sent.'
    ]);
}


    /**
     * Reset admin password
     */
   public function resetAdminPassword(Request $request)
{
    $request->validate([
        'email' => ['required','email','exists:users,email'],
        'reset_code' => ['required','string'],
        'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
    ]);

    // MUST MATCH the check in sendAdminResetLink
    $user = User::where('email', $request->email)
                ->where('role', 'Admin')
                ->first();

    if (! $user) {
        return response()->json(['message' => 'Invalid request.'], 400);
    }

    // if (!$user->password_reset_code 
    //     || !$user->password_reset_expires_at 
    //     || $user->password_reset_expires_at->isPast()) {
    //     return response()->json(['message' => 'Reset code expired or invalid.'], 400);
    // }

    if (! Hash::check($request->reset_code, $user->password_reset_code)) {
        return response()->json(['message' => 'Invalid reset code.'], 400);
    }

    $user->password = Hash::make($request->password);
    $user->password_reset_code = null;
    $user->password_reset_expires_at = null;
    $user->save();

    return response()->json(['message' => 'Password reset successful.']);
}

}

