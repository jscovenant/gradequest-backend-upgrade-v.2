<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\SalesCommission;
use App\Models\SalesPayoutBatch;
use App\Models\SalesPayoutPolicy;
use App\Models\SalesRepresentative;
use App\Services\SalesPayoutService;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class SalesPayoutController extends Controller
{
    public function __construct(private SalesPayoutService $payoutService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $status = trim((string) $request->get('status', ''));
        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);

        $query = SalesPayoutBatch::query()
            ->with(['representative.user', 'items.commission.school'])
            ->latest();

        if ($status !== '') {
            $query->where('status', $status);
        }

        return response()->json([
            'authorization' => [
                'can_manage_policy' => (bool) $request->user()?->hasSuperAdminPermission('owner'),
                'can_authorize_transfers' => (bool) $request->user()?->hasSuperAdminPermission('owner'),
            ],
            'summary' => [
                'pending_commissions' => (float) SalesCommission::where('status', 'pending')->sum('amount'),
                'approved_commissions' => (float) SalesCommission::where('status', 'approved')->sum('amount'),
                'paid_commissions' => (float) SalesCommission::where('status', 'paid')->sum('amount'),
                'processing_payouts' => (float) SalesPayoutBatch::whereIn('status', ['processing', 'requires_otp', 'awaiting_approval'])->sum('total_amount'),
                'paid_payouts' => (float) SalesPayoutBatch::where('status', 'paid')->sum('total_amount'),
                'held_commissions' => (float) SalesCommission::where('status', 'held')->sum('amount'),
                'queued_commissions' => (float) SalesCommission::where('status', 'queued')->sum('amount'),
            ],
            'policy' => SalesPayoutPolicy::current(),
            'automation' => $this->payoutService->automationSnapshot(),
            'batches' => $query->paginate($perPage),
        ]);
    }

    public function policy(): JsonResponse
    {
        return response()->json([
            'policy' => SalesPayoutPolicy::current(),
            'automation' => $this->payoutService->automationSnapshot(),
        ]);
    }

    public function updatePolicy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'default_commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'minimum_payout_amount' => ['nullable', 'numeric', 'min:0'],
            'monthly_payout_day' => ['nullable', 'integer', 'min:1', 'max:28'],
            'commission_waiting_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'auto_approval_enabled' => ['nullable', 'boolean'],
            'auto_payout_enabled' => ['nullable', 'boolean'],
            'large_commission_review_threshold' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', Rule::in(['NGN'])],
        ]);

        $policy = SalesPayoutPolicy::current();
        $policy->update($data);

        return response()->json([
            'message' => 'Sales payout policy updated successfully.',
            'policy' => $policy->fresh(),
        ]);
    }

    public function representatives(): JsonResponse
    {
        $representatives = SalesRepresentative::query()
            ->with(['user:id,firstname,surname,email,phone', 'commissions'])
            ->where('status', 'active')
            ->orderBy('id')
            ->get()
            ->map(function (SalesRepresentative $rep) {
                return [
                    'id' => $rep->id,
                    'code' => $rep->code,
                    'name' => trim(($rep->user?->firstname ?? '') . ' ' . ($rep->user?->surname ?? '')) ?: $rep->user?->email,
                    'email' => $rep->user?->email,
                    'commission_rate' => (float) $rep->commission_rate,
                    'core_commission_rate' => (float) ($rep->core_commission_rate ?? $rep->commission_rate),
                    'premium_commission_rate' => (float) ($rep->premium_commission_rate ?? $rep->commission_rate),
                    'bank_name' => $rep->bank_name,
                    'bank_code' => $rep->bank_code,
                    'account_number' => $this->maskAccountNumber($rep->account_number),
                    'account_name' => $rep->account_name,
                    'paystack_recipient_code' => $rep->paystack_recipient_code ? 'configured' : null,
                    'payout_verified_at' => optional($rep->payout_verified_at)?->toDateTimeString(),
                    'pending_commission' => (float) $rep->commissions->where('status', 'pending')->sum('amount'),
                    'approved_commission' => (float) $rep->commissions->where('status', 'approved')->sum('amount'),
                    'paid_commission' => (float) $rep->commissions->where('status', 'paid')->sum('amount'),
                ];
            });

        return response()->json(['representatives' => $representatives]);
    }

    public function myProfile(Request $request): JsonResponse
    {
        $rep = $this->currentRepresentative($request)
            ->load(['user:id,firstname,surname,email,phone', 'commissions', 'payoutBatches']);

        return response()->json([
            'representative' => [
                'id' => $rep->id,
                'code' => $rep->code,
                'name' => trim(($rep->user?->firstname ?? '') . ' ' . ($rep->user?->surname ?? '')) ?: $rep->user?->email,
                'email' => $rep->user?->email,
                'commission_rate' => (float) $rep->commission_rate,
                'core_commission_rate' => (float) ($rep->core_commission_rate ?? $rep->commission_rate),
                'premium_commission_rate' => (float) ($rep->premium_commission_rate ?? $rep->commission_rate),
                'bank_name' => $rep->bank_name,
                'bank_code' => $rep->bank_code,
                'account_number' => $this->maskAccountNumber($rep->account_number),
                'account_name' => $rep->account_name,
                'paystack_recipient_code' => $rep->paystack_recipient_code ? 'configured' : null,
                'payout_verified_at' => optional($rep->payout_verified_at)?->toDateTimeString(),
                'pending_commission' => (float) $rep->commissions->where('status', 'pending')->sum('amount'),
                'approved_commission' => (float) $rep->commissions->where('status', 'approved')->sum('amount'),
                'paid_commission' => (float) $rep->commissions->where('status', 'paid')->sum('amount'),
            ],
            'payouts' => $rep->payoutBatches()
                ->latest()
                ->limit(20)
                ->get(),
        ]);
    }

    public function saveMyBank(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bank_name' => ['required', 'string', 'max:180'],
            'bank_code' => ['required', 'string', 'max:20'],
            'account_number' => ['required', 'string', 'size:10'],
        ]);

        try {
            $rep = $this->payoutService->verifyAndSaveBank($this->currentRepresentative($request), $data);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Your payout bank account has been verified successfully.',
            'data' => $this->bankProfile($rep),
        ]);
    }

    public function saveBank(Request $request, SalesRepresentative $salesRepresentative): JsonResponse
    {
        $data = $request->validate([
            'bank_name' => ['required', 'string', 'max:180'],
            'bank_code' => ['required', 'string', 'max:20'],
            'account_number' => ['required', 'string', 'size:10'],
        ]);

        try {
            $rep = $this->payoutService->verifyAndSaveBank($salesRepresentative, $data);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Sales representative bank account verified and payout recipient created.',
            'data' => $this->bankProfile($rep),
        ]);
    }

    public function createBatch(Request $request, SalesRepresentative $salesRepresentative): JsonResponse
    {
        try {
            $batch = $this->payoutService->createBatchForApprovedCommissions(
                $salesRepresentative,
                (int) $request->user()?->id
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Payout batch created successfully.',
            'data' => $batch,
        ], 201);
    }

    public function approveEligible(Request $request): JsonResponse
    {
        $result = $this->payoutService->approveEligibleCommissions((int) $request->user()?->id);

        return response()->json([
            'message' => 'Eligible sales commissions reviewed successfully.',
            'data' => $result,
        ]);
    }

    public function createMonthly(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
        ]);

        try {
            $result = $this->payoutService->createMonthlyPayoutBatches(
                isset($data['period_start']) ? Carbon::parse($data['period_start']) : null,
                isset($data['period_end']) ? Carbon::parse($data['period_end']) : null,
                (int) $request->user()?->id
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Monthly sales payout batch review completed.',
            'data' => $result,
        ], 201);
    }

    public function initiate(SalesPayoutBatch $batch): JsonResponse
    {
        try {
            $batch = $this->payoutService->initiatePaystackTransfer($batch);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => match ($batch->status) {
                'requires_otp' => 'Paystack requires OTP approval. Complete it in Paystack before this payout can proceed.',
                'awaiting_approval' => 'Paystack is waiting for server approval.',
                default => 'Paystack transfer initiated successfully.',
            },
            'data' => $batch,
        ]);
    }

    public function reconcile(SalesPayoutBatch $batch): JsonResponse
    {
        try {
            $batch = $this->payoutService->reconcilePaystackTransfer($batch);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Payout status reconciled with Paystack.',
            'data' => $batch,
        ]);
    }

    private function maskAccountNumber(?string $accountNumber): ?string
    {
        if (! $accountNumber) return null;
        return str_repeat('*', max(strlen($accountNumber) - 4, 0)) . substr($accountNumber, -4);
    }

    private function bankProfile(SalesRepresentative $rep): array
    {
        return [
            'id' => $rep->id,
            'bank_name' => $rep->bank_name,
            'account_name' => $rep->account_name,
            'account_number' => $this->maskAccountNumber($rep->account_number),
            'payout_verified_at' => optional($rep->payout_verified_at)?->toDateTimeString(),
            'recipient_configured' => (bool) $rep->paystack_recipient_code,
        ];
    }

    private function currentRepresentative(Request $request): SalesRepresentative
    {
        return SalesRepresentative::query()
            ->where('user_id', $request->user()?->id)
            ->firstOrFail();
    }
}
