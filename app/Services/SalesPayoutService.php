<?php

namespace App\Services;

use App\Models\SalesCommission;
use App\Models\SalesPayoutBatch;
use App\Models\SalesPayoutItem;
use App\Models\SalesPayoutPolicy;
use App\Models\SalesRepresentative;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class SalesPayoutService
{
    public const PAYABLE_REP_STATUSES = ['active'];
    public const NON_EARNING_REP_STATUSES = ['suspended', 'under_review', 'terminated', 'closed', 'deceased', 'inactive', 'paused'];

    public function verifyAndSaveBank(SalesRepresentative $representative, array $data): SalesRepresentative
    {
        $response = Http::withToken(config('services.paystack.secret'))
            ->get('https://api.paystack.co/bank/resolve', [
                'account_number' => $data['account_number'],
                'bank_code' => $data['bank_code'],
            ]);

        if (! $response->successful() || ! $response->json('status')) {
            throw new RuntimeException($response->json('message') ?: 'Could not verify sales representative bank account.');
        }

        $accountName = $response->json('data.account_name');

        $recipient = Http::withToken(config('services.paystack.secret'))
            ->post('https://api.paystack.co/transferrecipient', [
                'type' => 'nuban',
                'name' => $accountName,
                'account_number' => $data['account_number'],
                'bank_code' => $data['bank_code'],
                'currency' => 'NGN',
                'metadata' => [
                    'sales_representative_id' => $representative->id,
                    'sales_code' => $representative->code,
                ],
            ]);

        if (! $recipient->successful() || ! $recipient->json('status')) {
            throw new RuntimeException($recipient->json('message') ?: 'Could not create Paystack transfer recipient.');
        }

        $representative->update([
            'bank_name' => $data['bank_name'] ?? null,
            'bank_code' => $data['bank_code'],
            'account_number' => $data['account_number'],
            'account_name' => $accountName,
            'paystack_recipient_code' => $recipient->json('data.recipient_code'),
            'payout_verified_at' => now(),
        ]);

        return $representative->fresh(['user', 'commissions']);
    }

    public function createBatchForApprovedCommissions(SalesRepresentative $representative, int $initiatedBy): SalesPayoutBatch
    {
        if (! $representative->paystack_recipient_code) {
            throw new RuntimeException('Sales representative bank details must be verified before payout.');
        }

        return DB::transaction(function () use ($representative, $initiatedBy) {
            $commissions = SalesCommission::query()
                ->where('sales_representative_id', $representative->id)
                ->where('status', 'approved')
                ->whereDoesntHave('payoutItem')
                ->lockForUpdate()
                ->get();

            if ($commissions->isEmpty()) {
                throw new RuntimeException('No approved unpaid commissions found for this representative.');
            }

            $batch = SalesPayoutBatch::create([
                'sales_representative_id' => $representative->id,
                'initiated_by' => $initiatedBy,
                'reference' => 'GQ-PAYOUT-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(6)),
                'total_amount' => (float) $commissions->sum('amount'),
                'commission_count' => $commissions->count(),
                'status' => 'pending',
                'paystack_recipient_code' => $representative->paystack_recipient_code,
            ]);

            foreach ($commissions as $commission) {
                SalesPayoutItem::create([
                    'sales_payout_batch_id' => $batch->id,
                    'sales_commission_id' => $commission->id,
                    'amount' => (float) $commission->amount,
                ]);
            }

            SalesCommission::whereIn('id', $commissions->pluck('id'))->update([
                'status' => 'queued',
                'updated_at' => now(),
            ]);

            return $batch->fresh(['representative.user', 'items.commission.school']);
        });
    }

    public function approveEligibleCommissions(?int $reviewedBy = null): array
    {
        $policy = SalesPayoutPolicy::current();

        if (! $policy->auto_approval_enabled) {
            $policy->update([
                'last_review_at' => now(),
                'last_review_approved_count' => 0,
                'last_review_held_count' => 0,
            ]);

            return [
                'approved' => 0,
                'held' => 0,
                'message' => 'Automatic commission approval is disabled.',
            ];
        }

        $approved = 0;
        $held = 0;
        $now = now();

        SalesCommission::query()
            ->with('representative')
            ->whereIn('status', ['pending', 'held'])
            ->whereDoesntHave('payoutItem')
            ->where(function ($query) use ($now, $policy) {
                $query->whereNotNull('eligible_at')->where('eligible_at', '<=', $now)
                    ->orWhere(function ($fallback) use ($now, $policy) {
                        $fallback->whereNull('eligible_at')
                            ->where('earned_at', '<=', $now->copy()->subDays((int) $policy->commission_waiting_days));
                    });
            })
            ->orderBy('id')
            ->chunkById(100, function ($commissions) use (&$approved, &$held, $policy, $reviewedBy) {
                foreach ($commissions as $commission) {
                    $holdReason = $this->commissionHoldReason($commission, $policy);

                    if ($holdReason) {
                        if ($commission->status !== 'held' || $commission->hold_reason !== $holdReason) {
                            $commission->update([
                                'status' => 'held',
                                'hold_reason' => $holdReason,
                                'reviewed_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                        $held++;
                        continue;
                    }

                    $commission->update([
                        'status' => 'approved',
                        'approved_by' => $reviewedBy,
                        'approved_at' => $commission->approved_at ?: now(),
                        'reviewed_at' => now(),
                        'hold_reason' => null,
                        'payout_period' => $commission->payout_period ?: optional($commission->earned_at)->format('Y-m'),
                        'updated_at' => now(),
                    ]);
                    $approved++;
                }
            });

        $policy->update([
            'last_review_at' => now(),
            'last_review_approved_count' => $approved,
            'last_review_held_count' => $held,
        ]);

        return compact('approved', 'held');
    }

    public function automationSnapshot(): array
    {
        $policy = SalesPayoutPolicy::current();
        $now = now();
        $nextReview = $now->copy()->setTime(2, 0);

        if ($nextReview->lte($now)) {
            $nextReview->addDay();
        }

        $nextPayout = $now->copy()
            ->day(min(max((int) $policy->monthly_payout_day, 1), 28))
            ->setTime(2, 0);

        if ($nextPayout->lte($now)) {
            $nextPayout->addMonthNoOverflow();
        }

        return [
            'auto_review_enabled' => (bool) $policy->auto_approval_enabled,
            'auto_payout_enabled' => (bool) $policy->auto_payout_enabled,
            'next_auto_review_at' => $nextReview->toDateTimeString(),
            'next_payout_run_at' => $nextPayout->toDateTimeString(),
            'last_review_at' => optional($policy->last_review_at)?->toDateTimeString(),
            'last_review_approved_count' => (int) ($policy->last_review_approved_count ?? 0),
            'last_review_held_count' => (int) ($policy->last_review_held_count ?? 0),
            'eligible_commissions_count' => $this->eligibleCommissionQuery()->count(),
            'eligible_commissions_amount' => (float) $this->eligibleCommissionQuery()->sum('amount'),
            'held_exceptions_count' => SalesCommission::where('status', 'held')->count(),
            'held_exceptions_amount' => (float) SalesCommission::where('status', 'held')->sum('amount'),
        ];
    }

    public function createMonthlyPayoutBatches(?CarbonInterface $periodStart = null, ?CarbonInterface $periodEnd = null, ?int $initiatedBy = null): array
    {
        $policy = SalesPayoutPolicy::current();
        $periodStart = $periodStart ? Carbon::parse($periodStart)->startOfDay() : now()->subMonthNoOverflow()->startOfMonth();
        $periodEnd = $periodEnd ? Carbon::parse($periodEnd)->endOfDay() : $periodStart->copy()->endOfMonth();
        $payoutMonth = $periodStart->format('Y-m');

        $this->approveEligibleCommissions($initiatedBy);

        $created = [];
        $carriedForward = [];
        $held = [];

        $representatives = SalesRepresentative::query()
            ->with('user')
            ->whereHas('commissions', function ($query) use ($periodStart, $periodEnd) {
                $query->where('status', 'approved')
                    ->whereDoesntHave('payoutItem')
                    ->whereBetween('earned_at', [$periodStart, $periodEnd]);
            })
            ->get();

        foreach ($representatives as $representative) {
            $commissions = SalesCommission::query()
                ->where('sales_representative_id', $representative->id)
                ->where('status', 'approved')
                ->whereDoesntHave('payoutItem')
                ->whereBetween('earned_at', [$periodStart, $periodEnd])
                ->lockForUpdate()
                ->get();

            if ($commissions->isEmpty()) {
                continue;
            }

            $total = (float) $commissions->sum('amount');
            $repName = trim(($representative->user?->firstname ?? '') . ' ' . ($representative->user?->surname ?? '')) ?: $representative->code;

            if (! in_array((string) $representative->status, self::PAYABLE_REP_STATUSES, true)) {
                SalesCommission::whereIn('id', $commissions->pluck('id'))->update([
                    'status' => 'held',
                    'hold_reason' => 'Sales representative account is not active.',
                    'reviewed_at' => now(),
                    'updated_at' => now(),
                ]);
                $held[] = ['representative_id' => $representative->id, 'name' => $repName, 'amount' => $total, 'reason' => 'Account is not active'];
                continue;
            }

            if (! $representative->paystack_recipient_code || ! $representative->payout_verified_at) {
                $held[] = ['representative_id' => $representative->id, 'name' => $repName, 'amount' => $total, 'reason' => 'Bank account is not verified'];
                continue;
            }

            if ($total < (float) $policy->minimum_payout_amount) {
                $carriedForward[] = [
                    'representative_id' => $representative->id,
                    'name' => $repName,
                    'amount' => $total,
                    'minimum' => (float) $policy->minimum_payout_amount,
                ];
                continue;
            }

            $existingBatch = SalesPayoutBatch::query()
                ->where('sales_representative_id', $representative->id)
                ->where('payout_month', $payoutMonth)
                ->where('batch_type', 'monthly')
                ->whereIn('status', ['pending', 'processing', 'paid'])
                ->first();

            if ($existingBatch) {
                continue;
            }

            $batch = DB::transaction(function () use ($representative, $commissions, $initiatedBy, $periodStart, $periodEnd, $payoutMonth, $policy, $total) {
                $batch = SalesPayoutBatch::create([
                    'sales_representative_id' => $representative->id,
                    'initiated_by' => $initiatedBy,
                    'approved_by' => $initiatedBy,
                    'approved_at' => now(),
                    'reference' => 'GQ-MONTHLY-PAYOUT-' . $payoutMonth . '-' . $representative->id . '-' . Str::upper(Str::random(6)),
                    'period_start' => $periodStart->toDateString(),
                    'period_end' => $periodEnd->toDateString(),
                    'payout_month' => $payoutMonth,
                    'batch_type' => 'monthly',
                    'total_amount' => $total,
                    'minimum_payout_amount' => (float) $policy->minimum_payout_amount,
                    'commission_count' => $commissions->count(),
                    'status' => 'pending',
                    'paystack_recipient_code' => $representative->paystack_recipient_code,
                ]);

                foreach ($commissions as $commission) {
                    SalesPayoutItem::create([
                        'sales_payout_batch_id' => $batch->id,
                        'sales_commission_id' => $commission->id,
                        'amount' => (float) $commission->amount,
                    ]);
                }

                SalesCommission::whereIn('id', $commissions->pluck('id'))->update([
                    'status' => 'queued',
                    'payout_period' => $payoutMonth,
                    'updated_at' => now(),
                ]);

                return $batch->fresh(['representative.user', 'items.commission.school']);
            });

            if ($policy->auto_payout_enabled) {
                try {
                    $batch = $this->initiatePaystackTransfer($batch);
                } catch (RuntimeException $e) {
                    $batch->update([
                        'status' => 'failed',
                        'failure_reason' => $e->getMessage(),
                    ]);
                }
            }

            $created[] = $batch;
        }

        return [
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'payout_month' => $payoutMonth,
            'created_count' => count($created),
            'created' => $created,
            'carried_forward' => $carriedForward,
            'held' => $held,
        ];
    }

    public function initiatePaystackTransfer(SalesPayoutBatch $batch): SalesPayoutBatch
    {
        $batch->loadMissing('representative');

        if (! in_array($batch->status, ['pending', 'failed'], true)) {
            throw new RuntimeException('Only pending or failed payout batches can be initiated.');
        }

        if ((float) $batch->total_amount <= 0) {
            throw new RuntimeException('Payout amount must be greater than zero.');
        }

        $recipientCode = $batch->paystack_recipient_code ?: $batch->representative?->paystack_recipient_code;

        if (! $recipientCode) {
            throw new RuntimeException('Paystack recipient code is missing for this payout.');
        }

        $response = Http::withToken(config('services.paystack.secret'))
            ->post('https://api.paystack.co/transfer', [
                'source' => 'balance',
                'amount' => (int) round(((float) $batch->total_amount) * 100),
                'recipient' => $recipientCode,
                'reason' => 'GradeQuest sales commission payout ' . $batch->reference,
                'reference' => $batch->reference,
            ]);

        $data = $response->json('data') ?: [];

        if (! $response->successful() || ! $response->json('status')) {
            $batch->update([
                'status' => 'failed',
                'paystack_response' => $response->json(),
                'failure_reason' => $response->json('message') ?: 'Could not initiate Paystack transfer.',
            ]);

            throw new RuntimeException($response->json('message') ?: 'Could not initiate Paystack transfer.');
        }

        $batch->update([
            'status' => 'processing',
            'paystack_transfer_code' => $data['transfer_code'] ?? null,
            'paystack_transfer_id' => isset($data['id']) ? (string) $data['id'] : null,
            'paystack_recipient_code' => $recipientCode,
            'paystack_response' => $response->json(),
            'failure_reason' => null,
            'initiated_at' => now(),
        ]);

        return $batch->fresh(['representative.user', 'items.commission.school']);
    }

    public function markSuccessful(SalesPayoutBatch $batch, array $payload = []): SalesPayoutBatch
    {
        return DB::transaction(function () use ($batch, $payload) {
            $locked = SalesPayoutBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'paid') {
                return $locked;
            }

            $locked->update([
                'status' => 'paid',
                'paystack_response' => $payload ?: $locked->paystack_response,
                'failure_reason' => null,
                'paid_at' => now(),
            ]);

            $commissionIds = SalesPayoutItem::where('sales_payout_batch_id', $locked->id)
                ->pluck('sales_commission_id');

            SalesCommission::whereIn('id', $commissionIds)->update([
                'status' => 'paid',
                'paid_at' => now(),
                'updated_at' => now(),
            ]);

            return $locked->fresh(['representative.user', 'items.commission.school']);
        });
    }

    public function markFailed(SalesPayoutBatch $batch, string $reason, array $payload = []): SalesPayoutBatch
    {
        $batch->update([
            'status' => 'failed',
            'failure_reason' => $reason,
            'paystack_response' => $payload ?: $batch->paystack_response,
        ]);

        $commissionIds = SalesPayoutItem::where('sales_payout_batch_id', $batch->id)
            ->pluck('sales_commission_id');

        SalesCommission::whereIn('id', $commissionIds)->update([
            'status' => 'approved',
            'updated_at' => now(),
        ]);

        return $batch->fresh(['representative.user', 'items.commission.school']);
    }

    private function commissionHoldReason(SalesCommission $commission, SalesPayoutPolicy $policy): ?string
    {
        $representative = $commission->representative;

        if (! $representative) {
            return 'Sales representative account is missing.';
        }

        if (! in_array((string) $representative->status, self::PAYABLE_REP_STATUSES, true)) {
            return 'Sales representative account is not active.';
        }

        if ((float) $commission->amount <= 0) {
            return 'Commission amount is zero or invalid.';
        }

        if ((float) $commission->amount >= (float) $policy->large_commission_review_threshold) {
            return 'Commission amount requires manual review.';
        }

        return null;
    }

    private function eligibleCommissionQuery()
    {
        $policy = SalesPayoutPolicy::current();
        $now = now();

        return SalesCommission::query()
            ->whereIn('status', ['pending', 'held'])
            ->whereDoesntHave('payoutItem')
            ->where(function ($query) use ($now, $policy) {
                $query->whereNotNull('eligible_at')->where('eligible_at', '<=', $now)
                    ->orWhere(function ($fallback) use ($now, $policy) {
                        $fallback->whereNull('eligible_at')
                            ->where('earned_at', '<=', $now->copy()->subDays((int) $policy->commission_waiting_days));
                    });
            });
    }
}
