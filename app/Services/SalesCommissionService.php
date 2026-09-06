<?php

namespace App\Services;

use App\Models\SchoolProfitInvoicePayment;
use App\Models\SchoolProfitTermInvoice;
use App\Models\Payment;
use App\Models\SalesCommission;
use App\Models\SalesPayoutPolicy;
use App\Models\SalesRepAssignment;
use App\Models\SalesRepresentative;
use App\Models\SubPayment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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

        $schoolId = (int) $schoolAdmin->school_id;
        $termNumber = $this->resolveSchoolTermNumber($schoolId, $representative->id);
        $rate = $this->resolveCommissionRate($representative, $termNumber, 'subscription');

        if ($rate <= 0) {
            return null;
        }

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
            'term_number' => $termNumber,
            'source' => 'subscription',
            'reference' => $payment->reference,
            'commissionable_amount' => $baseAmount,
            'commission_rate' => $rate,
            'amount' => $commissionAmount,
            'status' => 'pending',
            'earned_at' => $earnedAt,
            'eligible_at' => $earnedAt->copy()->addDays((int) $policy->commission_waiting_days),
            'notes' => "Auto-created from Term {$termNumber} subscription payment.",
            'metadata' => [
                'term_number' => $termNumber,
                'tier' => $termNumber === 1 ? 'acquisition' : 'retention',
            ],
        ]);

        $this->markAssignmentConverted($assignment, $termNumber);

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
        $sessionId = $studentFee?->session_id;
        $termId = $studentFee?->term_id;
        $schoolId = (int) $payment->school_id;

        $termNumber = $this->resolveSchoolTermNumber($schoolId, $representative->id, $sessionId, $termId);
        $rate = $this->resolveCommissionRate($representative, $termNumber, 'core_platform_fee');

        if ($rate <= 0) {
            return null;
        }

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
            'session_id' => $sessionId,
            'term_id' => $termId,
            'term_number' => $termNumber,
            'source' => 'core_platform_fee',
            'reference' => $payment->reference,
            'commissionable_amount' => $baseAmount,
            'commission_rate' => $rate,
            'amount' => $commissionAmount,
            'status' => 'pending',
            'earned_at' => $earnedAt,
            'eligible_at' => $earnedAt->copy()->addDays((int) $policy->commission_waiting_days),
            'notes' => "Auto-created from Term {$termNumber} SchoolProfit Core platform fee.",
            'metadata' => [
                'revenue_type' => 'core',
                'payment_amount' => (float) $payment->amount,
                'platform_fee' => $baseAmount,
                'term_number' => $termNumber,
                'tier' => $termNumber === 1 ? 'acquisition' : 'retention',
            ],
        ]);

        $this->markAssignmentConverted($assignment, $termNumber);

        return $commission;
    }

    public function recordTermInvoiceCommission(SchoolProfitTermInvoice $invoice, float $paidAmount, ?SchoolProfitInvoicePayment $payment = null): ?SalesCommission
    {
        if ($paidAmount <= 0) {
            return null;
        }

        if ($payment && SalesCommission::where('invoice_payment_id', $payment->id)->exists()) {
            return SalesCommission::where('invoice_payment_id', $payment->id)->first();
        }

        $schoolAdmin = User::query()
            ->where('school_id', $invoice->school_id)
            ->whereRaw('LOWER(role) = ?', ['admin'])
            ->orderBy('id')
            ->first();

        $assignment = null;
        if ($schoolAdmin) {
            $assignment = $this->resolveAssignment($schoolAdmin);
        }

        if (! $assignment) {
            $assignment = SalesRepAssignment::query()
                ->with('representative')
                ->where('school_id', $invoice->school_id)
                ->whereHas('representative', fn ($q) => $q->where('status', 'active'))
                ->latest('updated_at')
                ->first();
        }

        if (! $assignment || ! $assignment->representative) {
            return null;
        }

        $representative = $assignment->representative;
        $schoolId = (int) $invoice->school_id;
        $sessionId = (int) $invoice->session_id;
        $termId = (int) $invoice->term_id;

        $termNumber = $this->resolveSchoolTermNumber($schoolId, $representative->id, $sessionId, $termId);
        $rate = $this->resolveCommissionRate($representative, $termNumber, 'offline_invoice');

        if ($rate <= 0) {
            return null;
        }

        $commissionAmount = round(($paidAmount * $rate) / 100, 2);
        if ($commissionAmount <= 0) {
            return null;
        }

        $policy = SalesPayoutPolicy::current();
        $earnedAt = now();
        $reference = $payment?->reference ?? 'gq_inv_pmt_' . $invoice->id . '_' . time();

        $commission = SalesCommission::create([
            'sales_representative_id' => $representative->id,
            'school_id' => $schoolId,
            'invoice_id' => $invoice->id,
            'invoice_payment_id' => $payment?->id,
            'session_id' => $sessionId,
            'term_id' => $termId,
            'term_number' => $termNumber,
            'source' => 'offline_invoice',
            'reference' => $reference,
            'commissionable_amount' => $paidAmount,
            'commission_rate' => $rate,
            'amount' => $commissionAmount,
            'status' => 'pending',
            'earned_at' => $earnedAt,
            'eligible_at' => $earnedAt->copy()->addDays((int) $policy->commission_waiting_days),
            'notes' => "Auto-created from Term {$termNumber} offline invoice payment.",
            'metadata' => [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'paid_amount' => $paidAmount,
                'term_number' => $termNumber,
                'tier' => $termNumber === 1 ? 'acquisition' : 'retention',
            ],
        ]);

        $this->markAssignmentConverted($assignment, $termNumber);

        return $commission;
    }

    public function resolveSchoolTermNumber(int $schoolId, int $representativeId, ?int $sessionId = null, ?int $termId = null): int
    {
        $existingTerms = DB::table('sales_commissions')
            ->where('sales_representative_id', $representativeId)
            ->where('school_id', $schoolId)
            ->select('session_id', 'term_id', 'term_number')
            ->orderBy('id')
            ->get();

        if ($existingTerms->isEmpty()) {
            return 1;
        }

        if ($sessionId !== null && $termId !== null) {
            $matchingTerm = $existingTerms->first(function ($item) use ($sessionId, $termId) {
                return (int) $item->session_id === (int) $sessionId && (int) $item->term_id === (int) $termId;
            });

            if ($matchingTerm && ! empty($matchingTerm->term_number)) {
                return (int) $matchingTerm->term_number;
            }
        }

        // Count distinct academic periods (session_id + term_id) or fallback distinct timestamps
        $distinctPeriodsCount = $existingTerms
            ->filter(fn ($item) => $item->session_id && $item->term_id)
            ->unique(fn ($item) => "{$item->session_id}-{$item->term_id}")
            ->count();

        if ($distinctPeriodsCount > 0) {
            return $distinctPeriodsCount + 1;
        }

        // Fallback: max recorded term_number + 1
        $maxTerm = (int) $existingTerms->max('term_number');
        return max(1, $maxTerm + 1);
    }

    public function resolveCommissionRate(SalesRepresentative $representative, int $termNumber, string $source = 'core'): float
    {
        $policy = SalesPayoutPolicy::current();
        $maxTerms = (int) ($policy->max_commission_terms ?: 3);

        // Past max terms (e.g. Year 2+), commissions graduate to Customer Success (0%)
        if ($termNumber > $maxTerms) {
            return 0.00;
        }

        if ($termNumber === 1) {
            return (float) ($representative->term_1_commission_rate
                ?? $policy->default_term_1_rate
                ?? $policy->default_commission_rate
                ?? 30.00);
        }

        // Terms 2 and 3: Retention Commission
        return (float) ($representative->retention_commission_rate
            ?? $policy->default_retention_rate
            ?? 12.00);
    }

    private function markAssignmentConverted(SalesRepAssignment $assignment, int $termNumber): void
    {
        $updates = [];
        if ($assignment->stage !== 'converted') {
            $updates['stage'] = 'converted';
            $updates['converted_at'] = $assignment->converted_at ?: now();
        }
        if ((int) $assignment->commission_term_count < $termNumber) {
            $updates['commission_term_count'] = $termNumber;
        }
        if (! empty($updates)) {
            $assignment->update($updates);
        }
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
