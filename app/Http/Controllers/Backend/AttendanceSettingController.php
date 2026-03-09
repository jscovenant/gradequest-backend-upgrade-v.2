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
