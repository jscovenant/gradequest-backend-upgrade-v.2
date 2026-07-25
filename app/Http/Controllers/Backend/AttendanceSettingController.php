<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\AttendanceSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;


class AttendanceSettingController extends Controller
{
    // GET /api/attendance-settings
    public function show()
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'Unauthenticated'], 401);

        $schoolId = $user->school_id;

        $setting = AttendanceSetting::firstOrCreate(
            ['school_id' => $schoolId],
            [
                'staff_checkin_time' => '08:00:00',
                'grace_minutes' => 10,
                'staff_checkout_time' => null,
                'absent_after_time' => null,
                'school_latitude' => null,
                'school_longitude' => null,
                'allowed_radius_meters' => 100,
                'qr_expires_seconds' => 300,
                'require_location_verification' => true,
                'is_active' => true,
            ]
        );

        return response()->json([
            'success' => true,
            'data' => $setting,
        ]);
    }

    // PUT /api/attendance-settings
    public function update(Request $request)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'Unauthenticated'], 401);

        $validated = $request->validate([
            'staff_checkin_time' => 'required|date_format:H:i',
            'grace_minutes' => 'required|integer|min:0|max:180',
            'staff_checkout_time' => 'nullable|date_format:H:i',
            'absent_after_time' => 'nullable|date_format:H:i',
            'school_latitude' => 'nullable|numeric|between:-90,90',
            'school_longitude' => 'nullable|numeric|between:-180,180',
            'allowed_radius_meters' => 'required|integer|min:10|max:5000',
            'qr_expires_seconds' => 'required|integer|min:60|max:600',
            'require_location_verification' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);

        $schoolId = $user->school_id;

        $setting = AttendanceSetting::updateOrCreate(
            ['school_id' => $schoolId],
            [
                'staff_checkin_time' => $validated['staff_checkin_time'] . ':00',
                'grace_minutes' => $validated['grace_minutes'],
                'staff_checkout_time' => isset($validated['staff_checkout_time'])
                    ? $validated['staff_checkout_time'] . ':00'
                    : null,
                'absent_after_time' => isset($validated['absent_after_time'])
                    ? $validated['absent_after_time'] . ':00'
                    : null,
                'school_latitude' => $validated['school_latitude'] ?? null,
                'school_longitude' => $validated['school_longitude'] ?? null,
                'allowed_radius_meters' => $validated['allowed_radius_meters'],
                'qr_expires_seconds' => $validated['qr_expires_seconds'],
                'require_location_verification' => $validated['require_location_verification'] ?? true,
                'is_active' => $validated['is_active'] ?? true,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Attendance settings saved',
            'data' => $setting,
        ]);
    }
}
