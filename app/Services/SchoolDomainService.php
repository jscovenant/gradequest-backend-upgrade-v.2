<?php

namespace App\Services;

use App\Models\SchoolDomain;
use App\Models\SchoolSetting;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SchoolDomainService
{
    public function register(SchoolSetting $school, string $domain): SchoolDomain
    {
        $domain = $this->normalizeDomain($domain);

        $exists = SchoolDomain::query()
            ->where('domain', $domain)
            ->where('school_id', '!=', $school->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['domain' => 'This domain is already registered.']);
        }

        if (SchoolDomain::query()->where('school_id', $school->id)->where('domain', '!=', $domain)->exists()) {
            throw ValidationException::withMessages(['domain' => 'Remove the current school domain before registering another one.']);
        }

        $token = 'gradequest-verify=' . Str::random(40);

        return SchoolDomain::query()->updateOrCreate(
            ['school_id' => $school->id, 'domain' => $domain],
            [
                'type' => 'custom',
                'status' => 'pending',
                'verification_token' => $token,
                'verified_at' => null,
                'ownership_verified_at' => null,
                'routing_verified_at' => null,
                'activated_at' => null,
                'last_checked_at' => null,
                'last_error' => null,
                'consecutive_health_failures' => 0,
            ]
        );
    }

    public function verifyOwnership(SchoolDomain $domain): SchoolDomain
    {
        $records = $this->dnsRecords($this->verificationHost($domain), DNS_TXT);
        $found = collect($records)->contains(
            fn (array $record) => hash_equals(
                (string) $domain->verification_token,
                (string) ($record['txt'] ?? '')
            )
        );

        if (! $found) {
            throw ValidationException::withMessages([
                'domain' => 'Ownership TXT record was not found. DNS changes can take time to propagate.',
            ]);
        }

        $domain->forceFill([
            'status' => 'verified',
            'verified_at' => now(),
            'ownership_verified_at' => now(),
            'last_checked_at' => now(),
            'last_error' => null,
            'consecutive_health_failures' => 0,
        ])->save();

        return $domain->fresh();
    }

    public function activate(SchoolDomain $domain): SchoolDomain
    {
        if (! $domain->ownership_verified_at && ! $domain->verified_at) {
            throw ValidationException::withMessages(['domain' => 'Verify domain ownership before activation.']);
        }

        if (! $this->routingPointsToGradeQuest($domain->domain)) {
            $message = 'Domain routing is not ready. Add the required CNAME record and try again.';
            $domain->forceFill(['last_checked_at' => now(), 'last_error' => $message])->save();
            throw ValidationException::withMessages(['domain' => $message]);
        }

        $domain->forceFill([
            'status' => 'active',
            'routing_verified_at' => now(),
            'activated_at' => $domain->activated_at ?: now(),
            'last_checked_at' => now(),
            'last_error' => null,
            'consecutive_health_failures' => 0,
        ])->save();

        // Keep old consumers working while school_domains becomes the source of truth.
        SchoolSetting::query()->whereKey($domain->school_id)->update(['custom_domain' => $domain->domain]);

        return $domain->fresh();
    }

    public function checkHealth(SchoolDomain $domain): bool
    {
        $healthy = $this->routingPointsToGradeQuest($domain->domain);

        if ($healthy) {
            $domain->forceFill([
                'last_checked_at' => now(),
                'last_error' => null,
                'consecutive_health_failures' => 0,
            ])->save();

            return true;
        }

        $failures = (int) $domain->consecutive_health_failures + 1;
        $threshold = max(1, (int) config('domains.health_failure_threshold', 3));
        $attributes = [
            'last_checked_at' => now(),
            'last_error' => 'The domain no longer points to GradeQuest.',
            'consecutive_health_failures' => $failures,
        ];

        if ($failures >= $threshold) {
            $attributes['status'] = 'disabled';
            SchoolSetting::query()->whereKey($domain->school_id)->update(['custom_domain' => null]);
        }

        $domain->forceFill($attributes)->save();

        return false;
    }

    public function instructions(SchoolDomain $domain): array
    {
        return [
            'ownership' => [
                'type' => 'TXT',
                'host' => $this->verificationHost($domain),
                'value' => $domain->verification_token,
            ],
            'routing' => [
                'type' => 'CNAME',
                'host' => $domain->domain,
                'value' => config('domains.cname_target'),
            ],
            'portal_url' => 'https://' . $domain->domain,
        ];
    }

    private function verificationHost(SchoolDomain $domain): string
    {
        return trim((string) config('domains.verification_prefix'), '.') . '.' . $domain->domain;
    }

    protected function routingPointsToGradeQuest(string $domain): bool
    {
        $target = strtolower(trim((string) config('domains.cname_target'), '. '));
        $records = $this->dnsRecords($domain, DNS_CNAME | DNS_A | DNS_AAAA);

        foreach ($records as $record) {
            $cname = strtolower(trim((string) ($record['target'] ?? ''), '. '));
            if ($target !== '' && $cname !== '' && hash_equals($target, $cname)) {
                return true;
            }

            $address = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
            if ($address !== '' && in_array($address, config('domains.target_ips', []), true)) {
                return true;
            }
        }

        return false;
    }

    protected function dnsRecords(string $host, int $type): array
    {
        return @dns_get_record($host, $type) ?: [];
    }

    private function normalizeDomain(string $value): string
    {
        $value = strtolower(trim($value));
        $host = parse_url(str_contains($value, '://') ? $value : 'https://' . $value, PHP_URL_HOST);
        $host = strtolower(trim((string) $host, '. '));

        if (function_exists('idn_to_ascii')) {
            $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: '';
        }

        if ($host === ''
            || strlen($host) > 253
            || ! preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)
            || filter_var($host, FILTER_VALIDATE_IP)
            || in_array($host, config('domains.platform_hosts', []), true)
        ) {
            throw ValidationException::withMessages(['domain' => 'Enter a valid school-owned domain such as portal.yourschool.com.']);
        }

        return $host;
    }
}
