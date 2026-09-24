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

    /**
     * Bulk seed standard Nigerian/WAEC curriculum subjects into the school.
     */
    public function seedCurriculum(Request $request): JsonResponse
    {
        $schoolId = (int) Auth::user()->school_id;
        $category = $request->input('category'); // 'primary', 'junior', 'senior_core', 'senior_science', 'senior_arts', 'senior_commercial', 'senior_vocational', 'all'
        $requestedSubjects = $request->input('subjects'); // array of { name, code?, section_id?, department_id? }

        $definitions = $this->getCurriculumDefinitions();

        $subjectsToProcess = [];

        $categoryKey = $category === 'senior_core' ? 'senior_compulsory' : $category;

        if (is_array($requestedSubjects) && count($requestedSubjects) > 0) {
            $subjectsToProcess = $requestedSubjects;
        } elseif (!empty($categoryKey) && isset($definitions[$categoryKey])) {
            $subjectsToProcess = $definitions[$categoryKey];
        } elseif ($category === 'all') {
            foreach ($definitions as $catList) {
                foreach ($catList as $item) {
                    $subjectsToProcess[] = $item;
                }
            }
        } else {
            return response()->json([
                'message' => 'Invalid category or subjects list specified.',
                'available_categories' => array_keys($definitions),
            ], 422);
        }

        // Resolve Sections of the school
        $sections = Section::where('school_id', $schoolId)->whereNull('archived_at')->get();
        $primarySec = $sections->first(fn ($s) => preg_match('/primary|nursery|basic|grade|kinder/i', $s->name));
        $juniorSec = $sections->first(fn ($s) => preg_match('/junior|jss/i', $s->name));
        $seniorSec = $sections->first(fn ($s) => preg_match('/senior|sss/i', $s->name));

        // Resolve Departments of the school
        $departments = Department::where('school_id', $schoolId)->whereNull('archived_at')->get();
        $scienceDept = $departments->first(fn ($d) => preg_match('/science/i', $d->name));
        $artsDept = $departments->first(fn ($d) => preg_match('/art|humanit/i', $d->name));
        $commercialDept = $departments->first(fn ($d) => preg_match('/commercial|business/i', $d->name));
        $vocationalDept = $departments->first(fn ($d) => preg_match('/vocat|tech/i', $d->name));

        $createdCount = 0;
        $existingCount = 0;
        $processed = [];

        foreach ($subjectsToProcess as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            if (empty($name)) {
                continue;
            }

            $existing = Subject::where('school_id', $schoolId)
                ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower($name)])
                ->whereNull('archived_at')
                ->first();

            if ($existing) {
                $existingCount++;
                $processed[] = $existing;
                continue;
            }

            // Determine Section ID
            $secId = null;
            if (isset($item['section_id']) && !empty($item['section_id'])) {
                $secId = (int) $item['section_id'];
            } else {
                $sectionTag = $item['section_tag'] ?? '';
                if ($sectionTag === 'primary' && $primarySec) $secId = $primarySec->id;
                elseif ($sectionTag === 'junior' && $juniorSec) $secId = $juniorSec->id;
                elseif ($sectionTag === 'senior' && $seniorSec) $secId = $seniorSec->id;
            }

            // Determine Department ID
            $deptId = null;
            if (isset($item['department_id']) && !empty($item['department_id'])) {
                $deptId = (int) $item['department_id'];
            } else {
                $deptTag = $item['dept_tag'] ?? '';
                if ($deptTag === 'science' && $scienceDept) $deptId = $scienceDept->id;
                elseif ($deptTag === 'arts' && $artsDept) $deptId = $artsDept->id;
                elseif ($deptTag === 'commercial' && $commercialDept) $deptId = $commercialDept->id;
                elseif ($deptTag === 'vocational' && $vocationalDept) $deptId = $vocationalDept->id;
            }

            // Determine code
            $code = $item['code'] ?? null;
            if (empty($code)) {
                $cleanWord = preg_replace('/[^A-Za-z]/', '', $name);
                $prefix = strlen($cleanWord) >= 3 ? strtoupper(substr($cleanWord, 0, 3)) : 'SUB';
                $total = Subject::where('school_id', $schoolId)->whereNull('archived_at')->count();
                $code = $prefix . str_pad($total + 1, 3, '0', STR_PAD_LEFT);
            }

            $created = Subject::create([
                'name' => $name,
                'subject_id' => $code,
                'school_id' => $schoolId,
                'section_id' => $secId,
                'department_id' => $deptId,
            ]);

            $createdCount++;
            $processed[] = $created;
        }

        return response()->json([
            'message' => "Curriculum processed: {$createdCount} subjects added, {$existingCount} already existed.",
            'created_count' => $createdCount,
            'existing_count' => $existingCount,
            'total_processed' => count($processed),
        ]);
    }

    public function getCurriculumTemplates(): JsonResponse
    {
        return response()->json($this->getCurriculumDefinitions());
    }

    private function getCurriculumDefinitions(): array
    {
        return [
            'primary' => [
                ['name' => 'Mathematics', 'code' => 'MTH', 'section_tag' => 'primary'],
                ['name' => 'English Studies', 'code' => 'ENG', 'section_tag' => 'primary'],
                ['name' => 'Basic Science & Technology', 'code' => 'BST', 'section_tag' => 'primary'],
                ['name' => 'Social Studies', 'code' => 'SOS', 'section_tag' => 'primary'],
                ['name' => 'Civic Education', 'code' => 'CIV', 'section_tag' => 'primary'],
                ['name' => 'Quantitative Reasoning', 'code' => 'QTR', 'section_tag' => 'primary'],
                ['name' => 'Verbal Reasoning', 'code' => 'VRB', 'section_tag' => 'primary'],
                ['name' => 'Christian Religious Studies (CRS)', 'code' => 'CRS', 'section_tag' => 'primary'],
                ['name' => 'Islamic Religious Studies (IRS)', 'code' => 'IRS', 'section_tag' => 'primary'],
                ['name' => 'Cultural & Creative Arts (CCA)', 'code' => 'CCA', 'section_tag' => 'primary'],
                ['name' => 'Physical & Health Education (PHE)', 'code' => 'PHE', 'section_tag' => 'primary'],
                ['name' => 'Agricultural Science', 'code' => 'AGR', 'section_tag' => 'primary'],
                ['name' => 'Home Economics', 'code' => 'HEC', 'section_tag' => 'primary'],
                ['name' => 'Computer Studies / ICT', 'code' => 'ICT', 'section_tag' => 'primary'],
                ['name' => 'Handwriting & Phonics', 'code' => 'HWT', 'section_tag' => 'primary'],
                ['name' => 'French Language', 'code' => 'FRN', 'section_tag' => 'primary'],
            ],
            'junior' => [
                ['name' => 'English Studies', 'code' => 'ENG', 'section_tag' => 'junior'],
                ['name' => 'General Mathematics', 'code' => 'MTH', 'section_tag' => 'junior'],
                ['name' => 'Basic Science', 'code' => 'BSC', 'section_tag' => 'junior'],
                ['name' => 'Basic Technology', 'code' => 'BTE', 'section_tag' => 'junior'],
                ['name' => 'Business Studies', 'code' => 'BST', 'section_tag' => 'junior'],
                ['name' => 'Social Studies', 'code' => 'SOS', 'section_tag' => 'junior'],
                ['name' => 'Civic Education', 'code' => 'CIV', 'section_tag' => 'junior'],
                ['name' => 'Agricultural Science', 'code' => 'AGR', 'section_tag' => 'junior'],
                ['name' => 'Home Economics', 'code' => 'HEC', 'section_tag' => 'junior'],
                ['name' => 'Computer Studies / ICT', 'code' => 'ICT', 'section_tag' => 'junior'],
                ['name' => 'Physical & Health Education (PHE)', 'code' => 'PHE', 'section_tag' => 'junior'],
                ['name' => 'Cultural & Creative Arts (CCA)', 'code' => 'CCA', 'section_tag' => 'junior'],
                ['name' => 'Christian Religious Studies (CRS)', 'code' => 'CRS', 'section_tag' => 'junior'],
                ['name' => 'Islamic Religious Studies (IRS)', 'code' => 'IRS', 'section_tag' => 'junior'],
                ['name' => 'French Language', 'code' => 'FRN', 'section_tag' => 'junior'],
                ['name' => 'Nigerian Language', 'code' => 'NLN', 'section_tag' => 'junior'],
            ],
            'senior_compulsory' => [
                ['name' => 'English Language', 'code' => 'ENG', 'section_tag' => 'senior', 'dept_tag' => 'general'],
                ['name' => 'General Mathematics', 'code' => 'MTH', 'section_tag' => 'senior', 'dept_tag' => 'general'],
                ['name' => 'Civic Education', 'code' => 'CIV', 'section_tag' => 'senior', 'dept_tag' => 'general'],
                ['name' => 'Economics', 'code' => 'ECO', 'section_tag' => 'senior', 'dept_tag' => 'general'],
                ['name' => 'Data Processing', 'code' => 'DTP', 'section_tag' => 'senior', 'dept_tag' => 'general'],
                ['name' => 'Trade & Entrepreneurship', 'code' => 'TRD', 'section_tag' => 'senior', 'dept_tag' => 'general'],
            ],
            'senior_science' => [
                ['name' => 'Physics', 'code' => 'PHY', 'section_tag' => 'senior', 'dept_tag' => 'science'],
                ['name' => 'Chemistry', 'code' => 'CHM', 'section_tag' => 'senior', 'dept_tag' => 'science'],
                ['name' => 'Biology', 'code' => 'BIO', 'section_tag' => 'senior', 'dept_tag' => 'science'],
                ['name' => 'Further Mathematics', 'code' => 'FMT', 'section_tag' => 'senior', 'dept_tag' => 'science'],
                ['name' => 'Agricultural Science', 'code' => 'AGR', 'section_tag' => 'senior', 'dept_tag' => 'science'],
                ['name' => 'Technical Drawing', 'code' => 'TDR', 'section_tag' => 'senior', 'dept_tag' => 'science'],
                ['name' => 'Geography', 'code' => 'GEO', 'section_tag' => 'senior', 'dept_tag' => 'science'],
            ],
            'senior_arts' => [
                ['name' => 'Literature in English', 'code' => 'LIT', 'section_tag' => 'senior', 'dept_tag' => 'arts'],
                ['name' => 'Government', 'code' => 'GOV', 'section_tag' => 'senior', 'dept_tag' => 'arts'],
                ['name' => 'Christian Religious Studies (CRS)', 'code' => 'CRS', 'section_tag' => 'senior', 'dept_tag' => 'arts'],
                ['name' => 'Islamic Religious Studies (IRS)', 'code' => 'IRS', 'section_tag' => 'senior', 'dept_tag' => 'arts'],
                ['name' => 'History', 'code' => 'HIS', 'section_tag' => 'senior', 'dept_tag' => 'arts'],
                ['name' => 'Visual Arts', 'code' => 'ART', 'section_tag' => 'senior', 'dept_tag' => 'arts'],
                ['name' => 'Music', 'code' => 'MUS', 'section_tag' => 'senior', 'dept_tag' => 'arts'],
                ['name' => 'French Language', 'code' => 'FRN', 'section_tag' => 'senior', 'dept_tag' => 'arts'],
                ['name' => 'Nigerian Language', 'code' => 'NLN', 'section_tag' => 'senior', 'dept_tag' => 'arts'],
            ],
            'senior_commercial' => [
                ['name' => 'Financial Accounting', 'code' => 'ACC', 'section_tag' => 'senior', 'dept_tag' => 'commercial'],
                ['name' => 'Commerce', 'code' => 'COM', 'section_tag' => 'senior', 'dept_tag' => 'commercial'],
                ['name' => 'Book Keeping', 'code' => 'BKK', 'section_tag' => 'senior', 'dept_tag' => 'commercial'],
                ['name' => 'Store Management', 'code' => 'STM', 'section_tag' => 'senior', 'dept_tag' => 'commercial'],
                ['name' => 'Office Practice', 'code' => 'OFP', 'section_tag' => 'senior', 'dept_tag' => 'commercial'],
                ['name' => 'Insurance', 'code' => 'INS', 'section_tag' => 'senior', 'dept_tag' => 'commercial'],
            ],
            'senior_vocational' => [
                ['name' => 'Food & Nutrition', 'code' => 'FDN', 'section_tag' => 'senior', 'dept_tag' => 'vocational'],
                ['name' => 'Clothing & Textiles', 'code' => 'CLT', 'section_tag' => 'senior', 'dept_tag' => 'vocational'],
                ['name' => 'Auto Mechanics', 'code' => 'MEC', 'section_tag' => 'senior', 'dept_tag' => 'vocational'],
                ['name' => 'Building Construction', 'code' => 'BLD', 'section_tag' => 'senior', 'dept_tag' => 'vocational'],
                ['name' => 'Electrical Installation', 'code' => 'ELE', 'section_tag' => 'senior', 'dept_tag' => 'vocational'],
                ['name' => 'Woodwork', 'code' => 'WDW', 'section_tag' => 'senior', 'dept_tag' => 'vocational'],
            ],
        ];
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
