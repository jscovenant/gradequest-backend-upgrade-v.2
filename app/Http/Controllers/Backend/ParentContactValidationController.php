<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\SchoolSetting;
use App\Models\User;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class ParentContactValidationController extends Controller
{
    public function validatePhone(Request $request, int $parentId, WhatsAppService $whatsApp)
    {
        $parent = $this->parentForSchool($parentId);

        $validated = $request->validate([
            'phone' => 'nullable|string|max:30',
        ]);

        try {
            $normalized = $whatsApp->formatPhone($validated['phone'] ?? $parent->phone ?? '');
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => 'Phone number is invalid. Use a reachable number such as 08030000000 or +2348030000000.',
            ], 422);
        }

        $parent->phone = $validated['phone'] ?? $parent->phone;
        $parent->phone_normalized = $normalized;
        $parent->phone_validated_at = now();
        $parent->save();

        return response()->json([
            'message' => 'Phone number validated successfully.',
            'parent' => $this->parentPayload($parent->fresh()),
        ]);
    }

    public function sendWhatsappCode(Request $request, int $parentId, WhatsAppService $whatsApp)
    {
        $parent = $this->parentForSchool($parentId);
        $schoolId = (int) Auth::user()->school_id;

        $validated = $request->validate([
            'phone' => 'nullable|string|max:30',
        ]);

        $school = SchoolSetting::find($schoolId);
        if (! $school?->whatsapp_enabled) {
            return response()->json([
                'message' => 'WhatsApp messaging is not enabled for this school.',
            ], 422);
        }

        if (! $whatsApp->isConfigured()) {
            return response()->json([
                'message' => 'WhatsApp service is not configured yet. Please contact the system administrator.',
            ], 422);
        }

        try {
            $normalized = $whatsApp->formatPhone($validated['phone'] ?? $parent->whatsapp_number ?? $parent->whatsapp_no ?? $parent->phone ?? '');
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => 'WhatsApp number is invalid. Use a reachable number such as 08030000000 or +2348030000000.',
            ], 422);
        }

        $code = (string) random_int(100000, 999999);
        $name = trim(($parent->firstname ?? '') . ' ' . ($parent->surname ?? '')) ?: 'Parent';
        $schoolName = $school->name ?? 'your school';

        $sent = $whatsApp->sendToParent(
            $schoolId,
            $normalized,
            "*GradeQuest WhatsApp Verification*\n\nHello {$name}, your verification code for {$schoolName} is *{$code}*. It expires in 10 minutes."
        );

        if (! $sent) {
            return response()->json([
                'message' => 'Unable to send WhatsApp code. Confirm the number has joined the Twilio sandbox during testing.',
            ], 422);
        }

        $numberChanged = $normalized !== ($parent->whatsapp_no ?: $parent->whatsapp_number);
        $parent->whatsapp_no = $normalized;
        $parent->whatsapp_number = $normalized;
        $parent->whatsapp_verification_code = $code;
        $parent->whatsapp_verification_expires_at = now()->addMinutes(10);

        if ($numberChanged) {
            $parent->whatsapp_verified_at = null;
        }

        $parent->save();

        return response()->json([
            'message' => 'WhatsApp verification code sent.',
            'parent' => $this->parentPayload($parent->fresh()),
        ]);
    }

    public function verifyWhatsappCode(Request $request, int $parentId)
    {
        $parent = $this->parentForSchool($parentId);

        $validated = $request->validate([
            'code' => 'required|string|min:4|max:10',
        ]);

        if (! $parent->whatsapp_verification_code || ! $parent->whatsapp_verification_expires_at) {
            return response()->json([
                'message' => 'No active WhatsApp verification code found. Send a new code first.',
            ], 422);
        }

        if (now()->greaterThan($parent->whatsapp_verification_expires_at)) {
            return response()->json([
                'message' => 'WhatsApp verification code has expired. Send a new code.',
            ], 422);
        }

        if (! hash_equals((string) $parent->whatsapp_verification_code, trim($validated['code']))) {
            return response()->json([
                'message' => 'Incorrect WhatsApp verification code.',
            ], 422);
        }

        $parent->whatsapp_verified_at = now();
        $parent->whatsapp_verification_code = null;
        $parent->whatsapp_verification_expires_at = null;
        $parent->save();

        return response()->json([
            'message' => 'WhatsApp number verified successfully.',
            'parent' => $this->parentPayload($parent->fresh()),
        ]);
    }

    private function parentForSchool(int $parentId): User
    {
        $auth = Auth::user();

        return User::where('id', $parentId)
            ->where('school_id', $auth->school_id)
            ->withRole('parent')
            ->firstOrFail();
    }

    private function parentPayload(User $parent): array
    {
        return [
            'id' => $parent->id,
            'firstname' => $parent->firstname,
            'surname' => $parent->surname,
            'email' => $parent->email,
            'phone' => $parent->phone,
            'phone_normalized' => $parent->phone_normalized,
            'phone_validated_at' => optional($parent->phone_validated_at)->toISOString(),
            'whatsapp_no' => $parent->whatsapp_no,
            'whatsapp_number' => $parent->whatsapp_number,
            'whatsapp_verified_at' => optional($parent->whatsapp_verified_at)->toISOString(),
            'whatsapp_verification_expires_at' => optional($parent->whatsapp_verification_expires_at)->toISOString(),
        ];
    }
}
