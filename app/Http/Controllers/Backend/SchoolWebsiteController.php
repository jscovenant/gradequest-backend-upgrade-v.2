<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\SchoolSetting;
use App\Models\SchoolWebsiteSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SchoolWebsiteController extends Controller
{
    /**
     * Resolve school safely.
     */
    protected function resolveSchool(Request $request): ?SchoolSetting
    {
        $user = Auth::user();
        $schoolId = $user->school_id ?? $request->query('school_id') ?? $request->input('school_id');
        if ($schoolId) {
            $school = SchoolSetting::find($schoolId);
            if ($school) {
                return $school;
            }
        }
        if ($user && ($user->role === 'Super-Admin' || (method_exists($user, 'isSuperAdminUser') && $user->isSuperAdminUser()))) {
            return SchoolSetting::first();
        }
        return null;
    }

    /**
     * Get or initialize School Website Settings.
     */
    public function getSettings(Request $request): JsonResponse
    {
        $school = $this->resolveSchool($request);
        if (!$school) {
            return response()->json(['status' => false, 'message' => 'Active school profile not found.'], 404);
        }

        $website = SchoolWebsiteSetting::firstOrCreate(
            ['school_id' => $school->id],
            SchoolWebsiteSetting::defaultSettings($school)
        );

        return response()->json([
            'status' => true,
            'school' => [
                'id' => $school->id,
                'name' => $school->school_name,
                'logo' => $this->formatAssetUrl($school->logo),
                'custom_domain' => $school->custom_domain,
                'subdomain' => $school->school_subdomain,
            ],
            'website' => $website,
            'preview_url' => $school->custom_domain ? "https://{$school->custom_domain}" : config('app.frontend_url') . "/school/{$school->id}",
        ]);
    }

    /**
     * Update School Website Settings (Colors, Content, Navigation, Pages).
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $school = $this->resolveSchool($request);
        if (!$school) {
            return response()->json(['status' => false, 'message' => 'Active school profile not found.'], 404);
        }

        $website = SchoolWebsiteSetting::firstOrCreate(
            ['school_id' => $school->id],
            SchoolWebsiteSetting::defaultSettings($school)
        );

        $validated = $request->validate([
            // Colors & Brand Theme
            'theme_color_primary' => 'nullable|string|max:25',
            'theme_color_secondary' => 'nullable|string|max:25',
            'theme_color_accent' => 'nullable|string|max:25',
            'theme_color_text' => 'nullable|string|max:25',
            'theme_color_background' => 'nullable|string|max:25',
            'font_family' => 'nullable|string|max:60',

            // Hero & Headers
            'site_title' => 'nullable|string|max:255',
            'tagline' => 'nullable|string|max:255',
            'hero_badge' => 'nullable|string|max:255',
            'hero_title' => 'nullable|string|max:255',
            'hero_subtitle' => 'nullable|string|max:1000',
            'hero_cta_text' => 'nullable|string|max:100',
            'hero_cta_url' => 'nullable|string|max:255',
            'hero_secondary_cta_text' => 'nullable|string|max:100',
            'hero_secondary_cta_url' => 'nullable|string|max:255',

            // Principal
            'principal_name' => 'nullable|string|max:255',
            'principal_title' => 'nullable|string|max:255',
            'principal_welcome_title' => 'nullable|string|max:255',
            'principal_welcome_message' => 'nullable|string',

            // About & Philosophy
            'about_title' => 'nullable|string|max:255',
            'about_content' => 'nullable|string',
            'motto' => 'nullable|string|max:500',
            'mission' => 'nullable|string',
            'vision' => 'nullable|string',
            'core_values' => 'nullable|array',

            // Dynamic Sections
            'facilities' => 'nullable|array',
            'programs' => 'nullable|array',
            'gallery' => 'nullable|array',
            'testimonials' => 'nullable|array',
            'faqs' => 'nullable|array',
            'custom_pages' => 'nullable|array',
            'nav_links' => 'nullable|array',
            'announcements' => 'nullable|array',

            // Contact
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'contact_address' => 'nullable|string|max:500',
            'google_map_embed_url' => 'nullable|string|max:1000',
            'facebook_url' => 'nullable|url|max:255',
            'instagram_url' => 'nullable|url|max:255',
            'twitter_url' => 'nullable|url|max:255',
            'linkedin_url' => 'nullable|url|max:255',
            'youtube_url' => 'nullable|url|max:255',

            // Switches
            'show_admissions_cta' => 'nullable|boolean',
            'show_fee_payment_cta' => 'nullable|boolean',
            'show_result_checker_cta' => 'nullable|boolean',
            'show_portal_login_cta' => 'nullable|boolean',
            'is_published' => 'nullable|boolean',
        ]);

        $website->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'School website settings updated successfully.',
            'website' => $website->fresh(),
        ]);
    }

    /**
     * Upload Media Assets (Hero, Principal Photo, Gallery images).
     */
    public function uploadMedia(Request $request): JsonResponse
    {
        $school = $this->resolveSchool($request);
        if (!$school) {
            return response()->json(['status' => false, 'message' => 'Active school profile not found.'], 404);
        }

        $request->validate([
            'type' => 'required|string|in:hero_image,principal_photo,about_image,gallery_image,logo',
            'file' => 'required|image|mimes:jpeg,png,jpg,webp,svg|max:4096',
        ]);

        $type = $request->input('type');
        $file = $request->file('file');
        $fileName = 'school_' . $school->id . '_' . $type . '_' . time() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs("uploads/schools/{$school->id}/website", $fileName, 'public');
        $fullUrl = asset('storage/' . $path);

        $website = SchoolWebsiteSetting::firstOrCreate(
            ['school_id' => $school->id],
            SchoolWebsiteSetting::defaultSettings($school)
        );

        if ($type === 'hero_image') {
            $website->update(['hero_image' => $fullUrl]);
        } elseif ($type === 'principal_photo') {
            $website->update(['principal_photo' => $fullUrl]);
        } elseif ($type === 'about_image') {
            $website->update(['about_image' => $fullUrl]);
        } elseif ($type === 'logo') {
            $school->update(['logo' => $fullUrl]);
        } elseif ($type === 'gallery_image') {
            $currentGallery = $website->gallery ?: [];
            $currentGallery[] = [
                'image_url' => $fullUrl,
                'title' => $request->input('title', 'Campus Activity'),
                'category' => $request->input('category', 'General'),
            ];
            $website->update(['gallery' => $currentGallery]);
        }

        return response()->json([
            'status' => true,
            'message' => 'Image uploaded successfully.',
            'url' => $fullUrl,
            'school' => [
                'id' => $school->id,
                'name' => $school->school_name,
                'logo' => $this->formatAssetUrl($school->fresh()->logo),
            ],
            'website' => $website->fresh(),
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
