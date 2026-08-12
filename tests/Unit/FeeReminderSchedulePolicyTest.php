<?php

namespace Tests\Unit;

use App\Services\FeeReminderSchedulePolicy;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class FeeReminderSchedulePolicyTest extends TestCase
{
    private FeeReminderSchedulePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new FeeReminderSchedulePolicy();
    }

    public function test_it_enforces_finite_and_unlimited_maximums(): void
    {
        $this->assertFalse($this->policy->maxReached(5, 6));
        $this->assertTrue($this->policy->maxReached(6, 6));
        $this->assertFalse($this->policy->maxReached(100, 0));
    }

    public function test_it_enforces_the_configured_day_interval(): void
    {
        $lastSent = CarbonImmutable::parse('2026-08-01 09:00:00');

        $this->assertFalse($this->policy->intervalHasElapsed(
            $lastSent,
            5,
            CarbonImmutable::parse('2026-08-06 08:59:59')
        ));
        $this->assertTrue($this->policy->intervalHasElapsed(
            $lastSent,
            5,
            CarbonImmutable::parse('2026-08-06 09:00:00')
        ));
    }

    public function test_it_supports_daytime_and_overnight_quiet_hours(): void
    {
        $this->assertTrue($this->policy->isWithinQuietHours(
            '13:00',
            '15:00',
            CarbonImmutable::parse('2026-08-10 14:00:00')
        ));
        $this->assertTrue($this->policy->isWithinQuietHours(
            '22:00',
            '06:00',
            CarbonImmutable::parse('2026-08-10 23:30:00')
        ));
        $this->assertTrue($this->policy->isWithinQuietHours(
            '22:00',
            '06:00',
            CarbonImmutable::parse('2026-08-10 05:30:00')
        ));
        $this->assertFalse($this->policy->isWithinQuietHours(
            '22:00',
            '06:00',
            CarbonImmutable::parse('2026-08-10 12:00:00')
        ));
    }
}
