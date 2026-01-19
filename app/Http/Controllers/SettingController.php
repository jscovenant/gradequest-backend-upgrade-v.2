<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\ShowGrade;
use App\Models\GradeSetting;
use Illuminate\Http\Request;
use App\Models\SchoolSetting;
use App\Models\GradingForJunior;
use App\Models\GradingForSenior;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class SettingController extends Controller
{

  public function getSettings()
{
    $user = Auth::user();

    $settings = SchoolSetting::where('id', $user->school_id)->first();

    if (!$settings) {
        return response()->json([
            'message' => 'Settings not found.'
        ], 404);
    }

    return response()->json([
        'app_settings' => [
            'autoGenerateAdmissionNo' => (bool) $settings->auto_admission,
            'reportPrimaryColor' => $settings->primary_color ?? '#0d6efd',
            'reportSecondaryColor' => $settings->secondary_color ?? '#ffc107',
            'reportBackgroundColor' => $settings->background_color ?? '#ffffff', 
        ],
        'school_settings' => [
            'schoolName' => $settings->school_name,
            'address' => $settings->address,
            'email' => $settings->email,
            'phone' => $settings->phone,
            'prefix' => $settings->prefix,
            'customDomain' => $settings->custom_domain,
            'logo_url' => $settings->logo ? asset($settings->logo) : null,
            'principal_signature_url' => $settings->principal_signature ? asset($settings->principal_signature) : null, // add this line
        ],
    ]);
}

    



public function saveSettings(Request $request)
{
    $userId = Auth::user()->id;

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
        'principal_signature' => 'nullable|image|max:2048', // Add this line
        'prefix' => 'nullable|string|max:5',
        'auto_admission' => 'nullable|in:0,1',
    ]);

    $data = [
        'school_name'      => $validated['school_name'],
        'email'            => $validated['email'] ?? null,
        'address'          => $validated['address'],
        'phone'            => $validated['phone'],
        'prefix'           => $validated['prefix'],
        'custom_domain'    => $validated['custom_domain'] ?? null,
        'primary_color'    => $validated['primary_color'],
        'secondary_color'  => $validated['secondary_color'],
        'background_color' => $validated['background_color'],
        'auto_admission'   => $validated['auto_admission'] ?? 0,
    ];

    // Handle logo upload
    if ($request->hasFile('logo')) {
        $file = $request->file('logo');
        $filename = time() . '_' . $file->getClientOriginalName();

        $existing = SchoolSetting::where('user_id', $userId)->first();
        if ($existing && $existing->logo && file_exists(public_path($existing->logo))) {
            unlink(public_path($existing->logo));
        }

        $file->move(public_path('uploads/logo'), $filename);
        $data['logo'] = 'uploads/logo/' . $filename;
    }

    // Handle principal signature upload
    if ($request->hasFile('principal_signature')) {
        $file = $request->file('principal_signature');
        $filename = time() . '_' . $file->getClientOriginalName();

        $existing = SchoolSetting::where('user_id', $userId)->first();
        if ($existing && $existing->principal_signature && file_exists(public_path($existing->principal_signature))) {
            unlink(public_path($existing->principal_signature));
        }

        $file->move(public_path('uploads/signatures'), $filename);
        $data['principal_signature'] = 'uploads/signatures/' . $filename;
    }

    $setting = SchoolSetting::updateOrCreate(
        ['user_id' => $userId],
        $data
    );

    // ✅ Generate full URLs
    $logoUrl = $setting->logo ? URL::to($setting->logo) : null;
    $signatureUrl = $setting->principal_signature ? URL::to($setting->principal_signature) : null;

    return response()->json([
        'message' => 'Settings saved successfully.',
        'school_settings' => [
            ...$data,
            'logo' => $setting->logo,
            'logo_url' => $logoUrl,
            'principal_signature' => $setting->principal_signature,
            'principal_signature_url' => $signatureUrl
        ]
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
        ['auto_admission' => $validated['auto_admission']]
    );

    return response()->json([
        'message' => 'Auto admission setting updated successfully.',
        'auto_admission' => $setting->auto_admission,
    ]);
}






}
