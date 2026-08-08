<?php

namespace App\Services;

use App\Models\SalesRepStatusEvent;
use App\Models\SalesRepresentative;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class SalesRepresentativeActivityService
{
    public function snapshot(SalesRepresentative $representative): array
    {
        $representative->loadMissing(['user', 'assignments']);

        $now = now();
        $loginBaseline = $this->latestDate([
            $representative->user?->last_login_at,
            $representative->reactivated_at,
            $representative->joined_at,
            $representative->user?->created_at,
            $representative->created_at,
        ]);
        $lastSchoolRegisteredAt = $representative->assignments
            ->where('stage', 'converted')
            ->whereNotNull('school_id')
            ->max('converted_at');
        $schoolActivityBaseline = $this->latestDate([
            $lastSchoolRegisteredAt,
            $representative->reactivated_at,
            $representative->joined_at,
            $representative->created_at,
        ]);

        $loginWarning = $loginBaseline?->lte($now->copy()->subMonthsNoOverflow(3)) ?? false;
        $loginDisable = $loginBaseline?->lte($now->copy()->subYear()) ?? false;
        $dormant = $schoolActivityBaseline?->lte($now->copy()->subYear()) ?? false;
        $autoDisabled = $representative->status === 'inactive'
            && $representative->auto_disabled_at !== null;

        $flags = [];

        if ($loginWarning && ! $loginDisable) {
            $flags[] = 'login_inactive_3_months';
        }

        if ($dormant) {
            $flags[] = 'no_school_registered_1_year';
        }

        if ($loginDisable || $autoDisabled) {
            $flags[] = 'login_inactive_1_year';
        }

        return [
            'health_status' => $autoDisabled ? 'disabled' : ($loginDisable ? 'critical' : ($flags ? 'warning' : 'healthy')),
            'activity_flags' => $flags,
            'last_login_at' => $representative->user?->last_login_at?->toDateTimeString(),
            'login_activity_baseline_at' => $loginBaseline?->toDateTimeString(),
            'last_school_registered_at' => $lastSchoolRegisteredAt?->toDateTimeString(),
            'login_inactive_3_months' => $loginWarning,
            'login_inactive_1_year' => $loginDisable || $autoDisabled,
            'dormant_no_school_1_year' => $dormant,
            'auto_disabled_at' => $representative->auto_disabled_at?->toDateTimeString(),
            'can_reactivate' => $representative->status === 'inactive' && $representative->auto_disabled_at !== null,
        ];
    }

    public function enforceAll(): array
    {
        $disabled = 0;
        $flagged = 0;

        SalesRepresentative::query()
            ->with(['user', 'assignments'])
            ->chunkById(100, function ($representatives) use (&$disabled, &$flagged) {
                foreach ($representatives as $representative) {
                    $snapshot = $this->snapshot($representative);

                    if ($snapshot['activity_flags']) {
                        $flagged++;
                    }

                    if ($representative->status === 'active' && $snapshot['login_inactive_1_year']) {
                        $this->disableForLoginInactivity($representative);
                        $disabled++;
                    }
                }
            });

        return ['flagged' => $flagged, 'disabled' => $disabled];
    }

    public function reactivate(SalesRepresentative $representative, ?User $actor = null): SalesRepresentative
    {
        return DB::transaction(function () use ($representative, $actor) {
            $oldStatus = $representative->status;

            $representative->update([
                'status' => 'active',
                'status_reason' => null,
                'status_changed_at' => now(),
                'reactivated_at' => now(),
                'auto_disabled_at' => null,
            ]);
            $representative->user()->update(['status' => 1]);

            SalesRepStatusEvent::create([
                'sales_representative_id' => $representative->id,
                'changed_by' => $actor?->id,
                'old_status' => $oldStatus,
                'new_status' => 'active',
                'reason' => 'Reactivated by Super Admin after inactivity review.',
                'metadata' => ['source' => 'superadmin_reactivation'],
            ]);

            return $representative->refresh()->load(['user', 'assignments', 'commissions']);
        });
    }

    private function disableForLoginInactivity(SalesRepresentative $representative): void
    {
        DB::transaction(function () use ($representative) {
            $representative->update([
                'status' => 'inactive',
                'status_reason' => 'Automatically disabled after one year without login.',
                'status_changed_at' => now(),
                'auto_disabled_at' => now(),
            ]);
            $representative->user()->update(['status' => 0]);

            SalesRepStatusEvent::create([
                'sales_representative_id' => $representative->id,
                'changed_by' => null,
                'old_status' => 'active',
                'new_status' => 'inactive',
                'reason' => 'Automatically disabled after one year without login.',
                'metadata' => ['source' => 'sales_activity_policy'],
            ]);
        });
    }

    private function latestDate(array $dates): ?CarbonInterface
    {
        return collect($dates)
            ->filter()
            ->sortByDesc(fn ($date) => $date->getTimestamp())
            ->first();
    }
}
