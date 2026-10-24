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
            'name' => 'required|string|max:255',
            'stage' => 'nullable|string|in:early_years,basic,secondary',
        ]);

        if (Section::where('name', $request->name)
            ->where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->exists()
        ) {
            return response()->json(['error' => 'Section name already exists'], 409);
        }

        $detectedStage = $request->input('stage');
        if (!$detectedStage) {
            $n = strtolower((string) $request->name);
            if (preg_match('/junior|senior|secondary|jss|sss|college|high/i', $n)) {
                $detectedStage = 'secondary';
            } elseif (preg_match('/nursery|creche|kindergarten|kg|early|toddler|playgroup/i', $n)) {
                $detectedStage = 'early_years';
            } else {
                $detectedStage = 'basic';
            }
        }

        $section = Section::create([
            'name' => ucfirst($request->name),
            'stage' => $detectedStage,
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
        $request->validate([
            'name' => 'required|string|max:255',
            'stage' => 'nullable|string|in:early_years,basic,secondary',
        ]);
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

        $updateData = ['name' => ucfirst($request->name)];
        if ($request->filled('stage')) {
            $updateData['stage'] = $request->stage;
        }
        $section->update($updateData);

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
