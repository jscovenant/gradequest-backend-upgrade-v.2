<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CorsMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');

        // Regex: allow https://gradequest.com.ng and any subdomain
        $allowedPattern = '/^https:\/\/([a-z0-9-]+\.)?gradequest\.com\.ng$/';

        // Explicitly allow localhost + root domains
        $allowedOrigins = [
            'http://localhost:5173',
            'https://gradequest.com.ng', // root domain
            'http://gradequest.com.ng',  // in case http is needed (not recommended in prod)
        ];

        if (
            $origin &&
            (in_array($origin, $allowedOrigins) || preg_match($allowedPattern, $origin))
        ) {
            $requestedHeaders = $request->headers->get('Access-Control-Request-Headers');
            $allowHeaders = $requestedHeaders ?: 'Content-Type, Authorization, X-Requested-With, X-XSRF-TOKEN, X-CSRF-TOKEN';

            $corsHeaders = [
                'Access-Control-Allow-Origin'      => $origin,
                'Access-Control-Allow-Methods'     => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
                'Access-Control-Allow-Headers'     => $allowHeaders,
                'Access-Control-Allow-Credentials' => 'true',
                'Vary'                             => 'Origin',
            ];

            // If it's a preflight request, return immediately
            if ($request->getMethod() === 'OPTIONS') {
                return response()->noContent(204)->withHeaders($corsHeaders);
            }

            // Otherwise attach headers to actual response
            $response = $next($request);
            foreach ($corsHeaders as $key => $value) {
                $response->headers->set($key, $value);
            }
            return $response;
        }

        // If origin not allowed, still handle OPTIONS to prevent browser error
        if ($request->getMethod() === 'OPTIONS') {
            return response()->noContent(204);
        }

        return $next($request);
    }
}
