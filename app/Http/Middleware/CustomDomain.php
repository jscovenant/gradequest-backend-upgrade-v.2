<?php

namespace App\Http\Middleware;

use App\Models\SchoolSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CustomDomain
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost(); 
        $school = SchoolSetting::where('custom_domain', $host)->first();

        if (!$school) {
            abort(403, "Invalid domain");
        }
    
        app()->instance('school', $school);

        return $next($request);
    }
}
