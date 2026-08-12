<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Section;
use App\Models\Subject;
use App\Services\AcademicSetupArchiveService;
use App\Services\Results\SubjectService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SubjectController extends Controller
{
    public function getAllSubjects(Request $request, $departmentId)
    {
        $schoolId = (int) Auth::user()->school_id;
        $department = $this->resolveDepartment($departmentId, $schoolId);

        if (! $this->isGeneralDepartment($departmentId) && ! $department) {
            return response()->json(['message' => 'Department not found'], 404);
        }

        $subjects = Subject::query()
            ->where('school_id', $schoolId)
            ->when($request->boolean('archived'), fn ($query) => $query->whereNotNull('archived_at'))
            ->when(! $request->boolean('archived') && ! $request->boolean('include_archived'), fn ($query) => $query->whereNull('archived_at'))
            ->when($this->isGeneralDepartment($departmentId), function ($query) {
                $query->whereNull('department_id');
            }, function ($query) use ($department, $request) {
                $includeGeneral = ! $request->has('include_general') || $request->boolean('include_general');

                $query->where(function ($inner) use ($department, $includeGeneral) {
                    $inner->where('department_id', $department->id);

                    if ($includeGeneral) {
                        $inner->orWhereNull('department_id');
                    }
                });
            })
            ->select('id', 'name', 'subject_id', 'department_id', 'section_id', 'class_id', 'archived_at')
            ->orderBy('name')
            ->get();

        $subjects = app(SubjectService::class)->preferGeneralSubjects($subjects);

        return response()->json($subjects->values());
    }

    public function getSections()
    {
        $schoolId = Auth::user()->school_id;

        $sections = Section::where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->get();

        return response()->json($sections);
    }

    public function assignSection(Request $request)
    {
        $request->validate([
            'section_id' => 'required|exists:sections,id',
            'subject_ids' => 'required|array',
            'subject_ids.*' => 'exists:subjects,id',
        ]);

        $schoolId = (int) Auth::user()->school_id;
        $section = Section::where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->find($request->section_id);

        if (! $section) {
            return response()->json(['message' => 'Selected section is archived or unavailable.'], 422);
        }

        Subject::whereIn('id', $request->subject_ids)
            ->where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->update(['section_id' => $request->section_id]);

        return response()->json(['message' => 'Subjects successfully assigned to section']);
    }

    public function storeSubject(Request $request, $departmentId)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'is_general' => 'nullable|boolean',
        ]);

        $schoolId = (int) Auth::user()->school_id;
        $isGeneral = $request->boolean('is_general') || $this->isGeneralDepartment($departmentId);
        $department = null;

        if (! $isGeneral) {
            $department = $this->resolveDepartment($departmentId, $schoolId);

            if (! $department) {
                return response()->json(['message' => 'Department not found'], 404);
            }
        }

        $name = trim((string) $request->name);
        $departmentColumnValue = $isGeneral ? null : (int) $department->id;

        $existingSubject = Subject::query()
            ->where('school_id', $schoolId)
            ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower($name)])
            ->where('department_id', $departmentColumnValue)
            ->whereNull('archived_at')
            ->first();

        if ($existingSubject) {
            return response()->json([
                'message' => $isGeneral
                    ? 'A general subject with this name already exists.'
                    : 'Subject with this name already exists in the selected department.',
            ], 422);
        }

        $prefix = $isGeneral ? 'GEN' : strtoupper(substr((string) $department->name, 0, 3));
        $subjectCount = Subject::where('school_id', $schoolId)
            ->where('department_id', $departmentColumnValue)
            ->whereNull('archived_at')
            ->count();
        $subjectCode = $prefix . str_pad($subjectCount + 1, 3, '0', STR_PAD_LEFT);

        $subject = Subject::create([
            'name' => $name,
            'subject_id' => $subjectCode,
            'department_id' => $departmentColumnValue,
            'school_id' => $schoolId,
        ]);

        $duplicateDepartmentSubjects = $isGeneral
            ? Subject::query()
                ->where('school_id', $schoolId)
                ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower($name)])
                ->whereNotNull('department_id')
                ->whereNull('archived_at')
                ->count()
            : 0;

        return response()->json([
            'message' => $isGeneral
                ? 'General subject added successfully. It will be available to all departments.'
                : 'Subject added successfully',
            'subject' => $subject,
            'subject_code' => $subjectCode,
            'is_general' => $isGeneral,
            'duplicate_department_subjects' => $duplicateDepartmentSubjects,
        ], 201);
    }

    public function edit($id)
    {
        $subject = Subject::where('school_id', Auth::user()->school_id)
            ->whereNull('archived_at')
            ->findOrFail($id);

        return response()->json($subject);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'is_general' => 'nullable|boolean',
        ]);

        $schoolId = (int) Auth::user()->school_id;
        $subject = Subject::where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->findOrFail($id);

        $isGeneral = $request->boolean('is_general');
        $targetDepartmentId = $isGeneral ? null : $subject->department_id;

        $exists = Subject::where('school_id', $schoolId)
            ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower(trim((string) $request->name))])
            ->where('department_id', $targetDepartmentId)
            ->where('id', '!=', $id)
            ->whereNull('archived_at')
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => empty($subject->department_id)
                    ? 'A general subject with this name already exists.'
                    : 'Subject with this name already exists in this department.',
            ], 422);
        }

        $subject->name = trim((string) $request->name);
        $subject->department_id = $targetDepartmentId;
        $subject->save();

        return response()->json(['message' => 'Subject updated successfully']);
    }

    public function destroy($id)
    {
        $subject = Subject::where('school_id', Auth::user()->school_id)->find($id);

        if (! $subject) {
            return response()->json(['message' => 'Subject not found'], 404);
        }

        $usedInResults = app(AcademicSetupArchiveService::class)->subjectHasResultRecords($subject);

        $subject->forceFill(['archived_at' => now()])->save();

        return response()->json([
            'message' => $usedInResults
                ? 'This subject is already used in results. It has been archived instead and will no longer appear for future result entry.'
                : 'Subject archived successfully.',
            'archived' => true,
            'used_in_results' => $usedInResults,
        ]);
    }

    public function restore($id)
    {
        $schoolId = (int) Auth::user()->school_id;

        $subject = Subject::where('school_id', $schoolId)
            ->whereNotNull('archived_at')
            ->find($id);

        if (! $subject) {
            return response()->json(['message' => 'Archived subject not found'], 404);
        }

        $exists = Subject::where('name', $subject->name)
            ->where('department_id', $subject->department_id)
            ->where('school_id', $schoolId)
            ->where('id', '!=', $subject->id)
            ->whereNull('archived_at')
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'An active subject with this name already exists in this department. Rename it before restoring.'], 422);
        }

        $subject->forceFill(['archived_at' => null])->save();

        return response()->json([
            'message' => 'Subject restored successfully.',
            'archived' => false,
        ]);
    }

    private function isGeneralDepartment($departmentId): bool
    {
        $value = strtolower(trim((string) $departmentId));

        return in_array($value, ['0', 'general', 'common', 'all'], true);
    }

    private function resolveDepartment($departmentId, int $schoolId): ?Department
    {
        if ($this->isGeneralDepartment($departmentId)) {
            return null;
        }

        return Department::query()
            ->where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->find($departmentId);
    }
}
