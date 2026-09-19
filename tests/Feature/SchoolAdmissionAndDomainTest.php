<?php

namespace Tests\Feature;

use App\Models\SchoolSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SchoolAdmissionAndDomainTest extends TestCase
{
    use RefreshDatabase;

    private function createSchoolWithUser(string $role = 'Admin'): array
    {
        $school = SchoolSetting::create([
            'school_name' => 'Kings College Lagos',
            'address' => 'Lagos Island, Lagos',
            'phone' => '08000000000',
            'email' => 'info@kingscollege.sch.ng',
        ]);

        $user = User::create([
            'firstname' => 'Test',
            'surname' => 'Admin',
            'email' => 'admin@kingscollege.sch.ng',
            'password' => Hash::make('password'),
            'phone' => '08000000001',
            'role' => $role,
            'status' => 1,
            'school_id' => $school->id,
            'reg_no' => 'ADM' . random_int(100000, 999999),
        ]);

        $school->forceFill(['user_id' => $user->id])->save();

        return [$school, $user];
    }

    public function test_can_fetch_public_school_website_info(): void
    {
        [$school] = $this->createSchoolWithUser();

        $response = $this->getJson('/api/public/school/' . $school->id);

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonStructure([
                'status',
                'school' => ['id', 'name'],
                'website',
                'admission',
                'classes',
            ]);
    }

    public function test_can_submit_public_admission_application_with_wema_virtual_account(): void
    {
        [$school] = $this->createSchoolWithUser();

        $payload = [
            'firstname' => 'Chinedu',
            'surname' => 'Eze',
            'gender' => 'Male',
            'applied_class_name' => 'JSS 1',
            'parent_name' => 'Chief Emeka Eze',
            'parent_phone' => '08031234567',
            'parent_email' => 'emeka@example.com',
            'parent_relationship' => 'Father',
        ];

        $response = $this->postJson('/api/public/admissions/submit/' . $school->id, $payload);

        $response->assertStatus(201)
            ->assertJsonPath('status', true)
            ->assertJsonStructure([
                'status',
                'application' => ['application_number', 'firstname', 'surname'],
                'payment' => ['reference', 'total_amount', 'bank_name', 'account_number'],
            ]);

        $this->assertDatabaseHas('school_admission_applications', [
            'firstname' => 'Chinedu',
            'surname' => 'Eze',
            'parent_phone' => '08031234567',
        ]);

        $this->assertDatabaseHas('school_admission_payments', [
            'gateway' => 'wema_alat',
            'school_id' => $school->id,
        ]);
    }

    public function test_can_check_domain_pricing_and_availability(): void
    {
        [, $admin] = $this->createSchoolWithUser();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/domain-orders/check', [
            'query' => 'kingscollege',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonStructure([
                'status',
                'suggestions',
                'server_ip',
            ]);
    }
}
