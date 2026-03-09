<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\SchoolSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FeeReminderSettingsController extends Controller
{
    public function show()
    {
        $schoolId = (int)(Auth::user()->school_id ?? 0);

        $s = SchoolSetting::firstOrCreate(['id' => $schoolId], []);
        return response()->json([
            'fee_reminders_enabled' => (bool)$s->fee_reminders_enabled,
            'interval_days' => (int)$s->fee_reminder_interval_days,
            'max_count' => (int)$s->fee_reminder_max_count,
            'send_email' => (bool)$s->fee_reminder_send_email,
            'send_whatsapp' => (bool)$s->fee_reminder_send_whatsapp,
            'quiet_hours_start' => $s->fee_reminder_quiet_hours_start,
            'quiet_hours_end' => $s->fee_reminder_quiet_hours_end,
        ]);
    }

    public function update(Request $request)
    {
        $schoolId = (int)(Auth::user()->school_id ?? 0);

        $data = $request->validate([
            'fee_reminders_enabled' => ['required', 'boolean'],
            'interval_days' => ['required', 'integer', 'min:1', 'max:60'],
            'max_count' => ['required', 'integer', 'min:0', 'max:50'],
            'send_email' => ['required', 'boolean'],
            'send_whatsapp' => ['required', 'boolean'],
            'quiet_hours_start' => ['nullable', 'date_format:H:i'],
            'quiet_hours_end' => ['nullable', 'date_format:H:i'],
        ]);

        $s = SchoolSetting::firstOrCreate(['id' => $schoolId], []);

        $s->fee_reminders_enabled = (bool)$data['fee_reminders_enabled'];
        $s->fee_reminder_interval_days = (int)$data['interval_days'];
        $s->fee_reminder_max_count = (int)$data['max_count'];
        $s->fee_reminder_send_email = (bool)$data['send_email'];
        $s->fee_reminder_send_whatsapp = (bool)$data['send_whatsapp'];
        $s->fee_reminder_quiet_hours_start = $data['quiet_hours_start'];
        $s->fee_reminder_quiet_hours_end = $data['quiet_hours_end'];
        $s->save();

        return response()->json(['message' => 'Fee reminder settings updated.']);
    }
}
