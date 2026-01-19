<?php
namespace App\Traits;

use App\Models\StudentFee;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait CheckFeeStatus
{
    /**
     * Check if a user or their children (if parent) have outstanding fees.
     */
    public function hasOutstandingFees(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        // -------------------------
        // PARENT CHECK
        // -------------------------
        if ($user->role === 'Parent') {
            $children = $user->children ?? [];

            foreach ($children as $child) {
                $studentId = $child->student_id ?? $child->id;

                $fees = StudentFee::where('student_id', $studentId)
                    ->where('school_id', $child->school_id)
                    ->get();

                if ($fees->isNotEmpty() &&
                    $fees->whereIn('status', ['partial', 'unpaid'])->count() > 0) {
                    return true;
                }
            }

            return false;
        }

        // -------------------------
        // STUDENT CHECK
        // -------------------------
        if ($user->role === 'student') {
            $fees = StudentFee::where('student_id', $user->id)
                ->where('school_id', $user->school_id)
                ->get();

            if ($fees->isNotEmpty() &&
                $fees->whereIn('status', ['partial', 'unpaid'])->count() > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Restrict access if unpaid fees exist (API-friendly)
     */
    public function restrictIfUnpaid(?User $user): void
    {
        if ($this->hasOutstandingFees($user)) {
            throw new HttpException(
                403,
                'Result access denied. Please complete all outstanding school fees.'
            );
        }
    }
}
