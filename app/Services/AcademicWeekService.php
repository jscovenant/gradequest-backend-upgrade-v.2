<?php

namespace App\Services;

use App\Models\AcademicSession;
use Carbon\CarbonImmutable;

class AcademicWeekService
{
    public function contextForSchool(int $schoolId, ?string $date = null): array
    {
        $session = AcademicSession::query()
            ->where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->where(function ($query) {
                $query->where('is_current', true)->orWhere('status', 'Active');
            })
            ->orderByDesc('is_current')
            ->orderByDesc('id')
            ->first();

        if (! $session?->start_date || ! $session?->end_date) {
            return ['configured' => false, 'session' => $session?->name, 'weeks' => [], 'current_week' => null];
        }

        $start = CarbonImmutable::parse($session->start_date)->startOfDay();
        $end = CarbonImmutable::parse($session->end_date)->startOfDay();
        $target = CarbonImmutable::parse($date ?: now()->toDateString())->startOfDay();
        $weeks = [];
        $cursor = $start;
        $number = 1;

        while ($cursor->lte($end)) {
            $weekEnd = $cursor->addDays(6)->min($end);
            $weeks[] = [
                'number' => $number,
                'start_date' => $cursor->toDateString(),
                'end_date' => $weekEnd->toDateString(),
                'label' => "Week {$number}",
            ];
            $cursor = $weekEnd->addDay();
            $number++;
        }

        $current = collect($weeks)->first(
            fn (array $week) => $target->betweenIncluded($week['start_date'], $week['end_date'])
        );

        return [
            'configured' => true,
            'session' => $session->name,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'weeks' => $weeks,
            'current_week' => $current,
        ];
    }
}
