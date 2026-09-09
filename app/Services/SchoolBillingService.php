<?php

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\SchoolProfitBillingPolicy;
use App\Models\SchoolProfitInvoicePayment;
use App\Models\SchoolProfitTermInvoice;
use App\Models\Payment;
use App\Models\SchoolBankAccount;
use App\Models\SchoolSetting;
use App\Models\SchoolBillingAuditLog;
use App\Models\SchoolBillingPeriod;
use App\Models\SchoolBillingSetting;
use App\Models\SchoolBillingTemporaryAccess;
use App\Models\SubPayment;
use App\Models\Subscription;
use App\Models\StudentClass;
use App\Models\StudentBillingEntitlement;
use App\Models\StudentFee;
use App\Models\Term;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SchoolBillingService
{
    public function policy(): SchoolProfitBillingPolicy
    {
        return SchoolProfitBillingPolicy::firstOrCreate([], [
            'online_grace_days' => 14,
            'online_minimum_coverage_percent' => 70,
            'online_whole_school_block_enabled' => true,
            'online_student_level_block_enabled' => true,
            'offline_grace_days' => 7,
            'offline_school_block_enabled' => true,
            'platform_fee_per_student' => 1000,
            'legacy_subscription_honor_enabled' => true,
            'per_student_billing_starts_at' => now(),
            'temporary_access_min_days' => 3,
            'temporary_access_max_days' => 7,
        ]);
    }

    public function settingsForSchool(int $schoolId): SchoolBillingSetting
    {
        $policy = $this->policy();
        $settings = SchoolBillingSetting::firstOrCreate(
            ['school_id' => $schoolId],
            [
                'payment_mode' => 'offline',
                'grace_days' => (int) $policy->offline_grace_days,
                'platform_fee_per_student' => $this->pricePerStudentForSchool($schoolId),
                'block_results_when_unpaid' => true,
            ]
        );

        if (! $settings->block_results_when_unpaid) {
            $settings->forceFill(['block_results_when_unpaid' => true])->save();
        }

        return $settings->fresh();
    }

    public function currentPeriod(int $schoolId): array
    {
        $hasSchoolId = Schema::hasColumn('academic_sessions', 'school_id');

        $session = AcademicSession::query()
            ->when($hasSchoolId, fn ($q) => $q->where('school_id', $schoolId))
            ->where('is_current', 1)
            ->orderByDesc('id')
            ->first();

        if (! $session) {
            $session = AcademicSession::query()
                ->when($hasSchoolId, fn ($q) => $q->where('school_id', $schoolId))
                ->where('status', 'Active')
                ->orderByDesc('id')
                ->first();
        }

        if (! $session) {
            $session = AcademicSession::query()
                ->when($hasSchoolId, fn ($q) => $q->where('school_id', $schoolId))
                ->orderByDesc('id')
                ->first();
        }

        $term = Term::where('school_id', $schoolId)
            ->where('status', 'Active')
            ->orderByRaw('COALESCE(sort_order, 999999) ASC')
            ->orderBy('id')
            ->first();

        return [$session, $term];
    }

    public function canSwitchPaymentMode(int $schoolId): array
    {
        return $this->canSwitchPaymentModeTo($schoolId, null);
    }

    public function canSwitchPaymentModeTo(int $schoolId, ?string $targetMode, ?int $actorId = null): array
    {
        $this->syncOpenInvoicesForSchool($schoolId);
        $this->syncCurrentPeriodEntitlementsWithSubscriptionPayments($schoolId);
        $settings = $this->settingsForSchool($schoolId);
        $legacy = $this->legacySubscriptionProtection($schoolId);

        if ($legacy['active']) {
            return [
                'can_switch' => true,
                'outstanding_invoices' => 0,
                'unpaid_entitlements' => 0,
                'transition_invoice' => null,
                'legacy_subscription' => $legacy,
                'message' => $legacy['message'],
            ];
        }

        // Pay-As-You-Go Architecture: Seamless switching between Online & Offline
        return [
            'can_switch' => true,
            'outstanding_invoices' => 0,
            'unpaid_entitlements' => 0,
            'transition_invoice' => null,
            'message' => 'Payment mode can be changed seamlessly.',
        ];
    }

    protected function syncOpenInvoicesForSchool(int $schoolId): void
    {
        $legacy = $this->legacySubscriptionProtection($schoolId);
        if ($legacy['active']) {
            $this->deferOpenLegacyInvoices($schoolId, $legacy);
            return;
        }

        SchoolProfitTermInvoice::where('school_id', $schoolId)
            ->whereIn('status', ['issued', 'partial', 'overdue', 'paid'])
            ->get()
            ->each(fn (SchoolProfitTermInvoice $invoice) => $this->syncInvoiceWithSubscriptionPayments($invoice));
    }

    protected function syncCurrentPeriodEntitlementsWithSubscriptionPayments(int $schoolId): void
    {
        if ($this->legacySubscriptionProtection($schoolId)['active']) {
            return;
        }

        [$session, $term] = $this->currentPeriod($schoolId);

        if (! $session || ! $term) {
            return;
        }

        $activeStudents = $this->activeStudents($schoolId);
        if ($activeStudents->isEmpty()) {
            return;
        }

        $pricePerStudent = max(1, (float) $this->pricePerStudentForSchool($schoolId));
        $coveredCount = (int) floor($this->subscriptionPaidAmountForSchool($schoolId) / $pricePerStudent);

        if ($coveredCount <= 0) {
            return;
        }

        foreach ($activeStudents->take($coveredCount) as $student) {
            StudentBillingEntitlement::updateOrCreate(
                [
                    'school_id' => $schoolId,
                    'student_id' => $student->id,
                    'session_id' => $session->id,
                    'term_id' => $term->id,
                ],
                [
                    'status' => 'paid',
                    'source' => 'subscription_payment',
                    'covered_at' => now(),
                    'grace_until' => null,
                ]
            );
        }
    }

    public function updateSettings(int $schoolId, array $payload, ?int $actorId = null): SchoolBillingSetting
    {
        $settings = $this->settingsForSchool($schoolId);

        if (isset($payload['payment_mode']) && $payload['payment_mode'] !== $settings->payment_mode) {
            $switch = $this->canSwitchPaymentModeTo($schoolId, $payload['payment_mode'], $actorId);
            if (! $switch['can_switch']) {
                abort(422, $switch['message']);
            }
        }

        return DB::transaction(function () use ($schoolId, $payload, $actorId, $settings) {
            $settings = $settings->fresh();
            $before = $settings->toArray();

            if (isset($payload['payment_mode']) && $payload['payment_mode'] !== $settings->payment_mode) {
                $payload['switched_at'] = now();
                $payload['switched_by'] = $actorId;
            }

            $settings->update($payload);

            if (isset($payload['payment_mode'])) {
                $this->syncBankAccountsForPaymentMode($schoolId, $payload['payment_mode']);
            }

            $this->audit($schoolId, $actorId, 'billing_settings_updated', $settings, $before, $settings->fresh()->toArray());

            return $settings->fresh();
        });
    }

    protected function syncBankAccountsForPaymentMode(int $schoolId, string $paymentMode): void
    {
        if ($paymentMode === 'offline') {
            SchoolBankAccount::withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->update(['online_payment_enabled' => false]);

            return;
        }

        $onlineAccount = SchoolBankAccount::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('is_active', true)
            ->whereNotNull('paystack_subaccount_code')
            ->orderBy('sort_order')
            ->latest()
            ->first();

        if (! $onlineAccount) {
            abort(422, 'Please add and verify an online bank account before switching to online payment.');
        }

        SchoolBankAccount::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->update(['online_payment_enabled' => false]);

        $onlineAccount->forceFill(['online_payment_enabled' => true])->save();
    }

    public function generateOfflineInvoice(int $schoolId, int $sessionId, int $termId, ?int $actorId = null): SchoolProfitTermInvoice
    {
        $legacy = $this->legacySubscriptionProtection($schoolId);
        if ($legacy['active']) {
            $this->deferOpenLegacyInvoices($schoolId, $legacy);
            throw \Illuminate\Validation\ValidationException::withMessages([
                'billing' => $legacy['message'],
            ]);
        }

        return DB::transaction(function () use ($schoolId, $sessionId, $termId, $actorId) {
            $settings = $this->settingsForSchool($schoolId);
            $students = $this->activeStudents($schoolId);
            $billingProfile = $this->billingProfile($schoolId);

            $amountDue = $students->count() * (float) $billingProfile['price_per_student'];

            $invoice = SchoolProfitTermInvoice::firstOrNew([
                'school_id' => $schoolId,
                'session_id' => $sessionId,
                'term_id' => $termId,
                'billing_mode' => 'offline',
                'invoice_type' => 'term_invoice',
            ]);

            if (! $invoice->exists) {
                $invoice->invoice_no = $this->invoiceNumber();
                $invoice->amount_paid = 0;
                $invoice->created_by = $actorId;
            }

            $amountPaid = min(max((float) ($invoice->amount_paid ?? 0), $this->subscriptionPaidAmountForSchool($schoolId)), $amountDue);
            $balance = max(0, $amountDue - $amountPaid);

            $invoice->fill([
                'active_students_count' => $students->count(),
                'amount_due' => $amountDue,
                'amount_paid' => $amountPaid,
                'balance' => $balance,
                'status' => $balance <= 0 ? 'paid' : ($amountPaid > 0 ? 'partial' : 'issued'),
                'issued_at' => now()->toDateString(),
                'due_date' => now()->addDays((int) $settings->grace_days)->toDateString(),
                'meta' => $billingProfile,
            ])->save();

            $invoice = $invoice->fresh();

            foreach ($students as $student) {
                $entitlement = StudentBillingEntitlement::firstOrNew(
                    [
                        'school_id' => $schoolId,
                        'student_id' => $student->id,
                        'session_id' => $sessionId,
                        'term_id' => $termId,
                    ]
                );

                if (! $entitlement->exists || in_array($entitlement->status, ['unpaid', 'grace'], true)) {
                    $entitlement->fill([
                        'billing_mode' => 'offline',
                        'status' => now()->lte($invoice->due_date) ? 'grace' : 'unpaid',
                        'source' => 'offline_invoice',
                        'invoice_id' => $invoice->id,
                        'grace_until' => $invoice->due_date?->endOfDay(),
                    ])->save();
                }
            }

            $this->allocateInvoicePayment($invoice->fresh());
            $this->audit($schoolId, $actorId, 'offline_invoice_generated', $invoice->fresh());

            return $invoice->fresh();
        });
    }

    public function generateOnlineToOfflineTransitionInvoice(int $schoolId, ?int $actorId = null): ?SchoolProfitTermInvoice
    {
        [$session, $term] = $this->currentPeriod($schoolId);

        if (! $session || ! $term) {
            return null;
        }

        return DB::transaction(function () use ($schoolId, $session, $term, $actorId) {
            $uncovered = StudentBillingEntitlement::where('school_id', $schoolId)
                ->whereIn('status', ['unpaid', 'grace'])
                ->lockForUpdate()
                ->get();

            if ($uncovered->isEmpty()) {
                return null;
            }

            $pricePerStudent = (float) $this->pricePerStudentForSchool($schoolId);
            $amountDue = $uncovered->count() * $pricePerStudent;

            $invoice = SchoolProfitTermInvoice::firstOrNew([
                'school_id' => $schoolId,
                'session_id' => $session->id,
                'term_id' => $term->id,
                'billing_mode' => 'offline',
                'invoice_type' => 'online_to_offline_transition',
            ]);

            if (! $invoice->exists) {
                $invoice->invoice_no = $this->invoiceNumber();
                $invoice->amount_paid = 0;
                $invoice->created_by = $actorId;
            }

            $paid = min((float) ($invoice->amount_paid ?? 0), $amountDue);
            $balance = max(0, $amountDue - $paid);

            $invoice->fill([
                'active_students_count' => $uncovered->count(),
                'amount_due' => $amountDue,
                'amount_paid' => $paid,
                'balance' => $balance,
                'status' => $balance <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'issued'),
                'issued_at' => now()->toDateString(),
                'due_date' => now()->toDateString(),
                'meta' => [
                    'reason' => 'online_to_offline_transition',
                    'uncovered_entitlement_ids' => $uncovered->pluck('id')->values(),
                    'uncovered_students_count' => $uncovered->count(),
                    'price_per_student' => $pricePerStudent,
                ],
            ])->save();

            $uncovered->each(function (StudentBillingEntitlement $entitlement) use ($invoice) {
                $entitlement->update([
                    'invoice_id' => $invoice->id,
                    'reason' => 'Online to offline transition invoice pending.',
                ]);
            });

            $this->audit($schoolId, $actorId, 'online_to_offline_transition_invoice_created', $invoice->fresh());

            return $invoice->fresh();
        });
    }

    public function generateSessionInvoice(int $schoolId, int $sessionId, ?int $actorId = null): SchoolProfitTermInvoice
    {
        $legacy = $this->legacySubscriptionProtection($schoolId);
        if ($legacy['active']) {
            $this->deferOpenLegacyInvoices($schoolId, $legacy);
            throw \Illuminate\Validation\ValidationException::withMessages([
                'billing' => $legacy['message'],
            ]);
        }

        return DB::transaction(function () use ($schoolId, $sessionId, $actorId) {
            $settings = $this->settingsForSchool($schoolId);
            $students = $this->activeStudents($schoolId);
            $billingProfile = $this->billingProfile($schoolId);
            $terms = Term::where('school_id', $schoolId)->whereNull('archived_at')->orderBy('id')->get();
            $termsCount = max(1, $terms->count());

            $pricePerStudent = (float) $billingProfile['price_per_student'];
            $totalStudentTerms = $students->count() * $termsCount;
            $amountDue = $totalStudentTerms * $pricePerStudent;

            $invoice = SchoolProfitTermInvoice::firstOrNew([
                'school_id' => $schoolId,
                'session_id' => $sessionId,
                'term_id' => null,
                'billing_mode' => 'offline',
                'invoice_type' => 'session_invoice',
            ]);

            if (! $invoice->exists) {
                $invoice->invoice_no = $this->invoiceNumber();
                $invoice->amount_paid = 0;
                $invoice->created_by = $actorId;
            }

            $amountPaid = min((float) ($invoice->amount_paid ?? 0), $amountDue);
            $balance = max(0, $amountDue - $amountPaid);

            $invoice->fill([
                'active_students_count' => $students->count(),
                'amount_due' => $amountDue,
                'amount_paid' => $amountPaid,
                'balance' => $balance,
                'status' => $balance <= 0 ? 'paid' : ($amountPaid > 0 ? 'partial' : 'issued'),
                'issued_at' => now()->toDateString(),
                'due_date' => now()->addDays((int) $settings->grace_days)->toDateString(),
                'meta' => array_merge($billingProfile, [
                    'terms_count' => $termsCount,
                    'total_student_terms' => $totalStudentTerms,
                    'is_full_session' => true,
                ]),
            ])->save();

            $invoice = $invoice->fresh();

            foreach ($terms as $term) {
                foreach ($students as $student) {
                    $entitlement = StudentBillingEntitlement::firstOrNew(
                        [
                            'school_id' => $schoolId,
                            'student_id' => $student->id,
                            'session_id' => $sessionId,
                            'term_id' => $term->id,
                        ]
                    );

                    if (! $entitlement->exists || in_array($entitlement->status, ['unpaid', 'grace'], true)) {
                        $entitlement->fill([
                            'billing_mode' => 'offline',
                            'status' => now()->lte($invoice->due_date) ? 'grace' : 'unpaid',
                            'source' => 'offline_session_invoice',
                            'invoice_id' => $invoice->id,
                            'grace_until' => $invoice->due_date?->endOfDay(),
                        ])->save();
                    }
                }
            }

            $this->allocateInvoicePayment($invoice->fresh());
            $this->audit($schoolId, $actorId, 'session_invoice_generated', $invoice->fresh());

            return $invoice->fresh();
        });
    }

    public function recordOfflineInvoicePayment(SchoolProfitTermInvoice $invoice, float $amount, ?int $actorId = null, ?string $reason = null): SchoolProfitTermInvoice
    {
        return DB::transaction(function () use ($invoice, $amount, $actorId, $reason) {
            $invoice = SchoolProfitTermInvoice::lockForUpdate()->findOrFail($invoice->id);
            $before = $invoice->toArray();

            $paid = min((float) $invoice->amount_due, (float) $invoice->amount_paid + $amount);
            $balance = max(0, (float) $invoice->amount_due - $paid);

            $invoice->update([
                'amount_paid' => $paid,
                'balance' => $balance,
                'status' => $balance <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'issued'),
            ]);

        $this->allocateInvoicePayment($invoice->fresh());
        $this->audit($invoice->school_id, $actorId, 'offline_invoice_payment_recorded', $invoice->fresh(), $before, $invoice->fresh()->toArray(), $reason);

        try {
            app(SalesCommissionService::class)->recordTermInvoiceCommission($invoice->fresh(), (float) $amount);
        } catch (\Throwable $e) {
            // Commission logging should not break billing transaction
        }

            return $invoice->fresh();
        });
    }

    public function applyOnlineInvoicePayment(SchoolProfitTermInvoice $invoice, float $amount, ?int $actorId = null, ?string $reference = null): SchoolProfitTermInvoice
    {
        return DB::transaction(function () use ($invoice, $amount, $actorId, $reference) {
            $invoice = SchoolProfitTermInvoice::lockForUpdate()->findOrFail($invoice->id);
            $before = $invoice->toArray();

            $paid = min((float) $invoice->amount_due, (float) $invoice->amount_paid + $amount);
            $balance = max(0, (float) $invoice->amount_due - $paid);

            $invoice->update([
                'amount_paid' => $paid,
                'balance' => $balance,
                'status' => $balance <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'issued'),
            ]);

            $this->allocateInvoicePayment($invoice->fresh());
            $this->audit($invoice->school_id, $actorId, 'online_invoice_payment_confirmed', $invoice->fresh(), $before, $invoice->fresh()->toArray(), $reference);

            try {
                $payment = SchoolProfitInvoicePayment::where('reference', $reference)->first();
                app(SalesCommissionService::class)->recordTermInvoiceCommission($invoice->fresh(), (float) $amount, $payment);
            } catch (\Throwable $e) {
                // Commission logging should not break billing transaction
            }

            return $invoice->fresh();
        });
    }

    public function applyWalletInvoicePayment(SchoolProfitTermInvoice $invoice, float $amount, ?int $actorId = null): SchoolProfitTermInvoice
    {
        return DB::transaction(function () use ($invoice, $amount, $actorId) {
            $invoice = SchoolProfitTermInvoice::lockForUpdate()->findOrFail($invoice->id);
            $amount = min((float) $invoice->balance, max(1, $amount));

            $reference = 'SP_WAL_INV_' . strtoupper(Str::random(10));
            $description = "Wallet payment for Invoice #{$invoice->invoice_no} (₦" . number_format($amount, 2) . ")";

            // Debit school wallet
            app(WalletService::class)->debitSchoolWalletOrFail(
                $invoice->school_id,
                $amount,
                $actorId ?? 0,
                $description,
                $reference
            );

            // Record invoice payment
            SchoolProfitInvoicePayment::create([
                'school_id' => $invoice->school_id,
                'invoice_id' => $invoice->id,
                'user_id' => $actorId,
                'reference' => $reference,
                'amount' => $amount,
                'status' => 'successful',
                'channel' => 'wallet',
                'paid_at' => now(),
                'paystack_response' => [
                    'channel' => 'wallet',
                    'reference' => $reference,
                    'amount' => $amount,
                    'settled_at' => now()->toIso8601String(),
                ],
            ]);

            $before = $invoice->toArray();
            $paid = min((float) $invoice->amount_due, (float) $invoice->amount_paid + $amount);
            $balance = max(0, (float) $invoice->amount_due - $paid);

            $invoice->update([
                'amount_paid' => $paid,
                'balance' => $balance,
                'status' => $balance <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'issued'),
            ]);

            $this->allocateInvoicePayment($invoice->fresh());
            $this->audit($invoice->school_id, $actorId, 'wallet_invoice_payment_confirmed', $invoice->fresh(), $before, $invoice->fresh()->toArray(), $reference);

            return $invoice->fresh();
        });
    }

    public function markOnlineEntitlementFromPayment(Payment $payment): void
    {
        if ((float) $payment->platform_fee <= 0 || $payment->status !== 'success') {
            return;
        }

        $studentFee = StudentFee::find($payment->student_fee_id);
        if (! $studentFee) {
            return;
        }

        StudentBillingEntitlement::updateOrCreate(
            [
                'school_id' => $studentFee->school_id,
                'student_id' => $studentFee->student_id,
                'session_id' => $studentFee->session_id,
                'term_id' => $studentFee->term_id,
            ],
            [
                'billing_mode' => 'online',
                'status' => 'paid',
                'source' => 'online_fee',
                'student_fee_id' => $studentFee->id,
                'covered_at' => now(),
                'grace_until' => null,
            ]
        );
    }

    public function resultEntryStatus(int $schoolId, int $studentId, string $sessionName, string $termName): array
    {
        $session = AcademicSession::where('school_id', $schoolId)->where('name', $sessionName)->first();
        $term = Term::where('school_id', $schoolId)->where('name', $termName)->first();

        if ($session && $term) {
            $entitlement = $this->ensureEntitlement($schoolId, $studentId, $session->id, $term->id);
            $isCleared = in_array($entitlement->status, ['paid', 'override'], true);
            $fee = $this->pricePerStudentForSchool($schoolId);

            return [
                'allowed' => $isCleared,
                'status' => $isCleared ? 'cleared' : 'payment_required',
                'fee_amount' => $fee,
                'message' => $isCleared
                    ? 'Student is cleared for score entry and report cards.'
                    : "Result Entry Locked: Student fee clearance (₦" . number_format($fee, 2) . ") is required for this term. Please clear student from wallet or collect tuition online.",
            ];
        }

        return [
            'allowed' => true,
            'status' => 'cleared',
            'message' => 'Student result entry is cleared.',
        ];
    }

    public function studentAcademicClearanceStatus(int $schoolId, int $studentId): array
    {
        $legacy = $this->legacySubscriptionProtection($schoolId);

        if ($legacy['active']) {
            return [
                'allowed' => true,
                'status' => 'legacy_subscription_honored',
                'blocked_terms_count' => 0,
                'blocked_terms' => collect(),
                'legacy_subscription' => $legacy,
                'message' => $legacy['message'],
            ];
        }

        if ($this->activeTemporaryAccess($schoolId, 'student_academic')) {
            return [
                'allowed' => true,
                'status' => 'temporary_access',
                'blocked_terms_count' => 0,
                'blocked_terms' => collect(),
                'message' => 'Temporary access is active for student academic actions.',
            ];
        }

        [$session, $term] = $this->currentPeriod($schoolId);

        if ($session && $term) {
            $this->ensureEntitlement($schoolId, $studentId, $session->id, $term->id);
        }

        $blocked = StudentBillingEntitlement::query()
            ->leftJoin('academic_sessions', 'academic_sessions.id', '=', 'student_billing_entitlements.session_id')
            ->leftJoin('terms', 'terms.id', '=', 'student_billing_entitlements.term_id')
            ->where('student_billing_entitlements.school_id', $schoolId)
            ->where('student_billing_entitlements.student_id', $studentId)
            ->whereNotIn('student_billing_entitlements.status', ['paid', 'override'])
            ->orderByDesc('student_billing_entitlements.updated_at')
            ->get([
                'student_billing_entitlements.id',
                'student_billing_entitlements.status',
                'student_billing_entitlements.billing_mode',
                'student_billing_entitlements.grace_until',
                'academic_sessions.name as session',
                'terms.name as term',
            ]);

        return [
            'allowed' => $blocked->isEmpty(),
            'status' => $blocked->isEmpty() ? 'clear' : 'blocked',
            'blocked_terms_count' => $blocked->count(),
            'blocked_terms' => $blocked,
            'message' => $blocked->isEmpty()
                ? 'Student billing is clear.'
                : 'Access denied. This student has outstanding fees for one or more current or previous academic periods.',
        ];
    }

    protected function activeTemporaryAccess(int $schoolId, string $scope): ?SchoolBillingTemporaryAccess
    {
        return SchoolBillingTemporaryAccess::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('status', 'active')
            ->where(function ($query) use ($scope) {
                $query->where('scope', $scope)->orWhere('scope', 'all');
            })
            ->where(function ($query) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where('ends_at', '>', now())
            ->latest('ends_at')
            ->first();
    }

    public function billingPeriodFor(int $schoolId, AcademicSession $session, Term $term, ?int $actorId = null, string $source = 'system'): SchoolBillingPeriod
    {
        $policy = $this->policy();
        $academicStart = $term->start_date ?: $session->start_date;
        $eventDate = now()->startOfDay();
        $academicStartDate = $academicStart ? \Carbon\Carbon::parse($academicStart)->startOfDay() : null;
        $billingStart = $academicStartDate && $academicStartDate->lte($eventDate)
            ? $academicStartDate
            : $eventDate;
        $days = $this->settingsForSchool($schoolId)->payment_mode === 'online'
            ? (int) $policy->online_grace_days
            : (int) $policy->offline_grace_days;

        $flags = $this->billingPeriodFlags($academicStartDate, $eventDate, $source);

        $period = SchoolBillingPeriod::withoutGlobalScopes()->firstOrCreate(
            [
                'school_id' => $schoolId,
                'session_id' => $session->id,
                'term_id' => $term->id,
            ],
            [
                'academic_start_date' => $academicStart,
                'billing_started_at' => $billingStart,
                'billing_grace_ends_at' => $days > 0 ? $billingStart->copy()->addDays($days)->endOfDay() : null,
                'status' => 'active',
                'source' => $source,
                'term_activated_at' => $source === 'term_activated' ? now() : null,
                'first_protected_activity_at' => $source === 'protected_feature' ? now() : null,
                'locked_at' => now(),
                'locked_by' => $actorId,
                'created_by' => $actorId,
                'suspicious_flags' => $flags,
                'flagged_at' => ! empty($flags) ? now() : null,
                'meta' => [
                    'term_start_date' => $term->start_date,
                    'session_start_date' => $session->start_date,
                ],
            ]
        );

        $updates = [];
        $existingFlags = $period->suspicious_flags ?: [];
        $mergedFlags = array_values(array_unique(array_merge($existingFlags, $flags)));

        if ($source === 'term_activated' && ! $period->term_activated_at) {
            $updates['term_activated_at'] = now();
        }

        if ($source === 'protected_feature' && ! $period->first_protected_activity_at) {
            $updates['first_protected_activity_at'] = now();
        }

        if ($period->billing_started_at && $period->billing_started_at->gt($billingStart)) {
            $updates['billing_started_at'] = $billingStart;
            $updates['billing_grace_ends_at'] = $days > 0 ? $billingStart->copy()->addDays($days)->endOfDay() : null;
            $updates['source'] = $source;
            $mergedFlags[] = 'billing_start_moved_earlier_by_platform_event';
        }

        if ($period->academic_start_date && $academicStart && $period->academic_start_date->toDateString() !== $academicStartDate?->toDateString()) {
            $mergedFlags[] = 'term_start_changed_after_billing_period_created';
        }

        $mergedFlags = array_values(array_unique($mergedFlags));
        if ($mergedFlags !== $existingFlags) {
            $updates['suspicious_flags'] = $mergedFlags;
            $updates['flagged_at'] = now();
        }

        if ($updates) {
            $period->forceFill($updates)->save();
        }

        return $period->fresh();
    }

    protected function billingPeriodFlags($academicStartDate, $eventDate, string $source): array
    {
        $flags = [];

        if ($academicStartDate && $academicStartDate->gt($eventDate)) {
            $flags[] = 'declared_start_date_is_in_future';
        }

        if ($source === 'protected_feature' && $academicStartDate && $academicStartDate->gt($eventDate)) {
            $flags[] = 'protected_activity_before_declared_start_date';
        }

        if ($source === 'term_activated' && $academicStartDate && $academicStartDate->gt($eventDate)) {
            $flags[] = 'term_activated_before_declared_start_date';
        }

        return $flags;
    }

    protected function periodGraceUntil(int $schoolId, AcademicSession $session, Term $term, string $paymentMode, SchoolProfitBillingPolicy $policy)
    {
        $days = $paymentMode === 'online'
            ? (int) $policy->online_grace_days
            : (int) $policy->offline_grace_days;

        if ($days <= 0) {
            return null;
        }

        $billingPeriod = $this->billingPeriodFor($schoolId, $session, $term, null, 'protected_feature');

        if (! $billingPeriod->billing_grace_ends_at) {
            $billingPeriod->forceFill([
                'billing_grace_ends_at' => $billingPeriod->billing_started_at->copy()->addDays($days)->endOfDay(),
            ])->save();
        }

        return $billingPeriod->billing_grace_ends_at;
    }

    public function schoolCrudClearanceStatus(int $schoolId): array
    {
        [$session, $term] = $this->currentPeriod($schoolId);
        $settings = $this->settingsForSchool($schoolId);
        $policy = $this->policy();
        $legacy = $this->legacySubscriptionProtection($schoolId);

        if ($legacy['active']) {
            $this->deferOpenLegacyInvoices($schoolId, $legacy);

            return [
                'allowed' => true,
                'status' => 'legacy_subscription_honored',
                'payment_mode' => $settings->payment_mode,
                'blocked_invoices' => 0,
                'blocked_entitlements' => 0,
                'sample_students' => collect(),
                'coverage' => [
                    'current_total' => $this->activeStudents($schoolId)->count(),
                    'current_covered' => $this->activeStudents($schoolId)->count(),
                    'current_uncovered' => 0,
                    'coverage_percent' => 100,
                    'minimum_required_percent' => 100,
                    'grace_until' => $legacy['ends_at'] ?? null,
                    'grace_expired' => false,
                ],
                'legacy_subscription' => $legacy,
                'message' => $legacy['message'],
            ];
        }

        $override = $this->activeTemporaryAccess($schoolId, 'school_crud');

        if ($session && $term) {
            foreach ($this->activeStudents($schoolId) as $student) {
                $this->ensureEntitlement($schoolId, $student->id, $session->id, $term->id);
            }
        }

        $this->syncOpenInvoicesForSchool($schoolId);
        $this->syncCurrentPeriodEntitlementsWithSubscriptionPayments($schoolId);

        SchoolProfitTermInvoice::query()
            ->where('school_id', $schoolId)
            ->whereIn('status', ['issued', 'partial'])
            ->where('balance', '>', 0)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->update(['status' => 'overdue']);

        $blockedInvoices = SchoolProfitTermInvoice::query()
            ->where('school_id', $schoolId)
            ->whereIn('status', ['issued', 'partial', 'overdue'])
            ->where('balance', '>', 0)
            ->count();

        $blockedEntitlementsQuery = StudentBillingEntitlement::query()
            ->where('school_id', $schoolId)
            ->whereNotIn('status', ['paid', 'override']);

        $blockedEntitlements = (clone $blockedEntitlementsQuery)->count();

        $currentTotal = 0;
        $currentCovered = 0;
        $currentUncovered = 0;
        $coveragePercent = 100;
        $graceUntil = null;
        $graceExpired = false;

        if ($session && $term) {
            $currentBase = StudentBillingEntitlement::query()
                ->where('school_id', $schoolId)
                ->where('session_id', $session->id)
                ->where('term_id', $term->id);

            $currentTotal = (clone $currentBase)->count();
            $currentCovered = (clone $currentBase)->whereIn('status', ['paid', 'override'])->count();
            $currentUncovered = max(0, $currentTotal - $currentCovered);
            $coveragePercent = $currentTotal > 0 ? round(($currentCovered / $currentTotal) * 100, 2) : 100;
            $graceUntil = $this->periodGraceUntil($schoolId, $session, $term, $settings->payment_mode, $policy);
            $graceExpired = $graceUntil ? now()->gt($graceUntil) : false;
        }

        $sampleStudents = StudentBillingEntitlement::query()
            ->join('users', 'users.id', '=', 'student_billing_entitlements.student_id')
            ->leftJoin('academic_sessions', 'academic_sessions.id', '=', 'student_billing_entitlements.session_id')
            ->leftJoin('terms', 'terms.id', '=', 'student_billing_entitlements.term_id')
            ->where('student_billing_entitlements.school_id', $schoolId)
            ->whereNotIn('student_billing_entitlements.status', ['paid', 'override'])
            ->orderByDesc('student_billing_entitlements.updated_at')
            ->limit(5)
            ->get([
                'users.firstname',
                'users.surname',
                'users.reg_no',
                'academic_sessions.name as session',
                'terms.name as term',
                'student_billing_entitlements.status',
            ]);

        if ($override) {
            return [
                'allowed' => true,
                'status' => 'temporary_access',
                'payment_mode' => $settings->payment_mode,
                'blocked_invoices' => $blockedInvoices,
                'blocked_entitlements' => $blockedEntitlements,
                'sample_students' => $sampleStudents,
                'coverage' => [
                    'current_total' => $currentTotal,
                    'current_covered' => $currentCovered,
                    'current_uncovered' => $currentUncovered,
                    'coverage_percent' => $coveragePercent,
                    'minimum_required_percent' => (int) $policy->online_minimum_coverage_percent,
                    'grace_until' => $graceUntil?->toDateTimeString(),
                    'grace_expired' => $graceExpired,
                ],
                'temporary_access' => $override,
                'message' => 'Temporary access is active.',
            ];
        }

        if ($settings->payment_mode === 'online') {
            $shouldBlockSchool = (bool) $policy->online_whole_school_block_enabled
                && $graceExpired
                && $coveragePercent < (int) $policy->online_minimum_coverage_percent;

            return [
                'allowed' => ! $shouldBlockSchool,
                'status' => $shouldBlockSchool ? 'blocked' : 'student_level_enforcement',
                'payment_mode' => 'online',
                'blocked_invoices' => 0,
                'blocked_entitlements' => $blockedEntitlements,
                'sample_students' => $sampleStudents,
                'coverage' => [
                    'current_total' => $currentTotal,
                    'current_covered' => $currentCovered,
                    'current_uncovered' => $currentUncovered,
                    'coverage_percent' => $coveragePercent,
                    'minimum_required_percent' => (int) $policy->online_minimum_coverage_percent,
                    'grace_until' => $graceUntil?->toDateTimeString(),
                    'grace_expired' => $graceExpired,
                ],
                'message' => $shouldBlockSchool
                    ? 'Access denied. Current term payment coverage is below the required threshold.'
                    : 'School operations are allowed. Student-specific academic actions remain protected for uncovered students.',
            ];
        }

        $shouldBlockOffline = (bool) $policy->offline_school_block_enabled
            && $graceExpired
            && ($blockedInvoices > 0 || $blockedEntitlements > 0);

        $allowed = ! $shouldBlockOffline;

        return [
            'allowed' => $allowed,
            'status' => $allowed ? ($graceExpired ? 'clear' : 'grace_period_active') : 'blocked',
            'payment_mode' => 'offline',
            'blocked_invoices' => $blockedInvoices,
            'blocked_entitlements' => $blockedEntitlements,
            'sample_students' => $sampleStudents,
            'coverage' => [
                'current_total' => $currentTotal,
                'current_covered' => $currentCovered,
                'current_uncovered' => $currentUncovered,
                'coverage_percent' => $coveragePercent,
                'minimum_required_percent' => 100,
                'grace_until' => $graceUntil?->toDateTimeString(),
                'grace_expired' => $graceExpired,
            ],
            'message' => $allowed
                ? ($graceExpired ? 'Billing is clear.' : 'School operations are allowed under active grace period.')
                : 'Access denied. Please settle all outstanding fees or debt before continuing.',
        ];
    }

    public function dashboard(int $schoolId): array
    {
        [$session, $term] = $this->currentPeriod($schoolId);
        $settings = $this->settingsForSchool($schoolId);
        $billingProfile = $this->billingProfile($schoolId);
        $legacy = $this->legacySubscriptionProtection($schoolId);

        if (! $session || ! $term) {
            return [
                'settings' => $settings,
                'period' => null,
                'summary' => ['total' => 0, 'paid' => 0, 'unpaid' => 0, 'grace' => 0, 'waived' => 0, 'override' => 0],
                'package' => $billingProfile['package'],
                'price_per_student' => $billingProfile['price_per_student'],
                'active_student_count' => $billingProfile['active_student_count'],
                'billable_student_count' => $billingProfile['billable_student_count'],
                'student_limit' => $billingProfile['student_limit'],
                'current_invoice_amount' => $billingProfile['current_invoice_amount'],
                'next_billing_estimate_amount' => $billingProfile['current_invoice_amount'],
                'revenue_model' => $billingProfile['revenue_model'],
                'legacy_subscription' => $legacy['active'] ? $legacy : null,
                'online_collection' => $this->onlineCollectionSummary($schoolId, null, null),
                'unpaid_students' => [],
                'invoice' => null,
                'transition_invoice' => $this->transitionInvoiceForSchool($schoolId),
                'switch_check' => $this->canSwitchPaymentMode($schoolId),
            ];
        }

        if ($legacy['active']) {
            $this->deferOpenLegacyInvoices($schoolId, $legacy);
            $activeStudentCount = $this->activeStudents($schoolId)->count();

            return [
                'settings' => $settings,
                'period' => ['session_id' => $session->id, 'session' => $session->name, 'term_id' => $term->id, 'term' => $term->name],
                'package' => $billingProfile['package'],
                'price_per_student' => $billingProfile['price_per_student'],
                'active_student_count' => $billingProfile['active_student_count'],
                'billable_student_count' => $billingProfile['billable_student_count'],
                'student_limit' => $billingProfile['student_limit'],
                'current_invoice_amount' => $billingProfile['current_invoice_amount'],
                'next_billing_estimate_amount' => $billingProfile['current_invoice_amount'],
                'revenue_model' => $billingProfile['revenue_model'],
                'legacy_subscription' => $legacy,
                'online_collection' => $this->onlineCollectionSummary($schoolId, $session->id, $term->id),
                'current_period_online_collected_amount' => 0,
                'current_period_invoice_paid_amount' => 0,
                'current_period_subscription_credit_amount' => 0,
                'current_period_paid_amount' => 0,
                'current_period_balance_amount' => 0,
                'subscription_paid_amount' => 0,
                'outstanding_amount' => 0,
                'summary' => [
                    'total' => $activeStudentCount,
                    'paid' => $activeStudentCount,
                    'unpaid' => 0,
                    'grace' => 0,
                    'waived' => 0,
                    'override' => 0,
                ],
                'unpaid_students' => [],
                'invoice' => null,
                'transition_invoice' => null,
                'switch_check' => [
                    'can_switch' => true,
                    'outstanding_invoices' => 0,
                    'unpaid_entitlements' => 0,
                    'transition_invoice' => null,
                    'message' => $legacy['message'],
                ],
            ];
        }

        foreach ($this->activeStudents($schoolId) as $student) {
            $this->ensureEntitlement($schoolId, $student->id, $session->id, $term->id);
        }

        $this->syncCurrentPeriodEntitlementsWithSubscriptionPayments($schoolId);
        $subscriptionPaidAmount = $this->subscriptionPaidAmountForSchool($schoolId);
        $outstandingAmount = max(0, (float) $billingProfile['current_invoice_amount'] - $subscriptionPaidAmount);
        $subscriptionCreditAmount = min((float) $billingProfile['current_invoice_amount'], $subscriptionPaidAmount);

$summaryBase = StudentBillingEntitlement::query()
    ->where('student_billing_entitlements.school_id', $schoolId)
    ->where('student_billing_entitlements.session_id', $session->id)
    ->where('student_billing_entitlements.term_id', $term->id);

$summary = [
    'total' => (clone $summaryBase)->count(),
    'paid' => (clone $summaryBase)->where('status', 'paid')->count(),
    'unpaid' => (clone $summaryBase)->where('status', 'unpaid')->count(),
    'grace' => (clone $summaryBase)->where('status', 'grace')->count(),
    'waived' => (clone $summaryBase)->where('status', 'waived')->count(),
    'override' => (clone $summaryBase)->where('status', 'override')->count(),
];

$currentPeriodOnlineCollectedAmount = (float) $this->onlineCollectionSummary($schoolId, $session->id, $term->id)['current_period_collected_amount'];
$currentPeriodInvoicePaidAmount = $this->currentPeriodInvoicePaymentAmount($schoolId, $session->id, $term->id);
$currentPeriodPaidAmount = min(
    (float) $billingProfile['current_invoice_amount'],
    $currentPeriodOnlineCollectedAmount + $currentPeriodInvoicePaidAmount
);
$currentPeriodBalanceAmount = max(0, (float) $billingProfile['current_invoice_amount'] - $currentPeriodPaidAmount);

$unpaid = StudentBillingEntitlement::query()
    ->join('users', 'users.id', '=', 'student_billing_entitlements.student_id')
    ->leftJoin('student_classes', 'student_classes.id', '=', 'users.level_id')
    ->where('student_billing_entitlements.school_id', $schoolId)
    ->where('student_billing_entitlements.session_id', $session->id)
    ->where('student_billing_entitlements.term_id', $term->id)
    ->whereIn('student_billing_entitlements.status', ['unpaid', 'grace'])
    ->orderBy('users.surname')
    ->limit(100)
    ->get([
        'student_billing_entitlements.id',
        'student_billing_entitlements.status',
        'student_billing_entitlements.grace_until',
        'users.firstname',
        'users.surname',
        'users.reg_no',
        'student_classes.name as class_name',
    ]);
    
        $invoice = SchoolProfitTermInvoice::where([
            'school_id' => $schoolId,
            'session_id' => $session->id,
            'term_id' => $term->id,
            'billing_mode' => 'offline',
        ])->first();

        if ($invoice) {
            $invoice = $this->syncInvoiceWithSubscriptionPayments($invoice);
        }

        return [
            'settings' => $settings,
            'period' => ['session_id' => $session->id, 'session' => $session->name, 'term_id' => $term->id, 'term' => $term->name],
            'package' => $billingProfile['package'],
            'price_per_student' => $billingProfile['price_per_student'],
            'active_student_count' => $billingProfile['active_student_count'],
            'billable_student_count' => $billingProfile['billable_student_count'],
            'student_limit' => $billingProfile['student_limit'],
            'current_invoice_amount' => $billingProfile['current_invoice_amount'],
            'revenue_model' => $billingProfile['revenue_model'],
            'online_collection' => $this->onlineCollectionSummary($schoolId, $session->id, $term->id),
            'current_period_online_collected_amount' => $currentPeriodOnlineCollectedAmount,
            'current_period_invoice_paid_amount' => min($currentPeriodInvoicePaidAmount, max(0, (float) $billingProfile['current_invoice_amount'] - $currentPeriodOnlineCollectedAmount)),
            'current_period_subscription_credit_amount' => $subscriptionCreditAmount,
            'current_period_paid_amount' => $currentPeriodPaidAmount,
            'current_period_balance_amount' => $currentPeriodBalanceAmount,
            'subscription_paid_amount' => min((float) $billingProfile['current_invoice_amount'], $subscriptionPaidAmount),
            'outstanding_amount' => $settings->payment_mode === 'online' ? $currentPeriodBalanceAmount : $outstandingAmount,
            'summary' => $summary,
            'unpaid_students' => $unpaid,
            'invoice' => $invoice,
            'transition_invoice' => $this->transitionInvoiceForSchool($schoolId),
            'switch_check' => $this->canSwitchPaymentMode($schoolId),
        ];
    }

    protected function transitionInvoiceForSchool(int $schoolId): ?SchoolProfitTermInvoice
    {
        return SchoolProfitTermInvoice::where('school_id', $schoolId)
            ->where('invoice_type', 'online_to_offline_transition')
            ->whereIn('status', ['issued', 'partial', 'overdue'])
            ->where('balance', '>', 0)
            ->latest()
            ->first();
    }

    protected function ensureEntitlement(int $schoolId, int $studentId, int $sessionId, int $termId): StudentBillingEntitlement
    {
        $settings = $this->settingsForSchool($schoolId);

        $entitlement = StudentBillingEntitlement::firstOrCreate(
            [
                'school_id' => $schoolId,
                'student_id' => $studentId,
                'session_id' => $sessionId,
                'term_id' => $termId,
            ],
            [
                'billing_mode' => $settings->payment_mode,
                'status' => $settings->grace_days > 0 ? 'grace' : 'unpaid',
                'source' => 'system',
                'grace_until' => $settings->grace_days > 0 ? now()->addDays((int) $settings->grace_days) : null,
            ]
        );

        if ($entitlement->billing_mode !== $settings->payment_mode && in_array($entitlement->status, ['unpaid', 'grace'], true)) {
            $entitlement->update(['billing_mode' => $settings->payment_mode]);
        }

        if ($settings->payment_mode === 'online') {
            $this->syncOnlinePaidStatus($entitlement->fresh());
        }

        return $entitlement->fresh();
    }

    protected function syncOnlinePaidStatus(StudentBillingEntitlement $entitlement): void
    {
        $studentFee = StudentFee::where([
            'school_id' => $entitlement->school_id,
            'student_id' => $entitlement->student_id,
            'session_id' => $entitlement->session_id,
            'term_id' => $entitlement->term_id,
        ])->whereHas('payments', fn ($q) => $q->where('status', 'success')->where('platform_fee', '>', 0))
            ->first();

        if ($studentFee) {
            $entitlement->update([
                'status' => 'paid',
                'source' => 'online_fee',
                'student_fee_id' => $studentFee->id,
                'covered_at' => now(),
                'grace_until' => null,
            ]);
        }
    }

    protected function onlineCollectionSummary(int $schoolId, ?int $sessionId, ?int $termId): array
    {
        $base = Payment::query()
            ->join('student_fees', 'student_fees.id', '=', 'payments.student_fee_id')
            ->where('payments.school_id', $schoolId)
            ->where('payments.status', 'success')
            ->where('payments.platform_fee', '>', 0);

        $current = (clone $base)
            ->when($sessionId, fn ($query) => $query->where('student_fees.session_id', $sessionId))
            ->when($termId, fn ($query) => $query->where('student_fees.term_id', $termId));

        $recent = (clone $base)
            ->orderByDesc('payments.created_at')
            ->limit(5)
            ->get([
                'payments.id',
                'payments.reference',
                'payments.platform_fee',
                'payments.created_at',
                'student_fees.student_id',
                'student_fees.session_id',
                'student_fees.term_id',
            ]);

        return [
            'current_period_collected_amount' => (float) (clone $current)->sum('payments.platform_fee'),
            'current_period_collected_count' => (int) (clone $current)->distinct('payments.student_fee_id')->count('payments.student_fee_id'),
            'total_collected_amount' => (float) (clone $base)->sum('payments.platform_fee'),
            'total_collected_count' => (int) (clone $base)->distinct('payments.student_fee_id')->count('payments.student_fee_id'),
            'recent' => $recent,
        ];
    }

    protected function currentPeriodInvoicePaymentAmount(int $schoolId, int $sessionId, int $termId): float
    {
        $invoices = SchoolProfitTermInvoice::query()
            ->where('school_id', $schoolId)
            ->where('session_id', $sessionId)
            ->where('term_id', $termId)
            ->get(['id', 'amount_due']);

        if ($invoices->isEmpty()) {
            return 0.0;
        }

        return (float) $invoices->sum(function (SchoolProfitTermInvoice $invoice) {
            $paid = SchoolProfitInvoicePayment::query()
                ->where('invoice_id', $invoice->id)
                ->where('status', 'successful')
                ->sum('amount');

            return min((float) $invoice->amount_due, (float) $paid);
        });
    }

    protected function allocateInvoicePayment(SchoolProfitTermInvoice $invoice): void
    {
        $pricePerStudent = $this->pricePerStudentForSchool($invoice->school_id);
        $coveredCount = (int) floor(((float) $invoice->amount_paid) / max(1, (float) $pricePerStudent));

        if (($invoice->invoice_type ?? 'term_invoice') === 'online_to_offline_transition') {
            $ids = collect($invoice->meta['uncovered_entitlement_ids'] ?? [])
                ->filter()
                ->values();

            if ($ids->isEmpty()) {
                return;
            }

            $entitlements = StudentBillingEntitlement::where('school_id', $invoice->school_id)
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->get();

            foreach ($entitlements as $index => $entitlement) {
                if ($index < $coveredCount) {
                    $entitlement->update([
                        'status' => 'paid',
                        'source' => 'offline_invoice',
                        'invoice_id' => $invoice->id,
                        'covered_at' => now(),
                        'grace_until' => null,
                    ]);
                } elseif ($entitlement->status === 'paid') {
                    $entitlement->update([
                        'status' => 'unpaid',
                        'covered_at' => null,
                        'grace_until' => null,
                    ]);
                }
            }

            return;
        }

        if (($invoice->invoice_type ?? 'term_invoice') === 'session_invoice') {
            $entitlements = StudentBillingEntitlement::where('school_id', $invoice->school_id)
                ->where('session_id', $invoice->session_id)
                ->where('billing_mode', 'offline')
                ->orderBy('id')
                ->get();

            foreach ($entitlements as $index => $entitlement) {
                if ($index < $coveredCount) {
                    $entitlement->update([
                        'status' => 'paid',
                        'source' => 'offline_session_invoice',
                        'invoice_id' => $invoice->id,
                        'covered_at' => now(),
                        'grace_until' => null,
                    ]);
                } elseif ($entitlement->status === 'paid') {
                    $entitlement->update([
                        'status' => $invoice->due_date && now()->lte($invoice->due_date) ? 'grace' : 'unpaid',
                        'covered_at' => null,
                        'grace_until' => $invoice->due_date?->endOfDay(),
                    ]);
                }
            }

            return;
        }

        $entitlements = StudentBillingEntitlement::where([
            'school_id' => $invoice->school_id,
            'session_id' => $invoice->session_id,
            'term_id' => $invoice->term_id,
            'billing_mode' => 'offline',
        ])->orderBy('id')->get();

        foreach ($entitlements as $index => $entitlement) {
            if ($index < $coveredCount) {
                $entitlement->update([
                    'status' => 'paid',
                    'source' => 'offline_invoice',
                    'invoice_id' => $invoice->id,
                    'covered_at' => now(),
                    'grace_until' => null,
                ]);
            } elseif ($entitlement->status === 'paid') {
                $entitlement->update([
                    'status' => $invoice->due_date && now()->lte($invoice->due_date) ? 'grace' : 'unpaid',
                    'covered_at' => null,
                    'grace_until' => $invoice->due_date?->endOfDay(),
                ]);
            }
        }
    }

    protected function syncInvoiceWithSubscriptionPayments(SchoolProfitTermInvoice $invoice): SchoolProfitTermInvoice
    {
        if (($invoice->invoice_type ?? 'term_invoice') !== 'term_invoice') {
            return $invoice->fresh();
        }

        $coveredBySubscription = $this->subscriptionPaidAmountForSchool($invoice->school_id);
        $paid = min((float) $invoice->amount_due, max((float) $invoice->amount_paid, $coveredBySubscription));
        $balance = max(0, (float) $invoice->amount_due - $paid);

        if ((float) $invoice->amount_paid !== $paid || (float) $invoice->balance !== $balance) {
            $invoice->update([
                'amount_paid' => $paid,
                'balance' => $balance,
                'status' => $balance <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'issued'),
            ]);

            $this->allocateInvoicePayment($invoice->fresh());
        }

        return $invoice->fresh();
    }

    public function activeSubscriptionRevenueCoverage(int $schoolId): array
    {
        return $this->legacySubscriptionProtection($schoolId);
    }

    protected function legacySubscriptionProtection(int $schoolId): array
    {
        return ['active' => false];
    }

    protected function isRevenueCoveringSubscription(Subscription $subscription): bool
    {
        if ($subscription->ends_at && $subscription->ends_at->lte(now())) {
            return false;
        }

        $planName = strtolower((string) ($subscription->plan?->name ?? ''));

        return str_contains($planName, 'legacy plus')
            || str_contains($planName, 'gradequestplus')
            || str_contains($planName, 'gradequest plus');
    }

    protected function subscriptionRevenueCoveragePayload(Subscription $subscription, string $status): array
    {
        return [
            'active' => true,
            'status' => $status,
            'subscription_id' => $subscription->id,
            'plan_id' => $subscription->subscription_plan_id,
            'plan_name' => $subscription->plan?->name,
            'starts_at' => $subscription->starts_at?->toDateTimeString(),
            'ends_at' => $subscription->ends_at?->toDateTimeString(),
            'message' => 'Active package subscription covers platform access for this billing period.',
        ];
    }

    protected function deferOpenLegacyInvoices(int $schoolId, array $legacy): void
    {
        SchoolProfitTermInvoice::query()
            ->where('school_id', $schoolId)
            ->where('invoice_type', 'term_invoice')
            ->whereIn('status', ['issued', 'partial', 'overdue'])
            ->get()
            ->each(function (SchoolProfitTermInvoice $invoice) use ($legacy) {
                $meta = $invoice->meta ?: [];
                $meta['legacy_subscription_protection'] = $legacy;
                $meta['deferred_reason'] = 'Existing subscription is honored until expiry.';

                $invoice->update([
                    'status' => 'cancelled',
                    'balance' => 0,
                    'meta' => $meta,
                ]);
            });
    }

    protected function subscriptionPaidAmountForSchool(int $schoolId): float
    {
        $owner = $this->ownerForSchool($schoolId);
        if (! $owner) {
            return 0;
        }

        $subscription = $this->activeSubscriptionForSchool($schoolId);
        if (! $subscription) {
            return 0;
        }

        return (float) SubPayment::query()
            ->where('user_id', $owner->id)
            ->whereIn('status', ['successful', 'success', 'paid'])
            ->when($subscription->starts_at, fn ($query) => $query->where('created_at', '>=', $subscription->starts_at->copy()->subDay()))
            ->selectRaw('COALESCE(SUM(amount + COALESCE(upgrade_credit_amount, 0)), 0) as total_paid_value')
            ->value('total_paid_value');
    }

    protected function activeStudents(int $schoolId)
    {
        return User::where('school_id', $schoolId)
            ->whereRaw('LOWER(role) = ?', ['student'])
            ->where('status', 1)
            ->orderBy('surname')
            ->orderBy('firstname')
            ->get(['id', 'firstname', 'surname', 'reg_no', 'level_id']);
    }

    public function platformFeeAmount(): int
    {
        return (int) round($this->policy()->standard_cbt_tier_price_per_student ?? ($this->policy()->platform_fee_per_student ?? 500.00));
    }

    public function promoCoverageForSchool(int $schoolId): ?array
    {
        $globalPolicy = $this->policy();
        if (! ($globalPolicy->promo_enabled ?? false)) {
            return null;
        }

        if ($globalPolicy->promo_starts_at && now()->lt($globalPolicy->promo_starts_at)) {
            return null;
        }
        if ($globalPolicy->promo_ends_at && now()->gt($globalPolicy->promo_ends_at)) {
            return null;
        }

        if ($globalPolicy->promo_max_claims && ($globalPolicy->promo_claims_count ?? 0) >= $globalPolicy->promo_max_claims) {
            return null;
        }

        $school = SchoolSetting::find($schoolId);
        $rawPolicy = $school?->fee_access_policy;
        $policyArray = is_array($rawPolicy) ? $rawPolicy : (json_decode((string) $rawPolicy, true) ?: []);
        $tier = $policyArray['active_edition_tier'] ?? ($school?->active_edition_tier ?: 'standard_cbt');

        $targetTier = (string) ($globalPolicy->promo_target_tier ?? 'all');
        if ($targetTier !== 'all' && $targetTier !== $tier) {
            return null;
        }

        $minStudents = (int) ($globalPolicy->promo_min_students ?? 0);
        $studentCount = $this->activeStudents($schoolId)->count();
        if ($minStudents > 0 && $studentCount < $minStudents) {
            return null;
        }

        $discountPercent = (float) ($globalPolicy->promo_discount_percent ?? 100.00);

        return [
            'active' => true,
            'title' => $globalPolicy->promo_title ?: 'SchoolProfit Launch Offer',
            'description' => $globalPolicy->promo_description ?: 'Platform Fee Waiver for Qualifying Schools',
            'discount_percent' => $discountPercent,
            'bonus_days' => (int) ($globalPolicy->promo_bonus_days ?? 365),
            'target_tier' => $targetTier,
        ];
    }

    public function pricePerStudentForSchool(int $schoolId): float
    {
        $school = SchoolSetting::find($schoolId);
        $rawPolicy = $school?->fee_access_policy;
        $policyArray = is_array($rawPolicy) ? $rawPolicy : (json_decode((string) $rawPolicy, true) ?: []);

        $tier = $policyArray['active_edition_tier'] ?? ($school?->active_edition_tier ?: 'standard_cbt');

        $globalPolicy = $this->policy();
        $basicPrice = (float) ($globalPolicy->basic_tier_price_per_student ?? 300.00);
        $cbtPrice = (float) ($globalPolicy->standard_cbt_tier_price_per_student ?? ($globalPolicy->platform_fee_per_student ?? 500.00));
        $annualMultiplier = (float) ($globalPolicy->annual_full_session_multiplier ?? 3.00);
        $annualDiscount = (float) ($globalPolicy->annual_session_discount_percent ?? 0.00);

        $basePrice = match ($tier) {
            'basic_result' => $basicPrice,
            'annual_full_session' => round($cbtPrice * $annualMultiplier * (1 - ($annualDiscount / 100)), 2),
            default => $cbtPrice,
        };

        $promo = $this->promoCoverageForSchool($schoolId);
        if ($promo && ($promo['discount_percent'] ?? 0) > 0) {
            $discount = min(100, (float) $promo['discount_percent']);
            return round($basePrice * (1 - ($discount / 100)), 2);
        }

        return $basePrice;
    }

    public function clearStudentFromWallet(int $schoolId, int $studentId, int $sessionId, int $termId, ?int $actorId = null): array
    {
        $fee = (float) $this->pricePerStudentForSchool($schoolId);
        $student = User::where('school_id', $schoolId)->findOrFail($studentId);
        $session = AcademicSession::where('school_id', $schoolId)->findOrFail($sessionId);
        $term = Term::where('school_id', $schoolId)->findOrFail($termId);

        $referenceId = 'clear_std_' . $studentId . '_s' . $sessionId . '_t' . $termId . '_' . Str::random(6);
        $description = "Student Term Clearance (₦" . number_format($fee, 2) . "): {$student->firstname} {$student->surname} ({$student->reg_no}) - {$session->name} ({$term->name})";

        app(WalletService::class)->debitSchoolWalletOrFail(
            $schoolId,
            $fee,
            $actorId ?? (int) $student->id,
            $description,
            $referenceId
        );

        $entitlement = $this->ensureEntitlement($schoolId, $studentId, $sessionId, $termId);
        $entitlement->update([
            'status' => 'paid',
            'source' => 'wallet',
            'covered_at' => now(),
            'grace_until' => null,
            'meta' => array_merge($entitlement->meta ?: [], [
                'cleared_via' => 'wallet',
                'fee_amount' => $fee,
                'cleared_by' => $actorId,
                'cleared_at' => now()->toIso8601String(),
                'reference_id' => $referenceId,
            ]),
        ]);

        $wallet = DB::table('wallets')->where('school_id', $schoolId)->first();

        return [
            'success' => true,
            'message' => "Student {$student->firstname} {$student->surname} successfully cleared for {$session->name} {$term->name}.",
            'entitlement' => $entitlement->fresh(),
            'wallet_balance' => (float) ($wallet?->balance ?? 0),
            'fee_paid' => $fee,
        ];
    }

    public function clearClassFromWallet(int $schoolId, int $classId, int $sessionId, int $termId, ?int $actorId = null): array
    {
        $feePerStudent = (float) $this->pricePerStudentForSchool($schoolId);
        $session = AcademicSession::where('school_id', $schoolId)->findOrFail($sessionId);
        $term = Term::where('school_id', $schoolId)->findOrFail($termId);
        $class = StudentClass::where('school_id', $schoolId)->findOrFail($classId);

        $students = User::where('school_id', $schoolId)
            ->where('level_id', $classId)
            ->whereRaw('LOWER(role) = ?', ['student'])
            ->where('status', 1)
            ->get();

        $unpaidStudents = [];
        foreach ($students as $student) {
            $entitlement = $this->ensureEntitlement($schoolId, $student->id, $sessionId, $termId);
            if (!in_array($entitlement->status, ['paid', 'override'], true)) {
                $unpaidStudents[] = $student;
            }
        }

        $count = count($unpaidStudents);
        if ($count === 0) {
            return [
                'success' => true,
                'message' => "All students in {$class->name} are already cleared for {$session->name} {$term->name}.",
                'cleared_count' => 0,
                'total_fee' => 0,
                'wallet_balance' => (float) (DB::table('wallets')->where('school_id', $schoolId)->value('balance') ?? 0),
            ];
        }

        $totalFee = $count * $feePerStudent;
        $referenceId = 'clear_cls_' . $classId . '_s' . $sessionId . '_t' . $termId . '_' . Str::random(6);
        $description = "Class Bulk Clearance ({$count} students @ ₦" . number_format($feePerStudent, 2) . "): {$class->name} - {$session->name} ({$term->name})";

        app(WalletService::class)->debitSchoolWalletOrFail(
            $schoolId,
            $totalFee,
            $actorId ?? 0,
            $description,
            $referenceId
        );

        foreach ($unpaidStudents as $student) {
            $ent = $this->ensureEntitlement($schoolId, $student->id, $sessionId, $termId);
            $ent->update([
                'status' => 'paid',
                'source' => 'wallet',
                'covered_at' => now(),
                'grace_until' => null,
                'meta' => array_merge($ent->meta ?: [], [
                    'cleared_via' => 'wallet_class_bulk',
                    'fee_amount' => $feePerStudent,
                    'class_id' => $classId,
                    'cleared_by' => $actorId,
                    'cleared_at' => now()->toIso8601String(),
                    'reference_id' => $referenceId,
                ]),
            ]);
        }

        $wallet = DB::table('wallets')->where('school_id', $schoolId)->first();

        return [
            'success' => true,
            'message' => "Successfully cleared {$count} students in {$class->name} for {$session->name} {$term->name}.",
            'cleared_count' => $count,
            'total_fee' => $totalFee,
            'fee_per_student' => $feePerStudent,
            'wallet_balance' => (float) ($wallet?->balance ?? 0),
        ];
    }

    public function clearSchoolTermFromWallet(int $schoolId, int $sessionId, int $termId, ?int $actorId = null): array
    {
        $feePerStudent = (float) $this->pricePerStudentForSchool($schoolId);
        $session = AcademicSession::where('school_id', $schoolId)->findOrFail($sessionId);
        $term = Term::where('school_id', $schoolId)->findOrFail($termId);
        $school = SchoolSetting::findOrFail($schoolId);

        $students = User::where('school_id', $schoolId)
            ->whereRaw('LOWER(role) = ?', ['student'])
            ->where('status', 1)
            ->get();

        $unpaidStudents = [];
        foreach ($students as $student) {
            $entitlement = $this->ensureEntitlement($schoolId, $student->id, $sessionId, $termId);
            if (!in_array($entitlement->status, ['paid', 'override'], true)) {
                $unpaidStudents[] = $student;
            }
        }

        $count = count($unpaidStudents);
        if ($count === 0) {
            return [
                'success' => true,
                'message' => "All active students in {$school->school_name} are already cleared for {$session->name} {$term->name}.",
                'cleared_count' => 0,
                'total_fee' => 0,
                'wallet_balance' => (float) (DB::table('wallets')->where('school_id', $schoolId)->value('balance') ?? 0),
            ];
        }

        $totalFee = $count * $feePerStudent;
        $referenceId = 'clear_sch_t_' . $sessionId . '_t' . $termId . '_' . Str::random(6);
        $description = "Whole-School Term Clearance ({$count} students @ ₦" . number_format($feePerStudent, 2) . "): {$session->name} ({$term->name})";

        app(WalletService::class)->debitSchoolWalletOrFail(
            $schoolId,
            $totalFee,
            $actorId ?? 0,
            $description,
            $referenceId
        );

        foreach ($unpaidStudents as $student) {
            $ent = $this->ensureEntitlement($schoolId, $student->id, $sessionId, $termId);
            $ent->update([
                'status' => 'paid',
                'source' => 'wallet',
                'covered_at' => now(),
                'grace_until' => null,
                'meta' => array_merge($ent->meta ?: [], [
                    'cleared_via' => 'wallet_school_term_bulk',
                    'fee_amount' => $feePerStudent,
                    'cleared_by' => $actorId,
                    'cleared_at' => now()->toIso8601String(),
                    'reference_id' => $referenceId,
                ]),
            ]);
        }

        $wallet = DB::table('wallets')->where('school_id', $schoolId)->first();

        return [
            'success' => true,
            'message' => "Successfully cleared {$count} students for {$session->name} {$term->name}.",
            'cleared_count' => $count,
            'total_fee' => $totalFee,
            'fee_per_student' => $feePerStudent,
            'wallet_balance' => (float) ($wallet?->balance ?? 0),
        ];
    }

    public function clearSchoolSessionFromWallet(int $schoolId, int $sessionId, ?int $actorId = null): array
    {
        $feePerStudent = (float) $this->pricePerStudentForSchool($schoolId);
        $session = AcademicSession::where('school_id', $schoolId)->findOrFail($sessionId);
        $school = SchoolSetting::findOrFail($schoolId);

        $terms = Term::where('school_id', $schoolId)->whereNull('archived_at')->orderBy('id')->get();
        if ($terms->isEmpty()) {
            throw new \RuntimeException('No academic terms found for this session.');
        }

        $students = User::where('school_id', $schoolId)
            ->whereRaw('LOWER(role) = ?', ['student'])
            ->where('status', 1)
            ->get();

        $unpaidSlots = [];
        foreach ($terms as $term) {
            foreach ($students as $student) {
                $ent = $this->ensureEntitlement($schoolId, $student->id, $sessionId, $term->id);
                if (!in_array($ent->status, ['paid', 'override'], true)) {
                    $unpaidSlots[] = ['student' => $student, 'term' => $term, 'entitlement' => $ent];
                }
            }
        }

        $slotCount = count($unpaidSlots);
        if ($slotCount === 0) {
            return [
                'success' => true,
                'message' => "All active students in {$school->school_name} are already cleared for all terms in {$session->name}.",
                'cleared_slots' => 0,
                'total_fee' => 0,
                'terms_count' => $terms->count(),
                'wallet_balance' => (float) (DB::table('wallets')->where('school_id', $schoolId)->value('balance') ?? 0),
            ];
        }

        $totalFee = $slotCount * $feePerStudent;
        $referenceId = 'clear_sch_s_' . $sessionId . '_' . Str::random(6);
        $termNames = $terms->pluck('name')->join(', ');
        $description = "Full Academic Session Clearance ({$slotCount} term-slots @ ₦" . number_format($feePerStudent, 2) . "): {$session->name} ({$termNames})";

        app(WalletService::class)->debitSchoolWalletOrFail(
            $schoolId,
            $totalFee,
            $actorId ?? 0,
            $description,
            $referenceId
        );

        foreach ($unpaidSlots as $slot) {
            $ent = $slot['entitlement'];
            $ent->update([
                'status' => 'paid',
                'source' => 'wallet',
                'covered_at' => now(),
                'grace_until' => null,
                'meta' => array_merge($ent->meta ?: [], [
                    'cleared_via' => 'wallet_school_session_bulk',
                    'fee_amount' => $feePerStudent,
                    'cleared_by' => $actorId,
                    'cleared_at' => now()->toIso8601String(),
                    'reference_id' => $referenceId,
                ]),
            ]);
        }

        $wallet = DB::table('wallets')->where('school_id', $schoolId)->first();

        return [
            'success' => true,
            'message' => "Successfully cleared {$slotCount} student term-slots across all {$terms->count()} terms in {$session->name}.",
            'cleared_slots' => $slotCount,
            'total_fee' => $totalFee,
            'terms_count' => $terms->count(),
            'fee_per_student' => $feePerStudent,
            'wallet_balance' => (float) ($wallet?->balance ?? 0),
        ];
    }

    public function clearanceSummary(int $schoolId, ?int $sessionId = null, ?int $termId = null): array
    {
        [$currentSession, $currentTerm] = $this->currentPeriod($schoolId);
        $sessionId = $sessionId ?: $currentSession?->id;
        $termId = $termId ?: $currentTerm?->id;

        $totalStudents = $this->activeStudents($schoolId)->count();
        $feePerStudent = (float) $this->pricePerStudentForSchool($schoolId);
        $wallet = DB::table('wallets')->where('school_id', $schoolId)->first();
        $walletBalance = (float) ($wallet?->balance ?? 0);

        $clearedCount = 0;
        $pendingCount = $totalStudents;

        if ($sessionId && $termId) {
            $clearedCount = StudentBillingEntitlement::where('school_id', $schoolId)
                ->where('session_id', $sessionId)
                ->where('term_id', $termId)
                ->whereIn('status', ['paid', 'override'])
                ->count();

            $pendingCount = max(0, $totalStudents - $clearedCount);
        }

        $termsCount = Term::where('school_id', $schoolId)->whereNull('archived_at')->count();

        return [
            'total_students' => $totalStudents,
            'cleared_count' => $clearedCount,
            'pending_count' => $pendingCount,
            'terms_count' => $termsCount,
            'fee_per_student' => $feePerStudent,
            'term_clearance_fee' => $pendingCount * $feePerStudent,
            'session_clearance_fee' => $totalStudents * max(1, $termsCount) * $feePerStudent,
            'wallet_balance' => $walletBalance,
            'session_id' => $sessionId,
            'term_id' => $termId,
        ];
    }

    public function billingProfile(int $schoolId): array
    {
        $settings = $this->settingsForSchool($schoolId);
        $activeStudentCount = $this->activeStudents($schoolId)->count();
        $pricePerStudent = (float) $this->pricePerStudentForSchool($schoolId);
        $revenueModel = $settings->payment_mode === 'online'
            ? 'online_transaction_fee'
            : 'offline_term_invoice';

        return [
            'package' => [
                'id' => null,
                'name' => 'SchoolProfit Free Core',
                'billing_interval' => 'term',
                'duration_in_days' => 0,
                'features' => $this->coreFeatures(),
                'access_model' => 'pay_as_you_go',
            ],
            'subscription' => null,
            'price_per_student' => $pricePerStudent,
            'active_student_count' => $activeStudentCount,
            'billable_student_count' => $activeStudentCount,
            'student_limit' => null,
            'current_invoice_amount' => $activeStudentCount * $pricePerStudent,
            'next_billing_estimate_amount' => $activeStudentCount * $pricePerStudent,
            'revenue_model' => $revenueModel,
            'payment_mode' => $settings->payment_mode,
            'subscription_coverage' => null,
        ];
    }

    protected function coreFeatures(): array
    {
        return [
            ['feature_key' => 'student_management', 'feature_name' => 'Student Management', 'is_enabled' => true],
            ['feature_key' => 'teacher_management', 'feature_name' => 'Teacher Management', 'is_enabled' => true],
            ['feature_key' => 'result_management', 'feature_name' => 'Result Management', 'is_enabled' => true],
            ['feature_key' => 'fee_management', 'feature_name' => 'Fee Management', 'is_enabled' => true],
            ['feature_key' => 'online_payment', 'feature_name' => 'Online Fee Payment', 'is_enabled' => true],
            ['feature_key' => 'attendance_management', 'feature_name' => 'Attendance', 'is_enabled' => true],
            ['feature_key' => 'staff_attendance', 'feature_name' => 'Staff Attendance', 'is_enabled' => true],
            ['feature_key' => 'parent_management', 'feature_name' => 'Parent Portal', 'is_enabled' => true],
            ['feature_key' => 'bursar_management', 'feature_name' => 'Bursar Portal', 'is_enabled' => true],
            ['feature_key' => 'settings_management', 'feature_name' => 'School Settings', 'is_enabled' => true],
        ];
    }

    protected function activeSubscriptionForSchool(int $schoolId): ?Subscription
    {
        $owner = $this->ownerForSchool($schoolId);

        if (! $owner) {
            return null;
        }

        return Subscription::with('plan')
            ->where('user_id', $owner->id)
            ->where('status', 'active')
            ->latest('created_at')
            ->first();
    }

    protected function ownerForSchool(int $schoolId): ?User
    {
        $schoolOwnerId = SchoolSetting::where('id', $schoolId)->value('user_id');

        if ($schoolOwnerId) {
            $owner = User::query()
                ->where('id', $schoolOwnerId)
                ->where('school_id', $schoolId)
                ->first();

            if ($owner) {
                return $owner;
            }
        }

        return User::query()
            ->where('school_id', $schoolId)
            ->whereRaw('LOWER(role) = ?', ['admin'])
            ->orderBy('id')
            ->first();
    }

    protected function invoiceNumber(): string
    {
        return 'GQ-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
    }

    protected function audit(int $schoolId, ?int $actorId, string $action, $auditable = null, ?array $before = null, ?array $after = null, ?string $reason = null): void
    {
        SchoolBillingAuditLog::create([
            'school_id' => $schoolId,
            'actor_id' => $actorId,
            'action' => $action,
            'auditable_type' => $auditable ? get_class($auditable) : null,
            'auditable_id' => $auditable->id ?? null,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
        ]);
    }
}
