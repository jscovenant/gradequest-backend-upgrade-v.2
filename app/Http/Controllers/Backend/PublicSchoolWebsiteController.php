<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\SchoolAdmissionSetting;
use App\Models\SchoolDomain;
use App\Models\SchoolSetting;
use App\Models\SchoolWebsiteSetting;
use App\Models\StudentClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicSchoolWebsiteController extends Controller
{
    /**
     * Resolve and return complete public school website data.
     */
    public function show(Request $request, string $identifier = null): JsonResponse
    {
        $school = null;

        // 1. If explicit identifier provided (ID or Subdomain)
        if (! empty($identifier) && $identifier !== 'current') {
            if (is_numeric($identifier)) {
                $school = SchoolSetting::find($identifier);
            } else {
                $school = SchoolSetting::where('school_subdomain', $identifier)
                    ->orWhere('custom_domain', $identifier)
                    ->first();
            }
        }

        // 2. If no explicit identifier, resolve from incoming host header (custom domain)
        if (! $school) {
            $host = strtolower(trim($request->getHost(), '. '));
            $domainRecord = SchoolDomain::where('domain', $host)->where('status', 'active')->first();
            if ($domainRecord) {
                $school = SchoolSetting::find($domainRecord->school_id);
            } elseif ($hostSetting = SchoolSetting::where('custom_domain', $host)->first()) {
                $school = $hostSetting;
            }
        }

        // 3. Fallback to first school if local/sandbox or default
        if (! $school) {
            $school = SchoolSetting::first();
        }

        if (! $school) {
            return response()->json(['message' => 'School profile not found.'], 404);
        }

        // Get or initialize website settings
        $website = SchoolWebsiteSetting::firstOrCreate(
            ['school_id' => $school->id],
            SchoolWebsiteSetting::defaultSettings($school)
        );

        // Get admission settings
        $admissionSetting = SchoolAdmissionSetting::firstOrCreate(
            ['school_id' => $school->id],
            SchoolAdmissionSetting::defaultSettings($school)
        );

        // Get available classes for admissions
        $classes = StudentClass::where('school_id', $school->id)
            ->whereNull('archived_at')
            ->select('id', 'name')
            ->get();

        $formattedWebsite = $website->toArray();
        $formattedWebsite['hero_image'] = $this->formatAssetUrl($website->hero_image);
        $formattedWebsite['principal_photo'] = $this->formatAssetUrl($website->principal_photo);
        $formattedWebsite['about_image'] = $this->formatAssetUrl($website->about_image);

        return response()->json([
            'status' => true,
            'school' => [
                'id' => $school->id,
                'name' => $school->school_name,
                'email' => $school->email,
                'phone' => $school->phone ?: $school->phone_number,
                'address' => $school->address,
                'logo' => $this->formatAssetUrl($school->logo),
                'logo_url' => $this->formatAssetUrl($school->logo),
                'principal_signature' => $this->formatAssetUrl($school->principal_signature),
                'subdomain' => $school->school_subdomain,
                'custom_domain' => $school->custom_domain,
            ],
            'website' => $formattedWebsite,
            'admission' => [
                'is_open' => (bool) $admissionSetting->is_open,
                'session_name' => $admissionSetting->admission_session_name,
                'application_fee' => (float) $admissionSetting->application_fee,
                'platform_fee' => (float) $admissionSetting->platform_fee,
                'total_fee' => (float) ($admissionSetting->application_fee + $admissionSetting->platform_fee),
                'require_payment' => (bool) $admissionSetting->require_payment,
                'available_classes' => ! empty($classes) && $classes->isNotEmpty() ? $classes->pluck('name') : $admissionSetting->available_classes,
                'instructions' => $admissionSetting->instructions,
                'requirements' => $admissionSetting->requirements,
                'contact_email' => $admissionSetting->contact_email,
                'contact_phone' => $admissionSetting->contact_phone,
            ],
            'classes' => $classes,
        ]);
    }

    private function formatAssetUrl(?string $path): ?string
    {
        if (empty($path)) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, 'data:')) {
            return $path;
        }

        $clean = ltrim($path, '/');
        if (str_starts_with($clean, 'uploads/')) {
            return url($clean);
        }
        if (str_starts_with($clean, 'storage/')) {
            return url($clean);
        }

        return url('storage/' . $clean);
    }
}
