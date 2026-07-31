<?php

namespace App\Services;

use App\Models\SalesCommission;
use App\Models\SalesPayoutPolicy;
use App\Models\SalesRepAssignment;
use App\Models\SalesRepresentative;
use App\Models\Payment;
use App\Models\SubPayment;
use App\Models\Subscription;
use App\Models\User;

class SalesCommissionService
{
    public function recordSubscriptionCommission(SubPayment $payment, ?Subscription $subscription = null): ?SalesCommission
    {
        if (! in_array(strtolower((string) $payment->status), ['successful', 'success', 'paid'], true)) {
            return null;
        }

        if ($payment->amount <= 0) {
            return null;
        }

        if (SalesCommission::where('sub_payment_id', $payment->id)->exists()) {
            return SalesCommission::where('sub_payment_id', $payment->id)->first();
        }

        $schoolAdmin = User::find($payment->user_id);
        if (! $schoolAdmin) {
            return null;
        }

        $assignment = $this->resolveAssignment($schoolAdmin);
        if (! $assignment) {
            return null;
        }

        $representative = $assignment->representative;
        if (! $representative) {
            return null;
        }

        $rate = (float) $representative->commission_rate;
        $baseAmount = (float) $payment->amount;
        $commissionAmount = round(($baseAmount * $rate) / 100, 2);
        $policy = SalesPayoutPolicy::current();
        $earnedAt = now();

        $commission = SalesCommission::create([
            'sales_representative_id' => $representative->id,
            'school_id' => $schoolAdmin->school_id,
            'subscription_id' => $subscription?->id,
            'sub_payment_id' => $payment->id,
            'source' => 'subscription',
            'reference' => $payment->reference,
            'commissionable_amount' => $baseAmount,
            'commission_rate' => $rate,
            'amount' => $commissionAmount,
            'status' => 'pending',
            'earned_at' => $earnedAt,
            'eligible_at' => $earnedAt->copy()->addDays((int) $policy->commission_waiting_days),
            'notes' => 'Auto-created from successful subscription payment.',
        ]);

        if ($assignment->stage !== 'converted') {
            $assignment->update([
                'stage' => 'converted',
                'converted_at' => $assignment->converted_at ?: now(),
            ]);
        }

        return $commission;
    }

    public function recordFeePaymentCommission(Payment $payment): ?SalesCommission
    {
        $payment->loadMissing('studentFee');

        if (! in_array(strtolower((string) $payment->status), ['successful', 'success', 'paid'], true)) {
            return null;
        }

        $commissionableAmount = (float) ($payment->platform_fee ?? 0);

        if ($commissionableAmount <= 0) {
            return null;
        }

        $studentFee = $payment->studentFee;

        if (! $studentFee) {
            return null;
        }

        $periodIdentity = [
            'school_id' => (int) $payment->school_id,
            'student_id' => (int) $studentFee->student_id,
            'session_id' => (int) $studentFee->session_id,
            'term_id' => (int) $studentFee->term_id,
            'source' => 'fee_payment',
        ];

        $existing = SalesCommission::where($periodIdentity)->first();

        if ($existing) {
            return $existing;
        }

        $assignment = $this->resolveAssignmentForSchool((int) $payment->school_id);

        if (! $assignment) {
            return null;
        }

        $representative = $assignment->representative;

        if (! $representative) {
            return null;
        }

        $rate = (float) $representative->commission_rate;
        $commissionAmount = round(($commissionableAmount * $rate) / 100, 2);
        $policy = SalesPayoutPolicy::current();
        $earnedAt = now();

        $commission = SalesCommission::create([
            'sales_representative_id' => $representative->id,
            'school_id' => $payment->school_id,
            'student_id' => $studentFee->student_id,
            'session_id' => $studentFee->session_id,
            'term_id' => $studentFee->term_id,
            'subscription_id' => null,
            'sub_payment_id' => null,
            'payment_id' => $payment->id,
            'source' => 'fee_payment',
            'reference' => $payment->reference,
            'commissionable_amount' => $commissionableAmount,
            'commission_rate' => $rate,
            'amount' => $commissionAmount,
            'status' => 'pending',
            'earned_at' => $earnedAt,
            'eligible_at' => $earnedAt->copy()->addDays((int) $policy->commission_waiting_days),
            'payout_period' => $earnedAt->format('Y-m'),
            'notes' => 'Auto-created from successful parent fee payment platform revenue.',
            'metadata' => [
                'student_fee_id' => $studentFee->id,
                'period_rule' => 'once_per_student_per_term_session',
            ],
        ]);

        if ($assignment->stage !== 'converted') {
            $assignment->update([
                'stage' => 'converted',
                'converted_at' => $assignment->converted_at ?: now(),
            ]);
        }

        return $commission;
    }

    private function resolveAssignment(User $schoolAdmin): ?SalesRepAssignment
    {
        return $this->assignmentQuery()
            ->where(function ($query) use ($schoolAdmin) {
                $query->where('admin_user_id', $schoolAdmin->id);

                if ($schoolAdmin->school_id) {
                    $query->orWhere('school_id', $schoolAdmin->school_id);
                }

                if ($schoolAdmin->email) {
                    $query->orWhere('contact_email', $schoolAdmin->email);
                }
            })
            ->latest('updated_at')
            ->first();
    }

    private function resolveAssignmentForSchool(int $schoolId): ?SalesRepAssignment
    {
        return $this->assignmentQuery()
            ->where('school_id', $schoolId)
            ->latest('updated_at')
            ->first();
    }

    private function assignmentQuery()
    {
        return SalesRepAssignment::query()
            ->with('representative')
            ->whereHas('representative', function ($query) {
                $query->where('status', 'active');
            });
    }
}
