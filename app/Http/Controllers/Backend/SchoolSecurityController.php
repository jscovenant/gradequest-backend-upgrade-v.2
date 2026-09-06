<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Mail\SchoolSecurityAlertMail;
use App\Mail\SchoolSecurityOtpMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SchoolSecurityController extends Controller
{
    /**
     * Mask email for secure display (e.g., s****@gmail.com)
     */
    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) < 2) return $email;
        $name = $parts[0];
        $domain = $parts[1];
        $maskedName = strlen($name) <= 2
            ? substr($name, 0, 1) . '***'
            : substr($name, 0, 1) . str_repeat('*', max(3, strlen($name) - 2)) . substr($name, -1);
        return "{$maskedName}@{$domain}";
    }

    /**
     * Find the master School Owner/Admin for the school
     */
    private function getSchoolOwner(Request $request): User
    {
        $user = $request->user();
        if (!$user->school_id) {
            return $user;
        }

        $owner = User::where('school_id', $user->school_id)
            ->where('role', 'Admin')
            ->orderBy('id')
            ->first();

        return $owner ?: $user;
    }

    /**
     * Request an OTP for a sensitive action
     */
    public function requestOtp(Request $request)
    {
        $request->validate([
            'action' => 'required|string|max:50',
            'action_description' => 'nullable|string|max:255',
        ]);

        $action = $request->input('action', 'bank_account_update');
        $actionDesc = $request->input('action_description', 'modify school bank account settings');
        $owner = $this->getSchoolOwner($request);

        // Generate 6-digit OTP
        $otp = (string) random_int(100000, 999999);
        $cacheKey = "school_security_otp_{$owner->id}_{$action}";

        // Store in cache for 10 minutes
        Cache::put($cacheKey, $otp, now()->addMinutes(10));

        try {
            Mail::to($owner->email)->send(new SchoolSecurityOtpMail($owner, $otp, $actionDesc));
        } catch (\Throwable $e) {
            Log::error("Failed to send School Security OTP to {$owner->email}: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Unable to send security verification code right now. Please check your email configuration.',
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => "A 6-digit security code has been sent to the school proprietor's email (" . $this->maskEmail($owner->email) . ").",
            'masked_email' => $this->maskEmail($owner->email),
            'expires_in_seconds' => 600,
        ]);
    }

    /**
     * Verify an OTP for a sensitive action
     */
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'action' => 'required|string|max:50',
            'otp' => 'required|string|size:6',
        ]);

        $action = $request->input('action', 'bank_account_update');
        $inputOtp = trim($request->input('otp'));
        $owner = $this->getSchoolOwner($request);
        $cacheKey = "school_security_otp_{$owner->id}_{$action}";

        $cachedOtp = Cache::get($cacheKey);

        if (!$cachedOtp || $cachedOtp !== $inputOtp) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid or expired verification code. Please request a new code.',
            ], 422);
        }

        // OTP is valid - grant temporary verified state (15 minutes)
        Cache::forget($cacheKey);
        $verifiedKey = "school_security_verified_{$owner->id}_{$action}";
        Cache::put($verifiedKey, true, now()->addMinutes(15));

        return response()->json([
            'status' => 'success',
            'message' => 'Security code verified successfully.',
            'verified' => true,
        ]);
    }

    /**
     * Emergency Kill Switch: Revoke all other active sessions for this school
     */
    public function revokeOtherSessions(Request $request)
    {
        $user = $request->user();
        $currentTokenId = $user->currentAccessToken()?->id;

        $schoolUsers = User::where('school_id', $user->school_id)
            ->whereIn('role', ['Admin', 'admin', 'Operator', 'operator', 'Bursar', 'bursar'])
            ->get();

        $revokedCount = 0;
        foreach ($schoolUsers as $u) {
            if ($u->id === $user->id && $currentTokenId) {
                $revokedCount += $u->tokens()->where('id', '!=', $currentTokenId)->delete();
            } else {
                $revokedCount += $u->tokens()->delete();
            }
        }

        $owner = $this->getSchoolOwner($request);

        try {
            Mail::to($owner->email)->send(new SchoolSecurityAlertMail(
                $owner,
                'Emergency Action: All Staff & Operator Sessions Revoked',
                [
                    'action_performed' => 'Emergency Session Revocation (Kill Switch)',
                    'terminated_sessions_count' => $revokedCount,
                    'initiated_by' => $user->email,
                    'security_status' => 'All other devices and assistants have been logged out.',
                ]
            ));
        } catch (\Throwable $e) {
            Log::error("Failed to send Revoke Sessions alert to {$owner->email}: " . $e->getMessage());
        }

        return response()->json([
            'status' => 'success',
            'message' => 'All other active staff and assistant sessions have been terminated immediately.',
            'revoked_count' => $revokedCount,
        ]);
    }

    /**
     * Get active sessions status overview
     */
    public function getActiveSessions(Request $request)
    {
        $user = $request->user();
        $tokens = $user->tokens()->orderByDesc('last_used_at')->take(10)->get();

        return response()->json([
            'status' => 'success',
            'total_active_tokens' => $tokens->count(),
            'current_token_id' => $user->currentAccessToken()?->id,
            'sessions' => $tokens->map(fn($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'is_current' => $t->id === $user->currentAccessToken()?->id,
                'last_used_at' => $t->last_used_at ? $t->last_used_at->toIso8601String() : $t->created_at->toIso8601String(),
                'created_at' => $t->created_at->toIso8601String(),
            ]),
        ]);
    }
}
