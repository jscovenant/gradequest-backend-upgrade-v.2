<?php

namespace App\Http\Controllers\Backend;

use App\Models\Department;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redis;

class DepartmentController extends Controller
{
   


    public function index()
    {
        $auth = Auth::user();

        $departments = Department::where('school_id', $auth->school_id)->latest()->get();

        return response()->json($departments);
    }

    /**
     * Store a newly created department in storage.
     */
    public function store(Request $request)
    {
        $auth = Auth::user();

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
        ]);

        // Check for duplicate
        $exists = Department::where('school_id', $auth->school_id)
            ->where('name', $validated['name'])
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Department with this name already exists.'], 409);
        }

        $department = new Department();
        $department->name = $validated['name'];
        $department->description = $validated['description'] ?? null;
        $department->school_id = $auth->school_id;
        $department->save();

        return response()->json(['message' => 'Department created successfully.', 'department' => $department], 201);
    }

    public function show($id)
    {
        $department = Department::find($id);
    
        if (!$department) {
            return response()->json(['message' => 'Department not found'], 404);
        }
    
        return response()->json($department);
    }

    public function update(Request $request, $id)
{
        
    $schoolId = Auth::user()->school_id;
    $exists = Department::where('name', $request->name)
    ->where('school_id', $schoolId)
    ->where('id', '!=', $id)
    ->exists();

    if ($exists) {
    return response()->json(['message' => 'This department is already saved, update a new one'], 422);
    }
    $department = Department::find($id);

    if (!$department) {
        return response()->json(['message' => 'Department not found'], 404);
    }

    $request->validate([
        'name' => 'required|string|max:255',
        'description' => 'nullable|string',
    ]);


    $department->name = $request->name;
    $department->description = $request->description;
    $department->save();

    return response()->json(['message' => 'Department updated successfully']);
}


    public function destroy($id)
    {
        $auth = Auth::user();

        $department = Department::where('id', $id)
            ->where('school_id', $auth->school_id)
            ->first();

        if (!$department) {
            return response()->json(['message' => 'Department not found.'], 404);
        }

        $department->delete();

        return response()->json(['message' => 'Department deleted successfully.']);
    }
}
