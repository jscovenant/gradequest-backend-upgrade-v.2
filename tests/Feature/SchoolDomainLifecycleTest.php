<?php

namespace Tests\Feature;

use App\Models\SchoolDomain;
use App\Models\SchoolSetting;
use App\Models\User;
use App\Services\SchoolDomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SchoolDomainLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_school_administrators_can_manage_domains(): void
    {
        [$school] = $this->createSchoolWithUser('Admin');
        [, $teacher] = $this->createSchoolWithUser('Teacher', $school);
        Sanctum::actingAs($teacher);

        $this->getJson('/api/settings/domain')
            ->assertForbidden()
            ->assertJsonPath('message', 'Only a school administrator can manage the school domain.');
    }

    public function test_settings_are_loaded_from_the_authenticated_users_school_id(): void
    {
        [$school, $admin] = $this->createSchoolWithUser('Admin');
        $school->forceFill(['school_name' => 'Correct Tenant School'])->save();
        Sanctum::actingAs($admin);

        $this->getJson('/api/get-settings')
            ->assertOk()
            ->assertJsonPath('school_settings.schoolName', 'Correct Tenant School');
    }

    public function test_verified_domain_is_not_resolved_until_it_is_active(): void
    {
        [$school] = $this->createSchoolWithUser('Admin');
        SchoolDomain::create([
            'school_id' => $school->id,
            'domain' => 'verified-only.school.test',
            'status' => 'verified',
            'verification_token' => 'gradequest-verify=test',
            'verified_at' => now(),
            'ownership_verified_at' => now(),
        ]);

        $this->getJson('http://verified-only.school.test/api/frontend/subscription-plans')
            ->assertNotFound();
    }

    public function test_admin_can_complete_and_remove_the_full_domain_lifecycle(): void
    {
        [$school, $admin] = $this->createSchoolWithUser('Admin');
        $service = new FakeSchoolDomainService();
        $this->app->instance(SchoolDomainService::class, $service);
        Sanctum::actingAs($admin);

        $registered = $this->postJson('/api/settings/domain', ['domain' => 'Portal.Example-School.test']);
        $registered->assertCreated()->assertJsonPath('data.status', 'pending');
        $domainId = $registered->json('data.id');
        $token = $registered->json('data.verification_token');

        $service->records['_gradequest-verification.portal.example-school.test'] = [['txt' => $token]];
        $this->postJson('/api/settings/domain/verify', ['domain_id' => $domainId])
            ->assertOk()
            ->assertJsonPath('data.status', 'verified');

        $this->postJson('/api/settings/domain/activate', ['domain_id' => $domainId])
            ->assertUnprocessable();

        $service->records['portal.example-school.test'] = [['target' => 'domains.gradequest.com.ng']];
        $this->postJson('/api/settings/domain/activate', ['domain_id' => $domainId])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertSame('portal.example-school.test', $school->fresh()->custom_domain);

        $this->deleteJson('/api/settings/domain/' . $domainId)->assertOk();
        $this->assertDatabaseMissing('school_domains', ['id' => $domainId]);
        $this->assertNull($school->fresh()->custom_domain);
    }

    public function test_repeated_health_failures_disable_an_active_domain(): void
    {
        config(['domains.health_failure_threshold' => 3]);
        [$school] = $this->createSchoolWithUser('Admin');
        $domain = SchoolDomain::create([
            'school_id' => $school->id,
            'domain' => 'broken.school.test',
            'status' => 'active',
            'verification_token' => 'gradequest-verify=test',
        ]);
        $school->forceFill(['custom_domain' => $domain->domain])->save();
        $service = new FakeSchoolDomainService();

        $this->assertFalse($service->checkHealth($domain->fresh()));
        $this->assertSame('active', $domain->fresh()->status);
        $this->assertFalse($service->checkHealth($domain->fresh()));
        $this->assertFalse($service->checkHealth($domain->fresh()));

        $this->assertSame('disabled', $domain->fresh()->status);
        $this->assertSame(3, $domain->fresh()->consecutive_health_failures);
        $this->assertNull($school->fresh()->custom_domain);
    }

    private function createSchoolWithUser(string $role, ?SchoolSetting $school = null): array
    {
        $school ??= SchoolSetting::create([
            'school_name' => 'Domain Test School ' . uniqid(),
            'address' => 'Test address',
            'phone' => '08000000000',
        ]);

        $user = User::create([
            'firstname' => $role,
            'surname' => 'User',
            'email' => strtolower($role) . uniqid() . '@example.test',
            'password' => Hash::make('password'),
            'phone' => '08000000001',
            'role' => $role,
            'status' => 1,
            'school_id' => $school->id,
            'reg_no' => strtoupper(substr($role, 0, 1)) . random_int(100000, 999999),
        ]);

        if ($role === 'Admin' && ! $school->user_id) {
            $school->forceFill(['user_id' => $user->id])->save();
        }

        return [$school, $user];
    }
}

class FakeSchoolDomainService extends SchoolDomainService
{
    public array $records = [];

    protected function dnsRecords(string $host, int $type): array
    {
        return $this->records[$host] ?? [];
    }
}
