<?php

namespace App\Services;

use App\Models\SchoolSetting;
use App\Models\User;
use App\Notifications\FeeInvoiceReminderNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutoFeeInvoiceService
{
  

    public function run(int $cooldownHours = 24): array
    {
        return $this->runCombinedParentReminders($cooldownHours);
    }

    public function runCombinedParentReminders(int $cooldownHours = 24): array
    {
        $notified = 0;
        $skipped = 0;


        


        $parentGroups = DB::table('parent_students')
            ->select('school_id', 'parent_id', 'student_id')
            ->whereNotNull('school_id')
            ->whereNotNull('parent_id')
            ->whereNotNull('student_id')
            ->get()
            ->groupBy(function ($row) {
                return $row->school_id . ':' . $row->parent_id;
            });

        foreach ($parentGroups as $group) {
            $schoolId = (int) $group->first()->school_id;
            $parentId = (int) $group->first()->parent_id;
            $studentIds = $group->pluck('student_id')->unique()->map(fn ($id) => (int) $id)->values();

            if ($studentIds->isEmpty()) {
                $skipped++;
                continue;
            }

            $parent = User::find($parentId);
            if (!$parent) {
                Log::warning('Combined fee reminder skipped: parent not found', [
                    'school_id' => $schoolId,
                    'parent_id' => $parentId,
                ]);
                $skipped++;
                continue;
            }

            // IMPORTANT:
            // If your school_settings is linked differently, adjust this lookup.
            $settings = SchoolSetting::where('id', $schoolId)->first();

            if (!$settings) {
                Log::warning('Combined fee reminder skipped: school settings not found', [
                    'school_id' => $schoolId,
                    'parent_id' => $parentId,
                ]);
                $skipped++;
                continue;
            }

            if (!(bool) ($settings->fee_reminders_enabled ?? true)) {
                $skipped++;
                continue;
            }

            if (!$this->canSendCombinedReminder($schoolId, $parentId, $cooldownHours)) {
                $skipped++;
                continue;
            }

            $students = DB::table('users')
                ->whereIn('id', $studentIds)
                ->select('id', 'firstname', 'surname', 'role')
                ->get()
                ->mapWithKeys(function ($student) {
                    $name = trim(($student->firstname ?? '') . ' ' . ($student->surname ?? ''));
                    if ($name === '') {
                        $name = $student->role ?? 'Student';
                    }

                    return [(int) $student->id => $name];
                });

            $feeRows = DB::table('student_fees as sf')
                ->leftJoin('fee_types as ft', 'ft.id', '=', 'sf.fee_type_id')
                ->where('sf.school_id', $schoolId)
                ->whereIn('sf.student_id', $studentIds->all())
                ->where('sf.balance', '>', 0)
                ->select([
                    'sf.id',
                    'sf.student_id',
                    'sf.school_id',
                    'sf.fee_type_id',
                    'sf.term_id',
                    'sf.session_id',
                    'sf.total_amount',
                    'sf.amount_paid',
                    'sf.balance',
                    DB::raw('COALESCE(ft.name, "School Fee") as fee_title'),
                ])
                ->orderBy('sf.student_id')
                ->orderBy('sf.id')
                ->get();

            if ($feeRows->isEmpty()) {
                $skipped++;
                continue;
            }

            $childrenMap = [];
            $totalAmount = 0.0;
            $totalPaid = 0.0;
            $totalBalance = 0.0;

            foreach ($feeRows as $row) {
                $studentId = (int) $row->student_id;
                $studentName = $students[$studentId] ?? 'Student';

                if (!isset($childrenMap[$studentId])) {
                    $childrenMap[$studentId] = [
                        'student_id' => $studentId,
                        'student_name' => $studentName,
                        'items' => [],
                    ];
                }

                $amount = (float) ($row->total_amount ?? 0);
                $paid = (float) ($row->amount_paid ?? 0);
                $balance = (float) ($row->balance ?? 0);

                $childrenMap[$studentId]['items'][] = [
                    'fee_title' => $row->fee_title ?? 'School Fee',
                    'amount' => $amount,
                    'paid' => $paid,
                    'balance' => $balance,
                    'term_id' => $row->term_id,
                    'session_id' => $row->session_id,
                ];

                $totalAmount += $amount;
                $totalPaid += $paid;
                $totalBalance += $balance;
            }

            $children = array_values($childrenMap);

            $sendEmail = (bool) ($settings->fee_reminder_send_email ?? true);
            $sendWhatsApp =
                (bool) ($settings->fee_reminder_send_whatsapp ?? false)
                && (bool) ($settings->whatsapp_enabled ?? false)
                && (bool) ($settings->whatsapp_fee_reminders ?? false);

            $paymentUrl = url('/');

            if (!empty($settings->custom_domain)) {
                $paymentUrl = str_starts_with($settings->custom_domain, 'http://') || str_starts_with($settings->custom_domain, 'https://')
                    ? $settings->custom_domain
                    : 'https://' . $settings->custom_domain;
            } elseif (!empty($settings->school_subdomain)) {
                $paymentUrl = str_starts_with($settings->school_subdomain, 'http://') || str_starts_with($settings->school_subdomain, 'https://')
                    ? $settings->school_subdomain
                    : 'https://' . $settings->school_subdomain;
            }

            $payload = [
                'summary_type' => 'combined_parent_fees',
                'school_id' => $schoolId,
                'parent_id' => $parentId,
                'parent_name' => trim(($parent->firstname ?? '') . ' ' . ($parent->surname ?? '')) ?: 'Parent/Guardian',
                'children' => $children,
                'total_amount' => $totalAmount,
                'total_paid' => $totalPaid,
                'total_balance' => $totalBalance,
                'due_date' => now()->addDays(7)->toDateString(),
                'payment_url' => $paymentUrl,
                'whatsapp_charge' => (float) config('services.whatsapp.fee_reminder_cost', 10),
            ];

            try {
            
                $notificationId = $this->markCombinedReminderSent($schoolId, $parentId, $payload);

                    $payload['notification_id'] = $notificationId;
                    $payload['payment_url'] = rtrim(config('app.frontend_url'), '/') . '/payment-instructions/' . $notificationId;

                    $parent->notify(new FeeInvoiceReminderNotification(
                        data: $payload,
                        forceEmail: $sendEmail,
                        forceWhatsApp: $sendWhatsApp
                    ));

                $this->markCombinedReminderSent($schoolId, $parentId, $payload);

                $notified++;

                Log::info('Combined fee reminder sent', [
                    'school_id' => $schoolId,
                    'parent_id' => $parentId,
                    'student_count' => count($children),
                    'total_balance' => $totalBalance,
                    'email' => $sendEmail,
                    'whatsapp' => $sendWhatsApp,
                ]);
            } catch (\Throwable $e) {
                Log::error('Combined fee reminder send failed', [
                    'school_id' => $schoolId,
                    'parent_id' => $parentId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'notified' => $notified,
            'skipped' => $skipped,
        ];
    }

   private function canSendCombinedReminder(int $schoolId, int $parentId, int $cooldownHours): bool
{
    $last = DB::table('combined_fee_reminder_logs')
        ->where('school_id', $schoolId)
        ->where('parent_id', $parentId)
        ->latest('sent_at')
        ->first();

    if (!$last || empty($last->sent_at)) {
        return true;
    }

    return now()->diffInHours($last->sent_at) >= $cooldownHours;
}

private function markCombinedReminderSent(int $schoolId, int $parentId, array $payload): void
{
    DB::table('combined_fee_reminder_logs')->insert([
        'school_id' => $schoolId,
        'parent_id' => $parentId,
        'total_balance' => (float) ($payload['total_balance'] ?? 0),
        'payload' => json_encode($payload),
        'sent_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

   
}