<?php

namespace App\Http\Controllers\Backend;

use App\Models\Section;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class SectionController extends Controller
{
    public function index()
    {
        $schoolId = Auth::user()->school_id;
        $sections = Section::where('school_id', $schoolId)->latest()->paginate(10);
        return response()->json($sections);
    }

    public function store(Request $request)
    {
        $schoolId = Auth::user()->school_id;

        $request->validate([
            'name' => 'required|string|max:255'
        ]);

        if (Section::where('name', $request->name)
            ->where('school_id', $schoolId)
            ->exists()
        ) {
            return response()->json(['error' => 'Section name already exists'], 409);
        }

        $section = Section::create([
            'name' => ucfirst($request->name),
            'school_id' => $schoolId,
        ]);

        return response()->json([
            'message' => 'Section saved successfully',
            'section' => $section
        ]);
    }

    public function show($id)
    {
        $section = Section::findOrFail($id);
        return response()->json($section);
    }

    public function update(Request $request, $id)
    {
        $request->validate(['name' => 'required|string|max:255']);
        $schoolId = Auth::user()->school_id;

        $section = Section::findOrFail($id);

        if (Section::where('name', $request->name)
            ->where('school_id', $schoolId)
            ->where('id', '!=', $id)
            ->exists()
        ) {
            return response()->json(['error' => 'Section name already exists'], 409);
        }

        $section->update(['name' => ucfirst($request->name)]);

        return response()->json([
            'message' => 'Section updated successfully',
            'section' => $section
        ]);
    }

    public function destroy($id)
    {
        $section = Section::findOrFail($id);
        $section->delete();

        return response()->json(['message' => 'Section deleted successfully']);
    }
}
