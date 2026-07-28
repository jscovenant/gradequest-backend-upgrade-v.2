<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\SchoolSetting;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;

class SuperAdminTwilioController extends Controller
{
    public function status(Request $request, WhatsAppService $whatsapp)
    {
        if (! $this->isSuperAdmin($request)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json([
            'data' => [
                'twilio' => $whatsapp->configurationStatus(),
                'schools_enabled' => SchoolSetting::where('whatsapp_enabled', true)->count(),
                'messages_sent_this_month' => (int) SchoolSetting::sum('whatsapp_messages_sent'),
            ],
        ]);
    }

    public function test(Request $request, WhatsAppService $whatsapp)
    {
        if (! $this->isSuperAdmin($request)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
        ]);

        if (! $whatsapp->isConfigured()) {
            return response()->json([
                'message' => 'Twilio WhatsApp is not fully configured on the server.',
                'twilio' => $whatsapp->configurationStatus(),
            ], 422);
        }

        $sent = $whatsapp->sendSystemMessage(
            (string) $data['phone'],
            "*GradeQuest Platform Test*\n\nThis is a Super Admin Twilio WhatsApp test.\n\nThe platform sender is working."
        );

        return $sent
            ? response()->json(['message' => 'Twilio test message sent successfully.'])
            : response()->json(['message' => 'Failed to send Twilio test message. Check server logs and Twilio message logs.'], 422);
    }

    private function isSuperAdmin(Request $request): bool
    {
        $role = strtolower(str_replace(['-', '_', ' '], '', (string) $request->user()?->role));

        return $role === 'superadmin';
    }
}
