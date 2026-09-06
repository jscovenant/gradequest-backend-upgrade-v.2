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
        $s = $this->getOrCreateSetting($schoolId);

        return response()->json([
            'fee_reminders_enabled' => (bool)($s?->fee_reminders_enabled ?? false),
            'interval_days' => (int)($s?->fee_reminder_interval_days ?? 7),
            'max_count' => (int)($s?->fee_reminder_max_count ?? 3),
            'send_email' => (bool)($s?->fee_reminder_send_email ?? true),
            'send_whatsapp' => (bool)($s?->fee_reminder_send_whatsapp ?? false),
            'quiet_hours_start' => $s?->fee_reminder_quiet_hours_start,
            'quiet_hours_end' => $s?->fee_reminder_quiet_hours_end,
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

        if ($data['fee_reminders_enabled'] && ! $data['send_email'] && ! $data['send_whatsapp']) {
            return response()->json([
                'message' => 'Select at least one reminder delivery channel.',
                'errors' => ['channels' => ['Enable email or WhatsApp reminders.']],
            ], 422);
        }

        if (($data['quiet_hours_start'] === null) !== ($data['quiet_hours_end'] === null)) {
            return response()->json([
                'message' => 'Set both quiet-hours times or leave both empty.',
                'errors' => ['quiet_hours' => ['Both start and end times are required.']],
            ], 422);
        }

        $s = $this->getOrCreateSetting($schoolId);

        if (!$s) {
            return response()->json(['message' => 'School settings not found.'], 404);
        }

        if ($data['fee_reminders_enabled'] && $data['send_whatsapp'] && ! (bool) $s->whatsapp_enabled) {
            return response()->json([
                'message' => 'Enable WhatsApp from WhatsApp Settings before using it for fee reminders.',
                'errors' => ['send_whatsapp' => ['WhatsApp is disabled for this school.']],
            ], 422);
        }

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

    private function getOrCreateSetting(int $schoolId): ?SchoolSetting
    {
        if (!$schoolId) {
            return null;
        }

        $s = SchoolSetting::find($schoolId);
        if (!$s) {
            $user = Auth::user();
            $s = SchoolSetting::create([
                'id' => $schoolId,
                'user_id' => $user->id,
                'school_name' => $user->name ?? ('School #' . $schoolId),
                'address' => 'N/A',
                'phone' => $user->phone ?? 'N/A',
                'email' => $user->email ?? null,
                'primary_color' => '#0d6efd',
                'secondary_color' => '#ffc107',
                'background_color' => '#ffffff',
            ]);
        }

        return $s;
    }
}
