<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
class ParentController extends Controller
{
    // ✅ Register a new parent
    public function register(Request $request)
    {
        $auth = Auth::user();

        $request->validate([
            'firstname' => 'required|string|max:100',
            'surname' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'phone' => 'required|string|max:20|unique:users,phone',
            'address' => 'nullable|string|max:255',
        ]);

        $randomPassword = Str::random(8);

        $parent = User::create([
            'firstname' => $request->firstname,
            'surname' => $request->surname,
            'email' => $request->email,
            'phone' => $request->phone,
            'address' => $request->address,
            'role' => 'Parent',
            'school_id' => $auth->school_id,
            'password' => Hash::make($randomPassword),
            'default_password' => encrypt($randomPassword),
            'status' => 1,
        ]);

        $parent->assignRole('Parent');

        return response()->json([
            'message' => 'Parent registered successfully',
            'parent' => $parent,
            'default_password' => $randomPassword,
        ], 201);
    }

    // ✅ Assign one or more students to a parent
    public function assignChild(Request $request)
    {
        $auth = Auth::user();

        $request->validate([
            'parent_id' => 'required|integer|exists:users,id',
            'student_ids' => 'required|array|min:1',
            'student_ids.*' => 'integer|exists:users,id',
        ]);

        $parent = User::where('id', $request->parent_id)
            ->where('school_id', $auth->school_id)
            ->where('role', 'Parent')
            ->firstOrFail();

        foreach ($request->student_ids as $studentId) {
            $student = User::where('id', $studentId)
                ->where('school_id', $auth->school_id)
                ->where('role', 'student')
                ->first();

            if ($student) {
                DB::table('parent_students')->updateOrInsert([
                    'parent_id' => $parent->id,
                    'student_id' => $student->id,
                    'school_id' => $auth->school_id,
                ]);
            }
        }

        return response()->json([
            'message' => 'Students assigned successfully to parent',
        ]);
    }

    // ✅ Get all students for a parent
    public function viewParent($parentId)
    {
       $auth = Auth::user();

    $parent = User::where('id', $parentId)
        ->where('school_id', $auth->school_id)
        ->where('role', 'Parent')
        ->with(['children' => function ($query) {
            $query->select('users.id', 'firstname', 'surname', 'reg_no', 'level_id')
                ->with('level:id,name');
        }])
        ->firstOrFail();

    $decryptedPassword = null;
    try {
        $decryptedPassword = Crypt::decrypt($parent->default_password);
    } catch (\Exception $e) {
        $decryptedPassword = 'N/A';
    }

    return response()->json([
        'parent' => [
            'id' => $parent->id,
            'firstname' => $parent->firstname,
            'surname' => $parent->surname,
            'email' => $parent->email,
            'phone' => $parent->phone,
            'address' => $parent->address,
            'default_password' => $decryptedPassword,
        ],
        'children' => $parent->children,
    ]);
    }

    // ✅ Remove a student from parent
    public function removeChild(Request $request)
    {
        $auth = Auth::user();

        $request->validate([
            'parent_id' => 'required|integer',
            'student_id' => 'required|integer',
        ]);

        DB::table('parent_students')
            ->where('parent_id', $request->parent_id)
            ->where('student_id', $request->student_id)
            ->where('school_id', $auth->school_id)
            ->delete();

        return response()->json(['message' => 'Student removed from parent']);
    }

    // ✅ List all parents (admin view)
    public function allParents()
    {
        $auth = Auth::user();

        $parents = User::where('role', 'Parent')
            ->where('school_id', $auth->school_id)
            ->select('id', 'firstname', 'surname', 'email', 'phone')
            ->latest()
            ->get();

        return response()->json($parents);
    }
    
    
public function getByClasses(Request $request)
{
    $auth = Auth::user();

    $request->validate([
        'class_ids' => 'required|array|min:1',
        'class_ids.*' => 'integer|exists:student_classes,id', // ✅ Correct table
    ]);

    $students = User::where('role', 'student')
        ->where('school_id', $auth->school_id)
        ->whereIn('level_id', $request->class_ids) // ✅ Using your actual column
        ->select('id', 'firstname', 'surname', 'reg_no', 'level_id')
        ->with('level:id,name') // ✅ Relation name you defined
        ->get();

    return response()->json($students);
}


// ✅ Edit (Fetch Parent Details for Editing)
public function edit($id)
{
    $auth = Auth::user();

    $parent = User::where('id', $id)
        ->where('school_id', $auth->school_id)
        ->where('role', 'Parent')
        ->first();

    if (!$parent) {
        return response()->json(['message' => 'Parent not found'], 404);
    }

    $decryptedPassword = null;
    try {
        $decryptedPassword = Crypt::decrypt($parent->default_password);
    } catch (\Exception $e) {
        $decryptedPassword = 'N/A';
    }

    return response()->json([
        'parent' => [
            'id' => $parent->id,
            'firstname' => $parent->firstname,
            'surname' => $parent->surname,
            'email' => $parent->email,
            'phone' => $parent->phone,
            'address' => $parent->address,
            'default_password' => $decryptedPassword,
        ]
    ]);
}


// ✅ Update Parent Details
public function update(Request $request, $id)
{
    $auth = Auth::user();

    $parent = User::where('id', $id)
        ->where('school_id', $auth->school_id)
        ->where('role', 'Parent')
        ->first();

    if (!$parent) {
        return response()->json(['message' => 'Parent not found'], 404);
    }

    $request->validate([
        'firstname' => 'required|string|max:100',
        'surname' => 'required|string|max:100',
        'email' => ['required', 'email', Rule::unique('users')->ignore($parent->id)],
        'phone' => ['required', 'string', 'max:20', Rule::unique('users')->ignore($parent->id)],
        'address' => 'nullable|string|max:255',
        'password' => 'nullable|string|min:6',
    ]);

    // ✅ Update basic info
    $parent->firstname = $request->firstname;
    $parent->surname = $request->surname;
    $parent->email = $request->email;
    $parent->phone = $request->phone;
    $parent->address = $request->address;

    // ✅ Handle optional password update
    if (!empty($request->password)) {
        $parent->password = Hash::make($request->password);
        $parent->default_password = encrypt($request->password);
    }

    $parent->save();

    return response()->json([
        'message' => 'Parent updated successfully',
        'parent' => $parent,
    ]);
}



    
    
    public function destroy($id)
{
    $parent = User::role('Parent')->find($id);

    if (!$parent) {
        return response()->json(['message' => 'Parent not found'], 404);
    }

    $parent->delete();

    return response()->json(['message' => 'Parent deleted successfully'], 200);
}

}
