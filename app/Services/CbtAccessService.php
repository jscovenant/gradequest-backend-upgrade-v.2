<?php

namespace App\Services;

use App\Models\User;

class CbtAccessService
{
    public function ensureCanUse(User $user, string $mode = 'online'): void
    {
        if (strtolower((string) $user->role) === 'student') {
            $clearance = app(SchoolBillingService::class)->studentAcademicClearanceStatus((int) $user->school_id, (int) $user->id);
            if (!$clearance['allowed']) {
                abort(403, 'CBT Exam Access Locked: Term fee clearance is required for this student. Please contact the school administration.');
            }
        }
    }
}
