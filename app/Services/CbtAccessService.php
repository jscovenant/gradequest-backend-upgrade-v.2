<?php

namespace App\Services;

use App\Models\User;

class CbtAccessService
{
    public function ensureCanUse(User $user, string $mode = 'online'): void
    {
        $schoolId = (int) ($user->school_id ?? 0);
        if ($schoolId > 0) {
            $policy = app(SchoolFeeAccessPolicyService::class)->policyForSchool($schoolId);
            $tier = $policy['active_edition_tier'] ?? 'standard_cbt';
            if ($tier === 'basic_result') {
                abort(403, 'CBT Examination is not included in the Basic Result Edition. Please upgrade to the Full CBT & AI Edition to access CBT exams.');
            }
        }

        if (strtolower((string) $user->role) === 'student') {
            $clearance = app(SchoolBillingService::class)->studentAcademicClearanceStatus($schoolId, (int) $user->id);
            if (!$clearance['allowed']) {
                abort(403, 'CBT Exam Access Locked: Term fee clearance is required for this student. Please contact the school administration.');
            }
        }
    }
}
