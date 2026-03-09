<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SchoolSetting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;

class SettingController extends Controller
{
    public function getSettings()
    {
        $user = Auth::user();

        $settings = SchoolSetting::where('user_id', $user->id)->first();

        if (!$settings) {
            return response()->json(['message' => 'Settings not found.'], 404);
        }

        return response()->json([
            'app_settings' => [
                'autoGenerateAdmissionNo' => (bool) $settings->auto_admission,
                'reportPrimaryColor' => $settings->primary_color ?? '#0d6efd',
                'reportSecondaryColor' => $settings->secondary_color ?? '#ffc107',
                'reportBackgroundColor' => $settings->background_color ?? '#ffffff',

                'whatsapp' => [
                    'enabled' => (bool) $settings->whatsapp_enabled,
                    'feeReminders' => (bool) $settings->whatsapp_fee_reminders,
                    'activityNotices' => (bool) $settings->whatsapp_activity_notices,
                    // ✅ removed: subscriptionReminders
                ],
            ],

            'school_settings' => [
                'schoolName' => $settings->school_name,
                'address' => $settings->address,
                'email' => $settings->email,
                'phone' => $settings->phone,
                'prefix' => $settings->prefix,
                'customDomain' => $settings->custom_domain,
                'logo_url' => $settings->logo ? asset($settings->logo) : null,
                'principal_signature_url' => $settings->principal_signature ? asset($settings->principal_signature) : null,
            ],
        ]);
    }

    public function saveSettings(Request $request)
    {
        $userId = Auth::id();

        $validated = $request->validate([
            'school_name' => 'required|string|max:255',
            'email' => 'nullable|email',
            'address' => 'required|string',
            'phone' => 'required|string',

            'custom_domain' => 'nullable|string|unique:school_settings,custom_domain,' . $userId . ',user_id',

            'primary_color' => 'required|string',
            'secondary_color' => 'required|string',
            'background_color' => 'required|string',

            'logo' => 'nullable|image|max:2048',
            'principal_signature' => 'nullable|image|max:2048',
            'prefix' => 'nullable|string|max:5',
            'auto_admission' => 'nullable|in:0,1',

            // ✅ WhatsApp toggles
            'whatsapp_enabled' => 'nullable|in:0,1',
            'whatsapp_fee_reminders' => 'nullable|in:0,1',
            'whatsapp_activity_notices' => 'nullable|in:0,1',
            // ✅ removed: whatsapp_subscription_reminders
        ]);

        $data = [
            'school_name'      => $validated['school_name'],
            'email'            => $validated['email'] ?? null,
            'address'          => $validated['address'],
            'phone'            => $validated['phone'],
            'prefix'           => $validated['prefix'] ?? null,
            'custom_domain'    => $validated['custom_domain'] ?? null,
            'primary_color'    => $validated['primary_color'],
            'secondary_color'  => $validated['secondary_color'],
            'background_color' => $validated['background_color'],
            'auto_admission'   => (int)($validated['auto_admission'] ?? 0),

            // ✅ WhatsApp toggles
            'whatsapp_enabled' => (int)($validated['whatsapp_enabled'] ?? 0),
            'whatsapp_fee_reminders' => (int)($validated['whatsapp_fee_reminders'] ?? 0),
            'whatsapp_activity_notices' => (int)($validated['whatsapp_activity_notices'] ?? 0),
            // ✅ removed: whatsapp_subscription_reminders
        ];

        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            $filename = time() . '_' . $file->getClientOriginalName();

            $existing = SchoolSetting::where('user_id', $userId)->first();
            if ($existing && $existing->logo && file_exists(public_path($existing->logo))) {
                @unlink(public_path($existing->logo));
            }

            $file->move(public_path('uploads/logo'), $filename);
            $data['logo'] = 'uploads/logo/' . $filename;
        }

        if ($request->hasFile('principal_signature')) {
            $file = $request->file('principal_signature');
            $filename = time() . '_' . $file->getClientOriginalName();

            $existing = SchoolSetting::where('user_id', $userId)->first();
            if ($existing && $existing->principal_signature && file_exists(public_path($existing->principal_signature))) {
                @unlink(public_path($existing->principal_signature));
            }

            $file->move(public_path('uploads/signatures'), $filename);
            $data['principal_signature'] = 'uploads/signatures/' . $filename;
        }

        $setting = SchoolSetting::updateOrCreate(
            ['user_id' => $userId],
            $data
        );

        return response()->json([
            'message' => 'Settings saved successfully.',
            'school_settings' => [
                ...$data,
                'logo' => $setting->logo,
                'logo_url' => $setting->logo ? URL::to($setting->logo) : null,
                'principal_signature' => $setting->principal_signature,
                'principal_signature_url' => $setting->principal_signature ? URL::to($setting->principal_signature) : null,
            ],
        ]);
    }

    public function updateAutoAdmission(Request $request)
    {
        $userId = Auth::id();

        $validated = $request->validate([
            'auto_admission' => 'required|in:0,1',
        ]);

        $setting = SchoolSetting::updateOrCreate(
            ['user_id' => $userId],
            ['auto_admission' => (int)$validated['auto_admission']]
        );

        return response()->json([
            'message' => 'Auto admission setting updated successfully.',
            'auto_admission' => (int) $setting->auto_admission,
        ]);
    }

    public function getAutoAdmissionStatus()
    {
        $userId = Auth::id();

        $settings = SchoolSetting::where('user_id', $userId)->first();

        if (!$settings) {
            return response()->json([
                'message' => 'Settings not found.',
                'auto_admission' => 0
            ], 404);
        }

        return response()->json([
            'auto_admission' => (int) $settings->auto_admission
        ]);
    }
}