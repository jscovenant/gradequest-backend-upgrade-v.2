<?php

namespace App\Traits;

use App\Models\User;
use App\Services\SubscriptionGate;
use Illuminate\Database\Eloquent\Model;

trait HandlesFeatureLimits
{
    /**
     * Check if the user's subscription allows a specific feature
     * and validate student/teacher limits when applicable.
     */
    public function checkFeatureLimit(Model $user, string $featureKey): array
    {
        if (! ($user instanceof User)) {
            return [
                'allowed' => true,
                'message' => 'Feature allowed.',
            ];
        }

        $gate = app(SubscriptionGate::class);
        $decision = $gate->inspect($user, $featureKey);

        return [
            'allowed' => (bool) ($decision['allowed'] ?? false),
            'reason' => $decision['reason'] ?? 'granted',
            'message' => $decision['message'] ?? 'Feature allowed.',
        ];
    }

    /**
     * Standardized deny response
     */
    protected function deny(string $reason, string $message, array $extra = []): array
    {
        return array_merge([
            'allowed' => false,
            'reason' => $reason,
            'message' => $message,
        ], $extra);
    }
}
