<?php

namespace App\Http\Controllers\Backend;

use App\Models\Quiz;
use App\Models\User;
use App\Models\Level;
use App\Models\Average;
use App\Models\Section;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Department;
use App\Exports\UsersExport;
use Illuminate\Http\Request;
use App\Models\SchoolSetting;
use App\Models\SubjectEnroll;
use App\Exports\StudentExport;
use App\Models\AcademicSession;
use App\Models\AffectiveDomain;
use App\Models\FirstTermResult;
use App\Models\ThirdTermResult;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\SecondTermResult;

use App\Models\PsychomotorDomain;
use App\Models\TeacherEnrollment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Models\StudentClass;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Auth\Events\Validated;
use App\Models\UserHasAffectiveDomain;
use App\Models\UserHasPsychomotorDomain;
use App\Models\StudentFee;
use App\Models\FeeType;
use Illuminate\Contracts\Encryption\DecryptException;
   
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class StudentController extends Controller
{
    
    public function search(Request $request)
    {
        $schoolId = Auth::user()->school_id;
        $query = $request->query('query');

        if (!$query) {
            return response()->json([]);
        }

        $students = User::where('school_id', $schoolId)
            ->where(function ($q) use ($query) {
                $q->where('firstname', 'like', "%{$query}%")
                  ->orWhere('surname', 'like', "%{$query}%")
                  ->orWhere('reg_no', 'like', "%{$query}%");
            })
            ->select('id', 'firstname', 'surname', 'reg_no')
            ->limit(10)
            ->get();

        return response()->json($students);
    }


// ✅ Fetch subjects offered by student's department
    public function mySubjects()
    {
        $student = Auth::user();

        // Ensure the user is a student and has a department
        if (!$student || !$student->department_id) {
            return response()->json([
                'message' => 'Student department not found.'
            ], 404);
        }

        // ✅ Get subjects linked to this department
        $subjects = Subject::where('department_id', $student->department_id)
            ->where('school_id', $student->school_id)
            ->select('id', 'name', 'subject_id')
            ->get();

        if ($subjects->isEmpty()) {
            return response()->json([
                'message' => 'No subjects found for your department.',
                'subjects' => [],
            ]);
        }

        return response()->json([
            'message' => 'Subjects retrieved successfully.',
            'department' => $student->department->name ?? null,
            'subjects' => $subjects,
        ]);
    }





public function AllStudents(Request $request)
{
    $user = Auth::user();
    $school_setting = SchoolSetting::first();
    $perPage = $request->get('perPage', 8);
    $page = $request->get('page', 1);

    // ========================
    // TEACHER VIEW
    // ========================
    if ($user->role == 'Teacher') {
        $enrolledLevels = TeacherEnrollment::where('user_id', $user->id)
            ->where('enroll', '1')
            ->where('school_id', $user->school_id)
            ->pluck('level_id');

        $studentsQuery = User::with('level')
            ->where('role', 'student')
            ->where('school_id', $user->school_id)
            ->whereHas('level', function ($query) use ($enrolledLevels) {
                $query->whereIn('id', $enrolledLevels);
            });

        $levels = StudentClass::whereIn('id', $enrolledLevels)
            ->where('school_id', $user->school_id)
            ->get();
    }

    // ========================
    // ADMIN VIEW
    // ========================
    elseif ($user->role == 'Admin') {
        $studentsQuery = User::with('level')
            ->where('role', 'student')
            ->where('school_id', $user->school_id);

        $levels = StudentClass::where('school_id', $user->school_id)->get();
    }

    // ========================
    // PARENT VIEW (using belongsToMany)
    // ========================
    elseif ($user->role == 'Parent') {
        $children = $user->children()->with('level')->get();

        // Optional filters (search and class)
        if ($request->filled('search')) {
            $search = $request->input('search');
            $children = $children->filter(function ($child) use ($search) {
                return str_contains(strtolower($child->firstname), strtolower($search)) ||
                       str_contains(strtolower($child->surname), strtolower($search)) ||
                       str_contains(strtolower($child->reg_no), strtolower($search));
            });
        }

        if ($request->filled('level')) {
            $children = $children->filter(function ($child) use ($request) {
                return optional($child->level)->name === $request->level;
            });
        }

        // Manual pagination for Collection
        $total = $children->count();
        $students = $children->slice(($page - 1) * $perPage, $perPage)->values();
        $levels = StudentClass::where('school_id', $user->school_id)->get();

        return response()->json([
            'students' => [
                'data' => $students,
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => ceil($total / $perPage),
            ],
            'school_setting' => $school_setting,
            'levels' => $levels,
            'user_role' => $user->role
        ]);
    }

    // ========================
    // INVALID ROLE
    // ========================
    else {
        return response()->json([
            'message' => 'Unauthorized access',
        ], 403);
    }

    // ========================
    // SEARCH FILTER
    // ========================
    if ($request->filled('search')) {
        $search = $request->input('search');
        $studentsQuery->where(function ($q) use ($search) {
            $q->where('firstname', 'LIKE', "%$search%")
              ->orWhere('surname', 'LIKE', "%$search%")
              ->orWhere('reg_no', 'LIKE', "%$search%");
        });
    }

    // ========================
    // CLASS FILTER
    // ========================
    if ($request->filled('level')) {
        $studentsQuery->whereHas('level', function ($q) use ($request) {
            $q->where('name', $request->level);
        });
    }

    // ========================
    // PAGINATION
    // ========================
    $students = $studentsQuery->latest()->paginate($perPage, ['*'], 'page', $page);

    return response()->json([
        'students' => $students,
        'school_setting' => $school_setting,
        'levels' => $levels,
        'user_role' => $user->role
    ]);
}


    
    
    

    
     

    public function ViewStudent($id)
    {
        $auth = Auth::user();
    
        // Fetch the student with department and level
        $user = User::with(['level', 'department'])->find($id);
    
        if (!$user || $user->school_id !== $auth->school_id) {
            return response()->json(['message' => 'Student not found.'], 404);
        }
    
        // Attempt to decrypt the default password
        try {
            $user->decrypted_password = decrypt($user->default_password);
        } catch (DecryptException $e) {
            $user->decrypted_password = null; // Gracefully fallback if decryption fails
        }
    
        // Fetch affective and psychomotor domains
        $affectiveDomains = AffectiveDomain::all();
        $psychomotorDomains = PsychomotorDomain::all();
    
        // Get student-specific ratings
        $userHasAffectiveRatings = UserHasAffectiveDomain::where('user_id', $user->id)
            ->where('school_id', $auth->school_id)
            ->get()
            ->keyBy('affective_id');
    
        $userPsychomotorRatings = UserHasPsychomotorDomain::where('user_id', $user->id)
            ->where('school_id', $auth->school_id)
            ->get()
            ->keyBy('psychomotor_id');
    
        $sessions = AcademicSession::where('school_id', $auth->school_id)->get();
    
        return response()->json([
            'student' => $user,
            'sessions' => $sessions,
            'affectiveDomains' => $affectiveDomains,
            'psychomotorDomains' => $psychomotorDomains,
            'affectiveRatings' => $userHasAffectiveRatings,
            'psychomotorRatings' => $userPsychomotorRatings,
        ]);
    }
    
    
  public function Level(): JsonResponse
    {
        $auth = Auth::user();
        $levels = StudentClass::select('id', 'name')->where('school_id', $auth->school_id)->get();
        return response()->json($levels);
    }
    
 

    public function Department(): JsonResponse
    {
        $auth = Auth::user();
        $departments = Department::select('id', 'name')
        ->where('school_id', $auth->school_id)->get();
        return response()->json($departments);
    }

  
    
    public function StoreAllStudent(Request $request)
    {
        $auth = Auth::user();
    
        // ✅ Step 1: Fetch school setting BEFORE validation
        $school_setting = SchoolSetting::where('id', $auth->school_id)->firstOrFail();
    
        // ✅ Step 2: Now you can safely use the setting in validation
        $request->validate([
            'firstname' => 'required|string',
            'surname' => 'required|string',
            'third_name' => 'required|string',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'role' => 'required|string|max:255',
            'gender' => 'required|in:Male,Female',
            'level_id' => 'required|integer',
            'section_id' => 'required|integer',
            'department_id' => 'required|integer',
          'reg_no' => [
        Rule::requiredIf($school_setting->auto_admission == 0), 
        'nullable',
        'string',
        'max:255',
        Rule::unique('users', 'reg_no'),
    ],
        ]);
    
        $user = new User();
    
       // ✅ Step 3: Decide how to assign reg_no
if ($school_setting->auto_admission == 1) {
    // Auto-generate admission number
    do {
        $reg_no = random_int(100000, 999999);
        $final_reg_no = "{$school_setting->prefix}{$reg_no}";
    } while (User::where('reg_no', $final_reg_no)->exists());
} else {
    // Manually provided by user
    $final_reg_no = strtoupper(trim($request->reg_no));

    if (User::where('reg_no', $final_reg_no)->exists()) {
        return response()->json([
            'message' => "Admission number '$final_reg_no' already exists.",
        ], 409);
    }
}
    
        // ✅ Step 4: Check for duplicate student name
        $existingUser = User::where('firstname', $request->firstname)
            ->where('surname', $request->surname)
            ->where('third_name', $request->third_name)
            ->where('school_id', $auth->school_id)
            ->first();
    
        if ($existingUser) {
            return response()->json([
                'message' => 'A student with the same full name already exists.',
            ], 409);
        }
    
        // ✅ Step 5: Create user
        $randomPassword = Str::random(8);
    
        $user->firstname = $request->firstname;
        $user->surname = $request->surname;
        $user->third_name = $request->third_name;
        $user->username = $final_reg_no;
        $user->reg_no = $final_reg_no;
        $user->dob = $request->dob;
        $user->address = $request->address;
        $user->level_id = $request->level_id;
        $user->section_id = $request->section_id;
        $user->department_id = $request->department_id;
        $user->password = Hash::make($randomPassword);
        $user->default_password = encrypt($randomPassword);
        $user->sex = $request->gender;
        $user->role = $request->role;
        $user->school_id = $auth->school_id;
        $user->phone = $request->phone;
        $user->status = "1";
    
        if ($request->hasFile('photo') && $request->file('photo')->isValid()) {
            $file = $request->file('photo');
            $fileName = date('ymdHi') . $file->getClientOriginalName();
            $file->move(public_path('uploads/users'), $fileName);
            $user->photo = $fileName;
        }
    
        $user->save();
        $user->assignRole($request->role);
    
        return response()->json([
            'message' => 'Student registered successfully',
            'reg_no' => $user->reg_no,
        ], 201);
    }
    





    public function EditStudents($id)
    {
        $student = User::with('department')->findOrFail($id);
    
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
            'gender' => 'required|in:Male,Female',
            'level_id' => 'required|integer',
            'section_id' => 'required|integer',
            'department_id' => 'required|integer',
            'email' => 'nullable|email',
            'reg_no' => 'nullable|string', 
        ]);
    
        $student = User::findOrFail($id);
    
        $student->firstname = $request->firstname;
        $student->surname = $request->surname;
        $student->third_name = $request->third_name;
        $student->username = $request->username ?? $student->username;
        $student->dob = $request->dob;
        $student->address = $request->address;
        $student->email = $request->email;
        $student->sex = $request->gender;
        $student->level_id = $request->level_id;
        $student->section_id = $request->section_id;
        $student->department_id = $request->department_id; 
        $student->phone = $request->phone;
        $student->status = "1";
    
        // ✅ Only update admission number if it was filled
        if ($request->filled('admission_no')) {
            $student->reg_no = $request->admission_no;
        }
    
        if ($request->filled('password')) {
            $student->password = Hash::make($request->password);
            $student->default_password = encrypt($request->password);
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
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'message' => 'User not found',
                    'status' => 'error',
                ], 404);
            }

            if ($user->role === 'Admin') {
                return response()->json([
                    'message' => 'User cannot be deleted',
                    'status' => 'error',
                ], 403);
            }

            if (Average::where('user_id', $user->id)->exists()) {
                return response()->json([
                    'message' => 'Student whose result is active cannot be deleted',
                    'status' => 'error',
                ], 409);
            }

            $user->delete();

            return response()->json([
                'message' => 'User record deleted successfully',
                'status' => 'success',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'An error occurred: ' . $e->getMessage(),
                'status' => 'error',
            ], 500);
        }
    }



    public function getClasses()
    {
        $schoolId = Auth::user()->school_id;
        return StudentClass::where('school_id', $schoolId)->get();
    }

public function getStudentsByClass(Request $request)
{
    $schoolId = Auth::user()->school_id;

    $request->validate([
        'class_id' => 'required|integer',
    ]);

    $students = User::where('school_id', $schoolId)
        ->where('role', 'student')
        ->where('level_id', $request->class_id)
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
    $request->validate([
        'from_class' => 'required|integer',
        'to_class' => 'required|integer',
        'student_ids' => 'required|array',
    ]);

    User::whereIn('id', $request->student_ids)
        ->update([
            'level_id' => $request->to_class,
        ]);

    return response()->json(['message' => 'Students promoted successfully']);
}


//method for affective and psychomotor domain

    public function saveRatings(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer',
            'school_id' => 'required|integer',
            'affective' => 'nullable|array',
            'affective.*.id' => 'required|integer',
            'affective.*.rate' => 'required|integer|min:1|max:4',
            'psychomotor' => 'nullable|array',
            'psychomotor.*.id' => 'required|integer',
            'psychomotor.*.rate' => 'required|integer|min:1|max:4',
        ]);

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

  
  





}
