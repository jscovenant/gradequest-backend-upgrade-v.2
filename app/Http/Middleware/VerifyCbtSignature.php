<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies HMAC-SHA256 signature on requests coming FROM the CBT platform.
 * Prevents forged results or unauthorized webhook calls.
 */
class VerifyCbtSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-CBT-Signature');
        $timestamp = $request->header('X-CBT-Timestamp');

        if (!$signature || !$timestamp) {
            return response()->json(['error' => 'Missing authentication headers.'], 401);
        }

        // Prevent replay attacks — reject requests older than 5 minutes
        if (abs(now()->timestamp - (int) $timestamp) > 300) {
            return response()->json(['error' => 'Request has expired.'], 401);
        }

        $rawBody     = $request->getContent();
        $expected    = 'sha256=' . hash_hmac('sha256', $rawBody, config('cbt.signing_secret'));

        if (!hash_equals($expected, $signature)) {
            return response()->json(['error' => 'Signature verification failed.'], 403);
        }

        return $next($request);
    }
}
