<?php

namespace App\Http\Controllers\Backend;

use App\Models\User;
use App\Models\Level;

use App\Models\Section;

use Illuminate\Http\Request;

use App\Models\SchoolSetting;


use App\Models\TeacherEnrollment;
use App\Http\Controllers\Controller;
use App\Services\People\PeopleExcelImportService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;



class TeacherController extends Controller
{


  
    public function getAllTeachers(Request $request)
    {
        $auth = Auth::user();
        $teacherStatus = $request->input('teacher_status', 'active');
    
        $query = User::withRole('teacher')
            ->where('school_id', $auth->school_id);

        if ($teacherStatus !== 'all') {
            $query->where('teacher_status', $teacherStatus);
        }
    
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('firstname', 'like', '%' . $request->search . '%')
                  ->orWhere('surname', 'like', '%' . $request->search . '%')
                  ->orWhere('email', 'like', '%' . $request->search . '%')
                  ->orWhere('phone', 'like', '%' . $request->search . '%');
            });
        }
    
        $perPage = $request->input('perPage', 10);
    
        // Fetch teachers with nested relationship
        $teachers = $query->with(['teacherEnrollment.level'])->paginate($perPage);
    
       
        $teachers->through(function ($teacher) {
            $teacher->level = $teacher->teacherEnrollment->level ?? null;
            return $teacher;
        });
    
        $statusCounts = User::withRole('teacher')
            ->where('school_id', $auth->school_id)
            ->selectRaw('COALESCE(teacher_status, "active") as teacher_status, COUNT(*) as total')
            ->groupBy('teacher_status')
            ->pluck('total', 'teacher_status');
    
        return response()->json([
            'teachers' => $teachers,
            'status_counts' => [
                'active' => (int) ($statusCounts['active'] ?? 0),
                'suspended' => (int) ($statusCounts['suspended'] ?? 0),
                'inactive' => (int) ($statusCounts['inactive'] ?? 0),
                'resigned' => (int) ($statusCounts['resigned'] ?? 0),
            ],
        ]);
    }
    

  

public function viewTeacher($id)
{
    try {
        $auth = Auth::user();

        $teacher = User::with(['teacherEnrollment.level'])
            ->where('id', $id)
            ->forSchool($auth->school_id)
            ->withRole('teacher')
            ->first();

        if (!$teacher) {
            return response()->json([
                'message' => 'Teacher not found.',
            ], 404);
        }

        $decryptedPassword = null;
        try {
            $decryptedPassword = decrypt($teacher->default_password);
        } catch (\Exception $e) {
            $decryptedPassword = 'Unable to decrypt';
        }

        return response()->json([
            'teacher' => $teacher,
            'decrypted_password' => $decryptedPassword,
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'message' => 'An error occurred.',
            'error' => $e->getMessage(),
        ], 500);
    }
}

   



  
    
public function StoreTeacher(Request $request)
{
    $request->validate([
        'firstname' => 'required|string',
        'surname' => 'required|string',
        'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        'role' => 'required|string|max:255',
        'level_id' => 'required',
    ]);

    $auth = Auth::user();
    $school_setting = SchoolSetting::where('id', $auth->school_id)->first();

    $reg_no = random_int(100000, 999999);
    $final_reg_no = "{$school_setting->prefix}{$reg_no}";

    if (User::where('reg_no', $final_reg_no)->exists()) {
        return response()->json(['message' => 'Reg number exists'], 409);
    }

    if ($request->email && User::where('email', $request->email)->exists()) {
        return response()->json(['message' => 'Staff record already exists'], 409);
    }

    $randomPassword = Str::random(10);
    $hashedPassword = Hash::make($randomPassword);
    $encryptedPassword = encrypt($randomPassword);

    $user = new User();
    $user->firstname = $request->firstname;
    $user->surname = $request->surname;
    $user->username = $request->username;
    $user->dob = $request->dob;
    $user->address = $request->address;
    $user->email = $request->email;
    $user->sex = $request->sex;
    $user->school_id = $auth->school_id;
    $user->phone = $request->phone;
    $user->role = $request->role;
    $user->reg_no = $final_reg_no;
    $user->status = "1";
    $user->teacher_status = 'active';
    $user->password = $hashedPassword;
    $user->default_password = $encryptedPassword;

    if ($request->hasFile('photo') && $request->file('photo')->isValid()) {
        $file = $request->file('photo');
        $fileName = date('ymdHi') . $file->getClientOriginalName();
        $file->move(public_path('uploads/users'), $fileName);
        $user->photo = $fileName;
    }

    $user->save();
    $user->assignRole($request->role);

    TeacherEnrollment::updateOrCreate(
        [
            'user_id' => $user->id,
            'level_id' => $request->level_id,
        ],
        [
            'enroll' => true,
            'school_id' => $auth->school_id
        ]
    );

    return response()->json([
        'message' => 'Teacher registered and enrolled successfully.',
        'user' => $user,
        'reg_no' => $final_reg_no
    ], 201);
}

public function importTemplate(Request $request, PeopleExcelImportService $service)
{
    $format = strtolower((string) $request->query('format', 'xlsx'));

    return $service->teacherTemplate((int) Auth::user()->school_id, in_array($format, ['xlsx', 'xls', 'csv'], true) ? $format : 'xlsx');
}

public function previewImport(Request $request, PeopleExcelImportService $service)
{
    $request->validate([
        'file' => 'required|file|max:10240',
    ]);

    try {
        return response()->json($service->previewTeachers(Auth::user(), $request->file('file')));
    } catch (\InvalidArgumentException $e) {
        return response()->json(['message' => $e->getMessage()], 422);
    }
}

public function importTeachers(Request $request, PeopleExcelImportService $service)
{
    $request->validate([
        'file' => 'required|file|max:10240',
    ]);

    try {
        $result = $service->importTeachers(Auth::user(), $request->file('file'));

        return response()->json($result, ($result['imported'] ?? 0) > 0 ? 201 : 422);
    } catch (\InvalidArgumentException $e) {
        return response()->json(['message' => $e->getMessage()], 422);
    }
}

public function updateLifecycleStatus(Request $request, $id)
{
    $validated = $request->validate([
        'teacher_status' => ['required', Rule::in(['active', 'suspended', 'inactive', 'resigned'])],
        'reason' => ['nullable', 'string', 'max:1000'],
    ]);

    $auth = Auth::user();
    $teacher = User::forSchool($auth->school_id)
        ->withRole('teacher')
        ->findOrFail($id);

    $oldStatus = $teacher->teacher_status ?: 'active';
    $newStatus = $validated['teacher_status'];

    DB::transaction(function () use ($teacher, $auth, $oldStatus, $newStatus, $validated) {
        $teacher->forceFill([
            'teacher_status' => $newStatus,
            'teacher_status_reason' => $validated['reason'] ?? null,
            'teacher_status_changed_at' => now(),
            'teacher_status_changed_by' => $auth->id,
            'status' => $newStatus === 'active' ? 1 : 0,
        ])->save();

        DB::table('teacher_status_audit_logs')->insert([
            'school_id' => (int) $teacher->school_id,
            'teacher_id' => (int) $teacher->id,
            'changed_by' => (int) $auth->id,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'reason' => $validated['reason'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    return response()->json([
        'message' => match ($newStatus) {
            'suspended' => 'Teacher suspended successfully.',
            'inactive' => 'Teacher marked as inactive successfully.',
            'resigned' => 'Teacher marked as resigned successfully.',
            default => 'Teacher restored to active successfully.',
        },
        'teacher' => $teacher->fresh(['teacherEnrollment.level']),
    ]);
}

    







public function editTeacher($id)
{
    try {
        $auth = Auth::user();

        $teacher = User::with(['teacherEnrollment.level'])
            ->where('id', $id)
            ->forSchool($auth->school_id)
            ->withRole('teacher')
            ->first();

        if (!$teacher) {
            return response()->json([
                'message' => 'Teacher not found.',
                'alert-type' => 'error',
            ], 404);
        }

        return response()->json([
            'teacher' => $teacher,
            'level_id' => $teacher->teacherEnrollment->level_id ?? null,
            'photo_url' => $teacher->photo 
                ? asset("uploads/users/{$teacher->photo}") 
                : asset("uploads/avatar-3.png"),
            'message' => 'Teacher fetched successfully.',
        ]);
    } catch (\Exception $e) {
        Log::error('Edit Teacher Error: ' . $e->getMessage());
        return response()->json([
            'message' => 'An error occurred while fetching teacher data.',
            'error' => $e->getMessage(),
        ], 500);
    }
}




public function updateTeacher(Request $request, $id)
{
    $auth = Auth::user();

    $validated = $request->validate([
        'firstname' => 'required|string|max:100',
        'surname' => 'required|string|max:100',
        'email' => 'required|email',
        'phone' => 'nullable|string|max:20',
        'dob' => 'nullable|date',
        'sex' => 'nullable|string',
        'address' => 'nullable|string|max:255',
        'level_id' => 'nullable|integer',
        'password' => 'nullable|string|min:6',
        'photo' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
    ]);

    $teacher = User::where('id', $id)
        ->forSchool($auth->school_id)
        ->withRole('teacher')
        ->firstOrFail();

    $teacher->fill($validated);

    if ($request->hasFile('photo')) {
        $filename = time().'_'.$request->file('photo')->getClientOriginalName();
        $request->file('photo')->move(public_path('uploads/users'), $filename);
        $teacher->photo = $filename;
    }

    // If password is provided, hash it and store encrypted version
    if ($request->filled('password')) {
        $teacher->password = Hash::make($request->password);
        $teacher->default_password = encrypt($request->password);;
    }

    $teacher->save();

    // Update or create enrollment
    if ($request->filled('level_id')) {
        $teacher->teacherEnrollment()->updateOrCreate(
            ['user_id' => $teacher->id],
            [
                'level_id' => $request->level_id,
                'school_id' => $auth->school_id,
            ]
        );
    }

    return response()->json([
        'message' => 'Teacher updated successfully',
    ]);
}




public function deleteTeacher($id)
{
    $auth = Auth::user();

    $teacher = User::withRole('teacher')
        ->forSchool($auth->school_id)
        ->findOrFail($id);
    $teacher->delete();

    return response()->json(['message' => 'Teacher deleted successfully']);
}




 

 
}
