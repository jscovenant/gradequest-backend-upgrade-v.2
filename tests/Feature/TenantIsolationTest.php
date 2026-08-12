<?php

namespace Tests\Feature;

use App\Models\SchoolDomain;
use App\Models\SchoolSetting;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_legacy_result_endpoint_is_not_publicly_accessible(): void
    {
        $this->getJson('/api/result/1?class_id=1&term=First%20Term&session=2025%2F2026')
            ->assertUnauthorized();
    }

    public function test_school_owned_models_are_automatically_scoped_to_the_current_user_school(): void
    {
        [$schoolA, $adminA] = $this->createSchoolWithAdmin('School A');
        [$schoolB] = $this->createSchoolWithAdmin('School B');

        Section::withoutGlobalScope('school')->create([
            'name' => 'Nursery',
            'school_id' => $schoolA->id,
        ]);

        Section::withoutGlobalScope('school')->create([
            'name' => 'Primary',
            'school_id' => $schoolB->id,
        ]);

        Sanctum::actingAs($adminA);

        $response = $this->getJson('/api/sections');

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'Nursery']);
        $response->assertJsonMissing(['name' => 'Primary']);
    }

    public function test_school_user_cannot_access_another_schools_scoped_record_by_id(): void
    {
        [$schoolA, $adminA] = $this->createSchoolWithAdmin('School A');
        [$schoolB] = $this->createSchoolWithAdmin('School B');

        Section::withoutGlobalScope('school')->create([
            'name' => 'Junior',
            'school_id' => $schoolA->id,
        ]);

        $schoolBSection = Section::withoutGlobalScope('school')->create([
            'name' => 'Senior',
            'school_id' => $schoolB->id,
        ]);

        Sanctum::actingAs($adminA);

        $this->getJson('/api/sections/' . $schoolBSection->id)
            ->assertNotFound();
    }

    public function test_custom_domain_blocks_authenticated_user_from_another_school(): void
    {
        [$schoolA] = $this->createSchoolWithAdmin('School A');
        [, $adminB] = $this->createSchoolWithAdmin('School B');

        SchoolDomain::create([
            'school_id' => $schoolA->id,
            'domain' => 'school-a.test',
            'type' => 'custom',
            'status' => 'active',
            'verified_at' => now(),
            'ownership_verified_at' => now(),
            'routing_verified_at' => now(),
            'activated_at' => now(),
        ]);

        Sanctum::actingAs($adminB);

        $this->getJson('http://school-a.test/api/user')
            ->assertForbidden();
    }

    public function test_custom_domain_login_cannot_authenticate_a_user_from_another_school(): void
    {
        [$schoolA] = $this->createSchoolWithAdmin('School A');
        [, $adminB] = $this->createSchoolWithAdmin('School B');

        SchoolDomain::create([
            'school_id' => $schoolA->id,
            'domain' => 'login.school-a.test',
            'type' => 'custom',
            'status' => 'active',
            'verified_at' => now(),
            'ownership_verified_at' => now(),
            'routing_verified_at' => now(),
            'activated_at' => now(),
        ]);

        $this->postJson('http://login.school-a.test/api/login', [
            'identifier' => $adminB->email,
            'password' => 'password',
        ])->assertUnprocessable();
    }

    public function test_tls_ask_endpoint_allows_only_active_registered_domains(): void
    {
        config(['domains.tls_ask_secret' => 'test-domain-secret']);
        [$school] = $this->createSchoolWithAdmin('TLS School');

        SchoolDomain::create([
            'school_id' => $school->id,
            'domain' => 'portal.tls-school.test',
            'type' => 'custom',
            'status' => 'active',
            'verified_at' => now(),
            'ownership_verified_at' => now(),
            'routing_verified_at' => now(),
            'activated_at' => now(),
        ]);

        $this->get('http://127.0.0.1/api/internal/domains/tls-allowed?token=test-domain-secret&domain=portal.tls-school.test')
            ->assertNoContent();

        $this->get('http://127.0.0.1/api/internal/domains/tls-allowed?token=wrong&domain=portal.tls-school.test')
            ->assertForbidden();
    }

    public function test_school_admin_cannot_view_teacher_from_another_school(): void
    {
        [, $adminA] = $this->createSchoolWithAdmin('School A');
        [$schoolB] = $this->createSchoolWithAdmin('School B');

        $teacherB = $this->createSchoolUser($schoolB, 'Teacher');

        Sanctum::actingAs($adminA);

        $this->getJson('/api/teachers/view/' . $teacherB->id)
            ->assertNotFound();
    }

    public function test_school_admin_cannot_delete_parent_from_another_school(): void
    {
        [, $adminA] = $this->createSchoolWithAdmin('School A');
        [$schoolB] = $this->createSchoolWithAdmin('School B');

        $parentB = $this->createSchoolUser($schoolB, 'Parent');

        Sanctum::actingAs($adminA);

        $this->deleteJson('/api/delete-parent/' . $parentB->id)
            ->assertNotFound();
    }

    public function test_school_admin_cannot_update_bursar_from_another_school(): void
    {
        [, $adminA] = $this->createSchoolWithAdmin('School A');
        [$schoolB] = $this->createSchoolWithAdmin('School B');

        $bursarB = $this->createSchoolUser($schoolB, 'Bursar');

        Sanctum::actingAs($adminA);

        $this->putJson('/api/bursars/' . $bursarB->id, [
            'firstname' => 'Updated',
            'surname' => 'Bursar',
            'email' => 'updated_' . Str::random(12) . '@example.test',
        ])->assertNotFound();
    }

    public function test_school_admin_cannot_decrypt_student_password_from_another_school(): void
    {
        [, $adminA] = $this->createSchoolWithAdmin('School A');
        [$schoolB] = $this->createSchoolWithAdmin('School B');

        $studentB = $this->createSchoolUser($schoolB, 'Student', [
            'default_password' => 'secret-password',
        ]);

        Sanctum::actingAs($adminA);

        $this->postJson('/api/decrypt-password', [
            'user_id' => $studentB->id,
        ])->assertNotFound();
    }

    private function createSchoolWithAdmin(string $name): array
    {
        $uuid = (string) Str::uuid();

        $school = SchoolSetting::create([
            'school_name' => $name . ' ' . $uuid,
            'address' => 'Test address',
            'phone' => '08000000000',
        ]);

        $userData = [
            'firstname' => $name,
            'surname' => 'Admin',
            'email' => 'admin_' . $uuid . '@example.test',
            'password' => Hash::make('password'),
            'phone' => '08000000000',
            'role' => 'Admin',
            'status' => 1,
            'school_id' => $school->id,
            'reg_no' => 'A' . random_int(100000, 999999),
        ];

        if (Schema::hasColumn('users', 'name')) {
            $userData['name'] = $name . ' Admin';
        }

        if (Schema::hasColumn('users', 'username')) {
            $userData['username'] = 'admin_' . Str::random(12);
        }

        $admin = User::create($userData);

        $school->forceFill(['user_id' => $admin->id])->save();

        return [$school, $admin];
    }

    private function createSchoolUser(SchoolSetting $school, string $role, array $overrides = []): User
    {
        $uuid = (string) Str::uuid();

        $userData = array_merge([
            'firstname' => $role,
            'surname' => 'User',
            'email' => strtolower($role) . '_' . $uuid . '@example.test',
            'password' => Hash::make('password'),
            'phone' => '08000000001',
            'role' => $role,
            'status' => 1,
            'school_id' => $school->id,
            'reg_no' => strtoupper(substr($role, 0, 1)) . random_int(100000, 999999),
        ], $overrides);

        if (Schema::hasColumn('users', 'name') && ! isset($userData['name'])) {
            $userData['name'] = $role . ' User';
        }

        if (Schema::hasColumn('users', 'username') && ! isset($userData['username'])) {
            $userData['username'] = strtolower($role) . '_' . Str::random(12);
        }

        return User::create($userData);
    }
}
