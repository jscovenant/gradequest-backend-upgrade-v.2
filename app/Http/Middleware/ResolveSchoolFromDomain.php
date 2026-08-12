<?php

namespace App\Http\Middleware;

use App\Models\SchoolDomain;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveSchoolFromDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower(trim($request->getHost(), '. '));

        if ($request->is('api/offline-cbt*')
            || in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || filter_var($host, FILTER_VALIDATE_IP)
            || in_array($host, $this->platformHosts(), true)
        ) {
            return $next($request);
        }

        $schoolDomain = SchoolDomain::query()
            ->with('school')
            ->where('domain', $host)
            ->where('status', 'active')
            ->first();

        if (! $schoolDomain?->school) {
            abort(404, 'Domain not recognised.');
        }

        app()->instance('current_school', $schoolDomain->school);
        $request->attributes->set('school', $schoolDomain->school);

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
