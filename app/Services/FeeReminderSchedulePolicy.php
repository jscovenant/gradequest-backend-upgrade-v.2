<?php

namespace App\Services;

use Carbon\CarbonInterface;

class FeeReminderSchedulePolicy
{
    public function maxReached(int $sentCount, int $maxCount): bool
    {
        return $maxCount > 0 && $sentCount >= $maxCount;
    }

    public function intervalHasElapsed(?CarbonInterface $lastSentAt, int $intervalDays, CarbonInterface $now): bool
    {
        return ! $lastSentAt || $now->greaterThanOrEqualTo(
            $lastSentAt->copy()->addDays(max(1, $intervalDays))
        );
    }

    public function isWithinQuietHours(?string $start, ?string $end, CarbonInterface $now): bool
    {
        if (! $start || ! $end || $start === $end) {
            return false;
        }

        $current = $now->format('H:i');

        return $start < $end
            ? $current >= $start && $current < $end
            : $current >= $start || $current < $end;
    }
}
