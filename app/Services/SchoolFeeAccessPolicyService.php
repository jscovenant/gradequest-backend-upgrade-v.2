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
        'message' => 'Result access is currently unavailable because the required school fee payment has not been completed.',
        'cbt_message' => 'Access denied. Complete the required school fee payment before starting this exam.',
    ];

    public function policyForSchool(int $schoolId): array
    {
        $school = SchoolSetting::find($schoolId);
        $raw = $school?->fee_access_policy;
        $policy = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);

        return array_merge(self::DEFAULT_POLICY, $policy);
    }

    public function updatePolicy(int $schoolId, array $data): array
    {
        $current = $this->policyForSchool($schoolId);

        $cbtScope = $data['cbt_scope'] ?? ($current['cbt_scope'] ?? 'selected_period');

        $policy = array_merge($current, [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'result_access_enabled' => (bool) ($data['result_access_enabled'] ?? true),
            'result_min_payment_percent' => max(0, min(100, (float) ($data['result_min_payment_percent'] ?? 100))),
            'result_scope' => in_array(($data['result_scope'] ?? 'selected_period'), ['selected_period', 'all_outstanding'], true)
                ? $data['result_scope']
                : 'selected_period',
            'cbt_access_enabled' => (bool) ($data['cbt_access_enabled'] ?? ($current['cbt_access_enabled'] ?? false)),
            'cbt_min_payment_percent' => max(0, min(100, (float) ($data['cbt_min_payment_percent'] ?? ($current['cbt_min_payment_percent'] ?? 100)))),
            'cbt_scope' => in_array($cbtScope, ['selected_period', 'all_outstanding'], true)
                ? $cbtScope
                : ($current['cbt_scope'] ?? 'selected_period'),
            'message' => trim((string) ($data['message'] ?? self::DEFAULT_POLICY['message'])) ?: self::DEFAULT_POLICY['message'],
            'cbt_message' => trim((string) ($data['cbt_message'] ?? ($current['cbt_message'] ?? self::DEFAULT_POLICY['cbt_message']))) ?: self::DEFAULT_POLICY['cbt_message'],
        ]);

        SchoolSetting::where('id', $schoolId)->update([
            'fee_access_policy' => json_encode($policy),
            'updated_at' => now(),
        ]);

        return $policy;
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
