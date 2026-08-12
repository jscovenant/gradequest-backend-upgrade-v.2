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

        $rate = (float) ($representative->premium_commission_rate ?? $representative->commission_rate);
        $baseAmount = (float) $payment->amount;
        $commissionAmount = round(($baseAmount * $rate) / 100, 2);

        if ($commissionAmount <= 0) {
            return null;
        }

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

    public function recordCoreCommission(Payment $payment): ?SalesCommission
    {
        if (strtolower((string) $payment->status) !== 'success' || (float) $payment->platform_fee <= 0) {
            return null;
        }

        $existing = SalesCommission::query()
            ->where('source', 'core_platform_fee')
            ->where('payment_id', $payment->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $schoolAdmin = User::query()
            ->where('school_id', $payment->school_id)
            ->whereRaw('LOWER(role) = ?', ['admin'])
            ->orderBy('id')
            ->first();

        if (! $schoolAdmin || ! ($assignment = $this->resolveAssignment($schoolAdmin))) {
            return null;
        }

        $representative = $assignment->representative;
        if (! $representative) {
            return null;
        }

        $studentFee = $payment->studentFee;
        $rate = (float) ($representative->core_commission_rate ?? $representative->commission_rate);
        $baseAmount = (float) $payment->platform_fee;
        $commissionAmount = round(($baseAmount * $rate) / 100, 2);

        if ($commissionAmount <= 0) {
            return null;
        }

        $policy = SalesPayoutPolicy::current();
        $earnedAt = now();

        $commission = SalesCommission::create([
            'sales_representative_id' => $representative->id,
            'school_id' => $payment->school_id,
            'payment_id' => $payment->id,
            'student_id' => $studentFee?->student_id,
            'session_id' => $studentFee?->session_id,
            'term_id' => $studentFee?->term_id,
            'source' => 'core_platform_fee',
            'reference' => $payment->reference,
            'commissionable_amount' => $baseAmount,
            'commission_rate' => $rate,
            'amount' => $commissionAmount,
            'status' => 'pending',
            'earned_at' => $earnedAt,
            'eligible_at' => $earnedAt->copy()->addDays((int) $policy->commission_waiting_days),
            'notes' => 'Auto-created from confirmed GradeQuest Core platform revenue.',
            'metadata' => [
                'revenue_type' => 'core',
                'payment_amount' => (float) $payment->amount,
                'platform_fee' => $baseAmount,
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

    private function assignmentQuery()
    {
        return SalesRepAssignment::query()
            ->with('representative')
            ->whereHas('representative', function ($query) {
                $query->where('status', 'active');
            });
    }
}
