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
    $request->headers->set('Accept', 'application/json');
    config(['sanctum.stateful' => []]);

    $request->validate([
        'identifier' => ['required', 'string'],
        'password'   => ['required', 'string'],
    ]);

    $throttleKey = $this->throttleKey($request);

    if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
        return response()->json([
            'message' => 'Too many login attempts. Please try again later.',
        ], Response::HTTP_TOO_MANY_REQUESTS);
    }

    // Resolved by ResolveSchoolFromDomain middleware when the request
    // arrives via a verified custom domain (e.g. portal.school.edu).
    // NULL when logging in via the main app domain — schools without a
    // custom domain always take this path and are unaffected.
    $school = $request->attributes->get('school');

    $user = User::with('schoolsetting')
        ->where(function ($q) use ($request) {
            // Wrap in a closure so orWhere doesn't escape the school scope
            $q->where('email', $request->identifier)
              ->orWhere('reg_no', $request->identifier);
        })
        // Only add school_id scope when a domain was resolved.
        // Schools without a custom domain: $school is null, clause is skipped.
        ->when($school, fn($q) => $q->where('school_id', $school->id))
        ->first();

    if (!$user || !Hash::check($request->password, $user->password)) {
        RateLimiter::hit($throttleKey, 60);
        return response()->json([
            'message' => 'Invalid credentials.',
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    // Extra guard: if a custom domain was used, ensure the authenticated user
    // actually belongs to that school. Redundant when ->when() is in place,
    // but kept as a safety net against future query refactors.
    if ($school && (int) $user->school_id !== (int) $school->id) {
        RateLimiter::hit($throttleKey, 60);
        return response()->json([
            'message' => 'Invalid credentials.',
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    $userRole = $user->role ?? null;

    if ((int) $user->status !== 1 && $userRole !== 'Admin') {
        return response()->json([
            'message' => 'Account is inactive.',
        ], Response::HTTP_FORBIDDEN);
    }

    RateLimiter::clear($throttleKey);

    $user->forceFill(['last_login_at' => now()])->save();

    $tokenResult = $user->createToken('access_token', ['basic-auth']);
    $plainToken  = $tokenResult->plainTextToken;

    try {
        ActivityLog::create([
            'user_id'     => $user->id,
            'school_id'   => $user->school_id,
            'action'      => 'login',
            'description' => 'User logged in' . ($school ? " via {$school->domain}" : ''),
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);
    } catch (\Throwable $e) {
        Log::warning('Login activity log failed: ' . $e->getMessage());
    }

    $safeUser = [
        'id'        => $user->id,
        'firstname' => $user->firstname,
        'email'     => $user->email,
        'reg_no'    => $user->reg_no,
        'school_id' => $user->school_id,
        'role'      => $userRole,
        'photo_url' => $user->photo
            ? asset('uploads/users/' . $user->photo)
            : asset('img/profile.png'),
        'school'    => $user->schoolsetting ? [
            'name' => $user->schoolsetting->school_name,
            'logo' => $user->schoolsetting->logo
                ? asset($user->schoolsetting->logo)
                : asset('img/school-default.png'),
        ] : null,
        'must_change_password' => (bool) $user->force_password_change,
        'super_admin_type' => $user->super_admin_type,
        'super_admin_type_label' => $user->isSuperAdminUser() ? $user->superAdminTypeLabel() : null,
        'super_admin_permissions' => $user->superAdminPermissions(),
    ];

    return response()->json([
        'message'      => 'Login successful',
        'access_token' => $plainToken,
        'token_type'   => 'Bearer',
        'user'         => $safeUser,
    ], Response::HTTP_OK);
}

public function changeInitialPassword(Request $request)
{
    $user = $request->user();

    $validated = $request->validate([
        'current_password' => ['required', 'string'],
        'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
    ]);

    if (! Hash::check($validated['current_password'], $user->password)) {
        return response()->json([
            'message' => 'Current password is incorrect.',
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    if (Hash::check($validated['password'], $user->password)) {
        return response()->json([
            'message' => 'New password must be different from the temporary password.',
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    $user->forceFill([
        'password' => Hash::make($validated['password']),
        'default_password' => null,
        'force_password_change' => false,
        'password_changed_at' => now(),
    ])->save();

    return response()->json([
        'message' => 'Password changed successfully.',
        'user' => [
            'id' => $user->id,
            'firstname' => $user->firstname,
            'email' => $user->email,
            'reg_no' => $user->reg_no,
            'school_id' => $user->school_id,
            'role' => $user->role,
            'must_change_password' => false,
            'super_admin_type' => $user->super_admin_type,
            'super_admin_type_label' => $user->isSuperAdminUser() ? $user->superAdminTypeLabel() : null,
            'super_admin_permissions' => $user->superAdminPermissions(),
        ],
    ]);
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

    $user = User::where('email', $request->email)
                ->where('role', 'Admin')
                ->first();

   

  

    $code = random_int(100000, 999999);

    $user->password_reset_code = Hash::make((string) $code);
    $user->password_reset_expires_at = now()->addMinutes(15);
    $user->save();

 

    Mail::to($user->email)->send(new ForgotPassword($user, $code));

    return response()->json([
        'message' => 'If an account exists, a reset code has been sent.'
    ]);
}


 


public function verifyResetCode(Request $request)
{
    $request->validate([
        'email' => ['required', 'email'],
        'code'  => ['required', 'digits:6'],
    ]);

    $user = User::where('email', $request->email)
                ->where('role', 'Admin')
                ->first();



    if (! $user) {
        Log::warning('verifyResetCode: admin user not found', [
            'email' => $request->email,
        ]);

        return response()->json(['message' => 'Invalid or expired reset code.'], 422);
    }



    if (! $user->password_reset_expires_at || now()->isAfter($user->password_reset_expires_at)) {
        Log::warning('verifyResetCode: code expired or missing expiry', [
            'user_id' => $user->id,
            'expires_at' => $user->password_reset_expires_at,
            'now' => now()->toDateTimeString(),
        ]);

        return response()->json(['message' => 'Invalid or expired reset code.'], 422);
    }

    $codeMatches = Hash::check((string) $request->code, $user->password_reset_code);

  

 

    return response()->json(['message' => 'Code verified.']);
}



public function resetPassword(Request $request)
{
    $request->validate([
        'email'    => ['required', 'email'],
        'code'     => ['required', 'digits:6'],
        'password' => ['required', 'min:8', 'confirmed'],
    ]);

    $user = User::where('email', $request->email)
                ->where('role', 'Admin')
                ->first();

    if (! $user || ! $user->password_reset_expires_at || now()->isAfter($user->password_reset_expires_at)) {
        return response()->json(['message' => 'Invalid or expired reset code.'], 422);
    }

    if (! Hash::check((string) $request->code, $user->password_reset_code)) {
        return response()->json(['message' => 'The reset code is incorrect.'], 422);
    }

    $user->password = Hash::make($request->password);
    $user->password_reset_code = null;
    $user->password_reset_expires_at = null;
    $user->save();

    return response()->json(['message' => 'Password reset successfully.']);
}
}
