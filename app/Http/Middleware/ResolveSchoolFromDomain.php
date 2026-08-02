<?php

namespace App\Http\Middleware;

use App\Models\SchoolDomain;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveSchoolFromDomain
{
    // Hosts that are never school domains — skip resolution entirely
    private array $bypass = [
        'localhost',
        '127.0.0.1',
        '::1',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();

        // Skip for local development and your own production app domain
        if (
            $request->is('api/offline-cbt*')
            || in_array($host, $this->bypass, true)
            || filter_var($host, FILTER_VALIDATE_IP)
            || $host === config('app.domain')
        ) {
            return $next($request);
        }

        $schoolDomain = SchoolDomain::with('school')
            ->where('domain', $host)
            ->where('status', 'verified')
            ->first();

        if (!$schoolDomain) {
            abort(404, 'Domain not recognised.');
        }

        app()->instance('current_school', $schoolDomain->school);
        $request->attributes->set('school', $schoolDomain->school);

        return $next($request);
    }
}
