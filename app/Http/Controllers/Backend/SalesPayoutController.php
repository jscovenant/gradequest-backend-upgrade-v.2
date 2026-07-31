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
            'summary' => [
                'pending_commissions' => (float) SalesCommission::where('status', 'pending')->sum('amount'),
                'approved_commissions' => (float) SalesCommission::where('status', 'approved')->sum('amount'),
                'paid_commissions' => (float) SalesCommission::where('status', 'paid')->sum('amount'),
                'processing_payouts' => (float) SalesPayoutBatch::where('status', 'processing')->sum('total_amount'),
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
                    'bank_name' => $rep->bank_name,
                    'bank_code' => $rep->bank_code,
                    'account_number' => $rep->account_number,
                    'account_name' => $rep->account_name,
                    'paystack_recipient_code' => $rep->paystack_recipient_code,
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
                'bank_name' => $rep->bank_name,
                'bank_code' => $rep->bank_code,
                'account_number' => $rep->account_number,
                'account_name' => $rep->account_name,
                'paystack_recipient_code' => $rep->paystack_recipient_code,
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
            'data' => $rep,
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
            'data' => $rep,
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

    public function approvePending(Request $request, SalesRepresentative $salesRepresentative): JsonResponse
    {
        $updated = SalesCommission::query()
            ->where('sales_representative_id', $salesRepresentative->id)
            ->where('status', 'pending')
            ->whereDoesntHave('payoutItem')
            ->update([
                'status' => 'approved',
                'approved_by' => $request->user()?->id,
                'approved_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            return response()->json([
                'message' => 'No pending unpaid commissions found for this representative.',
            ], 422);
        }

        return response()->json([
            'message' => "{$updated} commission record(s) approved for payout.",
            'approved_count' => $updated,
        ]);
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
            'message' => 'Paystack transfer initiated successfully.',
            'data' => $batch,
        ]);
    }

    public function markPaid(SalesPayoutBatch $batch): JsonResponse
    {
        $batch = $this->payoutService->markSuccessful($batch, [
            'manual_confirmation' => true,
            'confirmed_at' => now()->toDateTimeString(),
        ]);

        return response()->json([
            'message' => 'Payout marked as paid successfully.',
            'data' => $batch,
        ]);
    }

    private function currentRepresentative(Request $request): SalesRepresentative
    {
        return SalesRepresentative::query()
            ->where('user_id', $request->user()?->id)
            ->firstOrFail();
    }
}
