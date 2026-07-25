<?php

namespace App\Http\Controllers\Backend;

use App\Models\User;
use App\Models\Level;

use App\Models\Section;

use Illuminate\Http\Request;

use App\Models\SchoolSetting;


use App\Models\TeacherEnrollment;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;



class TeacherController extends Controller
{


  
    public function getAllTeachers(Request $request)
    {
        $auth = Auth::user();
    
        $query = User::withRole('teacher')
            ->where('school_id', $auth->school_id);
    
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
    
        return response()->json([
            'teachers' => $teachers
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
