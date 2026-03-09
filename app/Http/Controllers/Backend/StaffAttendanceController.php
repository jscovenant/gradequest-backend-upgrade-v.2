<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\BiometricId;
use App\Models\StaffAttendance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use App\Models\AttendanceSetting;


class StaffAttendanceController extends Controller
{
    /**
     * Mark attendance with biometric_code (from QR scan).
     * Behaviour:
     * - If no record for today: create + check_in_at
     * - If record exists and check_out_at is null: set check_out_at
     * - If record exists and check_out_at exists: return already_marked
     */
    public function mark(Request $request)
    {
        $request->validate([
            'biometric_code' => 'required|string',
            'source' => 'nullable|string',  // camera|manual|device
            'device_id' => 'nullable|string',
            'mode' => 'nullable|string|in:auto,checkin,checkout',
            'notes' => 'nullable|string',
        ]);

        $schoolId = Auth::user()->school_id;
        $today = Carbon::today(); // uses app timezone
        $now = Carbon::now();


$settings = AttendanceSetting::firstOrCreate(
    ['school_id' => $schoolId],
    ['staff_checkin_time' => '08:00:00', 'grace_minutes' => 10, 'is_active' => true]
);

if (!$settings->is_active) {
    return response()->json([
        'success' => false,
        'message' => 'Attendance system is disabled in settings',
    ], 400);
}

[$h, $m, $s] = array_pad(explode(':', $settings->staff_checkin_time), 3, 0);

$expected = Carbon::today()->setTime((int)$h, (int)$m, (int)$s);
$lateCutoff = $expected->copy()->addMinutes((int)$settings->grace_minutes);

$computedStatus = $now->gt($lateCutoff) ? 'late' : 'present';


        // 1) Find biometric record and ensure it's valid + belongs to this school
        $bio = BiometricId::with('user')
            ->where('biometric_code', $request->biometric_code)
            // ->where('school_id', $schoolId)
            ->first();

        if (!$bio) {
            return response()->json([
                'success' => false,
                'message' => 'Biometric code not found for this school',
            ], 404);
        }

        if ($bio->expires_at && Carbon::parse($bio->expires_at)->isPast()) {
            return response()->json([
                'success' => false,
                'message' => 'Biometric code expired',
            ], 400);
        }

        $user = $bio->user; // staff

        // 2) Find existing attendance row for today
        $attendance = StaffAttendance::where('school_id', $schoolId)
            ->where('user_id', $user->id)
            ->whereDate('att_date', $today)
            ->first();

        $mode = $request->mode ?? 'auto'; // auto | checkin | checkout

        // 3) If no record -> create and set check_in_at
        if (!$attendance) {
            $attendance = StaffAttendance::create([
                'school_id' => $schoolId,
                'user_id' => $user->id,
                'att_date' => $today->toDateString(),
                'check_in_at' => $now,
               'status' => $computedStatus,
                'source' => $request->source ?? 'qr',
                'device_id' => $request->device_id,
                'notes' => $request->notes,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Checked in successfully',
                'action' => 'checkin',
                'attendance' => $attendance,
                'user' => [
                    'id' => $user->id,
                    'firstname' => $user->firstname,
                    'surname' => $user->surname,
                    'email' => $user->email,
                    'reg_no' => $user->reg_no ?? null,
                ],
            ]);
        }

        // 4) Record exists — decide checkin/checkout
        if ($mode === 'checkin') {
            if ($attendance->check_in_at) {
                return response()->json([
                    'success' => true,
                    'message' => 'Already checked in today',
                    'already_marked' => true,
                    'action' => 'none',
                    'attendance' => $attendance,
                ]);
            }

            $attendance->update([
                'check_in_at' => $now,
                'source' => $request->source ?? $attendance->source,
                'device_id' => $request->device_id ?? $attendance->device_id,
                'notes' => $request->notes ?? $attendance->notes,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Checked in successfully',
                'action' => 'checkin',
                'attendance' => $attendance->fresh(),
            ]);
        }

        if ($mode === 'checkout') {
            if ($attendance->check_out_at) {
                return response()->json([
                    'success' => true,
                    'message' => 'Already checked out today',
                    'already_marked' => true,
                    'action' => 'none',
                    'attendance' => $attendance,
                ]);
            }

            $attendance->update([
                'check_out_at' => $now,
                'source' => $request->source ?? $attendance->source,
                'device_id' => $request->device_id ?? $attendance->device_id,
                'notes' => $request->notes ?? $attendance->notes,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Checked out successfully',
                'action' => 'checkout',
                'attendance' => $attendance->fresh(),
            ]);
        }

        // AUTO mode:
        // If no check_in => set check_in
        // else if no check_out => set check_out
        // else already done
        if (!$attendance->check_in_at) {
            $attendance->update([
                'check_in_at' => $now,
                'source' => $request->source ?? $attendance->source,
                'device_id' => $request->device_id ?? $attendance->device_id,
                'notes' => $request->notes ?? $attendance->notes,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Checked in successfully',
                'action' => 'checkin',
                'attendance' => $attendance->fresh(),
            ]);
        }

        if (!$attendance->check_out_at) {
            $attendance->update([
                'check_out_at' => $now,
                'source' => $request->source ?? $attendance->source,
                'device_id' => $request->device_id ?? $attendance->device_id,
                'notes' => $request->notes ?? $attendance->notes,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Checked out successfully',
                'action' => 'checkout',
                'attendance' => $attendance->fresh(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Attendance already completed for today',
            'already_marked' => true,
            'action' => 'none',
            'attendance' => $attendance,
        ]);
    }


     /**
     * GET /api/staff-attendance/logs
     * Filters:
     *  - from (YYYY-MM-DD)
     *  - to (YYYY-MM-DD)
     *  - q (search staff name/reg_no/email)
     *  - status (present|late|absent|on_leave)
     *  - page, per_page
     */
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
            ->with(['staff:id,firstname,surname,email,reg_no,photo']) // staff() relation on model
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

        // quick summary counts for the range (same filters except pagination)
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




  
}
