<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\SchoolSetting;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class WhatsAppSettingsController extends Controller
{
    public function show(WhatsAppService $whatsapp)
    {
        $school = SchoolSetting::find(Auth::user()->school_id);
        $quota = $whatsapp->quotaSummary($school);

        return response()->json([
            'data' => [
                'whatsapp_enabled' => (bool) ($school->whatsapp_enabled ?? false),
                'whatsapp_messages_sent' => $quota['used'],
                'whatsapp_monthly_limit' => $quota['limit'],
                'whatsapp_remaining' => $quota['remaining'],
                'whatsapp_unlimited' => $quota['unlimited'],
                'whatsapp_has_access' => $quota['has_access'],
                'whatsapp_usage_reset_date' => $school?->whatsapp_usage_reset_date,
                'twilio' => $whatsapp->configurationStatus(),
            ],
        ]);
    }

    public function toggle(Request $request)
    {
        $request->validate([
            'whatsapp_enabled' => ['required', 'boolean'],
        ]);

        $school = SchoolSetting::find(Auth::user()->school_id);

        if (! $school) {
            return response()->json(['message' => 'School settings not found.'], 404);
        }

        $school->update([
            'whatsapp_enabled' => $request->boolean('whatsapp_enabled'),
        ]);

        return response()->json([
            'message' => 'WhatsApp setting updated.',
            'whatsapp_enabled' => (bool) $school->whatsapp_enabled,
        ]);
    }

    public function test(Request $request, WhatsAppService $whatsapp)
    {
        $request->validate([
            'phone' => ['required', 'string', 'max:30'],
        ]);

        $school = SchoolSetting::find(Auth::user()->school_id);

        if (! $school?->whatsapp_enabled) {
            return response()->json(['message' => 'WhatsApp is not enabled for this school.'], 422);
        }

        if (! $whatsapp->isConfigured()) {
            return response()->json([
                'message' => 'Twilio WhatsApp is not fully configured on the server.',
                'twilio' => $whatsapp->configurationStatus(),
            ], 422);
        }

        $sent = $whatsapp->sendToParent(
            (int) $school->id,
            (string) $request->phone,
            "*GradeQuest Test*\n\nThis is a test message from *{$school->school_name}*.\n\nWhatsApp integration is working."
        );

        return $sent
            ? response()->json(['message' => 'Test message sent successfully!'])
            : response()->json(['message' => 'Failed to send. Check server logs.'], 422);
    }

    public function queueStats(Request $request)
    {
        $schoolId = $request->user()->school_id;

        return response()->json([
            'pending' => DB::table('jobs')
                ->where('payload', 'like', "%\"schoolId\":{$schoolId}%")
                ->count(),
            'failed' => DB::table('failed_jobs')
                ->where('payload', 'like', "%\"schoolId\":{$schoolId}%")
                ->count(),
        ]);
    }
}
