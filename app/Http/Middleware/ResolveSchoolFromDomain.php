<?php

namespace App\Http\Middleware;

use App\Models\SchoolDomain;
use App\Models\SchoolSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveSchoolFromDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $rawHost = strtolower(trim($request->getHost(), '. '));
        $host = preg_replace('/^www\./', '', $rawHost);

        if ($request->is('api/offline-cbt*')
            || in_array($rawHost, ['localhost', '127.0.0.1', '::1'], true)
            || filter_var($rawHost, FILTER_VALIDATE_IP)
            || in_array($rawHost, $this->platformHosts(), true)
            || in_array($host, $this->platformHosts(), true)
        ) {
            return $next($request);
        }

        $schoolDomain = SchoolDomain::query()
            ->with('school')
            ->where(function ($q) use ($rawHost, $host) {
                $q->where('domain', $rawHost)
                  ->orWhere('domain', $host);
            })
            ->where('status', 'active')
            ->first();

        $school = $schoolDomain?->school;

        if (! $school) {
            $school = SchoolSetting::query()
                ->where('custom_domain', $rawHost)
                ->orWhere('custom_domain', $host)
                ->first();
        }

        if (! $school) {
            abort(404, 'Domain not recognised.');
        }

        app()->instance('current_school', $school);
        $request->attributes->set('school', $school);

        return $next($request);
    }

    private function platformHosts(): array
    {
        $hosts = config('domains.platform_hosts', []);

        foreach ([config('app.url'), config('app.frontend_url')] as $url) {
            $parsed = parse_url((string) $url, PHP_URL_HOST);
            if ($parsed) {
                $hosts[] = strtolower($parsed);
            }
        }

        return array_values(array_unique(array_filter($hosts)));
    }
}
