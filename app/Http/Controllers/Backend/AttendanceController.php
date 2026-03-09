<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Attendance;
use App\Models\StudentClass;
use App\Models\TeacherEnrollment;
use Illuminate\Support\Facades\Auth;

class AttendanceController extends Controller
{
    /**
     * Return classes the logged-in user can take attendance for.
     * - admin: all classes in school
     * - teacher: only assigned class (TeacherEnrollment.level_id)
     */
    public function classes()
    {
        $auth = Auth::user();

        $role =  $auth->role; 
        $schoolId = $auth->school_id;

        if ($role === 'Admin') {
            $classes = StudentClass::where('school_id', $schoolId)->get();
            return response()->json([
                'role' => 'Admin',
                'default_class_id' => null,
                'classes' => $classes,
            ]);
        }

        if ($role === 'Teacher') {
            $enrollment = TeacherEnrollment::where('user_id', $auth->id)
                ->where('school_id', $schoolId)
                ->first();

            $defaultClassId = $enrollment?->level_id;

            $classes = StudentClass::where('school_id', $schoolId)
                ->when($defaultClassId, fn ($q) => $q->where('id', $defaultClassId))
                ->get();

            return response()->json([
                'role' => 'Teacher',
                'default_class_id' => $defaultClassId,
                'classes' => $classes,
            ]);
        }

        // fallback: return no classes
        return response()->json([
            'role' => 'other',
            'default_class_id' => null,
            'classes' => [],
        ]);
    }

    /**
     * Fetch students by class + their attendance for a given date
     * - teacher can only query their assigned class
     * - admin can query any class in school
     */
    public function index(Request $request)
    {
        $auth = Auth::user();
        $role = strtolower((string) $auth->role);
        $schoolId = $auth->school_id;

        $request->validate([
            'class_id' => 'required|exists:student_classes,id',
            'date'     => 'nullable|date',
        ]);

        $classId = (int) $request->query('class_id');
        $date    = $request->query('date') ?? now()->toDateString();

        // authorization: teacher restricted to assigned class
        if ($role === 'teacher') {
            $assigned = TeacherEnrollment::where('user_id', $auth->id)
                ->where('school_id', $schoolId)
                ->value('level_id');

            if (!$assigned || (int)$assigned !== (int)$classId) {
                return response()->json(['message' => 'You are not assigned to this class.'], 403);
            }
        }

        // admin should also be restricted to same school classes
        $classExistsInSchool = StudentClass::where('school_id', $schoolId)
            ->where('id', $classId)
            ->exists();

        if (!$classExistsInSchool) {
            return response()->json(['message' => 'Class not found for your school.'], 404);
        }

        $students = User::with([
                'level',
                'attendances' => function ($q) use ($date) {
                    $q->whereDate('date', $date);
                }
            ])
            ->where('role', 'Student')
            ->where('school_id', $schoolId)
            ->where('level_id', $classId)
            ->get();

        return response()->json($students);
    }

    /**
     * Save/update attendance
     * - teacher: can only save for assigned class, and only for students in that class/school
     * - admin: can save for any class in school
     */
    public function store(Request $request)
    {
        $auth = Auth::user();
        $role = strtolower((string) $auth->role);
        $schoolId = $auth->school_id;

        $validated = $request->validate([
            'class_id' => 'required|exists:student_classes,id',
            'date' => 'required|date',
            'students' => 'required|array',
            'students.*.student_id' => 'required|exists:users,id',
            'students.*.status' => 'required|in:present,absent,late,excused',
            'students.*.remarks' => 'nullable|string',
        ]);

        $classId = (int) $validated['class_id'];

        // admin should be restricted to same school classes
        $classExistsInSchool = StudentClass::where('school_id', $schoolId)
            ->where('id', $classId)
            ->exists();

        if (!$classExistsInSchool) {
            return response()->json(['message' => 'Class not found for your school.'], 404);
        }

        // authorization: teacher restricted to assigned class
        if ($role === 'teacher') {
            $assigned = TeacherEnrollment::where('user_id', $auth->id)
                ->where('school_id', $schoolId)
                ->value('level_id');

            if (!$assigned || (int)$assigned !== (int)$classId) {
                return response()->json(['message' => 'You are not assigned to this class.'], 403);
            }
        }

        // Build a whitelist of valid student IDs in this school+class
        $validStudentIds = User::where('role', 'Student')
            ->where('school_id', $schoolId)
            ->where('level_id', $classId)
            ->pluck('id')
            ->map(fn($v) => (int)$v)
            ->toArray();

        $validSet = array_flip($validStudentIds);

        foreach ($validated['students'] as $stu) {
            $sid = (int) $stu['student_id'];
            if (!isset($validSet[$sid])) {
                // skip or block. I recommend block to prevent tampering.
                return response()->json([
                    'message' => 'Invalid student for this class.',
                    'student_id' => $sid
                ], 422);
            }

            Attendance::updateOrCreate(
                [
                    'student_id' => $sid,
                    'date' => $validated['date'],
                ],
                [
                    'class_id' => $classId,
                    'school_id' => $schoolId,
                    'status' => $stu['status'],
                    'remarks' => $stu['remarks'] ?? null,
                ]
            );
        }

        return response()->json([
            'message' => 'Attendance saved successfully',
        ], 200);
    }

    public function report(Request $request)
    {
        $validated = $request->validate([
            'class_id'  => 'required|exists:student_classes,id',
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ]);

        $schoolId = Auth::user()->school_id;
        $classId = (int) $validated['class_id'];

        // restrict to school
        $classExistsInSchool = StudentClass::where('school_id', $schoolId)
            ->where('id', $classId)
            ->exists();

        if (!$classExistsInSchool) {
            return response()->json(['message' => 'Class not found for your school.'], 404);
        }

        $students = User::where('role', 'Student')
            ->where('school_id', $schoolId)
            ->where('level_id', $classId)
            ->with(['attendances' => function ($q) use ($validated) {
                $q->whereBetween('date', [$validated['start_date'], $validated['end_date']]);
            }])
            ->get();

        $report = $students->map(function ($stu) {
            $present = $stu->attendances->where('status', 'present')->count();
            $absent = $stu->attendances->where('status', 'absent')->count();
            $late = $stu->attendances->where('status', 'late')->count();
            $excused = $stu->attendances->where('status', 'excused')->count();

            return [
                'id'                 => $stu->id,
                'name'               => $stu->surname . ' ' . $stu->firstname,
                'present'            => $present,
                'absent'             => $absent,
                'late'               => $late,
                'excused'            => $excused,
                'totalTimesPresent'  => $present + $late,
                'totalTimesAbsent'   => $absent + $excused,
                'total'              => $stu->attendances->count(),
            ];
        });

        return response()->json($report);
    }
}