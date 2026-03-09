<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\FeeInvoice;
use App\Models\FeeInvoiceReminderLog;
use App\Models\SchoolSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FeeReminderAnalyticsController extends Controller
{
    public function summary(Request $request)
    {
        $user = Auth::user();
        $schoolId = (int)($user->school_id ?? 0);

        $settings = SchoolSetting::where('id', $schoolId)->first();
        $intervalDays = (int)($settings->fee_reminder_interval_days ?? 5);

        $days = (int)$request->query('days', $intervalDays); // default to the school interval
        $days = max(1, $days);

        $base = FeeInvoice::query()
            ->where('school_id', $schoolId)
            ->whereNotNull('first_reminded_at');

        $totalReminded = (clone $base)->count();

        // paid within N days of first_reminded_at
        $paidWithin = (clone $base)
            ->whereNotNull('paid_at')
            ->whereRaw('paid_at <= DATE_ADD(first_reminded_at, INTERVAL ? DAY)', [$days])
            ->count();

        $rate = $totalReminded > 0 ? round(($paidWithin / $totalReminded) * 100, 2) : 0.0;

        // overdue reminders (due for follow-up now)
        $dueNow = FeeInvoice::query()
            ->where('school_id', $schoolId)
            ->where('balance', '>', 0)
            ->whereNotIn('status', ['paid', 'cancelled'])
            ->whereNotNull('next_reminder_at')
            ->where('next_reminder_at', '<=', now())
            ->count();

        return response()->json([
            'school_id' => $schoolId,
            'days_window' => $days,
            'total_reminded' => $totalReminded,
            'paid_within_window' => $paidWithin,
            'response_rate_percent' => $rate,
            'due_for_reminder_now' => $dueNow,
        ]);
    }


     public function reminderLogs(Request $request)
    {
        $schoolId = (int)(Auth::user()->school_id ?? 0);

        $q = FeeInvoiceReminderLog::query()
            ->where('school_id', $schoolId)
            ->latest('id');

        if ($request->filled('invoice_id')) {
            $q->where('fee_invoice_id', (int)$request->query('invoice_id'));
        }

        if ($request->filled('status')) {
            $q->where('status', (string)$request->query('status'));
        }

        if ($request->filled('event')) {
            $q->where('event', (string)$request->query('event'));
        }

        $perPage = min(100, max(10, (int)$request->query('per_page', 25)));

        return response()->json($q->paginate($perPage));
    }
}
