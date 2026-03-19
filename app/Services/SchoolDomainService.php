<?php

namespace App\Services;


use App\Models\SchoolDomain;
use App\Models\SchoolSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SchoolDomainService
{
    public function register(SchoolSetting $school, string $domain): SchoolDomain
    {
        // 1. Normalise — strip protocol if accidentally included
        $domain = preg_replace('#^https?://#', '', rtrim($domain, '/'));

        // 2. Format check
        if (!filter_var("https://{$domain}", FILTER_VALIDATE_URL)) {
            throw ValidationException::withMessages([
                'domain' => 'Invalid domain format.',
            ]);
        }

        // 3. Not already taken by another school
        $exists = SchoolDomain::where('domain', $domain)
            ->where('school_id', '!=', $school->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'domain' => 'This domain is already registered.',
            ]);
        }

        // 4. HTTPS reachable check
        $this->assertHttpsReachable($domain);

        // 5. Generate ownership verification token
        $token = 'gradequest-verify=' . Str::random(32);

        return SchoolDomain::updateOrCreate(
            ['school_id' => $school->id, 'domain' => $domain],
            [
                'status'             => 'pending',
                'verification_token' => $token,
            ]
        );
    }

    public function verify(SchoolDomain $schoolDomain): void
    {
        // Check DNS TXT record contains the token
        $records = dns_get_record($schoolDomain->domain, DNS_TXT);

        $found = collect($records)->contains(function ($r) use ($schoolDomain) {
            return str_contains($r['txt'] ?? '', $schoolDomain->verification_token);
        });

        if (!$found) {
            throw ValidationException::withMessages([
                'domain' => 'Verification TXT record not found. Please add it to your DNS and try again.',
            ]);
        }

        $schoolDomain->update([
            'status'      => 'verified',
            'verified_at' => now(),
        ]);
    }

    private function assertHttpsReachable(string $domain): void
    {
        try {
            $response = Http::timeout(5)
                ->withoutVerifying() // just checking reachability, not cert validity
                ->get("https://{$domain}");

            // We just need it to respond — any HTTP response is fine
        } catch (\Exception) {
            throw ValidationException::withMessages([
                'domain' => 'Domain is not reachable over HTTPS. Ensure it has a valid SSL certificate.',
            ]);
        }
    }
}