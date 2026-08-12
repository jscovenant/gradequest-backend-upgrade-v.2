<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SchoolSetting;
use App\Models\SchoolDomain;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;

class SettingController extends Controller
{
    public function getSettings()
    {
        $user = Auth::user();

        $settings = $this->settingsFor($user);

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
                'customDomain' => SchoolDomain::query()
                    ->where('school_id', $settings->id)
                    ->where('status', 'active')
                    ->value('domain'),
                'logo_url' => $settings->logo ? asset($settings->logo) : null,
                'principal_signature_url' => $settings->principal_signature ? asset($settings->principal_signature) : null,
            ],
        ]);
    }

    public function saveSettings(Request $request)
    {
        $user = Auth::user();
        $settings = $this->settingsFor($user);

        abort_unless($settings, 404, 'Settings not found.');

        $validated = $request->validate([
            'school_name' => 'required|string|max:255',
            'email' => 'nullable|email',
            'address' => 'required|string',
            'phone' => 'required|string',

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
            'primary_color'    => $validated['primary_color'],
            'secondary_color'  => $validated['secondary_color'],
            'background_color' => $validated['background_color'],
            'auto_admission'   => (int)($validated['auto_admission'] ?? 0),

            // ✅ WhatsApp toggles
            // ✅ removed: whatsapp_subscription_reminders
        ];

        // WhatsApp access is managed from the dedicated WhatsApp Settings page.
        // Preserve it when the general settings form does not submit these fields.
        foreach (['whatsapp_enabled', 'whatsapp_fee_reminders', 'whatsapp_activity_notices'] as $field) {
            if (array_key_exists($field, $validated)) {
                $data[$field] = (int) $validated[$field];
            }
        }

        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            $filename = time() . '_' . $file->getClientOriginalName();

            if ($settings->logo && file_exists(public_path($settings->logo))) {
                @unlink(public_path($settings->logo));
            }

            $file->move(public_path('uploads/logo'), $filename);
            $data['logo'] = 'uploads/logo/' . $filename;
        }

        if ($request->hasFile('principal_signature')) {
            $file = $request->file('principal_signature');
            $filename = time() . '_' . $file->getClientOriginalName();

            if ($settings->principal_signature && file_exists(public_path($settings->principal_signature))) {
                @unlink(public_path($settings->principal_signature));
            }

            $file->move(public_path('uploads/signatures'), $filename);
            $data['principal_signature'] = 'uploads/signatures/' . $filename;
        }

        $settings->fill($data)->save();
        $setting = $settings->fresh();

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
        $user = Auth::user();
        $settings = $this->settingsFor($user);

        abort_unless($settings, 404, 'Settings not found.');

        $validated = $request->validate([
            'auto_admission' => 'required|in:0,1',
        ]);

        $settings->forceFill(['auto_admission' => (int) $validated['auto_admission']])->save();

        return response()->json([
            'message' => 'Auto admission setting updated successfully.',
            'auto_admission' => (int) $settings->auto_admission,
        ]);
    }

    public function getAutoAdmissionStatus()
    {
        $settings = $this->settingsFor(Auth::user());

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

    private function settingsFor($user): ?SchoolSetting
    {
        if (! $user) {
            return null;
        }

        return $user->school_id
            ? SchoolSetting::find($user->school_id)
            : SchoolSetting::where('user_id', $user->id)->first();
    }
}
