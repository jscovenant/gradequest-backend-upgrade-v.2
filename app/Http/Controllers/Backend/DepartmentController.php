<?php

namespace App\Http\Controllers\Backend;

use App\Models\Department;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\AcademicSetupArchiveService;
use Illuminate\Support\Facades\Auth;

class DepartmentController extends Controller
{
   


    public function index(Request $request)
    {
        $auth = Auth::user();

        $departments = Department::where('school_id', $auth->school_id)
            ->when($request->boolean('archived'), fn ($query) => $query->whereNotNull('archived_at'))
            ->when(!$request->boolean('archived') && !$request->boolean('include_archived'), fn ($query) => $query->whereNull('archived_at'))
            ->latest()
            ->get();

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
            ->whereNull('archived_at')
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
        $department = Department::where('school_id', Auth::user()->school_id)
            ->whereNull('archived_at')
            ->find($id);
    
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
    ->whereNull('archived_at')
    ->exists();

    if ($exists) {
    return response()->json(['message' => 'This department is already saved, update a new one'], 422);
    }
    $department = Department::where('school_id', $schoolId)
        ->whereNull('archived_at')
        ->find($id);

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

        $usedInResults = app(AcademicSetupArchiveService::class)->departmentHasResultRecords($department);

        $department->forceFill(['archived_at' => now()])->save();

        return response()->json([
            'message' => $usedInResults
                ? 'This department is already used in results. It has been archived instead and will no longer appear in future setup forms.'
                : 'Department archived successfully.',
            'archived' => true,
            'used_in_results' => $usedInResults,
        ]);
    }

    public function restore($id)
    {
        $auth = Auth::user();

        $department = Department::where('id', $id)
            ->where('school_id', $auth->school_id)
            ->whereNotNull('archived_at')
            ->first();

        if (!$department) {
            return response()->json(['message' => 'Archived department not found.'], 404);
        }

        $exists = Department::where('school_id', $auth->school_id)
            ->where('name', $department->name)
            ->where('id', '!=', $department->id)
            ->whereNull('archived_at')
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'An active department with this name already exists. Rename it before restoring.'], 422);
        }

        $department->forceFill(['archived_at' => null])->save();

        return response()->json([
            'message' => 'Department restored successfully.',
            'archived' => false,
        ]);
    }
}
