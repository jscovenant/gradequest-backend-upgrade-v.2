<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\SchoolSetting;
use App\Models\SchoolTermsAcceptance;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SchoolTermsAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_accept_current_terms_for_the_school(): void
    {
        [$school, $admin] = $this->createSchoolAndUser();
        Sanctum::actingAs($admin);

        $this->postJson('/api/accept-terms', ['accepted' => true])
            ->assertOk()
            ->assertJsonPath('terms_accepted', true)
            ->assertJsonPath('terms_version', SchoolTermsAcceptance::CURRENT_VERSION);

        $this->assertDatabaseHas('school_terms_acceptances', [
            'school_id' => $school->id,
            'accepted_by' => $admin->id,
            'terms_version' => SchoolTermsAcceptance::CURRENT_VERSION,
        ]);
    }

    public function test_activation_is_rejected_until_current_terms_are_accepted(): void
    {
        [$school, $admin] = $this->createSchoolAndUser([
            'email_verified_at' => now(),
            'bonus_given' => false,
        ]);

        AcademicSession::create(['school_id' => $school->id, 'name' => '2026/2027', 'status' => 'Active']);
        foreach (['First Term', 'Second Term', 'Third Term'] as $name) {
            Term::create(['school_id' => $school->id, 'name' => $name, 'status' => 'Inactive']);
        }

        Sanctum::actingAs($admin);

        $this->postJson('/api/activate-bonus')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Complete activation steps first');
    }

    public function test_non_admin_cannot_accept_terms_for_a_school(): void
    {
        [, $teacher] = $this->createSchoolAndUser(['role' => 'Teacher']);
        Sanctum::actingAs($teacher);

        $this->postJson('/api/accept-terms', ['accepted' => true])->assertForbidden();
    }

    private function createSchoolAndUser(array $overrides = []): array
    {
        $school = SchoolSetting::create(['school_name' => 'Test School']);
        $user = User::create(array_merge([
            'firstname' => 'School',
            'surname' => 'Admin',
            'email' => uniqid('terms_', true) . '@example.test',
            'password' => Hash::make('Password1!'),
            'phone' => '08000000000',
            'role' => 'Admin',
            'status' => 1,
            'school_id' => $school->id,
            'reg_no' => 'A' . random_int(100000, 999999),
        ], $overrides));

        $school->forceFill(['user_id' => $user->id])->save();

        return [$school, $user];
    }
}
