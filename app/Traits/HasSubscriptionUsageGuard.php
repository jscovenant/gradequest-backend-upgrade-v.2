<?php

namespace App\Traits;

use App\Exceptions\SubscriptionLimitExceededException;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;

/**
 * Apply to the school-owner (Admin) User model.
 *
 * Guards the app against excessive usage by comparing:
 *   - how many students (users with role = 'Student' AND status = 1) the school
 *     currently has, against
 *   - how many students the owner actually subscribed for, for
 *   - as long as the subscription's duration hasn't expired.
 *
 * Reuse this anywhere a module needs to check "is this school still within
 * what they paid for" — student creation, bulk imports, CSV uploads, exam
 * registration, result publishing, etc.
 */
trait HasSubscriptionUsageGuard
{
    /**
     * The role value used for student accounts in the users table.
     */
    protected function studentRole(): string
    {
        return 'Student';
    }

    /**
     * The value in the `status` column that means "active" (as opposed to
     * suspended/disabled/soft-deactivated students who shouldn't count
     * against the subscribed limit).
     */
    protected function activeStatusValue()
    {
        return 1;
    }

    /**
     * The school_id students are scoped by. Override on the model if the
     * owner's school id lives somewhere other than $this->school_id.
     */
    public function getSchoolIdForSubscriptionCheck()
    {
        return $this->school_id ?? $this->id;
    }

    /**
     * The owner's most recent subscription, with its plan loaded.
     */
    public function resolveGuardSubscription(): ?Subscription
    {
        return Subscription::with('plan')
            ->where('user_id', $this->id)
            ->latest('created_at')
            ->first();
    }

    /**
     * Whether the owner has a subscription that is both marked active
     * and still within its paid duration (ends_at not in the past).
     */
    public function isSubscriptionActive(): bool
    {
        $subscription = $this->resolveGuardSubscription();

        if (!$subscription || $subscription->status !== 'active') {
            return false;
        }

        if ($subscription->ends_at && Carbon::parse($subscription->ends_at)->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Whether the subscription's paid duration has lapsed, regardless of
     * whatever status is stored — useful for lazy/on-the-fly expiry checks.
     */
    public function isSubscriptionExpired(): bool
    {
        $subscription = $this->resolveGuardSubscription();

        if (!$subscription || !$subscription->ends_at) {
            return false;
        }

        return Carbon::parse($subscription->ends_at)->isPast();
    }

    /**
     * Days left on the current subscription. Null if there's no
     * subscription; 0 or negative once expired.
     */
    public function subscriptionDaysRemaining(): ?int
    {
        $subscription = $this->resolveGuardSubscription();

        if (!$subscription || !$subscription->ends_at) {
            return null;
        }

        return (int) Carbon::now()->diffInDays(Carbon::parse($subscription->ends_at), false);
    }

    /**
     * The number of students the owner actually paid/subscribed for.
     * Null means there's no subscription to check against.
     */
    public function subscribedStudentLimit(): ?int
    {
        $subscription = $this->resolveGuardSubscription();

        return $subscription?->number_of_students !== null
            ? (int) $subscription->number_of_students
            : null;
    }

    /**
     * How many students currently exist for this school with status = 1
     * (active). Suspended/disabled student accounts don't count.
     */
    public function activeStudentCount(): int
    {
        return User::where('school_id', $this->getSchoolIdForSubscriptionCheck())
            ->where('role', $this->studentRole())
            ->where('status', $this->activeStatusValue())
            ->count();
    }

    /**
     * How many more active students can be added before hitting the
     * subscribed limit. Null if there's no limit to check against.
     */
    public function remainingStudentSlots(): ?int
    {
        $limit = $this->subscribedStudentLimit();

        if ($limit === null) {
            return null;
        }

        return max(0, $limit - $this->activeStudentCount());
    }

    /**
     * Whether the school currently has more active students than the
     * subscribed plan allows.
     */
    public function hasExceededStudentLimit(): bool
    {
        $limit = $this->subscribedStudentLimit();

        if ($limit === null) {
            return false;
        }

        return $this->activeStudentCount() > $limit;
    }

    /**
     * Whether `$additional` more active students can be added without
     * breaching the subscribed limit. True if there's no subscription-based
     * limit at all (the caller may still want to block that case separately
     * via isSubscriptionActive()).
     */
    public function canAddStudents(int $additional = 1): bool
    {
        $limit = $this->subscribedStudentLimit();

        if ($limit === null) {
            return true;
        }

        return ($this->activeStudentCount() + $additional) <= $limit;
    }

    /**
     * The single check to call from any module before doing something that
     * should be gated behind an active, unexpired subscription (uploading
     * results, generating reports, sending bulk notifications, etc).
     *
     * Throws if there's no active subscription or it has expired.
     */
    public function assertSubscriptionActive(): void
    {
        if (!$this->isSubscriptionActive()) {
            throw new SubscriptionLimitExceededException(
                'Your subscription is not active or has expired. Please subscribe or renew to continue.'
            );
        }
    }

    /**
     * Throws if adding `$additional` students would exceed the subscribed
     * limit, or if the subscription itself is missing/expired. Use this
     * right before creating new student accounts, e.g.:
     *
     *   $schoolOwner->assertCanAddStudents();
     *   User::create([... 'role' => 'Student', 'status' => 1, ...]);
     */
    public function assertCanAddStudents(int $additional = 1): void
    {
        $this->assertSubscriptionActive();

        if (!$this->canAddStudents($additional)) {
            $limit = $this->subscribedStudentLimit();
            $current = $this->activeStudentCount();

            throw new SubscriptionLimitExceededException(
                "You've reached your student limit ({$current}/{$limit}). Upgrade your plan or increase your subscribed student count to add more."
            );
        }
    }

    /**
     * A single payload you can hand straight to a dashboard/API response —
     * everything a component needs to show usage vs. limit without each
     * module re-deriving it.
     */
    public function subscriptionUsageSummary(): array
    {
        $subscription = $this->resolveGuardSubscription();
        $limit = $this->subscribedStudentLimit();
        $current = $this->activeStudentCount();

        return [
            'has_subscription' => (bool) $subscription,
            'is_active' => $this->isSubscriptionActive(),
            'is_expired' => $this->isSubscriptionExpired(),
            'days_remaining' => $this->subscriptionDaysRemaining(),
            'plan_name' => $subscription?->plan?->name,
            'ends_at' => $subscription?->ends_at,
            'student_limit' => $limit,
            'active_student_count' => $current,
            'remaining_student_slots' => $this->remainingStudentSlots(),
            'has_exceeded_student_limit' => $this->hasExceededStudentLimit(),
        ];
    }
}