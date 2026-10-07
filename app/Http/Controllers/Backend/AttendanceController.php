<?php

namespace App\Http\Controllers\Backend;

use App\Events\AttendanceMarked;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Attendance;
use App\Models\StudentClass;
use App\Models\TeacherEnrollment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Services\AcademicWeekService;

class AttendanceController extends Controller
{
    public function __construct(private AcademicWeekService $academicWeeks)
    {
    }
    /**
     * Check if role has administrative privileges
     */
    private function isAdminRole(?string $role): bool
    {
        $norm = strtolower(str_replace(['-', '_', ' '], '', (string) $role));
        return in_array($norm, [
            'admin',
            'superadmin',
            'proprietor',
            'principal',
            'operator',
            'schooloperator',
            'director',
            'bursar',
        ], true);
    }

    /**
     * Check if role is teacher
     */
    private function isTeacherRole(?string $role): bool
    {
        $norm = strtolower(str_replace(['-', '_', ' '], '', (string) $role));
        return in_array($norm, [
            'teacher',
            'classteacher',
            'subjectteacher',
        ], true);
    }

    /**
     * Return classes the logged-in user can take attendance for.
     * - admin, proprietor, principal, operator: all unarchived classes in school
     * - teacher: assigned class(es) from TeacherEnrollment or teacher_subjects, with fallback to all classes
     */
    public function classes()
    {
        $auth = Auth::user();
        $schoolId = $auth->school_id;
        $role = $auth->role;

        $isAdmin = $this->isAdminRole($role);
        $isTeacher = $this->isTeacherRole($role);

        if (!$isAdmin && !$isTeacher) {
            return response()->json([
                'role' => 'other',
                'default_class_id' => null,
                'classes' => [],
            ]);
        }

        if ($isAdmin) {
            $classes = StudentClass::where('school_id', $schoolId)
                ->whereNull('archived_at')
                ->orderBy('name')
                ->get(['id', 'name']);

            return response()->json([
                'role' => 'admin',
                'default_class_id' => $classes->first()?->id ?? null,
                'classes' => $classes,
                'academic_calendar' => $this->academicWeeks->contextForSchool((int) $schoolId),
            ]);
        }

        // For Teacher: check active enrollments in TeacherEnrollment
        $enrolledLevelIds = TeacherEnrollment::where('user_id', $auth->id)
            ->where('school_id', $schoolId)
            ->where(function ($q) {
                $q->where('enroll', '1')->orWhere('enroll', 1);
            })
            ->pluck('level_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        // Also check teacher_subjects for classes taught
        $subjectLevelIds = DB::table('teacher_subjects')
            ->join('subjects', 'subjects.id', '=', 'teacher_subjects.subject_id')
            ->where('teacher_subjects.teacher_id', $auth->id)
            ->whereNotNull('subjects.class_id')
            ->pluck('subjects.class_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $mergedLevelIds = array_values(array_unique(array_filter(array_merge($enrolledLevelIds, $subjectLevelIds))));

        if (!empty($mergedLevelIds)) {
            $classes = StudentClass::where('school_id', $schoolId)
                ->whereNull('archived_at')
                ->whereIn('id', $mergedLevelIds)
                ->orderBy('name')
                ->get(['id', 'name']);
        } else {
            // Fallback: If no specific class enrollment configured yet, show unarchived classes in school so teacher is not blocked
            $classes = StudentClass::where('school_id', $schoolId)
                ->whereNull('archived_at')
                ->orderBy('name')
                ->get(['id', 'name']);
        }

        $defaultClassId = $classes->first()?->id ?? null;

        return response()->json([
            'role' => 'teacher',
            'default_class_id' => $defaultClassId,
            'classes' => $classes,
            'academic_calendar' => $this->academicWeeks->contextForSchool((int) $schoolId),
        ]);
    }

    /**
     * Fetch students by class + their attendance for a given date
     * - teacher can only query their assigned class (if assigned)
     * - admin/proprietor/principal can query any unarchived class in school
     */
    public function index(Request $request)
    {
        $auth = Auth::user();
        $schoolId = $auth->school_id;
        $role = $auth->role;

        $isAdmin = $this->isAdminRole($role);
        $isTeacher = $this->isTeacherRole($role);

        if (!$isAdmin && !$isTeacher) {
            return response()->json(['message' => 'Unauthorized to view attendance.'], 403);
        }

        $request->validate([
            'class_id' => 'required|integer|exists:student_classes,id',
            'date'     => 'nullable|date',
            'week_number' => 'required|integer|min:1',
        ]);

        $classId = (int) $request->query('class_id');
        $date    = $request->query('date') ?? now()->toDateString();
        $this->validateAcademicWeek($schoolId, $date, (int) $request->query('week_number'));

        // verify class belongs to this school and is not archived
        $classExistsInSchool = StudentClass::where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->where('id', $classId)
            ->exists();

        if (!$classExistsInSchool) {
            return response()->json(['message' => 'Class not found for your school.'], 404);
        }

        // teacher authorization: if teacher has specific assigned classes, restrict to them
        if ($isTeacher && !$isAdmin) {
            $enrolledLevelIds = TeacherEnrollment::where('user_id', $auth->id)
                ->where('school_id', $schoolId)
                ->where(function ($q) {
                    $q->where('enroll', '1')->orWhere('enroll', 1);
                })
                ->pluck('level_id')
                ->filter()
                ->unique()
                ->values()
                ->all();

            $subjectLevelIds = DB::table('teacher_subjects')
                ->join('subjects', 'subjects.id', '=', 'teacher_subjects.subject_id')
                ->where('teacher_subjects.teacher_id', $auth->id)
                ->whereNotNull('subjects.class_id')
                ->pluck('subjects.class_id')
                ->filter()
                ->unique()
                ->values()
                ->all();

            $mergedLevelIds = array_values(array_unique(array_filter(array_merge($enrolledLevelIds, $subjectLevelIds))));

            if (!empty($mergedLevelIds) && !in_array($classId, array_map('intval', $mergedLevelIds), true)) {
                return response()->json(['message' => 'You are not assigned to this class.'], 403);
            }
        }

        $students = User::with([
                'attendances' => function ($q) use ($date, $classId) {
                    $q->whereDate('date', $date)->where('class_id', $classId);
                }
            ])
            ->withRole('student')
            ->where('school_id', $schoolId)
            ->where('level_id', $classId)
            ->where('status', 1)
            ->where(function ($q) {
                $q->whereNull('student_status')->orWhere('student_status', 'active');
            })
            ->select(['id', 'firstname', 'surname', 'reg_no', 'photo', 'level_id', 'school_id'])
            ->orderBy('surname')
            ->orderBy('firstname')
            ->get();

        return response()->json($students->map(fn (User $student) => [
            'id' => $student->id,
            'firstname' => $student->firstname,
            'surname' => $student->surname,
            'reg_no' => $student->reg_no,
            'photo' => $student->photo,
            'attendances' => $student->attendances->map(fn (Attendance $attendance) => [
                'status' => $attendance->status,
                'remarks' => $attendance->remarks,
            ])->values(),
        ])->values());
    }

    /**
     * Save/update attendance
     * - teacher: can only save for assigned class, and only for students in that class/school
     * - admin/proprietor/principal: can save for any class in school
     */
    public function store(Request $request)
    {
        $auth = Auth::user();
        $schoolId = $auth->school_id;
        $role = $auth->role;

        $isAdmin = $this->isAdminRole($role);
        $isTeacher = $this->isTeacherRole($role);

        if (!$isAdmin && !$isTeacher) {
            return response()->json(['message' => 'Unauthorized to mark attendance.'], 403);
        }

        $validated = $request->validate([
            'class_id' => 'required|integer|exists:student_classes,id',
            'date' => 'required|date',
            'week_number' => 'required|integer|min:1',
            'students' => 'required|array',
            'students.*.student_id' => 'required|integer|exists:users,id',
            'students.*.status' => 'required|in:present,absent,late,excused',
            'students.*.remarks' => 'nullable|string',
        ]);

        $classId = (int) $validated['class_id'];
        $this->validateAcademicWeek($schoolId, $validated['date'], (int) $validated['week_number']);

        // verify class belongs to this school and is not archived
        $classExistsInSchool = StudentClass::where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->where('id', $classId)
            ->exists();

        if (!$classExistsInSchool) {
            return response()->json(['message' => 'Class not found for your school.'], 404);
        }

        // teacher authorization
        if ($isTeacher && !$isAdmin) {
            $enrolledLevelIds = TeacherEnrollment::where('user_id', $auth->id)
                ->where('school_id', $schoolId)
                ->where(function ($q) {
                    $q->where('enroll', '1')->orWhere('enroll', 1);
                })
                ->pluck('level_id')
                ->filter()
                ->unique()
                ->values()
                ->all();

            $subjectLevelIds = DB::table('teacher_subjects')
                ->join('subjects', 'subjects.id', '=', 'teacher_subjects.subject_id')
                ->where('teacher_subjects.teacher_id', $auth->id)
                ->whereNotNull('subjects.class_id')
                ->pluck('subjects.class_id')
                ->filter()
                ->unique()
                ->values()
                ->all();

            $mergedLevelIds = array_values(array_unique(array_filter(array_merge($enrolledLevelIds, $subjectLevelIds))));

            if (!empty($mergedLevelIds) && !in_array($classId, array_map('intval', $mergedLevelIds), true)) {
                return response()->json(['message' => 'You are not assigned to this class.'], 403);
            }
        }

        // Build a whitelist of valid active student IDs in this school+class
        $validStudentIds = User::withRole('student')
            ->where('school_id', $schoolId)
            ->where('level_id', $classId)
            ->pluck('id')
            ->map(fn($v) => (int)$v)
            ->toArray();

        $validSet = array_flip($validStudentIds);

        foreach ($validated['students'] as $student) {
            if (! isset($validSet[(int) $student['student_id']])) {
                return response()->json([
                    'message' => 'Invalid student for this class.',
                    'student_id' => (int) $student['student_id'],
                ], 422);
            }
        }

        DB::transaction(function () use ($validated, $classId, $schoolId): void {
            foreach ($validated['students'] as $stu) {
                $sid = (int) $stu['student_id'];
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

            DB::afterCommit(fn () => AttendanceMarked::dispatch(
                (int) $schoolId,
                $classId,
                $validated['date'],
                collect($validated['students'])->mapWithKeys(
                    fn (array $student) => [(int) $student['student_id'] => $student['status']]
                )->all(),
            ));
        });

        return response()->json([
            'message' => 'Attendance saved successfully',
        ], 200);
    }

    private function validateAcademicWeek(int $schoolId, string $date, int $weekNumber): void
    {
        $context = $this->academicWeeks->contextForSchool($schoolId, $date);
        abort_unless($context['configured'], 422, 'Set the academic session start and end dates before marking attendance.');
        
        if (!isset($context['current_week']['number'])) {
            $matchingWeek = collect($context['weeks'])->firstWhere('number', $weekNumber);
            abort_unless($matchingWeek !== null, 422, 'The selected academic week does not exist for this session.');
            return;
        }

        abort_unless(($context['current_week']['number'] ?? null) === $weekNumber, 422, 'The selected date does not belong to the specified academic week.');
    }

    public function report(Request $request)
    {
        $validated = $request->validate([
            'class_id'  => 'required|integer|exists:student_classes,id',
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ]);

        $schoolId = Auth::user()->school_id;
        $classId = (int) $validated['class_id'];

        // restrict to school and unarchived
        $classExistsInSchool = StudentClass::where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->where('id', $classId)
            ->exists();

        if (!$classExistsInSchool) {
            return response()->json(['message' => 'Class not found for your school.'], 404);
        }

        $students = User::withRole('student')
            ->where('school_id', $schoolId)
            ->where('level_id', $classId)
            ->where('status', 1)
            ->where(function ($q) {
                $q->whereNull('student_status')->orWhere('student_status', 'active');
            })
            ->with(['attendances' => function ($q) use ($validated, $classId) {
                $q->where('class_id', $classId)
                  ->whereBetween('date', [$validated['start_date'], $validated['end_date']]);
            }])
            ->orderBy('surname')
            ->orderBy('firstname')
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
