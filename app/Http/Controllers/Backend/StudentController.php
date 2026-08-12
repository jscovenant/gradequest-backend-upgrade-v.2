<?php

namespace App\Http\Controllers\Backend;


use App\Models\User;

use App\Models\Average;
use App\Models\Section;

use App\Models\Subject;
use App\Models\Department;

use Illuminate\Http\Request;
use App\Models\SchoolSetting;

use App\Models\AcademicSession;
use App\Models\AffectiveDomain;


use App\Models\PsychomotorDomain;
use App\Models\TeacherEnrollment;

use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Models\StudentClass;
use App\Models\StudentResultV2;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

use App\Models\UserHasAffectiveDomain;
use App\Models\UserHasPsychomotorDomain;
use App\Services\SchoolBillingService;
use App\Services\Students\StudentExcelImportService;
use App\Services\Results\SubjectService;


use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Exceptions\SubscriptionLimitExceededException;


class StudentController extends Controller
{
    
   public function search(Request $request)
{
    $schoolId = Auth::user()->school_id;
    $query = $request->query('query');

    if (!$query) {
        return response()->json([]);
    }

    // Prefix search for faster lookup using indexes
    $students = User::where('school_id', $schoolId)
        ->where(function ($q) use ($query) {
            $q->where('firstname', 'like', "{$query}%")
              ->orWhere('surname', 'like', "{$query}%")
              ->orWhere('reg_no', 'like', "{$query}%");
        })
        ->select('id', 'firstname', 'surname', 'reg_no')
        ->limit(10)
        ->get();

    return response()->json($students);
}




// Fetch subjects offered by student's department, including general subjects.
    public function mySubjects()
    {
        $student = Auth::user();

        if (! $student) {
            return response()->json([
                'message' => 'Student not found.',
                'subjects' => [],
            ], 404);
        }

        $subjects = app(SubjectService::class)->subjectsForDepartment(
            (int) $student->school_id,
            $student->department_id ? (int) $student->department_id : null
        );

        if ($subjects->isEmpty()) {
            return response()->json([
                'message' => 'No subjects found for your department yet.',
                'subjects' => [],
            ]);
        }

        return response()->json([
            'message' => 'Subjects retrieved successfully.',
            'department' => $student->department->name ?? 'General',
            'subjects' => $subjects,
        ]);
    }






    public function AllStudents(Request $request)
{
    $user = Auth::user();
    $school_setting = SchoolSetting::first();
    $perPage = $request->get('perPage', 8);
    $page = $request->get('page', 1);
    $search = $request->input('search');
    $levelFilter = $request->input('level');
    $studentStatus = $request->input('student_status', 'active');

    // ========================
    // BASE STUDENT QUERY
    // ========================
    $studentsQuery = User::with('level')
        ->withRole('student')
        ->where('school_id', $user->school_id);

    if ($studentStatus === 'inactive') {
        $studentsQuery->where('status', 0);
    } elseif ($studentStatus !== 'all') {
        $studentsQuery->where('student_status', $studentStatus);
    }

    $levelsQuery = StudentClass::where('school_id', $user->school_id)
        ->whereNull('archived_at');

    // ========================
    // TEACHER VIEW
    // ========================
    if ($user->role == 'Teacher') {
        $enrolledLevels = TeacherEnrollment::where('user_id', $user->id)
            ->where('enroll', '1')
            ->where('school_id', $user->school_id)
            ->pluck('level_id');

        $studentsQuery->whereHas('level', function ($q) use ($enrolledLevels) {
            $q->whereIn('id', $enrolledLevels);
        });

        $levelsQuery->whereIn('id', $enrolledLevels);
    }

    // ========================
    // PARENT VIEW
    // ========================
    elseif ($user->role == 'Parent') {
        // Join with pivot table for children
        $studentsQuery->whereHas('parents', function ($q) use ($user) {
            $q->where('parent_id', $user->id);
        });
    }

    // ========================
    // SEARCH FILTER
    // ========================
    if ($search) {
        $studentsQuery->where(function ($q) use ($search) {
            $q->where('firstname', 'LIKE', "%$search%")
              ->orWhere('surname', 'LIKE', "%$search%")
              ->orWhere('reg_no', 'LIKE', "%$search%");
        });
    }

    // ========================
    // CLASS FILTER
    // ========================
    if ($levelFilter) {
        $studentsQuery->whereHas('level', function ($q) use ($levelFilter) {
            $q->where('name', $levelFilter);
        });
    }

    // ========================
    // PAGINATION
    // ========================
    $students = $studentsQuery->latest()->paginate($perPage, ['*'], 'page', $page);
    $levels = $levelsQuery->get();
    $statusCounts = User::withRole('student')
        ->where('school_id', $user->school_id)
        ->selectRaw('COALESCE(student_status, "active") as student_status, COUNT(*) as total')
        ->groupBy('student_status')
        ->pluck('total', 'student_status');

    return response()->json([
        'students' => $students,
        'school_setting' => $school_setting,
        'levels' => $levels,
        'status_counts' => [
            'active' => (int) ($statusCounts['active'] ?? 0),
            'alumni' => (int) ($statusCounts['alumni'] ?? 0),
            'graduate' => (int) ($statusCounts['graduate'] ?? 0),
            'withdrawn' => (int) ($statusCounts['withdrawn'] ?? 0),
            'inactive' => User::withRole('student')
                ->where('school_id', $user->school_id)
                ->where('status', 0)
                ->count(),
        ],
        'user_role' => $user->role
    ]);
}



    
    
    

    
     

   public function ViewStudent($id)
{
    $auth = Auth::user();

    $user = User::with(['level', 'department', 'section'])->find($id);

    if (!$user || $user->school_id !== $auth->school_id) {
        return response()->json(['message' => 'Student not found.'], 404);
    }

    $affectiveDomains = AffectiveDomain::all();
    $psychomotorDomains = PsychomotorDomain::all();

    $userHasAffectiveRatings = UserHasAffectiveDomain::where('user_id', $user->id)
        ->where('school_id', $auth->school_id)
        ->get()
        ->keyBy('affective_id');

    $userPsychomotorRatings = UserHasPsychomotorDomain::where('user_id', $user->id)
        ->where('school_id', $auth->school_id)
        ->get()
        ->keyBy('psychomotor_id');

    $sessions = AcademicSession::where('school_id', $auth->school_id)->get();

    // ðŸ”¹ Add available levels and departments
    $levels = StudentClass::where('school_id', $auth->school_id)->whereNull('archived_at')->get();
    $departments = Department::where('school_id', $auth->school_id)->whereNull('archived_at')->get();
    $sections = Section::where('school_id', $auth->school_id)->whereNull('archived_at')->get();

    return response()->json([
        'student' => $user,
        'sessions' => $sessions,
        'affectiveDomains' => $affectiveDomains,
        'psychomotorDomains' => $psychomotorDomains,
        'affectiveRatings' => $userHasAffectiveRatings,
        'psychomotorRatings' => $userPsychomotorRatings,
        'levels' => $levels,
        'departments' => $departments,
        'sections' => $sections,
    ]);
}




public function Section(): JsonResponse
{
    $auth = Auth::user();

    // Fetch all sections belonging to the authenticated user's school
    $sections = Section::select('id', 'name')
        ->where('school_id', $auth->school_id)
        ->whereNull('archived_at')
        ->get();

    return response()->json($sections);
}

    
    
  public function Level(): JsonResponse
    {
        $auth = Auth::user();
        $levels = StudentClass::select('id', 'name')->where('school_id', $auth->school_id)->whereNull('archived_at')->get();
        return response()->json($levels);
    }
    
 

    public function Department(): JsonResponse
    {
        $auth = Auth::user();
        $departments = Department::select('id', 'name')
        ->where('school_id', $auth->school_id)
        ->whereNull('archived_at')
        ->get();
        return response()->json($departments);
    }

  
    
public function storeAllStudent(Request $request)
{
    $auth = Auth::user();

    
    $schoolSetting = SchoolSetting::where('id', $auth->school_id)->firstOrFail();

   

    
if (strtolower((string) $request->input('role')) === 'student') {
    try {
        $auth->assertCanAddStudents();
    } catch (SubscriptionLimitExceededException $e) {
        return response()->json([
            'message' => $e->getMessage(),
        ], 403);
    }
}

    $request->validate([
        'firstname'      => 'required|string',
        'surname'        => 'required|string',
        'third_name'     => 'required|string',
        'photo'          => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        'role'           => 'required|string|max:255',
        'gender'         => 'required|in:Male,Female',
        'blood_group'   => 'nullable|in:A+,A-,B+,B-,O+,O-',
        'religion'      => 'nullable|string|max:255',
        'nationality'   => 'nullable|string|max:255',
        'level_id'       => 'required|integer',
        'section_id'     => 'required|integer',
        'department_id'  => 'required|integer',
        'reg_no'         => [
            Rule::requiredIf($schoolSetting->auto_admission == 0),
            'nullable',
            'string',
            'max:255',
            Rule::unique('users', 'reg_no'),
        ],
    ]);

    // âœ… Step 3: Generate or accept admission number
    if ($schoolSetting->auto_admission == 1) {
        do {
            $regNo = random_int(100000, 999999);
            $finalRegNo = "{$schoolSetting->prefix}{$regNo}";
        } while (User::where('reg_no', $finalRegNo)->exists());
    } else {
        $finalRegNo = strtoupper(trim($request->reg_no));

        if (User::where('reg_no', $finalRegNo)->exists()) {
            return response()->json([
                'message' => "Admission number '{$finalRegNo}' already exists.",
            ], 409);
        }
    }

    // âœ… Step 4: Prevent duplicate student names in same school
    $existingUser = User::where([
            'firstname'  => $request->firstname,
            'surname'    => $request->surname,
            'third_name' => $request->third_name,
            'school_id'  => $auth->school_id,
        ])->first();

    if ($existingUser) {
        return response()->json([
            'message' => 'A student with the same full name already exists.',
        ], 409);
    }

    // âœ… Step 5: Create student
    $randomPassword = Str::random(8);

    $user = new User();
    $user->firstname         = $request->firstname;
    $user->surname           = $request->surname;
    $user->third_name        = $request->third_name;
    $user->username          = $finalRegNo;
    $user->reg_no            = $finalRegNo;
    $user->dob               = $request->dob;
    $user->address           = $request->address;
    $user->level_id          = $request->level_id;
    $user->section_id        = $request->section_id;
    $user->department_id     = $request->department_id;
    $user->blood_group       = $request->blood_group;
    $user->religion          = $request->religion;
    $user->nationality       = $request->nationality;
    $user->password          = Hash::make($randomPassword);
    $user->default_password  = $randomPassword;
    $user->sex               = $request->gender;
    $user->role              = $request->role;
    $user->school_id         = $auth->school_id;
    $user->phone             = $request->phone;
    $user->status            = 1;
    $user->student_status    = 'active';

    // âœ… Step 6: Upload photo (if provided)
    if ($request->hasFile('photo') && $request->file('photo')->isValid()) {
        $file     = $request->file('photo');
        $fileName = now()->format('ymdHi') . '_' . $file->getClientOriginalName();
        $file->move(public_path('uploads/users'), $fileName);
        $user->photo = $fileName;
    }

    $user->save();
    $user->assignRole($request->role);

    return response()->json([
        'message' => 'Student registered successfully',
        'reg_no'  => $user->reg_no,
    ], 201);
}






    public function EditStudents($id)
    {
        $auth = Auth::user();

        $student = User::with('department')
            ->forSchool($auth->school_id)
            ->withRole('student')
            ->findOrFail($id);
    
        return response()->json([
            'id' => $student->id,
            'firstname' => $student->firstname,
            'surname' => $student->surname,
            'third_name' => $student->third_name,
            'sex' => $student->sex,
            'dob' => $student->dob,
            'address' => $student->address,
            'phone' => $student->phone,
            'email' => $student->email,
            'level_id' => $student->level_id,
            'section_id' => $student->section_id,
            'department_id' => $student->department_id,
            'photo' => $student->photo,
            'username' => $student->username,
            'reg_no' => $student->reg_no, 
        ]);
    }
    


    public function UpdateStudent(Request $request, $id)
    {
        $request->validate([
            'firstname' => 'required|string',
            'surname' => 'required|string',
            'third_name' => 'nullable|string',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'sex' => 'required|in:Male,Female',
            'level_id' => 'required|integer',
            'blood_group' => 'nullable|string',
            'religion' => 'nullable|string',
            'nationality' => 'nullable|string',
            'section_id' => 'required|integer',
            'department_id' => 'required|integer',
            'email' => 'nullable|email',
            'reg_no' => 'nullable|string', 
        ]);
    
        $auth = Auth::user();

        $student = User::forSchool($auth->school_id)
            ->withRole('student')
            ->findOrFail($id);
    
        $student->firstname = $request->firstname;
        $student->surname = $request->surname;
        $student->third_name = $request->third_name;
        $student->username = $request->username ?? $student->username;
        $student->dob = $request->dob;
        $student->address = $request->address;
        $student->email = $request->email;
        $student->sex = $request->sex;
         $student->blood_group       = $request->blood_group;
        $student->religion          = $request->religion;
        $student->nationality       = $request->nationality;
        $student->level_id = $request->level_id;
        $student->section_id = $request->section_id;
        $student->department_id = $request->department_id; 
        $student->phone = $request->phone;
        $student->status = "1";
        $student->student_status = $request->student_status ?? $student->student_status ?? 'active';
    
        // âœ… Only update admission number if it was filled
        if ($request->filled('admission_no')) {
            $student->reg_no = $request->admission_no;
        }
    
        if ($request->filled('password')) {
            $student->password = Hash::make($request->password);
            $student->default_password = $request->password;
        }
    
        if ($request->hasFile('photo') && $request->file('photo')->isValid()) {
            if ($student->photo && file_exists(public_path('uploads/users/' . $student->photo))) {
                @unlink(public_path('uploads/users/' . $student->photo));
            }
    
            $file = $request->file('photo');
            $fileName = date('ymdHi') . '_' . $file->getClientOriginalName();
            $file->move(public_path('uploads/users/'), $fileName);
            $student->photo = $fileName;
        }
    
        $student->save();
    
        return response()->json([
            'message' => 'Student record updated successfully',
            'data' => $student,
        ], 200);
    }
    


    public function getStudentPerformance($id)
    {
        $auth = Auth::user();

        $student = User::forSchool($auth->school_id)
            ->withRole('student')
            ->find($id);

        if (! $student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        // Get latest session for this student
        $latestSession = Average::where('user_id', $id)
            ->orderBy('session', 'desc')
            ->value('session');
    
        if (!$latestSession) {
            return response()->json([
                'averages' => [],
                'session' => null
            ]);
        }
    
        // Get averages for that session across all terms
        $averages = Average::where('user_id', $id)
            ->where('session', $latestSession)
            ->orderByRaw("FIELD(term, 'First Term', 'Second Term', 'Third Term')")
            ->get(['term', 'total_average']);
    
        $chartData = $averages->map(function ($avg) {
            return [
                'term' => $avg->term,
                'total_average' => (float) $avg->total_average,
            ];
        });
    
        return response()->json([
            'session' => $latestSession,
            'averages' => $chartData,
        ]);
    }
    
    






  public function DeleteStudent($id)
{
    try {
        $admin = auth::user();

        $user = User::where('id', $id)
                    ->where('school_id', $admin->school_id) // scoped to same school
                    ->first();

        if (!$user) {
            return response()->json([
                'message' => 'Student not found.',
                'status'  => 'error',
            ], 404);
        }

        // Only students can be withdrawn through this endpoint
        if (strtolower(trim((string) $user->role)) !== 'student') {
            return response()->json([
                'message' => 'Only student accounts can be withdrawn.',
                'status'  => 'error',
            ], 403);
        }

        DB::transaction(function () use ($user, $admin) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->update([
                'status' => 0,
                'student_status' => 'withdrawn',
                'student_status_changed_at' => now(),
                'student_status_changed_by' => $admin->id,
            ]);

            $locked->tokens()->delete();
        });

        return response()->json([
            'message' => 'Student withdrawn successfully. Their account is inactive and historical records were preserved.',
            'status'  => 'success',
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'message' => 'An error occurred: ' . $e->getMessage(),
            'status'  => 'error',
        ], 500);
    }
}

public function destroyStudent(Request $request, $id)
{
    $validated = $request->validate([
        'confirmation' => ['required', Rule::in(['DELETE'])],
    ]);

    $admin = Auth::user();
    $student = User::forSchool($admin->school_id)->withRole('student')->findOrFail($id);

    $dependencies = [
        'student_results_v2' => 'user_id',
        'averages' => 'user_id',
        'attendances' => 'student_id',
        'student_fees' => 'student_id',
        'parent_students' => 'student_id',
        'cbt_attempts' => 'student_id',
        'user_has_affective_domains' => 'user_id',
        'user_has_psychomotor_domains' => 'user_id',
    ];

    $history = collect($dependencies)
        ->filter(fn (string $column, string $table) => Schema::hasTable($table)
            && Schema::hasColumn($table, $column)
            && DB::table($table)->where($column, $student->id)->exists())
        ->keys()
        ->values();

    if ($history->isNotEmpty()) {
        return response()->json([
            'message' => 'This student has historical records and cannot be deleted. Withdraw the student instead.',
            'blocking_records' => $history,
        ], 409);
    }

    DB::transaction(function () use ($student): void {
        $locked = User::whereKey($student->id)->lockForUpdate()->firstOrFail();
        $locked->tokens()->delete();
        $locked->delete();
    });

    return response()->json([
        'message' => 'Erroneous student account deleted permanently.',
        'status' => 'success',
    ]);
}

public function studentImportTemplate(Request $request, StudentExcelImportService $service)
{
    $format = strtolower((string) $request->query('format', 'xlsx'));

    return $service->template((int) Auth::user()->school_id, in_array($format, ['xlsx', 'xls', 'csv'], true) ? $format : 'xlsx');
}

public function previewStudentImport(Request $request, StudentExcelImportService $service)
{
    $request->validate([
        'file' => 'required|file|max:10240',
    ]);

    try {
        return response()->json($service->preview(Auth::user(), $request->file('file')));
    } catch (\InvalidArgumentException $e) {
        return response()->json(['message' => $e->getMessage()], 422);
    }
}

public function importStudents(Request $request, StudentExcelImportService $service)
{
    $request->validate([
        'file' => 'required|file|max:10240',
    ]);

    try {
        $result = $service->import(Auth::user(), $request->file('file'));

        return response()->json($result, ($result['imported'] ?? 0) > 0 ? 201 : 422);
    } catch (SubscriptionLimitExceededException $e) {
        return response()->json(['message' => $e->getMessage()], 403);
    } catch (\InvalidArgumentException $e) {
        return response()->json(['message' => $e->getMessage()], 422);
    }
}

public function updateStudentLifecycleStatus(Request $request, $id)
{
    $validated = $request->validate([
        'student_status' => ['required', Rule::in(['active', 'alumni', 'graduate'])],
    ]);

    $auth = Auth::user();
    $student = User::forSchool($auth->school_id)
        ->withRole('student')
        ->findOrFail($id);

    if ($validated['student_status'] === 'active') {
        try {
            $auth->assertCanAddStudents();
        } catch (SubscriptionLimitExceededException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }

    $student->forceFill([
        'student_status' => $validated['student_status'],
        'status' => $validated['student_status'] === 'active' ? 1 : 0,
        'student_status_changed_at' => now(),
        'student_status_changed_by' => $auth->id,
    ])->save();

    return response()->json([
        'message' => match ($validated['student_status']) {
            'alumni' => 'Student marked as alumni successfully.',
            'graduate' => 'Student marked as graduate successfully.',
            default => 'Student restored to active students successfully.',
        },
        'student' => $student->fresh(['level', 'department', 'section']),
    ]);
}



  public function getClasses()
{
    $user = Auth::user();
    $schoolId = $user->school_id;

    $allClasses = StudentClass::where('school_id', $schoolId)
        ->whereNull('archived_at')
        ->select('id', 'name')
        ->orderBy('name')
        ->get();

    // Admin can promote from any class
    if (strtolower($user->role) === 'admin') {
        return response()->json([
            'user_role' => $user->role,
            'from_classes' => $allClasses,
            'all_classes' => $allClasses,
        ]);
    }

    // Teacher: only assigned classes as FROM
    if (strtolower($user->role) === 'teacher') {
        $enrolledLevelIds = TeacherEnrollment::where('user_id', $user->id)
            ->where('school_id', $schoolId)
            ->where('enroll', '1')
            ->pluck('level_id');

        $fromClasses = StudentClass::where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->whereIn('id', $enrolledLevelIds)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return response()->json([
            'user_role' => $user->role,
            'from_classes' => $fromClasses,
            'all_classes' => $allClasses, // Teacher can promote TO any class
        ]);
    }

    // Default fallback (optional)
    return response()->json([
        'user_role' => $user->role,
        'from_classes' => [],
        'all_classes' => $allClasses,
    ]);
}



public function getStudentsByClass(Request $request)
{
    $user = Auth::user();
    $schoolId = $user->school_id;

    $request->validate([
        'class_id' => 'required|integer|exists:student_classes,id',
    ]);

    $classId = (int) $request->class_id;

    // TEACHER restriction: class_id must be among enrolled classes
    if (strtolower($user->role) === 'teacher') {
        $allowed = TeacherEnrollment::where('user_id', $user->id)
            ->where('school_id', $schoolId)
            ->where('enroll', '1')
            ->where('level_id', $classId)
            ->exists();

        if (!$allowed) {
            return response()->json([
                'message' => 'You are not allowed to load students from this class.',
            ], 403);
        }
    }

    // Admin is allowed for all classes in same school
    $students = User::where('school_id', $schoolId)
        ->withRole('student')
        ->where('level_id', $classId)
        ->with('level')
        ->get()
        ->map(function ($s) {
            return [
                'id' => $s->id,
                'reg_no' => $s->reg_no,
                'firstname' => $s->firstname,
                'surname' => $s->surname,
                'class_name' => $s->level->name ?? '',
            ];
        });

    return response()->json($students);
}



public function promoteStudents(Request $request)
{
    $user = Auth::user();
    $schoolId = $user->school_id;

    $request->validate([
        'from_class' => 'required|integer|exists:student_classes,id',
        'to_class' => 'required|integer|exists:student_classes,id|different:from_class',
        'student_ids' => 'required|array|min:1',
        'student_ids.*' => 'required|integer|exists:users,id',
    ]);

    $fromClass = (int) $request->from_class;
    $toClass   = (int) $request->to_class;
    $studentIds = $request->student_ids;

    // TEACHER restriction: must be assigned to from_class
    if (strtolower($user->role) === 'teacher') {
        $allowed = TeacherEnrollment::where('user_id', $user->id)
            ->where('school_id', $schoolId)
            ->where('enroll', '1')
            ->where('level_id', $fromClass)
            ->exists();

        if (!$allowed) {
            return response()->json([
                'message' => 'You are not allowed to promote students from this class.',
            ], 403);
        }
    }

    // Safety: ensure students belong to this school + are in from_class
    $validCount = User::where('school_id', $schoolId)
        ->withRole('student')
        ->where('level_id', $fromClass)
        ->whereIn('id', $studentIds)
        ->count();

    if ($validCount !== count($studentIds)) {
        return response()->json([
            'message' => 'Some selected students are not in the selected From Class (or not in your school).',
        ], 422);
    }

    $billing = app(SchoolBillingService::class);
    $blockedStudents = User::where('school_id', $schoolId)
        ->withRole('student')
        ->where('level_id', $fromClass)
        ->whereIn('id', $studentIds)
        ->get(['id', 'firstname', 'surname', 'reg_no'])
        ->map(function (User $student) use ($billing, $schoolId) {
            $status = $billing->studentAcademicClearanceStatus((int) $schoolId, (int) $student->id);

            if ($status['allowed']) {
                return null;
            }

            return [
                'id' => $student->id,
                'name' => trim($student->firstname . ' ' . $student->surname),
                'reg_no' => $student->reg_no,
                'blocked_terms_count' => $status['blocked_terms_count'],
                'blocked_terms' => $status['blocked_terms'],
            ];
        })
        ->filter()
        ->values();

    if ($blockedStudents->isNotEmpty()) {
        return response()->json([
            'message' => 'Access denied. Please settle all outstanding fees for the selected student(s) before promotion.',
            'reason' => 'student_outstanding_billing_required',
            'blocked_students' => $blockedStudents,
        ], 402);
    }

    User::where('school_id', $schoolId)
        ->withRole('student')
        ->where('level_id', $fromClass)
        ->whereIn('id', $studentIds)
        ->update(['level_id' => $toClass]);

    return response()->json(['message' => 'Students promoted successfully']);
}


//method for affective and psychomotor domain

    public function saveRatings(Request $request)
    {
        $auth = Auth::user();

        $request->validate([
            'user_id' => 'required|integer',
            'school_id' => 'required|integer|in:' . $auth->school_id,
            'affective' => 'nullable|array',
            'affective.*.id' => 'required|integer',
            'affective.*.rate' => 'required|integer|min:1|max:4',
            'psychomotor' => 'nullable|array',
            'psychomotor.*.id' => 'required|integer',
            'psychomotor.*.rate' => 'required|integer|min:1|max:4',
        ]);

        $studentExists = User::forSchool($auth->school_id)
            ->withRole('student')
            ->where('id', $request->user_id)
            ->exists();

        if (! $studentExists) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        // Save Affective ratings
        if (!empty($request->affective)) {
            foreach ($request->affective as $item) {
                UserHasAffectiveDomain::updateOrCreate(
                    [
                        'user_id' => $request->user_id,
                        'school_id' => $request->school_id,
                        'affective_id' => $item['id'],
                    ],
                    ['rate' => $item['rate']]
                );
            }
        }

        // Save Psychomotor ratings
        if (!empty($request->psychomotor)) {
            foreach ($request->psychomotor as $item) {
                UserHasPsychomotorDomain::updateOrCreate(
                    [
                        'user_id' => $request->user_id,
                        'school_id' => $request->school_id,
                        'psychomotor_id' => $item['id'],
                    ],
                    ['rate' => $item['rate']]
                );
            }
        }

        return response()->json(['message' => 'Ratings saved successfully'], 200);
    }

  
/**
     * Decrypt and return the student's default password
     *
     
     */
  public function decryptPassword(Request $request)
{
    $request->validate([
        'user_id' => 'required|integer|exists:users,id',
    ]);

    $auth = Auth::user();

    $student = User::forSchool($auth->school_id)
        ->withRole('student')
        ->findOrFail($request->user_id);

    if (!$student->default_password) {
        return response()->json([
            'success' => false,
            'message' => 'No default password found for this student',
        ], 404);
    }

    try {
        $decryptedPassword = $student->default_password;

        return response()->json([
            'success' => true,
            'message' => 'Password decrypted successfully',
            'decrypted_password' => $decryptedPassword,
        ], 200);

    } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
        Log::error('Password decryption failed', [
            'user_id' => $student->id,
            'error' => $e->getMessage()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failed to decrypt password. The password may be corrupted.',
        ], 500);
    }
}





}

