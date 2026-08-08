<?php

namespace Tests\Unit;

use App\Models\SalesRepAssignment;
use App\Models\SalesRepresentative;
use App\Models\User;
use App\Services\SalesRepresentativeActivityService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class SalesRepresentativeActivityServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_flags_three_month_login_inactivity_without_disabling(): void
    {
        Carbon::setTestNow('2026-08-08 12:00:00');
        $representative = $this->representative(lastLoginAt: '2026-04-01 10:00:00');

        $snapshot = (new SalesRepresentativeActivityService())->snapshot($representative);

        $this->assertTrue($snapshot['login_inactive_3_months']);
        $this->assertFalse($snapshot['login_inactive_1_year']);
        $this->assertSame('warning', $snapshot['health_status']);
    }

    public function test_it_marks_one_year_login_inactivity_as_critical(): void
    {
        Carbon::setTestNow('2026-08-08 12:00:00');
        $representative = $this->representative(lastLoginAt: '2025-08-01 10:00:00');

        $snapshot = (new SalesRepresentativeActivityService())->snapshot($representative);

        $this->assertTrue($snapshot['login_inactive_1_year']);
        $this->assertContains('login_inactive_1_year', $snapshot['activity_flags']);
        $this->assertSame('critical', $snapshot['health_status']);
    }

    public function test_it_flags_a_representative_with_no_school_registration_for_one_year(): void
    {
        Carbon::setTestNow('2026-08-08 12:00:00');
        $representative = $this->representative(lastLoginAt: '2026-08-01 10:00:00');
        $representative->setRelation('assignments', new Collection([
            new SalesRepAssignment([
                'stage' => 'converted',
                'school_id' => 10,
                'converted_at' => Carbon::parse('2025-07-01 10:00:00'),
            ]),
        ]));

        $snapshot = (new SalesRepresentativeActivityService())->snapshot($representative);

        $this->assertTrue($snapshot['dormant_no_school_1_year']);
        $this->assertContains('no_school_registered_1_year', $snapshot['activity_flags']);
    }

    private function representative(string $lastLoginAt): SalesRepresentative
    {
        $representative = new SalesRepresentative([
            'status' => 'active',
            'joined_at' => '2024-01-01',
        ]);
        $representative->created_at = Carbon::parse('2024-01-01');
        $representative->setRelation('user', new User([
            'last_login_at' => Carbon::parse($lastLoginAt),
        ]));
        $representative->setRelation('assignments', new Collection());

        return $representative;
    }
}
