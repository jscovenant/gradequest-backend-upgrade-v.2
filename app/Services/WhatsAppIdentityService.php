<?php

namespace App\Services;

use App\Models\SchoolWhatsappAccount;
use App\Models\User;
use App\Models\WhatsappVerification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class WhatsAppIdentityService
{
    public function __construct(
        private WhatsAppCloudClient $client
    ) {}

    public function startAdminVerification(User $authUser, string $phone): array
    {
        if (($authUser->role ?? null) !== 'Admin') {
            throw ValidationException::withMessages([
                'user' => 'Only admins can verify school WhatsApp contacts.',
            ]);
        }

        $schoolId = (int) ($authUser->school_id ?? 0);

        if (!$schoolId) {
            throw ValidationException::withMessages([
                'school_id' => 'School not found for admin.',
            ]);
        }

        $this->assertSchoolWhatsappConnected($schoolId);

        $normalized = $this->client->normalizePhone($phone);
        $otp = (string) random_int(100000, 999999);

        $verification = WhatsappVerification::create([
            'school_id' => $schoolId,
            'user_id' => $authUser->id,
            'actor_type' => 'admin',
            'phone' => $phone,
            'normalized_phone' => $normalized,
            'code_hash' => Hash::make($otp),
            'channel' => 'mixed',
            'expires_at' => now()->addMinutes(10),
            'status' => 'pending',
        ]);

        $this->sendOtpByMixedFallback(
            schoolId: $schoolId,
            phone: $normalized,
            name: trim(($authUser->firstname ?? '') . ' ' . ($authUser->surname ?? '')),
            otp: $otp
        );

        return [
            'message' => 'Verification OTP sent successfully.',
            'verification_id' => $verification->id,
            'expires_at' => $verification->expires_at,
        ];
    }

    public function startParentVerification(User $parentUser): array
    {
        if (($parentUser->role ?? null) !== 'Parent') {
            throw ValidationException::withMessages([
                'user' => 'Only parent users can verify parent WhatsApp.',
            ]);
        }

        $schoolId = (int) ($parentUser->school_id ?? 0);
        if (!$schoolId) {
            throw ValidationException::withMessages([
                'school_id' => 'School not found for parent.',
            ]);
        }

        $this->assertSchoolWhatsappConnected($schoolId);

        $phone = $parentUser->whatsapp_no ?: $parentUser->phone;

        if (!$phone) {
            throw ValidationException::withMessages([
                'phone' => 'Parent has no phone number.',
            ]);
        }

        $normalized = $this->client->normalizePhone($phone);
        $otp = (string) random_int(100000, 999999);

        $verification = WhatsappVerification::create([
            'school_id' => $schoolId,
            'user_id' => $parentUser->id,
            'actor_type' => 'parent',
            'phone' => $phone,
            'normalized_phone' => $normalized,
            'code_hash' => Hash::make($otp),
            'channel' => 'mixed',
            'expires_at' => now()->addMinutes(10),
            'status' => 'pending',
        ]);

        $this->sendOtpByMixedFallback(
            schoolId: $schoolId,
            phone: $normalized,
            name: trim(($parentUser->firstname ?? '') . ' ' . ($parentUser->surname ?? '')),
            otp: $otp
        );

        return [
            'message' => 'Verification OTP sent successfully.',
            'verification_id' => $verification->id,
            'expires_at' => $verification->expires_at,
        ];
    }

    public function verifyCode(User $user, int $verificationId, string $code): array
    {
        $verification = WhatsappVerification::query()
            ->where('id', $verificationId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        if ($verification->status !== 'pending') {
            throw ValidationException::withMessages([
                'code' => 'Verification is no longer pending.',
            ]);
        }

        if (Carbon::parse($verification->expires_at)->isPast()) {
            $verification->update(['status' => 'expired']);

            throw ValidationException::withMessages([
                'code' => 'Verification code has expired.',
            ]);
        }

        if (!Hash::check($code, $verification->code_hash)) {
          // ✅ Fixed
            $verification->increment('attempts');
            $verification->refresh(); // sync in-memory model with DB

            if ($verification->attempts >= 5) {
                $verification->update(['status' => 'failed']);
            }

            throw ValidationException::withMessages([
                'code' => 'Invalid verification code.',
            ]);
        }

        DB::transaction(function () use ($verification, $user) {
            $verification->update([
                'status' => 'verified',
                'verified_at' => now(),
            ]);

            $user->forceFill([
                'whatsapp_no' => $verification->normalized_phone,
                'whatsapp_verified_at' => now(),
            ])->save();
        });

        return [
            'message' => 'WhatsApp number verified successfully.',
        ];
    }

    private function assertSchoolWhatsappConnected(int $schoolId): void
    {
        $account = SchoolWhatsappAccount::query()
            ->where('school_id', $schoolId)
            ->where('status', 'active')
            ->first();

        if (!$account) {
            throw ValidationException::withMessages([
                'whatsapp' => 'School WhatsApp business account is not connected.',
            ]);
        }
    }

   private function sendOtpByMixedFallback(int $schoolId, string $phone, string $name, string $otp): void
{
    $this->client->sendTemplateForSchool(
        schoolId: $schoolId,
        toPhone: $phone,
        templateName: 'verify_whatsapp',
        lang: 'en',
        bodyParams: [
            $otp, 
        ]
    );
}
}