<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Section;
use App\Models\Subject;
use App\Services\AcademicSetupArchiveService;
use App\Services\Results\SubjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class SubjectController extends Controller
{
    /**
     * Get all school subjects with optional filtering (Universal Subject Bank).
     */
    public function index(Request $request): JsonResponse
    {
        $schoolId = (int) Auth::user()->school_id;
        $showArchived = $request->boolean('archived');
        $sectionId = $request->query('section_id');
        $departmentId = $request->query('department_id');
        $search = trim((string) ($request->query('search') ?? $request->query('q') ?? ''));

        $query = Subject::query()
            ->where('school_id', $schoolId)
            ->with(['section:id,name', 'department:id,name'])
            ->when($showArchived, fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'))
            ->when(! empty($sectionId), fn ($q) => $q->where('section_id', (int) $sectionId))
            ->when(! empty($departmentId), function ($q) use ($departmentId) {
                if ($this->isGeneralDepartment($departmentId)) {
                    $q->whereNull('department_id');
                } else {
                    $q->where('department_id', (int) $departmentId);
                }
            })
            ->when(! empty($search), function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('subject_id', 'LIKE', "%{$search}%");
                });
            })
            ->select('id', 'name', 'subject_id', 'department_id', 'section_id', 'class_id', 'archived_at', 'created_at')
            ->orderBy('name');

        $subjects = $query->get();

        return response()->json($subjects);
    }

    /**
     * Legacy & department-filtered subject resolver.
     */
    public function getAllSubjects(Request $request, $departmentId): JsonResponse
    {
        $schoolId = (int) Auth::user()->school_id;

        // If 'all' or no department filter, return all school subjects
        if (in_array(strtolower(trim((string) $departmentId)), ['all', 'any', 'none', '*'], true)) {
            return $this->index($request);
        }

        $department = $this->resolveDepartment($departmentId, $schoolId);

        if (! $this->isGeneralDepartment($departmentId) && ! $department) {
            return response()->json(['message' => 'Department not found'], 404);
        }

        $subjects = Subject::query()
            ->where('school_id', $schoolId)
            ->with(['section:id,name', 'department:id,name'])
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
            ->select('id', 'name', 'subject_id', 'department_id', 'section_id', 'class_id', 'archived_at', 'created_at')
            ->orderBy('name')
            ->get();

        $subjects = app(SubjectService::class)->preferGeneralSubjects($subjects);

        return response()->json($subjects->values());
    }

    public function getSections(): JsonResponse
    {
        $schoolId = (int) Auth::user()->school_id;

        $sections = Section::query()
            ->where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->orderBy('id')
            ->get();

        return response()->json($sections);
    }

    public function assignSection(Request $request): JsonResponse
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

    /**
     * Create a new Subject (Guarantees school-wide uniqueness & zero duplicate subjects).
     */
    public function storeSubject(Request $request, $departmentId = null): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'section_id' => 'nullable|integer|exists:sections,id',
            'department_id' => 'nullable',
            'is_general' => 'nullable|boolean',
        ]);

        $schoolId = (int) Auth::user()->school_id;
        $name = trim((string) $request->name);

        // 1. School-wide duplicate check: Each subject should exist ONCE per school
        $existingSubject = Subject::query()
            ->where('school_id', $schoolId)
            ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower($name)])
            ->whereNull('archived_at')
            ->first();

        if ($existingSubject) {
            return response()->json([
                'message' => "A subject named '{$existingSubject->name}' already exists in your school. You can allocate it to any class or department from the Subject Allocation tab without creating duplicates.",
                'subject' => $existingSubject,
            ], 422);
        }

        // 2. Resolve department if specified
        $deptIdInput = $request->input('department_id', $departmentId);
        $isGeneral = $request->boolean('is_general') || $this->isGeneralDepartment($deptIdInput);
        $resolvedDepartmentId = null;

        if (! $isGeneral && ! empty($deptIdInput)) {
            $department = $this->resolveDepartment($deptIdInput, $schoolId);
            if ($department) {
                $resolvedDepartmentId = (int) $department->id;
            }
        }

        // 3. Resolve section if specified
        $sectionId = $request->filled('section_id') ? (int) $request->section_id : null;

        // 4. Generate clean subject code (e.g. MTH001, ENG002, SUB003)
        $cleanWord = preg_replace('/[^A-Za-z]/', '', $name);
        $prefix = strlen($cleanWord) >= 3 ? strtoupper(substr($cleanWord, 0, 3)) : 'SUB';
        $totalSchoolSubjects = Subject::where('school_id', $schoolId)->whereNull('archived_at')->count();
        $subjectCode = $prefix . str_pad($totalSchoolSubjects + 1, 3, '0', STR_PAD_LEFT);

        $subject = Subject::create([
            'name' => $name,
            'subject_id' => $subjectCode,
            'department_id' => $resolvedDepartmentId,
            'section_id' => $sectionId,
            'school_id' => $schoolId,
        ]);

        $subject->load(['section:id,name', 'department:id,name']);

        return response()->json([
            'message' => "Subject '{$subject->name}' added successfully. You can now assign it to classes.",
            'subject' => $subject,
            'subject_code' => $subjectCode,
        ], 201);
    }

    public function edit($id): JsonResponse
    {
        $subject = Subject::where('school_id', Auth::user()->school_id)
            ->with(['section:id,name', 'department:id,name'])
            ->whereNull('archived_at')
            ->findOrFail($id);

        return response()->json($subject);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'section_id' => 'nullable|integer|exists:sections,id',
            'department_id' => 'nullable',
            'is_general' => 'nullable|boolean',
        ]);

        $schoolId = (int) Auth::user()->school_id;
        $subject = Subject::where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->findOrFail($id);

        $name = trim((string) $request->name);

        // Check uniqueness on other active subjects in the school
        $exists = Subject::where('school_id', $schoolId)
            ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower($name)])
            ->where('id', '!=', $id)
            ->whereNull('archived_at')
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => "Another subject named '{$name}' already exists in your school.",
            ], 422);
        }

        $deptIdInput = $request->input('department_id');
        $isGeneral = $request->boolean('is_general') || $this->isGeneralDepartment($deptIdInput);
        $resolvedDepartmentId = null;

        if (! $isGeneral && ! empty($deptIdInput)) {
            $department = $this->resolveDepartment($deptIdInput, $schoolId);
            if ($department) {
                $resolvedDepartmentId = (int) $department->id;
            }
        }

        $subject->name = $name;
        $subject->department_id = $resolvedDepartmentId;
        if ($request->has('section_id')) {
            $subject->section_id = $request->filled('section_id') ? (int) $request->section_id : null;
        }
        $subject->save();

        $subject->load(['section:id,name', 'department:id,name']);

        return response()->json([
            'message' => 'Subject updated successfully',
            'subject' => $subject,
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $subject = Subject::where('school_id', Auth::user()->school_id)->find($id);

        if (! $subject) {
            return response()->json(['message' => 'Subject not found'], 404);
        }

        $usedInResults = app(AcademicSetupArchiveService::class)->subjectHasResultRecords($subject);

        $subject->forceFill(['archived_at' => now()])->save();

        return response()->json([
            'message' => $usedInResults
                ? 'This subject is used in past results. It has been archived and will no longer appear for new subject allocation.'
                : 'Subject archived successfully.',
            'archived' => true,
            'used_in_results' => $usedInResults,
        ]);
    }

    public function restore($id): JsonResponse
    {
        $schoolId = (int) Auth::user()->school_id;

        $subject = Subject::where('school_id', $schoolId)
            ->whereNotNull('archived_at')
            ->find($id);

        if (! $subject) {
            return response()->json(['message' => 'Archived subject not found'], 404);
        }

        $exists = Subject::where('name', $subject->name)
            ->where('school_id', $schoolId)
            ->where('id', '!=', $subject->id)
            ->whereNull('archived_at')
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'An active subject with this name already exists. Rename or merge it before restoring.'], 422);
        }

        $subject->forceFill(['archived_at' => null])->save();

        return response()->json([
            'message' => 'Subject restored successfully.',
            'archived' => false,
        ]);
    }

    private function isGeneralDepartment($departmentId): bool
    {
        if (is_null($departmentId) || $departmentId === '') {
            return true;
        }
        $value = strtolower(trim((string) $departmentId));

        return in_array($value, ['0', 'general', 'common', 'all', 'none'], true);
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
