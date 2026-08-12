<?php

namespace App\Console\Commands;

use App\Models\SchoolDomain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ValidateCustomDomainDeployment extends Command
{
    protected $signature = 'domains:validate-deployment {--domain= : An active school domain to validate end-to-end} {--skip-dns : Skip live DNS lookups}';

    protected $description = 'Validate production configuration and DNS prerequisites for custom school domains';

    public function handle(): int
    {
        $checks = [
            'APP_ENV is production' => app()->environment('production'),
            'APP_DEBUG is disabled' => ! (bool) config('app.debug'),
            'APP_URL uses HTTPS' => str_starts_with((string) config('app.url'), 'https://'),
            'Frontend URL uses HTTPS' => str_starts_with((string) config('app.frontend_url'), 'https://'),
            'CNAME target is configured' => (string) config('domains.cname_target') !== '',
            'TLS ask secret is at least 32 characters' => strlen((string) config('domains.tls_ask_secret')) >= 32,
            'Health failure threshold is positive' => (int) config('domains.health_failure_threshold') >= 1,
            'Domain lifecycle migration is applied' => Schema::hasColumns('school_domains', [
                'ownership_verified_at',
                'routing_verified_at',
                'activated_at',
                'last_checked_at',
                'last_error',
                'consecutive_health_failures',
            ]),
        ];

        $targetIps = config('domains.target_ips', []);
        $checks['Target IPs do not contain documentation placeholders'] = ! collect($targetIps)
            ->contains(fn (string $ip) => str_starts_with($ip, '203.0.113.'));

        if (! $this->option('skip-dns')) {
            $target = (string) config('domains.cname_target');
            $checks['CNAME target resolves publicly'] = $target !== ''
                && count(@dns_get_record($target, DNS_A | DNS_AAAA) ?: []) > 0;
        }

        $domain = strtolower(trim((string) $this->option('domain'), '. '));
        if ($domain !== '') {
            $checks['Requested domain is active in GradeQuest'] = SchoolDomain::query()
                ->where('domain', $domain)
                ->where('status', 'active')
                ->exists();

            if (! $this->option('skip-dns')) {
                $target = strtolower(trim((string) config('domains.cname_target'), '. '));
                $records = @dns_get_record($domain, DNS_CNAME | DNS_A | DNS_AAAA) ?: [];
                $checks['Requested domain routes to GradeQuest'] = collect($records)->contains(function (array $record) use ($target, $targetIps) {
                    $cname = strtolower(trim((string) ($record['target'] ?? ''), '. '));
                    $address = (string) ($record['ip'] ?? $record['ipv6'] ?? '');

                    return ($target !== '' && $cname === $target)
                        || ($address !== '' && in_array($address, $targetIps, true));
                });
            }
        }

        foreach ($checks as $label => $passed) {
            $this->line(sprintf('%s %s', $passed ? '[PASS]' : '[FAIL]', $label));
        }

        if (in_array(false, $checks, true)) {
            $this->error('Custom-domain deployment validation failed.');

            return self::FAILURE;
        }

        $this->info('Custom-domain deployment validation passed.');

        return self::SUCCESS;
    }
}
