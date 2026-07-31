<?php

namespace App\Services;

use App\Models\User;

class CbtAccessService
{
    public function ensureCanUse(User $user, string $mode = 'online'): void
    {
        $feature = $mode === 'offline' ? 'cbt_offline' : 'cbt_online';

        $gate = app(SubscriptionGate::class)->inspect($user, $feature);

        abort_unless(
            $gate['allowed'] ?? false,
            (int) ($gate['status'] ?? 403),
            $gate['message'] ?? 'CBT is not available in your current package.'
        );
    }
}
