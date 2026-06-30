<?php

namespace App\Http\Controllers\Backend;
 
use App\Http\Controllers\Controller;
use App\Models\SchoolSetting;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
 


// app/Http/Controllers/WhatsAppSettingsController.php

class WhatsAppSettingsController extends Controller
{
    // GET /settings/whatsapp
 public function show()
{
    $user   = Auth::user();
    $school = SchoolSetting::find($user->school_id);

    return response()->json([
        'data' => [
            'whatsapp_enabled'        => (bool) ($school->whatsapp_enabled        ?? false),
            'whatsapp_messages_sent'  => (int)  ($school->whatsapp_messages_sent  ?? 0),
            'whatsapp_monthly_limit'  => (int)  ($school->whatsapp_monthly_limit  ?? 200),
            'whatsapp_usage_reset_date' => $school->whatsapp_usage_reset_date,
        ],
    ]);
}

    // POST /settings/whatsapp/toggle
    public function toggle(Request $request)
    {
        $request->validate([
            'whatsapp_enabled' => 'required|boolean',
        ]);

        $school = SchoolSetting::find(Auth::user()->school_id);
        $school->update(['whatsapp_enabled' => $request->boolean('whatsapp_enabled')]);

        return response()->json([
            'message'          => 'WhatsApp setting updated.',
            'whatsapp_enabled' => (bool) $school->whatsapp_enabled,
        ]);
    }

    // POST /settings/whatsapp/test
    public function test(Request $request, WhatsAppService $whatsapp)
    {
        $request->validate(['phone' => 'required|string']);

        $school = SchoolSetting::find(Auth::user()->school_id);

        if (!$school->whatsapp_enabled) {
            return response()->json(['message' => 'WhatsApp is not enabled for this school.'], 422);
        }

        $sent = $whatsapp->sendToParent(
            $school->id,
            $request->phone,
            "✅ *GradeQuest Test*\n\nThis is a test message from *{$school->school_name}*.\n\nWhatsApp integration is working! 🎉"
        );

        return $sent
            ? response()->json(['message' => 'Test message sent successfully!'])
            : response()->json(['message' => 'Failed to send. Check server logs.'], 422);
    }

    // GET /settings/whatsapp/queue-stats
    public function queueStats(Request $request)
    {
        $schoolId = $request->user()->school_id;

        return response()->json([
            'pending' => DB::table('jobs')
                ->where('payload', 'like', "%\"schoolId\":{$schoolId}%")
                ->count(),
            'failed'  => DB::table('failed_jobs')
                ->where('payload', 'like', "%\"schoolId\":{$schoolId}%")
                ->count(),
        ]);
    }
}