<?php

namespace App\Http\Controllers\Backend;

use App\Models\Section;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\AcademicSetupArchiveService;
use Illuminate\Support\Facades\Auth;

class SectionController extends Controller
{
    public function index(Request $request)
    {
        $schoolId = Auth::user()->school_id;
        $sections = Section::where('school_id', $schoolId)
            ->when($request->boolean('archived'), fn ($query) => $query->whereNotNull('archived_at'))
            ->when(!$request->boolean('archived') && !$request->boolean('include_archived'), fn ($query) => $query->whereNull('archived_at'))
            ->latest()
            ->paginate(10);
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
            ->whereNull('archived_at')
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
        $section = Section::where('school_id', Auth::user()->school_id)
            ->whereNull('archived_at')
            ->findOrFail($id);
        return response()->json($section);
    }

    public function update(Request $request, $id)
    {
        $request->validate(['name' => 'required|string|max:255']);
        $schoolId = Auth::user()->school_id;

        $section = Section::where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->findOrFail($id);

        if (Section::where('name', $request->name)
            ->where('school_id', $schoolId)
            ->where('id', '!=', $id)
            ->whereNull('archived_at')
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
        $schoolId = Auth::user()->school_id;

        $section = Section::where('school_id', $schoolId)->findOrFail($id);
        $usedInRecords = app(AcademicSetupArchiveService::class)->sectionHasLinkedRecords($section);

        $section->forceFill(['archived_at' => now()])->save();

        return response()->json([
            'message' => $usedInRecords
                ? 'This section is already used in records. It has been archived instead and will no longer appear in future setup forms.'
                : 'Section archived successfully.',
            'archived' => true,
            'used_in_records' => $usedInRecords,
        ]);
    }

    public function restore($id)
    {
        $schoolId = Auth::user()->school_id;

        $section = Section::where('school_id', $schoolId)
            ->whereNotNull('archived_at')
            ->find($id);

        if (!$section) {
            return response()->json(['message' => 'Archived section not found.'], 404);
        }

        $exists = Section::where('name', $section->name)
            ->where('school_id', $schoolId)
            ->where('id', '!=', $section->id)
            ->whereNull('archived_at')
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'An active section with this name already exists. Rename it before restoring.'], 422);
        }

        $section->forceFill(['archived_at' => null])->save();

        return response()->json([
            'message' => 'Section restored successfully.',
            'archived' => false,
        ]);
    }
}
