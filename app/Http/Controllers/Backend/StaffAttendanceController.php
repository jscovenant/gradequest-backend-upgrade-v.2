<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\AttendanceSetting;
use App\Models\StaffAttendance;
use App\Models\StaffAttendanceSession;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class StaffAttendanceController extends Controller
{
    public function currentSession(Request $request)
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        if (! $this->canGenerateSchoolQr($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Only authorized school users can view staff attendance QR codes.',
            ], 403);
        }

        $settings = $this->settingsForSchool((int) $user->school_id);
        $session = StaffAttendanceSession::where('school_id', $user->school_id)
            ->whereNull('closed_at')
            ->where('expires_at', '>=', now())
            ->whereNotNull('token')
            ->latest('id')
            ->first();

        if (! $session) {
            return response()->json([
                'success' => true,
                'data' => null,
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $this->sessionPayload($session, $settings),
        ]);
    }

    public function generateSession(Request $request)
    {
        $request->validate([
            'mode' => 'nullable|string|in:auto,checkin,checkout',
        ]);

        $user = Auth::user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        if (! $this->canGenerateSchoolQr($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Only authorized school users can generate staff attendance QR codes.',
            ], 403);
        }

        $settings = $this->settingsForSchool((int) $user->school_id);
        if (! $settings->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Attendance system is disabled in settings.',
            ], 422);
        }

        if ($settings->require_location_verification && (! $settings->school_latitude || ! $settings->school_longitude)) {
            return response()->json([
                'success' => false,
                'message' => 'Set the school location before generating attendance QR codes.',
            ], 422);
        }

        $token = Str::random(48);
        $ttl = max(60, min(600, (int) ($settings->qr_expires_seconds ?: 300)));

        StaffAttendanceSession::where('school_id', $user->school_id)
            ->whereNull('closed_at')
            ->where('expires_at', '>=', now())
            ->update(['closed_at' => now()]);

        $session = StaffAttendanceSession::create([
            'school_id' => $user->school_id,
            'token_hash' => hash('sha256', $token),
            'token' => $token,
            'mode' => $request->mode ?: 'auto',
            'expires_at' => now()->addSeconds($ttl),
            'created_by' => $user->id,
            'meta' => [
                'radius_meters' => (int) $settings->allowed_radius_meters,
                'location_required' => (bool) $settings->require_location_verification,
            ],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Attendance QR generated.',
        ] + $this->sessionPayload($session, $settings));
    }

    public function mark(Request $request)
    {
        $request->validate([
            'attendance_token' => 'required|string',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0',
            'source' => 'nullable|string|max:80',
            'device_id' => 'nullable|string|max:120',
            'mode' => 'nullable|string|in:auto,checkin,checkout',
            'notes' => 'nullable|string|max:500',
        ]);

        $authUser = Auth::user();
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $schoolId = (int) $authUser->school_id;
        $settings = $this->settingsForSchool($schoolId);

        if (! $settings->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Attendance system is disabled in settings.',
            ], 400);
        }

        $session = null;
        $staffUser = null;
        $location = [
            'verified' => false,
            'distance_meters' => null,
            'allowed_radius_meters' => (int) $settings->allowed_radius_meters,
        ];

        $session = $this->findLiveSession($schoolId, (string) $request->attendance_token);
        if (! $session) {
            return $this->invalidSessionResponse($schoolId, (string) $request->attendance_token, $authUser);
        }

        if ($this->isStudentOrParent($authUser)) {
            return response()->json([
                'success' => false,
                'message' => 'Only staff members can mark staff attendance.',
            ], 403);
        }

        $staffUser = $authUser;
        $location = $this->verifyLocation($request, $settings);

        $today = Carbon::today();
        $now = Carbon::now();
        $mode = $request->mode ?: ($session?->mode ?: 'auto');
        $computedStatus = $this->computedStatus($settings, $now);

        $attendance = StaffAttendance::where('school_id', $schoolId)
            ->where('user_id', $staffUser->id)
            ->whereDate('att_date', $today)
            ->first();

        if (! $attendance) {
            $attendance = StaffAttendance::create(array_merge([
                'school_id' => $schoolId,
                'user_id' => $staffUser->id,
                'attendance_session_id' => $session?->id,
                'att_date' => $today->toDateString(),
                'check_in_at' => $now,
                'status' => $computedStatus,
                'source' => $request->source ?? 'school_live_qr',
                'device_id' => $request->device_id,
                'location_verified' => $location['verified'],
                'notes' => $request->notes,
            ], $this->locationPayload('check_in', $request, $location)));

            return $this->attendanceResponse('Checked in successfully.', 'checkin', $attendance, $staffUser, $location);
        }

        if ($mode === 'checkin') {
            if ($attendance->check_in_at) {
                return $this->attendanceResponse('Already checked in today.', 'none', $attendance, $staffUser, $location, true);
            }

            $attendance->update(array_merge($this->sharedUpdatePayload($request, $attendance, $session, $location), [
                'check_in_at' => $now,
            ], $this->locationPayload('check_in', $request, $location)));

            return $this->attendanceResponse('Checked in successfully.', 'checkin', $attendance->fresh(), $staffUser, $location);
        }

        if ($mode === 'checkout') {
            if ($attendance->check_out_at) {
                return $this->attendanceResponse('Already checked out today.', 'none', $attendance, $staffUser, $location, true);
            }

            $attendance->update(array_merge($this->sharedUpdatePayload($request, $attendance, $session, $location), [
                'check_out_at' => $now,
            ], $this->locationPayload('check_out', $request, $location)));

            return $this->attendanceResponse('Checked out successfully.', 'checkout', $attendance->fresh(), $staffUser, $location);
        }

        if (! $attendance->check_in_at) {
            $attendance->update(array_merge($this->sharedUpdatePayload($request, $attendance, $session, $location), [
                'check_in_at' => $now,
            ], $this->locationPayload('check_in', $request, $location)));

            return $this->attendanceResponse('Checked in successfully.', 'checkin', $attendance->fresh(), $staffUser, $location);
        }

        if (! $attendance->check_out_at) {
            $attendance->update(array_merge($this->sharedUpdatePayload($request, $attendance, $session, $location), [
                'check_out_at' => $now,
            ], $this->locationPayload('check_out', $request, $location)));

            return $this->attendanceResponse('Checked out successfully.', 'checkout', $attendance->fresh(), $staffUser, $location);
        }

        return $this->attendanceResponse('Attendance already completed for today.', 'none', $attendance, $staffUser, $location, true);
    }

    public function logs(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $schoolId = $user->school_id;

        $perPage = (int) ($request->get('per_page', 20));
        if ($perPage < 5) $perPage = 5;
        if ($perPage > 100) $perPage = 100;

        $from = $request->get('from');
        $to = $request->get('to');
        $q = trim((string) $request->get('q', ''));
        $status = $request->get('status');

        $query = StaffAttendance::query()
            ->where('school_id', $schoolId)
            ->with(['staff:id,firstname,surname,email,reg_no,photo'])
            ->orderByDesc('att_date')
            ->orderByDesc('id');

        if ($from) $query->whereDate('att_date', '>=', $from);
        if ($to) $query->whereDate('att_date', '<=', $to);

        if ($status && in_array($status, ['present', 'late', 'absent', 'on_leave'])) {
            $query->where('status', $status);
        }

        if ($q !== '') {
            $query->whereHas('staff', function ($s) use ($q) {
                $s->where('firstname', 'like', "%{$q}%")
                  ->orWhere('surname', 'like', "%{$q}%")
                  ->orWhere('email', 'like', "%{$q}%")
                  ->orWhere('reg_no', 'like', "%{$q}%");
            });
        }

        $paginated = $query->paginate($perPage);

        $summaryQuery = StaffAttendance::query()
            ->where('school_id', $schoolId);

        if ($from) $summaryQuery->whereDate('att_date', '>=', $from);
        if ($to) $summaryQuery->whereDate('att_date', '<=', $to);
        if ($status && in_array($status, ['present', 'late', 'absent', 'on_leave'])) {
            $summaryQuery->where('status', $status);
        }
        if ($q !== '') {
            $summaryQuery->whereHas('staff', function ($s) use ($q) {
                $s->where('firstname', 'like', "%{$q}%")
                  ->orWhere('surname', 'like', "%{$q}%")
                  ->orWhere('email', 'like', "%{$q}%")
                  ->orWhere('reg_no', 'like', "%{$q}%");
            });
        }

        $counts = (clone $summaryQuery)
            ->selectRaw("status, COUNT(*) as total")
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'success' => true,
            'data' => $paginated,
            'summary' => [
                'total' => (clone $summaryQuery)->count(),
                'present' => (int) ($counts['present'] ?? 0),
                'late' => (int) ($counts['late'] ?? 0),
                'absent' => (int) ($counts['absent'] ?? 0),
                'on_leave' => (int) ($counts['on_leave'] ?? 0),
            ],
        ]);
    }

    private function settingsForSchool(int $schoolId): AttendanceSetting
    {
        return AttendanceSetting::firstOrCreate(
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
    }

    private function findLiveSession(int $schoolId, string $token): ?StaffAttendanceSession
    {
        return StaffAttendanceSession::where('school_id', $schoolId)
            ->where('token_hash', hash('sha256', trim($token)))
            ->whereNull('closed_at')
            ->where('expires_at', '>=', now())
            ->first();
    }

    private function invalidSessionResponse(int $schoolId, string $token, $user)
    {
        $session = StaffAttendanceSession::where('token_hash', hash('sha256', trim($token)))
            ->latest('id')
            ->first();

        $message = 'Attendance QR is invalid. Ask the school to generate a fresh QR.';

        if ($session && (int) $session->school_id !== $schoolId) {
            $message = 'Attendance QR belongs to another school. Confirm that this teacher account is linked to the same school that generated the QR.';
        } elseif ($session && $session->closed_at) {
            $message = 'Attendance QR has been replaced by a newer QR. Please scan the latest QR shown by the school.';
        } elseif ($session && $session->expires_at && $session->expires_at->isPast()) {
            $message = 'Attendance QR has expired. Ask the school to generate a fresh QR and scan it immediately.';
        }

        return response()->json([
            'success' => false,
            'message' => $message,
            'user' => [
                'id' => $user->id,
                'firstname' => $user->firstname,
                'surname' => $user->surname,
                'email' => $user->email,
                'reg_no' => $user->reg_no ?? null,
            ],
        ], 422);
    }

    private function sessionPayload(StaffAttendanceSession $session, AttendanceSetting $settings): array
    {
        $token = (string) $session->token;

        return [
            'token' => $token,
            'qr_payload' => 'GQ_STAFF_ATTENDANCE:' . $token,
            'mode' => $session->mode,
            'expires_at' => $session->expires_at,
            'ttl_seconds' => max(0, now()->diffInSeconds($session->expires_at, false)),
            'settings' => [
                'school_latitude' => $settings->school_latitude,
                'school_longitude' => $settings->school_longitude,
                'allowed_radius_meters' => (int) $settings->allowed_radius_meters,
                'require_location_verification' => (bool) $settings->require_location_verification,
            ],
        ];
    }

    private function verifyLocation(Request $request, AttendanceSetting $settings): array
    {
        $required = (bool) $settings->require_location_verification;
        $schoolLatitude = $settings->school_latitude;
        $schoolLongitude = $settings->school_longitude;

        if ($required && (! $schoolLatitude || ! $schoolLongitude)) {
            abort(response()->json([
                'success' => false,
                'message' => 'School location is not configured for staff attendance.',
            ], 422));
        }

        if ($required && (! $request->filled('latitude') || ! $request->filled('longitude'))) {
            abort(response()->json([
                'success' => false,
                'message' => 'Location permission is required before staff attendance can be marked.',
            ], 422));
        }

        $distance = null;
        if ($request->filled('latitude') && $request->filled('longitude') && $schoolLatitude && $schoolLongitude) {
            $distance = $this->distanceMeters(
                (float) $schoolLatitude,
                (float) $schoolLongitude,
                (float) $request->latitude,
                (float) $request->longitude
            );
        }

        $allowed = (int) ($settings->allowed_radius_meters ?: 100);
        if ($required && $distance !== null && $distance > $allowed) {
            abort(response()->json([
                'success' => false,
                'message' => 'Attendance denied. You are outside the approved school location radius.',
                'distance_meters' => $distance,
                'allowed_radius_meters' => $allowed,
            ], 422));
        }

        return [
            'verified' => ! $required || ($distance !== null && $distance <= $allowed),
            'distance_meters' => $distance,
            'allowed_radius_meters' => $allowed,
        ];
    }

    private function computedStatus(AttendanceSetting $settings, Carbon $now): string
    {
        [$h, $m, $s] = array_pad(explode(':', (string) $settings->staff_checkin_time), 3, 0);

        $expected = Carbon::today()->setTime((int) $h, (int) $m, (int) $s);
        $lateCutoff = $expected->copy()->addMinutes((int) $settings->grace_minutes);

        return $now->gt($lateCutoff) ? 'late' : 'present';
    }

    private function locationPayload(string $prefix, Request $request, array $location): array
    {
        return [
            "{$prefix}_latitude" => $request->filled('latitude') ? $request->latitude : null,
            "{$prefix}_longitude" => $request->filled('longitude') ? $request->longitude : null,
            "{$prefix}_distance_meters" => $location['distance_meters'],
        ];
    }

    private function sharedUpdatePayload(Request $request, StaffAttendance $attendance, ?StaffAttendanceSession $session, array $location): array
    {
        return [
            'attendance_session_id' => $session?->id ?? $attendance->attendance_session_id,
            'source' => $request->source ?? $attendance->source,
            'device_id' => $request->device_id ?? $attendance->device_id,
            'location_verified' => $location['verified'] || $attendance->location_verified,
            'notes' => $request->notes ?? $attendance->notes,
        ];
    }

    private function attendanceResponse(string $message, string $action, StaffAttendance $attendance, $user, array $location, bool $alreadyMarked = false)
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'action' => $action,
            'already_marked' => $alreadyMarked,
            'attendance' => $attendance,
            'location_verified' => $location['verified'],
            'distance_meters' => $location['distance_meters'],
            'allowed_radius_meters' => $location['allowed_radius_meters'],
            'user' => [
                'id' => $user->id,
                'firstname' => $user->firstname,
                'surname' => $user->surname,
                'email' => $user->email,
                'reg_no' => $user->reg_no ?? null,
            ],
        ]);
    }

    private function distanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): int
    {
        $earthRadius = 6371000;
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;

        return (int) round($earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    private function canGenerateSchoolQr($user): bool
    {
        return in_array(strtolower((string) $user->role), ['admin', 'superadmin', 'super admin', 'principal', 'owner'], true);
    }

    private function isStudentOrParent($user): bool
    {
        return in_array(strtolower((string) $user->role), ['student', 'parent'], true);
    }
}
