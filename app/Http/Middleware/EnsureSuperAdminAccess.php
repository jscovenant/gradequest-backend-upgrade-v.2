<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdminAccess
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user || ! method_exists($user, 'isSuperAdminUser') || ! $user->isSuperAdminUser()) {
            return response()->json(['message' => 'Access denied. This area is restricted to platform administrators.'], 403);
        }

        if ($permissions && ! collect($permissions)->some(fn ($permission) => $user->hasSuperAdminPermission($permission))) {
            return response()->json(['message' => 'Access denied. Your administrator account is not permitted to perform this action.'], 403);
        }

        return $next($request);
    }
}
