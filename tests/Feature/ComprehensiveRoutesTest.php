<?php

namespace Tests\Feature;

use App\Models\SchoolSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ComprehensiveRoutesTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): User
    {
        $school = SchoolSetting::create([
            'school_name' => 'SchoolProfit Test School',
            'address' => '123 Education Way',
            'phone' => '08012345678',
        ]);

        $admin = User::create([
            'name' => 'Super Admin',
            'username' => 'admin_' . Str::random(8),
            'firstname' => 'Super',
            'surname' => 'Admin',
            'email' => 'admin_' . Str::random(8) . '@schoolprofit.ng',
            'password' => Hash::make('password123'),
            'phone' => '08012345678',
            'role' => 'Admin',
            'status' => 1,
            'school_id' => $school->id,
            'reg_no' => 'ADM' . random_int(1000, 9999),
        ]);

        $school->forceFill(['user_id' => $admin->id])->save();

        return $admin;
    }

    public function test_public_platform_info_route_never_throws_500(): void
    {
        $response = $this->getJson('/api/public/platform-info');
        $this->assertNotEquals(500, $response->status(), 'Public platform-info returned 500: ' . $response->getContent());
    }

    public function test_login_route_validates_and_never_throws_500(): void
    {
        $response = $this->postJson('/api/login', [
            'identifier' => 'invalid_user',
            'password' => 'invalid_pass',
        ]);
        $this->assertNotEquals(500, $response->status(), 'Login route returned 500: ' . $response->getContent());
        $this->assertContains($response->status(), [401, 422]);
    }

    public function test_forgot_password_route_never_throws_500(): void
    {
        $response = $this->postJson('/api/forgot-password', [
            'email' => 'nonexistent@schoolprofit.ng',
        ]);
        $this->assertNotEquals(500, $response->status(), 'Forgot password returned 500: ' . $response->getContent());
    }

    public function test_public_sales_rep_register_never_throws_500(): void
    {
        $response = $this->postJson('/api/public/sales-representatives/register', []);
        $this->assertNotEquals(500, $response->status(), 'Sales rep register returned 500: ' . $response->getContent());
        $this->assertEquals(422, $response->status());
    }

    public function test_admin_core_routes_return_valid_responses_and_never_throw_500(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin);

        $routes = [
            '/api/schools/settings',
            '/api/sections',
            '/api/levels',
            '/api/departments',
            '/api/subjects',
            '/api/terms',
            '/api/academic-sessions',
            '/api/students',
            '/api/teachers',
            '/api/bursars',
            '/api/parents',
            '/api/fee-types',
            '/api/student-fees',
            '/api/financial-records',
            '/api/billing/overview',
            '/api/billing/settings',
            '/api/billing/invoices',
            '/api/school/bank-accounts',
            '/api/school-whatsapp/settings',
            '/api/hostels',
            '/api/transport/routes',
            '/api/support-tickets',
        ];

        foreach ($routes as $route) {
            $response = $this->getJson($route);
            $this->assertNotEquals(
                500,
                $response->status(),
                "Route " . $route . " threw internal server error 500: " . $response->getContent()
            );
        }
    }
}
