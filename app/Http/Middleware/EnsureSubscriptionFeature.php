<?php

namespace App\Http\Middleware;

use App\Services\SubscriptionGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSubscriptionFeature
{
    public function __construct(private readonly SubscriptionGate $subscriptionGate)
    {
    }

    public function handle(Request $request, Closure $next, string $featureKey, ?string $limitKey = null, int $amount = 1): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $result = $this->subscriptionGate->inspect($user, $featureKey, $limitKey, max(1, $amount));

        if (! $result['allowed']) {
            return response()->json([
                'message' => $result['message'],
                'reason' => $result['reason'],
                'subscription' => [
                    'feature_key' => $featureKey,
                    'limit_key' => $limitKey,
                    'limit' => $result['limit'] ?? null,
                    'used' => $result['used'] ?? null,
                    'requested' => $result['requested'] ?? null,
                ],
            ], $result['status'] ?? 403);
        }

        $request->attributes->set('subscription_gate', $result);

        return $next($request);
    }
}
