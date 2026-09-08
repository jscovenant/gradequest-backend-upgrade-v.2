<?php

namespace App\Services;

use App\Models\SchoolSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SchoolFeeAccessPolicyService
{
    public const DEFAULT_POLICY = [
        'enabled' => false,
        'result_access_enabled' => true,
        'result_min_payment_percent' => 100,
        'result_scope' => 'selected_period',
        'cbt_access_enabled' => false,
        'cbt_min_payment_percent' => 100,
        'cbt_scope' => 'selected_period',
        'installment_enabled' => false,
        'installment_type' => 'two_installments_70_30',
        'min_initial_installment_percent' => 70,
        'message' => 'Result access is currently unavailable because the required school fee payment has not been completed.',
        'cbt_message' => 'Access denied. Complete the required school fee payment before starting this exam.',
        'installment_message' => 'This school requires a minimum initial payment of :percent% (:amount) for the term.',
        'bank_charge_bearer' => 'parent',
        'bank_charge_amount' => 200.0,
        'platform_fee_bearer' => 'school',
        'active_edition_tier' => 'standard_cbt',
        'active_payment_gateway' => 'wema_alat',
    ];

    public function policyForSchool(int $schoolId): array
    {
        $school = SchoolSetting::find($schoolId);
        $raw = $school?->fee_access_policy;
        $policy = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);

        $globalPolicy = DB::table('gradequest_billing_policies')->orderByDesc('id')->first();
        $basicPrice = (float) ($globalPolicy->basic_tier_price_per_student ?? 300.00);
        $cbtPrice = (float) ($globalPolicy->standard_cbt_tier_price_per_student ?? ($globalPolicy->platform_fee_per_student ?? 500.00));
        $annualMultiplier = (float) ($globalPolicy->annual_full_session_multiplier ?? 3.00);
        $annualDiscount = (float) ($globalPolicy->annual_session_discount_percent ?? 0.00);
        $defaultBankCharge = (float) ($globalPolicy->default_bank_charge_amount ?? 200.00);

        $activeTier = $policy['active_edition_tier'] ?? ($school?->active_edition_tier ?: 'standard_cbt');

        $merged = array_merge(self::DEFAULT_POLICY, $policy);
        $merged['active_edition_tier'] = $activeTier;

        $activePlatformFee = match ($activeTier) {
            'basic_result' => $basicPrice,
            'annual_full_session' => round($cbtPrice * $annualMultiplier * (1 - ($annualDiscount / 100)), 2),
            default => $cbtPrice,
        };

        $merged['basic_tier_price'] = $basicPrice;
        $merged['standard_cbt_tier_price'] = $cbtPrice;
        $merged['annual_full_session_price'] = round($cbtPrice * $annualMultiplier * (1 - ($annualDiscount / 100)), 2);
        $merged['annual_session_multiplier'] = $annualMultiplier;
        $merged['annual_session_discount_percent'] = $annualDiscount;
        $merged['platform_fee_amount'] = $activePlatformFee;
        $merged['bank_charge_amount'] = $defaultBankCharge;
        $merged['default_bank_charge_amount'] = $defaultBankCharge;

        return $merged;
    }

    public function updatePolicy(int $schoolId, array $data): array
    {
        $current = $this->policyForSchool($schoolId);
        $globalPolicy = DB::table('gradequest_billing_policies')->orderByDesc('id')->first();
        $defaultBankCharge = (float) ($globalPolicy->default_bank_charge_amount ?? 200.00);

        $policy = array_merge($current, [
            'enabled' => array_key_exists('enabled', $data) ? (bool) $data['enabled'] : ($current['enabled'] ?? false),
            'result_access_enabled' => array_key_exists('result_access_enabled', $data) ? (bool) $data['result_access_enabled'] : ($current['result_access_enabled'] ?? true),
            'result_min_payment_percent' => max(0, min(100, (float) ($data['result_min_payment_percent'] ?? ($current['result_min_payment_percent'] ?? 100)))),
            'result_scope' => in_array(($data['result_scope'] ?? ''), ['selected_period', 'all_outstanding'], true)
                ? $data['result_scope']
                : ($current['result_scope'] ?? 'selected_period'),
            'cbt_access_enabled' => array_key_exists('cbt_access_enabled', $data) ? (bool) $data['cbt_access_enabled'] : ($current['cbt_access_enabled'] ?? false),
            'cbt_min_payment_percent' => max(0, min(100, (float) ($data['cbt_min_payment_percent'] ?? ($current['cbt_min_payment_percent'] ?? 100)))),
            'cbt_scope' => in_array(($data['cbt_scope'] ?? ''), ['selected_period', 'all_outstanding'], true)
                ? $data['cbt_scope']
                : ($current['cbt_scope'] ?? 'selected_period'),
            'installment_enabled' => array_key_exists('installment_enabled', $data) ? (bool) $data['installment_enabled'] : ($current['installment_enabled'] ?? false),
            'installment_type' => in_array(($data['installment_type'] ?? ''), ['two_installments_70_30', 'two_installments_50_50', 'three_installments_40_30_30', 'full_only', 'custom'], true)
                ? $data['installment_type']
                : ($current['installment_type'] ?? 'two_installments_70_30'),
            'min_initial_installment_percent' => max(1, min(100, (float) ($data['min_initial_installment_percent'] ?? ($current['min_initial_installment_percent'] ?? 70)))),
            'message' => trim((string) ($data['message'] ?? ($current['message'] ?? self::DEFAULT_POLICY['message']))) ?: self::DEFAULT_POLICY['message'],
            'cbt_message' => trim((string) ($data['cbt_message'] ?? ($current['cbt_message'] ?? self::DEFAULT_POLICY['cbt_message']))) ?: self::DEFAULT_POLICY['cbt_message'],
            'installment_message' => trim((string) ($data['installment_message'] ?? ($current['installment_message'] ?? self::DEFAULT_POLICY['installment_message']))) ?: self::DEFAULT_POLICY['installment_message'],
            'bank_charge_bearer' => in_array(($data['bank_charge_bearer'] ?? ''), ['parent', 'school'], true) ? $data['bank_charge_bearer'] : ($current['bank_charge_bearer'] ?? 'parent'),
            'bank_charge_amount' => $defaultBankCharge,
            'platform_fee_bearer' => in_array(($data['platform_fee_bearer'] ?? ''), ['parent', 'school'], true) ? $data['platform_fee_bearer'] : ($current['platform_fee_bearer'] ?? 'school'),
            'active_edition_tier' => in_array(($data['active_edition_tier'] ?? ''), ['basic_result', 'standard_cbt', 'annual_full_session'], true) ? $data['active_edition_tier'] : ($current['active_edition_tier'] ?? 'standard_cbt'),
            'active_payment_gateway' => in_array(($data['active_payment_gateway'] ?? ''), ['wema_alat', 'monnify', 'paystack'], true) ? $data['active_payment_gateway'] : ($current['active_payment_gateway'] ?? 'wema_alat'),
        ]);

        $updateColumns = [
            'fee_access_policy' => json_encode($policy),
            'updated_at' => now(),
        ];

        if (! empty($policy['active_edition_tier'])) {
            $updateColumns['active_edition_tier'] = $policy['active_edition_tier'];
        }
        if (! empty($policy['bank_charge_bearer'])) {
            $updateColumns['bank_charge_bearer'] = $policy['bank_charge_bearer'];
        }
        $updateColumns['bank_charge_amount'] = $defaultBankCharge;
        if (! empty($policy['platform_fee_bearer'])) {
            $updateColumns['platform_fee_bearer'] = $policy['platform_fee_bearer'];
        }
        if (! empty($policy['active_payment_gateway'])) {
            $updateColumns['active_payment_gateway'] = $policy['active_payment_gateway'];
        }

        SchoolSetting::where('id', $schoolId)->update($updateColumns);

        return $this->policyForSchool($schoolId);
    }

    public function calculateInstallmentPlan(int $schoolId, float $totalTermAmount, float $totalPaid, float $balance): array
    {
        $policy = $this->policyForSchool($schoolId);
        $enabled = (bool) ($policy['installment_enabled'] ?? false);
        $minPercent = (float) ($policy['min_initial_installment_percent'] ?? 70);
        $type = (string) ($policy['installment_type'] ?? 'two_installments_70_30');

        if ($type === 'two_installments_50_50') {
            $minPercent = 50;
        } elseif ($type === 'three_installments_40_30_30') {
            $minPercent = 40;
        } elseif ($type === 'full_only') {
            $minPercent = 100;
        }

        $minInitialAmount = round(($minPercent / 100) * $totalTermAmount, 2);
        $initialTarget = max(0, $minInitialAmount - $totalPaid);
        $minPayableNow = min($balance, $initialTarget > 0 ? $initialTarget : 100);

        $presets = [];
        if ($balance > 0) {
            $presets[] = [
                'label' => 'Full Payment (100%)',
                'percent' => 100,
                'amount' => round($balance, 2),
            ];

            if ($enabled && $totalPaid < $minInitialAmount && $balance > $minInitialAmount) {
                $presets[] = [
                    'label' => "1st Installment ({$minPercent}%)",
                    'percent' => $minPercent,
                    'amount' => round($minInitialAmount - $totalPaid, 2),
                ];
            }

            if ($enabled && $type === 'two_installments_70_30' && $totalPaid >= $minInitialAmount) {
                $presets[] = [
                    'label' => '2nd Installment (30% Balance)',
                    'percent' => 30,
                    'amount' => round($balance, 2),
                ];
            }
        }

        return [
            'enabled' => $enabled,
            'installment_type' => $type,
            'min_initial_percent' => $minPercent,
            'min_initial_amount' => $minInitialAmount,
            'min_payable_now' => $minPayableNow,
            'presets' => $presets,
            'message' => str_replace([':percent', ':amount'], [(string) $minPercent, number_format($minInitialAmount, 2)], (string) ($policy['installment_message'] ?? '')),
        ];
    }

    public function resultAccessStatus(int $schoolId, int $studentId, string $session, string $term): array
    {
        $policy = $this->policyForSchool($schoolId);
        $summary = $this->feeSummary($schoolId, $studentId, $session, $term, (string) $policy['result_scope']);

        $requiredPercent = (float) ($policy['result_min_payment_percent'] ?? 100);
        $allowed = ! ($policy['enabled'] ?? false)
            || ! ($policy['result_access_enabled'] ?? true)
            || $summary['total_amount'] <= 0
            || $summary['payment_percent'] >= $requiredPercent;

        return [
            'allowed' => $allowed,
            'message' => $allowed ? null : (string) $policy['message'],
            'required_percent' => $requiredPercent,
            'policy' => $policy,
            'summary' => $summary,
        ];
    }

    public function assertResultAccess(User $viewer, int $schoolId, int $studentId, string $session, string $term): ?array
    {
        $role = strtolower((string) $viewer->role);
        if (! in_array($role, ['student', 'parent'], true)) {
            return null;
        }

        $status = $this->resultAccessStatus($schoolId, $studentId, $session, $term);

        return $status['allowed'] ? null : $status;
    }

    public function cbtAccessStatus(int $schoolId, int $studentId, ?int $sessionId, ?int $termId): array
    {
        $policy = $this->policyForSchool($schoolId);
        $scope = (string) ($policy['cbt_scope'] ?? 'selected_period');
        $summary = $this->feeSummaryByIds($schoolId, $studentId, $sessionId, $termId, $scope);

        $requiredPercent = (float) ($policy['cbt_min_payment_percent'] ?? 100);
        $allowed = ! ($policy['enabled'] ?? false)
            || ! ($policy['cbt_access_enabled'] ?? false)
            || $summary['total_amount'] <= 0
            || $summary['payment_percent'] >= $requiredPercent;

        return [
            'allowed' => $allowed,
            'message' => $allowed ? null : (string) ($policy['cbt_message'] ?? self::DEFAULT_POLICY['cbt_message']),
            'required_percent' => $requiredPercent,
            'policy' => $policy,
            'summary' => $summary,
        ];
    }

    public function assertCbtAccess(int $schoolId, int $studentId, ?int $sessionId, ?int $termId): ?array
    {
        $status = $this->cbtAccessStatus($schoolId, $studentId, $sessionId, $termId);

        return $status['allowed'] ? null : $status;
    }

    public function feeSummary(int $schoolId, int $studentId, string $session, string $term, string $scope = 'selected_period'): array
    {
        $query = DB::table('student_fees as sf')
            ->join('academic_sessions as s', 's.id', '=', 'sf.session_id')
            ->join('terms as t', 't.id', '=', 'sf.term_id')
            ->where('sf.school_id', $schoolId)
            ->where('sf.student_id', $studentId);

        if ($scope !== 'all_outstanding') {
            $query->where('s.name', $session)->where('t.name', $term);
        }

        $row = $query->selectRaw('COALESCE(SUM(sf.total_amount),0) as total_amount, COALESCE(SUM(sf.amount_paid),0) as amount_paid, COALESCE(SUM(sf.balance),0) as balance')
            ->first();

        $total = round((float) ($row->total_amount ?? 0), 2);
        $paid = round((float) ($row->amount_paid ?? 0), 2);
        $balance = round(max(0, (float) ($row->balance ?? ($total - $paid))), 2);
        $percent = $total > 0 ? round(min(100, ($paid / $total) * 100), 2) : 100.0;

        return [
            'scope' => $scope,
            'session' => $session,
            'term' => $term,
            'total_amount' => $total,
            'amount_paid' => $paid,
            'balance' => $balance,
            'payment_percent' => $percent,
        ];
    }

    public function feeSummaryByIds(int $schoolId, int $studentId, ?int $sessionId, ?int $termId, string $scope = 'selected_period'): array
    {
        $query = DB::table('student_fees as sf')
            ->where('sf.school_id', $schoolId)
            ->where('sf.student_id', $studentId);

        if ($scope !== 'all_outstanding') {
            if ($sessionId) {
                $query->where('sf.session_id', $sessionId);
            }

            if ($termId) {
                $query->where('sf.term_id', $termId);
            }
        }

        $row = $query->selectRaw('COALESCE(SUM(sf.total_amount),0) as total_amount, COALESCE(SUM(sf.amount_paid),0) as amount_paid, COALESCE(SUM(sf.balance),0) as balance')
            ->first();

        $total = round((float) ($row->total_amount ?? 0), 2);
        $paid = round((float) ($row->amount_paid ?? 0), 2);
        $balance = round(max(0, (float) ($row->balance ?? ($total - $paid))), 2);
        $percent = $total > 0 ? round(min(100, ($paid / $total) * 100), 2) : 100.0;

        return [
            'scope' => $scope,
            'session_id' => $sessionId,
            'term_id' => $termId,
            'total_amount' => $total,
            'amount_paid' => $paid,
            'balance' => $balance,
            'payment_percent' => $percent,
        ];
    }
}
