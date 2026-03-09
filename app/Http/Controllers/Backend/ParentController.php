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
use Illuminate\Support\Facades\Schema;


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

    $validated = $request->validate([
        'parent_id' => 'required|integer|exists:users,id',
        'student_ids' => 'required|array|min:1',
        'student_ids.*' => 'integer|exists:users,id',
    ]);

    $parentId = (int) $validated['parent_id'];
    $studentIds = array_values(array_unique($validated['student_ids']));
    $schoolId = (int) $auth->school_id;

    // Verify parent belongs to same school & is parent role
    $parent = User::where('id', $parentId)
        ->where('school_id', $schoolId)
        ->where('role', 'parent')
        ->firstOrFail();

    // Verify students belong to same school & role student
    $validStudents = User::whereIn('id', $studentIds)
        ->where('school_id', $schoolId)
        ->where('role', 'student')
        ->pluck('id')
        ->toArray();

    if (count($validStudents) !== count($studentIds)) {
        return response()->json([
            'message' => 'One or more students are invalid for this school.',
        ], 422);
    }

    // Check conflicts: student already assigned to another parent (same school)
    $conflicts = DB::table('parent_students as ps')
        ->join('users as st', 'st.id', '=', 'ps.student_id')
        ->join('users as op', 'op.id', '=', 'ps.parent_id') // other parent
        ->where('ps.school_id', $schoolId)
        ->whereIn('ps.student_id', $studentIds)
        ->where('ps.parent_id', '!=', $parentId)
        ->select([
            'ps.student_id',
            'st.firstname as student_firstname',
            'st.surname as student_surname',
            'op.id as parent_id',
            'op.firstname as parent_firstname',
            'op.surname as parent_surname',
        ])
        ->get();

    if ($conflicts->count() > 0) {
        $first = $conflicts->first();

        return response()->json([
            'message' =>
                'Student ' . $first->student_firstname . ' ' . $first->student_surname .
                ' has already been assigned to Parent: ' . $first->parent_firstname . ' ' . $first->parent_surname,
            'conflicts' => $conflicts,
        ], 422);
    }

    DB::transaction(function () use ($parentId, $studentIds, $schoolId) {
        foreach ($studentIds as $sid) {
            // match by school + student, so each student can only exist once per school
            DB::table('parent_students')->updateOrInsert(
                [
                    'school_id'  => $schoolId,
                    'student_id' => $sid,
                ],
                [
                    'parent_id'  => $parentId,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    });

    return response()->json(['message' => 'Students assigned successfully']);
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
    
// ✅ Fetch classes for the logged-in school (student_classes table)
public function getStudentClasses()
{
    $auth = Auth::user();

    $classes = DB::table('student_classes')
        ->where('school_id', $auth->school_id)
        ->select('id', 'name', 'description')
        ->orderBy('name')
        ->get();

    return response()->json($classes);
}
    
public function getByClasses(Request $request)
{
    $auth = Auth::user();

    $request->validate([
        'class_ids' => 'required|array|min:1',
        'class_ids.*' => 'integer',
        // optional: pass current parent to auto-check in UI
        'parent_id' => 'nullable|integer',
    ]);

    // Ensure classes belong to this school
    $validClassIds = DB::table('student_classes')
        ->where('school_id', $auth->school_id)
        ->whereIn('id', $request->class_ids)
        ->pluck('id')
        ->toArray();

    if (count($validClassIds) === 0) {
        return response()->json([
            'message' => 'No valid classes found for this school.',
            'students' => [],
        ], 422);
    }

    // Students + assignment info
    $students = User::query()
        ->where('users.role', 'student')
        ->where('users.school_id', $auth->school_id)
        ->whereIn('users.level_id', $validClassIds)
        ->leftJoin('parent_students as ps', 'ps.student_id', '=', 'users.id')
        ->leftJoin('users as p', 'p.id', '=', 'ps.parent_id') // parent is also a user
        ->select([
            'users.id',
            'users.firstname',
            'users.surname',
            'users.reg_no',
            'users.level_id',
            DB::raw('ps.parent_id as assigned_parent_id'),
            DB::raw('p.firstname as assigned_parent_firstname'),
            DB::raw('p.surname as assigned_parent_surname'),
        ])
        ->orderBy('users.surname')
        ->orderBy('users.firstname')
        ->get();

    // If you still want level relation name, you can load it separately:
    // (quickest approach: map ids and attach in frontend; or use Eloquent relations if you prefer)

    return response()->json([
        'class_ids' => $validClassIds,
        'students' => $students,
    ]);
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






public function myChildren(Request $request)
{
    $parent = $request->user();

    if (!$parent || strtolower(trim((string) $parent->role)) !== 'parent') {
        return response()->json(['message' => 'Unauthorized'], 403);
    }

    $schoolId = (int) $parent->school_id;

    // 1) Get children basic rows
    $childrenRows = DB::table('parent_students as ps')
        ->join('users as s', 's.id', '=', 'ps.student_id')
        ->leftJoin('student_classes as c', 'c.id', '=', 's.level_id')
        ->where('ps.parent_id', $parent->id)
        ->where('ps.school_id', $schoolId)
        ->select([
            's.id',
            's.firstname',
            's.surname',
            's.reg_no',
            's.photo',
            'c.name as class_name',
        ])
        ->orderBy('s.firstname')
        ->get();

    $studentIds = $childrenRows->pluck('id')->values()->all();

    // Short-circuit
    if (count($studentIds) === 0) {
        return response()->json([
            'parent' => [
                'id' => (int) $parent->id,
                'name' => trim(($parent->firstname ?? '') . ' ' . ($parent->surname ?? '')) ?: ($parent->firstname ?? 'Parent'),
                'email' => $parent->email,
                'phone' => $parent->phone ?? null,
            ],
            'stats' => ['children' => 0],
            'children' => [],
        ]);
    }

    // 2) Attendance rate in last 30 days (attendances table)
    $fromDate = now()->subDays(30)->toDateString();

    $attendanceAgg = DB::table('attendances')
        ->whereIn('student_id', $studentIds)
        ->where('school_id', $schoolId)
        ->where('date', '>=', $fromDate)
        ->selectRaw('student_id,
            SUM(CASE WHEN status IN ("present","late") THEN 1 ELSE 0 END) as present_count,
            COUNT(DISTINCT date) as total_count
        ')
        ->groupBy('student_id')
        ->get()
        ->keyBy('student_id');

    // 3) Fee balance per student (student_fees.balance)
    $feeBalanceAgg = DB::table('student_fees')
        ->whereIn('student_id', $studentIds)
        ->where('school_id', $schoolId)
        ->selectRaw('student_id, SUM(COALESCE(balance,0)) as total_balance')
        ->groupBy('student_id')
        ->get()
        ->keyBy('student_id');

    // 4) Results count per student (SAFE: only if table exists)
    // If your real results table name differs, update $resultsTable to match.
    $resultsTable = 'student_results_v2';
    $resultsAgg = collect();

    if (Schema::hasTable($resultsTable)) {
        // Common columns: student_id, school_id
        $q = DB::table($resultsTable)->whereIn('user_id', $studentIds);

        if (Schema::hasColumn($resultsTable, 'user_id')) {
            $q->where('user_id', $schoolId);
        }

        $resultsAgg = $q->selectRaw('user_id, COUNT(*) as results_count')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');
    }

    $children = $childrenRows->map(function ($r) use ($attendanceAgg, $feeBalanceAgg, $resultsAgg) {
        $att = $attendanceAgg->get($r->id);
        $present = (int) ($att->present_count ?? 0);
        $total = (int) ($att->total_count ?? 0);
        $rate = $total > 0 ? (int) round(($present / $total) * 100) : 0;

        $balance = (float) ($feeBalanceAgg->get($r->id)->total_balance ?? 0);

        $rc = (int) ($resultsAgg->get($r->id)->results_count ?? 0);

        $photoUrl = $r->photo
            ? (str_starts_with($r->photo, 'http') ? $r->photo : url('uploads/' . 'users'. ltrim($r->photo, '/')))
            : url('img/profile.png');

        return [
            'id' => (int) $r->id,
            'name' => trim(($r->firstname ?? '') . ' ' . ($r->surname ?? '')) ?: 'Student',
            'reg_no' => $r->reg_no,
            'photo' => $photoUrl,
            'class' => $r->class_name,
            'attendance_rate_30d' => $rate,
            'fee_balance' => $balance,
            'results_count' => $rc,
        ];
    })->values();

    return response()->json([
        'parent' => [
            'id' => (int) $parent->id,
            'name' => trim(($parent->firstname ?? '') . ' ' . ($parent->surname ?? '')) ?: ($parent->firstname ?? 'Parent'),
            'email' => $parent->email,
            'phone' => $parent->phone ?? null,
        ],
        'stats' => [
            'children' => $children->count(),
        ],
        'children' => $children,
    ]);
}



}
