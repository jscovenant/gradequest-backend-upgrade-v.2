<?php

namespace App\Http\Controllers\Backend;

use App\Models\Level;
use App\Models\Section;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\StudentClass;
use App\Services\AcademicSetupArchiveService;
use Illuminate\Support\Facades\Auth;

class StudentClassController extends Controller
{
 



    public function index(Request $request)
    {
        $user = Auth::user();

        if(!$user){
            return response()->json("User not Authenticated!");
        }

        $levels = StudentClass::where('school_id', $user->school_id)
            ->when($request->boolean('archived'), fn ($query) => $query->whereNotNull('archived_at'))
            ->when(!$request->boolean('archived') && !$request->boolean('include_archived'), fn ($query) => $query->whereNull('archived_at'))
            ->orderBy('name')
            ->get();

        return response()->json($levels);
    }

   public function store(Request $request)
{
    $auth = Auth::user();

    $request->validate([
        'name' => 'required|string|max:50',
        'description' => 'nullable|string|max:255',
        'section_id' => 'nullable|exists:sections,id'
    ]);

    if (StudentClass::where('name', $request->name)
        ->where('school_id', $auth->school_id)
        ->whereNull('archived_at')
        ->exists()) {
        return response()->json(['message' => 'Class already exists'], 409);
    }

    if ($request->filled('section_id') && !Section::where('school_id', $auth->school_id)->whereNull('archived_at')->where('id', $request->section_id)->exists()) {
        return response()->json(['message' => 'Selected section is archived or unavailable.'], 422);
    }

    $class = new StudentClass();
    $class->name = strtoupper($request->name);
    $class->description = $request->description;
    $class->school_id = $auth->school_id;
    $class->section_id = $request->section_id;
    $class->save();

    return response()->json(['message' => 'Class created successfully'], 201);
}


public function update(Request $request, $id)
{
    $request->validate([
        'name' => 'required|string|max:5',
        'description' => 'nullable|string|max:255',
        'section_id' => 'nullable|exists:sections,id'
    ]);

    $schoolId = Auth::user()->school_id;

    $exists = StudentClass::where('name', $request->name)
        ->where('school_id', $schoolId)
        ->where('id', '!=', $id)
        ->whereNull('archived_at')
        ->exists();

    if ($exists) {
        return response()->json(['message' => 'This class is already saved, update a new one'], 422);
    }

    if ($request->filled('section_id') && !Section::where('school_id', $schoolId)->whereNull('archived_at')->where('id', $request->section_id)->exists()) {
        return response()->json(['message' => 'Selected section is archived or unavailable.'], 422);
    }

    $level = StudentClass::where('school_id', $schoolId)
        ->whereNull('archived_at')
        ->findOrFail($id);

    $level->update([
        'name' => strtoupper($request->name),
        'description' => $request->description,
        'section_id' => $request->section_id
    ]);

    return response()->json(['message' => 'Class updated successfully']);
}



    public function show($id)
{
    $level = StudentClass::where('school_id', Auth::user()->school_id)
        ->whereNull('archived_at')
        ->findOrFail($id);
    return response()->json($level);
}




    public function destroy($id)
    {
        $schoolId = Auth::user()->school_id;

        $level = StudentClass::where('id', $id)->where('school_id', $schoolId)->first();

        if (!$level) {
            return response()->json(['message' => 'Class level not found'], 404);
        }

        $usedInResults = app(AcademicSetupArchiveService::class)->classHasResultRecords($level);

        $level->forceFill(['archived_at' => now()])->save();

        return response()->json([
            'message' => $usedInResults
                ? 'This class is already used in results. It has been archived instead and will no longer appear in future setup forms.'
                : 'Class archived successfully.',
            'archived' => true,
            'used_in_results' => $usedInResults,
        ]);
    }

    public function restore($id)
    {
        $schoolId = Auth::user()->school_id;

        $level = StudentClass::where('id', $id)
            ->where('school_id', $schoolId)
            ->whereNotNull('archived_at')
            ->first();

        if (!$level) {
            return response()->json(['message' => 'Archived class not found'], 404);
        }

        $exists = StudentClass::where('name', $level->name)
            ->where('school_id', $schoolId)
            ->where('id', '!=', $level->id)
            ->whereNull('archived_at')
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'An active class with this name already exists. Rename it before restoring.'], 422);
        }

        $level->forceFill(['archived_at' => null])->save();

        return response()->json([
            'message' => 'Class restored successfully.',
            'archived' => false,
        ]);
    }
}
