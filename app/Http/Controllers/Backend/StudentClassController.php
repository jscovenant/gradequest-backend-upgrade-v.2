<?php

namespace App\Http\Controllers\Backend;

use App\Models\Level;
use App\Models\Section;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\StudentClass;
use Illuminate\Support\Facades\Auth;

class StudentClassController extends Controller
{
 



    public function index()
    {
        $schoolId = Auth::user()->school_id;

        $levels = StudentClass::where('school_id', $schoolId)->orderBy('name')->get();

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
        ->exists()) {
        return response()->json(['message' => 'Class already exists'], 409);
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
        ->exists();

    if ($exists) {
        return response()->json(['message' => 'This class is already saved, update a new one'], 422);
    }

    $level = StudentClass::findOrFail($id);

    $level->update([
        'name' => strtoupper($request->name),
        'description' => $request->description,
        'section_id' => $request->section_id
    ]);

    return response()->json(['message' => 'Class updated successfully']);
}



    public function show($id)
{
    $level = StudentClass::findOrFail($id);
    return response()->json($level);
}




    public function destroy($id)
    {
        $schoolId = Auth::user()->school_id;

        $level = StudentClass::where('id', $id)->where('school_id', $schoolId)->first();

        if (!$level) {
            return response()->json(['message' => 'Class level not found'], 404);
        }

        $level->delete();

        return response()->json(['message' => 'Class level deleted successfully']);
    }
}
