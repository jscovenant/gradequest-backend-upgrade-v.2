<?php

namespace App\Services;

use App\Models\SchoolBankAccount;
use App\Models\SchoolSetting;
use App\Models\StudentFee;
use App\Models\User;
use App\Notifications\FeeInvoiceReminderNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutoFeeInvoiceService
{
    public function __construct(
        private readonly WhatsAppService $whatsapp,
        private readonly FeeReminderSchedulePolicy $schedulePolicy
    ) {}

    public function run(?int $onlySchoolId = null): array
    {
        $notified = 0;
        $skipped = 0;

        $parentGroups = DB::table('parent_students')
            ->select('school_id', 'parent_id', 'student_id')
            ->when($onlySchoolId, fn ($query) => $query->where('school_id', $onlySchoolId))
            ->whereNotNull('school_id')
            ->whereNotNull('parent_id')
            ->whereNotNull('student_id')
            ->get()
            ->groupBy(fn ($row) => $row->school_id . ':' . $row->parent_id);

        foreach ($parentGroups as $group) {
            $schoolId = (int) $group->first()->school_id;
            $parentId = (int) $group->first()->parent_id;
            $studentIds = $group->pluck('student_id')->map(fn ($id) => (int) $id)->unique()->values();
            $settings = SchoolSetting::find($schoolId);
            $parent = User::find($parentId);

            if (! $settings || ! $parent || $studentIds->isEmpty() || ! (bool) $settings->fee_reminders_enabled) {
                $skipped++;
                continue;
            }

            if ($this->schedulePolicy->isWithinQuietHours(
                $settings->fee_reminder_quiet_hours_start,
                $settings->fee_reminder_quiet_hours_end,
                now()
            )) {
                $skipped++;
                continue;
            }

            $fees = StudentFee::query()
                ->with(['student', 'feeType', 'term', 'session'])
                ->where('school_id', $schoolId)
                ->whereIn('student_id', $studentIds)
                ->where('balance', '>', 0)
                ->orderBy('student_id')
                ->orderBy('id')
                ->get();

            if ($fees->isEmpty()) {
                $skipped++;
                continue;
            }

            $scopeKey = $this->scopeKey($fees);
            $scopeLogs = $this->logsForScope($schoolId, $parentId, $scopeKey);
            $maxCount = max(0, (int) ($settings->fee_reminder_max_count ?? 6));

            $lastSentAt = $scopeLogs->first()?->sent_at
                ? \Illuminate\Support\Carbon::parse($scopeLogs->first()->sent_at)
                : null;

            if ($this->schedulePolicy->maxReached($scopeLogs->count(), $maxCount)
                || ! $this->schedulePolicy->intervalHasElapsed(
                    $lastSentAt,
                    (int) ($settings->fee_reminder_interval_days ?? 5),
                    now()
                )) {
                $skipped++;
                continue;
            }

            $sendEmail = (bool) ($settings->fee_reminder_send_email ?? true) && filled($parent->email);
            $sendWhatsApp = (bool) ($settings->fee_reminder_send_whatsapp ?? false)
                && (bool) ($settings->whatsapp_enabled ?? false);

            if (! $sendEmail && ! $sendWhatsApp) {
                $skipped++;
                continue;
            }

            $payload = $this->payload($settings, $parent, $fees, $scopeKey);
            $notificationId = $this->createReminderLog($schoolId, $parentId, $payload);
            $payload['notification_id'] = $notificationId;
            $payload['payment_url'] = rtrim((string) config('app.frontend_url'), '/')
                . '/payment-instructions/' . $notificationId;

            $emailQueued = false;
            $whatsAppSent = false;

            try {
                if ($sendEmail) {
                    $parent->notify(new FeeInvoiceReminderNotification(
                        data: $payload,
                        forceEmail: true,
                        forceWhatsApp: false
                    ));
                    $emailQueued = true;
                }

                if ($sendWhatsApp) {
                    $phone = $this->whatsapp->parentPhone($parent);

                    if ($phone) {
                        $message = WhatsAppMessageBuilder::feeReminder(
                            $fees,
                            $parent,
                            [],
                            $this->bankAccounts($schoolId)
                        );

                        $whatsAppSent = $this->whatsapp->sendToParent($schoolId, $phone, $message);
                    }
                }

                $payload['delivery'] = [
                    'email_queued' => $emailQueued,
                    'whatsapp_sent' => $whatsAppSent,
                ];
                $this->finalizeReminderLog($notificationId, $payload, $emailQueued || $whatsAppSent);

                if ($emailQueued || $whatsAppSent) {
                    $notified++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $payload['delivery'] = [
                    'email_queued' => $emailQueued,
                    'whatsapp_sent' => $whatsAppSent,
                    'error' => $e->getMessage(),
                ];
                $this->finalizeReminderLog($notificationId, $payload, false);

                Log::error('Combined fee reminder delivery failed', [
                    'school_id' => $schoolId,
                    'parent_id' => $parentId,
                    'error' => $e->getMessage(),
                ]);
                $skipped++;
            }
        }

        return ['notified' => $notified, 'skipped' => $skipped];
    }

    public function sendManualParentReminder(int $schoolId, int $parentId, array $filters = []): array
    {
        $settings = SchoolSetting::find($schoolId);
        $parent = User::query()
            ->where('school_id', $schoolId)
            ->whereKey($parentId)
            ->first();

        if (! $settings || ! $parent) {
            return [
                'delivered' => false,
                'email_queued' => false,
                'whatsapp_sent' => false,
                'message' => 'Parent record was not found for this school.',
            ];
        }

        $studentIds = DB::table('parent_students')
            ->where('school_id', $schoolId)
            ->where('parent_id', $parentId)
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($studentIds->isEmpty()) {
            return [
                'delivered' => false,
                'email_queued' => false,
                'whatsapp_sent' => false,
                'message' => 'No student is linked to this parent.',
            ];
        }

        $fees = StudentFee::query()
            ->with(['student', 'feeType', 'term', 'session'])
            ->where('school_id', $schoolId)
            ->whereIn('student_id', $studentIds)
            ->where('balance', '>', 0)
            ->when(! empty($filters['session_id']), fn ($query) => $query->where('session_id', (int) $filters['session_id']))
            ->when(! empty($filters['term_id']), fn ($query) => $query->where('term_id', (int) $filters['term_id']))
            ->when(! empty($filters['section_id']), fn ($query) => $query->where('section_id', (int) $filters['section_id']))
            ->when(! empty($filters['class_id']), fn ($query) => $query->whereHas('student', fn ($students) => $students->where('level_id', (int) $filters['class_id'])))
            ->orderBy('student_id')
            ->orderBy('id')
            ->get();

        if ($fees->isEmpty()) {
            return [
                'delivered' => false,
                'email_queued' => false,
                'whatsapp_sent' => false,
                'message' => 'No outstanding fee record was found for this parent in the selected filter.',
            ];
        }

        $sendEmail = filled($parent->email);
        $sendWhatsApp = (bool) ($settings->whatsapp_enabled ?? false) && filled($this->whatsapp->parentPhone($parent));

        if (! $sendEmail && ! $sendWhatsApp) {
            return [
                'delivered' => false,
                'email_queued' => false,
                'whatsapp_sent' => false,
                'message' => 'This parent has no available email or WhatsApp contact.',
            ];
        }

        $scopeKey = 'manual:' . $this->scopeKey($fees) . ':' . now()->format('YmdHis');
        $payload = $this->payload($settings, $parent, $fees, $scopeKey);
        $payload['manual'] = true;
        $payload['triggered_by'] = auth()->id();
        $payload['filters'] = $filters;

        $notificationId = $this->createReminderLog($schoolId, $parentId, $payload);
        $payload['notification_id'] = $notificationId;
        $payload['payment_url'] = rtrim((string) config('app.frontend_url'), '/')
            . '/payment-instructions/' . $notificationId;

        $emailQueued = false;
        $whatsAppSent = false;

        try {
            if ($sendEmail) {
                $parent->notifyNow(new FeeInvoiceReminderNotification(
                    data: $payload,
                    forceEmail: true,
                    forceWhatsApp: false
                ));
                $emailQueued = true;
            }

            if ($sendWhatsApp) {
                $message = WhatsAppMessageBuilder::feeReminder(
                    $fees,
                    $parent,
                    $this->paymentLinks($schoolId, $fees),
                    $this->bankAccounts($schoolId)
                );

                $whatsAppSent = $this->whatsapp->sendToParent($schoolId, (string) $this->whatsapp->parentPhone($parent), $message);
            }

            $payload['delivery'] = [
                'email_queued' => $emailQueued,
                'whatsapp_sent' => $whatsAppSent,
                'manual' => true,
            ];
            $this->finalizeReminderLog($notificationId, $payload, $emailQueued || $whatsAppSent);

            return [
                'delivered' => $emailQueued || $whatsAppSent,
                'email_queued' => $emailQueued,
                'whatsapp_sent' => $whatsAppSent,
                'notification_id' => $emailQueued || $whatsAppSent ? $notificationId : null,
                'payment_url' => $emailQueued || $whatsAppSent ? $payload['payment_url'] : null,
                'message' => $emailQueued || $whatsAppSent
                    ? 'Reminder sent through the available channel(s).'
                    : 'Reminder could not be delivered through the available channel(s).',
            ];
        } catch (\Throwable $e) {
            $payload['delivery'] = [
                'email_queued' => $emailQueued,
                'whatsapp_sent' => $whatsAppSent,
                'manual' => true,
                'error' => $e->getMessage(),
            ];
            $this->finalizeReminderLog($notificationId, $payload, $emailQueued || $whatsAppSent);

            Log::error('Manual fee reminder delivery failed', [
                'school_id' => $schoolId,
                'parent_id' => $parentId,
                'error' => $e->getMessage(),
            ]);

            return [
                'delivered' => $emailQueued || $whatsAppSent,
                'email_queued' => $emailQueued,
                'whatsapp_sent' => $whatsAppSent,
                'notification_id' => $emailQueued || $whatsAppSent ? $notificationId : null,
                'payment_url' => $emailQueued || $whatsAppSent ? $payload['payment_url'] : null,
                'message' => $emailQueued || $whatsAppSent
                    ? 'Reminder was partially delivered. Check the delivery log for details.'
                    : 'Reminder could not be delivered. Check contact details and messaging settings.',
            ];
        }
    }
    private function payload(SchoolSetting $settings, User $parent, Collection $fees, string $scopeKey): array
    {
        $children = $fees->groupBy('student_id')->map(function (Collection $studentFees) {
            $student = $studentFees->first()?->student;

            return [
                'student_id' => (int) $studentFees->first()->student_id,
                'student_name' => trim(($student?->firstname ?? '') . ' ' . ($student?->surname ?? '')) ?: 'Student',
                'items' => $studentFees->map(fn ($fee) => [
                    'fee_title' => $fee->feeType?->name ?? 'School Fee',
                    'amount' => (float) ($fee->total_amount ?? 0),
                    'paid' => (float) ($fee->amount_paid ?? 0),
                    'balance' => (float) $fee->balance,
                    'term_id' => $fee->term_id,
                    'session_id' => $fee->session_id,
                ])->values()->all(),
            ];
        })->values()->all();

        return [
            'summary_type' => 'combined_parent_fees',
            'scope_key' => $scopeKey,
            'school_id' => (int) $settings->id,
            'parent_id' => (int) $parent->id,
            'parent_name' => trim(($parent->firstname ?? '') . ' ' . ($parent->surname ?? '')) ?: 'Parent/Guardian',
            'children' => $children,
            'total_amount' => (float) $fees->sum('total_amount'),
            'total_paid' => (float) $fees->sum('amount_paid'),
            'total_balance' => (float) $fees->sum('balance'),
            'due_date' => now()->addDays(7)->toDateString(),
        ];
    }

    private function scopeKey(Collection $fees): string
    {
        return hash('sha256', $fees->pluck('id')->map(fn ($id) => (int) $id)->sort()->implode(','));
    }

    private function logsForScope(int $schoolId, int $parentId, string $scopeKey): Collection
    {
        return DB::table('combined_fee_reminder_logs')
            ->where('school_id', $schoolId)
            ->where('parent_id', $parentId)
            ->whereNotNull('sent_at')
            ->orderByDesc('sent_at')
            ->get()
            ->filter(function ($log) use ($scopeKey) {
                $payload = json_decode((string) ($log->payload ?? ''), true);

                return is_array($payload) && ($payload['scope_key'] ?? null) === $scopeKey;
            })
            ->values();
    }

    private function bankAccounts(int $schoolId): Collection
    {
        return SchoolBankAccount::query()
            ->where('school_id', $schoolId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['bank_name', 'account_name', 'account_number', 'currency']);
    }

    private function paymentLinks(int $schoolId, Collection $fees): array
    {
        $admin = User::query()
            ->where('school_id', $schoolId)
            ->whereRaw('LOWER(role) = ?', ['admin'])
            ->orderBy('id')
            ->first(['reg_no']);

        if (! $admin?->reg_no) {
            return [];
        }

        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return $fees->groupBy('student_id')
            ->mapWithKeys(function (Collection $studentFees, $studentId) use ($admin, $baseUrl) {
                $student = $studentFees->first()?->student;
                if (! $student?->reg_no) {
                    return [];
                }

                $query = http_build_query([
                    'school_code' => $admin->reg_no,
                    'student_reg_no' => $student->reg_no,
                    'amount' => number_format((float) $studentFees->sum('balance'), 2, '.', ''),
                ]);

                return [(int) $studentId => "{$baseUrl}/pay-school-fee?{$query}"];
            })
            ->all();
    }
    private function createReminderLog(int $schoolId, int $parentId, array $payload): int
    {
        return (int) DB::table('combined_fee_reminder_logs')->insertGetId([
            'school_id' => $schoolId,
            'parent_id' => $parentId,
            'total_balance' => (float) $payload['total_balance'],
            'payload' => json_encode($payload),
            'sent_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function finalizeReminderLog(int $id, array $payload, bool $delivered): void
    {
        if (! $delivered) {
            DB::table('combined_fee_reminder_logs')->where('id', $id)->delete();
            return;
        }

        DB::table('combined_fee_reminder_logs')->where('id', $id)->update([
            'payload' => json_encode($payload),
            'sent_at' => now(),
            'updated_at' => now(),
        ]);
    }
}



