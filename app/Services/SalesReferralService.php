<?php

namespace App\Services;

use App\Models\SalesRepAssignment;
use Illuminate\Support\Str;

class SalesReferralService
{
    public function issueRegistrationToken(SalesRepAssignment $lead, int $validDays = 14): string
    {
        $token = Str::random(64);

        $lead->forceFill([
            'registration_token_hash' => hash('sha256', $token),
            'registration_token_expires_at' => now()->addDays($validDays),
            'attribution_locked_at' => $lead->attribution_locked_at ?: now(),
        ])->save();

        return $token;
    }

    public function resolveRegistrationToken(?string $token, bool $lock = false): ?SalesRepAssignment
    {
        if (! is_string($token) || strlen($token) < 32) {
            return null;
        }

        $query = SalesRepAssignment::query()
            ->where('registration_token_hash', hash('sha256', $token))
            ->whereNull('school_id')
            ->whereNull('admin_user_id')
            ->where('registration_token_expires_at', '>=', now());

        return $lock ? $query->lockForUpdate()->first() : $query->first();
    }

    public function registrationUrl(string $token): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . '/register?invitation=' . urlencode($token);
    }

    public function salesPageUrl(string $representativeCode): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . '/sales-page/' . urlencode($representativeCode);
    }
}
