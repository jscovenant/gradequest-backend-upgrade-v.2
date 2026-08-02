<?php

namespace App\Services;

use App\Models\PlatformFeeCharge;
use App\Models\StudentFee;
use Illuminate\Support\Facades\DB;

class PlatformFeeService
{
    public function __construct(private SchoolBillingService $billing)
    {
    }

    /**
     * Flat platform fee, in naira, charged once per student_fee record
     * (i.e. once per student per term per academic session).
     */
    public function feeAmountNaira(?StudentFee $studentFee = null): int
    {
        if ($studentFee) {
            $schoolId = (int) $studentFee->school_id;

            if (($this->billing->activeSubscriptionRevenueCoverage($schoolId)['active'] ?? false) === true) {
                return 0;
            }

            $amount = (int) round($this->billing->pricePerStudentForSchool($schoolId));

            if ($amount > 0) {
                return $amount;
            }

            $schoolSettingAmount = DB::table('school_billing_settings')
                ->where('school_id', $schoolId)
                ->value('platform_fee_per_student');

            if ((float) $schoolSettingAmount > 0) {
                return (int) round((float) $schoolSettingAmount);
            }

            $policyAmount = DB::table('gradequest_billing_policies')
                ->orderByDesc('id')
                ->value('platform_fee_per_student');

            if ((float) $policyAmount > 0) {
                return (int) round((float) $policyAmount);
            }
        }

        return (int) config('services.paystack.platform_fee_naira', 1000); 
    }

    /**
     * Decide how much of the incoming installment (if any) should be
     * routed to GradeQuest's main account as the one-time platform fee.
     *
     * Returns 0 if the fee has already been collected — or is currently
     * being collected by another in-flight payment — for this student_fee.
     */
    public function resolveCharge(StudentFee $studentFee, int $installmentNaira, string $reference): int
    {
        if (($this->billing->activeSubscriptionRevenueCoverage((int) $studentFee->school_id)['active'] ?? false) === true) {
            return 0;
        }

        return DB::transaction(function () use ($studentFee, $installmentNaira, $reference) {
            $periodIdentity = [
                'school_id' => (int) $studentFee->school_id,
                'student_id' => (int) $studentFee->student_id,
                'session_id' => (int) $studentFee->session_id,
                'term_id' => (int) $studentFee->term_id,
            ];

            $existing = PlatformFeeCharge::where($periodIdentity)
                ->lockForUpdate()
                ->first();

            if ($existing?->status === 'confirmed') {
                return 0; // already collected for this student's fee record
            }

            if (
                $existing?->status === 'pending'
                && $existing->paystack_reference === $reference
                && $existing->updated_at->gt(now()->subMinutes(30))
            ) {
                return $this->feeAmountNaira($studentFee); // same in-flight transaction, keep the split amount stable
            }

            if ($existing?->status === 'pending' && $existing->updated_at->gt(now()->subMinutes(30))) {
                return 0; // another installment is currently in-flight claiming the fee
            }

            $fee = $this->feeAmountNaira($studentFee);

            if ($installmentNaira < $fee) {
                return 0; // this installment is too small to bear the flat fee — wait for a bigger one
            }

            PlatformFeeCharge::updateOrCreate($periodIdentity, [
                'student_fee_id' => $studentFee->id,
                'status' => 'pending',
                'paystack_reference' => $reference,
            ]);

            return $fee;
        });
    }

    /**
     * Called once a charge that carried the platform fee is confirmed
     * successful — locks the claim in for the rest of the term/session.
     */
    public function confirmCharge(string $reference): void
    {
        PlatformFeeCharge::where('paystack_reference', $reference)
            ->update(['status' => 'confirmed']);
    }

    /**
     * Called if a charge that carried a pending fee claim ends up
     * failing — frees the claim immediately so the next installment
     * attempt doesn't have to wait out the 30-minute staleness window.
     */
    public function releaseCharge(string $reference): void
    {
        PlatformFeeCharge::where('paystack_reference', $reference)
            ->where('status', 'pending')
            ->delete();
    }
}
