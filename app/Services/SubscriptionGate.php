<?php

namespace App\Services;

use App\Models\FeatureUsage;
use App\Models\SchoolBankAccount;
use App\Models\SchoolBillingSetting;
use App\Models\Subscription;
use App\Models\SubscriptionPlanFeature;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class SubscriptionGate
{
    private const CORE_FEATURES = [
        'student_management',
        'teacher_management',
        'result_management',
        'fee_management',
        'online_payment',
        'attendance_management',
        'parent_management',
        'bursar_management',
        'settings_management',
    ];

    public function inspect(User $user, string $featureKey, ?string $limitKey = null, int $amount = 1): array
    {
        if ($this->isPlatformUser($user)) {
            return $this->allow(null, null, null, null);
        }

        $owner = $this->resolveSchoolOwner($user);

        if (! $owner) {
            return $this->deny('school_owner_missing', 'School subscription owner was not found.', 403);
        }

        if ($this->isCoreFeature($featureKey) && $this->schoolHasOnlineCoreAccess((int) $owner->school_id)) {
            return $this->allow($owner, null, [
                'feature_key' => $this->normalizeFeatureKey($featureKey),
                'feature_name' => 'GradeQuest Core',
                'is_enabled' => true,
                'access_model' => 'online_transaction_fee',
            ], null);
        }

        $subscription = $this->activeSubscriptionFor($owner);

        if (! $subscription) {
            return $this->deny(
                'no_active_subscription',
                'No active subscription found. Set up online payments for Core access or subscribe to unlock this feature.',
                402
            );
        }

        if ($subscription->ends_at && Carbon::parse($subscription->ends_at)->isPast()) {
            if ($this->isCoreFeature($featureKey) && $this->schoolHasOnlineCoreAccess((int) $owner->school_id)) {
                return $this->allow($owner, null, [
                    'feature_key' => $this->normalizeFeatureKey($featureKey),
                    'feature_name' => 'GradeQuest Core',
                    'is_enabled' => true,
                    'access_model' => 'online_transaction_fee',
                ], null);
            }

            return $this->deny('subscription_expired', 'Your subscription has expired. Please renew to continue.', 402);
        }

        $plan = $subscription->plan;

        if (! $plan) {
            return $this->deny('plan_missing', 'Subscription plan was not found.', 402);
        }

        $feature = $this->featureConfig($subscription, $featureKey);

        if ($feature === null && $this->hasConfiguredFeatures($subscription)) {
            return $this->deny('feature_missing', 'This feature is not included in your current plan.', 403, [
                'feature_key' => $featureKey,
            ]);
        }

        if ($feature !== null && array_key_exists('is_enabled', $feature) && ! (bool) $feature['is_enabled']) {
            return $this->deny('feature_disabled', 'This feature is disabled in your current plan.', 403, [
                'feature_key' => $featureKey,
            ]);
        }

        $limitCheck = $this->inspectLimit($owner, $subscription, $featureKey, $feature, $limitKey, $amount);

        if (! $limitCheck['allowed']) {
            return $limitCheck;
        }

        return $this->allow($owner, $subscription, $feature, $limitCheck['usage'] ?? null);
    }

    public function recordUsage(User $user, string $featureKey, int $amount = 1): void
    {
        if ($this->isPlatformUser($user)) {
            return;
        }

        $owner = $this->resolveSchoolOwner($user);
        $subscription = $owner ? $this->activeSubscriptionFor($owner) : null;

        if (! $owner || ! $subscription) {
            return;
        }

        if (! Schema::hasTable('feature_usages')) {
            return;
        }

        FeatureUsage::query()->updateOrCreate(
            [
                'user_id' => $owner->id,
                'subscription_id' => $subscription->id,
                'feature_key' => $featureKey,
            ],
            []
        )->increment('used_count', $amount);
    }

    private function resolveSchoolOwner(User $user): ?User
    {
        if ($this->isSchoolAdmin($user)) {
            return $user;
        }

        if (! $user->school_id) {
            return null;
        }

        return User::query()
            ->forSchool((int) $user->school_id)
            ->withRole('admin')
            ->orderBy('id')
            ->first();
    }

    private function activeSubscriptionFor(User $owner): ?Subscription
    {
        return Subscription::with('plan')
            ->where('user_id', $owner->id)
            ->where('status', 'active')
            ->latest('created_at')
            ->first();
    }

    private function featureConfig(Subscription $subscription, string $featureKey): ?array
    {
        $candidateKeys = $this->candidateFeatureKeys($featureKey);
        $normalizedFeatureKey = $this->normalizeFeatureKey($featureKey);

        if ($normalizedFeatureKey === 'whatsapp_notifications' && (bool) ($subscription->plan?->whatsapp_enabled ?? false)) {
            return [
                'feature_key' => 'whatsapp_notifications',
                'feature_name' => 'WhatsApp Notifications',
                'is_enabled' => true,
                'limit_type' => 'usage',
                'limit_count' => (int) ($subscription->plan?->whatsapp_monthly_credits ?? 0),
            ];
        }

        $relationFeature = Schema::hasTable('subscription_plan_features')
            ? SubscriptionPlanFeature::query()
                ->where('subscription_plan_id', $subscription->subscription_plan_id)
                ->whereIn('feature_key', $candidateKeys)
                ->first()
            : null;

        if ($relationFeature) {
            return [
                'feature_key' => $relationFeature->feature_key,
                'feature_name' => $relationFeature->feature_name,
                'is_enabled' => (bool) $relationFeature->is_enabled,
                'limit_type' => $relationFeature->limit_type,
                'limit_count' => (int) $relationFeature->limit_count,
            ];
        }

        return $this->jsonFeatures($subscription)
            ->first(fn ($feature) => in_array($this->normalizeFeatureKey($feature['feature_key'] ?? ''), $candidateKeys, true));
    }

    private function hasConfiguredFeatures(Subscription $subscription): bool
    {
        return (Schema::hasTable('subscription_plan_features')
                && SubscriptionPlanFeature::query()
                    ->where('subscription_plan_id', $subscription->subscription_plan_id)
                    ->exists())
            || $this->jsonFeatures($subscription)->isNotEmpty();
    }

    private function jsonFeatures(Subscription $subscription): Collection
    {
        $features = $subscription->plan?->getAttribute('features') ?? [];

        $features = $this->decodeFeatures($features);

        return collect($features)
            ->filter(fn ($feature) => is_array($feature))
            ->map(function (array $feature) {
                if (isset($feature['feature_key'])) {
                    $feature['feature_key'] = $this->normalizeFeatureKey($feature['feature_key']);
                }

                if (array_key_exists('is_enabled', $feature)) {
                    $feature['is_enabled'] = (bool) $feature['is_enabled'];
                }

                return $feature;
            })
            ->values();
    }

    private function decodeFeatures(mixed $features): array
    {
        for ($attempt = 0; $attempt < 3 && is_string($features); $attempt++) {
            $decoded = json_decode($features, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return [];
            }

            $features = $decoded;
        }

        return is_array($features) ? $features : [];
    }

    private function candidateFeatureKeys(string $featureKey): array
    {
        $normalized = $this->normalizeFeatureKey($featureKey);

        $aliases = [
            'student_management' => ['student_management', 'support_student_management', 'students', 'add_student'],
            'teacher_management' => ['teacher_management', 'support_teacher_management', 'teachers', 'add_teacher'],
            'result_management' => ['result_management', 'support_result_management', 'results_upload', 'result_upload', 'support_results_upload', 'support_result_upload'],
            'fee_management' => ['fee_management', 'support_fee_management', 'school_fees', 'support_school_fees'],
            'finance_management' => ['finance_management', 'support_finance_management', 'finance', 'support_finance'],
            'attendance_management' => ['attendance_management', 'support_attendance_management', 'staff_attendance', 'support_staff_attendance'],
            'settings_management' => ['settings_management', 'support_settings_management', 'school_settings', 'support_school_settings'],
            'online_payment' => ['online_payment', 'support_online_payment', 'fee_management', 'support_fee_management'],
            'parent_management' => ['parent_management', 'support_parent_management'],
            'bursar_management' => ['bursar_management', 'support_bursar_management'],
            'whatsapp_notifications' => ['whatsapp_notifications', 'support_whatsapp_notifications', 'whatsapp', 'whatsapp_messages'],
            'cbt_online' => ['cbt_online', 'cbt', 'computer_based_test', 'online_cbt', 'support_cbt_online'],
            'cbt_offline' => ['cbt_offline', 'offline_cbt', 'lan_cbt', 'support_cbt_offline'],
            'report_card_designer' => ['report_card_designer', 'support_report_card_designer', 'custom_report_designer', 'result_template_designer'],
        ];

        return array_values(array_unique(array_map(
            fn ($key) => $this->normalizeFeatureKey($key),
            $aliases[$normalized] ?? [$normalized, 'support_' . $normalized]
        )));
    }

    private function normalizeFeatureKey(string $featureKey): string
    {
        $key = strtolower(trim($featureKey));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?: '';

        return trim($key, '_');
    }

    private function isCoreFeature(string $featureKey): bool
    {
        $candidateKeys = $this->candidateFeatureKeys($featureKey);

        return collect(self::CORE_FEATURES)
            ->map(fn ($key) => $this->normalizeFeatureKey($key))
            ->intersect($candidateKeys)
            ->isNotEmpty();
    }

    private function schoolHasOnlineCoreAccess(int $schoolId): bool
    {
        if ($schoolId <= 0) {
            return false;
        }

        $settings = SchoolBillingSetting::query()
            ->where('school_id', $schoolId)
            ->first();

        if (! $settings || $settings->payment_mode !== 'online') {
            return false;
        }

        return SchoolBankAccount::query()
            ->where('school_id', $schoolId)
            ->where('is_active', true)
            ->where('online_payment_enabled', true)
            ->whereNotNull('paystack_subaccount_code')
            ->exists();
    }

    private function inspectLimit(
        User $owner,
        Subscription $subscription,
        string $featureKey,
        ?array $feature,
        ?string $limitKey,
        int $amount
    ): array {
        $limitKey = $limitKey ?: $this->defaultLimitKey($featureKey);

        return match ($limitKey) {
            'students' => $this->inspectStudentLimit($owner, $subscription, $amount),
            'teachers' => $this->inspectTeacherLimit($owner, $subscription, $amount),
            'usage' => $this->inspectFeatureUsage($owner, $subscription, $featureKey, $feature, $amount),
            default => ['allowed' => true],
        };
    }

    private function inspectStudentLimit(User $owner, Subscription $subscription, int $amount): array
    {
        $limit = $this->studentLimitFor($subscription);

        if (! $limit || (int) $limit <= 0) {
            return ['allowed' => true];
        }

        $used = User::query()
            ->forSchool((int) $owner->school_id)
            ->withRole('student')
            ->where('status', 1)
            ->count();

        if (($used + $amount) > (int) $limit) {
            return $this->deny('student_limit_exceeded', "Student limit reached ({$used}/{$limit}). Upgrade your plan to add more students.", 429, [
                'limit_key' => 'students',
                'limit' => (int) $limit,
                'used' => $used,
                'requested' => $amount,
            ]);
        }

        return [
            'allowed' => true,
            'usage' => ['limit_key' => 'students', 'limit' => (int) $limit, 'used' => $used],
        ];
    }

    private function studentLimitFor(Subscription $subscription): ?int
    {
        $planLimit = $subscription->plan?->max_students;

        if ($planLimit === null || (int) $planLimit <= 0) {
            return null;
        }

        return (int) $planLimit;
    }

    private function inspectTeacherLimit(User $owner, Subscription $subscription, int $amount): array
    {
        $limit = $subscription->plan?->max_teachers;

        if (! $limit || (int) $limit <= 0) {
            return ['allowed' => true];
        }

        $used = User::query()
            ->forSchool((int) $owner->school_id)
            ->withRole('teacher')
            ->where('status', 1)
            ->count();

        if (($used + $amount) > (int) $limit) {
            return $this->deny('teacher_limit_exceeded', "Teacher limit reached ({$used}/{$limit}). Upgrade your plan to add more teachers.", 429, [
                'limit_key' => 'teachers',
                'limit' => (int) $limit,
                'used' => $used,
                'requested' => $amount,
            ]);
        }

        return [
            'allowed' => true,
            'usage' => ['limit_key' => 'teachers', 'limit' => (int) $limit, 'used' => $used],
        ];
    }

    private function inspectFeatureUsage(User $owner, Subscription $subscription, string $featureKey, ?array $feature, int $amount): array
    {
        $limit = (int) ($feature['limit_count'] ?? 0);

        if ($limit <= 0) {
            return ['allowed' => true];
        }

        if (! Schema::hasTable('feature_usages')) {
            return [
                'allowed' => true,
                'usage' => ['limit_key' => 'usage', 'limit' => $limit, 'used' => 0],
            ];
        }

        $usage = FeatureUsage::query()
            ->where('user_id', $owner->id)
            ->where('subscription_id', $subscription->id)
            ->where('feature_key', $featureKey)
            ->first();

        $used = (int) ($usage?->used_count ?? 0);

        if (($used + $amount) > $limit) {
            return $this->deny('feature_usage_limit_exceeded', "Feature usage limit reached ({$used}/{$limit}). Upgrade your plan to continue.", 429, [
                'feature_key' => $featureKey,
                'limit_key' => 'usage',
                'limit' => $limit,
                'used' => $used,
                'requested' => $amount,
            ]);
        }

        return [
            'allowed' => true,
            'usage' => ['limit_key' => 'usage', 'limit' => $limit, 'used' => $used],
        ];
    }

    private function defaultLimitKey(string $featureKey): ?string
    {
        return match ($featureKey) {
            'student_management', 'students', 'add_student' => 'students',
            'teacher_management', 'teachers', 'add_teacher' => 'teachers',
            default => null,
        };
    }

    private function isSchoolAdmin(User $user): bool
    {
        return strtolower(trim((string) $user->role)) === 'admin';
    }

    private function isPlatformUser(User $user): bool
    {
        $role = strtolower(str_replace(['-', '_', ' '], '', (string) $user->role));

        return in_array($role, [
            'superadmin',
            'platformadmin',
            'supportadmin',
            'salesadmin',
            'financeadmin',
        ], true);
    }

    private function allow(?User $owner, ?Subscription $subscription, ?array $feature, ?array $usage): array
    {
        return [
            'allowed' => true,
            'owner_id' => $owner?->id,
            'subscription_id' => $subscription?->id,
            'plan_name' => $subscription?->plan?->name,
            'feature' => $feature,
            'usage' => $usage,
        ];
    }

    private function deny(string $reason, string $message, int $status, array $extra = []): array
    {
        return array_merge([
            'allowed' => false,
            'reason' => $reason,
            'message' => $message,
            'status' => $status,
        ], $extra);
    }
}
