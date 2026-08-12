<?php

namespace Tests\Feature;

use App\Models\SchoolSetting;
use App\Models\SubPayment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\AcademicSession;
use App\Models\Term;
use App\Models\User;
use App\Services\SchoolBillingService;
use App\Services\SubscriptionGate;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionFeatureGateTest extends TestCase
{
    use DatabaseTransactions;

    public function test_staff_attendance_is_in_core_while_whatsapp_requires_gradequest_plus(): void
    {
        [, $admin] = $this->createSchoolWithAdmin('Core Restricted Features School');
        $plan = $this->createSubscription($admin, features: [
            ['feature_key' => 'support_staff_attendance', 'is_enabled' => true],
            ['feature_key' => 'whatsapp_notifications', 'is_enabled' => true],
        ]);
        $plan->forceFill(['name' => 'GradeQuest Core', 'whatsapp_enabled' => true])->save();

        $gate = app(SubscriptionGate::class);

        $this->assertTrue($gate->inspect($admin, 'staff_attendance')['allowed']);
        $this->assertSame('gradequest_plus_required', $gate->inspect($admin, 'whatsapp_notifications')['reason']);

        $plan->forceFill(['name' => 'GradeQuest Plus'])->save();

        $this->assertTrue($gate->inspect($admin, 'whatsapp_notifications')['allowed']);
    }

    public function test_student_creation_requires_active_subscription(): void
    {
        [, $admin] = $this->createSchoolWithAdmin('No Subscription School');

        Sanctum::actingAs($admin);

        $this->postJson('/api/students/store', [])
            ->assertStatus(402)
            ->assertJsonPath('reason', 'no_active_subscription');
    }

    public function test_student_creation_ignores_legacy_purchased_student_count_limit(): void
    {
        [$school, $admin] = $this->createSchoolWithAdmin('Limited School');

        $this->createSubscription($admin, numberOfStudents: 1, maxStudents: 0);
        $this->createSchoolUser($school, 'Student');

        Sanctum::actingAs($admin);

        $this->postJson('/api/students/store', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['firstname']);
    }

    public function test_plan_student_limit_is_hard_cap_even_when_subscription_student_count_is_higher(): void
    {
        [$school, $admin] = $this->createSchoolWithAdmin('Hard Cap School');

        $this->createSubscription($admin, numberOfStudents: 200, maxStudents: 4);

        for ($i = 0; $i < 4; $i++) {
            $this->createSchoolUser($school, 'Student');
        }

        Sanctum::actingAs($admin);

        $this->postJson('/api/students/store', [])
            ->assertStatus(429)
            ->assertJsonPath('reason', 'student_limit_exceeded')
            ->assertJsonPath('subscription.limit', 4)
            ->assertJsonPath('subscription.used', 4);
    }

    public function test_feature_gate_accepts_existing_support_prefixed_plan_keys(): void
    {
        [, $admin] = $this->createSchoolWithAdmin('Support Key School');

        $this->createSubscription($admin, numberOfStudents: 10, features: json_encode([
            [
                'feature_name' => 'Student Management',
                'feature_key' => 'support_student_management',
                'is_enabled' => 1,
            ],
        ]));

        Sanctum::actingAs($admin);

        $this->postJson('/api/students/store', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['firstname']);
    }

    public function test_result_entry_requires_result_feature_when_plan_features_are_configured(): void
    {
        [, $admin] = $this->createSchoolWithAdmin('Feature School');

        $this->createSubscription($admin, features: [
            [
                'feature_key' => 'student_management',
                'feature_name' => 'Student Management',
                'is_enabled' => true,
            ],
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/results/store', [])
            ->assertForbidden()
            ->assertJsonPath('reason', 'feature_missing');
    }

    public function test_wallet_subscription_blocks_same_active_package_renewal(): void
    {
        [$school, $admin] = $this->createSchoolWithAdmin('Duplicate Package School');
        $plan = $this->createSubscription($admin);

        $this->createSchoolUser($school, 'Student');
        $this->createWallet($admin, 100000);

        Sanctum::actingAs($admin);

        $currentPlanOption = collect($this->getJson('/api/subscription/plans')->assertOk()->json())
            ->firstWhere('id', $plan->id);
        $this->assertFalse($currentPlanOption['can_select']);
        $this->assertStringContainsString('still active', $currentPlanOption['disabled_reason']);

        $this->postJson('/api/payment/wallet-charge', [
            'subscription_plan_id' => $plan->id,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'This school already has an active subscription on this package. You can renew this same package after it expires, or upgrade to a higher package now.');
    }

    public function test_wallet_upgrade_applies_unused_package_credit(): void
    {
        Mail::fake();

        [$school, $admin] = $this->createSchoolWithAdmin('Upgrade Credit School');
        $basic = $this->createSubscription($admin, maxStudents: 100);
        $basic->forceFill(['price_per_student' => 1000, 'price' => 1000, 'duration_in_days' => 30])->save();

        $subscription = Subscription::where('user_id', $admin->id)->firstOrFail();
        $subscription->forceFill([
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->addDays(20),
            'billing_cycle_count' => 1,
        ])->save();

        $premium = SubscriptionPlan::create([
            'name' => 'Premium ' . Str::random(8),
            'price' => 2000,
            'price_per_student' => 2000,
            'duration_in_days' => 30,
            'billing_interval' => 'term',
            'description' => 'Premium test plan',
            'max_students' => 100,
            'max_teachers' => 20,
            'is_active' => true,
            'currency' => 'NGN',
            'features' => [],
        ]);

        $this->createSchoolUser($school, 'Student');
        $this->createWallet($admin, 100000);

        SubPayment::create([
            'user_id' => $admin->id,
            'subscription_plan_id' => $basic->id,
            'reference' => 'BASIC-' . Str::random(10),
            'amount' => 1000,
            'status' => 'successful',
            'channel' => 'wallet',
            'starts_at' => now()->subDays(10),
        ]);

        Sanctum::actingAs($admin);

        $upgradeOption = collect($this->getJson('/api/subscription/plans')->assertOk()->json())
            ->firstWhere('id', $premium->id);
        $this->assertTrue($upgradeOption['can_select']);
        $this->assertSame('upgrade', $upgradeOption['subscription_action']);
        $this->assertSame(666.67, $upgradeOption['upgrade_credit_amount']);
        $this->assertSame(20, $upgradeOption['carried_days']);

        $this->postJson('/api/payment/wallet-charge', [
            'subscription_plan_id' => $premium->id,
        ])->assertOk()
            ->assertJsonPath('quote.action', 'upgrade')
            ->assertJsonPath('quote.upgrade_credit_amount', 666.67)
            ->assertJsonPath('quote.payable_amount', 1333.33)
            ->assertJsonPath('quote.remaining_days', 20)
            ->assertJsonPath('quote.new_package_days', 30);

        $upgraded = Subscription::where('user_id', $admin->id)->firstOrFail();
        $this->assertSame(50, (int) now()->startOfDay()->diffInDays($upgraded->ends_at->copy()->startOfDay()));
    }

    public function test_new_school_without_subscription_has_no_gradequest_invoice_amount_but_can_view_plans(): void
    {
        [$school, $admin] = $this->createSchoolWithAdmin('Fresh Checkout School');

        $this->createSchoolUser($school, 'Student');
        $this->createSchoolUser($school, 'Student');

        SubscriptionPlan::create([
            'name' => 'Starter ' . Str::random(8),
            'price' => 1000,
            'price_per_student' => 1000,
            'duration_in_days' => 30,
            'billing_interval' => 'term',
            'description' => 'Starter plan',
            'max_students' => 100,
            'max_teachers' => 10,
            'is_active' => true,
            'currency' => 'NGN',
            'features' => [],
        ]);

        $dashboard = app(SchoolBillingService::class)->dashboard($school->id);

        $this->assertNull($dashboard['package']);
        $this->assertSame(0, $dashboard['price_per_student']);
        $this->assertSame(0, $dashboard['current_invoice_amount']);
        $this->assertNull($dashboard['invoice']);

        Sanctum::actingAs($admin);

        $this->getJson('/api/user/subscription/details')
            ->assertOk()
            ->assertJsonPath('status', 'None');

        $this->getJson('/api/subscription/plans')
            ->assertOk()
            ->assertJsonFragment([
                'active_students' => 2,
                'current_amount' => 2000,
            ]);
    }

    public function test_first_time_checkout_can_set_renewal_source_without_existing_subscription(): void
    {
        [, $admin] = $this->createSchoolWithAdmin('Fresh Renewal Source School');

        Sanctum::actingAs($admin);

        $this->postJson('/api/subscription/renewal-source', [
            'source' => 'paystack',
        ])->assertOk()
            ->assertJsonPath('has_subscription', false)
            ->assertJsonPath('source', 'paystack');
    }

    public function test_successful_subscription_payment_covers_current_billing_without_invoice_row(): void
    {
        [$school, $admin] = $this->createSchoolWithAdmin('Paid Billing Dashboard School');
        $plan = $this->createSubscription($admin, maxStudents: 100);
        $plan->forceFill(['price_per_student' => 1000, 'price' => 1000])->save();

        $this->createCurrentPeriod($school);

        for ($i = 0; $i < 4; $i++) {
            $this->createSchoolUser($school, 'Student');
        }

        SubPayment::create([
            'user_id' => $admin->id,
            'subscription_plan_id' => $plan->id,
            'reference' => 'PAID-' . Str::random(10),
            'amount' => 4000,
            'status' => 'successful',
            'channel' => 'card',
            'paid_at' => now(),
            'starts_at' => now(),
        ]);

        $dashboard = app(SchoolBillingService::class)->dashboard($school->id);

        $this->assertSame(4000.0, (float) $dashboard['current_invoice_amount']);
        $this->assertSame(4000.0, (float) $dashboard['subscription_paid_amount']);
        $this->assertSame(0.0, (float) $dashboard['outstanding_amount']);
        $this->assertSame(4, $dashboard['summary']['paid']);
        $this->assertSame(0, $dashboard['summary']['unpaid'] + $dashboard['summary']['grace']);
    }

    private function createSubscription(
        User $admin,
        ?int $numberOfStudents = null,
        array|string $features = [],
        int $maxStudents = 100
    ): SubscriptionPlan
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Test Plan ' . Str::random(8),
            'price' => 1000,
            'price_per_student' => 1000,
            'duration_in_days' => 30,
            'billing_interval' => 'term',
            'description' => 'Test subscription plan',
            'max_students' => $maxStudents,
            'max_teachers' => 10,
            'is_active' => true,
            'currency' => 'NGN',
            'features' => $features,
        ]);

        Subscription::create([
            'user_id' => $admin->id,
            'subscription_plan_id' => $plan->id,
            'number_of_students' => $numberOfStudents,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        return $plan;
    }

    private function createSchoolWithAdmin(string $name): array
    {
        $uuid = (string) Str::uuid();

        $school = SchoolSetting::create([
            'school_name' => $name . ' ' . $uuid,
            'address' => 'Test address',
            'phone' => '08000000000',
        ]);

        $admin = $this->createSchoolUser($school, 'Admin', [
            'firstname' => $name,
            'surname' => 'Admin',
            'email' => 'admin_' . $uuid . '@example.test',
            'phone' => '08000000000',
        ]);

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

    private function createCurrentPeriod(SchoolSetting $school): void
    {
        AcademicSession::query()->create([
            'name' => '2026/2027 ' . Str::random(8),
            'school_id' => $school->id,
            'status' => 'Active',
            'is_current' => 1,
        ]);

        Term::query()->create([
            'name' => 'First Term ' . Str::random(8),
            'school_id' => $school->id,
            'status' => 'Active',
            'sort_order' => 1,
        ]);
    }

    private function createWallet(User $admin, float $balance): void
    {
        \App\Models\Wallet::create([
            'user_id' => $admin->id,
            'school_id' => $admin->school_id,
            'balance' => $balance,
        ]);
    }
}
