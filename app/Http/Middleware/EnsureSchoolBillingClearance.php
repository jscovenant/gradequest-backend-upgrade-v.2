<?php

namespace App\Http\Middleware;

use App\Services\SchoolBillingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSchoolBillingClearance
{
    public function __construct(private readonly SchoolBillingService $billing)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->school_id || $request->isMethodSafe() || $this->isPlatformUser($user) || $this->isLearnerOrParent($user)) {
            return $next($request);
        }

        if ($this->isAllowedWhenOutstanding($request)) {
            return $next($request);
        }

        $status = $this->billing->schoolCrudClearanceStatus((int) $user->school_id);

        if ($status['allowed']) {
            return $next($request);
        }

        return response()->json([
            'message' => $status['message'],
            'reason' => 'outstanding_billing_required',
            'billing' => [
                'status' => $status['status'],
                'blocked_invoices' => $status['blocked_invoices'],
                'blocked_entitlements' => $status['blocked_entitlements'],
                'sample_students' => $status['sample_students'],
                'allowed_actions' => [
                    'Add new student',
                    'Create academic session',
                    'Create term',
                    'Settle outstanding fees',
                ],
            ],
        ], 402);
    }

    private function isAllowedWhenOutstanding(Request $request): bool
    {
        $method = strtoupper($request->method());
        $path = trim($request->path(), '/');
        $path = str_starts_with($path, 'api/') ? substr($path, 4) : $path;

        if ($method === 'POST' && in_array($path, [
            'students/store',
            'sessions',
            'terms',
            'terms/bulk-create',
        ], true)) {
            return true;
        }

        foreach ([
            'school/billing',
            'school/billing/invoices',
            'school/bank-accounts',
            'bank-account/verify',
            'banks',
            'subscription',
            'subscription-plans',
            'initialize-payment',
            'verify-payment',
            'user/transactions',
            'user/transaction-summary',
            'fees/online',
            'public/fee-payment',
            'logout',
            'user-profile',
            'user/update-password',
        ] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    private function isPlatformUser($user): bool
    {
        $role = strtolower(str_replace(['-', '_', ' '], '', (string) $user->role));

        return in_array($role, [
            'superadmin',
            'platformadmin',
            'supportadmin',
            'salesadmin',
            'financeadmin',
            'owner',
        ], true);
    }

    private function isLearnerOrParent($user): bool
    {
        $role = strtolower(str_replace(['-', '_', ' '], '', (string) $user->role));

        return in_array($role, ['student', 'parent'], true);
    }
}
