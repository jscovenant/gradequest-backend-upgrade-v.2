<?php

namespace App\Http\Middleware;

use App\Models\CbtAccessKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures the school has an active CBT connection before allowing CBT actions.
 */
class EnsureCbtConnected
{
    public function handle(Request $request, Closure $next)
    {
        $school = $request->user()?->school;

        if (!$school) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        $key = CbtAccessKey::where('school_id', $school->id)->connected()->first();

        if (!$key) {
            return response()->json([
                'error'  => 'Your school is not connected to the CBT platform.',
                'action' => 'Visit Settings > CBT Integration to connect.',
            ], 403);
        }

        // Inject key into request for downstream use
        $request->merge(['_cbt_key' => $key]);

        return $next($request);
    }
}
