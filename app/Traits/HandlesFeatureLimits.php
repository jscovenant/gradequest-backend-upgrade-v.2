<?php

namespace App\Traits;

use Carbon\Carbon;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

trait HandlesFeatureLimits
{
    /**
     * Check if the user's subscription allows a specific feature
     * and validate student/teacher limits when applicable.
     */
    public function checkFeatureLimit(Model $user, string $featureKey): array
    {
        /**
         * 1️⃣ Get the SCHOOL ADMIN (subscription owner)
         * --------------------------------------------
         * Every Parent and Student belongs to a school ID.
         * The Admin of that school is the one who owns the subscription.
         */
        $schoolAdmin = User::where('school_id', $user->school_id)
            ->where('role', 'Admin')   // FIXED: removed school_owner
            ->first();

        if (!$schoolAdmin) {
            return $this->deny('no_admin', 'School administrator not found.');
        }

        /**
         * 2️⃣ Get admin's active subscription
         */
        $subscription = $schoolAdmin->activeSubscription()
            ->with('plan')
            ->first();

        if (!$subscription) {
            return $this->deny('no_subscription', 'No active subscription found.');
        }

        /**
         * 3️⃣ Check expiry
         */
        if ($subscription->ends_at && Carbon::parse($subscription->ends_at)->isPast()) {
            return $this->deny('subscription_expired', 'Your subscription has expired.');
        }

        /**
         * 4️⃣ Validate plan exists
         */
        $plan = $subscription->plan;

        if (!$plan) {
            return $this->deny('no_plan', 'Subscription plan not found.');
        }

        /**
         * 5️⃣ Decode features (JSON → array)
         */
        $features = is_string($plan->features)
            ? json_decode($plan->features, true) ?? []
            : ($plan->features ?? []);

        /**
         * 6️⃣ Find requested feature by key
         */
        $feature = collect($features)->firstWhere('feature_key', $featureKey);

        if ($feature && isset($feature['is_enabled']) && !$feature['is_enabled']) {
            return $this->deny('feature_disabled', 'This feature is disabled in your plan.');
        }

        if (!$feature && count($features) > 0) {
            return $this->deny('feature_missing', 'This feature is not included in your plan.');
        }

        /**
         * 7️⃣ Check student limits
         */
        $maxStudents = (int) ($plan->max_students ?? 0);

        if ($maxStudents > 0) {
            $currentStudentCount = User::where('school_id', $user->school_id)
                ->where('role', 'student')
                ->count();

            if ($currentStudentCount >= $maxStudents) {
                return $this->deny(
                    'student_limit_reached',
                    'You have reached the maximum number of allowed students.',
                    ['limit' => $maxStudents, 'used' => $currentStudentCount]
                );
            }
        }

        /**
         * 8️⃣ Teachers limit (if needed)
         */
        $maxTeachers = (int) ($plan->max_teachers ?? 0);

        if ($maxTeachers > 0) {
            $currentTeacherCount = User::where('school_id', $user->school_id)
                ->where('role', 'teacher')
                ->count();

            if ($currentTeacherCount >= $maxTeachers) {
                return $this->deny(
                    'teacher_limit_reached',
                    'You have reached the maximum number of allowed teachers.',
                    ['limit' => $maxTeachers, 'used' => $currentTeacherCount]
                );
            }
        }

        /**
         * 9️⃣ All checks passed → Feature allowed
         */
        return [
            'allowed' => true,
            'message' => "Feature allowed under current plan.",
        ];
    }

    /**
     * Standardized deny response
     */
    protected function deny(string $reason, string $message, array $extra = []): array
    {
        return array_merge([
            'allowed' => false,
            'reason' => $reason,
            'message' => $message,
        ], $extra);
    }
}
